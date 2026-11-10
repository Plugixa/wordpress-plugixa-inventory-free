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
 * Every stock change one document makes, applied together or not at all.
 *
 * The `batch_id` is a UUID rather than the document id, and it has to be: two document TYPES can
 * hold the same id, so `source_id` alone does not identify a batch. It is also what
 * `StockEngine::rollback()` keys on to un-write a batch that failed half-way, and what a ledger
 * screen groups by to show one movement as one event rather than forty rows.
 */
final class StockBatch {

	/**
	 * @param string      $source_type One of stock_movement, transfer, adjustment.
	 * @param int         $source_id   The document's id.
	 * @param StockLine[] $lines       Every change this document makes.
	 * @param string      $batch_id    A UUID. Use wp_generate_uuid4().
	 */
	public function __construct(
		public readonly string $source_type,
		public readonly int $source_id,
		public readonly array $lines,
		public readonly string $batch_id
	) {}

	/** Just the lines that take stock away -- the only ones that can be refused for insufficiency. */
	public function decreases(): array {
		return array_values( array_filter( $this->lines, static fn( StockLine $l ): bool => $l->is_decrease() ) );
	}

	public function is_empty(): bool {
		return array() === $this->lines;
	}
}
