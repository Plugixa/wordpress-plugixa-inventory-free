<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Dashboard\Http;

use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Stock\Services\InventoryReports;
use PlugixaInventory\Modules\Stock\Repositories\TransactionRepository;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The opening screen, in one request.
 *
 * SIX FIGURES, ONE ROUND TRIP, and that is the whole reason this module exists rather than the
 * dashboard calling six existing endpoints. The component this ports does exactly that — six
 * `getStats`/`getMiniTrends`/`getTopMovers` calls — and the screen assembles itself in six visible
 * steps, tiles first and charts last, which reads as slowness whatever the total time is. Served
 * together they also agree with each other: six requests are six snapshots taken as stock moves
 * between them, and a dashboard whose "total items" disagrees with its own "stock by type" chart is
 * a support ticket nobody can reproduce.
 *
 * IT OWNS NO TABLES AND NO LOGIC. Every number here is `InventoryReports` answering, or a
 * repository read. That is deliberate: the dashboard and the Reports screen must never be able to
 * disagree about what "stock value" means, and the only way to guarantee that is for one of them to
 * have no opinion.
 */
final class DashboardController extends RestController {

	/** Rows in each of the two lists. Enough to scan, few enough to not become a report. */
	private const RECENT = 8;
	private const LOW_STOCK = 8;

	/** The trend window, in days. */
	private const TREND_DAYS = 30;

	/** How far back "top movers" looks — a week, because it is about right now. */
	private const MOVER_DAYS = 7;

	public function __construct(
		private readonly InventoryReports $reports,
		private readonly ItemRepository $items,
		private readonly TransactionRepository $ledger
	) {}

	protected function entity_type(): string {
		return 'dashboard';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/dashboard',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok(
			array(
				'totals'       => $this->reports->totals(),
				'by_type'      => $this->reports->value_by_type(),
				/*
				 * WHAT IS WHERE, for the warehouses tile. Already written and already used by the
				 * valuation report, so the tile and the report cannot disagree about what a
				 * warehouse holds -- which is the whole reason this screen asks one service for
				 * everything rather than counting rows itself.
				 */
				'by_warehouse' => $this->reports->value_by_warehouse(),
				'trend'        => $this->reports->movement_by_day( self::TREND_DAYS ),
				'top_movers'   => $this->reports->top_movers( self::MOVER_DAYS, 5 ),
				'kpi'          => $this->reports->kpi_trends( self::TREND_DAYS ),
				'low_stock'    => $this->items->low_stock( self::LOW_STOCK ),
				'recent'       => $this->recent(),
			)
		);
	}

	/**
	 * The last few things that happened to stock.
	 *
	 * ORDERED BY id, NOT BY created_at, and the difference shows up exactly when it matters. A
	 * finalized document writes its lines in one batch inside a single second, so ordering by a
	 * DATETIME leaves MySQL free to return them in any order — and the activity list shows the
	 * three lines of one movement shuffled. The id is monotonic and is the order they were written.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recent(): array {
		$rows = $this->ledger->query( 'dashboard' )
			->order_by( 't.id', 'DESC' )
			->paginate( self::RECENT, 0 )
			->get();

		$rows = array_map( array( $this->ledger, 'cast' ), $rows );

		/*
		 * The names behind the ids, resolved through the record-names filter rather than by joining
		 * — so this module names no table but its own reads, exactly as TransactionsController does
		 * for the same rows on the ledger screen.
		 */
		foreach ( array( 'item_id' => 'item', 'warehouse_id' => 'warehouse' ) as $field => $entity ) {
			$ids = array_values(
				array_unique(
					array_filter(
						array_map( static fn( array $row ): int => (int) ( $row[ $field ] ?? 0 ), $rows )
					)
				)
			);

			$names = array() === $ids
				? array()
				: (array) apply_filters( 'plugixa_inventory_record_names', array(), $entity, $ids );

			$rows = array_map(
				static function ( array $row ) use ( $field, $entity, $names ): array {
					$id = (int) ( $row[ $field ] ?? 0 );

					$row[ $entity . '_name' ] = $id > 0 ? ( $names[ $id ] ?? null ) : null;

					return $row;
				},
				$rows
			);
		}

		return $rows;
	}
}
