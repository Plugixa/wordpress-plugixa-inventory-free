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
 * Turning "Power tools" into a category id.
 *
 * NOTHING ELSE IN THIS PLUGIN RESOLVES A FOREIGN KEY BY NAME. `ItemService::validate()` loads a
 * category, a warehouse and a unit strictly by id, and there is no `find_by_name()` on any
 * repository. That is right for the REST API, where the client picked from a list and has the id —
 * and useless to an import, where the cell says what a human typed into a spreadsheet. So this
 * module owns the translation.
 *
 * BY NAME *OR* CODE, CASE-INSENSITIVELY, AND CODE WINS. A code is an identifier and a name is a
 * label, so when a file contains a value that is one entity's code and another's name, the
 * identifier is the better bet. Both are accepted because an exported file carries names (the CSV
 * exporter resolves relations to display names) while a hand-built one usually carries codes.
 *
 * A BARE INTEGER IS ALSO ACCEPTED, so a file produced by this plugin's own REST API — which writes
 * ids — round-trips as well as one produced by its CSV export.
 *
 * AMBIGUITY IS AN ERROR, NEVER A COIN FLIP. Nothing enforces name uniqueness on any entity: no
 * index, no service check. If two live categories are both called "Fixings", a row naming "Fixings"
 * has no correct answer, and picking the lower id would file four hundred items under whichever one
 * happened to be created first. The row is refused and both ids are named, which is something
 * somebody can act on.
 *
 * NOTHING IS EVER CREATED. A miss fails that row and says what was not found. Auto-creating from a
 * typo'd cell puts "Power Tolls" next to "Power Tools" in the picker for ever, and the person who
 * finds it six months later cannot tell which items are really in which.
 *
 * IT IS WARMED FROM THE VALUES THE BATCH CONTAINS, not from the whole table. A hundred rows
 * referencing three categories is one query rather than a hundred — and, unlike loading every
 * published category once and caching it, it stays correct on a site with more categories than a
 * list page returns.
 */
final class LookupCache {

	/** Returned instead of an id when two live rows answer to the same value. */
	public const AMBIGUOUS = -1;

	/**
	 * entity => folded value => id (or AMBIGUOUS).
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $resolved = array();

	/**
	 * entity => folded value => the ids that answered, for the error message.
	 *
	 * @var array<string, array<string, int[]>>
	 */
	private array $clashes = array();

	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * Forget everything. Called at the start of each batch.
	 */
	public function reset(): void {
		$this->resolved = array();
		$this->clashes  = array();
	}

	/**
	 * Look up everything this batch mentions, one query per entity per column.
	 *
	 * @param array<string, string[]> $wanted Entity slug => the values seen in this batch.
	 */
	public function warm( array $wanted ): void {
		foreach ( $wanted as $entity_type => $values ) {
			$base = $this->base_for( (string) $entity_type );

			if ( '' === $base ) {
				continue;
			}

			$table = Db::table( $base );

			$clean = array_values(
				array_unique(
					array_filter(
						array_map( static fn( $v ): string => trim( (string) $v ), $values ),
						static fn( string $v ): bool => '' !== $v
					)
				)
			);

			if ( array() === $clean ) {
				continue;
			}

			/*
			 * NAME FIRST, THEN CODE, SO CODE OVERWRITES IT. Both columns are merged into one lookup
			 * because a cell is one value and we do not know which kind it is; running the code pass
			 * second is what makes "code wins" true without a second branch at read time.
			 *
			 * BOTH ARE ALWAYS CONSULTED, WHICH IS WHAT MAKES A UNIT COLUMN WORK. `UnitRepository`
			 * overrides its display column to `code`, because every movement line and report shows
			 * "kg" rather than "Kilogram" — so a file this plugin exported carries codes where a
			 * hand-written one carries names, and reading only one column would fail one of them.
			 */
			$this->absorb( (string) $entity_type, $table, 'name', $clean );

			if ( $this->has_code( $base ) ) {
				$this->absorb( (string) $entity_type, $table, 'code', $clean );
			}
		}
	}

	/**
	 * The id for one cell, `AMBIGUOUS`, or null when nothing matched.
	 *
	 * @param string $entity_type Entity slug.
	 * @param string $value       The cell.
	 */
	public function resolve( string $entity_type, string $value ): ?int {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		// A bare integer is taken as an id, but only if it really is a live row of that type —
		// otherwise a category literally named "12" would be unreachable.
		if ( 1 === preg_match( '/^[1-9][0-9]*$/', $value ) && $this->row_exists( $entity_type, (int) $value ) ) {
			return (int) $value;
		}

		return $this->resolved[ $entity_type ][ $this->fold( $value ) ] ?? null;
	}

	/**
	 * Remember a record this batch just created, so a later row can point at it.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * A FILE MAY DEFINE SOMETHING AND THEN REFER TO IT. The map is warmed once, from the values the
	 * batch contains, BEFORE any row is written — so without this, a units file that defines
	 * "ZZKG" on line 2 and gives "ZZG" a base unit of "ZZKG" on line 3 fails on line 3 with
	 * "nothing here is called ZZKG", having created it seconds earlier.
	 *
	 * That is not a contrived ordering: defining a base unit and then its derivatives is the
	 * natural way to write that file, and units are the one entity whose relation points at its own
	 * table. `NaturalKey` already had exactly this problem and exactly this fix for repeated keys;
	 * this is the same fix for relations, and it was missing because nothing but items had ever
	 * been imported.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string               $entity_type Entity slug.
	 * @param array<string, mixed> $data        What was written.
	 * @param int                  $id          The new record.
	 */
	public function remember( string $entity_type, array $data, int $id ): void {
		foreach ( array( 'name', 'code' ) as $column ) {
			$value = trim( (string) ( $data[ $column ] ?? '' ) );

			if ( '' === $value ) {
				continue;
			}

			$folded = $this->fold( $value );

			// An ambiguity already recorded survives: a value that was two things does not become
			// unambiguous by a third row using it.
			if ( self::AMBIGUOUS !== ( $this->resolved[ $entity_type ][ $folded ] ?? 0 ) ) {
				$this->resolved[ $entity_type ][ $folded ] = $id;
			}
		}
	}

	/**
	 * The ids that clashed for a value, so the refusal can name them.
	 *
	 * @param string $entity_type Entity slug.
	 * @param string $value       The cell.
	 *
	 * @return int[]
	 */
	public function clashing( string $entity_type, string $value ): array {
		return $this->clashes[ $entity_type ][ $this->fold( $value ) ] ?? array();
	}

	/**
	 * Read one column's worth of matches into the map.
	 *
	 * @param string   $entity_type Entity slug.
	 * @param string   $table       Real table name.
	 * @param string   $column      Column to match on.
	 * @param string[] $values      What to look for.
	 */
	private function absorb( string $entity_type, string $table, string $column, array $values ): void {
		global $wpdb;

		foreach ( array_chunk( $values, IdList::CHUNK ) as $batch ) {
			$pad = $batch[0];

			while ( count( $batch ) < IdList::CHUNK ) {
				$batch[] = $pad;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, %i AS match_value FROM %i
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

			$seen = array();

			foreach ( (array) $rows as $row ) {
				$folded = $this->fold( (string) $row['match_value'] );
				$id     = (int) $row['id'];

				$seen[ $folded ][] = $id;
			}

			foreach ( $seen as $folded => $ids ) {
				$ids = array_values( array_unique( $ids ) );

				if ( count( $ids ) > 1 ) {
					$this->resolved[ $entity_type ][ $folded ] = self::AMBIGUOUS;
					$this->clashes[ $entity_type ][ $folded ]  = $ids;

					continue;
				}

				// A code match replaces a name match; a name match never replaces a code match.
				// `absorb()` is called with the name column first, so a plain assignment here is
				// what implements "code wins" — but an ambiguity already recorded must survive,
				// because a value that is two things is not made unambiguous by being one of them
				// under a different column.
				if ( self::AMBIGUOUS !== ( $this->resolved[ $entity_type ][ $folded ] ?? 0 ) ) {
					$this->resolved[ $entity_type ][ $folded ] = $ids[0];
				}
			}
		}
	}

	/**
	 * Whether a row of this type exists, for the bare-integer path.
	 *
	 * @param string $entity_type Entity slug.
	 * @param int    $id          Candidate id.
	 */
	private function row_exists( string $entity_type, int $id ): bool {
		$base = $this->base_for( $entity_type );

		if ( '' === $base ) {
			return false;
		}

		$table = Db::table( $base );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND deleted_at IS NULL', $table, $id )
		);

		return null !== $found;
	}

	/**
	 * Whether this table has a `code` column at all.
	 *
	 * Recipients do not, so asking for one would be a SQL error rather than an empty result.
	 *
	 * **IT TAKES THE BARE NAME, NOT THE REAL ONE.** `Schema::has_column()` prefixes what it is
	 * given, so handing it an already-prefixed table asks about `wp_plugixainventory_wp_plugixainventory_categories`
	 * — which does not exist, so the answer is a confident "no column" rather than an error. That
	 * made every code lookup silently return nothing while matching by name went on working, which
	 * is exactly the shape of bug this module exists to avoid producing in other people's data.
	 *
	 * @param string $base The entity's `api_base`, i.e. the bare table name.
	 */
	private function has_code( string $base ): bool {
		static $known = array();

		if ( ! isset( $known[ $base ] ) ) {
			$known[ $base ] = Db::has_column( $base, 'code' );
		}

		return $known[ $base ];
	}

	/**
	 * The bare table name behind an entity.
	 *
	 * `api_base` IS THE TABLE NAME, an assumption this plugin already documents and tests where the
	 * CSV exporter makes it, and the reason a module can be reached generically without anything
	 * indexing repositories.
	 *
	 * @param string $entity_type Entity slug.
	 */
	private function base_for( string $entity_type ): string {
		$descriptor = $this->entities->get( $entity_type );

		return (string) ( $descriptor['api_base'] ?? '' );
	}

	/**
	 * @param string $value Raw cell.
	 */
	private function fold( string $value ): string {
		return mb_strtolower( trim( $value ) );
	}
}
