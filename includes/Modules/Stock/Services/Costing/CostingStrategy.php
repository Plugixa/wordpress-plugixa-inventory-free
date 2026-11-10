<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services\Costing;

use PlugixaInventory\Modules\Stock\Services\StockLine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a stock movement is costed.
 *
 * AN INTERFACE RESOLVED FROM THE CONTAINER, NOT A MODULE AND NOT CONCRETE CODE INSIDE THE ENGINE.
 * Three reasons, and the first is the one that actually decides it:
 *
 * 1. `consume()` MUST RUN INSIDE THE ENGINE'S CRITICAL SECTION. It decrements `quantity_remaining`
 *    on the very layers the same operation is decrementing `item_stock` for. Reached through a
 *    `do_action`, it could neither fail the finalize nor return the cost — and the failure mode of
 *    that is a consumed layer with no ledger row, which is silent corruption.
 * 2. `consume()` RETURNS A VALUE the caller writes onto the ledger row. An action cannot return one.
 * 3. IT IS ALREADY THE FREE/PREMIUM SEAM. Moving `LayerCosting` and its two tables into `pro/`
 *    later is a binding change in `Stock\Module` and zero characters of `StockEngine`.
 *
 * WHAT AN IMPLEMENTATION MAY ASSUME: it is called with the stock lock held, once per line, in the
 * order the batch declares — and that `unwind()` will be called with the batch id if anything later
 * in the same batch fails. Before `receive()`, `consume()` or `transfer()` for a line, the engine calls
 * `ensure_opening_balance()` for every pair that line touches, with the lock already held.
 */
interface CostingStrategy {

	/**
	 * An option that says: costing state kept on disk predates a period this strategy did not see.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * WRITTEN BY `Stock\Module` WHEN A COSTING METHOD THAT KEEPS STATE COMES BACK INTO FORCE after a
	 * stretch without it — the free build ran for a while, or the site was on average costing.
	 * Stock moved during that stretch and nothing kept the stored state in step, so it describes an
	 * inventory that no longer exists.
	 *
	 * An implementation that keeps such state must CLAIM this option (delete it, atomically, and act
	 * only if the delete removed a row) before its next write, and discard what it held. One that
	 * keeps none ignores it. The claim has to be atomic because two requests can both see the option
	 * set; only the one whose delete succeeds may act, or the second would discard state the first
	 * had just rebuilt.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */
	public const RESTART_OPTION = 'plugixa_inventory_costing_restart';

	/**
	 * Whether this strategy keeps cost layers at all.
	 *
	 * NOT WHAT VALUATION BRANCHES ON, and it used to be. `value_expression()` once chose between
	 * two different formulas on the strength of this flag, which is how the two builds came to
	 * disagree about what "stock value" meant — one arm valued at cost, the other at the sale
	 * price. The expression now comes from `valuation_value()` and this answers a narrower
	 * question: whether there are layers to age stock by, and whether the reconcile has a third
	 * comparison to make.
	 */
	public function active(): bool;

	/**
	 * Stock arrived. Record what it cost.
	 *
	 * @param StockLine $line     The increase.
	 * @param float     $cost     What one unit cost, already defaulted by the caller.
	 * @param string    $batch_id The batch, so `unwind()` can find what this created.
	 *
	 * @return array{cost_per_unit: float, total_cost: float}
	 */
	public function receive( StockLine $line, float $cost, string $batch_id ): array;

	/**
	 * Stock left. Work out what it cost us, which is the COGS the ledger row records.
	 *
	 * @param StockLine $line     The decrease.
	 * @param string    $batch_id The batch, so `unwind()` can find what this consumed.
	 *
	 * @return array{cost_per_unit: float, total_cost: float}
	 *
	 * @throws InsufficientLayersException When the layers do not cover the quantity leaving.
	 * @throws TooManyLayersException      When they do, but across more layers than one walk allows.
	 */
	public function consume( StockLine $line, string $batch_id ): array;

	/**
	 * Stock moved between warehouses at the SAME cost.
	 *
	 * Not `consume()` then `receive()`: that would create the destination layer at the item's
	 * default price rather than at what the stock actually cost, and a transfer would silently
	 * revalue inventory.
	 *
	 * @param int    $item_id  The item.
	 * @param int    $from     Source warehouse.
	 * @param int    $to       Destination warehouse.
	 * @param float  $quantity How much, unsigned.
	 * @param float  $cost     What the line says a unit cost. A HINT, not an instruction: a layer
	 *                         strategy computes the real figure from the layers it is moving and
	 *                         ignores this, while a strategy with no layers has nothing better and
	 *                         uses it. Passing it is what stops a transfer from silently zeroing
	 *                         the cost the user typed.
	 * @param string $batch_id The batch, so a rollback can find what this did.
	 *
	 * @return array{cost_per_unit: float, total_cost: float}
	 *
	 * @throws InsufficientLayersException When the source's layers do not cover the quantity.
	 * @throws TooManyLayersException      When they do, but across more layers than one walk allows.
	 */
	public function transfer( int $item_id, int $from, int $to, float $quantity, float $cost, string $batch_id ): array;

	/**
	 * Undo everything this strategy did for a batch.
	 *
	 * Called by `StockEngine::rollback()` when a batch fails part-way. Must be safe to call for a
	 * batch this strategy did nothing for.
	 *
	 * @param string $batch_id The batch to unwind.
	 */
	public function unwind( string $batch_id ): void;

	/**
	 * A SQL fragment joining layer value onto a query aliased `t`, or null when there is none.
	 *
	 * Returned as SQL rather than as a number because valuation is asked per category, per warehouse
	 * and per item type, and a strategy that answered one row at a time would turn a report into a
	 * query per row.
	 */
	public function valuation_join(): ?string;

	/**
	 * What one stock row is worth, given the cost basis the reports define.
	 *
	 * THE FALLBACK IS PASSED IN RATHER THAN KNOWN HERE, and that is the whole point of the method.
	 * A strategy may SHARPEN the figure with costs it actually holds — layers know what each
	 * remaining unit really cost — but it cannot substitute a different basis. Free stays the only
	 * author of "what is stock worth", so the answer means the same thing on both builds and
	 * buying the premium build refines the number rather than redefining it.
	 *
	 * THE FRAGMENT MAY REFERENCE ONLY `s` AND `t` PLUS WHATEVER `valuation_join()` INTRODUCED.
	 * `$fallback` is built from `s` (`item_stock`) and `t` (`items`); a strategy that joins its own
	 * alias must join it in `valuation_join()` first, because a join cannot reference an alias that
	 * has not been introduced yet.
	 *
	 * @param string $fallback The cost basis, already a complete per-row expression.
	 *
	 * @return string A per-row value expression, ready to be wrapped in SUM().
	 */
	public function valuation_value( string $fallback ): string;

	/**
	 * Give stock that predates this strategy something to be costed from.
	 *
	 * THE REASON SWITCHING A LIVE SITE TO FIFO IS NOT AN OUTAGE. Every quantity received while
	 * average costing was in force has no layer behind it, so the first removal afterwards would
	 * find nothing to consume and be refused as a corruption — on a site where nothing is corrupt
	 * at all. This is the one operation allowed to create a layer for stock it did not see arrive.
	 *
	 * IT IS AN INITIALISATION, NOT A REPAIR, and the distinction is what keeps
	 * `StockEngine::rebuild()` honest: the reconcile still reports layer drift and still refuses to
	 * touch it under any flag, because drift means the layers and the ledger disagree about stock
	 * they both saw. A pair with NO layers at all is a different statement — nobody has claimed
	 * anything about it — and filling that in invents nothing.
	 *
	 * @param int|null $item_id Narrow to one item, or null for every pair that needs one.
	 *
	 * @return array<string, mixed> A report of what was created. Empty for a strategy with no layers.
	 */
	public function seed_opening_balances( ?int $item_id = null ): array;

	/**
	 * Make sure a pair's pre-existing stock has something to be costed from, before a line moves it.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE SEED, DONE THE FIRST TIME A PAIR IS TOUCHED, SO NO LINE IS EVER REFUSED FOR WANT OF ONE.
	 *
	 * `seed_opening_balances()` runs in the background after a switch. Until it reaches a pair, that
	 * pair's stock has nothing behind it — and before this the gap was worse than a wait: a receipt into
	 * the pair opened a layer, the seed then treated the pair as already seeded and skipped it for
	 * good, and the stock that was there before was valued at nothing and could never be removed.
	 * Reproduced on Linux. Calling this before every line closes that window by construction; the
	 * background run only gets there first.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * Called with the lock held. MUST NEVER touch a pair that already holds state — that would be a
	 * repair, and repairs are refused (see `layer_diffs()`). `unwind()` does not undo it: what it
	 * creates describes stock that existed before the batch, and remains true if the batch fails.
	 *
	 * @param int   $item_id      The item.
	 * @param int   $warehouse_id The warehouse.
	 * @param float $batch_delta  The net signed quantity this batch has ALREADY applied to the pair,
	 *                            the current line included, so "on hand before this batch" is the
	 *                            stored quantity minus this.
	 */
	public function ensure_opening_balance( int $item_id, int $warehouse_id, float $batch_delta ): void;

	/**
	 * How many pairs hold stock with nothing to cost it from yet. Zero for a strategy with no layers.
	 *
	 * An advisory count for the Stock health panel and the audit, which re-queue the background
	 * seed while it is above zero. Bounded by the implementation — a site with more than it counts
	 * sees the bound, which is still "not zero".
	 */
	public function unseeded_pairs(): int;

	/**
	 * Where the layers and the stock table disagree — the reconcile's third comparison.
	 *
	 * REPORTED, NEVER REPAIRED. `consume()` is path-dependent: the ledger records the COGS that
	 * came out, not which layers produced it, so `layer_consumptions` is the only record of the
	 * path. A tool that "fixed" layers by recomputing them would invent a history and destroy the
	 * real one, which is the difference between a reconcile and a data-loss tool.
	 *
	 * @param int|null $item_id Narrow to one item, or null for all.
	 *
	 * @return array<int, array<string, mixed>> One entry per disagreeing pair. Empty for a strategy
	 *         with no layers, which cannot disagree with anything.
	 */
	public function layer_diffs( ?int $item_id = null ): array;
}
