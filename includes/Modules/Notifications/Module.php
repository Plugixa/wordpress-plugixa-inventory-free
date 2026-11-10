<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Entities\RecordLoader;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Notifications\Http\NotificationsController;
use PlugixaInventory\Modules\Notifications\Repositories\AlertSubscriptionRepository;
use PlugixaInventory\Modules\Notifications\Repositories\NotificationRepository;
use PlugixaInventory\Modules\Notifications\Services\AlertSubscriptionService;
use PlugixaInventory\Modules\Notifications\Services\LowStockService;
use PlugixaInventory\Modules\Notifications\Services\NotificationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telling people what has happened, and what is about to.
 *
 * WHAT IT LISTENS TO, AND WHY IT LISTENS RATHER THAN BEING CALLED. Stock movements, transfers and
 * adjustments raise actions when they finalize; this module hooks them. The alternative — each
 * document service calling a notifier — would make three modules depend on this one for something
 * none of them needs in order to work, and would mean a site with notifications disabled still
 * paying for the coupling.
 *
 * A NOTIFICATION IS NEVER LOAD-BEARING. Every listener here is a `do_action` late in a transaction
 * that has already succeeded, so a failure to notify can never fail a finalize. That is the
 * opposite of `StockEngine`'s costing strategy, which is injected precisely BECAUSE it must be able
 * to refuse — and the two together are the reason this plugin uses both patterns rather than
 * picking one.
 */
final class Module implements ModuleInterface, AppInterface {

	/** The daily low-stock scan. */
	public const LOW_STOCK_HOOK = 'plugixa_inventory_low_stock';

	/** The weekly tidy-up of notifications nobody will read again. */
	public const PRUNE_HOOK = 'plugixa_inventory_prune_notifications';

	public function id(): string {
		return 'notifications';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 30,
			'category'  => 'configuration',
			'core'      => false,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_notifications',
			'desc_key'  => 'app_notifications_desc',
			'icon'      => 'bell',
			'cron'      => array(
				self::LOW_STOCK_HOOK,
				self::PRUNE_HOOK,
			),
		);
	}

	/**
	 * @return string[]
	 */
	public function usage( Container $container ): array {
		$prefs = $container->get( AlertSubscriptionRepository::class )->count();

		if ( 0 === $prefs ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: %d: number of saved per-user alert preferences. */
				_n( '%d alert preference', '%d alert preferences', $prefs, 'plugixa-inventory' ),
				$prefs
			),
		);
	}


	/**
	 * The card's name and description, from this module's OWN catalogue.
	 *
	 * Read back out of `Strings::all()` rather than written again here: the pair is registered
	 * there so `bin/generate-strings.php` and the .pot both see it, and a second copy would be a
	 * second thing to keep in step.
	 *
	 * @return array{label: string, desc: string}
	 */
	public function labels(): array {
		$strings = Strings::all();

		return array(
			'label' => (string) ( $strings['app_notifications'] ?? 'notifications' ),
			'desc'  => (string) ( $strings['app_notifications_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	public function migrate(): void {
		Schema::install();
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerServices( Container $container ): void {
		unset( $container );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( NotificationsController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);

		add_filter(
			'plugixa_inventory_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'notification' === $entity_type
					? array_merge( $fields, $container->get( NotificationRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		$this->register_cron( $container );
		$this->register_event_listeners( $container );

		/*
		 * A DELETED USER TAKES THEIR BELL WITH THEM. Rows addressed to a user id that no longer
		 * resolves are unreachable — nothing will ever render them and nothing else would ever
		 * remove them — so they are exactly the kind of orphan that makes a table grow for ever.
		 */
		add_action(
			'deleted_user',
			static function ( int $user_id ) use ( $container ): void {
				$container->get( AlertSubscriptionService::class )->forget_user( $user_id );

				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
				$wpdb->delete(
					\PlugixaInventory\Core\Database\Schema::table( 'notifications' ),
					array( 'user_id' => $user_id ),
					array( '%d' )
				);
			}
		);
	}

	/**
	 * A finalized document's reference, re-read.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE ONE IN THE ACTION'S PAYLOAD IS THE DRAFT'S, AND A DRAFT USUALLY HAS NONE.
	 *
	 * `plugixa_inventory_document_finalized` passes the document as it was BEFORE finalizing, and
	 * finalizing is exactly when `reference` is generated. A draft's is the EMPTY STRING, so
	 * `$document['reference'] ?? '#42'` does not fall back — `??` only catches null — and the
	 * notification reads "Assets were checked out on " with a hole where the reference should be.
	 *
	 * `RecordLoader` is Core's generic by-slug lookup, so this costs one indexed read and does not
	 * make this module name StockMovements.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param Container $container   The plugin container.
	 * @param string    $entity_type The document's registry slug.
	 * @param int       $id          The document.
	 */
	private static function reference( Container $container, string $entity_type, int $id ): string {
		$saved     = $container->get( RecordLoader::class )->load( $entity_type, $id );
		$reference = trim( (string) ( $saved['reference'] ?? '' ) );

		return '' === $reference ? '#' . $id : $reference;
	}

	/**
	 * The two scheduled events.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE LOW-STOCK SCAN IS DAILY AND IS NOT TIED TO ANYBODY LOOKING AT THE SCREEN. In the component
	 * this ports it ran from the bell's unread-count poll, which meant its frequency depended on how
	 * many people had the admin open, it ran inside a request somebody was waiting on, and on a site
	 * where nobody was logged in it never ran at all — which is precisely the site where stock
	 * quietly runs out.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param Container $container The plugin container.
	 */
	private function register_cron( Container $container ): void {
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::LOW_STOCK_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::LOW_STOCK_HOOK );
				}

				if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
					wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::PRUNE_HOOK );
				}
			}
		);

		add_action(
			self::LOW_STOCK_HOOK,
			static function () use ( $container ): void {
				$container->get( LowStockService::class )->run();
			}
		);

		add_action(
			self::PRUNE_HOOK,
			static function () use ( $container ): void {
				$container->get( NotificationService::class )->prune();
			}
		);

		add_action(
			'plugixa_inventory_deactivated',
			static function (): void {
				wp_clear_scheduled_hook( self::LOW_STOCK_HOOK );
				wp_clear_scheduled_hook( self::PRUNE_HOOK );
			}
		);
	}

	/**
	 * The things worth telling somebody about as they happen.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THIS MODULE LISTENS FOR THE EVENTS IT OWNS, AND OTHER MODULES ANNOUNCE THEIRS THEMSELVES.
	 *
	 * There used to be one listener here with a branch per document type — transfer, adjustment,
	 * check-out — reached through `plugixa_inventory_document_finalized` so that this module named
	 * none of those three. That was the right instinct and the wrong seam: the branches were still
	 * HERE, so this file carried three modules' vocabulary and their `group_key` conventions, and a
	 * build without one of those modules shipped a sentence describing an event that could never
	 * fire.
	 *
	 * The generic action is still the door — it is just that each module walks through its own.
	 * `AlertType::all()` is a filter for the same reason (see that class), so a module registers
	 * the event AND the sentence in one place, next to the code that raises it.
	 *
	 * A check-out stays here because it is the one movement type free ships that is addressed to a
	 * person, and because `Notifications` is what a check-out is FOR.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param Container $container The plugin container.
	 */
	private function register_event_listeners( Container $container ): void {
		add_action(
			'plugixa_inventory_document_finalized',
			static function ( string $entity_type, int $id, array $document ) use ( $container ): void {
				if ( 'stock_movement' !== $entity_type || 'check_out' !== (string) ( $document['movement_type'] ?? '' ) ) {
					return;
				}

				$container->get( NotificationService::class )->notify(
					AlertType::ITEM_CHECKED_OUT,
					array(
						'title'     => __( 'Asset checked out', 'plugixa-inventory' ),
						'message'   => sprintf(
							/* translators: %s: the movement's reference. */
							__( 'Assets were checked out on %s.', 'plugixa-inventory' ),
							self::reference( $container, $entity_type, $id )
						),
						'group_key' => 'checkout_' . $id,
					)
				);
			},
			10,
			3
		);

		/*
		 * THE DAILY STOCK AUDIT'S FINDINGS REACH THE BELL, and this is the listener P4 left a seam
		 * for: `Stock\Module` fires `plugixa_inventory_stock_audited` and deliberately sends no mail
		 * itself, because "who should hear about this" is a policy question that belongs where the
		 * other alert policies live. This is where they live.
		 */
		add_action(
			'plugixa_inventory_stock_audited',
			static function ( array $report ) use ( $container ): void {
				$findings = count( $report['stock_diffs'] ?? array() )
					+ count( $report['cache_diffs'] ?? array() )
					+ count( $report['layer_diffs'] ?? array() );

				if ( 0 === $findings ) {
					return;
				}

				$container->get( NotificationService::class )->notify(
					AlertType::SYSTEM,
					array(
						'title'        => __( 'Stock health check found something', 'plugixa-inventory' ),
						'message'      => sprintf(
							/* translators: %d: how many differences the daily check found. */
							_n(
								'The daily check found %d quantity that does not follow from the stock ledger.',
								'The daily check found %d quantities that do not follow from the stock ledger.',
								$findings,
								'plugixa-inventory'
							),
							$findings
						),
						'priority'     => 'high',
						'action_url'   => admin_url( 'admin.php?page=plugixa-inventory#/settings' ),
						'action_label' => __( 'Open stock health', 'plugixa-inventory' ),
						'group_key'    => 'stock_audit_' . gmdate( 'Y-m-d' ),
					)
				);
			}
		);
	}
}
