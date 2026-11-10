<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

use PlugixaInventory\Core\Database\Schema as Db;
use PlugixaInventory\Core\Entities\EntityRegistry;
use PlugixaInventory\Core\Entities\RecordWriter;
use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Modules\Import\Quiet;
use PlugixaInventory\Modules\Import\Repositories\ImportRepository;
use PlugixaInventory\Modules\Import\Repositories\ImportRowRepository;
use PlugixaInventory\Modules\Stock\Repositories\ItemStockRepository;
use PlugixaInventory\Modules\StockMovements\Services\StockMovementService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Undoing an import.
 *
 * THE THING THAT MAKES THIS HARD IS THAT AN IMPORTED ITEM MAY BE IMPOSSIBLE TO DELETE. The Stock
 * module answers the delete guard with two refusals for an item: one for stock still on hand, and
 * one for stock HISTORY — and the second never clears, because the ledger is append-only. So the
 * moment an import posts an opening balance, every item it created becomes permanently untrashable,
 * and the obvious revert ("trash what it created") would remove nothing at all while reporting
 * success. That is the worst possible answer, because the user stops looking.
 *
 * A FINALIZED DOCUMENT CANNOT BE UNDONE EITHER. There is no transition out of `finalized` and the
 * immutability guard refuses to delete one. The plugin's own answer to this is written in that
 * guard: correcting a finalized document means creating a reversing one, which is what every stock
 * system does and what the audit trail is for.
 *
 * So a revert does three things, in this order, and says all three plainly before it is asked to:
 *
 *  1. **Checks that it still can.** If the stock this import added has since been used, it refuses
 *     and changes nothing. The stock has moved on and the import is part of the business's history
 *     now; quietly taking a shortfall out of somebody's warehouse would be worse than saying no.
 *  2. **Takes the stock back out with a reversing stock movement**, one per document it posted, with the
 *     same lines at the same costs.
 *  3. **Trashes what it can and unpublishes what it cannot** — using the plugin's own words for that
 *     case, because "Unpublish it instead" is literally what the delete guard tells a user who tries.
 *
 * IT IS SAFE TO RUN TWICE. A revert can fail part-way — a lock timeout on the third of five
 * documents is an ordinary thing — so every reversal is recorded on the run's own row log as it
 * happens, and a retry skips whatever is already there. Without that, running it again would
 * subtract the same stock a second time.
 *
 * UPDATES ARE NOT UNDONE, AND THE SCREEN SAYS SO. Reverting an update would mean having stored every
 * previous value of every field of every row, and a revert that restored some and not others is
 * worse than one that admits its limits.
 */
final class Reverter {

	/**
	 * Reversing documents written per call, and records touched per call.
	 *
	 * A REVERT IS AS HEAVY AS THE IMPORT IT UNDOES, and doing it in one request was wrong for
	 * exactly the same reason. Measured against a 1,500-line import across three warehouses, a
	 * single-request revert took twenty-two seconds: sixteen finalizing fifteen hundred reversal
	 * lines, and six updating fifteen hundred records. Both halves are now bounded and the caller
	 * comes back, which is the shape the run itself already has.
	 */
	private const DOCUMENTS_PER_CALL = 1;

	/** Records trashed or unpublished per call. */
	private const RECORDS_PER_CALL = 200;

	public function __construct(
		private readonly ImportRepository $imports,
		private readonly ImportRowRepository $rows,
		private readonly RecordWriter $writer,
		private readonly EntityRegistry $entities,
		private readonly StockMovementService $movements,
		private readonly ItemStockRepository $stock
	) {}

	/**
	 * Undo a run.
	 *
	 * @param int $id The run.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function revert( int $id ): array|WP_Error {
		$import = $this->imports->find( $id );

		if ( null === $import ) {
			return new WP_Error(
				'plugixa_inventory_not_found',
				__( 'That import does not exist.', 'plugixa-inventory' ),
				array( 'status' => 404 )
			);
		}

		if ( null !== $import['reverted_at'] ) {
			return new WP_Error(
				'plugixa_inventory_already_reverted',
				__( 'That import has already been reverted.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$entity_type = (string) $import['entity_type'];

		if ( ! Capabilities::user_can( 'delete', $entity_type ) ) {
			return new WP_Error(
				'plugixa_inventory_forbidden',
				__( 'You do not have permission to remove those.', 'plugixa-inventory' ),
				array( 'status' => 403 )
			);
		}

		$created = $this->rows->created_ids( $id );

		/*
		 * REFUSE RATHER THAN CLAIM SUCCESS WITH NOTHING TO SHOW.
		 *
		 * The run's counters and the per-row log are written separately, so they can disagree: a
		 * failed migration that left the row log missing produces a run saying "created 3" and a log
		 * saying nothing. Marking that reverted and reporting "removed: 0" tells the user their
		 * three thousand records are gone when they are all still there — the worst possible answer,
		 * because they stop looking. The sibling plugin this ports hit exactly that, from a CREATE
		 * TABLE that failed silently on a reserved word.
		 */
		if ( array() === $created && (int) $import['created_count'] > 0 ) {
			return new WP_Error(
				'plugixa_inventory_revert_unavailable',
				__( 'This import cannot be reverted: the record of what it created is missing. The imported records are still there and have not been touched.', 'plugixa-inventory' ),
				array( 'status' => 409 )
			);
		}

		$documents = $this->postings( $id );

		/*
		 * THE STOCK COMES OUT FIRST, ALL OF IT, BEFORE ANY RECORD IS TOUCHED. Trashing an item
		 * whose stock has not been reversed yet would hit the delete guard for a reason that is
		 * about to stop being true, and unpublish it instead — permanently, for no reason.
		 */
		if ( array() !== $documents ) {
			$blocked = $this->blocked_by( $documents );

			if ( array() !== $blocked ) {
				return new WP_Error(
					'plugixa_inventory_import_revert_blocked',
					sprintf(
						/* translators: %s: a comma-separated list of item names. */
						__( 'This import cannot be reverted: some of the stock it added has already been used (%s). Nothing has been changed.', 'plugixa-inventory' ),
						implode( ', ', $blocked )
					),
					array( 'status' => 409 )
				);
			}

			$reversed = $this->reverse_stock( $id, array_slice( $documents, 0, self::DOCUMENTS_PER_CALL ) );

			if ( $reversed instanceof WP_Error ) {
				return $reversed;
			}

			return $this->progress( $id, $entity_type, false );
		}

		/*
		 * THE RECORD PASS RESUMES FROM A STORED OFFSET, for the same reason the import does: fifteen
		 * hundred service calls is not one request's work. `created_ids()` is DISTINCT and ordered by
		 * id, so the slice is stable between calls — an unstable order here would skip records and
		 * repeat others, and the run would report itself finished having missed some.
		 */
		$offset = (int) $import['revert_offset'];
		$slice  = array_slice( $created, $offset, self::RECORDS_PER_CALL );

		foreach ( $slice as $record ) {
			if ( ! $this->writer->delete( $entity_type, $record ) ) {
				$this->unpublish( $entity_type, $record );
			}
		}

		$offset += count( $slice );

		$this->imports->update( $id, array( 'revert_offset' => $offset ) );

		$done = $offset >= count( $created );

		if ( $done ) {
			$this->imports->update(
				$id,
				array(
					'status'      => 'reverted',
					'reverted_at' => current_time( 'mysql', true ),
				)
			);
		}

		return $this->progress( $id, $entity_type, $done );
	}

	/**
	 * How far the revert has got, counted from what is actually on disk.
	 *
	 * THE TOTALS ARE READ BACK RATHER THAN ACCUMULATED, because a revert now spans many calls and
	 * carrying running totals between them would mean two more columns and a second thing that can
	 * disagree with the records. Asking the table what state its rows are in cannot drift from the
	 * truth, and it is one query.
	 *
	 * @param int    $import_id   The run.
	 * @param string $entity_type Entity slug.
	 * @param bool   $done        Whether there is nothing left to do.
	 *
	 * @return array<string, mixed>
	 */
	private function progress( int $import_id, string $entity_type, bool $done ): array {
		$counts = $this->outcome_counts( $import_id, $entity_type );

		return array(
			'reverted'           => $done,
			'done'               => $done,
			'removed'            => $counts['removed'],
			'unpublished'        => $counts['unpublished'],
			'reversed_documents' => count( $this->rows->reversed_documents( $import_id ) ),
			'remaining'          => count( $this->postings( $import_id ) ),
		);
	}

	/**
	 * How many of this run's records are now trashed, and how many unpublished.
	 *
	 * @param int    $import_id   The run.
	 * @param string $entity_type Entity slug.
	 *
	 * @return array{removed: int, unpublished: int}
	 */
	private function outcome_counts( int $import_id, string $entity_type ): array {
		$descriptor = $this->entities->get( $entity_type );

		$base = (string) ( $descriptor['api_base'] ?? '' );

		if ( '' === $base ) {
			return array( 'removed' => 0, 'unpublished' => 0 );
		}

		global $wpdb;

		$entity = Db::table( $base );
		$log    = Db::table( 'import_rows' );

		$options = (array) ( $descriptor['fields']['status']['options'] ?? array() );

		// Two literal statements rather than one built conditionally: an entity with no status
		// column would make `e.status` a SQL error, not an empty result.
		if ( in_array( 'unpublished', array_map( 'strval', $options ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables, no object cache to read from.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT
						SUM(CASE WHEN e.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS removed,
						SUM(CASE WHEN e.deleted_at IS NULL AND e.status = %s THEN 1 ELSE 0 END) AS unpublished
					   FROM %i r
					   JOIN %i e ON e.id = r.entity_id
					  WHERE r.import_id = %d AND r.action = %s',
					'unpublished',
					$log,
					$entity,
					$import_id,
					'created'
				),
				ARRAY_A
			);

			return array(
				'removed'     => (int) ( $row['removed'] ?? 0 ),
				'unpublished' => (int) ( $row['unpublished'] ?? 0 ),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables, no object cache to read from.
		$removed = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i r JOIN %i e ON e.id = r.entity_id
				  WHERE r.import_id = %d AND r.action = %s AND e.deleted_at IS NOT NULL',
				$log,
				$entity,
				$import_id,
				'created'
			)
		);

		return array( 'removed' => (int) $removed, 'unpublished' => 0 );
	}

	/**
	 * The finalized opening-stock documents still to be taken back out, with their lines.
	 *
	 * A DRAFT IS TRASHED, NOT REVERSED. It holds no stock — that is what being a draft means — so
	 * writing a subtraction against it would remove quantities nobody ever added.
	 *
	 * @param int $import_id The run.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function postings( int $import_id ): array {
		$already = $this->rows->reversed_documents( $import_id );

		$out = array();

		foreach ( $this->rows->stock_documents( $import_id ) as $document_id ) {
			if ( in_array( $document_id, $already, true ) ) {
				continue;
			}

			$document = $this->movements->get_with_lines( $document_id );

			if ( null === $document ) {
				continue;
			}

			if ( 'finalized' !== (string) $document['status'] ) {
				$this->movements->delete( $document_id );

				continue;
			}

			$out[] = $document;
		}

		return $out;
	}

	/**
	 * Items whose stock has moved on since the import put it there.
	 *
	 * ADVISORY, AND CHECKED BEFORE ANYTHING IS WRITTEN. It cannot be atomic — the plugin does not
	 * use database transactions, deliberately, because it does not own the connection — so a sale
	 * landing between this check and the reversing document would still make one line fail. What it
	 * turns a half-done revert into is a race rather than the normal case, and the reversing
	 * document's own guarded update is the authoritative check that catches the rest.
	 *
	 * @param array<int, array<string, mixed>> $documents The postings.
	 *
	 * @return string[] Item names, for the message.
	 */
	private function blocked_by( array $documents ): array {
		$needed = array();

		foreach ( $documents as $document ) {
			$warehouse = (int) $document['warehouse_id'];

			foreach ( (array) $document['lines'] as $line ) {
				$item = (int) $line['item_id'];

				$needed[ $item . ':' . $warehouse ] = ( $needed[ $item . ':' . $warehouse ] ?? 0.0 ) + (float) $line['quantity'];
			}
		}

		$short = array();

		foreach ( $needed as $pair => $quantity ) {
			[ $item, $warehouse ] = array_map( 'intval', explode( ':', (string) $pair ) );

			if ( $this->stock->quantity( $item, $warehouse ) + 0.0001 < $quantity ) {
				$short[] = $item;
			}
		}

		if ( array() === $short ) {
			return array();
		}

		/** This filter is how the ledger renders a record's name without knowing what one is. */
		$names = (array) apply_filters( 'plugixa_inventory_record_names', array(), 'item', $short );

		return array_map(
			static fn( int $id ): string => (string) ( $names[ $id ] ?? '#' . $id ),
			array_slice( $short, 0, 10 )
		);
	}

	/**
	 * Write one reversing document per posting, recording each as it lands.
	 *
	 * @param int                              $import_id The run.
	 * @param array<int, array<string, mixed>> $documents The postings.
	 *
	 * @return int|WP_Error How many were reversed.
	 */
	private function reverse_stock( int $import_id, array $documents ): int|WP_Error {
		$done = 0;

		foreach ( $documents as $document ) {
			$lines = array();

			foreach ( (array) $document['lines'] as $line ) {
				$lines[] = array(
					'item_id'       => (int) $line['item_id'],
					'quantity'      => (float) $line['quantity'],
					// The same cost it went in at, so the reversal removes exactly the value it
					// added rather than revaluing what is left.
					'cost_per_unit' => (float) $line['cost_per_unit'],
				);
			}

			if ( array() === $lines ) {
				continue;
			}

			// A revert writes one reversing document per document the import posted, so it rings the
			// bell exactly as many times as the import would have. Silenced for the same reason —
			// see {@see Quiet}. The screen reports what the revert did, in full.
			$id = Quiet::post(
				fn(): int|WP_Error => $this->movements->create(
					array(
						'warehouse_id'  => (int) $document['warehouse_id'],
						/*
						 * THE MIRROR OF WHAT THE IMPORT POSTED. `OpeningStock` writes `add_stock`;
						 * this writes `remove_stock`, at the same costs, so the pair nets to nothing
						 * in the ledger and in the cost layers alike.
						 *
						 * IT IS NOT `check_out`, WHICH IS THE WHOLE REASON `remove_stock` HAD TO
						 * LEARN ABOUT ASSETS. A check-out demands a recipient — the person who has
						 * the stock — and reversing an import that should never have happened has
						 * no such person: nothing left the building. Inventing one to satisfy a
						 * required field would write a false custody record into an append-only
						 * ledger, which is worse than any packaging problem it would solve.
						 */
						'movement_type' => 'remove_stock',
						'movement_date' => current_time( 'Y-m-d' ) . ' 00:00:00',
						/*
						 * A stock movement has no `reason` column; it stamps `details` onto every
						 * ledger row it writes. See `OpeningStock::details()` for the same note.
						 */
						'details'       => sprintf(
							/* translators: 1: an import run id, 2: a document reference such as SM-2026-0043. */
							__( 'Reversing import #%1$d opening balance (%2$s)', 'plugixa-inventory' ),
							$import_id,
							(string) ( $document['reference'] ?? '' )
						),
						'lines'         => $lines,
					)
				)
			);

			if ( $id instanceof WP_Error ) {
				return $this->partial( $id, $done );
			}

			$posted = Quiet::post( fn(): array|WP_Error => $this->movements->finalize( $id ) );

			if ( $posted instanceof WP_Error ) {
				// The draft is left behind on purpose: it is visible, it holds no stock, and it is
				// the evidence of where the revert stopped.
				return $this->partial( $posted, $done );
			}

			// RECORDED IMMEDIATELY, not at the end of the loop. This is what makes a retry safe
			// after a failure two documents later.
			$this->rows->record_reversal( $import_id, (int) $document['id'], $id );

			++$done;
		}

		return $done;
	}

	/**
	 * Stop, and say how far it got.
	 *
	 * NOT MARKED REVERTED. A run that is half undone must keep its Revert button, because running it
	 * again is exactly the right next move once whatever blocked it has passed.
	 *
	 * @param WP_Error $error What went wrong.
	 * @param int      $done  How many documents were reversed first.
	 */
	private function partial( WP_Error $error, int $done ): WP_Error {
		return new WP_Error(
			'plugixa_inventory_import_revert_partial',
			sprintf(
				/* translators: 1: a number of stock documents, 2: the reason the next one failed. */
				__( 'The revert stopped after taking back %1$d stock document(s): %2$s. Nothing else has been changed, and reverting again will carry on from there.', 'plugixa-inventory' ),
				$done,
				$error->get_error_message()
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * Unpublish a record the delete guard refused.
	 *
	 * ONLY WHERE THE ENTITY ITSELF SAYS IT HAS THAT STATE. The descriptor is read rather than a
	 * per-entity table being kept here, so this class never learns which kinds of record have a
	 * status — an entity that has no `unpublished` option simply is not unpublished, and the count
	 * reported to the user is the truth either way.
	 *
	 * @param string $entity_type Entity slug.
	 * @param int    $id          The record.
	 */
	private function unpublish( string $entity_type, int $id ): bool {
		$descriptor = $this->entities->get( $entity_type );

		$options = (array) ( $descriptor['fields']['status']['options'] ?? array() );

		if ( ! in_array( 'unpublished', array_map( 'strval', $options ), true ) ) {
			return false;
		}

		return true === $this->writer->update( $entity_type, $id, array( 'status' => 'unpublished' ) );
	}
}
