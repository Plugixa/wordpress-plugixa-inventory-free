<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bell's strings.
 *
 * HAND-MAINTAINED, AND MACHINE-CHECKED. `php bin/generate-strings.php` reads every `t()` call in
 * the React source and refuses three things: a key the app renders that is not registered here, a
 * key whose English differs from the fallback written at the call site, and a key registered twice
 * in two catalogues with two different texts. `--add` appends whatever is missing.
 *
 * IT DOES NOT REWRITE THIS FILE. The grouping and the comments below are worth more than
 * alphabetical order, so the tool is allowed to add to the catalogue and never to reorganise it.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'notifications'             => __( 'Notifications', 'plugixa-inventory' ),
			'notification'              => __( 'Notification', 'plugixa-inventory' ),
			'no_notifications'          => __( 'Nothing to report', 'plugixa-inventory' ),
			'no_notifications_hint'     => __( 'Low stock, finished transfers and stock adjustments show up here.', 'plugixa-inventory' ),
			'mark_all_read'             => __( 'Mark all read', 'plugixa-inventory' ),
			'mark_read'                 => __( 'Mark read', 'plugixa-inventory' ),
			'dismiss'                   => __( 'Dismiss', 'plugixa-inventory' ),
			'unread'                    => __( 'Unread', 'plugixa-inventory' ),
			'notification_settings'     => __( 'Notification settings', 'plugixa-inventory' ),

			'alert_email'               => __( 'Email', 'plugixa-inventory' ),
			'alert_browser'             => __( 'In the app', 'plugixa-inventory' ),
			/*
			 * SAID ON THE PREFERENCES SCREEN because the throttle is otherwise invisible: somebody
			 * who switches low-stock email on and sees nothing for a day would reasonably conclude
			 * it is broken.
			 */
			'alert_digest_hint'         => __( 'Low stock is sent as one digest a day, not one message per item. Turning email on will not send anything until the next daily check.', 'plugixa-inventory' ),

			/*
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 * THE FIVE `alert_*` LABELS ARE GONE, AND THEY HAD NEVER BEEN READ.
			 *
			 * `AlertPreferences` renders each row from `spec.label`, which the SERVER sends with
			 * the type — so these were a second, silent copy of `AlertType::all()`'s wording that
			 * nothing consulted. `bin/generate-strings.php --orphans` had been reporting all five
			 * for as long as they existed.
			 *
			 * Two of them were also the vocabulary of modules that are no longer free, which is
			 * what finally made an orphan worth deleting rather than tolerating: they reached the
			 * .pot, so translators were being asked to translate the names of events the build in
			 * front of them cannot raise.
			 *
			 * A module that raises an event now names it once, beside the code that raises it —
			 * see `plugixa_inventory_alert_types` on `AlertType`.
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 */

			'priority_low'              => __( 'Low', 'plugixa-inventory' ),
			'priority_normal'           => __( 'Normal', 'plugixa-inventory' ),
			'priority_high'             => __( 'High', 'plugixa-inventory' ),
			'priority_urgent'           => __( 'Urgent', 'plugixa-inventory' ),

			// The App Manager card.
			'app_notifications'      => __( 'Notifications and alerts', 'plugixa-inventory' ),
			'app_notifications_desc' => __( 'The notification bell, low-stock emails and per-user alert preferences.', 'plugixa-inventory' ),
		);
	}
}
