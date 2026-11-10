<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Repositories;

use PlugixaInventory\Core\Database\IdList;
use PlugixaInventory\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What happened to every line of the file.
 *
 * DELIBERATELY NOT A `Repository`, and the asymmetry with {@see ImportRepository} is the point.
 * These are log entries, not records: nothing lists them, nothing filters them, nothing trashes one.
 * Extending the base class would give every line audit columns it has no use for and — the part
 * that actually matters — would fire `plugixa_inventory_entity_created` fifty thousand times during
 * one import, which the notification bell and every other lifecycle listener would then have to
 * learn to ignore.
 *
 * So it owns its own small set of statements, and every one of them is a literal with bound
 * parameters, because `SqlLiteralTest` walks this file like any other.
 */
final class ImportRowRepository {

	/**
	 * Write one line's outcome.
	 *
	 * @param int                                                                                                                     $import_id The run.
	 * @param int                                                                                                                     $line      The line number in the user's file.
	 * @param array{action: string, entity_id: int|null, message: string, stock?: array{warehouse_id: int, quantity: float, cost: float}} $outcome   What happened.
	 */
	public function record( int $import_id, int $line, array $outcome ): void {
		global $wpdb;

		$stock = $outcome['stock'] ?? null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to write through.
		$wpdb->insert(
			Db::table( 'import_rows' ),
			array(
				'import_id'          => $import_id,
				'line_number'        => $line,
				'entity_id'          => $outcome['entity_id'] ?? null,
				'action'             => (string) $outcome['action'],
				// The column is varchar(255) and a service's refusal can name a long item. Cut it
				// rather than let MySQL truncate it in strict mode and fail the whole insert.
				'message'            => mb_substr( (string) ( $outcome['message'] ?? '' ), 0, 255 ),
				'stock_warehouse_id' => null === $stock ? null : (int) $stock['warehouse_id'],
				'stock_quantity'     => null === $stock ? 0 : (float) $stock['quantity'],
				'stock_cost'         => null === $stock ? 0 : (float) $stock['cost'],
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%f', '%f', '%s' )
		);
	}

	/**
	 * The ids this run created, for the revert.
	 *
	 * `created` only — an updated record was not brought into existence by this import and undoing
	 * it would mean having stored every previous value of every field.
	 *
	 * @param int $import_id The run.
	 *
	 * @return int[]
	 */
	public function created_ids( int $import_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT entity_id FROM %i WHERE import_id = %d AND action = %s AND entity_id IS NOT NULL ORDER BY entity_id ASC',
				Db::table( 'import_rows' ),
				$import_id,
				'created'
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The lines that went wrong, oldest first — which is the order they are in the user's file.
	 *
	 * A `reversed` ROW IS NOT A PROBLEM, and excluding it is not cosmetic. The revert records what it
	 * did on this same log, with a message and a line number of zero; without this clause the note
	 * "Opening stock reversed by stock movement #12" appears in the error report, so a run that
	 * one failed line shows a two-row table of problems — and one of them is a thing that went right.
	 * Only looking at the screen after a revert shows this.
	 *
	 * @param int $import_id The run.
	 * @param int $limit     How many to return.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function problems( int $import_id, int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT line_number, action, message FROM %i
				  WHERE import_id = %d AND message <> %s AND action <> %s
				  ORDER BY line_number ASC LIMIT %d',
				Db::table( 'import_rows' ),
				$import_id,
				'',
				'reversed',
				max( 1, min( 1000, $limit ) )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'line_number' => (int) $row['line_number'],
				'action'      => (string) $row['action'],
				'message'     => (string) $row['message'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Every problem, collapsed by message.
	 *
	 * WHAT SOMEBODY ACTUALLY ACTS ON. A hundred and eighteen lines that all say "no category called
	 * 'Power tolls'" are one fix in their spreadsheet, and a hundred and eighteen identical table
	 * rows communicate that no better than one line does — worse, in fact, because the other four
	 * distinct problems are now buried among them.
	 *
	 * THE COUNT COMES BACK AS A WHOLE PHRASE, NOT AS A NUMBER FOR THE CLIENT TO GLUE TO A NOUN.
	 * `_n()` has to see the count to pick the right plural form, and plenty of languages do not put
	 * the number in front — so "1 line" and "118 lines" is a decision only PHP can make. Returning
	 * `{ count: 1 }` and letting the client append "lines" is how a screen ends up saying "1 lines",
	 * which is precisely the reasoning `AppInterface::usage()` sets out for its own figures.
	 *
	 * @param int $import_id The run.
	 *
	 * @return array<int, array{message: string, count: int, label: string}>
	 */
	public function problem_summary( int $import_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT message, COUNT(*) AS total FROM %i
				  WHERE import_id = %d AND message <> %s AND action <> %s
				  GROUP BY message ORDER BY total DESC LIMIT 25',
				Db::table( 'import_rows' ),
				$import_id,
				'',
				'reversed'
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$count = (int) $row['total'];

				return array(
					'message' => (string) $row['message'],
					'count'   => $count,
					'label'   => sprintf(
						/* translators: %s: a number of lines in the imported file. */
						_n( '%s line', '%s lines', $count, 'plugixa-inventory' ),
						number_format_i18n( $count )
					),
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Every problem line, for the downloadable report. No cap: this is a file, not a screen.
	 *
	 * @param int $import_id The run.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_problems( int $import_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT line_number, action, message FROM %i
				  WHERE import_id = %d AND message <> %s AND action <> %s
				  ORDER BY line_number ASC',
				Db::table( 'import_rows' ),
				$import_id,
				'',
				'reversed'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The opening-stock lines waiting for a document, grouped by warehouse.
	 *
	 * `stock_warehouse_id` NOT NULL AND `stock_document_id` NULL *is* the pending set — that pair is
	 * what the composite key on this table exists for. Reading it back from the log rather than
	 * holding it in memory is what lets the flush survive between batches, and between a browser
	 * closed at thirty per cent and the run somebody resumes a day later.
	 *
	 * @param int $import_id The run.
	 * @param int $limit     Never build a document larger than this.
	 *
	 * @return array<int, array<int, array{row_id: int, item_id: int, quantity: float, cost: float}>> Keyed by warehouse id.
	 */
	public function pending_stock( int $import_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, entity_id, stock_warehouse_id, stock_quantity, stock_cost FROM %i
				  WHERE import_id = %d AND stock_warehouse_id IS NOT NULL AND stock_document_id IS NULL
				  ORDER BY stock_warehouse_id ASC, id ASC LIMIT %d',
				Db::table( 'import_rows' ),
				$import_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$grouped = array();

		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['stock_warehouse_id'] ][] = array(
				'row_id'   => (int) $row['id'],
				'item_id'  => (int) $row['entity_id'],
				'quantity' => (float) $row['stock_quantity'],
				'cost'     => (float) $row['stock_cost'],
			);
		}

		return $grouped;
	}

	/**
	 * How many opening-stock lines are still waiting.
	 *
	 * @param int $import_id The run.
	 */
	public function pending_stock_count( int $import_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE import_id = %d AND stock_warehouse_id IS NOT NULL AND stock_document_id IS NULL',
				Db::table( 'import_rows' ),
				$import_id
			)
		);

		return (int) $count;
	}

	/**
	 * Tie a set of log rows to the document their stock landed in.
	 *
	 * WRITTEN BEFORE THE DOCUMENT IS FINALIZED, not after. If finalizing throws, the rows still
	 * point at the draft, which is what lets `describe()` find an unfinalized document and tell
	 * somebody to deal with it. Stamping afterwards would leave a failed flush indistinguishable
	 * from one that never ran, and the next batch would try to post the same lines again.
	 *
	 * @param int   $import_id   The run.
	 * @param int[] $row_ids     The log rows.
	 * @param int   $document_id The stock movement.
	 */
	public function attach_document( int $import_id, array $row_ids, int $document_id ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $row_ids ) ) ) );

		if ( array() === $ids ) {
			return;
		}

		global $wpdb;

		$table = Db::table( 'import_rows' );

		/*
		 * CHUNKED INTO A FIXED TWENTY PLACEHOLDERS, padded by repeating the last id, because
		 * wordpress.org requires the statement to be a literal rather than one assembled from a
		 * count. `IdList::chunks()` is the plugin's implementation of that rule and this is the same
		 * shape; it is used through the helper so there is one definition of the chunk size.
		 */
		foreach ( IdList::chunks( $ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to write through.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET stock_document_id = %d
					  WHERE import_id = %d
					    AND id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
					$table,
					$document_id,
					$import_id,
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				)
			);
		}
	}

	/**
	 * Note that a line's opening stock could not be posted, and stop it being retried.
	 *
	 * The warehouse id is cleared so the row leaves the pending set. The record itself is untouched
	 * and stays `created`: the item exists and is correct, and calling the row an error because its
	 * stock failed would be a lie about the thing the user actually asked for.
	 *
	 * @param int    $import_id The run.
	 * @param int[]  $row_ids   The log rows.
	 * @param string $message   Why.
	 */
	public function fail_stock( int $import_id, array $row_ids, string $message ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $row_ids ) ) ) );

		if ( array() === $ids ) {
			return;
		}

		global $wpdb;

		$table = Db::table( 'import_rows' );
		$text  = mb_substr( $message, 0, 255 );

		foreach ( IdList::chunks( $ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to write through.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET stock_warehouse_id = NULL, message = %s
					  WHERE import_id = %d
					    AND id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
					$table,
					$text,
					$import_id,
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				)
			);
		}
	}

	/**
	 * The finalized opening-stock documents this run posted, newest first.
	 *
	 * Newest first because the revert walks them in reverse: the last document posted is the first
	 * one taken back out, which keeps the running balance sensible if the walk is interrupted.
	 *
	 * @param int $import_id The run.
	 *
	 * @return int[]
	 */
	public function stock_documents( int $import_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT stock_document_id FROM %i WHERE import_id = %d AND action <> %s AND stock_document_id IS NOT NULL ORDER BY stock_document_id DESC',
				Db::table( 'import_rows' ),
				$import_id,
				'reversed'
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The documents already reversed, so a retried revert does not reverse one twice.
	 *
	 * THIS IS WHY THE `reversed` ACTION EXISTS. The revert can fail part-way — a lock timeout on the
	 * third of five documents is an ordinary thing — and without a record of which originals have
	 * already been compensated, running it again would subtract the same stock a second time. The
	 * log is already "what this run did", so it is where the revert's own memory belongs; no new
	 * column, and it is visible to anybody reading the run.
	 *
	 * @param int $import_id The run.
	 *
	 * @return int[]
	 */
	public function reversed_documents( int $import_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT stock_document_id FROM %i WHERE import_id = %d AND action = %s AND stock_document_id IS NOT NULL',
				Db::table( 'import_rows' ),
				$import_id,
				'reversed'
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Record that one original document has been compensated.
	 *
	 * @param int $import_id   The run.
	 * @param int $document_id The ORIGINAL document, not the reversing one.
	 * @param int $reversal_id The reversing document, named in the message.
	 */
	public function record_reversal( int $import_id, int $document_id, int $reversal_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to write through.
		$wpdb->insert(
			Db::table( 'import_rows' ),
			array(
				'import_id'         => $import_id,
				'line_number'       => 0,
				'action'            => 'reversed',
				'message'           => sprintf(
					/* translators: %d: the id of the stock movement that reversed this import's stock. */
					__( 'Opening stock reversed by stock movement #%d.', 'plugixa-inventory' ),
					$reversal_id
				),
				'stock_document_id' => $document_id,
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Everything this run logged. Called when a run is purged.
	 *
	 * @param int $import_id The run.
	 */
	public function wipe_for_import( int $import_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to write through.
		$wpdb->delete( Db::table( 'import_rows' ), array( 'import_id' => $import_id ), array( '%d' ) );
	}
}
