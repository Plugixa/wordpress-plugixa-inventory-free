<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Items;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Core\Settings;
use PlugixaInventory\Modules\Items\Http\ItemsController;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Items\Services\ItemService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Items: the things the business holds.
 *
 * Owns the `items` table, including the denormalised `stock_quantity` cache — which it owns but
 * does not write. The Stock module computes that from `item_stock` and pushes it here through
 * `ItemService::set_cached_stock()`.
 *
 * THIS MODULE NAMES NO OTHER MODULE. It validates a category, a warehouse and a unit through
 * `RecordLoader`, resolves their display names through the `plugixa_inventory_record_names` filter,
 * and knows nothing about stock at all. That is what lets Stock arrive in a later phase without a
 * single edit here.
 *
 * WHY THERE IS NO DELETE BLOCKER ON `default_warehouse_id`, although the seam exists and the
 * temptation is obvious. That column is a form default — where a new receipt is SUGGESTED to land —
 * not where anything is stored. Trashing a warehouse that some items name as their default leaves
 * those items with a default that no longer resolves, which the form renders as "none" and the user
 * fixes by picking another. Refusing the delete for that would block a legitimate action to prevent
 * a non-problem. The blocker that matters is stock actually on hand in that warehouse, and it
 * belongs to the module that knows about stock.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'items';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 20,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_items',
			'desc_key'  => 'app_items_desc',
			'icon'      => 'inventory',
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
			'label' => (string) ( $strings['app_items'] ?? 'items' ),
			'desc'  => (string) ( $strings['app_items_desc'] ?? '' ),
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
		$container->get( ItemsController::class )->register_routes();
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
				return 'item' === $entity_type
					? array_merge( $fields, $container->get( ItemRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_record_writers',
			static function ( array $writers ) use ( $container ): array {
				$writers['item'] = array(
					'create' => array( $container->get( ItemService::class ), 'create' ),
					'update' => array( $container->get( ItemService::class ), 'update' ),
					'delete' => array( $container->get( ItemService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		add_filter(
			'plugixa_inventory_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'item' === $entity_type
					? $names + $container->get( ItemRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);

		$this->register_settings();
	}

	/**
	 * The two weight settings, which belong to Items because Items is what has a weight.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * BOTH ARE DEFAULTS FOR A NEW ITEM, NOT MODES THE WHOLE INVENTORY RUNS IN — and the first one's
	 * name says so, where the component this ports called it `track_weight_globally`.
	 *
	 * The literal reading of that name is "every item tracks weight and the per-item switch goes
	 * away", and it is a trap. `items.track_weight` would still be sitting in the table holding
	 * whatever each item last said, ignored while the mode is on; turning the mode off then either
	 * resurrects a set of choices nobody remembers making, or means throwing them away. A setting
	 * whose off state is ambiguous is a setting somebody will be afraid to turn off.
	 *
	 * As a default it composes instead: the checkbox stays where it is, keeps meaning exactly what
	 * it says for the item in front of you, and the setting decides where it starts. A warehouse
	 * that weighs everything ticks it once and stops thinking about it, which is the actual problem
	 * the Joomla setting was there to solve.
	 *
	 * WORTH SAYING WHAT IT DOES NOT DO: it does not backfill. Turning it on leaves every existing
	 * item exactly as it is, because a setting that silently rewrote a thousand rows on save is not
	 * a preference, it is a migration wearing a checkbox.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */
	private function register_settings(): void {
		add_filter(
			'plugixa_inventory_settings_defaults',
			static function ( array $defaults ): array {
				$defaults['track_weight_by_default'] = false;
				$defaults['default_weight_unit']     = 'kg';

				return $defaults;
			}
		);

		add_filter(
			'plugixa_inventory_sanitize_settings',
			static function ( array $clean, array $input ): array {
				$unit = (string) ( $input['default_weight_unit'] ?? 'kg' );

				$clean['track_weight_by_default'] = ! empty( $input['track_weight_by_default'] );

				/*
				 * Falls back rather than refusing, for the reason Stock's costing method does: this
				 * runs on a save that may be changing ten other things, and losing the user's nine
				 * good edits to complain about a select they did not touch is the worse trade.
				 */
				$clean['default_weight_unit'] = isset( ItemService::WEIGHT_UNITS[ $unit ] ) ? $unit : 'kg';

				return $clean;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_settings_choices',
			static function ( array $choices ): array {
				// The keys, not the table: the client offers a unit to pick and has its own copy of
				// the conversion factors for the live "= 0.907 kg" hint under the weight field.
				$choices['weight_units'] = array_keys( ItemService::WEIGHT_UNITS );

				return $choices;
			}
		);

		add_filter(
			'plugixa_inventory_admin_config',
			static function ( array $config ): array {
				/*
				 * PRINTED ONTO THE PAGE RATHER THAN FETCHED, which `Core\App\Config::formatting()`
				 * does NOT do for a module's settings as a rule — Stock's costing method is not
				 * here, and should not be.
				 *
				 * The difference is when the value is needed. Costing is a server-side decision the
				 * client only ever displays; these two decide what a form looks like on its FIRST
				 * paint. Fetched instead, `/items/new` would render an unticked box on a site that
				 * weighs everything and then tick it half a second later, under the user's cursor —
				 * and it would spend a request to do it, on a screen that otherwise needs none.
				 */
				$config['settings']['track_weight_by_default'] = (bool) Settings::get( 'track_weight_by_default', false );
				$config['settings']['default_weight_unit']     = (string) Settings::get( 'default_weight_unit', 'kg' );

				return $config;
			}
		);
	}

	/**
	 * Describe an item to the generic UI.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['item'] = array(
			'label'    => __( 'Item', 'plugixa-inventory' ),
			'plural'   => __( 'Items', 'plugixa-inventory' ),
			'api_base' => 'items',
			'fields'   => array(
				'name'                 => array(
					'label'    => __( 'Name', 'plugixa-inventory' ),
					'type'     => 'text',
					'required' => true,
				),
				'sku'                  => array(
					'label' => __( 'SKU', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'barcode'              => array(
					'label' => __( 'Barcode', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'serial_number'        => array(
					'label' => __( 'Serial number', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'item_type'            => array(
					'label'    => __( 'Type', 'plugixa-inventory' ),
					'type'     => 'select',
					'options'  => ItemService::TYPES,
					'required' => true,
				),
				'category_id'          => array(
					'label'  => __( 'Category', 'plugixa-inventory' ),
					'type'   => 'record',
					'entity' => 'category',
				),
				'default_warehouse_id' => array(
					'label'  => __( 'Default warehouse', 'plugixa-inventory' ),
					'type'   => 'record',
					'entity' => 'warehouse',
				),
				'unit_id'              => array(
					'label'  => __( 'Unit', 'plugixa-inventory' ),
					'type'   => 'record',
					'entity' => 'unit',
				),
				'image_id'             => array(
					'label' => __( 'Image', 'plugixa-inventory' ),
					'type'  => 'media',
				),
				'stock_quantity'       => array(
					'label'    => __( 'On hand', 'plugixa-inventory' ),
					'type'     => 'number',
					'readonly' => true,
				),
				'reorder_level'        => array(
					'label' => __( 'Reorder level', 'plugixa-inventory' ),
					'type'  => 'number',
				),
				'unit_price'           => array(
					'label' => __( 'Sale price', 'plugixa-inventory' ),
					'type'  => 'money',
				),
				'cost_price'           => array(
					'label' => __( 'Cost price', 'plugixa-inventory' ),
					'type'  => 'money',
				),
				'track_weight'         => array(
					'label' => __( 'Track weight', 'plugixa-inventory' ),
					'type'  => 'boolean',
				),
				'weight'               => array(
					'label' => __( 'Weight', 'plugixa-inventory' ),
					'type'  => 'number',
				),
				'weight_unit'          => array(
					'label'   => __( 'Weight unit', 'plugixa-inventory' ),
					'type'    => 'select',
					'options' => array_keys( ItemService::WEIGHT_UNITS ),
				),
				'description'          => array(
					'label' => __( 'Description', 'plugixa-inventory' ),
					'type'  => 'richtext',
				),
				'status'               => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'type'    => 'select',
					'options' => ItemService::STATUSES,
				),
			),
		);

		return $entities;
	}
}
