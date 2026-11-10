<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation. Removes scheduled work but never touches data — a deactivated plugin
 * that destroyed the user's records would be a very unpleasant surprise.
 *
 * IT NAMES NOTHING A MODULE OWNS. This used to clear `plugixa_inventory_reminders` by name — Core reaching down
 * into a leaf module to tidy up after it, which is the one direction the architecture does not allow, and
 * a list that would have grown a line for every module that ever scheduled anything. The modules hook the
 * action below and unschedule themselves; the seam already existed, and was already being fired.
 */
final class Deactivator {

	public static function deactivate(): void {
		/**
		 * Fires on deactivation, before scheduled events are cleared.
		 *
		 * A module that schedules cron clears it here. Modules are booted on this request like any other,
		 * so their listeners are live by the time this runs.
		 */
		do_action( 'plugixa_inventory_deactivated' );

		// Vestigial: nothing in this plugin schedules or listens to `plugixa_inventory_daily_cleanup` — it is a
		// leftover of the pre-2.0 rebuild. Kept because a site upgraded from v1 may still have the event in
		// its cron option, and clearing a hook that was never scheduled costs nothing.
		wp_clear_scheduled_hook( 'plugixa_inventory_daily_cleanup' );

		flush_rewrite_rules();
	}
}
