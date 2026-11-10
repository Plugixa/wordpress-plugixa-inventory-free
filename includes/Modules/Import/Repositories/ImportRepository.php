<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Repositories;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The import runs.
 *
 * A run IS a record — it is created, listed, and soft-deleted like anything else — so this extends
 * `Repository` and gets the audit columns, the `deleted_at` scoping and the lifecycle events for
 * free. Its per-line log does not, and {@see ImportRowRepository} explains why.
 */
final class ImportRepository extends Repository {

	protected function table(): string {
		return 'imports';
	}

	protected function entity_type(): string {
		return 'import';
	}

	/**
	 * Everything the service writes. Deliberately includes the counters.
	 *
	 * `total_rows` and the five counters are fillable because the only thing that ever writes them
	 * is the importer itself, batch by batch — there is no REST route that accepts a run's body, so
	 * `only_fillable()` is not acting as an input guard here the way it does on an entity. What it
	 * still does is stop a stray key in an internal array becoming a column write.
	 *
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'entity_type',
			'mode',
			'status',
			'file_name',
			'disk_name',
			'delimiter_char',
			'enclosure_char',
			'has_header',
			'mapping',
			'options',
			'total_rows',
			'offset_rows',
			'created_count',
			'updated_count',
			'skipped_count',
			'error_count',
			'stock_lines',
			'stock_error_count',
			'revert_offset',
			'reverted_at',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'file_name', 'entity_type', 'status', 'total_rows', 'created_at' );
	}

	/**
	 * A run's numbers, typed.
	 *
	 * The columns come back from `$wpdb` as strings, and the client's progress bar divides two of
	 * them. `"1200" / "4182"` happens to work in PHP and does not in JSON, where the difference
	 * between `1200` and `"1200"` is the difference between a number and a label.
	 *
	 * @param array<string, mixed> $row One run.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'total_rows', 'offset_rows', 'created_count', 'updated_count', 'skipped_count', 'error_count', 'stock_lines', 'stock_error_count' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		$row['has_header'] = ! empty( $row['has_header'] );

		$row['mapping'] = $this->decode( (string) ( $row['mapping'] ?? '' ) );
		$row['options'] = $this->decode( (string) ( $row['options'] ?? '' ) );

		// The file is dropped once a run is old (see `Module::register_cleanup()`), and the screen
		// has to be able to say so rather than offering a resume that cannot read anything.
		$row['has_file'] = '' !== (string) ( $row['disk_name'] ?? '' );

		// NEVER LEAVES THE SERVER. It is the one value that, combined with a directory listing,
		// would undo the whole "the name is unguessable" barrier.
		unset( $row['disk_name'] );

		return $row;
	}

	/**
	 * Whether any run is part-way through.
	 *
	 * What `Module::blocker()` asks before letting the App Manager switch Import off.
	 */
	public function has_unfinished(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE deleted_at IS NULL AND reverted_at IS NULL AND status IN ( %s, %s ) AND offset_rows < total_rows',
				$this->table_name(),
				'mapped',
				'running'
			)
		);

		return (int) $count > 0;
	}

	/**
	 * How many runs have finished. The App Manager card's one figure.
	 */
	public function finished_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE deleted_at IS NULL AND status = %s',
				$this->table_name(),
				'done'
			)
		);

		return (int) $count;
	}

	/**
	 * Finished runs whose stored file has outlived its usefulness.
	 *
	 * A run still `pending` or unfinished is never returned however old it is, because that file is
	 * the only thing a resume could read. The cutoff is compared against `modified_at`, which is
	 * UTC like every audit column in this plugin — hence `gmdate()` rather than `current_time()`.
	 *
	 * @param int $days How long a file is kept once its run is over.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function stale_files( int $days ): array {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, disk_name FROM %i WHERE disk_name <> %s AND modified_at < %s AND status IN ( %s, %s, %s ) LIMIT 200',
				$this->table_name(),
				'',
				$cutoff,
				'done',
				'reverted',
				'failed'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The stored file name for a run, which `cast()` deliberately strips.
	 *
	 * Separate from `find()` so that reaching for it is a decision rather than something that
	 * happens by default — every caller of this is a place the raw name is genuinely needed.
	 *
	 * @param int $id The run.
	 */
	public function disk_name( int $id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$name = $wpdb->get_var(
			$wpdb->prepare( 'SELECT disk_name FROM %i WHERE id = %d', $this->table_name(), $id )
		);

		return (string) $name;
	}

	/**
	 * Table name for another class in this module that needs to join against it.
	 */
	public function table_for_join(): string {
		return Db::table( 'imports' );
	}

	/**
	 * @param string $json Stored JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function decode( string $json ): array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
