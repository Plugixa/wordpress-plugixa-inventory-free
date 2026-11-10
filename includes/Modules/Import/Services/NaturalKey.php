<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

use PlugixaInventory\Core\Database\IdList;
use PlugixaInventory\Core\Database\Schema as Db;
use PlugixaInventory\Core\Entities\EntityRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which record a row is already about.
 *
 * THE KEY IS LOOKED UP BEFORE THE WRITE, NOT INFERRED FROM A FAILED ONE. The obvious alternative is
 * to call `create()` always and read the 409 it returns when the value is taken. That loses on
 * facts, not on taste:
 *
 *  - **It does not exist for three of the five.** Only `ItemService` puts a `conflict_id` in its
 *    duplicate error; the category, unit and warehouse services return a 409 that names nothing, so
 *    there would be no id to update.
 *  - **It does not exist at all for `recipient`.** Nothing enforces email uniqueness on a recipient
 *    — no index, and no check in the service — so creating the same address twice SUCCEEDS. A
 *    409-driven "update the existing one" would therefore duplicate every person on every re-run of
 *    the same file, silently, and nothing would ever report it. `ImportUpsertTest` pins exactly
 *    this case, because it is the one that proves the whole approach.
 *  - **`update` mode never calls `create()`,** so there is no 409 to read at all.
 *
 * Even where it works it is a failed write per update: a create runs sanitisation, validation and
 * three foreign-key loads before refusing.
 *
 * IT READS THROUGH A QUERY BUILT FROM THE DESCRIPTOR'S `api_base`, NOT THROUGH ANOTHER MODULE'S
 * REPOSITORY. The house rule is that a module reaches another through the container, a filter or an
 * action; `Reports\Services\EntityExporter` established the pattern for this exact problem, because
 * nothing indexes repositories and building that index would mean editing every module. The
 * `api_base = table name` assumption is the same one it documents, and `EntityRegistryTest` asserts
 * it.
 *
 * ONE QUERY PER (ENTITY, COLUMN) PER BATCH, warmed from the values the batch actually contains. A
 * hundred rows carrying eighty distinct SKUs is four padded queries, not a hundred.
 */
final class NaturalKey {

	/**
	 * How an existing record of each type is recognised, in preference order.
	 *
	 * FIXED PER ENTITY RATHER THAN CHOSEN IN THE WIZARD. Each of these is already the value the
	 * service enforces uniqueness on among live rows, so it is the key the data already has; making
	 * it a dropdown would invite somebody to match items on `name`, which merges two different
	 * things called "Cable" on the strength of a coincidence.
	 *
	 * An item falls back to `barcode` because plenty of real inventories have one and not the other,
	 * and both are unique among live rows. The fallback is per ROW, not per file: a row with a SKU
	 * matches on SKU even if the row above it had only a barcode.
	 *
	 * @var array<string, string[]>
	 */
	public const KEYS = array(
		'item'      => array( 'sku', 'barcode' ),
		'category'  => array( 'code' ),
		'unit'      => array( 'code' ),
		'warehouse' => array( 'code' ),
		'recipient' => array( 'email' ),
	);

	/**
	 * Lower-cased value => id, per column. Warmed once per batch.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $known = array();

	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * The key columns for an entity, or an empty list when it has none.
	 *
	 * @param string $entity_type Entity slug.
	 *
	 * @return string[]
	 */
	public static function columns_for( string $entity_type ): array {
		return self::KEYS[ $entity_type ] ?? array();
	}

	/**
	 * Forget everything. Called at the start of each batch.
	 */
	public function reset(): void {
		$this->known = array();
	}

	/**
	 * Look up every key value this batch mentions, in one query per column.
	 *
	 * @param string                    $entity_type Entity slug.
	 * @param array<string, string[]>   $values      Column => the values seen in this batch.
	 */
	public function warm( string $entity_type, array $values ): void {
		$table = $this->table_for( $entity_type );

		if ( '' === $table ) {
			return;
		}

		foreach ( self::columns_for( $entity_type ) as $column ) {
			$wanted = array_values( array_unique( array_filter( $values[ $column ] ?? array(), static fn( string $v ): bool => '' !== $v ) ) );

			if ( array() === $wanted ) {
				continue;
			}

			$this->known[ $column ] = ( $this->known[ $column ] ?? array() ) + $this->lookup( $table, $column, $wanted );
		}
	}

	/**
	 * The id this row is already about, or null.
	 *
	 * @param string               $entity_type Entity slug.
	 * @param array<string, mixed> $data        The mapped, coerced row.
	 */
	public function match( string $entity_type, array $data ): ?int {
		foreach ( self::columns_for( $entity_type ) as $column ) {
			$value = trim( (string) ( $data[ $column ] ?? '' ) );

			if ( '' === $value ) {
				continue;
			}

			$found = $this->known[ $column ][ $this->fold( $value ) ] ?? null;

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Remember a record this batch just created.
	 *
	 * WITHOUT THIS, A FILE THAT LISTS THE SAME SKU TWICE COLLIDES WITH ITSELF: the first row creates
	 * the item, the second is looked up against a map warmed before either existed, finds nothing,
	 * and tries to create it again — which the service correctly refuses with a duplicate error. The
	 * user's file is not wrong; a spreadsheet with a repeated key is an ordinary thing, and in
	 * `create_update` mode the second occurrence should update the first.
	 *
	 * @param string               $entity_type Entity slug.
	 * @param int                  $id          The new record.
	 * @param array<string, mixed> $data        What was written.
	 */
	public function remember( string $entity_type, int $id, array $data ): void {
		foreach ( self::columns_for( $entity_type ) as $column ) {
			$value = trim( (string) ( $data[ $column ] ?? '' ) );

			if ( '' !== $value ) {
				$this->known[ $column ][ $this->fold( $value ) ] = $id;
			}
		}
	}

	/**
	 * One padded query over a set of string values.
	 *
	 * The placeholder run is a literal, exactly {@see IdList::CHUNK} long, because wordpress.org
	 * requires the statement text not to be assembled from a count. `IdList::chunks()` itself only
	 * handles integers, so the padding is done here in the same shape over strings — and
	 * `SqlLiteralTest` counts these placeholders against that constant like every other call site.
	 *
	 * Trashed rows are excluded. Uniqueness in this plugin is enforced among LIVE rows only, so a
	 * trashed item does not own its SKU any more and an import must be free to create a new one
	 * with it.
	 *
	 * @param string   $table  Real table name.
	 * @param string   $column Key column.
	 * @param string[] $values What to look for.
	 *
	 * @return array<string, int> Folded value => id.
	 */
	private function lookup( string $table, string $column, array $values ): array {
		global $wpdb;

		$found = array();

		foreach ( array_chunk( $values, IdList::CHUNK ) as $batch ) {
			$pad = $batch[0];

			while ( count( $batch ) < IdList::CHUNK ) {
				$batch[] = $pad;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, %i AS key_value FROM %i
					  WHERE deleted_at IS NULL
					    AND %i IN (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
					  ORDER BY id ASC',
					$column,
					$table,
					$column,
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$folded = $this->fold( (string) $row['key_value'] );

				// The FIRST id wins, and the ORDER BY is what makes that deterministic. Nothing in
				// the database stops two live rows sharing a key — uniqueness lives in the service —
				// so on a table that already has duplicates the import must at least behave the
				// same way twice.
				if ( ! isset( $found[ $folded ] ) ) {
					$found[ $folded ] = (int) $row['id'];
				}
			}
		}

		return $found;
	}

	/**
	 * The real table behind an entity.
	 *
	 * @param string $entity_type Entity slug.
	 */
	private function table_for( string $entity_type ): string {
		$descriptor = $this->entities->get( $entity_type );

		$base = (string) ( $descriptor['api_base'] ?? '' );

		return '' === $base ? '' : Db::table( $base );
	}

	/**
	 * How two spellings of the same key are treated as one.
	 *
	 * CASE-INSENSITIVE, BECAUSE THE SERVICES ARE. `ItemService` refuses a SKU that differs from an
	 * existing one only in case, so matching case-sensitively here would look the value up, find
	 * nothing, try to create it, and be refused — reporting a duplicate error on a row the import
	 * should simply have updated.
	 *
	 * @param string $value Raw cell.
	 */
	private function fold( string $value ): string {
		return mb_strtolower( trim( $value ) );
	}
}
