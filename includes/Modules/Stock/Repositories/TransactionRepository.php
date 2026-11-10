<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Repositories;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\WallClock;
use PlugixaInventory\Modules\Stock\Services\StockEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stock ledger.
 *
 * APPEND-ONLY, ENFORCED IN FOUR PLACES because one of them is bound to be forgotten:
 *
 *   1. `delete()` and `purge()` below refuse outright.
 *   2. `Stock\Module` answers `plugixa_inventory_can_delete` with false for this entity, which
 *      catches anything reaching the base `Repository` directly.
 *   3. `TransactionsController` registers no DELETE route at all, so the refusal is a 404 at the
 *      router before any of this runs.
 *   4. There is no `transaction` entry in `plugixa_inventory_record_writers`, so the CSV importer
 *      and mass actions cannot reach it either.
 *
 * THE ONE EXCEPTION is `purge_batch()`, and it does not contradict the promise. A batch that failed
 * half-way never finalized: the document is still a draft, the stock is back where it started, and
 * nothing outside `StockEngine::apply()` ever observed those rows. Leaving them would be an audit
 * trail of a movement that did not happen, which is strictly worse than no trail.
 */
final class TransactionRepository extends Repository {

	protected function table(): string {
		return 'transactions';
	}

	protected function entity_type(): string {
		return 'transaction';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'item_id',
			'warehouse_id',
			'transaction_type',
			'quantity_change',
			'new_quantity',
			'original_quantity',
			'transaction_unit_id',
			'weight',
			'weight_unit',
			'weight_base',
			'cost_per_unit',
			'total_cost',
			'batch_id',
			'source_type',
			'source_id',
			'source_line_id',
			'recipient',
			'recipient_id',
			'reason',
			'occurred_at',
		);
	}

	/**
	 * `occurred_at` IS SORTABLE AND `created_at` STAYS SORTABLE TOO. They answer different
	 * questions -- "when did this move" against "when was it keyed in" -- and an auditor
	 * reconstructing what somebody did on a Tuesday wants the second.
	 *
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'occurred_at', 'created_at', 'transaction_type', 'quantity_change', 'new_quantity', 'total_cost' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array(
			'transaction_type' => array(
				'label'   => __( 'Type', 'plugixa-inventory' ),
				'sql'     => 't.transaction_type',
				'type'    => 'select',
				'options' => StockEngine::TRANSACTION_TYPES,
			),
			'item_id'          => array(
				'label'  => __( 'Item', 'plugixa-inventory' ),
				'sql'    => 't.item_id',
				'type'   => 'entity',
				'entity' => 'item',
			),
			'warehouse_id'     => array(
				'label'  => __( 'Warehouse', 'plugixa-inventory' ),
				'sql'    => 't.warehouse_id',
				'type'   => 'entity',
				'entity' => 'warehouse',
			),
			'recipient_id'     => array(
				'label'  => __( 'Recipient', 'plugixa-inventory' ),
				'sql'    => 't.recipient_id',
				'type'   => 'entity',
				'entity' => 'recipient',
			),
			/*
			 * `'stored' => 'utc'` IS NOT OPTIONAL HERE, AND ITS ABSENCE WAS A REAL BUG.
			 *
			 * `transactions.created_at` is written with `current_time( 'mysql', true )` -- UTC, like
			 * every audit column in this plugin. `Repository::common_filters()` tags its own
			 * `created_at`/`modified_at` with this flag so `FilterCompiler::date_predicate()` knows
			 * to convert the site-local day the date picker sent into a UTC instant before
			 * comparing. This descriptor is hand-written rather than inherited from
			 * `common_filters()` -- the ledger has its own set -- so it silently defaulted to
			 * 'local', and filtering the ledger by date was out by the site's offset: on a Tokyo
			 * site, nine hours of the wrong day.
			 *
			 * A UTC dev site hides this completely, which is why it survived P7's sweep.
			 *
			 * IT FILTERS `occurred_at`, NOT `created_at`, and the label is why. "Date" on a ledger
			 * screen means the date the stock moved -- the one the document carries and the one the
			 * row displays. Filtering on the write time instead would answer a question nobody
			 * asked and disagree with the column beside it the moment anything was backdated.
			 */
			'occurred_at'      => array(
				'label'  => __( 'Date', 'plugixa-inventory' ),
				'sql'    => 't.occurred_at',
				'type'   => 'date',
				'stored' => 'utc',
			),
			'created_at'       => array(
				'label'  => __( 'Entered', 'plugixa-inventory' ),
				'sql'    => 't.created_at',
				'type'   => 'date',
				'stored' => 'utc',
			),
		);
	}

	/**
	 * The ledger cannot be trashed.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		unset( $id );

		return false;
	}

	/**
	 * The ledger cannot be destroyed either.
	 *
	 * @param int $id Row id.
	 */
	public function purge( int $id ): bool {
		unset( $id );

		return false;
	}

	/**
	 * Un-write a batch that failed part-way.
	 *
	 * THE ONLY `DELETE` AGAINST THIS TABLE ANYWHERE. Reachable from `StockEngine::rollback()` and
	 * nowhere else; keyed on `batch_id` alone, so it cannot take a row that belongs to a different
	 * document even if the caller passes something odd.
	 *
	 * A hard DELETE rather than a soft one: a soft-deleted ledger row would still be summed by
	 * anything that forgot the `deleted_at` clause, and the whole point is that these rows describe
	 * a movement that never happened.
	 *
	 * @param string $batch_id The batch to remove.
	 *
	 * @return int How many rows went.
	 */
	public function purge_batch( string $batch_id ): int {
		global $wpdb;

		if ( '' === $batch_id ) {
			// A blank batch id would match every row written before batching existed. Refusing is
			// the only safe reading of "delete everything with no batch".
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$done = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE batch_id = %s', $this->table_name(), $batch_id )
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Every row of one batch, oldest first.
	 *
	 * @param string $batch_id The batch.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_batch( string $batch_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %s ORDER BY id ASC', $this->table_name(), $batch_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * The ledger's own idea of what is on hand, per (item, warehouse).
	 *
	 * THE QUERY THE WHOLE "DERIVABLE" CLAIM RESTS ON. `KEY item_warehouse (item_id, warehouse_id, id)`
	 * exists for exactly this GROUP BY and for nothing else.
	 *
	 * @param int|null $item_id Narrow to one item, or null for all.
	 *
	 * @return array<string, float> Keyed "itemId:warehouseId".
	 */
	public function computed_balances( ?int $item_id = null ): array {
		global $wpdb;

		if ( null === $item_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT item_id, warehouse_id, SUM(quantity_change) AS computed
					 FROM %i WHERE deleted_at IS NULL GROUP BY item_id, warehouse_id',
					$this->table_name()
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT item_id, warehouse_id, SUM(quantity_change) AS computed
					 FROM %i WHERE deleted_at IS NULL AND item_id = %d GROUP BY item_id, warehouse_id',
					$this->table_name(),
					$item_id
				),
				ARRAY_A
			);
		}

		$balances = array();

		foreach ( (array) $rows as $row ) {
			$balances[ $row['item_id'] . ':' . $row['warehouse_id'] ] = (float) $row['computed'];
		}

		return $balances;
	}

	/**
	 * One pair's receipts, collapsed to one row per day, newest first — only as far back as needed.
	 *
	 * WHAT OPENING COST LAYERS ARE RECONSTRUCTED FROM. A site switching to FIFO has stock that
	 * arrived before layers existed; the ledger is the only record of when it arrived and what it
	 * cost, and attributing today's quantity back across those receipts newest-first reproduces
	 * exactly the layers that would exist had FIFO always been on — because consuming oldest-first
	 * is what leaves the newest receipts standing.
	 *
	 * COLLAPSED TO A DAY, and the ageing report does the same thing for the same reason: two
	 * receipts of the same item into the same warehouse on one Tuesday are one layer's worth of
	 * fact, and a busy item then produces one row a day here rather than twenty-four.
	 *
	 * `cost` IS WEIGHTED, NOT AN AVERAGE OF THE ROWS. Taking `AVG(cost_per_unit)` over a day that
	 * received 1 unit at 50.00 and 999 at 1.00 gives 25.50, which is not what that day cost.
	 * Dividing the day's total cost by the day's quantity gives 1.05, which is.
	 *
	 * TRANSFERS IN ARE EXCLUDED. A transfer creates its destination layer directly, carrying the
	 * source layer's cost and date; counting `transfer_in` here as well would attribute the same
	 * physical stock twice and seed a warehouse with quantity it does not hold.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * ONE PAIR, PAGED, STOPPING ONCE THE STOCK ON HAND IS COVERED.
	 *
	 * This used to answer for up to 500 items at once under one shared budget of 5,000 receipt-days.
	 * Three months of daily receipts is ~92 days a pair, so a full batch of pairs blew the budget
	 * about a tenth of the way through: every pair after that got NO history, and its whole stock was
	 * seeded as one layer dated today at list price — three-month-old stock in the 0-30 day bucket.
	 *
	 * The walk only ever needs the newest receipts that add up to what is on hand, because consuming
	 * oldest-first is what leaves those standing. So this reads one pair's days, newest first, a page
	 * at a time, and stops as soon as they cover `$covering` — usually on the first page. It is also
	 * what the first touch of a pair can afford to call inside a finalize.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int   $item_id      The item.
	 * @param int   $warehouse_id The warehouse.
	 * @param float $covering     How much stock the days must account for.
	 * @param int   $page         Receipt-days per query.
	 *
	 * @return array<int, array{received_on: string, quantity: float, cost: float}> Newest first.
	 */
	public function receipt_days_for_pair( int $item_id, int $warehouse_id, float $covering, int $page = 200 ): array {
		global $wpdb;

		/*
		 * THE DAY IS THE READER'S DAY. `occurred_at` is UTC, so `DATE( occurred_at )` cut the day at
		 * UTC midnight — on a Tokyo site, 9am local, which filed every receipt before 9am under the
		 * previous day and gave the ageing report the wrong bucket for a third of every day.
		 * {@see \PlugixaInventory\Core\WallClock::utc_offset()} for why the offset is added as bound
		 * seconds rather than with CONVERT_TZ, and for the daylight-saving limit that follows.
		 */
		$offset  = WallClock::utc_offset();
		$page    = max( 1, $page );
		$days    = array();
		$covered = 0.0;
		$before  = null;

		// Fifty pages is 10,000 receipt-days on one pair — decades of daily deliveries. A pair with
		// more is seeded from what was read, and its remainder takes the item's cost (see the seeder).
		for ( $round = 0; $round < 50; $round++ ) {
			if ( null === $before ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, so there is no object cache to read from.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT DATE(occurred_at + INTERVAL %d SECOND) AS received_on,
						        SUM(quantity_change) AS quantity, SUM(total_cost) AS total_cost
						   FROM %i
						  WHERE item_id = %d AND warehouse_id = %d
						    AND deleted_at IS NULL
						    AND quantity_change > 0
						    AND transaction_type <> \'transfer_in\'
						  GROUP BY DATE(occurred_at + INTERVAL %d SECOND)
						  ORDER BY received_on DESC
						  LIMIT %d',
						$offset,
						$this->table_name(),
						$item_id,
						$warehouse_id,
						$offset,
						$page
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, so there is no object cache to read from.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT DATE(occurred_at + INTERVAL %d SECOND) AS received_on,
						        SUM(quantity_change) AS quantity, SUM(total_cost) AS total_cost
						   FROM %i
						  WHERE item_id = %d AND warehouse_id = %d
						    AND deleted_at IS NULL
						    AND quantity_change > 0
						    AND transaction_type <> \'transfer_in\'
						    AND occurred_at < %s
						  GROUP BY DATE(occurred_at + INTERVAL %d SECOND)
						  ORDER BY received_on DESC
						  LIMIT %d',
						$offset,
						$this->table_name(),
						$item_id,
						$warehouse_id,
						$before,
						$offset,
						$page
					),
					ARRAY_A
				);
			}

			$rows = (array) $rows;

			foreach ( $rows as $row ) {
				$quantity = (float) $row['quantity'];

				$days[] = array(
					'received_on' => (string) $row['received_on'],
					'quantity'    => $quantity,
					'cost'        => $quantity > 0.0 ? (float) $row['total_cost'] / $quantity : 0.0,
				);

				$covered += $quantity;
			}

			if ( count( $rows ) < $page || $covered >= $covering - ItemStockRepository::EPSILON ) {
				break;
			}

			// The next page starts before LOCAL midnight of the oldest day read, expressed in UTC.
			$oldest = (string) $rows[ count( $rows ) - 1 ]['received_on'];
			$before = gmdate( 'Y-m-d H:i:s', (int) strtotime( $oldest . ' 00:00:00 UTC' ) - $offset );
		}

		return $days;
	}

	/**
	 * Whether an item has any ledger history — the question that decides if it can be trashed.
	 *
	 * @param int $item_id The item.
	 */
	public function item_has_history( int $item_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE item_id = %d LIMIT 1', $this->table_name(), $item_id )
		);

		return (int) $count > 0;
	}

	/**
	 * Whether a warehouse appears anywhere in the ledger.
	 *
	 * @param int $warehouse_id The warehouse.
	 */
	public function warehouse_has_history( int $warehouse_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE warehouse_id = %d LIMIT 1', $this->table_name(), $warehouse_id )
		);

		return (int) $count > 0;
	}

	/**
	 * Whether anything was ever checked out to a recipient.
	 *
	 * @param int $recipient_id The recipient.
	 */
	public function recipient_has_history( int $recipient_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id = %d LIMIT 1', $this->table_name(), $recipient_id )
		);

		return (int) $count > 0;
	}

	/**
	 * Give the numeric columns their real types.
	 *
	 * @param array<string, mixed> $row One row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'item_id', 'warehouse_id', 'source_id', 'source_line_id', 'created_by', 'modified_by' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		foreach ( array( 'transaction_unit_id', 'recipient_id' ) as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
			}
		}

		foreach ( array( 'quantity_change', 'new_quantity', 'original_quantity', 'weight', 'weight_base', 'cost_per_unit', 'total_cost' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (float) $row[ $column ];
			}
		}

		return $row;
	}
}
