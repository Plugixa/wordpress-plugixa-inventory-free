<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Modules;

use PlugixaInventory\Core\Container;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers modules on disk and boots them.
 *
 * Discovery is by filesystem glob, in three roots:
 *
 *   includes/Modules/<Name>/Module.php      ->  PlugixaInventory\Modules\<Name>\Module
 *   pro/includes/Modules/<Name>/Module.php  ->  PlugixaInventory\Pro\Modules\<Name>\Module
 *   dev/includes/Modules/<Name>/Module.php  ->  PlugixaInventory\Dev\Modules\<Name>\Module
 *
 * THE SECOND ROOT IS THE ENTIRE FREE/PREMIUM SEAM, and it works by ABSENCE. Freemius deletes
 * `pro/` from the free build (see `@fs_premium_only` in plugixa-inventory.php), so on a free site
 * the glob finds nothing and premium modules DO NOT EXIST — as opposed to existing and being
 * locked behind a licence check, which is what WordPress.org rejects as trialware. There is
 * deliberately no `is_premium()` test here, and there must never be one: the only question this
 * class asks is whether a file is on disk.
 *
 * `is_dir()` inside scan() is what makes a missing directory a non-event rather than a warning.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * THE THIRD ROOT IS THE SAME MECHANISM POINTED AT A DIFFERENT QUESTION: not "has this site paid",
 * but "is this a working copy". `dev/` holds tooling that exists to build and test THIS PLUGIN and
 * has no business on anybody's site — the demo-data installer, which writes forty items and two
 * months of movements through the real services. `bin/release.ps1` ships an allow-list and `dev`
 * is not on it, so neither ZIP contains the directory and neither build discovers the module.
 *
 * It is a root rather than a `WP_DEBUG` check for the same reason the premium one is: a guard can
 * be flipped and a file that is not there cannot. It is a root rather than a subfolder of `pro/`
 * because the two answer different questions — a paying customer gets `pro/` and must never get
 * a button that installs forty fake items into their live inventory.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A fourth root arrives through the `plugixa_inventory_modules` filter below, which is how an
 * add-on plugin contributes a module without living in this directory at all. It is the same door
 * the premium overlay would use if it were ever repackaged as a separate plugin.
 *
 * FOUR LOOPS, AND THE APP MANAGER SKIPS EXACTLY THREE OF THEM. Every one of the four already
 * existed; switching a feature off did not need a new mechanism, only a decision about which loops
 * a disabled module sits out:
 *
 *   discover()        RUNS     — it must still be listable, describable and migratable
 *   registerHooks()   skipped  — no cron, no hooks, no entity, no strings, no config
 *   registerRoutes()  skipped  — an honest 404, never a 403 (see the method)
 *   migrate()         RUNS     — "keeps all of its data" is a promise about the schema too
 *
 * @see AppState      for how a stored flag becomes an answer.
 * @see AppInterface  for how a module says what it is without Core naming it.
 */
final class ModuleRegistry {

	/**
	 * Booted modules, keyed by id.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * What each module says it is, keyed by id. Normalised, so consumers need no guards.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $descriptors = array();

	/**
	 * Which modules actually run this request, keyed by id.
	 *
	 * @var array<string, bool>
	 */
	private array $enabled = array();

	public function __construct(
		private readonly Container $container,
		private readonly AppState $state = new AppState()
	) {}

	/**
	 * Discover and instantiate every module, then let each register its services.
	 */
	public function discover(): void {
		$classes = array_merge(
			$this->scan( PLUGIXA_INVENTORY_PATH . 'includes/Modules', 'PlugixaInventory\\Modules\\' ),
			$this->scan( PLUGIXA_INVENTORY_PATH . 'pro/includes/Modules', 'PlugixaInventory\\Pro\\Modules\\' ),
			$this->scan( PLUGIXA_INVENTORY_PATH . 'dev/includes/Modules', 'PlugixaInventory\\Dev\\Modules\\' )
		);

		/**
		 * Filter the discovered module classes.
		 *
		 * Lets a third-party add-on contribute a module without living inside this
		 * plugin's directory.
		 *
		 * @param string[] $classes Fully-qualified class names implementing ModuleInterface.
		 */
		$classes = (array) apply_filters( 'plugixa_inventory_modules', $classes );

		foreach ( $classes as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}
			if ( ! is_subclass_of( $class_name, ModuleInterface::class ) ) {
				continue;
			}

			// A broken module must not take the whole inventory (and its REST API) down.
			try {
				$module = $this->container->get( $class_name );
				$module->registerServices( $this->container );
				$this->modules[ $module->id() ] = $module;
			} catch ( Throwable $e ) {
				$this->log_failure( $class_name, $e );
			}
		}

		$this->describe();
	}

	/**
	 * Let every ENABLED module register its WordPress hooks.
	 *
	 * Skipping a disabled module here is what stops its background work: cron scheduling lives in
	 * `registerHooks()`, and so do its entity descriptor, its settings defaults, its i18n catalogue
	 * and its contribution to the client config. Everything it would say about itself to the rest
	 * of the plugin goes unsaid, which is the point — a disabled app is not a running app that
	 * refuses, it is one that is not there.
	 *
	 * The settings it has already SAVED survive regardless: `Core\Settings::keep_unclaimed()`
	 * carries forward a stored key that no `plugixa_inventory_settings_defaults` listener claims,
	 * which is precisely the state a disabled module leaves behind.
	 */
	public function registerHooks(): void {
		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->enabled( $id ) ) {
				continue;
			}

			try {
				$module->registerHooks( $this->container );
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}
	}

	/**
	 * Let every ENABLED module register its REST routes. Called on rest_api_init.
	 *
	 * NOT REGISTERING BEATS REGISTERING AND REFUSING, and the plugin has already learned this once
	 * the hard way. A controller's `register_routes()` returns early rather than declaring a
	 * `permission_callback` that says no, because one module's own test caught the first version:
	 * a registered route is listed in the REST index at `/wp-json/plugixa-inventory/v1` whether or
	 * not anybody may call it, so a switched-off module would announce itself in the one place
	 * everybody looks. A disabled app answers `rest_no_route` — an honest 404.
	 *
	 * THE MODULE THAT TAUGHT US THAT IS DELIBERATELY NOT NAMED HERE. It is a premium one, and this
	 * file ships in the FREE build: a docblock naming a premium class writes that name into the
	 * free bundle's bytes, which is what `bin/release.ps1` greps for and what Guideline 5 is about.
	 * Prose describing the rule is free to say everything; only the identifier has to go.
	 */
	public function registerRoutes(): void {
		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->enabled( $id ) ) {
				continue;
			}

			try {
				$module->registerRoutes( $this->container );
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}
	}

	/**
	 * Run EVERY module's migration, enabled or not. Idempotent.
	 *
	 * DELIBERATELY UNGATED, AND IT IS THE HALF OF THE PROMISE THAT MATTERS. The App Manager tells
	 * an administrator their data is kept and that they can pick up exactly where they left off. A
	 * disabled module whose schema stopped being upgraded would come back months and one plugin
	 * version later to a table shaped for an older release — the data would still be there and it
	 * would no longer load, which is a worse outcome than deleting it honestly.
	 *
	 * This is also why the gate is not in `discover()` and not on the `plugixa_inventory_modules`
	 * filter: `Activator::migrate()` calls `discover()` when the registry is empty, so a module
	 * skipped there would never be migrated at all.
	 */
	public function migrate(): void {
		foreach ( $this->modules as $module ) {
			try {
				$module->migrate();
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}
	}

	/**
	 * Does this module run this request?
	 *
	 * A module with no descriptor — one contributed by an add-on through the
	 * `plugixa_inventory_modules` filter, which knows nothing about the App Manager — is enabled.
	 * See `AppState::resolve()` for why the unknown case defaults on rather than off.
	 *
	 * @param string $id Module id.
	 */
	public function enabled( string $id ): bool {
		return $this->enabled[ $id ] ?? true;
	}

	/**
	 * Re-read the flags and work out what runs now.
	 *
	 * CALL THIS AFTER WRITING A FLAG, OR THE ANSWER YOU HAND BACK IS THE ONE FROM BEFORE THE WRITE.
	 * `$enabled` is computed once during `discover()`, on `plugins_loaded`, which is the right time
	 * for every request that only reads it. The request that SAVES is the exception: it changes the
	 * input after the answer was computed, so the response it renders would show the previous
	 * state and the screen would appear to have ignored the click until it was reloaded.
	 *
	 * It does not re-register anything. Hooks and routes were decided earlier in this request and
	 * cannot be taken back; the new state applies from the next one, which is what a page reload
	 * after a toggle is for.
	 */
	public function refresh(): void {
		$this->enabled = $this->state->resolve( $this->descriptors );
	}

	/**
	 * Every module's self-description, normalised.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function descriptors(): array {
		return $this->descriptors;
	}

	/**
	 * Module id => actually running.
	 *
	 * @return array<string, bool>
	 */
	public function enabledMap(): array {
		return $this->enabled;
	}

	/**
	 * The instance behind an id, so the App Manager can ask it for counts.
	 *
	 * @param string $id Module id.
	 */
	public function module( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * The flag store, so the App Manager writes through the instance that already read it.
	 *
	 * Handing out the same object rather than letting the controller build its own is what keeps
	 * `flags()`'s per-request cache honest: two instances would disagree the moment one saved.
	 */
	public function state(): AppState {
		return $this->state;
	}

	/**
	 * Collect what each module says it is, then resolve the flags into one answer per module.
	 *
	 * Only `app()` is called here. `usage()` and `blocker()` run COUNT queries and belong to the
	 * one screen that asks for them — never to `plugins_loaded`.
	 */
	private function describe(): void {
		$this->descriptors = array();

		foreach ( $this->modules as $id => $module ) {
			if ( ! $module instanceof AppInterface ) {
				continue;
			}

			try {
				$this->descriptors[ $id ] = $this->normalise( $id, $module->app() );
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}

		$this->enabled = $this->state->resolve( $this->descriptors );
	}

	/**
	 * Fill in what a descriptor left out, so nothing downstream needs a guard.
	 *
	 * An unrecognised category is filed under `tools` rather than dropped: the module still exists
	 * and still has to be switchable, and a card that vanished because of a typo in one string
	 * would be a feature nobody could turn back on.
	 *
	 * @param string               $id         Module id.
	 * @param array<string, mixed> $descriptor What the module returned.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise( string $id, array $descriptor ): array {
		$category = (string) ( $descriptor['category'] ?? 'tools' );

		return array(
			'id'        => $id,
			// Reading order inside a category. Everything unranked lands after everything ranked.
			'order'     => (int) ( $descriptor['order'] ?? 100 ),
			'category'  => in_array( $category, AppState::CATEGORIES, true ) ? $category : 'tools',
			'core'      => (bool) ( $descriptor['core'] ?? false ),
			'deps'      => array_values( array_filter( (array) ( $descriptor['deps'] ?? array() ), 'is_string' ) ),
			'default'   => (bool) ( $descriptor['default'] ?? true ),
			'label_key' => (string) ( $descriptor['label_key'] ?? 'app_' . $id ),
			'desc_key'  => (string) ( $descriptor['desc_key'] ?? 'app_' . $id . '_desc' ),
			'icon'      => (string) ( $descriptor['icon'] ?? 'apps' ),
			'cron'      => array_values( array_filter( (array) ( $descriptor['cron'] ?? array() ), 'is_string' ) ),
		);
	}

	/**
	 * Every capability introduced by every module, merged.
	 *
	 * @return array<string, string[]> Capability => roles.
	 */
	public function capabilities(): array {
		$caps = array();
		foreach ( $this->modules as $module ) {
			foreach ( $module->capabilities() as $cap => $roles ) {
				$caps[ $cap ] = array_unique( array_merge( $caps[ $cap ] ?? array(), $roles ) );
			}
		}

		return $caps;
	}

	/**
	 * @return array<string, ModuleInterface> Booted modules, keyed by id.
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * A stable fingerprint of WHICH modules are on disk.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THIS EXISTS BECAUSE THE VERSION NUMBER CANNOT ANSWER "HAS THE SCHEMA WORK BEEN DONE".
	 *
	 * `Plugin::boot()` ran `Activator::migrate()` only when the stored db_version differed from
	 * the plugin's. That is right for an update and silently wrong for the case that matters most
	 * commercially: a customer installing the PREMIUM build over the free one AT THE SAME VERSION.
	 * No activation hook fires on an in-place update, the two versions match, so migrate() was
	 * skipped — and `pro/Modules/Roles`, which appeared on disk during that copy, had its five REST
	 * routes registered against tables that had never been created. Every call answered
	 * "Table 'wp_plugixainventory_roles' doesn't exist".
	 *
	 * Found by installing the real artifacts in that order; a version bump hides it completely,
	 * which is why it survived every upgrade test that moved the version.
	 *
	 * Deliberately the module IDS and not their count: swapping one module for another is a change
	 * this has to notice, and two different sets can be the same size. Sorted, so discovery order —
	 * a filesystem glob plus whatever `plugixa_inventory_modules` adds — cannot make an unchanged
	 * install look changed and migrate on every request.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */
	public function fingerprint(): string {
		$ids = array_keys( $this->modules );

		sort( $ids );

		return md5( implode( '|', $ids ) );
	}

	/**
	 * Map each <Name>/Module.php under a root to its fully-qualified class name.
	 *
	 * @param string $dir              Absolute directory to scan.
	 * @param string $namespace_prefix PSR-4 prefix the directory maps to.
	 *
	 * @return string[]
	 */
	private function scan( string $dir, string $namespace_prefix ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$classes = array();
		foreach ( (array) glob( $dir . '/*/Module.php' ) as $file ) {
			$name = basename( dirname( (string) $file ) );
			$classes[] = $namespace_prefix . $name . '\\Module';
		}

		return $classes;
	}

	/**
	 * @param string    $class_name The module that failed.
	 * @param Throwable $e          The failure.
	 */
	private function log_failure( string $class_name, Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- module discovery failing is a developer error that must not be silent, and the call is already inside a WP_DEBUG guard.
				sprintf( '[Plugixa Inventory] Module "%s" failed: %s', $class_name, $e->getMessage() )
			);
		}
	}
}
