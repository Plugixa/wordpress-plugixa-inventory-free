<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\StockMovements;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\StockMovements\Http\StockMovementsController;
use PlugixaInventory\Modules\StockMovements\Repositories\StockMovementRepository;
use PlugixaInventory\Modules\StockMovements\Services\StockMovementService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock movements: stock arriving, leaving, and going out to somebody who brings it back.
 *
 * The busiest of the three document types — it is what a warehouse uses all day — and the only one
 * that cares whether an item is an asset or a consumable.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'stock_movements';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 50,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_stock_movements',
			'desc_key'  => 'app_stock_movements_desc',
			'icon'      => 'move',
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
			'label' => (string) ( $strings['app_stock_movements'] ?? 'stock_movements' ),
			'desc'  => (string) ( $strings['app_stock_movements_desc'] ?? '' ),
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
		$container->get( StockMovementsController::class )->register_routes();
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
				return 'stock_movement' === $entity_type
					? array_merge( $fields, $container->get( StockMovementRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'stock_movement' === $entity_type
					? $names + $container->get( StockMovementRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);

		add_filter(
			'plugixa_inventory_can_delete',
			static function ( bool $allowed, string $entity, int $id, array $row ): bool {
				unset( $id );

				return 'stock_movement' === $entity ? 'draft' === ( $row['status'] ?? '' ) : $allowed;
			},
			10,
			4
		);

		/*
		 * A RECIPIENT NAMED ON A DRAFT CANNOT BE TRASHED EITHER. Stock answers the same filter for
		 * anybody with ledger history; this covers the case that has not happened yet — a check-out
		 * sitting in draft, naming somebody who is then removed. Finalizing it afterwards would
		 * record a movement to a recipient who no longer exists.
		 */
		add_filter(
			'plugixa_inventory_recipient_delete_blockers',
			static function ( array $blockers, int $id ) use ( $container ): array {
				$drafts = $container->get( StockMovementRepository::class )
					->query( 'count' )
					->where( 't.recipient_id = %d', array( $id ) )
					->where( 't.status = %s', array( 'draft' ) )
					->count();

				if ( $drafts > 0 ) {
					$blockers[] = sprintf(
						/* translators: %d: number of draft movements. */
						_n(
							'%d draft check-out names this person.',
							'%d draft check-outs name this person.',
							$drafts,
							'plugixa-inventory'
						),
						$drafts
					);
				}

				return $blockers;
			},
			10,
			2
		);
	}

	/**
	 * Describe a stock movement to the generic UI.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['stock_movement'] = array(
			'label'    => __( 'Stock movement', 'plugixa-inventory' ),
			'plural'   => __( 'Stock movements', 'plugixa-inventory' ),
			// HYPHENATED, and it has to match `StockMovementsController::api_base()`. RecordLoader
			// resolves an entity to a table by its api_base, so a mismatch here is a permission
			// check that silently reads nothing. EntityRegistryTest pins the convention.
			'api_base' => 'stock-movements',
			'fields'   => array(
				'reference'     => array(
					'label' => __( 'Reference', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'movement_date' => array(
					'label' => __( 'Date', 'plugixa-inventory' ),
					'type'  => 'date',
				),
				'movement_type' => array(
					'label'    => __( 'Type', 'plugixa-inventory' ),
					'type'     => 'select',
					'options'  => StockMovementService::TYPES,
					'required' => true,
				),
				'warehouse_id'  => array(
					'label'    => __( 'Warehouse', 'plugixa-inventory' ),
					'type'     => 'record',
					'entity'   => 'warehouse',
					'required' => true,
				),
				'recipient_id'  => array(
					'label'  => __( 'Recipient', 'plugixa-inventory' ),
					'type'   => 'record',
					'entity' => 'recipient',
				),
				'status'        => array(
					'label'    => __( 'Status', 'plugixa-inventory' ),
					'type'     => 'select',
					'options'  => StockMovementService::STATUSES,
					'readonly' => true,
				),
				'details'       => array(
					'label' => __( 'Details', 'plugixa-inventory' ),
					'type'  => 'textarea',
				),
			),
		);

		return $entities;
	}
}
