<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Warehouses;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Warehouses\Http\WarehousesController;
use PlugixaInventory\Modules\Warehouses\Repositories\WarehouseRepository;
use PlugixaInventory\Modules\Warehouses\Services\WarehouseService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warehouses: the places stock is held.
 *
 * This module owns the `warehouses` table and knows nothing about stock. The relationship runs the
 * other way: the Stock module keys `item_stock` on (item_id, warehouse_id) and answers this
 * module's `plugixa_inventory_warehouse_delete_blockers` filter so a warehouse holding stock
 * cannot be trashed out from under it.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'warehouses';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 40,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_warehouses',
			'desc_key'  => 'app_warehouses_desc',
			'icon'      => 'warehouse',
			'cron'      => array(),
		);
	}

	/**
	 * @return string[]
	 */
	public function usage( Container $container ): array {
		unset( $container );

		return array();
	}


	/**
	 * The card's name and description, from this module's OWN catalogue.
	 *
	 * Read back out of `Strings::all()` rather than written again here: the pair is registered
	 * there so `bin/generate-strings.php` and the .pot both see it, and a second copy would be a
	 * second thing to keep in step.
	 *
	 * @return array{label: string, desc: string}
	 */
	public function labels(): array {
		$strings = Strings::all();

		return array(
			'label' => (string) ( $strings['app_warehouses'] ?? 'warehouses' ),
			'desc'  => (string) ( $strings['app_warehouses_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	public function migrate(): void {
		Schema::install();
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	public function registerServices( Container $container ): void {
		unset( $container );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( WarehousesController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);

		add_filter( 'plugixa_inventory_entity_types', array( $this, 'register_entity' ) );

		add_filter(
			'plugixa_inventory_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'warehouse' === $entity_type
					? array_merge( $fields, $container->get( WarehouseRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_record_writers',
			static function ( array $writers ) use ( $container ): array {
				$writers['warehouse'] = array(
					'create' => array( $container->get( WarehouseService::class ), 'create' ),
					'update' => array( $container->get( WarehouseService::class ), 'update' ),
					// The service, so an importer cannot delete a warehouse that still holds stock.
					'delete' => array( $container->get( WarehouseService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		add_filter(
			'plugixa_inventory_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'warehouse' === $entity_type
					? $names + $container->get( WarehouseRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a warehouse to the generic UI.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['warehouse'] = array(
			'label'    => __( 'Warehouse', 'plugixa-inventory' ),
			'plural'   => __( 'Warehouses', 'plugixa-inventory' ),
			'api_base' => 'warehouses',
			'fields'   => array(
				'code'    => array(
					'label' => __( 'Code', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'name'    => array(
					'label'    => __( 'Name', 'plugixa-inventory' ),
					'type'     => 'text',
					'required' => true,
				),
				'address' => array(
					'label' => __( 'Address', 'plugixa-inventory' ),
					'type'  => 'richtext',
				),
				'status'  => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'type'    => 'select',
					'options' => WarehouseService::STATUSES,
				),
			),
		);

		return $entities;
	}
}
