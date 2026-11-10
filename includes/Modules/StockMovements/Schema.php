<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\StockMovements;

use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A stock movement and its lines.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'stock_movements', 'stock_movement_items' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();

		$changes = Db::delta(
			self::stock_movements( $charset ),
			self::stock_movement_items( $charset )
		);

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * Stock arriving, leaving, going out to somebody or coming back from them.
	 *
	 * FOUR MOVEMENT TYPES, TWO PAIRS, AND THE PAIRING IS THE INTERESTING PART.
	 * `check_in`/`check_out` are for ASSETS — a drill that goes out to somebody and comes back.
	 * `add_stock`/`remove_stock` are for CONSUMABLES and SELLABLES — things that are used up and
	 * do not return. `StockMovementService` refuses a document that mixes them, because a
	 * "check out" of a box of screws is not a thing that means anything: nobody is bringing them
	 * back, and the recipient the check-out demands would be a person permanently holding a
	 * consumable.
	 *
	 * `recipient_id` IS NULLABLE AND CONDITIONALLY REQUIRED, which is a shape the database cannot
	 * express. A check-out without a recipient is a laptop that has left the building with no record
	 * of who has it — the single most useful fact about the movement. The service enforces it.
	 *
	 * `recipient` (the name) SITS BESIDE THE ID, denormalised on purpose. It is copied onto every
	 * ledger row at finalize so the ledger reads without a join, and a recipient who is later
	 * renamed does not silently rewrite history: the row says who it was at the time.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function stock_movements( string $charset ): string {
		$table = Db::table( 'stock_movements' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			reference varchar(50) NOT NULL default '',
			movement_type varchar(20) NOT NULL default 'add_stock',
			warehouse_id bigint(20) unsigned NOT NULL default 0,
			recipient_id bigint(20) unsigned default NULL,
			recipient varchar(255) NOT NULL default '',
			movement_date datetime default NULL,
			status varchar(20) NOT NULL default 'draft',
			finalized_at datetime default NULL,
			finalized_by bigint(20) unsigned default NULL,
			batch_id char(36) NOT NULL default '',
			line_count int(10) unsigned NOT NULL default 0,
			total_quantity decimal(18,4) NOT NULL default 0.0000,
			total_cost decimal(18,4) NOT NULL default 0.0000,
			details longtext,
			attachments longtext,
			assigned_user_id bigint(20) unsigned default NULL,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY reference (reference),
			KEY movement_type (movement_type),
			KEY warehouse_id (warehouse_id),
			KEY recipient_id (recipient_id),
			KEY status_date (status,movement_date),
			KEY batch_id (batch_id),
			KEY created_at (created_at),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * One item on a movement.
	 *
	 * THE ONLY DOCUMENT LINE THAT CARRIES WEIGHT, and it is here rather than on adjustments and
	 * transfers because this is the document where it is a fact about the EVENT rather than about
	 * the item. Twelve metres of cable checked out weighs what those twelve metres weigh; the item's
	 * own `weight` is per unit and does not answer it. An adjustment correcting a count and a
	 * transfer moving stock between shelves both leave the weight where it was.
	 *
	 * `weight_base` normalises to kilograms at write time, for the same reason `items.weight_base`
	 * does: summing `weight` across lines in grams and tonnes produces a number that means nothing.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function stock_movement_items( string $charset ): string {
		$table = Db::table( 'stock_movement_items' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			movement_id bigint(20) unsigned NOT NULL default 0,
			item_id bigint(20) unsigned NOT NULL default 0,
			unit_id bigint(20) unsigned default NULL,
			quantity decimal(18,4) NOT NULL default 0.0000,
			weight decimal(18,4) NOT NULL default 0.0000,
			weight_unit varchar(10) NOT NULL default '',
			weight_base decimal(18,4) NOT NULL default 0.0000,
			cost_per_unit decimal(18,4) NOT NULL default 0.0000,
			total_cost decimal(18,4) NOT NULL default 0.0000,
			transaction_id bigint(20) unsigned NOT NULL default 0,
			notes text,
			position int(11) NOT NULL default 0,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY movement_position (movement_id,position),
			KEY item_id (item_id),
			KEY transaction_id (transaction_id)
		) {$charset};";
	}
}
