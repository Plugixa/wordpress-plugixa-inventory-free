<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\WarehouseDashboard\Services;

use PlugixaInventory\Modules\Stock\Services\InventoryReports;
use PlugixaInventory\Modules\Stock\Repositories\ItemStockRepository;
use PlugixaInventory\Modules\Stock\Repositories\TransactionRepository;
use PlugixaInventory\Modules\Warehouses\Repositories\WarehouseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is going on in each warehouse.
 *
 * IT HOLDS NO ARITHMETIC OF ITS OWN, for the reason `Modules\Dashboard` gives at length: every
 * figure here also appears on a report or a list, and a tile that disagrees with the screen it
 * links to destroys trust in both. Valuation comes from `InventoryReports` — the same service the
 * valuation report uses — and everything else is a COUNT through the owning module's own
 * repository, so this file names no table but reads through the classes that do.
 *
 * ACTIVITY IS THE LEDGER, WHICH IS WHY THERE IS NO SEPARATE TRANSFERS OR ADJUSTMENTS FEED. The
 * Joomla screen this follows has five tabs, two of which are pre-filtered slices of the third:
 * `transactions` already carries `transfer_in`, `transfer_out` and every `adjustment_*` row for
 * this warehouse. One feed with the type on each line says the same thing and cannot drift from
 * itself.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE TILES A DOCUMENT MODULE OWNS ARE CONTRIBUTED, NOT INJECTED.
 *
 * This class used to take `TransferRepository` and `AdjustmentRepository` in its constructor for
 * two COUNTs — a warehouse's pending transfers, and how many adjustments it has seen. That made a
 * dashboard depend on two document modules in order to boot at all: a build without either one is
 * not a dashboard with a missing tile, it is a container that cannot construct this class, and
 * every warehouse screen answers 500.
 *
 * `plugixa_inventory_warehouse_metrics` inverts it. A module that has something to say about a
 * warehouse says it; this class merges whatever turned up into the card. A key that nobody
 * contributed is simply absent, and the React card renders a tile only for the keys it is handed —
 * so the tile disappears with the module rather than reading zero for ever, which is a different
 * and much worse claim.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class WarehouseInsights {

	/** The window "recent" means, in days. A week, because it is about right now. */
	private const RECENT_DAYS = 7;

	public function __construct(
		private readonly InventoryReports $reports,
		private readonly WarehouseRepository $warehouses,
		private readonly ItemStockRepository $stock,
		private readonly TransactionRepository $ledger
	) {}

	/**
	 * What every other module has to say about one warehouse.
	 *
	 * @param int    $id      The warehouse.
	 * @param string $context 'card' for the grid, 'detail' for the one-warehouse page. A listener
	 *                        that runs an expensive query can answer only where it is shown.
	 *
	 * @return array<string, mixed>
	 */
	private function contributed( int $id, string $context ): array {
		/**
		 * Filter the extra figures shown for a warehouse.
		 *
		 * Each key becomes a tile. Contribute a scalar, or an array for a tile with parts (the
		 * transfer tile is `in` and `out`). Keys are merged into the card, so pick one that names
		 * the module rather than the shape.
		 *
		 * @param array<string, mixed> $metrics Metric key => value.
		 * @param int                  $id      The warehouse.
		 * @param string               $context 'card' or 'detail'.
		 */
		return (array) apply_filters( 'plugixa_inventory_warehouse_metrics', array(), $id, $context );
	}

	/**
	 * One card per warehouse.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function cards(): array {
		$rows = $this->warehouses->query( 'list' )->order_by( 'name', 'ASC' )->get();

		// One valuation query for every warehouse, keyed for lookup — rather than one per card.
		$valued = array();

		foreach ( $this->reports->value_by_warehouse() as $group ) {
			$valued[ (int) $group['warehouse_id'] ] = $group;
		}

		$cards = array();

		foreach ( $rows as $row ) {
			$row = $this->warehouses->cast( $row );
			$id  = (int) $row['id'];

			$value = $valued[ $id ] ?? array();

			$cards[] = array(
				'id'          => $id,
				'name'        => (string) ( $row['name'] ?? '' ),
				'code'        => (string) ( $row['code'] ?? '' ),
				'address'     => (string) ( $row['address'] ?? '' ),
				'status'      => (string) ( $row['status'] ?? '' ),
				'item_count'  => (int) ( $value['item_count'] ?? 0 ),
				'total_value' => (float) ( $value['total_value'] ?? 0 ),
				'low_stock'   => $this->low_stock_count( $id ),
				'activity'    => $this->activity_count( $id ),
			) + $this->contributed( $id, 'card' );
		}

		return $cards;
	}

	/**
	 * The tiles, the shortest low-stock list and the latest movements for one warehouse.
	 *
	 * @param int $id The warehouse.
	 *
	 * @return array<string, mixed>|null Null when there is no such warehouse.
	 */
	public function detail( int $id ): ?array {
		$row = $this->warehouses->find( $id );

		if ( null === $row ) {
			return null;
		}

		$row = $this->warehouses->cast( $row );

		$valued = array();

		foreach ( $this->reports->value_by_warehouse() as $group ) {
			if ( (int) $group['warehouse_id'] === $id ) {
				$valued = $group;
				break;
			}
		}

		return array(
			'warehouse' => $row,
			'totals'    => array(
				'item_count'  => (int) ( $valued['item_count'] ?? 0 ),
				'total_value' => (float) ( $valued['total_value'] ?? 0 ),
				'low_stock'   => $this->low_stock_count( $id ),
				'activity'    => $this->activity_count( $id ),
			) + $this->contributed( $id, 'detail' ),
			'low_stock' => $this->low_stock( $id ),
			'recent'    => $this->activity( $id, 8, 0 )['rows'],
		);
	}

	/**
	 * What this warehouse holds, paginated.
	 *
	 * @param int    $id     The warehouse.
	 * @param string $search Item name or SKU.
	 * @param int    $limit  Page size.
	 * @param int    $offset Page offset.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function stock( int $id, string $search, int $limit, int $offset ): array {
		return $this->stock->for_warehouse( $id, $search, $limit, $offset );
	}

	/**
	 * Everything that has happened to stock here, newest first.
	 *
	 * ORDERED BY id, NOT BY created_at, for the reason `DashboardController::recent()` records: a
	 * finalized document writes its lines in one batch inside a single second, so ordering by a
	 * DATETIME leaves MySQL free to return the lines of one movement shuffled.
	 *
	 * @param int $id     The warehouse.
	 * @param int $limit  Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function activity( int $id, int $limit, int $offset ): array {
		$total = $this->ledger->query( 'count' )
			->where( 't.warehouse_id = %d', array( $id ) )
			->count();

		$rows = $this->ledger->query( 'dashboard' )
			->where( 't.warehouse_id = %d', array( $id ) )
			->order_by( 't.id', 'DESC' )
			->paginate( $limit, $offset )
			->get();

		$rows = array_map( array( $this->ledger, 'cast' ), $rows );

		return array(
			'rows'  => $this->name_items( $rows ),
			'total' => $total,
		);
	}

	/**
	 * The few items here that are at or below their reorder level.
	 *
	 * @param int $id The warehouse.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function low_stock( int $id ): array {
		$short = $this->low_stock_rows( $id );

		usort(
			$short,
			static fn( array $a, array $b ): int => ( $a['quantity'] / max( $a['reorder_level'], 1 ) )
				<=> ( $b['quantity'] / max( $b['reorder_level'], 1 ) )
		);

		return array_slice( $short, 0, 6 );
	}

	/**
	 * How many items here are at or below their reorder level.
	 *
	 * A REORDER LEVEL IS A PROPERTY OF THE ITEM, NOT OF THE PAIR, so this asks "how much of it is
	 * HERE, against the level set for it" rather than reusing the global low-stock count — an item
	 * short overall may be perfectly stocked in this building, and the reverse.
	 *
	 * @param int $id The warehouse.
	 */
	private function low_stock_count( int $id ): int {
		return count( $this->low_stock_rows( $id ) );
	}

	/**
	 * @param int $id The warehouse.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function low_stock_rows( int $id ): array {
		$held = $this->stock->for_warehouse( $id, '', 500, 0 );

		return array_values(
			array_filter(
				$held['rows'],
				static fn( array $row ): bool => $row['reorder_level'] > 0
					&& $row['quantity'] <= $row['reorder_level']
			)
		);
	}

	/**
	 * Ledger entries here in the last week.
	 *
	 * @param int $id The warehouse.
	 */
	private function activity_count( int $id ): int {
		$since = gmdate( 'Y-m-d H:i:s', time() - ( self::RECENT_DAYS * DAY_IN_SECONDS ) );

		return $this->ledger->query( 'count' )
			->where( 't.warehouse_id = %d', array( $id ) )
			->where( 't.occurred_at >= %s', array( $since ) )
			->count();
	}

	/**
	 * Attach item names to ledger rows through the record-names filter.
	 *
	 * Through the filter rather than a JOIN, so this module names no table but its own reads —
	 * exactly as `DashboardController` and `TransactionsController` do for the same rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Ledger rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function name_items( array $rows ): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( static fn( array $row ): int => (int) ( $row['item_id'] ?? 0 ), $rows )
				)
			)
		);

		$names = array() === $ids
			? array()
			: (array) apply_filters( 'plugixa_inventory_record_names', array(), 'item', $ids );

		return array_map(
			static function ( array $row ) use ( $names ): array {
				$id = (int) ( $row['item_id'] ?? 0 );

				$row['item_name'] = $id > 0 ? ( $names[ $id ] ?? null ) : null;

				return $row;
			},
			$rows
		);
	}
}
