<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Export\Services;

use PlugixaInventory\Core\Database\QueryBuilder;
use PlugixaInventory\Core\Database\Schema as Db;
use PlugixaInventory\Core\Entities\EntityRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Any list on screen, as a CSV file.
 *
 * WHY THIS TAKES AN ENTITY SLUG AND NOT A REPOSITORY, which is what `paged_list()` does and would
 * have been the obvious symmetry. There is no registry mapping a slug to a repository — modules
 * index their SERVICES for writing (`plugixa_inventory_record_writers`) and nothing indexes their
 * repositories, deliberately, because a repository is a module's private business. Building one
 * would mean editing all seven modules to register something none of them otherwise needs.
 *
 * It turns out not to be necessary. `QueryBuilder` needs a table and an entity slug, both of which
 * `EntityRegistry` already publishes, and it is the QueryBuilder — not the repository — that fires
 * `plugixa_inventory_query_clauses`. So a builder constructed here is inside the ACL choke point
 * exactly as a repository's would be, and a premium Roles module narrows the export by narrowing
 * nothing in particular.
 *
 * THE SCREEN'S FILTERS COME ALONG FOR FREE, which is the promise `ExportButton` makes and the one
 * that would be quietly broken by any other design. `FilterRequest` hooks that same
 * `plugixa_inventory_query_clauses` for the duration of the dispatch, so `?status__eq=published`
 * narrows this file without this class containing the word "filter". A filtered list whose export
 * silently held the whole table would be discovered by somebody reconciling a spreadsheet, weeks
 * later.
 *
 * THE api_base = TABLE ASSUMPTION is the same one `RecordLoader` documents and `EntityRegistryTest`
 * asserts for every registered entity. Stated here rather than assumed, because this is the second
 * place that leans on it.
 */
final class EntityExporter {

	/**
	 * How many rows one export may contain.
	 *
	 * A CSV is not a backup. Past this the honest answer is a scheduled export, which is what the
	 * premium ImportExport module is for; an unbounded one is a request that times out after
	 * holding a database connection open long enough to matter.
	 */
	private const MAX_ROWS = 10000;

	/**
	 * `QueryBuilder::paginate()` refuses more than 200, so a large export is many pages.
	 */
	private const PAGE = 200;

	/**
	 * Field types whose values are text a person might search for.
	 *
	 * @var string[]
	 */
	private const SEARCHABLE_TYPES = array( 'text', 'email', 'textarea' );

	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * Build one entity's export.
	 *
	 * @param string $entity_type Entity slug.
	 * @param string $search      The list's search term, so the file matches the screen.
	 *
	 * @return array{filename: string, csv: string}|null Null when the entity is not registered.
	 */
	public function export( string $entity_type, string $search = '' ): ?array {
		$descriptor = $this->entities->get( $entity_type );

		if ( null === $descriptor ) {
			return null;
		}

		$api_base = (string) ( $descriptor['api_base'] ?? '' );

		if ( '' === $api_base ) {
			return null;
		}

		/** @var array<string, array<string, mixed>> $fields */
		$fields = (array) ( $descriptor['fields'] ?? array() );

		$columns  = array_keys( $fields );
		$headings = array_merge(
			array( __( 'ID', 'plugixa-inventory' ) ),
			array_map(
				static fn( array $field ): string => (string) ( $field['label'] ?? '' ),
				array_values( $fields )
			)
		);

		$rows = $this->resolve_records( $this->read( $api_base, $entity_type, $fields, $search ), $fields );

		return array(
			'filename' => $this->filename( $api_base ),
			'csv'      => CsvWriter::build(
				$headings,
				array_map(
					static function ( array $row ) use ( $columns ): array {
						$cells = array( $row['id'] ?? '' );

						foreach ( $columns as $column ) {
							$cells[] = $row[ $column ] ?? '';
						}

						return $cells;
					},
					$rows
				)
			),
		);
	}

	/**
	 * Read every row the current user may see, a page at a time.
	 *
	 * PAGED RATHER THAN ONE BIG SELECT because `paginate()` caps at 200 and that cap is not this
	 * class's to lift — it is what keeps a mistyped `per_page` from being a denial of service on
	 * every list route in the plugin. Fifty queries to build a ten-thousand-row file is a fine
	 * trade for not weakening that.
	 *
	 * ONE BUILDER, REUSED. `prepare()` is idempotent by design (its docblock explains why: a list
	 * route calls `get()` and `count()` on the same builder), so re-paginating and re-reading does
	 * not re-apply the ACL joins. A fresh builder per page would also re-run the filter hook per
	 * page, which is the same answer at more cost.
	 *
	 * @param string                             $api_base    Table, per the convention above.
	 * @param string                             $entity_type Entity slug, handed to the ACL filter.
	 * @param array<string, array<string, mixed>> $fields      The entity's field definitions.
	 * @param string                             $search      Search term, or empty.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read( string $api_base, string $entity_type, array $fields, string $search ): array {
		$query = new QueryBuilder( Db::table( $api_base ), $entity_type, 'export' );

		$query->order_by( 't.id', 'ASC' )->search( $search, $this->search_columns( $fields ) );

		$rows   = array();
		$offset = 0;

		while ( count( $rows ) < self::MAX_ROWS ) {
			$page = $query->paginate( self::PAGE, $offset )->get();

			if ( array() === $page ) {
				break;
			}

			$rows = array_merge( $rows, $page );

			// A short page is the last page. Without this an export of exactly 200 rows would run
			// a second query to learn nothing.
			if ( count( $page ) < self::PAGE ) {
				break;
			}

			$offset += self::PAGE;
		}

		return array_slice( $rows, 0, self::MAX_ROWS );
	}

	/**
	 * Replace foreign keys with the names they point at.
	 *
	 * WITHOUT THIS THE FILE SAYS `Category: 79`, and a spreadsheet full of row ids is not an export
	 * — it is a database dump that happens to open in Excel. The person who asked for it wants
	 * "Tools", and they have no way to resolve 79 into it.
	 *
	 * RESOLVED THROUGH `plugixa_inventory_record_names`, one call per FIELD rather than one per row.
	 * A ten-thousand-row export of items has three record columns, so this is three queries; doing
	 * it per row would be thirty thousand, which is the difference between an export and a timeout.
	 * It is also the same filter the ledger and dashboard controllers use, so this class learns
	 * nothing about categories, warehouses or units beyond the entity slugs already in the descriptor.
	 *
	 * A NAME THAT CANNOT BE RESOLVED KEEPS ITS ID rather than becoming blank. A row pointing at a
	 * deleted category is a fact worth exporting; an empty cell would read as "no category", which
	 * is a different fact.
	 *
	 * @param array<int, array<string, mixed>>    $rows   The rows.
	 * @param array<string, array<string, mixed>> $fields The entity's field definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_records( array $rows, array $fields ): array {
		if ( array() === $rows ) {
			return $rows;
		}

		foreach ( $fields as $column => $field ) {
			if ( 'record' !== ( $field['type'] ?? '' ) ) {
				continue;
			}

			$entity = (string) ( $field['entity'] ?? '' );

			if ( '' === $entity ) {
				continue;
			}

			$ids = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( array $row ): int => (int) ( $row[ $column ] ?? 0 ),
							$rows
						)
					)
				)
			);

			if ( array() === $ids ) {
				continue;
			}

			/** @var array<int, string> $names */
			$names = (array) apply_filters( 'plugixa_inventory_record_names', array(), $entity, $ids );

			$rows = array_map(
				static function ( array $row ) use ( $column, $names ): array {
					$id = (int) ( $row[ $column ] ?? 0 );

					if ( $id > 0 && isset( $names[ $id ] ) ) {
						$row[ $column ] = $names[ $id ];
					}

					return $row;
				},
				$rows
			);
		}

		return $rows;
	}

	/**
	 * The columns a search term is matched against.
	 *
	 * Derived from the entity's own field types rather than from a per-entity list, because this
	 * class has no per-entity knowledge and acquiring some would defeat the point of it. A
	 * repository's `search()` may cover more — `t.reason`, say — so an export's search can be
	 * narrower than the screen's. It is never WIDER, which is the direction that would matter.
	 *
	 * @param array<string, array<string, mixed>> $fields The entity's field definitions.
	 *
	 * @return string[]
	 */
	private function search_columns( array $fields ): array {
		$columns = array();

		foreach ( $fields as $name => $field ) {
			if ( in_array( (string) ( $field['type'] ?? '' ), self::SEARCHABLE_TYPES, true ) ) {
				$columns[] = 't.' . $name;
			}
		}

		return $columns;
	}

	/**
	 * @param string $api_base The entity's collection name.
	 */
	private function filename( string $api_base ): string {
		return sprintf( '%s-%s.csv', $api_base, gmdate( 'Y-m-d' ) );
	}
}
