<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Documents;

use PlugixaInventory\Core\Database\IdList;
use PlugixaInventory\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lines of a document — a stock movement, a transfer, an adjustment.
 *
 * WHY THIS IS IN CORE RATHER THAN COPIED INTO EACH MODULE.
 *
 * The CRM this architecture came from records what happens otherwise: two line repositories that
 * were "~135 lines each and mechanically identical apart from one word — the foreign key naming the
 * document. Not similar; the same, down to a copy-paste grammar slip that had been sitting in one of
 * them since it was written." There are three document types here, and a fourth is the obvious next
 * feature.
 *
 * WHAT IS SHARED IS THE MECHANICS, NOT THE SHAPE, and that is the one place this differs from the
 * class it was ported from. There, `COLUMNS` was fixed and un-overridable because a shared `Totals`
 * read the fields by name. Here the shapes genuinely differ: a transfer line points at TWO ledger
 * rows (one out, one in) where a movement line points at one, and pretending otherwise would mean
 * every transfer carrying a column it cannot use and losing one it needs. So the subclass declares
 * its columns and this class declares how they are walked, cast and cascaded.
 *
 * A LINE IS PURGED, NEVER TRASHED. `delete()` is overridden to `purge()` below — see its docblock.
 */
abstract class LineRepository extends Repository {

	/**
	 * The column naming the document this line belongs to — `movement_id`, `transfer_id`.
	 */
	abstract protected function parent_column(): string;

	/**
	 * The line's own columns, after the foreign key.
	 *
	 * @return string[]
	 */
	abstract protected function columns(): array;

	/**
	 * Which of those MySQL hands back as strings and every caller wants as floats.
	 *
	 * Defaults to the one column every document line has. A subclass with costs or weights on its
	 * lines adds them.
	 *
	 * @return string[]
	 */
	protected function numeric(): array {
		return array( 'quantity' );
	}

	/**
	 * Which of those are ids that may legitimately be zero-meaning-absent.
	 *
	 * @return string[]
	 */
	protected function integer(): array {
		return array( 'item_id', 'position' );
	}

	/**
	 * Rows per fetch when walking a document's lines.
	 *
	 * MATCHES `QueryBuilder::paginate()`'s OWN CEILING EXACTLY, and that is not a coincidence to be
	 * tidied away. Asking for more is not an error there — it is silently clamped with
	 * `min( 200, $limit )`, which is how a `500` written at a call site came to mean 200 while
	 * reading like a ceiling nobody would reach.
	 */
	private const BATCH = 200;

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array_merge( array( $this->parent_column() ), $this->columns() );
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'position' );
	}

	/**
	 * EVERY line of a document, in order.
	 *
	 * WHY THIS IS A LOOP AND NOT ONE `paginate()` CALL. In the class this was ported from it was a
	 * single `->paginate( 500, 0 )`, and the caller summed whatever came back — so a document with
	 * more lines than that silently totalled only the first batch. No error, no warning: a
	 * plausible-looking number that nothing invited anyone to check.
	 *
	 * IN THIS DOMAIN THE SAME BUG IS WORSE THAN A WRONG TOTAL. These lines are what `finalize()`
	 * compiles into stock changes, so a truncated fetch does not merely misreport — it ships 200 of
	 * 380 units, marks the document finalized, and leaves the other 180 unaccounted for with the
	 * paperwork saying they went.
	 *
	 * The cap in `QueryBuilder` is a deliberate safety rail for list endpoints and stays. Walking a
	 * document's lines is simply not a paginated question, so the batches are walked until one comes
	 * back short — the only way to be sure the last line was seen.
	 *
	 * @param int $document_id The document.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_document( int $document_id ): array {
		$lines  = array();
		$offset = 0;

		do {
			$batch = $this->query( 'list' )
				->where( 't.' . $this->parent_column() . ' = %d', array( $document_id ) )
				->order_by( 't.position', 'ASC' )
				->paginate( self::BATCH, $offset )
				->get();

			foreach ( $batch as $row ) {
				$lines[] = $this->cast( $row );
			}

			$offset += self::BATCH;
		} while ( count( $batch ) === self::BATCH );

		return $lines;
	}

	/**
	 * How many lines a document has, without loading them.
	 *
	 * @param int $document_id The document.
	 */
	public function count_for_document( int $document_id ): int {
		return $this->count_for_documents( array( $document_id ) )[ $document_id ] ?? 0;
	}

	/**
	 * How many lines each of several documents has, in one query.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE OTHER HALF OF THE LIST N+1. Batching the foreign-key NAMES took a fifty-row page of stock
	 * movements from 95 queries to 33 — and thirty of the thirty-three that remained were this
	 * count, run once per draft row by the per-row decorator. A list of drafts is the common case on
	 * these screens, so "only drafts pay it" was close to "every row pays it".
	 *
	 * `count_for_document()` above now goes through here, so the single-document callers keep
	 * working and there is one piece of SQL rather than two that could disagree.
	 *
	 * THE ALIAS IS `line_total`, NOT `lines`, AND THAT IS NOT A STYLE CHOICE. `LINES` is a reserved
	 * word in MySQL — it belongs to `LOAD DATA ... LINES TERMINATED BY` — so `COUNT(id) AS lines` is
	 * a syntax error. `$wpdb->get_results()` returns an empty array on a failed query rather than
	 * throwing, so the first version of this shipped a count of zero for every draft on the screen
	 * and looked exactly like a document with no lines on it.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int[] $document_ids The documents.
	 *
	 * @return array<int, int> Document id => line count. Documents with no lines are absent.
	 */
	public function count_for_documents( array $document_ids ): array {
		global $wpdb;

		$counts = array();

		// One literal query per batch of twenty. {@see \PlugixaInventory\Core\Database\IdList}.
		foreach ( IdList::chunks( $document_ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT %i AS parent, COUNT(id) AS line_total FROM %i
					  WHERE deleted_at IS NULL
					    AND %i IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)
					  GROUP BY %i',
					$this->parent_column(),
					$this->table_name(),
					$this->parent_column(),
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
					$this->parent_column()
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$counts[ (int) $row['parent'] ] = (int) $row['line_total'];
			}
		}

		return $counts;
	}

	/**
	 * The next position on a document, so a new line lands at the bottom.
	 *
	 * @param int $document_id The document.
	 */
	public function next_position( int $document_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$highest = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(position) FROM %i WHERE %i = %d AND deleted_at IS NULL',
				$this->table_name(),
				$this->parent_column(),
				$document_id
			)
		);

		return (int) $highest + 1;
	}

	/**
	 * Write a value onto one line without going through `update()`.
	 *
	 * FOR THE LEDGER IDS `finalize()` WRITES BACK, and only that. `Repository::update()` fires
	 * `plugixa_inventory_entity_field_changed` per changed column, which is the seam a notification
	 * or workflow module hangs triggers off — and a forty-line document would raise forty of them
	 * for a column the user never touched, immediately after the one event that actually matters.
	 *
	 * @param int                  $id     Line id.
	 * @param array<string, mixed> $values Column => value. Restricted to this line's own columns.
	 */
	public function set_columns( int $id, array $values ): bool {
		global $wpdb;

		$values = array_intersect_key( $values, array_flip( $this->columns() ) );

		if ( array() === $values ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$done = $wpdb->update( $this->table_name(), $values, array( 'id' => $id ) );

		return false !== $done;
	}

	/**
	 * A line is destroyed, not trashed.
	 *
	 * THE UNIQUE-KEY RULE IN REVERSE. Elsewhere in this plugin soft delete is what a row gets and a
	 * unique key is what it cannot have. A document line has neither reason: it is not a record
	 * somebody looks for in a trash, and a trashed line on a draft would still be fetched by
	 * anything that forgot the `deleted_at` clause and would then be finalized into a stock change
	 * nobody could see on the document.
	 *
	 * Removing a line from a draft means the line is gone. The document is still there, the item is
	 * still there, and neither has lost anything.
	 *
	 * @param int $id Line id.
	 */
	public function delete( int $id ): bool {
		return $this->purge( $id );
	}

	/**
	 * Destroy every line on a document. The cascade when the document is purged.
	 *
	 * Without it, a hard delete of the document leaves its lines pointing at an id that no longer
	 * exists — invisible, unreachable and counted by nothing. Everything would look right, which is
	 * what makes it worth writing down.
	 *
	 * @param int $document_id The document.
	 */
	public function purge_for_document( int $document_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$wpdb->delete( $this->table_name(), array( $this->parent_column() => $document_id ), array( '%d' ) );
	}

	/**
	 * Cast the columns MySQL hands back as strings.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row['id'] = (int) $row['id'];

		if ( isset( $row[ $this->parent_column() ] ) ) {
			$row[ $this->parent_column() ] = (int) $row[ $this->parent_column() ];
		}

		foreach ( $this->integer() as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
			}
		}

		foreach ( $this->numeric() as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (float) $row[ $column ];
			}
		}

		return $row;
	}
}
