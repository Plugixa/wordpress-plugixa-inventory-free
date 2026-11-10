<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Export;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Export\Http\ExportController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export: taking a list out as CSV.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE COUNTERPART OF IMPORT, AND THAT IS WHY IT IS ITS OWN MODULE RATHER THAN PART OF REPORTS.
 *
 * `EntityExporter`, `CsvWriter` and the `/export/{entity}` route lived in Reports because that is
 * where the CSV writer happened to be written first — a fact the reports controller recorded in a
 * comment rather than defended: *"NOT UNDER `/reports/`, because it is not one. This is the button
 * on every list screen, and it belongs to the entity being exported."*
 *
 * Reports became a premium module, and that comment stopped being about tidiness. Import is free,
 * every list screen draws an Export button, and a free build where those buttons answered
 * `rest_no_route` would be a plugin that can take data in and not out — worse than one with no
 * button at all, because the button is right there.
 *
 * So the split follows what the two things ARE. A report is an aggregate question about stock; an
 * export is the rows you are already looking at, in a file. They shared a CSV writer and nothing
 * else.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * IT OWNS NO TABLES AND REGISTERS NO ENTITY, for the same reasons Reports does not: there is
 * nothing to store, and `plugixa_inventory_entity_types` is the catalogue of things with rows. The
 * controller still names `report` as its entity type for `Capabilities::user_can()` — deliberately
 * the SAME string it used before this split, so a site that has granted the export capability keeps
 * it across the upgrade.
 *
 * NOT `'core' => true`, although every list screen shows its button. Switching it off is a coherent
 * thing to want — a site that has decided its stock data does not leave in a file — and the button
 * disappears with the route rather than staying and failing.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'export';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			// Beside Import, which is order 10 in the same category: they are one idea seen from
			// two directions, and a card between them would read as unrelated.
			'order'     => 15,
			'category'  => 'tools',
			'core'      => false,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_export',
			'desc_key'  => 'app_export_desc',
			'icon'      => 'upload',
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
	 * @return array{label: string, desc: string}
	 */
	public function labels(): array {
		$strings = Strings::all();

		return array(
			'label' => (string) ( $strings['app_export'] ?? 'export' ),
			'desc'  => (string) ( $strings['app_export_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	public function migrate(): void {
		// Nothing to install. See the class docblock.
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

		// `EntityExporter` and the controller are concrete classes whose constructor arguments are
		// concrete classes, so the container autowires both. `CsvWriter` is static and is never
		// resolved at all.
	}

	public function registerRoutes( Container $container ): void {
		$container->get( ExportController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		unset( $container );

		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);
	}
}
