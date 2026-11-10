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
 * The removal is spread across more cost layers than one walk will process.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A CAPACITY LIMIT, AND IT NEEDED ITS OWN EXCEPTION BECAUSE IT WAS BEING REPORTED AS CORRUPTION.
 *
 * The walk is bounded — a removal spread over ten thousand one-unit receipts is ten thousand
 * statements — and when it ran out at the bound it threw `InsufficientLayersException`, which the
 * engine turns into `costing_unavailable`: *"the cost layers do not cover the quantity being
 * removed… run the stock reconcile"*. On a site whose layers cover the quantity perfectly well,
 * that sentence is false in every part. It says the data is broken, and it sends the user to a tool
 * that will correctly report nothing wrong — which is the worst possible outcome, because the next
 * thing they doubt is the reconcile.
 *
 * The two conditions are genuinely different and the remedies share nothing:
 *
 *   InsufficientLayersException  the layers and `item_stock` DISAGREE. Something wrote outside the
 *                                engine. Reconcile, and find out what.
 *   TooManyLayersException       the layers are fine and there are simply a lot of them. Split the
 *                                removal, or raise the bound.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE BOUND IS FILTERABLE, and this exception's message says so, because "your inventory is shaped
 * in a way this default did not anticipate" has to come with something the user can actually do. A
 * site that receives in many small lots — a bakery counting trays, a workshop logging offcuts —
 * is not doing anything wrong, and `plugixa_inventory_max_layer_walk` is the answer for it.
 */
final class TooManyLayersException extends RuntimeException {

	/**
	 * @param int   $item_id      The item.
	 * @param int   $warehouse_id Where it was leaving from.
	 * @param int   $walked       How many layers the walk was allowed to consume.
	 * @param float $consumed     What those layers covered.
	 * @param float $requested    What was asked for.
	 */
	public function __construct(
		public readonly int $item_id,
		public readonly int $warehouse_id,
		public readonly int $walked,
		public readonly float $consumed,
		public readonly float $requested
	) {
		parent::__construct(
			sprintf(
				'Item %d in warehouse %d holds more than %d open cost layers; the oldest %d cover only %.4f of the %.4f requested.',
				$item_id,
				$warehouse_id,
				$walked,
				$walked,
				$consumed,
				$requested
			)
		);
	}
}
