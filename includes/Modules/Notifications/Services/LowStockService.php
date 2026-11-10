<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications\Services;

use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Notifications\AlertType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "These things are running out."
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A CRON EVENT, NOT A CHECK ON EVERY UNREAD-COUNT POLL — which is where the component this ports
 * put it, and the reason that mattered is not tidiness.
 *
 * There, the bell's poll triggered the low-stock scan. So the alert's frequency was decided by how
 * many people had the admin open, the scan ran during a request somebody was waiting on, and on a
 * site with nobody logged in it never ran at all — which is exactly the site where stock quietly
 * runs out. Worse, several concurrent polls each ran the scan, so the throttle was doing the work
 * of a lock and doing it badly.
 *
 * Here it is a scheduled event that runs once whether anybody is looking or not.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ONE DIGEST A DAY, NOT ONE ALERT PER ITEM. A late delivery puts dozens of items under their
 * threshold at once, and dozens of emails about one delivery is how somebody ends up muting the
 * whole thing. The digest is also what the component this replaces sends, so a site migrating gets
 * what it had.
 *
 * THE BELL RE-RAISES WHEN THE NUMBERS CHANGE AND THE EMAIL DOES NOT. `group_key` is the day, so a
 * second run rewrites the same bell row with the new list and marks it unread again — free, because
 * it is one row. The email stays quiet until its window expires. Somebody watching the app sees the
 * change; somebody who is not is not interrupted twice about the same problem.
 */
final class LowStockService {

	/**
	 * How many short items one digest names.
	 *
	 * A DIGEST IS NOT A REPORT. Past this the honest thing to say is "and 340 more", with a link to
	 * the low-stock report that is built for the question — an email listing four hundred rows is
	 * one nobody reads, and it is also one some mail servers truncate without saying so.
	 */
	public const MAX_LISTED = 25;

	public function __construct(
		private readonly ItemRepository $items,
		private readonly NotificationService $notifications
	) {}

	/**
	 * Look, and tell people if there is anything to tell them.
	 *
	 * @return array{items: int, notified: int, emailed: int}
	 */
	public function run(): array {
		$short = $this->items->low_stock( 500 );

		if ( array() === $short ) {
			/*
			 * NOTHING IS SENT WHEN NOTHING IS SHORT, and there is deliberately no "all clear"
			 * message. An alert that arrives every day whether or not anything is wrong is an alert
			 * people learn to delete unread, which costs the days when something IS wrong.
			 */
			return array( 'items' => 0, 'notified' => 0, 'emailed' => 0 );
		}

		$result = $this->notifications->notify(
			AlertType::LOW_STOCK,
			array(
				'title'        => sprintf(
					/* translators: %d: how many items are at or below their reorder level. */
					_n( '%d item is running low', '%d items are running low', count( $short ), 'plugixa-inventory' ),
					count( $short )
				),
				'message'      => $this->compose( $short ),
				'priority'     => 'high',
				'action_url'   => admin_url( 'admin.php?page=plugixa-inventory#/reports' ),
				'action_label' => __( 'Open the low-stock report', 'plugixa-inventory' ),
				/*
				 * THE DAY IS THE GROUP. Two runs on one day update one bell row rather than making
				 * two; tomorrow's run makes a new one, because tomorrow it is news again.
				 */
				'group_key'    => 'low_stock_' . gmdate( 'Y-m-d' ),
				'metadata'     => array(
					'count' => count( $short ),
					'items' => array_map( static fn( array $row ): int => (int) $row['id'], array_slice( $short, 0, self::MAX_LISTED ) ),
				),
			)
		);

		return array_merge( array( 'items' => count( $short ) ), $result );
	}

	/**
	 * The digest's body.
	 *
	 * PLAIN TEXT, AND THAT IS A CHOICE. An HTML mail needs a template, an inliner and a plain-text
	 * alternative, and this message is a list of names and numbers — the format adds nothing a
	 * reader wants and a great deal somebody has to maintain. `wp_mail` sends text/plain by default,
	 * so this also needs no content-type filter that would leak onto other plugins' mail.
	 *
	 * @param array<int, array<string, mixed>> $short Items at or below their reorder level.
	 */
	private function compose( array $short ): string {
		$lines = array(
			sprintf(
				/* translators: %s: the site's name. */
				__( 'These items are at or below their reorder level on %s:', 'plugixa-inventory' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			),
			'',
		);

		foreach ( array_slice( $short, 0, self::MAX_LISTED ) as $item ) {
			$lines[] = sprintf(
				/* translators: 1: item name, 2: SKU or a dash, 3: quantity on hand, 4: reorder level. */
				__( '%1$s (%2$s) — %3$s on hand, reorder at %4$s', 'plugixa-inventory' ),
				(string) $item['name'],
				'' === (string) ( $item['sku'] ?? '' ) ? '—' : (string) $item['sku'],
				(string) $item['stock_quantity'],
				(string) $item['reorder_level']
			);
		}

		if ( count( $short ) > self::MAX_LISTED ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: how many further items are short and not listed. */
				_n( 'and %d more.', 'and %d more.', count( $short ) - self::MAX_LISTED, 'plugixa-inventory' ),
				count( $short ) - self::MAX_LISTED
			);
		}

		/*
		 * NO LINK ON THE END. `NotificationService` appends `action_url` when it MAILS this, because
		 * an email is somewhere you cannot click a row — and the bell is somewhere you can, where a
		 * pasted URL under a message that is already a link to that same page is just noise. Found
		 * by looking at the bell: the digest read as an email quoted into a notification, because
		 * that is exactly what it was.
		 */
		return implode( "\n", $lines );
	}
}
