<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Documents\Immutability;
use PlugixaInventory\Core\Documents\LineRepository;
use PlugixaInventory\Core\Documents\Numbering;
use PlugixaInventory\Core\Documents\StatusMachine;
use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Core\WallClock;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A document that moves stock when it is finalized.
 *
 * Three of them exist — stock movements, transfers, adjustments — and roughly four fifths of each
 * would otherwise be identical: the same draft lifecycle, the same line editing, the same
 * immutability rules, the same finalize sequence, the same delete guard.
 *
 * WHAT A SUBCLASS ACTUALLY OWNS is five methods, and only two of them are interesting:
 *
 *   `entity_type()`            its slug
 *   `prefix()`                 `SM-`, `TR-`, `ADJ-`
 *   `machine()`                its status table
 *   `validate_for_finalize()`  ITS OWN RULES — item types, a required recipient, from != to
 *   `compile()`                HOW IT BECOMES STOCK LINES — where a transfer emits two per line
 *
 * Everything else is here, written once.
 *
 * WHY THIS LIVES IN THE STOCK MODULE AND NOT IN `Core\Documents`, where the rest of the document
 * furniture does. Because it names `StockEngine`, and Core may not name a module — a rule the CRM
 * this architecture came from enforces with a test, because on a build where that module is absent
 * a Core file referencing it is a fatal error rather than a design smell.
 *
 * The class is also honestly a Stock concept: it is not "a document", it is "a document that moves
 * stock". `Immutability`, `StatusMachine`, `Numbering` and `LineRepository` know nothing about
 * stock and stay in Core, where a future document type that moves nothing can still use them.
 *
 * THE ENGINE IS A CONSTRUCTOR DEPENDENCY, NOT AN ACTION, for three reasons: `apply()` returns
 * transaction ids the caller must persist, `apply()` must be able to REFUSE and abort the finalize,
 * and it must run synchronously inside the engine's lock. No `do_action` can do any of the three.
 */
abstract class DocumentService {

	public function __construct(
		protected readonly Repository $documents,
		protected readonly LineRepository $lines,
		protected readonly StockEngine $engine,
		protected readonly ItemRepository $items
	) {}

	/** The entity slug — `stock_movement`, `transfer`, `adjustment`. */
	abstract protected function entity_type(): string;

	/** The reference prefix, including its separator. */
	abstract protected function prefix(): string;

	/** Which statuses this document may move between. */
	abstract protected function machine(): StatusMachine;

	/**
	 * This document type's own rules, checked before anything is applied.
	 *
	 * Runs on the full line set, so it can report every offending line at once rather than dying on
	 * the first — which is what turns "fix it, submit, fix it, submit" into one round trip.
	 *
	 * @param array<string, mixed>            $document The row.
	 * @param array<int, array<string,mixed>> $lines    Its lines.
	 */
	abstract protected function validate_for_finalize( array $document, array $lines ): true|WP_Error;

	/**
	 * Turn this document into the stock changes it represents.
	 *
	 * THE ONLY PLACE A DOCUMENT TYPE'S SHAPE MEETS THE ENGINE'S. A movement emits one `StockLine`
	 * per document line; an adjustment emits one with the sign its type implies; a transfer emits
	 * TWO, and nothing else in the system needs to know that.
	 *
	 * @param array<string, mixed>            $document The row.
	 * @param array<int, array<string,mixed>> $lines    Its lines.
	 * @param string                          $batch_id The batch id to stamp on all of them.
	 */
	abstract protected function compile( array $document, array $lines, string $batch_id ): StockBatch;

	/**
	 * One document, cast.
	 *
	 * @param int $id Document id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->documents->find( $id );

		if ( null === $row ) {
			return null;
		}

		return method_exists( $this->documents, 'cast' ) ? $this->documents->cast( $row ) : $row;
	}

	/**
	 * A document with its lines, which is what a detail page and an edit form both want.
	 *
	 * THE LINES COME BACK NAMED. The line editor fills in each item's name, SKU and type from the
	 * picker when a line is added, and until this did the same, every document that was saved and
	 * opened again listed its lines as "#18" with an empty SKU column — a finalized document could no
	 * longer say what it had moved. `DocumentLineNamesTest` pins it on every route that returns one.
	 *
	 * @param int $id Document id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_with_lines( int $id ): ?array {
		$document = $this->get( $id );

		if ( null === $document ) {
			return null;
		}

		$document['lines'] = $this->named_lines( $this->lines->for_document( $id ) );

		return $document;
	}

	/**
	 * Add what a reader needs to recognise each line's item: `item_name`, `item_sku`, `item_type`
	 * and `unit_code` — the four keys the line editor fills in from the picker.
	 *
	 * AN ITEM THAT CANNOT BE FOUND GETS NULLS, NOT ABSENT KEYS, the convention
	 * `RestController::resolve_names()` set: the client's "#18" fallback reads null correctly, and
	 * the key set does not change from one line to the next.
	 *
	 * UNIT CODES GO THROUGH THE NAMES FILTER, because units belong to a premium module a free file may
	 * not name. `UnitRepository` answers it with the code rather than the name, which is what a
	 * quantity column wants; on the free build nobody answers and the code is null.
	 *
	 * @param array<int, array<string, mixed>> $lines Lines carrying `item_id`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function named_lines( array $lines ): array {
		if ( array() === $lines ) {
			return $lines;
		}

		$labels   = $this->items->labels( array_column( $lines, 'item_id' ) );
		$unit_ids = array_values( array_filter( array_map( 'intval', array_column( $lines, 'unit_id' ) ) ) );
		$units    = array() === $unit_ids
			? array()
			: (array) apply_filters( 'plugixa_inventory_record_names', array(), 'unit', $unit_ids );

		foreach ( $lines as $index => $line ) {
			$label = $labels[ (int) ( $line['item_id'] ?? 0 ) ] ?? null;

			$unit  = (int) ( $line['unit_id'] ?? 0 );

			$lines[ $index ]['item_name'] = $label['name'] ?? null;
			$lines[ $index ]['item_sku']  = $label['sku'] ?? null;
			$lines[ $index ]['item_type'] = $label['item_type'] ?? null;
			$lines[ $index ]['unit_code'] = $unit > 0 ? ( $units[ $unit ] ?? null ) : null;
		}

		return $lines;
	}

	/**
	 * The same refusal, with the items in its `lines` named.
	 *
	 * "There is not enough stock" used to list "#18 — available 0, requested 5": the engine reports
	 * a shortage by item id, which is all it should know, and nothing between it and the screen
	 * added the name. Every other refusal that carries `lines` — a wrong item type, an item that no
	 * longer exists, a zero quantity — goes through here too, so the form can name what to fix.
	 *
	 * NAME AND SKU, NOT THE TYPE. The form prints a refusal line's `item_type` beside its name, and
	 * only the wrong-type refusal is ABOUT the type — it sets that key itself. Adding it everywhere
	 * put "(consumable)" after every item in a stock shortage, where it answers nothing.
	 *
	 * A NEW `WP_Error` RATHER THAN `add_data()`, because `add_data()` keeps the previous data and the
	 * REST response then prints both, the unnamed lines as `additional_data`. Each refusal that
	 * reaches this carries exactly one code, so nothing else is lost by rebuilding it.
	 *
	 * @param WP_Error $error What validation, the preflight or the engine refused with.
	 */
	private function with_named_lines( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) || ! is_array( $data['lines'] ?? null ) || array() === $data['lines'] ) {
			return $error;
		}

		$labels = $this->items->labels( array_column( $data['lines'], 'item_id' ) );

		foreach ( $data['lines'] as $index => $line ) {
			$label = $labels[ (int) ( $line['item_id'] ?? 0 ) ] ?? null;

			$data['lines'][ $index ]['item_name'] = $label['name'] ?? null;
			$data['lines'][ $index ]['item_sku']  = $label['sku'] ?? null;
		}

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Move a document to finalized, applying every stock change it describes.
	 *
	 * THE SEQUENCE IS THE POINT, and each step is here because skipping it produces a specific bug:
	 *
	 *   1. exists          — a 404 rather than a fatal on a null row.
	 *   2. may this user   — the capability check, on the RECORD, so the premium Roles module can
	 *                        answer per-document rather than per-type.
	 *   3. transition legal — finalizing twice would apply the stock twice. This is the only thing
	 *                        stopping a double-clicked button from doubling a delivery.
	 *   4. lines exist     — a document with no lines finalizes to nothing and looks done.
	 *   5. type's own rules — item types, recipient, from != to.
	 *   6. compile         — into the engine's vocabulary.
	 *   7. preflight       — advisory, so the user sees every short line at once.
	 *   8. apply           — authoritative. ALLOWED TO DISAGREE with step 7: that disagreement is
	 *                        the race being caught, and it has its own error code.
	 *   9. write back      — transaction ids and costs onto the lines.
	 *  10. mark finalized  — last, so a failure anywhere above leaves a draft rather than a
	 *                        finalized document with no stock behind it.
	 *
	 * @param int $id Document id.
	 *
	 * @return array<string, mixed>|WP_Error The finalized document with its lines.
	 */
	final public function finalize( int $id ): array|WP_Error {
		$document = $this->get( $id );

		if ( null === $document ) {
			return $this->not_found();
		}

		if ( ! Capabilities::user_can( 'finalize', $this->entity_type(), $document ) ) {
			return new WP_Error(
				'plugixa_inventory_forbidden',
				__( 'You are not allowed to finalize this.', 'plugixa-inventory' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! $this->machine()->allows( (string) $document['status'], 'finalized' ) ) {
			return new WP_Error(
				'plugixa_inventory_invalid_transition',
				__( 'This document has already been finalized.', 'plugixa-inventory' ),
				array( 'status' => 409, 'status_now' => $document['status'] )
			);
		}

		$lines = $this->lines->for_document( $id );

		if ( array() === $lines ) {
			return new WP_Error(
				'plugixa_inventory_document_empty',
				__( 'A document with no lines cannot be finalized.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		$valid = $this->validate_for_finalize( $document, $lines );

		if ( $valid instanceof WP_Error ) {
			return $this->with_named_lines( $valid );
		}

		$batch = $this->compile( $document, $lines, wp_generate_uuid4() );

		$fits = $this->engine->preflight( $batch );

		if ( $fits instanceof WP_Error ) {
			return $this->with_named_lines( $fits );
		}

		$applied = $this->engine->apply( $batch );

		if ( $applied instanceof WP_Error ) {
			// Nothing changed: the engine compensates its own partial work before returning.
			return $this->with_named_lines( $applied );
		}

		$this->write_back( $applied );

		$this->documents->update(
			$id,
			array(
				'status'         => 'finalized',
				'finalized_at'   => current_time( 'mysql', true ),
				'finalized_by'   => get_current_user_id(),
				'batch_id'       => $batch->batch_id,
				'line_count'     => count( $lines ),
				'total_quantity' => $this->sum_quantity( $applied ),
				'total_cost'     => $this->sum_cost( $applied ),
				'reference'      => $this->reference_for( $id, $document ),
			)
		);

		/**
		 * Fires when a document has moved stock.
		 *
		 * The seam a notifications module, a timeline or a webhook hangs off. It fires AFTER the
		 * stock is on disk and the document is marked, so a listener that reads either sees the
		 * finished state.
		 *
		 * @param string                          $entity   Entity slug.
		 * @param int                             $id       Document id.
		 * @param array<string, mixed>            $document The document as it was BEFORE finalizing.
		 * @param array<int, array<string,mixed>> $applied  One entry per stock line.
		 */
		do_action( 'plugixa_inventory_document_finalized', $this->entity_type(), $id, $document, $applied );

		return $this->get_with_lines( $id ) ?? array();
	}

	/**
	 * Trash a document, unless it has been finalized.
	 *
	 * @param int $id Document id.
	 */
	public function delete( int $id ): bool|WP_Error {
		$document = $this->get( $id );

		if ( null === $document ) {
			return $this->not_found();
		}

		$guard = Immutability::guard_delete( $this->entity_type(), $document );

		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		return $this->documents->delete( $id );
	}

	/**
	 * Destroy a document and its lines.
	 *
	 * The lines go first. A hard delete of the document alone leaves them pointing at an id that no
	 * longer exists — see `LineRepository::purge_for_document()`.
	 *
	 * @param int $id Document id.
	 */
	public function purge( int $id ): bool|WP_Error {
		$document = $this->documents->find( $id, true );

		if ( null === $document ) {
			return $this->not_found();
		}

		$guard = Immutability::guard_delete( $this->entity_type(), $document );

		if ( $guard instanceof WP_Error ) {
			return $guard;
		}

		$this->lines->purge_for_document( $id );

		return $this->documents->purge( $id );
	}

	/**
	 * The 404 this document type answers with.
	 */
	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_inventory_not_found',
			__( 'That document does not exist.', 'plugixa-inventory' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Refuse a write to a finalized document.
	 *
	 * @param int                  $id      Document id.
	 * @param array<string, mixed> $changes What the caller wants to write.
	 */
	protected function guard_edit( int $id, array $changes ): true|WP_Error {
		$document = $this->get( $id );

		if ( null === $document ) {
			return $this->not_found();
		}

		return Immutability::guard_edit( $this->entity_type(), $document, $changes );
	}

	/**
	 * Refuse a line change on a finalized document.
	 *
	 * @param int $id Document id.
	 */
	protected function guard_lines( int $id ): true|WP_Error {
		$document = $this->get( $id );

		if ( null === $document ) {
			return $this->not_found();
		}

		return Immutability::guard_lines( $this->entity_type(), $document );
	}

	/**
	 * Put the ledger row ids and costs back onto the document lines.
	 *
	 * A transfer line produced two ledger rows, so the mapping is by `source_line_id` and by the
	 * transaction TYPE — which is why the base does the grouping and the subclass says which column
	 * each half lands in.
	 *
	 * @param array<int, array<string, mixed>> $applied What the engine returned.
	 */
	protected function write_back( array $applied ): void {
		foreach ( $applied as $entry ) {
			$line_id = (int) ( $entry['source_line_id'] ?? 0 );

			if ( $line_id <= 0 ) {
				continue;
			}

			$this->lines->set_columns( $line_id, $this->write_back_columns( $entry ) );
		}
	}

	/**
	 * Which columns one applied stock line writes onto its document line.
	 *
	 * The default suits a document whose line produced exactly one ledger row. Transfers override
	 * it, because theirs produced two and they go in different columns.
	 *
	 * @param array<string, mixed> $entry One entry from the engine's result.
	 *
	 * @return array<string, mixed>
	 */
	protected function write_back_columns( array $entry ): array {
		return array(
			'transaction_id' => (int) $entry['transaction_id'],
			'cost_per_unit'  => (float) $entry['cost_per_unit'],
			'total_cost'     => (float) $entry['total_cost'],
		);
	}

	/**
	 * What the document's total cost comes to.
	 *
	 * @param array<int, array<string, mixed>> $applied What the engine returned.
	 */
	protected function sum_cost( array $applied ): float {
		$total = 0.0;

		foreach ( $applied as $entry ) {
			// A transfer's IN half reports the same cost as its OUT half so the two ledger rows
			// agree; counting both would double the document's cost. Only the outward side counts.
			if ( 'transfer_in' === ( $entry['transaction_type'] ?? '' ) ) {
				continue;
			}

			$total += (float) $entry['total_cost'];
		}

		return round( abs( $total ), 4 );
	}

	/**
	 * How much the document moved, as an amount.
	 *
	 * `total_quantity` WAS DECLARED A CACHE "WRITTEN AT FINALIZE" AND NEVER WRITTEN, so every
	 * finalized movement, transfer and adjustment said 0 — `DocumentTotalsTest`. Counted the way
	 * `sum_cost()` counts: from what the engine applied, one ledger row per document line, and as a
	 * magnitude — a removal of four moved four, not minus four.
	 *
	 * @param array<int, array<string, mixed>> $applied What the engine returned.
	 */
	protected function sum_quantity( array $applied ): float {
		$total = 0.0;

		foreach ( $applied as $entry ) {
			// A transfer's IN half is the same line arriving; counting it as well would double the move.
			if ( 'transfer_in' === ( $entry['transaction_type'] ?? '' ) ) {
				continue;
			}

			$total += abs( (float) ( $entry['quantity_change'] ?? 0 ) );
		}

		return round( $total, 4 );
	}

	/**
	 * The reference to stamp on finalize, keeping one the user typed.
	 *
	 * A reference is generated from the id rather than a counter, so it is unique by construction
	 * and cannot collide with a trashed document's — which a counter derived from a live-row count
	 * absolutely would.
	 *
	 * @param int                  $id       Document id.
	 * @param array<string, mixed> $document The row.
	 */
	protected function reference_for( int $id, array $document ): string {
		$existing = trim( (string) ( $document['reference'] ?? '' ) );

		if ( '' !== $existing ) {
			return $existing;
		}

		/*
		 * THE DOCUMENT'S OWN YEAR, NOT THIS ONE. A stock take dated 31 December that somebody
		 * finalizes on 2 January belongs to the year it counted, and a reference saying otherwise
		 * would be wrong on the one document most likely to be audited.
		 */
		return Numbering::reference( $this->prefix(), $id, $this->year_of( $document ) );
	}

	/**
	 * The year a document belongs to, from whichever date column it has.
	 *
	 * Each type names its date differently — `adjustment_date`, `transfer_date`, `movement_date` —
	 * so the subclass says which, and the base falls back to when the row was created.
	 *
	 * @param array<string, mixed> $document The row.
	 */
	protected function year_of( array $document ): string {
		$date = (string) ( $document[ $this->date_column() ] ?? $document['created_at'] ?? '' );

		return 1 === preg_match( '/^(\d{4})-/', $date, $m ) ? $m[1] : current_time( 'Y' );
	}

	/**
	 * The column holding this document's own date. Override where it is not `created_at`.
	 */
	protected function date_column(): string {
		return 'created_at';
	}

	/**
	 * When this document's stock actually moved, in UTC, for the ledger and the cost layers.
	 *
	 * THE ONE PLACE THIS CONVERSION HAPPENS. Document dates are stored NAIVE WALL-CLOCK -- a date
	 * input yields "2026-08-31" and `WallClock::datetime()` makes that "2026-08-31 00:00:00" in the
	 * reader's own timezone, with no offset applied. Audit columns, by contrast, are UTC. Writing
	 * the wall-clock value straight into the ledger would put two different clocks in one column and
	 * silently shift every report by the site's offset.
	 *
	 * So: subtract the offset once, here, and the ledger stays uniformly UTC. The report windows
	 * then work unchanged -- they already convert the reader's `Y-m-d` to a UTC instant and add
	 * `WallClock::utc_offset()` back before `DATE()` so the day is cut at LOCAL midnight. A document
	 * dated the 31st therefore buckets on the 31st for the person who typed it.
	 *
	 * Falls back to now when the document has no usable date, which is the same answer the ledger
	 * gave for every row before this existed.
	 *
	 * @param array<string, mixed> $document The row.
	 */
	protected function occurred_at( array $document ): string {
		$raw = $document[ $this->date_column() ] ?? '';
		$local = WallClock::datetime( $raw );

		if ( null === $local ) {
			return current_time( 'mysql', true );
		}

		return gmdate( 'Y-m-d H:i:s', (int) strtotime( $local . ' UTC' ) - WallClock::utc_offset() );
	}

	/**
	 * The status table every document in this plugin currently uses.
	 *
	 * TWO STATES, AND `voided` IS ONE ARRAY ENTRY AWAY. Reversal is the honest way to undo a
	 * finalized document, and when it ships this is where it starts.
	 */
	protected function draft_to_finalized(): StatusMachine {
		return new StatusMachine(
			array(
				'draft'     => array( 'finalized' ),
				'finalized' => array(),
			)
		);
	}
}
