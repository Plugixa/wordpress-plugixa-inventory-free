<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Recipients;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The recipients table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'recipients' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::recipients( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * Somebody an asset gets checked out to.
	 *
	 * A RECIPIENT IS NOT A CUSTOMER AND NOT A WORDPRESS USER. It is the internal party a check-out
	 * names -- "who has the laptop" -- and the Joomla component this is ported from has no customer
	 * concept at all. Keeping it a plain row rather than a wp_user reference is deliberate: the
	 * people assets go to are frequently contractors, visitors and departments that will never have
	 * a login, and requiring an account to hand somebody a drill would make the feature unusable.
	 *
	 * `user_id` IS THERE ANYWAY, nullable, for the ones who do have accounts. It is what lets a
	 * later phase mail somebody their own checked-out list without a second matching-by-email
	 * heuristic that would be wrong the first time two people share an address.
	 *
	 * `email` HAS NO UNIQUE INDEX. Two recipients legitimately share one: a shared team mailbox, a
	 * site office, a family business. Uniqueness here would be a rule nobody asked for.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function recipients( string $charset ): string {
		$table = Db::table( 'recipients' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(255) NOT NULL default '',
			email varchar(255) NOT NULL default '',
			phone varchar(50) NOT NULL default '',
			department varchar(100) NOT NULL default '',
			user_id bigint(20) unsigned default NULL,
			notes longtext,
			custom_fields longtext,
			status varchar(20) NOT NULL default 'published',
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY name (name),
			KEY email (email),
			KEY department (department),
			KEY user_id (user_id),
			KEY status (status),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
