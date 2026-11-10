<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services;

use PlugixaInventory\Modules\Items\Services\ItemService;
use PlugixaInventory\Modules\Stock\Repositories\ItemStockRepository;
use PlugixaInventory\Modules\Stock\Repositories\TransactionRepository;
use PlugixaInventory\Modules\Stock\Services\Costing\CostingStrategy;
use PlugixaInventory\Modules\Stock\Services\Costing\InsufficientLayersException;
use PlugixaInventory\Modules\Stock\Services\Costing\TooManyLayersException;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only thing in this plugin that changes how much stock exists.
 *
 * IT HAS NO IDEA WHAT A DOCUMENT IS. Its vocabulary is `(item, warehouse, signed delta, type)` —
 * see `StockLine`. Stock movements, transfers and adjustments each compile themselves down to a
 * list of those and hand it over; a transfer produces two lines per document line. That is what
 * keeps a `switch ( $document_type )` out of this file, and why a fourth document type needs no
 * change here at all.
 *
 * HOW IT STAYS CORRECT UNDER CONCURRENCY, in three layers:
 *
 *   1. `StockLock` — a MySQL named lock, taken for the whole batch. Read its docblock for why this
 *      is not a database transaction; the short version is that a plugin does not own the
 *      connection, and `FOR UPDATE` without a transaction is a no-op that looks like a lock.
 *   2. `ItemStockRepository::apply_delta()` — a guarded, conditional UPDATE whose WHERE clause IS
 *      the sufficiency check. This is the actual fix for the Joomla component's oversell: there is
 *      no window between checking and writing, because they are one statement.
 *   3. A COMPENSATING ROLLBACK. Without a transaction there is nothing to roll back, so a failure
 *      part-way through un-does what it already did, in reverse order.
 *
 * THE ONE GAP, STATED PLAINLY. A PHP fatal — OOM, `max_execution_time`, a segfault in an unrelated
 * extension — between the `item_stock` update and the ledger insert leaves stock changed with no
 * ledger row, and no `finally` runs to compensate. A real transaction would have rolled that back.
 * Three things mitigate it and none of them is a fix: the shutdown sentinel below records the
 * in-flight batch id so a survivor is a known suspect; the daily audit cron runs `rebuild()` and
 * reports any drift within 24 hours; and the named lock releases on connection drop, so a fatal
 * never wedges the next request. Together that is better than the component this ports, which has
 * the same gap and none of the three.
 */
final class StockEngine {

	/**
	 * Every ledger type, and the direction each implies.
	 *
	 * THE ENGINE DOES NOT READ THIS TO DECIDE DIRECTION — the sign of `StockLine::$delta` does that,
	 * and one source of truth for a direction is the whole point. This exists so the ledger's filter
	 * dropdown, the reports and the validation in the document services all name the same set.
	 *
	 * @var string[]
	 */
	public const TRANSACTION_TYPES = array(
		'check_in',
		'check_out',
		'add_stock',
		'remove_stock',
		'transfer_in',
		'transfer_out',
		'adjustment_addition',
		'adjustment_damage',
		'adjustment_subtraction',
	);

	/** Where a batch id is parked while its writes are in flight. See `arm_sentinel()`. */
	/**
	 * PUBLIC ONLY SO THE MODULE CAN DELETE IT ON UNINSTALL. Nothing outside `Stock\Module`'s
	 * cleanup listener should read or write it -- the sentinel is this class's own bookkeeping.
	 */
	public const SENTINEL_OPTION = 'plugixa_inventory_orphan_batches';

	public function __construct(
		private readonly ItemStockRepository $stock,
		private readonly TransactionRepository $ledger,
		private readonly CostingStrategy $costing,
		private readonly ItemService $items,
		private readonly StockLock $lock
	) {}

	/**
	 * Apply a whole batch, or none of it.
	 *
	 * @param StockBatch $batch What the document wants to do.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error One entry per line, in the batch's order:
	 *         `{line_index, transaction_id, item_id, warehouse_id, source_line_id, transaction_type,
	 *         quantity_change, new_quantity, cost_per_unit, total_cost}`. The caller writes the transaction
	 *         ids back onto its document lines and totals the document from `quantity_change` and
	 *         `total_cost`.
	 */
	public function apply( StockBatch $batch ): array|WP_Error {
		if ( $batch->is_empty() ) {
			return new WP_Error(
				'plugixa_inventory_empty_batch',
				__( 'There is nothing to apply.', 'plugixa-inventory' ),
				array( 'status' => 422 )
			);
		}

		if ( ! $this->lock->acquire() ) {
			return new WP_Error(
				'plugixa_inventory_stock_busy',
				__( 'Another stock operation is in progress. Try again in a moment.', 'plugixa-inventory' ),
				array( 'status' => 503 )
			);
		}

		$this->arm_sentinel( $batch->batch_id );

		// What has actually been written, so a failure knows exactly what to undo. Built as we go
		// rather than derived from the batch afterwards: a line that was refused must not be
		// compensated, and only this list knows which those were.
		$applied = array();

		try {
			$result = $this->run( $batch, $applied );

			if ( $result instanceof WP_Error ) {
				$this->rollback( $batch, $applied );

				return $result;
			}

			$this->refresh_cached_totals( $batch );

			/**
			 * Fires once a batch is safely on disk.
			 *
			 * AFTER the cached totals are refreshed, so a listener reading an item's
			 * `stock_quantity` sees the new number rather than the old one.
			 *
			 * @param StockBatch                      $batch  What was applied.
			 * @param array<int, array<string,mixed>> $result One entry per line.
			 */
			do_action( 'plugixa_inventory_stock_applied', $batch, $result );

			return $result;
		} catch ( TooManyLayersException $e ) {
			$this->rollback( $batch, $applied );

			/*
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 * CAUGHT BEFORE `InsufficientLayersException` AND ANSWERED SEPARATELY, because the two
			 * look identical from here and mean opposite things.
			 *
			 * This one says the layers are FINE and there are simply more of them than one removal
			 * walks. Folding it into `costing_unavailable` told the user their data was corrupt and
			 * sent them to a reconcile that would correctly find nothing — after which the next
			 * thing they stop trusting is the reconcile.
			 *
			 * So: a code of its own, the numbers that make the situation legible, and advice that
			 * can actually be acted on.
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 */
			return new WP_Error(
				'plugixa_inventory_too_many_layers',
				sprintf(
					/* translators: 1: how many cost layers one removal may consume. */
					__( 'This item’s stock is spread across more than %1$d separate receipts, which is more than one removal can cost in a single step. Split it into smaller lines, or raise the limit with the plugixa_inventory_max_layer_walk filter. Nothing has been changed.', 'plugixa-inventory' ),
					$e->walked
				),
				array(
					'status'        => 409,
					'item_id'       => $e->item_id,
					'warehouse_id'  => $e->warehouse_id,
					'layers_walked' => $e->walked,
					// What the bound DID cover, which is the largest line that would have worked —
					// the number somebody splitting the removal actually needs.
					'covered'       => $e->consumed,
					'requested'     => $e->requested,
				)
			);
		} catch ( InsufficientLayersException $e ) {
			$this->rollback( $batch, $applied );

			/*
			 * NO LONGER SENDS ANYBODY TO THE RECONCILE. It used to say "Run the stock reconcile
			 * before finalizing", and the reconcile never repairs layers under any flag — so previously
			 * a user who hit this after switching costing method followed the advice, got a clean
			 * report or an unfixable one, and was stuck. Opening balances now happen on first touch,
			 * so what is left to reach here is genuine drift: layers that exist and disagree.
			 */
			return new WP_Error(
				'plugixa_inventory_costing_unavailable',
				sprintf(
					/* translators: %s: the underlying costing error. */
					__( 'The cost layers for an item no longer cover the stock on hand, so this removal cannot be costed. Nothing has been changed — Stock health lists where layers and stock disagree. (%s)', 'plugixa-inventory' ),
					$e->getMessage()
				),
				array(
					'status'       => 409,
					'item_id'      => $e->item_id,
					'warehouse_id' => $e->warehouse_id,
				)
			);
		} catch ( Throwable $e ) {
			$this->rollback( $batch, $applied );

			// The message is not shown to the user: it can carry SQL and paths. The log line is
			// where a developer looks, and the response says only that nothing changed.
			$this->log( $e );

			return new WP_Error(
				'plugixa_inventory_stock_write_failed',
				__( 'The stock change could not be applied. Nothing was changed.', 'plugixa-inventory' ),
				array( 'status' => 500 )
			);
		} finally {
			$this->disarm_sentinel( $batch->batch_id );
			$this->lock->release();
		}
	}

	/**
	 * Advisory check: would this batch fit?
	 *
	 * REPORTS EVERY SHORT LINE, NOT THE FIRST. A form that refuses one row at a time makes the user
	 * submit, fix, submit, fix; the whole reason this exists separately from `apply()` is so the UI
	 * can highlight all three bad rows at once.
	 *
	 * IT IS ALLOWED TO DISAGREE WITH `apply()`, and when it does that disagreement IS the race being
	 * caught. Hence the distinct error code: `insufficient_stock` means "you asked for more than
	 * there is", `stock_changed` means "there was enough when you looked and there is not now", and
	 * the right advice differs.
	 *
	 * @param StockBatch $batch What the document wants to do.
	 */
	public function preflight( StockBatch $batch ): true|WP_Error {
		$short = array();

		/*
		 * NEEDS SUMMED PER PAIR, not checked per line. A document with three lines for the same item
		 * in the same warehouse needs the TOTAL to fit; checking each line against the current
		 * quantity would pass all three when only one fits.
		 */
		$needed = array();

		foreach ( $batch->decreases() as $line ) {
			$key             = $line->item_id . ':' . $line->warehouse_id;
			$needed[ $key ]  = ( $needed[ $key ] ?? 0.0 ) + $line->magnitude();
		}

		foreach ( $needed as $key => $quantity ) {
			list( $item_id, $warehouse_id ) = array_map( 'intval', explode( ':', (string) $key ) );

			$available = $this->stock->quantity( $item_id, $warehouse_id );

			if ( $available + ItemStockRepository::EPSILON < $quantity ) {
				$short[] = array(
					'item_id'      => $item_id,
					'warehouse_id' => $warehouse_id,
					'available'    => round( $available, 4 ),
					'requested'    => round( $quantity, 4 ),
				);
			}
		}

		if ( array() === $short ) {
			return true;
		}

		return new WP_Error(
			'plugixa_inventory_insufficient_stock',
			__( 'There is not enough stock in one or more warehouses to finalize this document.', 'plugixa-inventory' ),
			array(
				'status' => 409,
				'lines'  => $short,
			)
		);
	}

	/** What is on hand for one item in one warehouse. */
	public function available( int $item_id, int $warehouse_id ): float {
		return $this->stock->quantity( $item_id, $warehouse_id );
	}

	/**
	 * An item's quantity per warehouse.
	 *
	 * @return array<int, float>
	 */
	public function levels( int $item_id ): array {
		return $this->stock->levels( $item_id );
	}

	/** An item's total across every warehouse. */
	public function total( int $item_id ): float {
		return $this->stock->total( $item_id );
	}

	/**
	 * Check every denormalised number against the ledger, and optionally correct it.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THIS IS WHAT MAKES "DERIVABLE" A PROPERTY RATHER THAN A CLAIM.
	 *
	 * Three tables hold numbers that are supposed to follow from `transactions`, each one further
	 * from the source than the last:
	 *
	 *   `item_stock.quantity`     = SUM(quantity_change) per (item, warehouse)   <- the ledger
	 *   `items.stock_quantity`    = SUM(item_stock.quantity) per item            <- a cache of a cache
	 *   `inventory_layers`        = NOT derivable. See below.
	 *
	 * Every one of them exists because the alternative is a GROUP BY over the whole ledger on every
	 * page load. They are worth having and they are worth checking, and a system that denormalises
	 * without ever proving the copies still agree has simply chosen not to know.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * THE LOCK IS TAKEN FIRST, and not as a formality: a reconcile that reads `item_stock` while a
	 * finalize is half-written reports drift that does not exist, and an `--apply` run would then
	 * "correct" a number that was about to be correct anyway — turning a report into the thing that
	 * breaks the data.
	 *
	 * FULL OUTER JOIN, DONE IN PHP. A pair present in `item_stock` but absent from the ledger, and a
	 * pair in the ledger with no stock row, are both differences — and a LEFT JOIN in either
	 * direction silently reports only half of them. MySQL has no FULL OUTER JOIN, and the UNION of
	 * two LEFT JOINs is harder to read than merging two arrays on their keys.
	 *
	 * THREE RULES, AND EACH ONE IS A DECISION RATHER THAN AN IMPLEMENTATION DETAIL:
	 *
	 *  1. DRY RUN IS THE DEFAULT. "Item 44 in warehouse 2 says 17 and the ledger says 15" is a bug
	 *     report about something writing outside the engine. Silently correcting it destroys the
	 *     only evidence that it happened, and the next run finds nothing wrong at all.
	 *  2. `$repair_balances` IS A SEPARATE FLAG FROM `$apply`. Rewriting `item_stock` restores a
	 *     number the ledger proves; rewriting `items.stock_quantity` is one further inference, and
	 *     asking for the first should not silently authorise the second.
	 *  3. LAYERS ARE REPORTED, NEVER REPAIRED, UNDER ANY FLAG. `LayerCosting::consume()` is
	 *     path-dependent — the ledger records the COGS that came out, not which layers produced it
	 *     — so `layer_consumptions` is the only record of the path. Recomputing layers would invent
	 *     a history and overwrite the real one. That is the line between a reconcile tool and a
	 *     data-loss tool.
	 *
	 * @param int|null $item_id         Narrow to one item, or null for the whole site.
	 * @param bool     $apply           Correct `item_stock` from the ledger.
	 * @param bool     $repair_balances Additionally correct `items.stock_quantity`.
	 *
	 * @return array<string, mixed>|WP_Error The findings, whether or not anything was corrected.
	 */
	public function rebuild( ?int $item_id = null, bool $apply = false, bool $repair_balances = false ): array|WP_Error {
		if ( ! $this->lock->acquire() ) {
			return new WP_Error(
				'plugixa_inventory_stock_busy',
				__( 'A stock operation is in progress, so the reconcile would report drift that is not there. Try again in a moment.', 'plugixa-inventory' ),
				array( 'status' => 503 )
			);
		}

		try {
			$stored   = $this->stock->all_pairs( $item_id );
			$computed = $this->ledger->computed_balances( $item_id );

			$stock_diffs = $this->diff_pairs( $stored, $computed, $apply );

			/*
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 * RE-READ AFTER A REPAIR, BEFORE CHECKING THE CACHE. The order here is load-bearing and
			 * the bug it prevents is silent.
			 *
			 * `items.stock_quantity` is defined as the sum of that item's `item_stock` rows. If
			 * `--apply` has just corrected those rows, then `$stored` is the state from BEFORE the
			 * correction — and `--repair-balances` would faithfully write that stale sum onto the
			 * item. The run reports success, both numbers change, and the cache is now wrong in a
			 * new way: the next reconcile finds drift the previous one claims to have fixed.
			 * ═══════════════════════════════════════════════════════════════════════════════════
			 */
			if ( $apply && array() !== $stock_diffs ) {
				$stored = $this->stock->all_pairs( $item_id );
			}

			$cache_diffs = $this->diff_cached_totals( $stored, $item_id, $repair_balances );

			return array(
				'item_id'           => $item_id,
				'applied'           => $apply,
				'balances_repaired' => $repair_balances,
				'pairs_checked'     => count( array_unique( array_merge( array_keys( $stored ), array_keys( $computed ) ) ) ),
				'stock_diffs'       => $stock_diffs,
				'cache_diffs'       => $cache_diffs,
				/*
				 * Asked of the strategy rather than computed here, so this method never learns what
				 * a layer is. `StandardCosting` answers with an empty list because a site with no
				 * layers cannot have layer drift — which the caller renders differently from
				 * "checked and found none".
				 */
				'layers_active'     => $this->costing->active(),
				'layer_diffs'       => $this->costing->layer_diffs( $item_id ),
				/*
				 * Batches that were in flight when a request died. Not drift, and possibly not a
				 * problem at all — but if any of the diffs above are real, these are the first
				 * place to look, so they travel with the report rather than in a separate screen.
				 */
				'orphan_batches'    => $this->orphan_batches(),
			);
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * How many pairs still hold stock with nothing to be costed from. Advisory, so no lock.
	 */
	public function unseeded_costing_pairs(): int {
		return $this->costing->unseeded_pairs();
	}

	/**
	 * Give stock that predates the costing strategy something to be costed from.
	 *
	 * ON THE ENGINE RATHER THAN ON THE STRATEGY'S CALLER BECAUSE THE ENGINE OWNS THE LOCK. Seeding
	 * writes layers for pairs it has just read the quantity of; a finalize running underneath it
	 * would consume from a layer set that is still being built. The strategy holds the logic and
	 * knows nothing about locking, which is the same division as everywhere else here.
	 *
	 * @param int|null $item_id Narrow to one item, or null for every pair that needs one.
	 *
	 * @return array<string, mixed>|WP_Error What was created.
	 */
	public function seed_costing_layers( ?int $item_id = null ): array|WP_Error {
		if ( ! $this->costing->active() ) {
			return array( 'seeded' => 0, 'layers' => 0, 'quantity' => 0.0, 'remaining' => 0 );
		}

		if ( ! $this->lock->acquire() ) {
			return new WP_Error(
				'plugixa_inventory_stock_busy',
				__( 'Another stock operation is in progress. Try again in a moment.', 'plugixa-inventory' ),
				array( 'status' => 503 )
			);
		}

		try {
			return $this->costing->seed_opening_balances( $item_id );
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Compare `item_stock` against what the ledger says, and optionally correct it.
	 *
	 * @param array<string, float> $stored   What `item_stock` holds, keyed "item:warehouse".
	 * @param array<string, float> $computed What the ledger sums to, same keys.
	 * @param bool                 $apply    Write the ledger's answer back.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function diff_pairs( array $stored, array $computed, bool $apply ): array {
		$diffs = array();

		foreach ( array_unique( array_merge( array_keys( $stored ), array_keys( $computed ) ) ) as $key ) {
			$have = $stored[ $key ] ?? 0.0;
			$want = $computed[ $key ] ?? 0.0;

			if ( abs( $have - $want ) <= ItemStockRepository::EPSILON ) {
				continue;
			}

			list( $item, $warehouse ) = array_map( 'intval', explode( ':', (string) $key ) );

			if ( $apply ) {
				$this->stock->set_quantity( $item, $warehouse, $want );
			}

			$diffs[] = array(
				'item_id'      => $item,
				'warehouse_id' => $warehouse,
				'stock'        => round( $have, 4 ),
				'ledger'       => round( $want, 4 ),
				'difference'   => round( $have - $want, 4 ),
				'repaired'     => $apply,
			);
		}

		return $diffs;
	}

	/**
	 * Compare `items.stock_quantity` against the sum of that item's stock rows.
	 *
	 * COMPARED AGAINST `item_stock`, NOT AGAINST THE LEDGER, and the difference is diagnostic. The
	 * cache is defined as the sum of the stock rows; checking it against the ledger instead would
	 * make one broken `item_stock` row show up twice, as two findings, and give no way to tell
	 * whether the cache itself had ever drifted.
	 *
	 * @param array<string, float> $stored  Every stock pair, keyed "item:warehouse".
	 * @param int|null             $item_id Narrow to one item, or null for all.
	 * @param bool                 $repair  Write the corrected total back.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function diff_cached_totals( array $stored, ?int $item_id, bool $repair ): array {
		$actual = array();

		foreach ( $stored as $key => $quantity ) {
			$id            = (int) strtok( (string) $key, ':' );
			$actual[ $id ] = ( $actual[ $id ] ?? 0.0 ) + $quantity;
		}

		/*
		 * THE CANDIDATE SET IS "HAS STOCK ROWS, OR CLAIMS A NON-ZERO TOTAL" rather than every item
		 * on the site. An item with no stock rows and a cached zero agrees with itself trivially,
		 * and on a large catalogue that is most of them — fetching them all to find that out is the
		 * query this reconcile would otherwise be remembered for.
		 */
		$cached = $this->items->cached_totals( $item_id, array_keys( $actual ) );

		$diffs = array();

		foreach ( array_unique( array_merge( array_keys( $cached ), array_keys( $actual ) ) ) as $id ) {
			$id = (int) $id;

			if ( null !== $item_id && $id !== $item_id ) {
				continue;
			}

			$have = $cached[ $id ] ?? null;
			$want = $actual[ $id ] ?? 0.0;

			// No row in `items` at all: the stock rows outlived the item they belong to. Reported
			// as an orphan rather than repaired, because writing a total onto a row that does not
			// exist is not a fix and deleting stock is not this tool's job.
			if ( null === $have ) {
				$diffs[] = array(
					'item_id'    => $id,
					'cached'     => null,
					'actual'     => round( $want, 4 ),
					'difference' => round( $want, 4 ),
					'orphan'     => true,
					'repaired'   => false,
				);

				continue;
			}

			if ( abs( $have - $want ) <= ItemStockRepository::EPSILON ) {
				continue;
			}

			if ( $repair ) {
				$this->items->set_cached_stock( $id, $want );
			}

			$diffs[] = array(
				'item_id'    => $id,
				'cached'     => round( $have, 4 ),
				'actual'     => round( $want, 4 ),
				'difference' => round( $have - $want, 4 ),
				'orphan'     => false,
				'repaired'   => $repair,
			);
		}

		return $diffs;
	}

	/**
	 * Apply every line, recording what was written.
	 *
	 * @param StockBatch                      $batch   The batch.
	 * @param array<int, array<string,mixed>> $applied Filled as we go, for the rollback.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function run( StockBatch $batch, array &$applied ): array|WP_Error {
		$results = array();

		// The transfer pairs, so the costing strategy is asked to MOVE layers once rather than to
		// consume and re-receive them — which would land the destination at the wrong cost.
		$transfers = $this->transfer_pairs( $batch );

		/*
		 * The net change this batch has applied to each pair so far, keyed "item:warehouse". The
		 * costing strategy needs it to know what a pair held BEFORE the batch — see
		 * `CostingStrategy::ensure_opening_balance()`.
		 */
		$net = array();

		foreach ( $batch->lines as $index => $line ) {
			$this->stock->ensure_row( $line->item_id, $line->warehouse_id );

			$ok = $this->stock->apply_delta( $line->item_id, $line->warehouse_id, $line->delta );

			if ( null === $ok ) {
				return new WP_Error(
					'plugixa_inventory_stock_write_failed',
					__( 'The stock change could not be applied. Nothing was changed.', 'plugixa-inventory' ),
					array( 'status' => 500 )
				);
			}

			if ( false === $ok ) {
				/*
				 * THE GUARD REFUSED, WHICH MEANS THE NUMBERS MOVED UNDER THE USER. `preflight()`
				 * said this fitted; by the time the write ran it did not. That is exactly the race
				 * this design exists to catch, and it gets its own code because the advice differs
				 * from an ordinary shortage: reload and look again, rather than order more.
				 */
				return new WP_Error(
					'plugixa_inventory_stock_changed',
					__( 'Stock changed while this document was being finalized. Nothing has been applied — reload and try again.', 'plugixa-inventory' ),
					array(
						'status'       => 409,
						'item_id'      => $line->item_id,
						'warehouse_id' => $line->warehouse_id,
						'requested'    => round( $line->magnitude(), 4 ),
						'available'    => round( $this->stock->quantity( $line->item_id, $line->warehouse_id ), 4 ),
					)
				);
			}

			// Recorded BEFORE anything else can fail, so the rollback knows this delta landed.
			$applied[] = array(
				'item_id'      => $line->item_id,
				'warehouse_id' => $line->warehouse_id,
				'delta'        => $line->delta,
			);

			$pair         = $line->item_id . ':' . $line->warehouse_id;
			$net[ $pair ] = ( $net[ $pair ] ?? 0.0 ) + $line->delta;

			$cost = $this->cost_for( $line, $transfers, $batch->batch_id, $net );

			/*
			 * READ BACK INSIDE THE LOCK, not computed from the delta.
			 *
			 * `new_quantity` is a convenience column — a snapshot of the balance after this row —
			 * and `quantity_change` is the truth that `rebuild()` sums. Reading it is correct
			 * against every writer that takes the lock; against one that does not, layer 2 has
			 * already guaranteed the STOCK is right and only this snapshot could drift, which is
			 * what `rebuild( repair_balances: true )` exists to fix.
			 *
			 * The alternative is MySQL's `LAST_INSERT_ID(expr)` trick to have the UPDATE return the
			 * new value. It needs integer scaling by 10^4 to survive that function's semantics, and
			 * it clobbers the session's `LAST_INSERT_ID()` — which unrelated code reading
			 * `$wpdb->insert_id` would silently pick up. A clever mechanism whose failure mode is
			 * invisible and cross-plugin. Do not "optimise" this read away.
			 */
			$new_quantity = $this->stock->quantity( $line->item_id, $line->warehouse_id );

			$transaction_id = $this->ledger->insert(
				array(
					'item_id'             => $line->item_id,
					'warehouse_id'        => $line->warehouse_id,
					'transaction_type'    => $line->transaction_type,
					'quantity_change'     => $line->delta,
					'new_quantity'        => $new_quantity,
					'original_quantity'   => $line->original_quantity,
					'transaction_unit_id' => $line->unit_id,
					'weight'              => $line->weight,
					'weight_unit'         => $line->weight_unit,
					'weight_base'         => $line->weight_base,
					'cost_per_unit'       => $cost['cost_per_unit'],
					'total_cost'          => $cost['total_cost'],
					'batch_id'            => $batch->batch_id,
					'source_type'         => $batch->source_type,
					'source_id'           => $batch->source_id,
					'source_line_id'      => $line->source_line_id,
					'recipient'           => $line->recipient,
					'recipient_id'        => $line->recipient_id,
					'reason'              => $line->reason,
					/*
					 * WHEN IT MOVED, not when it was written. The document's own date, already
					 * converted to UTC by `DocumentService::occurred_at()`. Falling back to now
					 * keeps a caller that supplies nothing behaving exactly as the ledger did
					 * before this column existed.
					 */
					'occurred_at'         => '' !== $line->occurred_at ? $line->occurred_at : current_time( 'mysql', true ),
				)
			);

			if ( 0 === $transaction_id ) {
				return new WP_Error(
					'plugixa_inventory_stock_write_failed',
					__( 'The stock change could not be recorded. Nothing was changed.', 'plugixa-inventory' ),
					array( 'status' => 500 )
				);
			}

			$results[] = array(
				'line_index'      => $index,
				'transaction_id'  => $transaction_id,
				'item_id'         => $line->item_id,
				'warehouse_id'    => $line->warehouse_id,
				'source_line_id'  => $line->source_line_id,
				'transaction_type' => $line->transaction_type,
				// What this line applied, signed. `DocumentService::sum_quantity()` totals it.
				'quantity_change' => $line->delta,
				'new_quantity'    => round( $new_quantity, 4 ),
				'cost_per_unit'   => $cost['cost_per_unit'],
				'total_cost'      => $cost['total_cost'],
			);
		}

		return $results;
	}

	/**
	 * Which lines are the two halves of one transfer.
	 *
	 * THE ONLY PLACE IN THIS FILE THAT KNOWS THE WORD "TRANSFER", and it is here rather than in the
	 * Transfers module because the alternative is worse. Expressing a transfer as two independent
	 * lines would make the costing strategy consume layers at the source and create a fresh layer at
	 * the destination priced from the ITEM rather than from the stock that actually moved — silently
	 * revaluing inventory on every transfer. Pairing them lets `CostingStrategy::transfer()` move
	 * the layers across at their own cost.
	 *
	 * Paired on `source_line_id`, which is why `StockLine` carries one.
	 *
	 * @param StockBatch $batch The batch.
	 *
	 * @return array<int, array{out: StockLine, in: StockLine}> Keyed by source_line_id.
	 */
	private function transfer_pairs( StockBatch $batch ): array {
		$pairs = array();

		foreach ( $batch->lines as $line ) {
			if ( 'transfer_out' !== $line->transaction_type && 'transfer_in' !== $line->transaction_type ) {
				continue;
			}

			if ( 0 === $line->source_line_id ) {
				continue;
			}

			$half = 'transfer_out' === $line->transaction_type ? 'out' : 'in';

			$pairs[ $line->source_line_id ][ $half ] = $line;
		}

		// Only complete pairs. A half on its own is an ordinary decrease or increase.
		return array_filter( $pairs, static fn( array $p ): bool => isset( $p['out'], $p['in'] ) );
	}

	/**
	 * What this line cost, asked of whichever costing strategy is bound.
	 *
	 * @param StockLine                                     $line      The line.
	 * @param array<int, array{out: StockLine, in: StockLine}> $transfers Complete transfer pairs.
	 * @param string                                          $batch_id  The batch, for the strategy.
	 * @param array<string, float>                            $net       Net delta applied per pair so far.
	 *
	 * @return array{cost_per_unit: float, total_cost: float}
	 */
	private function cost_for( StockLine $line, array $transfers, string $batch_id, array $net ): array {
		$pair = $transfers[ $line->source_line_id ] ?? null;

		/*
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * EVERY PAIR THIS LINE TOUCHES GETS AN OPENING BALANCE FIRST, IF IT HAS NONE.
		 *
		 * The strategy decides what that means — average costing does nothing — so this file still
		 * knows nothing about layers. The point is WHEN: before the line is costed, under the lock,
		 * so no line is ever refused, or valued at nothing, because a background seed had not reached
		 * its pair yet. A transfer touches two pairs, and the destination must be opened before
		 * anything is moved into it, or the stock it already held would be skipped for ever.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 */
		$this->costing->ensure_opening_balance(
			$line->item_id,
			$line->warehouse_id,
			$net[ $line->item_id . ':' . $line->warehouse_id ] ?? 0.0
		);

		if ( null !== $pair && 'transfer_out' === $line->transaction_type ) {
			$destination = $pair['in']->warehouse_id;

			$this->costing->ensure_opening_balance(
				$line->item_id,
				$destination,
				$net[ $line->item_id . ':' . $destination ] ?? 0.0
			);
		}

		if ( null !== $pair ) {
			/*
			 * The layers move ONCE per pair, on the OUT half. Doing it on both would move them
			 * twice; doing it on the IN half would move them before the source had been decremented.
			 * The IN half then reports the same cost, so the two ledger rows agree.
			 */
			if ( 'transfer_out' === $line->transaction_type ) {
				return $this->costing->transfer(
					$line->item_id,
					$pair['out']->warehouse_id,
					$pair['in']->warehouse_id,
					$line->magnitude(),
					$line->cost_per_unit,
					$batch_id
				);
			}

			/*
			 * THE IN HALF ASKS FOR A QUANTITY OF ZERO, and that is what makes its `total_cost` zero
			 * while keeping its `cost_per_unit`.
			 *
			 * A transfer costs the business nothing: the same stock is in a different place. If
			 * both halves carried the full total, `SUM(total_cost)` over the ledger would count
			 * every transfer twice — and that sum is what a COGS report is. Recording the value on
			 * the side it LEFT, and the unit cost on both so either row reads sensibly on its own,
			 * makes the naive sum correct without needing a filter on transaction type to rescue it.
			 */
			return $this->costing->transfer(
				$line->item_id,
				$pair['out']->warehouse_id,
				$pair['in']->warehouse_id,
				0.0,
				$line->cost_per_unit,
				$batch_id
			);
		}

		if ( $line->is_decrease() ) {
			return $this->costing->consume( $line, $batch_id );
		}

		return $this->costing->receive( $line, $line->cost_per_unit, $batch_id );
	}

	/**
	 * Undo a batch that failed part-way, in reverse order.
	 *
	 * COMPENSATION, NOT ROLLBACK, because there is no transaction to roll back — see the class
	 * docblock. Reverse order because the costing strategy's layers were consumed in forward order,
	 * and un-consuming them the other way round is what restores the same layers.
	 *
	 * NONE OF THESE THREE STEPS MAY THROW. This runs inside a `catch`, and an exception here would
	 * replace a recoverable failure with a half-undone one.
	 *
	 * @param StockBatch                      $batch   The batch.
	 * @param array<int, array<string,mixed>> $applied The deltas that actually landed.
	 */
	private function rollback( StockBatch $batch, array $applied ): void {
		try {
			// 1. The cost layers, by batch. Safe to call for a batch that created none.
			$this->costing->unwind( $batch->batch_id );

			// 2. The quantities, inverted. Unguarded: a compensating move can never be short.
			foreach ( array_reverse( $applied ) as $entry ) {
				$this->stock->force_delta(
					(int) $entry['item_id'],
					(int) $entry['warehouse_id'],
					-(float) $entry['delta']
				);
			}

			// 3. The ledger rows. See TransactionRepository::purge_batch() for why deleting from an
			// append-only table is right here: this batch never finalized, so it never happened.
			$this->ledger->purge_batch( $batch->batch_id );
		} catch ( Throwable $e ) {
			$this->log( $e );
		}
	}

	/**
	 * Push each touched item's new total onto its `items.stock_quantity` cache.
	 *
	 * ONCE PER ITEM, NOT ONCE PER LINE. A forty-line movement touching six items writes six rows,
	 * and raises six `plugixa_inventory_stock_level_changed` actions rather than forty.
	 *
	 * @param StockBatch $batch The batch that was applied.
	 */
	private function refresh_cached_totals( StockBatch $batch ): void {
		$item_ids = array_values( array_unique( array_map( static fn( StockLine $l ): int => $l->item_id, $batch->lines ) ) );

		foreach ( $item_ids as $item_id ) {
			$this->items->set_cached_stock( $item_id, $this->stock->total( $item_id ) );
		}
	}

	/**
	 * Park a batch id where a later request can find it if this one dies mid-write.
	 *
	 * THE MITIGATION FOR THE GAP IN THE CLASS DOCBLOCK, and it is honest about being partial: a
	 * fatal between the stock update and the ledger insert runs no `finally`, so the id stays in
	 * this option. It does not repair anything — it names the suspect, so the reconcile has
	 * somewhere to start and so a support conversation is not archaeology.
	 *
	 * `autoload = false`: this option is read by the reconcile and by nothing on a normal page load.
	 *
	 * @param string $batch_id The batch about to be written.
	 */
	private function arm_sentinel( string $batch_id ): void {
		$parked = (array) get_option( self::SENTINEL_OPTION, array() );

		$parked[ $batch_id ] = current_time( 'mysql', true );

		update_option( self::SENTINEL_OPTION, $parked, false );
	}

	/**
	 * @param string $batch_id The batch that finished, one way or the other.
	 */
	private function disarm_sentinel( string $batch_id ): void {
		$parked = (array) get_option( self::SENTINEL_OPTION, array() );

		unset( $parked[ $batch_id ] );

		update_option( self::SENTINEL_OPTION, $parked, false );
	}

	/**
	 * Batch ids that were in flight when something died.
	 *
	 * @return array<string, string> Batch id => when it was armed.
	 */
	public function orphan_batches(): array {
		return (array) get_option( self::SENTINEL_OPTION, array() );
	}

	/**
	 * @param Throwable $e What went wrong.
	 */
	private function log( Throwable $e ): void {
		/**
		 * Fires when the stock engine catches something it could not handle.
		 *
		 * The hook exists so a site can route this to whatever it already uses. Core writes to the
		 * PHP error log only when WP_DEBUG is on, because a plugin that logs unconditionally fills
		 * somebody's disk.
		 *
		 * @param Throwable $e The exception.
		 */
		do_action( 'plugixa_inventory_stock_error', $e );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- guarded by WP_DEBUG, and a swallowed stock failure is exactly what must not be silent.
			error_log( 'Plugixa Inventory stock engine: ' . $e->getMessage() );
		}
	}
}
