<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock;

use PlugixaInventory\Core\Database\Schema as Db;
use PlugixaInventory\Core\WallClock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two tables the whole stock engine rests on.
 *
 * THERE WERE FOUR. `inventory_layers` and `layer_consumptions` existed only for the layer costing
 * strategies, and they left with the module that writes them. That module's own schema class
 * explains why the "create them on every site regardless" argument was about the SETTING and still
 * holds there.
 *
 * NOTHING HERE CHANGED SHAPE. `StockEngine` never touched those tables: it talks to
 * `CostingStrategy`, and the two exceptions it catches are still declared beside that interface,
 * because they are part of the contract rather than of any implementation of it.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'item_stock', 'transactions' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();

		$changes = Db::delta(
			self::item_stock( $charset ),
			self::transactions( $charset )
		);

		Db::ensure_innodb( ...self::tables() );

		self::backfill_occurred_at();

		return $changes;
	}

	/**
	 * Give every ledger row written before `occurred_at` existed the date its document carries.
	 *
	 * WITHOUT THIS THE COLUMN IS WORSE THAN NOT ADDING IT. Every historic row would hold NULL, the
	 * reports would read NULL, and a site that had been running for a year would show an empty
	 * trend chart and an ageing report with nothing in any bucket -- a regression dressed as a fix.
	 *
	 * Three UPDATE ... JOINs, one per document type, each reading that type's own date column. Rows
	 * whose document has since been purged, and any row from a source this does not know about, fall
	 * through to `created_at`, which is exactly what they meant before the column existed.
	 *
	 * IDEMPOTENT BY THE `IS NULL` GUARD, so `Activator::migrate()` running on every version change
	 * costs one indexed scan and touches nothing. It deliberately does NOT re-derive rows that
	 * already have a value: an operator who has corrected a date by hand should not have it
	 * overwritten by a later upgrade.
	 *
	 * The dates are converted from the document's naive wall-clock into UTC with the SAME offset
	 * `DocumentService::occurred_at()` uses, so backfilled rows and newly written ones agree.
	 */
	private static function backfill_occurred_at(): void {
		global $wpdb;

		$ledger = Db::table( 'transactions' );

		/*
		 * EVERY STATEMENT HERE IS A CONSTANT LITERAL WITH `%i` FOR THE IDENTIFIERS. Interpolating
		 * the table name would be safe -- it comes from `Db::table()`, not from a request -- and
		 * wordpress.org rejects it anyway, because the reviewer reads the interpolation itself as
		 * the defect. `SqlLiteralTest` enforces that here rather than leaving it to the review.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema migration on the plugin's own table.
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurred_at IS NULL', $ledger )
		);

		if ( 0 === $pending ) {
			return;
		}

		$offset = WallClock::utc_offset();

		/**
		 * Filter which document tables the ledger can read a real date back out of.
		 *
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * A DOCUMENT MODULE NAMES ITS OWN TABLE, BECAUSE THIS ONE CANNOT KNOW IT IS THERE.
		 *
		 * The map was three literals, and two of them were other modules' tables. Every UPDATE
		 * below JOINs the named table, so a build without one of those modules runs a migration
		 * against a table that does not exist — on the ONE code path where failing is worst,
		 * because `Activator::migrate()` is what runs before anything else works.
		 *
		 * Nothing is lost when a source is absent: the sweep after this loop gives any row it did
		 * not match `created_at`, which is exactly what those rows meant before the column existed.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 *
		 * @param array<string, array{0: string, 1: string}> $sources source_type => [table, date column].
		 */
		$sources = (array) apply_filters(
			'plugixa_inventory_occurred_at_sources',
			array( 'stock_movement' => array( Db::table( 'stock_movements' ), 'movement_date' ) )
		);

		foreach ( $sources as $source_type => list( $table, $column ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema migration on the plugin's own tables.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i t
					   JOIN %i d ON d.id = t.source_id
					    SET t.occurred_at = DATE_SUB( d.%i, INTERVAL %d SECOND )
					  WHERE t.occurred_at IS NULL
					    AND t.source_type = %s
					    AND d.%i IS NOT NULL',
					$ledger,
					$table,
					$column,
					$offset,
					$source_type,
					$column
				)
			);
		}

		// Anything left: no document, or a source type this release does not know about.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema migration on the plugin's own table.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET occurred_at = created_at WHERE occurred_at IS NULL', $ledger )
		);

	}

	/**
	 * How much of an item is in a warehouse.
	 *
	 * THIS TABLE IS THE FIX FOR THE JOOMLA COMPONENT'S CENTRAL DEFECT. There, an item carried one
	 * `stock_quantity` and one home `warehouse_id`, so a transfer wrote a `transfer_out` and a
	 * `transfer_in` row that netted to zero and every "stock by warehouse" report was really "stock
	 * by the item's home warehouse". Here quantity is keyed on the pair, and a transfer genuinely
	 * moves it.
	 *
	 * IT IS NOT A `Repository`, AND THAT IS LOAD-BEARING RATHER THAN A SHORTCUT.
	 *
	 * `UNIQUE KEY item_warehouse` is the concurrency primitive: it is what makes
	 * `INSERT ... ON DUPLICATE KEY UPDATE` a race-free way to ensure the row exists, and therefore
	 * what makes the guarded UPDATE in StockEngine possible at all. A unique key cannot coexist
	 * with a soft delete -- a trashed row keeps occupying the slot -- and `Core\Database\Repository`
	 * writes `deleted_at` unconditionally. So this table leaves the base class.
	 *
	 * It loses nothing by doing so. There is no trash worth having here: the row is DERIVED state,
	 * a materialised view of the ledger, and `StockEngine::rebuild()` can reproduce every one of
	 * these numbers from `transactions` alone. The precedent is the CRM's own
	 * `StageHistoryRepository`, a plain final class with raw $wpdb and no audit columns.
	 *
	 * FIVE COLUMNS, AND NO `created_by`. Nobody "creates" a stock level; a movement does, and the
	 * movement is in the ledger with its author on it. An audit column here would answer a question
	 * whose real answer is one join away and more truthful.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function item_stock( string $charset ): string {
		$table = Db::table( 'item_stock' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			item_id bigint(20) unsigned NOT NULL default 0,
			warehouse_id bigint(20) unsigned NOT NULL default 0,
			quantity decimal(18,4) NOT NULL default 0.0000,
			updated_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_warehouse (item_id,warehouse_id),
			KEY warehouse_id (warehouse_id)
		) {$charset};";
	}

	/**
	 * The stock ledger: every change to every quantity, for ever.
	 *
	 * APPEND-ONLY. `TransactionRepository` has no delete, `TransactionsController` registers no
	 * DELETE route, and the `plugixa_inventory_can_delete` veto in `Repository` refuses the entity
	 * outright. The single exception is `purge_batch()`, which un-writes a batch that failed
	 * half-way and therefore never happened -- see StockEngine::rollback().
	 *
	 * THREE COLUMNS THE JOOMLA LEDGER DOES NOT HAVE, each load-bearing:
	 *
	 *   `warehouse_id`   -- without it the ledger cannot reproduce `item_stock`, and the whole
	 *                       claim that the denormalised numbers are derivable collapses. Joomla's
	 *                       ledger records which ITEM moved and not from where.
	 *   `source_type`    -- "which document produced this row" is the first question anybody asks
	 *   `source_id`         of a ledger line, and it is unanswerable from `batch_id` alone once two
	 *   `source_line_id`    document types share a batch.
	 *
	 * `deleted_at` WILL HOLD NULL FOR EVER, and is here on purpose. It is what lets the ledger
	 * screen go through `RestController::paged_list()` -- and therefore through
	 * `QueryBuilder`'s `plugixa_inventory_query_clauses` choke point, which is where the premium
	 * Roles module scopes what a user may see. Hand-rolled SQL would be one line shorter and would
	 * quietly opt the most sensitive list in the plugin out of record-level access control.
	 *
	 * `KEY item_warehouse (item_id, warehouse_id, id)` IS THE ONE INDEX THAT EXISTS FOR A QUERY:
	 * it is exactly the access path for the rebuild's `GROUP BY item_id, warehouse_id` and for the
	 * latest-balance lookup.
	 *
	 * `cost_per_unit` AND `total_cost` KEEP FOUR DECIMALS, not the currency's two. Divide a 100.00
	 * delivery across 3 units and the honest per-unit cost is 33.3333; rounding it to the currency
	 * loses a penny on every consumption and leaves stock permanently over-valued. See Core\Money.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function transactions( string $charset ): string {
		$table = Db::table( 'transactions' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			item_id bigint(20) unsigned NOT NULL default 0,
			warehouse_id bigint(20) unsigned NOT NULL default 0,
			transaction_type varchar(30) NOT NULL default '',
			quantity_change decimal(18,4) NOT NULL default 0.0000,
			new_quantity decimal(18,4) NOT NULL default 0.0000,
			original_quantity decimal(18,4) NOT NULL default 0.0000,
			transaction_unit_id bigint(20) unsigned default NULL,
			weight decimal(18,4) NOT NULL default 0.0000,
			weight_unit varchar(10) NOT NULL default '',
			weight_base decimal(18,4) NOT NULL default 0.0000,
			cost_per_unit decimal(18,4) NOT NULL default 0.0000,
			total_cost decimal(18,4) NOT NULL default 0.0000,
			batch_id char(36) NOT NULL default '',
			source_type varchar(30) NOT NULL default '',
			source_id bigint(20) unsigned NOT NULL default 0,
			source_line_id bigint(20) unsigned NOT NULL default 0,
			recipient varchar(255) NOT NULL default '',
			recipient_id bigint(20) unsigned default NULL,
			reason text,
			occurred_at datetime default NULL,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY item_warehouse (item_id,warehouse_id,id),
			KEY batch_id (batch_id),
			KEY source (source_type,source_id),
			KEY transaction_type (transaction_type),
			KEY recipient_id (recipient_id),
			KEY occurred_at (occurred_at),
			KEY created_at (created_at),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

}
