<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\WarehouseDashboard\Http;

use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Modules\WarehouseDashboard\Services\WarehouseInsights;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The operational view of each warehouse.
 *
 * READ-ONLY, AND `entity_type()` IS `warehouse` ON PURPOSE. Every route here answers a question
 * about warehouses, so it is the warehouse permission that should decide who may ask — a separate
 * entity type would be a second answer to "may this person see the warehouses", which is exactly
 * the drift `Capabilities` exists to prevent.
 *
 * THREE ROUTES RATHER THAN FIVE TABS. The screen this follows has an Inventory tab, a Transfers
 * tab, an Adjustments tab and an Activity tab; the last three are pre-filtered slices of the
 * ledger, which already records the type of every line. See `WarehouseInsights`.
 */
final class WarehouseOverviewController extends RestController {

	/** The activity feed's page size, and the stock list's. */
	private const PER_PAGE = 25;

	public function __construct( private readonly WarehouseInsights $insights ) {}

	protected function entity_type(): string {
		return 'warehouse';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/warehouse-overview',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/warehouse-overview/(?P<id>\d+)',
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
			'/warehouse-overview/(?P<id>\d+)/stock',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'stock' ),
					// The items held in one warehouse: read on warehouses and on items.
					'permission_callback' => array( $this, 'can_read_stock' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/warehouse-overview/(?P<id>\d+)/activity',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'activity' ),
					// Ledger lines for one warehouse: read on warehouses and on the ledger.
					'permission_callback' => array( $this, 'can_read_activity' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
	}

	/**
	 * May this user see which items a warehouse holds?
	 *
	 * THE TWO FEEDS ARE NOT WAREHOUSE DATA, which is why they no longer take `can_read`. The stock
	 * feed is item rows and the activity feed is ledger rows, filtered to one warehouse — so each is
	 * asked about the records it actually returns as well as the warehouse it is filtered by.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_stock( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return $this->allowed_on( 'read', $this->entity_type(), 'item' );
	}

	/**
	 * May this user see a warehouse's ledger lines?
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_activity( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return $this->allowed_on( 'read', $this->entity_type(), 'transaction' );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( $this->insights->cards() );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$detail = $this->insights->detail( (int) $request->get_param( 'id' ) );

		if ( null === $detail ) {
			return $this->fail(
				'plugixa_inventory_not_found',
				__( 'There is no such warehouse.', 'plugixa-inventory' ),
				404
			);
		}

		return $this->ok( $detail );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function stock( WP_REST_Request $request ): WP_REST_Response {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$size = $this->page_size( $request );

		$result = $this->insights->stock(
			(int) $request->get_param( 'id' ),
			trim( (string) $request->get_param( 'search' ) ),
			$size,
			( $page - 1 ) * $size
		);

		return $this->paged( $result, $size );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function activity( WP_REST_Request $request ): WP_REST_Response {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$size = $this->page_size( $request );

		$result = $this->insights->activity(
			(int) $request->get_param( 'id' ),
			$size,
			( $page - 1 ) * $size
		);

		return $this->paged( $result, $size );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	private function page_size( WP_REST_Request $request ): int {
		$size = (int) $request->get_param( 'per_page' );

		return $size > 0 ? min( 100, $size ) : self::PER_PAGE;
	}

	/**
	 * The list envelope, with the `meta` shape `fetchList()` on the client already expects.
	 *
	 * @param array{rows: array<int, array<string, mixed>>, total: int} $result The page.
	 * @param int                                                      $size   Page size.
	 */
	private function paged( array $result, int $size ): WP_REST_Response {
		return $this->ok(
			$result['rows'],
			array(
				'total' => $result['total'],
				'pages' => $size > 0 ? (int) ceil( $result['total'] / $size ) : 0,
			)
		);
	}
}
