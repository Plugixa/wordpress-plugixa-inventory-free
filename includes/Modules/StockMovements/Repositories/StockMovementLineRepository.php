<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\StockMovements\Repositories;

use PlugixaInventory\Core\Documents\LineRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lines of a stock movement.
 */
final class StockMovementLineRepository extends LineRepository {

	protected function table(): string {
		return 'stock_movement_items';
	}

	protected function entity_type(): string {
		return 'stock_movement_line';
	}

	protected function parent_column(): string {
		return 'movement_id';
	}

	/**
	 * @return string[]
	 */
	protected function columns(): array {
		return array(
			'item_id',
			'unit_id',
			'quantity',
			'weight',
			'weight_unit',
			'weight_base',
			'cost_per_unit',
			'total_cost',
			'transaction_id',
			'notes',
			'position',
		);
	}

	/**
	 * @return string[]
	 */
	protected function numeric(): array {
		return array( 'quantity', 'weight', 'weight_base', 'cost_per_unit', 'total_cost' );
	}

	/**
	 * @return string[]
	 */
	protected function integer(): array {
		return array( 'item_id', 'unit_id', 'transaction_id', 'position' );
	}
}
