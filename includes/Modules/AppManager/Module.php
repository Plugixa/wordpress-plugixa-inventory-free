<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\AppManager;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Core\Modules\ModuleRegistry;
use PlugixaInventory\Modules\AppManager\Http\AppController;
use PlugixaInventory\Modules\AppManager\Services\AppCatalogue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switching features off: the screen, its route, and the flags it puts on the page.
 *
 * IT IS A MODULE RATHER THAN PART OF CORE BECAUSE CORE MAY NOT NAME A MODULE. `AppState` and
 * `AppInterface` — the rules and the contract — live in Core and name nothing; the catalogue that
 * asks fifteen modules what they are and renders the answer is a consumer of modules, and Core is
 * not allowed to be one. `CoreIndependenceTest` enforces this with an exemption list that is empty
 * on purpose.
 *
 * IT DESCRIBES ITSELF AS CORE, WHICH MAKES IT THE ONE APP THAT CANNOT SWITCH ITSELF OFF. Not
 * fussiness: `AppCatalogue::set()` refuses a core app, so the button never renders, so there is no
 * state in which the only screen that can re-enable anything is itself unreachable.
 *
 * THE FLAGS GO ON THE PAGE, NOT DOWN A FETCH. `modules/registry.ts` collects modules with an EAGER
 * `import.meta.glob`, so every `module.tsx` — and every `registerTopbarItem()` inside one — has
 * already run before React renders a single element. A screen that must decide whether to register
 * at all cannot ask the database to find out, which is the case `plugixa_inventory_admin_config`
 * was added for and is quoted for in its own docblock.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'app_manager';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 80,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_app_manager',
			'desc_key'  => 'app_app_manager_desc',
			'icon'      => 'apps',
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
			'label' => (string) ( $strings['app_app_manager'] ?? 'app_manager' ),
			'desc'  => (string) ( $strings['app_app_manager_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	/** NOTHING TO MIGRATE. One option, written on first save. */
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
		$container->get( AppController::class )->register_routes();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);

		/*
		 * EVERY MODULE'S CARD LABEL, INCLUDING THE MODULES THAT ARE SWITCHED OFF.
		 *
		 * A module contributes its own catalogue from `registerHooks()`, and a disabled module does
		 * not run it — that is exactly how its cron and its routes are stopped. Which left the App
		 * Manager redrawing a switched-off Transfers card as the bare word "transfers" with no
		 * description: the card explaining what somebody had just turned off was the one thing that
		 * stopped explaining. Found by looking at the screen; no test would have said a word.
		 *
		 * Two keys per module, resolved through the module's own `labels()`, so this file still
		 * names nothing.
		 */
		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static function ( array $strings ) use ( $container ): array {
				$modules = $container->get( ModuleRegistry::class );

				foreach ( $modules->descriptors() as $id => $descriptor ) {
					$module = $modules->module( $id );

					if ( ! $module instanceof AppInterface ) {
						continue;
					}

					$labels = $module->labels();

					$strings[ $descriptor['label_key'] ] = $labels['label'];
					$strings[ $descriptor['desc_key'] ]  = $labels['desc'];
				}

				return $strings;
			}
		);

		add_filter(
			'plugixa_inventory_admin_config',
			static function ( array $config ) use ( $container ): array {
				$catalogue = $container->get( AppCatalogue::class )->config();

				$config['apps']        = $catalogue['apps'];
				$config['appRegistry'] = $catalogue['registry'];

				return $config;
			}
		);

		add_filter( 'plugixa_inventory_apps', array( $this, 'add_settings_card' ) );

		/*
		 * The flags are this module's own property, so they leave with it. `Core\Uninstaller` drops
		 * tables by prefix and deletes the two options it knows about; a module's option is the
		 * module's business, which is the arrangement `Modules\Demo` already has for its manifest.
		 */
		add_action(
			'plugixa_inventory_uninstall',
			static fn() => $container->get( ModuleRegistry::class )->state()->forget()
		);
	}

	/**
	 * Add the Settings card, which is a screen rather than a module.
	 *
	 * The sidebar has an entry for it, the demo's grid has a card for it, and it has no
	 * `Module.php` to describe itself because `Core\Settings` is infrastructure every module writes
	 * into. Marked `core` so it draws no button — `AppCatalogue::set()` would refuse to store a
	 * flag for an id the registry has never heard of, and a card with a button that always failed
	 * would be worse than no card.
	 *
	 * @param array<int, array<string, mixed>> $cards The grid so far.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_settings_card( array $cards ): array {
		$cards[] = array(
			'id'         => 'settings',
			// Last of the core row: the others are things you look at, this is where you change them.
			'order'      => 90,
			'category'   => 'core',
			'core'       => true,
			'deps'       => array(),
			'default'    => true,
			'icon'       => 'settings',
			'label_key'  => 'app_settings',
			'desc_key'   => 'app_settings_desc',
			'flag'       => true,
			'enabled'    => true,
			'can_toggle' => false,
			'blocker'    => null,
			'usage'      => array(),
		);

		return $cards;
	}
}
