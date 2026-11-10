<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Entities;

use PlugixaInventory\Core\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load any record, given only its entity slug and its id.
 *
 * WHY THIS EXISTS, AND WHY IT IS IN CORE
 *
 * A cross-cutting module — tags, custom fields, notes, reports, record-level ACL — works on a
 * record it does not own and must not depend on the module that does. Tagging a contact cannot
 * mean importing ContactRepository, or the Tags module is welded to Inventory and neither can move.
 *
 * But authorisation needs the record. Capabilities::user_can( 'edit', 'contact', $record ) is
 * how the premium Teams module answers "may you edit THIS one", and a controller that hands it
 * null behaves identically in free while silently granting an "own records only" user the right
 * to tag every contact in the database. RestPermissionsTest says this in more words.
 *
 * So the lookup has to exist, it has to be generic, and it cannot live in a leaf module. It sits
 * next to EntityRegistry because that is the thing that knows what an entity slug means.
 *
 * THE ASSUMPTION, STATED OUT LOUD
 *
 * A descriptor carries `api_base` ("contacts"), not a table name, and every registered entity's
 * api_base happens to equal its table. That is a convention, not a contract — nothing enforces
 * it, and a module could register an entity where it does not hold. EntityRegistryTest asserts
 * it for every registered entity, so the day someone breaks it, a test says so instead of a
 * permission check quietly reading nothing.
 *
 * The `plugixa_inventory_load_record` filter is the escape hatch for an entity that needs one.
 */
final class RecordLoader {

	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * The record, or null if there is no such entity type or no such row.
	 *
	 * @param string $entity_type Entity slug, e.g. "contact".
	 * @param int    $id          Record id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function load( string $entity_type, int $id ): ?array {
		$record = null;

		$descriptor = $this->entities->get( $entity_type );

		if ( null !== $descriptor && $id > 0 ) {
			$record = $this->from_table( (string) ( $descriptor['api_base'] ?? '' ), $id );
		}

		/**
		 * Filters the record a cross-cutting module loaded for an authorisation check.
		 *
		 * The default lookup assumes an entity's table is named after its api_base. An entity
		 * that does not follow that convention must answer here, or record-level permissions
		 * will not see it.
		 *
		 * @param array<string, mixed>|null $record      The record, or null.
		 * @param string                    $entity_type Entity slug.
		 * @param int                       $id          Record id.
		 */
		return apply_filters( 'plugixa_inventory_load_record', $record, $entity_type, $id );
	}

	/**
	 * The bare table name an api_base refers to.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * ONE `str_replace`, AND ITS ABSENCE MADE AN ENTIRE ENTITY UNLOADABLE.
	 *
	 * An api_base is a REST path segment and a table name is an identifier, and they are spelled
	 * differently the moment an entity has a two-word name: the route is `/stock-movements`
	 * because that is how a URL reads, and the table is `stock_movements` because MySQL names in
	 * this plugin never carry hyphens. Using the api_base verbatim therefore worked for every
	 * single-word entity — items, warehouses, categories, units, recipients, transfers,
	 * adjustments, transactions — and missed exactly one.
	 *
	 * WHAT THAT COST, AND WHY NOTHING SHOWED IT: `from_table()` answers null when the table does
	 * not exist, because a record that cannot be found is deliberately indistinguishable from one
	 * the user may not see. `RestController::record_for()` passes that null to
	 * `plugixa_inventory_user_can`, so a record-level rule would be asked to judge a stock movement
	 * it had never been given. In the free build authorisation is all-or-nothing and never looks at
	 * the record, so the free build behaves identically — it becomes a permission hole precisely
	 * when the premium Roles module ships, which is the worst possible time to find it.
	 *
	 * `RecordLoader`'s own docblock has said since P1 that "EntityRegistryTest asserts it for every
	 * registered entity, so the day someone breaks it, a test says so". That test did not exist.
	 * It does now, and it failed on its first run.
	 *
	 * PUBLIC AND STATIC so the test can assert the mapping directly rather than inferring it from a
	 * null return, which is the shape that hid this in the first place.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $api_base The descriptor's api_base, e.g. "stock-movements".
	 */
	public static function table_for( string $api_base ): string {
		return str_replace( '-', '_', $api_base );
	}

	/**
	 * @param string $table Bare table name, or the api_base it is derived from.
	 * @param int    $id    Record id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function from_table( string $table, int $id ): ?array {
		global $wpdb;

		$table = self::table_for( $table );

		if ( '' === $table || ! Schema::exists( $table ) ) {
			return null;
		}

		$name = Schema::table( $table );

		// The table name is bound as an identifier, and it never comes from the request anyway:
		// it comes from a registered descriptor and is checked to exist first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL LIMIT 1', $name, $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
