<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Dashboard;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Dashboard\Http\DashboardController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The opening screen.
 *
 * A MODULE FOR ONE ROUTE, which looks like overhead until you ask where else it would go. The
 * dashboard reads Items, Stock and Reports; putting its controller in any one of them would make
 * that module depend on the other two for a screen it does not own. Discovery is by folder
 * (`ModuleRegistry`), so the cost of a module is a directory rather than a registration.
 *
 * IT DEPENDS ON `Reports` AND MUST KEEP DOING SO. Every figure on this screen also appears on a
 * report, and the two agreeing is not a nice-to-have — a dashboard tile that disagrees with the
 * report it links to is the kind of defect that destroys trust in both. `InventoryReports` is the
 * single definition; this module is a second presentation of it and holds no arithmetic of its own.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'dashboard';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 10,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_dashboard',
			'desc_key'  => 'app_dashboard_desc',
			'icon'      => 'dashboard',
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
			'label' => (string) ( $strings['app_dashboard'] ?? 'dashboard' ),
			'desc'  => (string) ( $strings['app_dashboard_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	public function migrate(): void {
		// No tables. Every number is derived.
	}

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

		// Autowired: the controller's three arguments are concrete classes.
	}

	public function registerRoutes( Container $container ): void {
		$container->get( DashboardController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		unset( $container );

		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);
	}
}
