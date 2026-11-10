<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Categories;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The categories table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'categories' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::categories( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * A category. Flat, not a tree.
	 *
	 * THE JOOMLA COMPONENT'S CATEGORIES ARE FLAT AND THIS ONE'S ARE TOO, which is worth stating
	 * because WordPress developers reach for a hierarchy by reflex and Joomla's own `#__categories`
	 * is nested. `com_nextinventory` deliberately did not use it: its table has no `parent_id`, no
	 * `lft`/`rgt`, and no `level`. Reproducing that is the point of the port, and adding a tree
	 * would not be a free improvement — every list query would need a descendant walk, and the
	 * valuation-by-category report would have to decide whether a parent's total includes its
	 * children, which is a question the reports were never designed to answer.
	 *
	 * `code` HAS A PLAIN KEY, NOT A UNIQUE ONE, and that is the house rule rather than an
	 * oversight: a unique index and a soft delete cannot coexist, because a trashed category would
	 * keep its code reserved forever and "ELEC" could be deleted and never created again.
	 * Uniqueness is enforced among LIVE rows in CategoryService, where "live" is a concept the
	 * database does not have.
	 *
	 * `status` REPLACES JOOMLA'S `published` TINYINT, which carried three meanings: 1 published,
	 * 0 unpublished, -2 trashed. The third is now `deleted_at`, so what is left is a genuine
	 * two-state flag — and it is a string because the day a third state appears, a tinyint has to
	 * be migrated and a varchar does not.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function categories( string $charset ): string {
		$table = Db::table( 'categories' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			code varchar(50) NOT NULL default '',
			name varchar(255) NOT NULL default '',
			description longtext,
			status varchar(20) NOT NULL default 'published',
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY code (code),
			KEY name (name),
			KEY status (status),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
