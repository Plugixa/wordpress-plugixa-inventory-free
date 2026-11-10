<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Items\Repositories;

use PlugixaInventory\Core\Database\IdList;
use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Modules\Items\Services\ItemService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The item catalogue.
 */
final class ItemRepository extends Repository {

	protected function table(): string {
		return 'items';
	}

	protected function entity_type(): string {
		return 'item';
	}

	/**
	 * The columns an ordinary edit may write.
	 *
	 * `stock_quantity` IS ABSENT ON PURPOSE. It is a cache of the Stock module's `item_stock`
	 * rows, and letting an item form write it would mean a number that no longer follows from the
	 * ledger — silently, and only discoverable by a reconcile weeks later. Stock writes it through
	 * the one narrow method built for it, `set_cached_stock()`.
	 *
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'name',
			'sku',
			'barcode',
			'serial_number',
			'item_type',
			'category_id',
			'default_warehouse_id',
			'unit_id',
			'image_id',
			'reorder_level',
			'unit_price',
			'cost_price',
			'weight',
			'weight_unit',
			'weight_base',
			'track_weight',
			'status',
			'description',
			'assigned_user_id',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array(
			'id',
			'name',
			'sku',
			'barcode',
			'item_type',
			'stock_quantity',
			'reorder_level',
			'unit_price',
			'status',
			'created_at',
			'modified_at',
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array_merge(
			$this->common_filters(),
			array(
				'status'               => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'sql'     => 't.status',
					'type'    => 'select',
					'options' => ItemService::STATUSES,
				),
				'item_type'            => array(
					'label'   => __( 'Type', 'plugixa-inventory' ),
					'sql'     => 't.item_type',
					'type'    => 'select',
					'options' => ItemService::TYPES,
				),
				'category_id'          => array(
					'label'  => __( 'Category', 'plugixa-inventory' ),
					'sql'    => 't.category_id',
					'type'   => 'entity',
					'entity' => 'category',
				),
				'default_warehouse_id' => array(
					'label'  => __( 'Default warehouse', 'plugixa-inventory' ),
					'sql'    => 't.default_warehouse_id',
					'type'   => 'entity',
					'entity' => 'warehouse',
				),
				'unit_id'              => array(
					'label'  => __( 'Unit', 'plugixa-inventory' ),
					'sql'    => 't.unit_id',
					'type'   => 'entity',
					'entity' => 'unit',
				),
				'unit_price'           => array(
					'label' => __( 'Unit price', 'plugixa-inventory' ),
					'sql'   => 't.unit_price',
					'type'  => 'number',
				),
			)
		);
	}

	/**
	 * A live item with this SKU, if there is one.
	 *
	 * @param string $sku     SKU to look for.
	 * @param int    $exclude Row id to ignore.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_sku( string $sku, int $exclude = 0 ): ?array {
		return $this->find_by_column( 'sku', $sku, $exclude );
	}

	/**
	 * A live item with this barcode, if there is one.
	 *
	 * @param string $barcode Barcode to look for.
	 * @param int    $exclude Row id to ignore.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_barcode( string $barcode, int $exclude = 0 ): ?array {
		return $this->find_by_column( 'barcode', $barcode, $exclude );
	}

	/**
	 * One live row matched on a single column.
	 *
	 * The column name is bound with `%i` rather than interpolated, even though both call sites pass
	 * a literal. A helper that takes a column and concatenates it is one refactor away from taking
	 * one off a request.
	 *
	 * @param string $column  Column to match.
	 * @param string $value   Value to match.
	 * @param int    $exclude Row id to ignore.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_by_column( string $column, string $value, int $exclude ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE %i = %s AND id <> %d AND deleted_at IS NULL LIMIT 1',
				$this->table_name(),
				$column,
				$value,
				$exclude
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Write the denormalised stock total.
	 *
	 * NOT `update()`, AND THE DIFFERENCE MATTERS. `Repository::update()` fires
	 * `plugixa_inventory_entity_updated` plus one `plugixa_inventory_entity_field_changed` per
	 * changed column — which is the seam a workflow or notification module hangs triggers off.
	 * Finalizing a stock movement of forty lines would emit forty of those, for a column the user
	 * never touched. So this is a direct two-column write that raises one event of its own instead.
	 *
	 * Called only by the Stock module, through `ItemService::set_cached_stock()`.
	 *
	 * @param int   $id    Item id.
	 * @param float $total The item's total across every warehouse.
	 */
	public function set_cached_stock( int $id, float $total ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$done = $wpdb->update(
			$this->table_name(),
			array(
				'stock_quantity' => $total,
				'modified_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		return false !== $done;
	}

	/**
	 * The stored `stock_quantity` of every item that could possibly be wrong.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE CANDIDATE SET, NOT THE CATALOGUE. An item with no stock rows and a cached total of zero
	 * agrees with itself trivially; on a site with 50,000 items that is nearly all of them, and
	 * loading them to discover it is the query a reconcile gets remembered for. So the set is
	 * "claims a non-zero total" (which can be wrong on its own — the drift where an item says 42.5
	 * and has no stock row at all) UNION "has stock rows" (which the caller has just read).
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * TRASHED ITEMS ARE INCLUDED. `item_stock` rows survive a soft delete so that a restore brings
	 * the stock back, and a reconcile that skipped them would report their pairs as orphaned.
	 *
	 * @param int|null $item_id Narrow to one item, or null.
	 * @param int[]    $ids     Items to include regardless of their stored total.
	 *
	 * @return array<int, float> Item id => stored `stock_quantity`.
	 */
	public function cached_totals( ?int $item_id = null, array $ids = array() ): array {
		global $wpdb;

		if ( null !== $item_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, stock_quantity FROM %i WHERE id = %d',
					$this->table_name(),
					$item_id
				),
				ARRAY_A
			);
		} else {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

			/*
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 * THE UNION IS TWO QUERIES, NOT ONE `OR`, AND THAT IS NOW THE CHEAPER SHAPE TOO.
			 *
			 * This used to assemble a `$where` string conditionally and interpolate it, which is
			 * the ONE query in the plugin that `PluginCheck.Security.DirectDB.UnescapedDBParameter`
			 * flagged — the sniff a wordpress.org reviewer runs and that WPCS does not ship. It then
			 * became two branches with an interpolated `%d` run, which the round-2 review rejects
			 * as well: the string handed to prepare() has to be a literal, placeholders included.
			 *
			 * So the `OR` is gone. The two halves of the documented union — "claims a non-zero
			 * total" and "has stock rows" — are now separate literal queries merged in PHP, which
			 * is what the docblock above always described. Splitting them also removes the reason
			 * the `OR` was expensive: it made the id half unindexable, so the query scanned the
			 * whole items table even when the caller named ten of them. Duplicates between the two
			 * halves collapse on the way into $totals, which is keyed by id.
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, stock_quantity FROM %i WHERE stock_quantity <> 0', $this->table_name() ),
				ARRAY_A
			);

			foreach ( IdList::chunks( $ids ) as $batch ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
				$named = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, stock_quantity FROM %i
						  WHERE id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
						$this->table_name(),
						$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
						$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
						$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
						$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
					),
					ARRAY_A
				);

				$rows = array_merge( (array) $rows, (array) $named );
			}
		}

		$totals = array();

		foreach ( (array) $rows as $row ) {
			$totals[ (int) $row['id'] ] = (float) $row['stock_quantity'];
		}

		return $totals;
	}

	/**
	 * The three facts the costing strategy needs about a set of items, in one query.
	 *
	 * ONE QUERY FOR A SET, NOT `find()` PER ITEM, and that is the entire reason this exists rather
	 * than the caller looping. Seeding opening cost layers looks at every (item, warehouse) pair
	 * holding stock; on a site with four hundred items that is four hundred round trips to read two
	 * columns, inside the stock lock, while every other finalize waits behind it.
	 *
	 * TRASHED ITEMS ARE INCLUDED, deliberately. An item can be soft-deleted while `item_stock` still
	 * has rows for it — that is the settled behaviour, so a restore brings the stock back — and a
	 * reconcile that could not see those items would report their pairs as unexplainable.
	 *
	 * @param int[] $ids Item ids.
	 *
	 * @return array<int, array{item_type: string, unit_price: float, cost_price: float}>
	 */
	public function basics( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$basics = array();

		// One literal query per batch of twenty. {@see \PlugixaInventory\Core\Database\IdList}.
		foreach ( IdList::chunks( $ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, item_type, unit_price, cost_price FROM %i
					  WHERE id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
					$this->table_name(),
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$basics[ (int) $row['id'] ] = array(
					'item_type'  => (string) $row['item_type'],
					'unit_price' => (float) $row['unit_price'],
					'cost_price' => (float) $row['cost_price'],
				);
			}
		}

		return $basics;
	}

	/**
	 * What a document line shows about each of its items — name, SKU and type — in one query.
	 *
	 * `names()` IS NOT ENOUGH, and that is why this sits beside it. A line renders its item's name,
	 * its SKU in a column of its own, and a type chip that the movement form also reads to drop the
	 * lines a new movement type no longer accepts. Three facts from one row: asking `names()` and
	 * then fetching the other two separately would be two queries where one does.
	 *
	 * TRASHED ITEMS ARE INCLUDED, for the reason `names()` gives. A draft can still point at an item
	 * trashed since, and "#18 no longer exists" tells the reader nothing they can act on, where the
	 * name tells them exactly which line to remove.
	 *
	 * @param int[] $ids Item ids.
	 *
	 * @return array<int, array{name: string, sku: string|null, item_type: string}>
	 */
	public function labels( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$labels = array();

		// One literal query per batch of twenty. {@see \PlugixaInventory\Core\Database\IdList}.
		foreach ( IdList::chunks( $ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, name, sku, item_type FROM %i
					  WHERE id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
					$this->table_name(),
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$labels[ (int) $row['id'] ] = array(
					'name'      => (string) $row['name'],
					'sku'       => null === $row['sku'] ? null : (string) $row['sku'],
					'item_type' => (string) $row['item_type'],
				);
			}
		}

		return $labels;
	}

	/**
	 * Items at or below their reorder level.
	 *
	 * `reorder_level > 0` IS PART OF THE CONDITION, not an optimisation. Zero means "no level set",
	 * and an item with no level would otherwise be permanently low the moment it hit zero stock —
	 * which is every item that has never been received.
	 *
	 * @param int $limit How many to return.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function low_stock( int $limit = 50 ): array {
		return array_map(
			array( $this, 'cast' ),
			$this->query()
				->where( 't.status = %s', array( 'published' ) )
				->where( 't.reorder_level > 0', array() )
				->where( 't.stock_quantity <= t.reorder_level', array() )
				->order_by( 't.stock_quantity', 'ASC' )
				->paginate( max( 1, $limit ), 0 )
				->get()
		);
	}

	/**
	 * Give the numeric columns their real types.
	 *
	 * The foreign keys stay NULL rather than becoming 0: "no category" is not category zero, and a
	 * client that renders a link whenever the id is set would draw a broken one for every item.
	 *
	 * @param array<string, mixed> $row One row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'created_by', 'modified_by' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		foreach ( array( 'category_id', 'default_warehouse_id', 'unit_id', 'image_id', 'assigned_user_id' ) as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
			}
		}

		foreach ( array( 'stock_quantity', 'reorder_level', 'unit_price', 'cost_price', 'weight', 'weight_base' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (float) $row[ $column ];
			}
		}

		if ( isset( $row['track_weight'] ) ) {
			$row['track_weight'] = (bool) (int) $row['track_weight'];
		}

		return $row;
	}
}
