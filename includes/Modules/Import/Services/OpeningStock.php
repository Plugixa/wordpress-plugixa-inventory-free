<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

use PlugixaInventory\Modules\Import\Quiet;
use PlugixaInventory\Modules\Import\Repositories\ImportRowRepository;
use PlugixaInventory\Modules\StockMovements\Services\StockMovementService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turning a quantity column into stock that actually exists.
 *
 * IT POSTS A REAL, FINALIZED DOCUMENT, AND THERE IS NO OTHER HONEST OPTION. `items.stock_quantity`
 * is a cache the Stock module owns and is not writable from an item payload at all; the real
 * quantity lives in `item_stock`, and every change to it is a row in an append-only ledger. Writing
 * any of those directly would produce numbers that look right in a list and that the reconcile
 * immediately reports as drift — which is exactly the bug the ported component had. So this builds
 * a stock movement and finalizes it, through the same service a person uses by hand.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * IT IS AN `add_stock` MOVEMENT, AND IT USED TO BE AN `addition` ADJUSTMENT.
 *
 * Both are real, finalized, auditable documents and both move stock through `StockEngine`, so the
 * numbers this produces are unchanged. What changed is which module owns the document type: import
 * is a free feature and adjustments are not, so an importer that could only express itself as an
 * adjustment was a free feature that stopped working depending on what else was installed.
 *
 * A stock movement is arguably the better fit anyway. An adjustment is a CORRECTION — it carries a
 * type (addition, damage, subtraction) and a reason, and it exists because the number on the shelf
 * disagrees with the number in the system. An opening balance is not a correction: it is stock
 * arriving, which is exactly what a movement is.
 *
 * `add_stock` had to learn to accept assets for this (see `StockMovementService`), because the
 * alternative — `check_in` — demands a person on the other side and its reversal demands one who
 * does not exist.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ONE DOCUMENT PER WAREHOUSE, FLUSHED AT A CAP. Three shapes were possible and two are wrong:
 *
 *  - *One per row* means thousands of documents and thousands of separate lock acquisitions for one
 *    import, and a Stock movements list nobody can read.
 *  - *One draft accumulated across the whole run* cannot be built at all: the document service has
 *    no way to APPEND a line, only to replace every line at once, so each batch would purge and
 *    re-insert everything before it — and an interrupted run would leave items created with a draft
 *    that never posts, so the opening balances would silently not exist.
 *  - *One per warehouse, capped*, is what this does. The lines wait on the import's own row log, so
 *    the pending set survives between batches and across a closed browser, and a document is written
 *    once the cap is reached and again when the run ends.
 *
 * ONE MOVEMENT TYPE FOR EVERY ITEM TYPE, so the grouping stays per warehouse rather than becoming
 * per (warehouse, item type). That is only true because `add_stock` takes assets as well now; had
 * it not, an opening balance carrying both a laptop and a box of screws would have needed two
 * documents, and the cap would have had to be shared between them.
 *
 * THE CAP IS NOT TIDINESS. Finalizing loads every line of a document, validates them, compiles them
 * and applies them in ONE request under a single lock, with no limit anywhere. A five-thousand-line
 * document would therefore undo the entire point of importing in batches — the run would be bounded
 * right up until the moment it posted its stock, and then time out on the host the batching existed
 * to protect.
 */
final class OpeningStock {

	/**
	 * Lines per document, and therefore the bound on one finalize.
	 *
	 * Five hundred keeps a typical import to exactly one document per warehouse — the number people
	 * expect to find in the Adjustments list afterwards — while keeping the heaviest single request
	 * an import can make to something a shared host will finish.
	 */
	public const FLUSH_AT = 500;

	/**
	 * The cap in force, which a site may lower.
	 *
	 * FIVE HUNDRED LINES IS ABOUT FIVE SECONDS on the machine this was measured on, because
	 * finalizing walks every line: validate, compile, preflight, a guarded update, a ledger row and a
	 * cost-layer operation each. That is comfortably inside a default `max_execution_time` and
	 * uncomfortably close to a mean one, so a site on slow shared hosting can bring it down without
	 * touching the code. Lowering it does not change what is written — only how many documents it is
	 * written across.
	 */
	public static function lines_per_document(): int {
		/**
		 * How many opening-stock lines one document may carry.
		 *
		 * @param int $lines Lines per document.
		 */
		$lines = (int) apply_filters( 'plugixa_inventory_import_document_lines', self::FLUSH_AT );

		return max( 10, min( 5000, $lines ) );
	}

	/**
	 * Documents posted per call, and the reason this is not "as many as are ready".
	 *
	 * THE CAP ON LINES BOUNDS ONE DOCUMENT; THIS BOUNDS THE REQUEST. Without it the last batch of a
	 * run flushes whatever every warehouse has left over — three warehouses each holding up to 499
	 * lines is fifteen hundred lines finalized in one call, which is exactly the unbounded work the
	 * line cap was added to prevent, arriving through the other door. Measured on a 1,500-row file
	 * across three warehouses, that last call took 5.7 seconds against 0.5 for every other batch.
	 *
	 * So a call posts one document and says how much is still waiting; the client asks again, the
	 * way it already does for rows. A run is finished when the file is read AND nothing is pending.
	 */
	private const DOCUMENTS_PER_CALL = 1;

	/** How many pending rows to pull in one pass. */
	private const READ_AHEAD = 2000;

	public function __construct(
		private readonly StockMovementService $movements,
		private readonly ImportRowRepository $rows
	) {}

	/**
	 * Post whatever is ready.
	 *
	 * @param int                  $import_id The run.
	 * @param array<string, mixed> $run       The run's row, for its date and file name.
	 * @param bool                 $final     Post part-full chunks too, because the file is read.
	 *
	 * @return array{documents: int, lines: int, errors: int, pending: int}
	 */
	public function flush( int $import_id, array $run, bool $final ): array {
		$posted = array(
			'documents' => 0,
			'lines'     => 0,
			'errors'    => 0,
			'pending'   => 0,
		);

		$cap     = self::lines_per_document();
		$pending = $this->rows->pending_stock( $import_id, self::READ_AHEAD );

		foreach ( $pending as $warehouse_id => $lines ) {
			foreach ( array_chunk( $lines, $cap ) as $chunk ) {
				// A part-full chunk waits for the rest of the file unless the file is read. Posting
				// it early would split one warehouse's opening balance across two documents for no
				// reason at all.
				if ( ! $final && count( $chunk ) < $cap ) {
					continue;
				}

				if ( $posted['documents'] >= self::DOCUMENTS_PER_CALL ) {
					// Bounded. What is left is reported as pending, and the caller comes back.
					break 2;
				}

				$result = $this->post( $import_id, $run, (int) $warehouse_id, $chunk );

				if ( null === $result ) {
					$posted['errors'] += count( $chunk );

					continue;
				}

				++$posted['documents'];
				$posted['lines'] += count( $chunk );
			}
		}

		/*
		 * COUNTED AFTER THE POSTING, so it describes what is left rather than what there was. It is
		 * the caller's "come back" signal, and it is also what stops the run being reported finished
		 * while a warehouse's opening balance has not been written.
		 *
		 * THE PENDING SET ALWAYS SHRINKS, so a client looping on it cannot spin: a document stamps
		 * its rows before it is finalized, and a refusal clears their warehouse — either way those
		 * rows leave the set. Only a part-full chunk stays, and only until the file is read.
		 */
		$posted['pending'] = $this->rows->pending_stock_count( $import_id );

		return $posted;
	}

	/**
	 * The opening-stock document this run left as a draft, if there is one.
	 *
	 * A READ-TIME ANSWER RATHER THAN STATE THAT COULD ITSELF DRIFT. The only way a draft survives a
	 * flush is a PHP fatal between creating the document and finalizing it — the two calls sit in
	 * one statement pair with no client round trip between them — so this is rare, and repairing it
	 * automatically would mean a second code path that posts stock without anybody asking. Reporting
	 * it and naming the document lets the person decide, which is the same line the plugin draws
	 * everywhere else between a reconcile tool and a data-loss tool.
	 *
	 * @param int $import_id The run.
	 *
	 * @return array{id: int, reference: string}|null
	 */
	public function unfinalized( int $import_id ): ?array {
		foreach ( $this->rows->stock_documents( $import_id ) as $document_id ) {
			$document = $this->movements->get( $document_id );

			if ( null !== $document && 'draft' === (string) $document['status'] ) {
				return array(
					'id'        => $document_id,
					'reference' => (string) ( $document['reference'] ?? '' ),
				);
			}
		}

		return null;
	}

	/**
	 * One document. Returns its id, or null if it could not be posted.
	 *
	 * @param int                                                                      $import_id    The run.
	 * @param array<string, mixed>                                                     $run          The run's row.
	 * @param int                                                                      $warehouse_id Where the stock lands.
	 * @param array<int, array{row_id: int, item_id: int, quantity: float, cost: float}> $chunk        The lines.
	 */
	private function post( int $import_id, array $run, int $warehouse_id, array $chunk ): ?int {
		$row_ids = array_map( static fn( array $line ): int => $line['row_id'], $chunk );

		$lines = array_map(
			/*
			 * THE ONE PLACE `cost` BECOMES `cost_per_unit`, AND THE ONLY PLACE IT MAY.
			 *
			 * The document line's key is `cost_per_unit`. The item's own columns are `unit_price`
			 * and `cost_price`. All three are plausible spellings of the same idea and only one of
			 * them is read here — and getting it wrong does not fail. `replace_lines()` reads the
			 * key it wants with a null-coalesce to zero, so a line carrying `cost_price` is accepted,
			 * the adjustment finalizes, and every cost layer it opens is worth nothing. The
			 * valuation report then reads zero for the entire opening inventory, with no error
			 * anywhere to explain it.
			 */
			static fn( array $line ): array => array(
				'item_id'       => $line['item_id'],
				'quantity'      => $line['quantity'],
				'cost_per_unit' => $line['cost'],
			),
			$chunk
		);

		$id = Quiet::post(
			fn(): int|WP_Error => $this->movements->create(
				array(
					'warehouse_id'  => $warehouse_id,
					'movement_type' => 'add_stock',
					'movement_date' => $this->document_date( $run ),
					'details'       => $this->details( $import_id, $run, count( $chunk ) ),
					'lines'         => $lines,
				)
			)
		);

		if ( $id instanceof WP_Error ) {
			$this->rows->fail_stock( $import_id, $row_ids, $this->explain( $id ) );

			return null;
		}

		/*
		 * STAMPED BEFORE THE FINALIZE, NOT AFTER.
		 *
		 * If finalizing throws, these rows still point at the draft — which is what lets the run's
		 * description find an unfinalized document and tell somebody to deal with it. Stamping
		 * afterwards would make a failed flush indistinguishable from one that never ran, and the
		 * next batch would build a second document for the same lines.
		 */
		$this->rows->attach_document( $import_id, $row_ids, $id );

		// SILENCED FOR THE SAME REASON AS THE CREATE, and this is the call that actually raises the
		// alert — finalizing is what fires `plugixa_inventory_document_finalized`. See {@see Quiet}.
		$posted = Quiet::post( fn(): array|WP_Error => $this->movements->finalize( $id ) );

		if ( $posted instanceof WP_Error ) {
			// The rows keep their document id so the draft is findable. They are no longer pending,
			// so nothing retries them: a finalize that failed once for a real reason — a lock
			// timeout, a costing refusal — will fail the same way again, and silently retrying it
			// every batch would bury the message.
			$this->rows->fail_stock( $import_id, $row_ids, $this->explain( $posted ) );

			return null;
		}

		return $id;
	}

	/**
	 * The date the documents carry.
	 *
	 * TAKEN FROM THE RUN, NOT FROM THE CLOCK. A large file imported across midnight would otherwise
	 * produce two documents dated a day apart for one action, and the second would sort away from
	 * the first in every list that orders by document date.
	 *
	 * A document's own date is naive wall-clock in this plugin — it is a business fact about when
	 * something happened, not an instant — whereas `created_at` is UTC. Converting through the
	 * site's timezone is what keeps a run started at 23:30 local from being dated tomorrow.
	 *
	 * @param array<string, mixed> $run The run's row.
	 */
	private function document_date( array $run ): string {
		$created = (string) ( $run['created_at'] ?? '' );

		if ( '' === $created ) {
			return current_time( 'Y-m-d' ) . ' 00:00:00';
		}

		return get_date_from_gmt( $created, 'Y-m-d' ) . ' 00:00:00';
	}

	/**
	 * What the document says about itself, and what its ledger rows will say.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE RUN NUMBER IS IN HERE, AND IT USED TO BE IN A SEPARATE `reason` FIELD.
	 *
	 * An adjustment has a `reason` column and a stock movement does not — it stamps `details` onto
	 * every ledger row it produces instead (`StockMovementService::reason_for()`). That column is
	 * what makes an import findable three weeks later: searching the stock ledger for "import #7"
	 * returns every movement that run created, and no other column could answer it.
	 *
	 * So the run number moved into the sentence rather than being dropped. Written FIRST, because
	 * the ledger's reason column is narrow on screen and a file name that runs long would push the
	 * one searchable token out of view.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int                  $import_id The run.
	 * @param array<string, mixed> $run       The run's row.
	 * @param int                  $lines     How many lines this document carries.
	 */
	private function details( int $import_id, array $run, int $lines ): string {
		return sprintf(
			/* translators: 1: the id of the import run, 2: a file name, 3: a number of lines. */
			__( 'Opening balance — import #%1$d, from %2$s, %3$d lines.', 'plugixa-inventory' ),
			$import_id,
			(string) ( $run['file_name'] ?? '' ),
			$lines
		);
	}

	/**
	 * A refusal, in words a person reading the import's problems can use.
	 *
	 * @param WP_Error $error What the service said.
	 */
	private function explain( WP_Error $error ): string {
		return sprintf(
			/* translators: %s: the reason the stock document could not be posted. */
			__( 'Opening stock was not posted: %s', 'plugixa-inventory' ),
			$error->get_error_message()
		);
	}
}
