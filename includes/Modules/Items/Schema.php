<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Items;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The items table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'items' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::items( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * A thing the business holds: an asset, a consumable or something it sells.
	 *
	 * FOUR DELIBERATE DEPARTURES FROM THE JOOMLA TABLE THIS PORTS, each fixing something that is
	 * broken there rather than merely different:
	 *
	 * 1. ONE STATUS COLUMN. Joomla's items carry BOTH `state` and `published`, they are never kept
	 *    in sync, and different code reads different ones — its reports filter on `published` while
	 *    its item list, dashboard and low-stock check all use `state`. So an item unpublished from
	 *    the list still appears in every report. There is one column here, and trashing is
	 *    `deleted_at`, which is the third value `state` was overloading.
	 *
	 * 2. `default_warehouse_id`, NOT `warehouse_id`. The name is the bug. In Joomla that column IS
	 *    the stock location, which is why a transfer there nets to zero: one item, one place, one
	 *    quantity. Here the quantity lives in the Stock module's `item_stock`, keyed on
	 *    (item_id, warehouse_id), and this column is only the default a new receipt is suggested
	 *    against. Renaming it makes every future reader stop and check, which is the point.
	 *
	 * 3. `image_id`, NOT `image`. Joomla stores a path and ships ~700 lines of upload helper plus a
	 *    ~250-file vendored copy of intervention/image to make thumbnails. WordPress has the media
	 *    library, `add_image_size()` and `wp_generate_attachment_metadata()`. This is an attachment
	 *    id, and none of that code gets ported.
	 *
	 * 4. `weight_base`. Joomla stores `weight` and `weight_unit` side by side and then sums the raw
	 *    column, which adds grams to tonnes and produces a number that means nothing. This column
	 *    holds the same mass normalised to kilograms at write time, so SUM() is answerable. It is
	 *    the `amount_base` pattern the CRM uses for money in a second currency.
	 *
	 * `stock_quantity` IS A CACHE AND IS NOT WRITTEN BY THIS MODULE. It is the sum of the item's
	 * rows in `item_stock`, denormalised because the item list and the low-stock query read it on
	 * every request and a correlated SUM per row would not scale. The Stock module owns it and
	 * writes it through `ItemService::set_cached_stock()`; `StockEngine::rebuild()` proves it can
	 * be derived from the ledger rather than merely trusted. It is deliberately NOT in
	 * `ItemRepository::fillable()`, so an ordinary item edit cannot touch it.
	 *
	 * NEITHER `sku` NOR `barcode` HAS A UNIQUE INDEX, for the reason the whole codebase repeats: a
	 * unique index and a soft delete cannot coexist. Uniqueness among LIVE rows is enforced in
	 * ItemService.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function items( string $charset ): string {
		$table = Db::table( 'items' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(255) NOT NULL default '',
			sku varchar(100) NOT NULL default '',
			barcode varchar(100) NOT NULL default '',
			serial_number varchar(255) NOT NULL default '',
			item_type varchar(20) NOT NULL default 'consumable',
			category_id bigint(20) unsigned default NULL,
			default_warehouse_id bigint(20) unsigned default NULL,
			unit_id bigint(20) unsigned default NULL,
			image_id bigint(20) unsigned default NULL,
			stock_quantity decimal(18,4) NOT NULL default 0.0000,
			reorder_level decimal(18,4) NOT NULL default 0.0000,
			unit_price decimal(18,4) NOT NULL default 0.0000,
			cost_price decimal(18,4) NOT NULL default 0.0000,
			weight decimal(18,4) NOT NULL default 0.0000,
			weight_unit varchar(10) NOT NULL default '',
			weight_base decimal(18,4) NOT NULL default 0.0000,
			track_weight tinyint(1) NOT NULL default 0,
			status varchar(20) NOT NULL default 'published',
			description longtext,
			custom_fields longtext,
			assigned_user_id bigint(20) unsigned default NULL,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY name (name),
			KEY sku (sku),
			KEY barcode (barcode),
			KEY serial_number (serial_number),
			KEY item_type (item_type),
			KEY category_id (category_id),
			KEY default_warehouse_id (default_warehouse_id),
			KEY unit_id (unit_id),
			KEY status_type (status,item_type),
			KEY low_stock (status,stock_quantity,reorder_level),
			KEY assigned_user_id (assigned_user_id),
			KEY created_at (created_at),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
