<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table-name and dbDelta helpers shared by every module's migration.
 *
 * Schema ownership is decentralised: this class creates no tables of its own. Each
 * module declares and creates the tables it owns, so a premium module can add tables
 * without any free file changing.
 */
final class Schema {

	/**
	 * Fully-qualified table name for an inventory entity.
	 *
	 * @param string $name Bare table name, e.g. "items".
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'plugixainventory_' . $name;
	}

	/**
	 * The engine and charset clause every inventory table is created with.
	 *
	 * InnoDB is requested EXPLICITLY rather than inherited from the server's default,
	 * because that default cannot be relied on — this very machine defaults to MyISAM, and
	 * an inventory on MyISAM is quietly broken in three ways that never announce themselves:
	 *
	 *  - no transactions. START TRANSACTION and ROLLBACK are accepted and silently ignored,
	 *    so code that believes it is atomic simply is not.
	 *  - table-level locking. Every write locks the whole table, so two people finalizing stock
	 *    movements at the same time serialise behind each other.
	 *  - no crash recovery. A power cut leaves a corrupt table rather than a rolled-back
	 *    transaction.
	 *
	 * None of that is acceptable for the system a business runs its stock on. The first of the three
	 * is load-bearing here in a way it was not for a CRM: the stock engine depends on row-level
	 * behaviour — a guarded UPDATE reporting how many rows it actually changed — that a table
	 * lock turns into a queue and a missing rollback turns into a lie.
	 */
	public static function charset(): string {
		global $wpdb;

		return 'ENGINE=InnoDB ' . $wpdb->get_charset_collate();
	}

	/**
	 * Convert any inventory table that is not already InnoDB.
	 *
	 * dbDelta only ever adds and alters columns — it will not change a table's engine — so
	 * an installation created before the ENGINE clause existed would stay on MyISAM
	 * forever. This is the upgrade path, and it is a no-op on a table that is already
	 * right.
	 *
	 * @param string ...$names Bare table names.
	 */
	public static function ensure_innodb( string ...$names ): void {
		global $wpdb;

		foreach ( $names as $name ) {
			$table = self::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);

			if ( null === $engine || 0 === strcasecmp( (string) $engine, 'InnoDB' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a schema change is the entire point of this method; nothing to cache.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		}
	}

	/**
	 * Run one or more CREATE TABLE statements through dbDelta.
	 *
	 * dbDelta is picky in ways that fail silently: types must be lowercase, there
	 * must be two spaces after PRIMARY KEY, and every KEY must be named. Statements
	 * are passed through verbatim, so those rules are the caller's to honour.
	 *
	 * Returns what dbDelta changed. On a schema that has already been applied that must
	 * be empty — if it is not, dbDelta disagrees with what MySQL actually stored and will
	 * keep issuing ALTERs on every single page load, silently, forever. SchemaTest asserts
	 * exactly that, which is the only way to catch the whole class of bug.
	 *
	 * @param string ...$statements CREATE TABLE statements.
	 *
	 * @return array<string, string> What dbDelta changed, keyed by table.column.
	 */
	public static function delta( string ...$statements ): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$changes = array();

		foreach ( $statements as $sql ) {
			$changes = array_merge( $changes, (array) dbDelta( $sql ) );
		}

		return $changes;
	}

	/**
	 * Whether an inventory table exists.
	 *
	 * @param string $name Bare table name.
	 */
	public static function exists( string $name ): bool {
		global $wpdb;

		$table = self::table( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Whether a table still has a column.
	 *
	 * THE REASON THIS EXISTS: dbDelta can add a column and cannot remove one. It compares the
	 * `CREATE TABLE` it is given against the live table and issues the ALTERs that ADD what is
	 * missing — a column you deleted from the statement is simply ignored, and stays for ever.
	 *
	 * So a migration that MOVES data out of a column has to drop it explicitly afterwards, and has
	 * to ask first, because it will run again on the next upgrade. Leaving a populated legacy column
	 * beside its replacement is worse than untidy: it is a second answer to the same question that
	 * somebody reads in two years, and `SchemaTest` cannot catch it, because dbDelta reports no
	 * changes for a column it was never told about.
	 *
	 * @param string $table  Bare table name.
	 * @param string $column Column name.
	 */
	public static function has_column( string $table, string $column ): bool {
		global $wpdb;

		$name = self::table( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection; a cached answer would be the whole bug.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $name, $column )
		);
	}

	/**
	 * Drop a column, if it is still there.
	 *
	 * Guarded rather than blind, because a migration runs on every version bump and the second run
	 * must be a no-op rather than an error.
	 *
	 * @param string $table  Bare table name.
	 * @param string $column Column name.
	 */
	public static function drop_column( string $table, string $column ): void {
		if ( ! self::has_column( $table, $column ) ) {
			return;
		}

		global $wpdb;

		$name = self::table( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a schema change is the entire point of this method; nothing to cache.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $name, $column ) );
	}

	/**
	 * Drop inventory tables.
	 *
	 * THE UNINSTALLER IS THE ONLY CALLER, AND IT SHOULD STAY THAT WAY. This method used to have a
	 * second one — a legacy-table sweep copied over from the CRM — which dropped `warehouses` on
	 * every fresh install because the CRM's inventory add-on had owned a table by that name. See
	 * `Activator::migrate()` for the whole story. A method that drops tables is worth keeping to
	 * one caller whose entire job is destroying data on purpose.
	 *
	 * @param string ...$names Bare table names.
	 */
	public static function drop( string ...$names ): void {
		global $wpdb;

		foreach ( $names as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the plugin's own tables; nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
