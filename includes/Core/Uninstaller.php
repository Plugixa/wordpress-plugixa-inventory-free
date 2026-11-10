<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core;

use PlugixaInventory\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What deleting the plugin does to the inventory.
 *
 * Registered through the Freemius SDK's after_uninstall action rather than a root
 * uninstall.php, which Freemius ships itself and forbids the plugin from providing.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * BY DEFAULT, NOTHING. DELETING THE PLUGIN REMOVES ITS FILES AND KEEPS THE INVENTORY.
 *
 * This used to drop every table unconditionally, and on a plugin whose data is a stock LEDGER that
 * is the wrong default by a long way. The people who delete this plugin are overwhelmingly not
 * people who want months of documents gone: they are reinstalling to fix something, or tidying the
 * plugin list after an upgrade — and the upgrade is the dangerous one. Buying the premium build
 * installs a SECOND copy beside the free one; a customer who deletes the free copy "because it is
 * the old one" was deleting the tables the premium copy runs on. Freemius's own guard only stands
 * down when the other copy is ACTIVE, so a site with both copies deactivated for five minutes of
 * troubleshooting lost everything to one click. That was reproduced, on Linux, before this changed.
 *
 * So erasing is now something an administrator asks for, with a switch in Settings that is off by
 * default — and even then it is refused while any other copy of the plugin is still installed,
 * because that copy uses the same tables and would come back to nothing.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHEN THE DATA IS KEPT, NOTHING IS TOUCHED — not the options, not the schema markers, and not the
 * capabilities. Revoking capabilities from an inventory that is staying would strip every custom
 * role and personal grant, and a reinstall only re-grants administrators: the data would come back
 * with the staff locked out of it. The schema markers are harmless to leave, because activation
 * migrates unconditionally and a different build fails `Activator::schema_is_current()` anyway.
 */
final class Uninstaller {

	/** The setting that turns erasing on. Core owns it — see `Settings::defaults()` for why. */
	public const SETTING = 'delete_data_on_uninstall';

	public const REFUSED = 'refused';
	public const KEPT    = 'kept';
	public const ERASED  = 'erased';

	public static function uninstall(): void {
		self::run( WP_PLUGIN_DIR, PLUGIXA_INVENTORY_BASENAME );
	}

	/**
	 * Decide, and act on the decision.
	 *
	 * The two parameters exist so the decision can be tested against a plugins folder that is not
	 * the real one; production passes the real one.
	 *
	 * @param string $plugins_dir  Where plugins are installed.
	 * @param string $own_basename This copy's basename, e.g. `plugixa-inventory/plugixa-inventory.php`.
	 *
	 * @return string One of the three constants above.
	 */
	public static function run( string $plugins_dir, string $own_basename ): string {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return self::REFUSED;
		}

		/*
		 * READ RAW, NOT THROUGH `Settings::get()`. The plugin is not booted during an uninstall —
		 * `plugins_loaded` has already fired by the time WordPress includes this file — so no
		 * module has registered its defaults or sanitizers, and only the stored value means anything.
		 * Strictly `true`: anything else, including a missing option, keeps the inventory.
		 */
		$settings = get_option( Settings::OPTION, array() );

		if ( ! is_array( $settings ) || true !== ( $settings[ self::SETTING ] ?? false ) ) {
			return self::KEPT;
		}

		if ( array() !== self::other_copies( $plugins_dir, $own_basename ) ) {
			return self::KEPT;
		}

		self::erase();

		return self::ERASED;
	}

	/**
	 * Other installed copies of this plugin, active or not.
	 *
	 * A FOLDER SCAN RATHER THAN ASKING THE FREEMIUS SDK. The SDK can name "the other version", but it
	 * guesses that folder's name, it is not loaded at all when its credentials are absent, and it
	 * only answers whether that copy is active — which is the question that turned out to be wrong.
	 * Every build of this plugin has the same main file name and the same text domain, so a folder
	 * holding both is another copy, whatever the folder is called.
	 *
	 * @param string $plugins_dir  Where plugins are installed.
	 * @param string $own_basename This copy's basename.
	 *
	 * @return string[] Absolute paths of the other copies' main files.
	 */
	public static function other_copies( string $plugins_dir, string $own_basename ): array {
		$own_folder = dirname( $own_basename );
		$found      = array();

		foreach ( (array) glob( trailingslashit( $plugins_dir ) . '*/' . basename( $own_basename ) ) as $file ) {
			if ( ! is_string( $file ) || basename( dirname( $file ) ) === $own_folder ) {
				continue;
			}

			$headers = get_file_data( $file, array( 'domain' => 'Text Domain' ) );

			if ( 'plugixa-inventory' === $headers['domain'] ) {
				$found[] = $file;
			}
		}

		return $found;
	}

	/**
	 * Remove everything the plugin stored. Only ever reached through `run()`.
	 */
	private static function erase(): void {
		global $wpdb;

		/*
		 * Add-on plugins clean up after THEMSELVES, in their own uninstall.php, when the customer
		 * deletes them. There is no premium overlay here to call.
		 *
		 * The prefix sweep below still takes an add-on's tables with it, and that is deliberate: this
		 * plugin is the base every pack stands on, so removing it removes the inventory entirely. It is
		 * also why an ADD-ON must never sweep by prefix — see any pack's uninstall.php.
		 */
		/**
		 * Fires during uninstall, before core tables are dropped.
		 */
		do_action( 'plugixa_inventory_uninstall' );

		// Drop by prefix rather than by a hard-coded list, so a table created by a
		// module that has since been removed still gets cleaned up.
		$like   = $wpdb->esc_like( $wpdb->prefix . 'plugixainventory_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the plugin's own tables; nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( Settings::OPTION );
		delete_option( 'plugixa_inventory_db_version' );

		// Written beside the version by Activator::migrate(). Leaving it behind would tell a
		// reinstall that the module set had not changed, and skip the migration that recreates
		// every table this uninstall just dropped.
		delete_option( 'plugixa_inventory_module_set' );

		/*
		 * Options belonging to modules are NOT enumerated here, deliberately. A module that
		 * stores one hooks `plugixa_inventory_uninstall` above and deletes its own -- which is the
		 * same rule as the schema: each module owns what it created, so a module can be added or
		 * removed without this file changing.
		 */

		Capabilities::revoke_all();
	}
}
