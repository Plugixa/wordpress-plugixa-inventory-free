<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core;

use PlugixaInventory\Core\Admin\Admin;
use PlugixaInventory\Core\Admin\Assets;
use PlugixaInventory\Core\Frontend\AppShortcode;
use PlugixaInventory\Core\Frontend\ControlAssets;
use PlugixaInventory\Core\Frontend\DevBar;
use PlugixaInventory\Core\Http\BootstrapController;
use PlugixaInventory\Core\Filters\FilterRequest;
use PlugixaInventory\Core\Http\FiltersController;
use PlugixaInventory\Core\Http\MassActionController;
use PlugixaInventory\Core\Http\RawResponse;
use PlugixaInventory\Core\Http\SettingsController;
use PlugixaInventory\Core\Http\UsersController;
use PlugixaInventory\Core\Modules\ModuleRegistry;
use PlugixaInventory\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The composition root.
 *
 * Holds no business logic: it builds the container, discovers the modules, and lets
 * the premium overlay wire itself in if it is present.
 */
final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private ModuleRegistry $modules;

	private function __construct() {
		$this->container = new Container();
		$this->container->instance( Container::class, $this->container );

		$this->modules = new ModuleRegistry( $this->container );
		$this->container->instance( ModuleRegistry::class, $this->modules );
	}

	public static function instance(): self {
		self::$instance ??= new self();

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Start the plugin. Called on plugins_loaded, ONCE.
	 *
	 * Once is a real precondition, not a figure of speech. Booting twice registers every module's hooks
	 * twice, because `add_action()` deduplicates a callback by identity and two closures are never the
	 * same object — the second registration appends rather than replaces, and every listener fires
	 * twice. There is deliberately no guard here: the one caller is the `plugins_loaded` hook in
	 * plugixa-inventory.php, and a boot() that quietly absorbed a second call would hide the mistake rather
	 * than prevent it. `PluginBootTest` asserts no hook ends up with a duplicate listener.
	 *
	 * The reason this is written down at all: tests/bootstrap.php used to call boot() itself as well,
	 * so the suite ran with every module's hooks registered twice — twelve listeners on
	 * `plugixa_inventory_before_purge` for six modules. Nothing noticed, because every listener was a DELETE
	 * ("forget this record's notes/tags/links") and deleting twice is indistinguishable from deleting
	 * once. The first listener that INSERTED — a notification — put two rows in the bell for one
	 * reminder, which is what finally gave it away.
	 */
	public function boot(): void {
		$this->modules->discover();

		/*
		 * Premium modules were discovered in the same call above, from `pro/includes/Modules`.
		 * There is no probe for them and no `class_exists` check: Freemius deletes that directory
		 * from the free build, so on a free site there is nothing to find. Feature availability is
		 * decided by CODE PRESENCE, never by a licence check — see the ModuleRegistry docblock.
		 */

		$this->modules->registerHooks();

		// AFTER the modules, deliberately. It reads the filter catalogue, which the modules answer,
		// and a request-scoped narrowing that registered before its own vocabulary existed would
		// see an empty catalogue and refuse every filter as unknown.
		$this->container->get( FilterRequest::class )->register_hooks();

		( new Admin() )->register_hooks();
		( new Assets() )->register_hooks();
		( new RawResponse() )->register_hooks();

		// The same application, mounted on a page of the site instead. Core rather than a module:
		// it registers no entity, owns no table and adds no authorisation rule — it is a second
		// door onto what Admin and Assets already open, and ModuleRegistry only discovers
		// includes/Modules/*/Module.php anyway.
		( new AppShortcode() )->register_hooks();

		// The control floor the lead form, the portal and the help centre all depend on. Registered
		// here rather than in any of them because all three need it and none owns it. It only ever
		// registers; each surface declares it as a dependency, which is what enqueues it.
		( new ControlAssets() )->register_hooks();

		// A "who am I / sign out" strip on the front end. Renders nothing unless the site has
		// declared itself non-production via WP_ENVIRONMENT_TYPE — see the class docblock for why
		// that gate and not WP_DEBUG.
		( new DevBar() )->register_hooks();

		// `plugixa_inventory_manage` is derived from the six granular capabilities rather than
		// stored, so the filter that derives it has to be in place before anything asks.
		Capabilities::register_hooks();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		/*
		 * Schema drift guard: a plugin updated by file copy never fires its activation hook, so new
		 * tables would silently not exist.
		 *
		 * TWO QUESTIONS, NOT ONE, AND THE SECOND IS THE ONE THAT BIT. Comparing only the version
		 * catches an upgrade and misses the premium build being installed over the free one at the
		 * SAME version — which adds `pro/Modules/Roles` to disk, registers its routes, and leaves
		 * its tables uncreated. `ModuleRegistry::fingerprint()` carries the full account.
		 *
		 * Both options are written by `Activator::migrate()` itself, so a site that has never run
		 * it (fingerprint `false`) migrates on the next request rather than waiting for a version
		 * bump that may never come.
		 */
		if ( ! Activator::schema_is_current( $this->modules ) ) {
			Activator::migrate();
		}

		/**
		 * Fires once the inventory is fully booted.
		 */
		do_action( 'plugixa_inventory_loaded', $this->container );
	}

	/**
	 * Register core and module REST routes.
	 */
	public function register_routes(): void {
		$this->container->get( BootstrapController::class )->register_routes();

		// Core owns these endpoints but knows none of their content: the modules contribute
		// the settings and the filter vocabulary they own, through filters.
		//
		// The dashboard and global search are NOT here, unlike the CRM this skeleton came from.
		// An inventory dashboard is inventory-specific — stock value, low stock, top movers —
		// so it is a module owning its own figures, not a Core endpoint collecting them.
		$this->container->get( SettingsController::class )->register_routes();
		$this->container->get( UsersController::class )->register_routes();
		$this->container->get( FiltersController::class )->register_routes();

		// Acting on many records of a type known only at runtime — the same reason RecordWriter lives
		// in Core rather than in whichever module happened to need it first.
		$this->container->get( MassActionController::class )->register_routes();

		$this->modules->registerRoutes();

		/**
		 * Register additional inventory REST routes.
		 *
		 * @param string    $namespace The inventory REST namespace.
		 * @param Container $container The plugin container.
		 */
		do_action( 'plugixa_inventory_register_rest_routes', 'plugixa-inventory/v1', $this->container );
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}
}
