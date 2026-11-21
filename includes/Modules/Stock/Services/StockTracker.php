<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Something that keeps its own record of stock, in step with the engine.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY AN OBJECT INSIDE THE ENGINE AND NOT A LISTENER ON `plugixa_inventory_stock_applied`.
 *
 * That action fires after a batch is committed. Anything that keeps a breakdown of the same stock
 * (by bin, by lot, by owner) and writes it from there can fail AFTER the engine has already moved
 * the quantities, and then the two records disagree with nothing to put them back. A tracker runs
 * inside `apply()`, under the engine's lock, line by line: if `record()` throws, the engine rolls the
 * whole batch back, and `unwind()` is how the tracker takes its own share back out.
 *
 * Same reasoning, and same shape, as the costing strategy: the engine hands each tracker every line
 * it applies and does not know or care what the tracker keeps.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Supplied through the `plugixa_inventory_stock_trackers` filter as objects, so no file here names
 * an implementation.
 */
interface StockTracker {

	/**
	 * One line has just been applied and written to the ledger.
	 *
	 * May throw to refuse it: the engine then rolls back the whole batch and calls `unwind()`.
	 *
	 * @param StockBatch $batch          The batch the line belongs to.
	 * @param StockLine  $line           The line.
	 * @param int        $transaction_id The ledger row it produced.
	 * @param float      $new_quantity   Stock for that item and warehouse after the line.
	 */
	public function record( StockBatch $batch, StockLine $line, int $transaction_id, float $new_quantity ): void;

	/**
	 * Take back everything recorded for a batch that is being rolled back.
	 *
	 * Must be safe to call for a batch the tracker never saw.
	 *
	 * @param string $batch_id The batch.
	 */
	public function unwind( string $batch_id ): void;
}
