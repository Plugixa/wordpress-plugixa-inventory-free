<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Services\Costing;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cost layers do not cover the quantity leaving.
 *
 * A CORRUPTION SIGNAL RATHER THAN AN ORDINARY REFUSAL, and the distinction matters to whoever reads
 * the error. Insufficient STOCK is a normal thing to be told: you asked for five and there are
 * three. Insufficient LAYERS means `item_stock` says there are five and the layers only account for
 * three -- the two disagree, and one of them is wrong. That is what `StockEngine::rebuild()`
 * reports as `layer_diffs`, and the message points there.
 *
 * An exception rather than a WP_Error because it unwinds from inside the costing strategy, several
 * frames below the method that has to turn it into a response -- and because the engine catching it
 * is what triggers the compensating rollback.
 */
final class InsufficientLayersException extends RuntimeException {

	/**
	 * @param int   $item_id      The item.
	 * @param int   $warehouse_id Where it was leaving from.
	 * @param float $available    What the layers actually hold.
	 * @param float $requested    What was asked for.
	 */
	public function __construct(
		public readonly int $item_id,
		public readonly int $warehouse_id,
		public readonly float $available,
		public readonly float $requested
	) {
		parent::__construct(
			sprintf(
				'Insufficient inventory layers for item %d in warehouse %d. Available: %.4f, requested: %.4f.',
				$item_id,
				$warehouse_id,
				$available,
				$requested
			)
		);
	}
}
