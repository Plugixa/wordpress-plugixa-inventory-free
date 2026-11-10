<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Repositories;

use PlugixaInventory\Core\Database\IdList;
use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How much of each item is in each warehouse.
 *
 * NOT A `Core\Database\Repository`, and the reason is in `Stock\Schema`: this table needs
 * `UNIQUE KEY (item_id, warehouse_id)`, a unique key cannot coexist with a soft delete, and the
 * base class writes `deleted_at` unconditionally. It also has nothing the base class offers — no
 * trash, no audit columns, no REST list — because it is derived state rather than user data.
 *
 * EVERY MUTATING METHOD HERE IS CALLABLE ONLY FROM `StockEngine`, which holds the lock. There is no
 * language-level way to enforce that in PHP, so it is enforced by the class not being bound as a
 * container service anything else resolves, and by this sentence. Writing to it from anywhere else
 * produces stock that no longer follows from the ledger.
 */
final class ItemStockRepository {

	/**
	 * Quantities compare at four decimal places, which is the column's precision.
	 *
	 * Anything smaller than half a unit at that precision is a float artefact rather than stock, and
	 * comparing without a tolerance is how a decrease of exactly the available quantity gets refused
	 * because 5.0 turned out to be 4.999999999999999.
	 */
	public const EPSILON = 0.00005;

	private function table(): string {
		return Db::table( 'item_stock' );
	}

	/**
	 * Make sure a row exists for this pair, without racing another request doing the same.
	 *
	 * `INSERT ... ON DUPLICATE KEY UPDATE item_id = item_id` is the idiom, and the no-op assignment
	 * is the point: it turns a concurrent insert into a harmless second statement rather than a
	 * duplicate-key error. `INSERT IGNORE` would also work and would additionally swallow every
	 * OTHER error the statement could raise, which is not a trade worth making.
	 *
	 * THIS ONLY WORKS BECAUSE OF THE UNIQUE KEY. The whole Tier-C argument in `Stock\Schema` is
	 * cashed out on this one line.
	 *
	 * @param int $item_id      The item.
	 * @param int $warehouse_id The warehouse.
	 */
	public function ensure_row( int $item_id, int $warehouse_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (item_id, warehouse_id, quantity, updated_at)
				 VALUES (%d, %d, 0.0000, %s)
				 ON DUPLICATE KEY UPDATE item_id = item_id',
				$this->table(),
				$item_id,
				$warehouse_id,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Apply a signed change, refusing to go short.
	 *
	 * THIS IS THE WHOLE FIX FOR THE JOOMLA COMPONENT'S OVERSELL, and it is one statement.
	 *
	 * Joomla reads the current quantity, compares it in PHP, and then writes back — three steps with
	 * two windows in them. Two concurrent finalizes both read 5, both decide 5 >= 3, and both write
	 * 2; six units ship and the ledger says three. Here the CHECK IS THE WHERE CLAUSE of the write,
	 * so there is no window at all: whichever statement runs second sees the quantity the first one
	 * left and matches no row.
	 *
	 * READING THE RESULT IS THE OTHER HALF. `$wpdb->query()` returns the affected-row count, and
	 * WordPress does NOT set `CLIENT_FOUND_ROWS` — so the count is of rows actually CHANGED. Since a
	 * `StockLine`'s delta is never zero, a matching row always reports 1, and 0 therefore means
	 * unambiguously "the guard failed" rather than "the value was already that".
	 *
	 * @param int   $item_id      The item.
	 * @param int   $warehouse_id The warehouse.
	 * @param float $delta        Signed. Never 0.
	 *
	 * @return bool|null True applied, false refused for insufficiency, null on a database error.
	 */
	public function apply_delta( int $item_id, int $warehouse_id, float $delta ): ?bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		if ( $delta >= 0 ) {
			// An increase has nothing to guard against.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$done = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET quantity = quantity + %f, updated_at = %s
					 WHERE item_id = %d AND warehouse_id = %d',
					$this->table(),
					$delta,
					$now,
					$item_id,
					$warehouse_id
				)
			);
		} else {
			/*
			 * The guard is `quantity >= needed - EPSILON`, not `quantity + delta >= 0`. Same
			 * arithmetic, but this form lets MySQL answer from the row it already has rather than
			 * evaluating an expression per row, and it puts the tolerance where a reader can see
			 * which comparison it applies to.
			 */
			$needed = abs( $delta ) - self::EPSILON;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$done = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET quantity = quantity + %f, updated_at = %s
					 WHERE item_id = %d AND warehouse_id = %d AND quantity >= %f',
					$this->table(),
					$delta,
					$now,
					$item_id,
					$warehouse_id,
					$needed
				)
			);
		}

		if ( false === $done ) {
			return null;
		}

		return $done > 0;
	}

	/**
	 * Put a quantity back, no questions asked.
	 *
	 * The compensating half of `apply_delta()`, used only by `StockEngine::rollback()`. Deliberately
	 * UNGUARDED: a compensating increase can never be short, and a compensating decrease is undoing
	 * an increase this same batch made moments ago. Guarding it would mean a rollback that could
	 * itself fail and leave the stock half-moved, which is strictly worse than the thing the guard
	 * would prevent.
	 *
	 * @param int   $item_id      The item.
	 * @param int   $warehouse_id The warehouse.
	 * @param float $delta        Signed. The inverse of what was applied.
	 */
	public function force_delta( int $item_id, int $warehouse_id, float $delta ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET quantity = quantity + %f, updated_at = %s
				 WHERE item_id = %d AND warehouse_id = %d',
				$this->table(),
				$delta,
				current_time( 'mysql', true ),
				$item_id,
				$warehouse_id
			)
		);
	}

	/**
	 * What is on hand for one pair.
	 *
	 * @param int $item_id      The item.
	 * @param int $warehouse_id The warehouse.
	 */
	public function quantity( int $item_id, int $warehouse_id ): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT quantity FROM %i WHERE item_id = %d AND warehouse_id = %d',
				$this->table(),
				$item_id,
				$warehouse_id
			)
		);

		return null === $value ? 0.0 : (float) $value;
	}

	/**
	 * An item's quantity in every warehouse that holds any, keyed by warehouse id.
	 *
	 * @param int $item_id The item.
	 *
	 * @return array<int, float>
	 */
	public function levels( int $item_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT warehouse_id, quantity FROM %i WHERE item_id = %d ORDER BY warehouse_id ASC',
				$this->table(),
				$item_id
			),
			ARRAY_A
		);

		$levels = array();

		foreach ( (array) $rows as $row ) {
			$levels[ (int) $row['warehouse_id'] ] = (float) $row['quantity'];
		}

		return $levels;
	}

	/**
	 * An item's total across every warehouse.
	 *
	 * @param int $item_id The item.
	 */
	public function total( int $item_id ): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(quantity) FROM %i WHERE item_id = %d', $this->table(), $item_id )
		);

		return null === $value ? 0.0 : (float) $value;
	}

	/**
	 * On-hand quantities for several items at once, keyed by item id.
	 *
	 * ONE QUERY FOR A PAGE OF ROWS, because the alternative is a query per row: a fifty-row item
	 * list asking `total()` per item is fifty round trips to render one screen.
	 *
	 * @param int[] $item_ids The items.
	 *
	 * @return array<int, float>
	 */
	public function totals( array $item_ids ): array {
		global $wpdb;

		$item_ids = array_values( array_unique( array_map( 'intval', $item_ids ) ) );

		if ( array() === $item_ids ) {
			return array();
		}

		$totals = array();

		/*
		 * BATCHING IS SAFE ACROSS THE `GROUP BY` because the ids are deduplicated first, so an item
		 * appears in exactly one batch and its sum is never split between two of them. The padding
		 * repeats an id already in the batch, and `IN` is set membership — a repeated id does not
		 * double a group's total. {@see \PlugixaInventory\Core\Database\IdList}.
		 */
		foreach ( IdList::chunks( $item_ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, so there is no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT item_id, SUM(quantity) AS total FROM %i
					  WHERE item_id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)
					  GROUP BY item_id',
					$this->table(),
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$totals[ (int) $row['item_id'] ] = (float) $row['total'];
			}
		}

		return $totals;
	}

	/**
	 * Whether a warehouse holds any stock at all — the question that decides if it can be trashed.
	 *
	 * NON-ZERO, NOT NON-EMPTY. A warehouse with rows that all read 0.0000 has held stock in the
	 * past and holds none now, which is not a reason to refuse a delete.
	 *
	 * @param int $warehouse_id The warehouse.
	 */
	public function warehouse_holds_stock( int $warehouse_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE warehouse_id = %d AND ABS(quantity) > %f',
				$this->table(),
				$warehouse_id,
				self::EPSILON
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Whether an item holds any stock anywhere.
	 *
	 * @param int $item_id The item.
	 */
	public function item_holds_stock( int $item_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE item_id = %d AND ABS(quantity) > %f',
				$this->table(),
				$item_id,
				self::EPSILON
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Every (item, warehouse) pair with its stored quantity, for the reconcile.
	 *
	 * @param int|null $item_id Narrow to one item, or null for all.
	 *
	 * @return array<string, float> Keyed "itemId:warehouseId".
	 */
	public function all_pairs( ?int $item_id = null ): array {
		global $wpdb;

		if ( null === $item_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT item_id, warehouse_id, quantity FROM %i', $this->table() ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT item_id, warehouse_id, quantity FROM %i WHERE item_id = %d', $this->table(), $item_id ),
				ARRAY_A
			);
		}

		$pairs = array();

		foreach ( (array) $rows as $row ) {
			$pairs[ $row['item_id'] . ':' . $row['warehouse_id'] ] = (float) $row['quantity'];
		}

		return $pairs;
	}

	/**
	 * What one warehouse holds, joined to the items so a list can be drawn from one query.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * ROWS WITH ZERO ARE EXCLUDED, AND THAT IS THE QUESTION BEING ASKED. `ensure_row()` creates a
	 * pair the first time stock is destined for it, so a warehouse accumulates zero rows for
	 * everything that has ever passed through — and "what is in this warehouse" answered with a
	 * hundred lines of 0.00 is a worse answer than a short one.
	 *
	 * A JOIN, WHICH THIS REPOSITORY OTHERWISE AVOIDS. Resolving names through the record-names
	 * filter is right for a handful of ledger rows; it is wrong for a paginated list that has to
	 * SORT and SEARCH by those names, because neither can be done after the fact in PHP without
	 * reading the whole table first.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int    $warehouse_id The warehouse.
	 * @param string $search       Item name or SKU fragment. Empty for everything.
	 * @param int    $limit        Page size.
	 * @param int    $offset       Page offset.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function for_warehouse( int $warehouse_id, string $search = '', int $limit = 25, int $offset = 0 ): array {
		global $wpdb;

		$items = Db::table( 'items' );

		/*
		 * FOUR WHOLE QUERIES RATHER THAN ONE BUILT FROM A $where FRAGMENT, and the fragment version
		 * is what `SqlLiteralTest` exists to catch: "the query string must be a CONSTANT LITERAL --
		 * not a variable holding one, and nothing interpolated into it". Every value was already
		 * bound, and it is still refused, because wordpress.org reads interpolation itself as the
		 * defect rather than auditing what was interpolated. Branching is the price of that rule
		 * and it is a low one.
		 */
		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i s INNER JOIN %i i ON i.id = s.item_id
					WHERE s.warehouse_id = %d AND s.quantity <> 0 AND i.deleted_at IS NULL',
					$this->table(),
					$items,
					$warehouse_id
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT i.id AS item_id, i.name, i.sku, i.item_type, i.unit_price, i.reorder_level, s.quantity
					FROM %i s INNER JOIN %i i ON i.id = s.item_id
					WHERE s.warehouse_id = %d AND s.quantity <> 0 AND i.deleted_at IS NULL
					ORDER BY i.name ASC
					LIMIT %d OFFSET %d',
					$this->table(),
					$items,
					$warehouse_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i s INNER JOIN %i i ON i.id = s.item_id
					WHERE s.warehouse_id = %d AND s.quantity <> 0 AND i.deleted_at IS NULL
						AND ( i.name LIKE %s OR i.sku LIKE %s )',
					$this->table(),
					$items,
					$warehouse_id,
					$like,
					$like
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT i.id AS item_id, i.name, i.sku, i.item_type, i.unit_price, i.reorder_level, s.quantity
					FROM %i s INNER JOIN %i i ON i.id = s.item_id
					WHERE s.warehouse_id = %d AND s.quantity <> 0 AND i.deleted_at IS NULL
						AND ( i.name LIKE %s OR i.sku LIKE %s )
					ORDER BY i.name ASC
					LIMIT %d OFFSET %d',
					$this->table(),
					$items,
					$warehouse_id,
					$like,
					$like,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		$cast = array();

		foreach ( (array) $rows as $row ) {
			$cast[] = array(
				'item_id'       => (int) $row['item_id'],
				'name'          => (string) $row['name'],
				'sku'           => (string) $row['sku'],
				'item_type'     => (string) $row['item_type'],
				'quantity'      => (float) $row['quantity'],
				'unit_price'    => (float) $row['unit_price'],
				'reorder_level' => (float) $row['reorder_level'],
			);
		}

		return array(
			'rows'  => $cast,
			'total' => $total,
		);
	}

	/**
	 * Set a pair to an exact quantity. The reconcile's repair, and nothing else.
	 *
	 * @param int   $item_id      The item.
	 * @param int   $warehouse_id The warehouse.
	 * @param float $quantity     What it should be.
	 */
	public function set_quantity( int $item_id, int $warehouse_id, float $quantity ): void {
		global $wpdb;

		$this->ensure_row( $item_id, $warehouse_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$wpdb->update(
			$this->table(),
			array(
				'quantity'   => $quantity,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'item_id'      => $item_id,
				'warehouse_id' => $warehouse_id,
			),
			array( '%f', '%s' ),
			array( '%d', '%d' )
		);
	}
}
