<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services\Costing;

use PlugixaInventory\Core\Money;
use PlugixaInventory\Modules\Stock\Services\StockLine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Average costing: no layers, one cost per item.
 *
 * The default, and what the Joomla component this ports does when `inventory_costing_method` is
 * `standard`. Stock is worth quantity times what it COST — `cost_price`, falling back to the sale
 * price only for an item that has no cost recorded — and a movement's cost is whatever the line
 * said it cost.
 *
 * IT USED TO VALUE STOCK AT THE SALE PRICE, which overstated inventory by the whole gross margin
 * and is worth naming so it is not restored. The basis is not decided here any more: the reports
 * hand it in and `valuation_value()` passes it straight back, because without layers there is
 * nothing more precise to say.
 *
 * IT STILL RECORDS A COST ON EVERY LEDGER ROW, which is the part worth noticing. Without layers
 * there is no COGS calculation to do -- but writing the cost anyway means the COGS report in a
 * later phase reads the same column whichever strategy is active, and a site switching from
 * standard to FIFO does not end up with a hole in its history where the cost should be.
 *
 * `unwind()` DOES NOTHING, and correctly so: this strategy writes nothing outside the ledger row,
 * and the ledger row is removed by `StockEngine::rollback()` itself.
 */
final class StandardCosting implements CostingStrategy {

	public function active(): bool {
		return false;
	}

	/**
	 * @param StockLine $line     The increase.
	 * @param float     $cost     What one unit cost.
	 * @param string    $batch_id The batch.
	 *
	 * @return array{cost_per_unit: float, total_cost: float}
	 */
	public function receive( StockLine $line, float $cost, string $batch_id ): array {
		unset( $batch_id );

		return $this->at( $cost, $line->magnitude() );
	}

	/**
	 * @param StockLine $line     The decrease.
	 * @param string    $batch_id The batch.
	 *
	 * @return array{cost_per_unit: float, total_cost: float}
	 */
	public function consume( StockLine $line, string $batch_id ): array {
		unset( $batch_id );

		return $this->at( $line->cost_per_unit, $line->magnitude() );
	}

	/**
	 * @param int    $item_id  The item.
	 * @param int    $from     Source warehouse.
	 * @param int    $to       Destination warehouse.
	 * @param float  $quantity How much.
	 * @param float  $cost     What the line says a unit cost.
	 * @param string $batch_id The batch.
	 *
	 * @return array{cost_per_unit: float, total_cost: float}
	 */
	public function transfer( int $item_id, int $from, int $to, float $quantity, float $cost, string $batch_id ): array {
		unset( $item_id, $from, $to, $batch_id );

		/*
		 * NO LAYERS TO MOVE, SO THE LINE'S OWN COST IS THE BEST INFORMATION THERE IS.
		 *
		 * Returning 0 here is defensible in the abstract — without layers, stock in one warehouse is
		 * worth what stock in the other is worth, so a transfer costs the business nothing overall.
		 * It is wrong in practice, and the reason is one layer up: `write_back_columns()` writes
		 * whatever comes back onto the document line. A user who typed 0.05, saved and finalized
		 * would find the field zeroed, with nothing on the screen to explain it.
		 */
		return $this->at( $cost, $quantity );
	}

	/**
	 * @param string $batch_id The batch to unwind.
	 */
	public function unwind( string $batch_id ): void {
		unset( $batch_id );
	}

	public function valuation_join(): ?string {
		return null;
	}

	/**
	 * THE BASIS IS THE ANSWER. No layers means no cost this strategy knows that the basis does not
	 * already carry, so sharpening it would mean inventing a number.
	 *
	 * @param string $fallback The cost basis the reports defined.
	 */
	public function valuation_value( string $fallback ): string {
		return $fallback;
	}

	/**
	 * NOTHING TO SEED. There are no layers, so there is no stock that predates them.
	 *
	 * @param int|null $item_id Ignored.
	 *
	 * @return array<string, mixed>
	 */
	public function seed_opening_balances( ?int $item_id = null ): array {
		unset( $item_id );

		return array();
	}

	/**
	 * NOTHING TO OPEN. Without layers, no stock ever lacks something to be costed from.
	 *
	 * @param int   $item_id      Ignored.
	 * @param int   $warehouse_id Ignored.
	 * @param float $batch_delta  Ignored.
	 */
	public function ensure_opening_balance( int $item_id, int $warehouse_id, float $batch_delta ): void {
		unset( $item_id, $warehouse_id, $batch_delta );
	}

	public function unseeded_pairs(): int {
		return 0;
	}

	/**
	 * NOTHING TO DISAGREE WITH, which is not the same as "no drift found" — and the reconcile
	 * reports it as such rather than printing a reassuring zero.
	 *
	 * @param int|null $item_id Ignored.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function layer_diffs( ?int $item_id = null ): array {
		unset( $item_id );

		return array();
	}

	/**
	 * @return array{cost_per_unit: float, total_cost: float}
	 */
	private function at( float $cost, float $quantity ): array {
		$unit = Money::unit_cost( $cost );

		return array(
			'cost_per_unit' => $unit,
			// Four decimals to match the column, NOT the currency's scale: this is a total built
			// from a per-unit cost that itself keeps four. See Core\Money.
			'total_cost'    => round( $unit * $quantity, 4 ),
		);
	}
}
