<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Warehouses;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The warehouses table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'warehouses' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::warehouses( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * A place stock is held.
	 *
	 * A WAREHOUSE IS A REAL LOCATION HERE, NOT A LABEL, and that is the one structural difference
	 * between this port and the Joomla component it came from. There, an item carried a single
	 * `warehouse_id` and a single `stock_quantity`, so a "transfer" wrote two ledger rows that
	 * netted to zero and every by-warehouse report was really by-home-warehouse. Here the Stock
	 * module owns an `item_stock` table keyed on (item_id, warehouse_id), and this row is what the
	 * second half of that key points at. That is why deleting a warehouse has to be guarded once
	 * Stock exists: a trashed warehouse with stock in it is stock nobody can find.
	 *
	 * `custom_fields` IS DECLARED NOW AND WRITTEN LATER. The custom-fields module is a later phase,
	 * but the column belongs to the row rather than to that module, and adding it up front costs a
	 * column on an empty table instead of a migration on a populated one. Values that need to be
	 * FILTERED on will not live here — they go to the EAV table, because you cannot index inside
	 * JSON in MySQL 5.7. This column is for the rest.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function warehouses( string $charset ): string {
		$table = Db::table( 'warehouses' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			code varchar(50) NOT NULL default '',
			name varchar(255) NOT NULL default '',
			address longtext,
			custom_fields longtext,
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
