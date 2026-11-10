<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\StockMovements\Http;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Http\CrudController;
use PlugixaInventory\Modules\StockMovements\Repositories\StockMovementLineRepository;
use PlugixaInventory\Modules\StockMovements\Repositories\StockMovementRepository;
use PlugixaInventory\Modules\StockMovements\Services\StockMovementService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for stock movements.
 */
final class StockMovementsController extends CrudController {

	public function __construct(
		private readonly StockMovementService $service,
		private readonly StockMovementRepository $movements,
		private readonly StockMovementLineRepository $lines
	) {}

	protected function entity_type(): string {
		return 'stock_movement';
	}

	protected function api_base(): string {
		return 'stock-movements';
	}

	protected function repository(): Repository {
		return $this->movements;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.reference', 't.recipient', 't.details' );
	}

	/**
	 * The warehouse and recipient behind every movement on the page, and each draft's line count.
	 *
	 * Once for the page. This list was the worst of the three: fifty rows cost ninety-five queries
	 * before `RestController::resolve_names()` and `CrudController::with_line_counts()`.
	 */
	protected function decorate_page(): ?callable {
		return function ( array $rows ): array {
			$rows = $this->resolve_names(
				$rows,
				array(
					'warehouse_name' => array( 'warehouse_id', 'warehouse' ),
					'recipient_name' => array( 'recipient_id', 'recipient' ),
				)
			);

			return $this->with_line_counts( $rows, $this->lines );
		};
	}

	/**
	 * @param int $id Movement id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Movement id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_purge( int $id ): bool|WP_Error {
		return $this->service->purge( $id );
	}

	protected function register_extra_routes(): void {
		register_rest_route(
			$this->namespace,
			'/stock-movements/(?P<id>\d+)/finalize',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'finalize' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/stock-movements/types',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'types' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * The movement types, and which item types each may carry.
	 *
	 * THE ITEM-TYPE PAIRING IS A SERVER RULE AND THE CLIENT NEEDS IT BEFORE IT CAN HELP. A form that
	 * knows `check_out` is asset-only can filter its item picker to assets, which is the difference
	 * between an error at finalize and an item that was never offered. Sending the table beats
	 * duplicating it in TypeScript, where it would be a second copy to keep in step.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function types( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$types = array();

		foreach ( StockMovementService::TYPES as $type ) {
			$types[] = array(
				'value'      => $type,
				'item_types' => StockMovementService::item_types_for( $type ),
				'increases'  => in_array( $type, array( 'check_in', 'add_stock' ), true ),
				// Only a check-out demands one, and the form needs to know which field to require.
				'needs_recipient' => 'check_out' === $type,
			);
		}

		return $this->ok( $types );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->decorated( $this->service->get_with_lines( (int) $request['id'] ) );

		return null === $row ? $this->service->not_found() : $this->ok( $row );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = $this->service->create( (array) $request->get_json_params() );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return $this->ok( $this->decorated( $this->service->get_with_lines( (int) $id ) ), array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$done = $this->service->update( $id, (array) $request->get_json_params() );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $this->ok( $this->decorated( $this->service->get_with_lines( $id ) ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function finalize( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->finalize( (int) $request['id'] );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}
}
