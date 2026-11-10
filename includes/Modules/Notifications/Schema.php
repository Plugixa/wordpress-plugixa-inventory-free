<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bell, and who has asked to hear it.
 *
 * TWO TABLES IN TWO DIFFERENT TIERS, and the reason is the one this plugin has hit three times
 * already: a UNIQUE key and a soft delete cannot coexist, because a trashed row keeps occupying the
 * slot while being invisible to every read.
 *
 *   `notifications`        Tier B — a `Repository`, soft-deleted, because DISMISSING one IS the
 *                          soft delete. See below.
 *   `alert_subscriptions`  Tier C — a plain table with `UNIQUE (user_id, alert_type)`, no audit
 *                          columns, written only by its own service.
 *
 * THE JOOMLA COMPONENT'S THIRD TABLE IS NOT PORTED. `#__nextinventory_notification_events` backed a
 * server-sent-events stream, which needs a long-lived PHP process holding a connection open.
 * WordPress has no such thing: a shared host kills it, and every request holds a session lock or a
 * database connection while it waits. The bell polls, exactly as the CRM this architecture comes
 * from does, and the unread count is one indexed COUNT.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'notifications', 'alert_subscriptions' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();

		$changes = Db::delta(
			self::notifications( $charset ),
			self::alert_subscriptions( $charset )
		);

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * One thing one person is being told.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * DISMISSING A NOTIFICATION IS THE SOFT DELETE, and that is why there is no `dismissed` column.
	 *
	 * A notification has three states — unread, read, dismissed — and the third means "gone from my
	 * bell". `Repository` already writes `deleted_at` on `delete()` and already filters it out of
	 * every read, so a separate `dismissed_at` would be a second column meaning the same thing,
	 * with two chances to disagree. There is no trash screen for notifications and there should not
	 * be one: nobody wants to restore a bell.
	 *
	 * `read_at` IS A DATETIME, NOT A FLAG, because "when did you see this" is a question support
	 * gets asked and a boolean cannot answer. It costs the same eight bytes either way.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * `group_key` IS WHAT STOPS THE BELL BECOMING NOISE. Forty items falling below their reorder
	 * level is one thing that happened, not forty — so the low-stock run writes one notification
	 * with a group key of the day, and finds it again tomorrow instead of adding a forty-first.
	 * Ported from the component this replaces, which had the same idea.
	 *
	 * `KEY unread (user_id, deleted_at, read_at)` IS THE ONLY QUERY THAT RUNS OFTEN. The bell asks
	 * "how many unread for me" on a timer, on every admin page, for every logged-in user — so it is
	 * the one access path worth an index built for it rather than inherited.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function notifications( string $charset ): string {
		$table = Db::table( 'notifications' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			user_id bigint(20) unsigned NOT NULL default 0,
			alert_type varchar(50) NOT NULL default '',
			title varchar(255) NOT NULL default '',
			message text,
			priority varchar(10) NOT NULL default 'normal',
			action_url varchar(500) default NULL,
			action_label varchar(100) default NULL,
			metadata longtext,
			group_key varchar(100) default NULL,
			item_id bigint(20) unsigned default NULL,
			read_at datetime default NULL,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY unread (user_id,deleted_at,read_at),
			KEY group_key (group_key),
			KEY alert_type (alert_type),
			KEY item_id (item_id),
			KEY created_at (created_at),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * Whether one person wants to hear about one kind of event, and how.
	 *
	 * TIER C: `UNIQUE (user_id, alert_type)` is the whole point of the table — one preference per
	 * person per kind — and it is what makes the upsert race-free, exactly as `item_stock`'s unique
	 * pair does for the stock engine. A soft delete would keep a trashed preference occupying the
	 * slot, so somebody who unsubscribed and resubscribed would hit a duplicate-key failure against
	 * a row they cannot see.
	 *
	 * ABSENCE IS A REAL STATE AND IT MEANS "THE DEFAULT". A row here is a deliberate choice; no row
	 * means the person has never expressed one, and `AlertSubscriptionService` answers from the
	 * type's default instead. That is why there is no "everybody gets a row on activation": a
	 * default that is stored is a default that cannot be changed later without rewriting everyone's
	 * settings.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function alert_subscriptions( string $charset ): string {
		$table = Db::table( 'alert_subscriptions' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			user_id bigint(20) unsigned NOT NULL default 0,
			alert_type varchar(50) NOT NULL default '',
			email_enabled tinyint(1) NOT NULL default 1,
			browser_enabled tinyint(1) NOT NULL default 1,
			updated_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_alert (user_id,alert_type),
			KEY alert_type (alert_type)
		) {$charset};";
	}
}
