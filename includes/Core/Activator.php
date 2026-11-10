<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core;

use PlugixaInventory\Core\Modules\ModuleRegistry;
use PlugixaInventory\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation and schema migration.
 */
final class Activator {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::migrate();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		/*
		 * NO EXAMPLE CONTENT HERE, deliberately.
		 *
		 * An empty inventory is a usable inventory — unlike a sales pipeline, nothing is broken
		 * by having no rows. Sample content is a thing the user asks for, from a tool that ships in
		 * which reaches the Demo module. Seeding forty items on activation would fight anyone who
		 * deliberately cleared them, and on a live inventory it would corrupt the ledger.
		 */

		/**
		 * Fires after the inventory is activated and its tables exist.
		 */
		do_action( 'plugixa_inventory_activated' );
	}

	/**
	 * Create/upgrade tables and capabilities. Idempotent, so it is safe to run on
	 * activation and again whenever the stored version differs from the shipped one.
	 */
	public static function migrate(): void {
		$plugin  = Plugin::instance();
		$modules = $plugin->modules();

		// A file-copy update can reach this before boot() has run.
		if ( ! $modules->all() ) {
			$modules->discover();
		}

		$modules->migrate();

		/*
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * THERE IS NO LEGACY CLEANUP HERE, AND THAT IS THE FIX RATHER THAN AN OMISSION.
		 *
		 * A `LegacyCleanup` class was copied in from the CRM during P1, along with its list of ~50
		 * tables left behind by six add-on plugins that CRM used to host. None of that history
		 * exists here: this plugin has never shipped a version, so there is nothing to clean up.
		 * What the list did have was a name collision — `warehouses`, which the CRM's inventory
		 * ADD-ON owned and which is a live, first-class table in THIS plugin.
		 *
		 * It had a guard for exactly that, and the guard was dead. It resolved a module's Schema
		 * class with `str_replace( '\Module', '\Schema', $module::class )`, and `\Modules\` also
		 * matches `\Module` — so `PlugixaInventory\Modules\Warehouses\Module` became
		 * `PlugixaInventory\Schemas\Warehouses\Schema`, which does not exist. Every module
		 * therefore claimed nothing, and `warehouses` was dropped on the first migrate of every
		 * install. It survived here only because the sweep sets a done-flag and the NEXT migrate
		 * recreated the table; on a fresh install it is created, dropped, and simply absent until
		 * the next version bump.
		 *
		 * `SchemaTest::test_every_declared_table_exists` is what found it, on a clean test database,
		 * within a minute of the suite first running. Nothing else would have: on the dev site the
		 * table was already back.
		 *
		 * The class was deleted rather than repaired. Fixing the `str_replace` would leave a
		 * "drop these 50 tables" list inside an inventory plugin where a third of the names —
		 * `products`, `suppliers`, `attributes`, `price_books`, `purchase_orders` — are plausible
		 * tables for a future phase to claim, each one a fresh chance for the same collision.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 */

		Capabilities::grant(
			array_merge(
				array_fill_keys( Capabilities::ALL, array( 'administrator' ) ),
				$modules->capabilities()
			)
		);

		/*
		 * THE CAPABILITY BACKFILL IS DELIBERATELY NOT CALLED HERE. It has to read users, and
		 * `get_userdata()` is pluggable — `pluggable.php` is required after the plugin files are
		 * included, so anything reaching `migrate()` early (a `muplugins_loaded` caller, which is
		 * how the test harness activates) would fatal on an undefined function. `WP_User_Query`
		 * says the same thing more politely, with a `_doing_it_wrong` about `plugins_loaded`.
		 * It runs on `init` instead, from `Capabilities::register_hooks()`, guarded by its own
		 * marker — see `Capabilities::backfill()`.
		 */

		update_option( 'plugixa_inventory_db_version', PLUGIXA_INVENTORY_VERSION );

		/*
		 * WRITTEN TOGETHER WITH THE VERSION, AND READ TOGETHER WITH IT BY `Plugin::boot()`. The
		 * version answers "is this a newer build"; this answers "are these the same modules". A
		 * premium install dropped over a free one at the same version changes the second and not
		 * the first — see `ModuleRegistry::fingerprint()` for what that cost.
		 */
		update_option( 'plugixa_inventory_module_set', $modules->fingerprint() );
	}

	/**
	 * Whether the database already matches the code on disk.
	 *
	 * A METHOD RATHER THAN A CONDITION INSIDE `Plugin::boot()`, so it can be tested. The version
	 * half of this was inline for the whole life of the plugin and was wrong for the premium
	 * upgrade the entire time; nothing could assert it without booting the plugin, which is exactly
	 * the shape of thing that goes unverified.
	 *
	 * BOTH HALVES, AND THE SECOND IS THE ONE WITH HISTORY. The version answers "is this a newer
	 * build"; the fingerprint answers "are these the same modules". Installing premium over free at
	 * the same version changes only the second, and no activation hook fires on an in-place update,
	 * so the version alone said the schema was current while `pro/Modules/Roles` had five REST
	 * routes pointed at tables that did not exist.
	 *
	 * A site that has never migrated stores `false` for both, which is not equal to either value,
	 * so it migrates rather than waiting for a version bump that may never come.
	 *
	 * @param ModuleRegistry $modules The registry, already discovered.
	 */
	public static function schema_is_current( ModuleRegistry $modules ): bool {
		return PLUGIXA_INVENTORY_VERSION === get_option( 'plugixa_inventory_db_version' )
			&& $modules->fingerprint() === get_option( 'plugixa_inventory_module_set' );
	}

}
