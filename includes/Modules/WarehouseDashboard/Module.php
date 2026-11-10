<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\WarehouseDashboard;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\WarehouseDashboard\Http\WarehouseOverviewController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The operational view of each warehouse: what is in it, what is short, what has moved.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT PART OF `Modules\Warehouses`.
 *
 * That module owns the warehouse RECORD — a name, a code, an address, and the rules about deleting
 * one. This module owns a question ABOUT warehouses that is answered from stock, the ledger,
 * transfers and adjustments. Putting it in Warehouses would make the module that owns a small
 * reference table depend on four others in order to draw a screen it does not own; `Modules\
 * Dashboard` exists for exactly this reason and says so in the same words.
 *
 * The split is also what the two nav entries mean. "Warehouses" under Inventory is this screen —
 * where the stock is. "Warehouses" under Configuration is the record list — what the warehouses
 * ARE. The Joomla build separates them the same way, down to the URLs.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * TABLE-LESS. It reads; it owns nothing. `migrate()` is empty and there is no Schema.php, which is
 * the same shape as Dashboard, Reports and Lookup.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'warehouse_dashboard';
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
			'category'  => 'insight',
			'core'      => false,
			// Warehouses themselves are core, so there is no dependency to declare: this screen
			// cannot be left pointing at something that has been switched off.
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_warehouse_dashboard',
			'desc_key'  => 'app_warehouse_dashboard_desc',
			'icon'      => 'warehouse',
			'cron'      => array(),
		);
	}

	/**
	 * @return array{label: string, desc: string}
	 */
	public function labels(): array {
		$strings = Strings::all();

		return array(
			'label' => (string) ( $strings['app_warehouse_dashboard'] ?? 'warehouse_dashboard' ),
			'desc'  => (string) ( $strings['app_warehouse_dashboard_desc'] ?? '' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function usage( Container $container ): array {
		unset( $container );

		return array();
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	/** NOTHING TO MIGRATE. It reads other modules' tables through their own repositories. */
	public function migrate(): void {}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerServices( Container $container ): void {
		unset( $container );
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerRoutes( Container $container ): void {
		$container->get( WarehouseOverviewController::class )->register_routes();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerHooks( Container $container ): void {
		unset( $container );

		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);
	}
}
