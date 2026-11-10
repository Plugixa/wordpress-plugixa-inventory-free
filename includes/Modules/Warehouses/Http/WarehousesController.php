<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Warehouses\Http;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Http\CrudController;
use PlugixaInventory\Modules\Warehouses\Repositories\WarehouseRepository;
use PlugixaInventory\Modules\Warehouses\Services\WarehouseService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for warehouses.
 *
 * The operational views the Joomla component has on a warehouse -- its inventory, its transfers,
 * its recent activity -- are not here, and cannot be: every one of them reads a table the Stock,
 * Transfers and Adjustments modules own, and none of those exist yet. They arrive as extra routes
 * on this controller once they do.
 */
final class WarehousesController extends CrudController {

	public function __construct(
		private readonly WarehouseService $service,
		private readonly WarehouseRepository $warehouses
	) {}

	protected function entity_type(): string {
		return 'warehouse';
	}

	protected function api_base(): string {
		return 'warehouses';
	}

	protected function repository(): Repository {
		return $this->warehouses;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name', 't.code', 't.address' );
	}

	/**
	 * Through the service, which asks every other module whether this warehouse is still in use.
	 *
	 * @param int $id Warehouse id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Warehouse id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}
}
