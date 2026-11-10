<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications\Services;

use PlugixaInventory\Modules\Notifications\AlertType;
use PlugixaInventory\Modules\Notifications\Repositories\NotificationRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telling somebody something: the bell, and the email that goes with it.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE EMAIL THROTTLE IS RECORDED AFTER THE SEND, NOT BEFORE IT — which is the one thing the
 * component this ports got wrong.
 *
 * There, the "last sent" timestamp was written as a setting BEFORE `mail()` was called. A send that
 * failed — a misconfigured SMTP host, a full mailbox, a plugin returning false from
 * `wp_mail` — still counted as sent, so the next twenty-four hours were silent about stock that was
 * running out. The failure mode is the worst available: an alert system that is quiet, and quiet is
 * what it looks like when everything is fine.
 *
 * Here the transient is set only once `wp_mail()` has returned true. A failed send therefore
 * retries on the next run, which is the behaviour somebody would expect from something calling
 * itself an alert.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * AND THE BELL IS NOT THROTTLED AT ALL, only the email. An in-app notification costs the reader
 * nothing until they open the bell, and `group_key` already stops repeats from piling up — so
 * throttling it would only mean the bell disagreeing with reality for a day.
 */
final class NotificationService {

	/**
	 * How long an emailed alert of one kind stays quiet for one person.
	 *
	 * Ported from the component this replaces, which sends at most one digest per day. It is a
	 * judgement about tolerable noise rather than a technical constant, so it is filterable.
	 */
	public const EMAIL_THROTTLE = DAY_IN_SECONDS;

	/** How long a notification stays in the bell before the cleanup removes it. */
	public const RETENTION_DAYS = 90;

	public function __construct(
		private readonly NotificationRepository $notifications,
		private readonly AlertSubscriptionService $subscriptions
	) {}

	/**
	 * Tell everybody who wants to hear about this.
	 *
	 * @param string               $alert_type One of AlertType's constants.
	 * @param array<string, mixed> $payload    title, message, and optionally priority, action_url,
	 *                                         action_label, metadata, group_key, item_id.
	 *
	 * @return array{notified: int, emailed: int}
	 */
	public function notify( string $alert_type, array $payload ): array {
		if ( ! AlertType::known( $alert_type ) ) {
			return array( 'notified' => 0, 'emailed' => 0 );
		}

		/**
		 * Whether this alert is worth delivering at all.
		 *
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * THE ONLY SEAM THAT CAN STOP AN ALERT, AND IT HAS TO BE BEFORE THE AUDIENCE IS READ.
		 * `plugixa_inventory_alert_sent` fires AFTER delivery, which is the right shape for a relay
		 * and useless for suppression — by the time it runs the rows are written and the mail has
		 * gone.
		 *
		 * It exists for a specific, real case: an operation that legitimately finalizes many
		 * documents in one go. Each one is an ordinary event that deserves its own bell row when a
		 * person does it by hand, and collectively they are ONE thing that happened — so a CSV
		 * import posting a dozen opening-balance adjustments would otherwise bury a day's real
		 * notifications under twelve rows saying the same sentence. The caller that knows it is one
		 * action is the only thing that can say so; this module cannot tell the difference from
		 * here.
		 *
		 * RETURNING FALSE MEANS NOTHING WAS SENT, so `plugixa_inventory_alert_sent` does not fire
		 * either — a relay must not hear about an alert no one was given.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 *
		 * @param bool                 $should     Whether to deliver it.
		 * @param string               $alert_type One of AlertType's constants.
		 * @param array<string, mixed> $payload    What would be sent.
		 */
		if ( ! apply_filters( 'plugixa_inventory_should_notify', true, $alert_type, $payload ) ) {
			return array( 'notified' => 0, 'emailed' => 0 );
		}

		$notified = 0;
		$emailed  = 0;

		foreach ( $this->subscriptions->audience( $alert_type ) as $user_id => $wants ) {
			if ( $wants['browser'] && $this->put( (int) $user_id, $alert_type, $payload ) ) {
				++$notified;
			}

			if ( $wants['email'] && $this->email( (int) $user_id, $alert_type, $payload ) ) {
				++$emailed;
			}
		}

		/**
		 * Fires after an alert has been delivered.
		 *
		 * The seam for a relay — Slack, Teams, a webhook — that wants the same events without this
		 * module learning what any of them are.
		 *
		 * @param string               $alert_type The type.
		 * @param array<string, mixed> $payload    What was sent.
		 * @param array{notified: int, emailed: int} $result How far it reached.
		 */
		do_action( 'plugixa_inventory_alert_sent', $alert_type, $payload, array( 'notified' => $notified, 'emailed' => $emailed ) );

		return array( 'notified' => $notified, 'emailed' => $emailed );
	}

	/**
	 * Write one notification, or update the one already standing in its group.
	 *
	 * ONE GROUP, ONE ROW. Forty items falling below their reorder level on a Tuesday is one thing
	 * that happened; the caller passes a group key naming the day, and the second call through
	 * updates the first row rather than adding to a pile the reader has to clear by hand.
	 *
	 * UPDATING RE-RAISES IT AS UNREAD, because the numbers in it have changed and somebody who read
	 * yesterday's has not read today's. Somebody who DISMISSED it is left alone — see
	 * `NotificationRepository::find_in_group()`.
	 *
	 * @param int                  $user_id    The recipient.
	 * @param string               $alert_type The type.
	 * @param array<string, mixed> $payload    The content.
	 */
	private function put( int $user_id, string $alert_type, array $payload ): bool {
		$group_key = (string) ( $payload['group_key'] ?? '' );

		$data = array(
			'user_id'      => $user_id,
			'alert_type'   => $alert_type,
			'title'        => sanitize_text_field( (string) ( $payload['title'] ?? '' ) ),
			'message'      => sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) ),
			'priority'     => in_array( $payload['priority'] ?? '', array( 'low', 'normal', 'high', 'urgent' ), true )
				? (string) $payload['priority']
				: 'normal',
			'action_url'   => isset( $payload['action_url'] ) ? esc_url_raw( (string) $payload['action_url'] ) : null,
			'action_label' => isset( $payload['action_label'] ) ? sanitize_text_field( (string) $payload['action_label'] ) : null,
			'metadata'     => isset( $payload['metadata'] ) ? (string) wp_json_encode( $payload['metadata'] ) : null,
			'group_key'    => '' === $group_key ? null : $group_key,
			'item_id'      => isset( $payload['item_id'] ) ? (int) $payload['item_id'] : null,
		);

		$existing = '' === $group_key ? null : $this->notifications->find_in_group( $user_id, $group_key );

		if ( null !== $existing ) {
			if ( ! empty( $existing['deleted_at'] ) ) {
				// Dismissed. They have seen this group and put it away; re-raising it would be the
				// plugin arguing with the reader.
				return false;
			}

			// The content changed, so it is unread again.
			$data['read_at'] = null;

			return $this->notifications->update( (int) $existing['id'], $data );
		}

		return $this->notifications->insert( $data ) > 0;
	}

	/**
	 * Email one person, at most once per throttle window per alert type.
	 *
	 * @param int                  $user_id    The recipient.
	 * @param string               $alert_type The type.
	 * @param array<string, mixed> $payload    The content.
	 */
	private function email( int $user_id, string $alert_type, array $payload ): bool {
		$user = get_userdata( $user_id );

		if ( false === $user || '' === (string) $user->user_email ) {
			return false;
		}

		$key = 'plugixa_inventory_alerted_' . $alert_type . '_' . $user_id;

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		/**
		 * Filter how long an emailed alert of one kind stays quiet for one person.
		 *
		 * @param int    $seconds    The window.
		 * @param string $alert_type The type.
		 */
		$window = (int) apply_filters( 'plugixa_inventory_email_throttle', self::EMAIL_THROTTLE, $alert_type );

		$body = (string) ( $payload['message'] ?? '' );
		$link = (string) ( $payload['action_url'] ?? '' );

		/*
		 * THE LINK IS THE EMAIL'S, NOT THE NOTIFICATION'S. In the bell the whole row is already a
		 * link to `action_url`, so printing it under the text says the same thing twice; in a mail
		 * client there is nothing to click unless it is written out. Appended here so every alert
		 * type gets it without each one remembering to.
		 */
		if ( '' !== $link ) {
			$body .= "\n\n" . $link;
		}

		$sent = wp_mail(
			$user->user_email,
			sprintf(
				/* translators: 1: the site's name, 2: the alert's own subject line. */
				__( '[%1$s] %2$s', 'plugixa-inventory' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				(string) ( $payload['title'] ?? __( 'Inventory alert', 'plugixa-inventory' ) )
			),
			$body
		);

		/*
		 * AFTER THE SEND, AND ONLY ON SUCCESS. See the class docblock: writing this first is how the
		 * component this ports went silent for a day every time mail failed.
		 */
		if ( $sent && $window > 0 ) {
			set_transient( $key, time(), $window );
		}

		return (bool) $sent;
	}

	/**
	 * One user's bell, newest first.
	 *
	 * @param int  $user_id The user.
	 * @param int  $limit   How many.
	 * @param bool $unread  Only the unread ones.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function inbox( int $user_id, int $limit = 30, bool $unread = false ): array {
		$query = $this->notifications->query()
			->where( 't.user_id = %d', array( $user_id ) )
			->order_by( 't.id', 'DESC' );

		if ( $unread ) {
			$query->where( 't.read_at IS NULL', array() );
		}

		return array_map(
			array( $this->notifications, 'cast' ),
			$query->paginate( max( 1, min( 100, $limit ) ), 0 )->get()
		);
	}

	/**
	 * @param int $user_id The user.
	 */
	public function unread_count( int $user_id ): int {
		return $this->notifications->unread_count( $user_id );
	}

	/**
	 * One notification, if and only if it belongs to this user.
	 *
	 * THE ONE OWNERSHIP RULE, asked by the route's permission callback before the request is
	 * processed and again by `mark_read()` and `dismiss()` below. Reading somebody else's
	 * notification is not a capability question — no capability should let it happen — so a row that
	 * is not yours is the same answer as a row that does not exist.
	 *
	 * @param int $id      The notification.
	 * @param int $user_id Who is asking.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_for( int $id, int $user_id ): ?array {
		$row = $id > 0 && $user_id > 0 ? $this->notifications->find( $id ) : null;

		return null !== $row && (int) $row['user_id'] === $user_id ? $row : null;
	}

	/**
	 * Mark one as read — but only if it is the caller's own.
	 *
	 * Refused as "not found" rather than as "forbidden" — telling a stranger that a notification
	 * exists is itself a small leak.
	 *
	 * @param int $id      The notification.
	 * @param int $user_id Who is asking.
	 */
	public function mark_read( int $id, int $user_id ): bool|WP_Error {
		if ( null === $this->find_for( $id, $user_id ) ) {
			return $this->not_found();
		}

		return $this->notifications->update( $id, array( 'read_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * @param int $user_id The user.
	 */
	public function mark_all_read( int $user_id ): int {
		return $this->notifications->mark_all_read( $user_id );
	}

	/**
	 * Dismiss one — the soft delete, which is what "gone from my bell" means here.
	 *
	 * @param int $id      The notification.
	 * @param int $user_id Who is asking.
	 */
	public function dismiss( int $id, int $user_id ): bool|WP_Error {
		if ( null === $this->find_for( $id, $user_id ) ) {
			return $this->not_found();
		}

		return $this->notifications->delete( $id );
	}

	/**
	 * Remove notifications nobody will read again.
	 *
	 * @param int $days How long to keep them.
	 */
	public function prune( int $days = self::RETENTION_DAYS ): int {
		return $this->notifications->purge_older_than( $days );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That notification does not exist.', 'plugixa-inventory' ),
			array( 'status' => 404 )
		);
	}
}
