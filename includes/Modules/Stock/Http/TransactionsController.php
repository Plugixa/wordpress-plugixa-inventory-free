<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Http;

use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Modules\Stock\Repositories\ItemStockRepository;
use PlugixaInventory\Modules\Stock\Repositories\TransactionRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stock ledger, read-only.
 *
 * NOT a `CrudController`, and the difference is the point: there is no POST, no PUT, no DELETE and
 * no restore. A ledger row is written by `StockEngine` when a document is finalized and by nothing
 * else, so every one of those verbs would be a route that either lies about what it does or
 * corrupts the audit trail.
 *
 * The absent DELETE is the first of four defences listed on `TransactionRepository`. It is the
 * cheapest: WordPress answers `rest_no_route` before any of this code runs.
 */
final class TransactionsController extends RestController {

	public function __construct(
		private readonly TransactionRepository $ledger,
		private readonly ItemStockRepository $stock
	) {}

	protected function entity_type(): string {
		return 'transaction';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/transactions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/transactions/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/stock/levels/(?P<item>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'levels' ),
					// An item's stock per warehouse: read on items as well as on the ledger.
					'permission_callback' => array( $this, 'can_read_levels' ),
				),
			)
		);
	}

	/**
	 * May this user see one item's stock across warehouses?
	 *
	 * THE ANSWER IS ITEM DATA, so it is asked about items too — not only about the ledger this
	 * controller is named for. A role that may not read items must not read them here instead.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_levels( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return $this->allowed_on( 'read', 'item', $this->entity_type() );
	}

	/**
	 * The ledger, newest first.
	 *
	 * DEFAULTS TO DESCENDING, unlike every other list in the plugin. A ledger is read from the
	 * present backwards: "what happened to this item" means the last thing first. `paged_list()`
	 * takes the order off the request, so the default is set here rather than by overriding it.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( null === $request->get_param( 'orderby' ) ) {
			$request->set_param( 'orderby', 'id' );
			$request->set_param( 'order', 'desc' );
		}

		return $this->paged_list(
			$request,
			$this->ledger,
			array( 't.reason', 't.recipient' ),
			/*
			 * `?item=` and `?warehouse=` narrow the ledger, and they are the two questions anybody
			 * ever asks of it. Clauses rather than separate routes, so they compose with the date
			 * filter, the type filter, the sort and the pager the way every other filter does.
			 */
			static function ( $query, WP_REST_Request $request ): ?WP_Error {
				$item = (int) ( $request->get_param( 'item' ) ?? 0 );

				if ( $item > 0 ) {
					$query->where( 't.item_id = %d', array( $item ) );
				}

				$warehouse = (int) ( $request->get_param( 'warehouse' ) ?? 0 );

				if ( $warehouse > 0 ) {
					$query->where( 't.warehouse_id = %d', array( $warehouse ) );
				}

				$batch = (string) ( $request->get_param( 'batch' ) ?? '' );

				if ( '' !== $batch ) {
					$query->where( 't.batch_id = %s', array( $batch ) );
				}

				return null;
			},
			null,
			/*
			 * The names behind the ids, resolved through the record-names filter so this module
			 * learns nothing about items, warehouses or recipients beyond their entity slugs.
			 *
			 * ONCE FOR THE PAGE, NOT ONCE PER ROW. This used to be a per-row decorator asking the
			 * filter for a single id at a time — three queries per ledger line. See
			 * `RestController::resolve_names()`.
			 */
			fn( array $rows ): array => $this->resolve_names(
				$rows,
				array(
					'item_name'      => array( 'item_id', 'item' ),
					'warehouse_name' => array( 'warehouse_id', 'warehouse' ),
					'recipient_name' => array( 'recipient_id', 'recipient' ),
				)
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->ledger->find( (int) $request['id'] );

		if ( null === $row ) {
			return $this->fail(
				'plugixa_inventory_not_found',
				__( 'That transaction does not exist.', 'plugixa-inventory' ),
				404
			);
		}

		return $this->ok( $this->ledger->cast( $row ) );
	}

	/**
	 * One item's stock, warehouse by warehouse.
	 *
	 * What the item detail page draws its breakdown from, and the answer the Joomla component this
	 * ports cannot give at all: there, an item has one quantity and one home warehouse.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function levels( WP_REST_Request $request ): WP_REST_Response {
		$item_id = (int) $request['item'];
		$levels  = $this->stock->levels( $item_id );

		$names = array() === $levels
			? array()
			: (array) apply_filters( 'plugixa_inventory_record_names', array(), 'warehouse', array_keys( $levels ) );

		$rows = array();

		foreach ( $levels as $warehouse_id => $quantity ) {
			$rows[] = array(
				'warehouse_id'   => $warehouse_id,
				'warehouse_name' => $names[ $warehouse_id ] ?? null,
				'quantity'       => round( $quantity, 4 ),
			);
		}

		return $this->ok(
			array(
				'item_id' => $item_id,
				'total'   => round( $this->stock->total( $item_id ), 4 ),
				'levels'  => $rows,
			)
		);
	}
}
