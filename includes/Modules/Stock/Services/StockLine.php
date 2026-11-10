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
 * One signed change to one item's quantity in one warehouse.
 *
 * THE ENGINE'S ENTIRE VOCABULARY, and the reason it has no idea what a transfer is. A stock
 * movement, a transfer and an adjustment all compile down to a list of these; a transfer compiles
 * to TWO per document line, one negative at the source and one positive at the destination. That is
 * what stops `StockEngine` from ever growing a `switch ( $document_type )`, and it is why a fourth
 * document type -- a purchase receipt, a stock count -- needs no engine change at all.
 *
 * `delta` IS SIGNED AND NEVER ZERO. The sign is the direction, so the engine needs no separate
 * "is this an increase" flag that could disagree with it. Zero is excluded because the affected-row
 * count is how the engine detects insufficient stock, and a zero delta changes no row and would
 * therefore be indistinguishable from a refusal.
 *
 * `occurred_at` IS WHEN THE STOCK MOVED, AND IT IS NOT `created_at`. Until this field existed the
 * ledger recorded only when a row was WRITTEN, so a delivery that arrived on the 20th and was keyed
 * in on the 6th of the following month was filed under the 6th -- by every trend chart, by COGS, by
 * the KPI windows, and by the ageing report, which then answered "nothing is over 30 days old"
 * about stock that was seven weeks old. Backdating is the normal case, not an edge one: you enter
 * Friday's delivery on Monday, and all three document forms put a Date field in the header for
 * exactly that reason. `DocumentService::occurred_at()` is the one place it is derived.
 *
 * IT IS STORED UTC, like every other datetime the engine writes, because that is what the report
 * windows compare against -- they convert the reader's local `Y-m-d` into a UTC instant and then
 * add `WallClock::utc_offset()` back before `DATE()` to cut the day at local midnight. Storing the
 * document's naive wall-clock value here instead would be an hour or nine out of step with every
 * one of those comparisons.
 */
final class StockLine {

	/**
	 * @param int         $item_id          The item moving.
	 * @param int         $warehouse_id     Where it is moving in or out of.
	 * @param float       $delta            Signed quantity. Never 0.
	 * @param string      $transaction_type Ledger type: check_in, check_out, add_stock,
	 *                                      remove_stock, transfer_in, transfer_out, adjustment_*.
	 * @param float       $cost_per_unit    What one unit cost, for a receipt. Ignored on a decrease,
	 *                                      where the cost comes back OUT of the costing strategy.
	 * @param float       $weight           Weight recorded on this line, if any.
	 * @param string      $weight_unit      The unit that weight is in.
	 * @param float       $weight_base      That weight normalised to kilograms.
	 * @param int|null    $unit_id          The unit the quantity was ENTERED in, when not the item's.
	 * @param float       $original_quantity The quantity as entered, before unit conversion.
	 * @param int         $source_line_id   The document line this came from. Pairs the two halves
	 *                                      of a transfer, which is how the engine spots one.
	 * @param string      $recipient        Denormalised name, so the ledger reads without a join.
	 * @param int|null    $recipient_id     Who it went to, on a check-out.
	 * @param string      $reason           Free text shown on the ledger row.
	 * @param string      $occurred_at      WHEN THE STOCK MOVED, in UTC, from the document's own
	 *                                      date -- not when the row was keyed in. Empty means "the
	 *                                      caller had no opinion", and the engine falls back to now.
	 *                                      See the note on the constructor below.
	 */
	public function __construct(
		public readonly int $item_id,
		public readonly int $warehouse_id,
		public readonly float $delta,
		public readonly string $transaction_type,
		public readonly float $cost_per_unit = 0.0,
		public readonly float $weight = 0.0,
		public readonly string $weight_unit = '',
		public readonly float $weight_base = 0.0,
		public readonly ?int $unit_id = null,
		public readonly float $original_quantity = 0.0,
		public readonly int $source_line_id = 0,
		public readonly string $recipient = '',
		public readonly ?int $recipient_id = null,
		public readonly string $reason = '',
		public readonly string $occurred_at = ''
	) {}

	/** Does this line take stock away? */
	public function is_decrease(): bool {
		return $this->delta < 0;
	}

	/** The unsigned size of the change, which is what a message quotes. */
	public function magnitude(): float {
		return abs( $this->delta );
	}
}
