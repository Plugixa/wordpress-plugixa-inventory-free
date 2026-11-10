<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Categories;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Categories\Http\CategoriesController;
use PlugixaInventory\Modules\Categories\Repositories\CategoryRepository;
use PlugixaInventory\Modules\Categories\Services\CategoryService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Categories: how items are grouped for browsing and for valuation reports.
 *
 * The module owns the `categories` table and nothing else. It does not know that items exist —
 * Items declares the `category_id` field and answers the picker, and this module answers
 * `plugixa_inventory_record_names` so a list can render a category's name without the listing
 * module knowing which column holds it.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'categories';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 30,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_categories',
			'desc_key'  => 'app_categories_desc',
			'icon'      => 'category',
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
			'label' => (string) ( $strings['app_categories'] ?? 'categories' ),
			'desc'  => (string) ( $strings['app_categories_desc'] ?? '' ),
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
		// Free is all-or-nothing on `plugixa_inventory_manage`; the premium Roles module answers
		// per-action through `plugixa_inventory_user_can`. A capability of this module's own would
		// have to be granted somewhere, and there is nowhere to grant it from.
		return array();
	}

	public function registerServices( Container $container ): void {
		// Nothing. The container autowires CategoryService and CategoryRepository from their
		// constructor signatures; only an INTERFACE needs an explicit binding.
		unset( $container );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( CategoriesController::class )->register_routes();
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
				return 'category' === $entity_type
					? array_merge( $fields, $container->get( CategoryRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		/*
		 * Generic write access, so the CSV importer and any mass action reach the SAME service the
		 * REST API does — and therefore the same uniqueness rule. A second write path is a second
		 * place for "two categories with the code ELEC" to become possible.
		 */
		add_filter(
			'plugixa_inventory_record_writers',
			static function ( array $writers ) use ( $container ): array {
				$writers['category'] = array(
					'create' => array( $container->get( CategoryService::class ), 'create' ),
					'update' => array( $container->get( CategoryService::class ), 'update' ),
					'delete' => array( $container->get( CategoryRepository::class ), 'delete' ),
				);

				return $writers;
			}
		);

		add_filter(
			'plugixa_inventory_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'category' === $entity_type
					? $names + $container->get( CategoryRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a category to the generic UI.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['category'] = array(
			'label'    => __( 'Category', 'plugixa-inventory' ),
			'plural'   => __( 'Categories', 'plugixa-inventory' ),
			'api_base' => 'categories',
			'fields'   => array(
				'code'        => array(
					'label' => __( 'Code', 'plugixa-inventory' ),
					'type'  => 'text',
				),
				'name'        => array(
					'label'    => __( 'Name', 'plugixa-inventory' ),
					'type'     => 'text',
					'required' => true,
				),
				'description' => array(
					'label' => __( 'Description', 'plugixa-inventory' ),
					'type'  => 'richtext',
				),
				'status'      => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'type'    => 'select',
					'options' => CategoryService::STATUSES,
				),
			),
		);

		return $entities;
	}
}
