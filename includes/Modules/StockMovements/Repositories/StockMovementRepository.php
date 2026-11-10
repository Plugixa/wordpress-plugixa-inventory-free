<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\StockMovements\Repositories;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Modules\StockMovements\Services\StockMovementService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock movement documents.
 */
final class StockMovementRepository extends Repository {

	protected function table(): string {
		return 'stock_movements';
	}

	protected function entity_type(): string {
		return 'stock_movement';
	}

	protected function name_column(): string {
		return 'reference';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'reference',
			'movement_type',
			'warehouse_id',
			'recipient_id',
			'recipient',
			'movement_date',
			'status',
			'finalized_at',
			'finalized_by',
			'batch_id',
			'line_count',
			'total_quantity',
			'total_cost',
			'details',
			'attachments',
			'assigned_user_id',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'reference', 'movement_type', 'movement_date', 'status', 'created_at', 'modified_at' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array_merge(
			$this->common_filters(),
			array(
				'status'        => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'sql'     => 't.status',
					'type'    => 'select',
					'options' => StockMovementService::STATUSES,
				),
				'movement_type' => array(
					'label'   => __( 'Type', 'plugixa-inventory' ),
					'sql'     => 't.movement_type',
					'type'    => 'select',
					'options' => StockMovementService::TYPES,
				),
				'warehouse_id'  => array(
					'label'  => __( 'Warehouse', 'plugixa-inventory' ),
					'sql'    => 't.warehouse_id',
					'type'   => 'entity',
					'entity' => 'warehouse',
				),
				'recipient_id'  => array(
					'label'  => __( 'Recipient', 'plugixa-inventory' ),
					'sql'    => 't.recipient_id',
					'type'   => 'entity',
					'entity' => 'recipient',
				),
				'movement_date' => array(
					'label' => __( 'Date', 'plugixa-inventory' ),
					'sql'   => 't.movement_date',
					'type'  => 'date',
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $row One row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'warehouse_id', 'line_count', 'created_by', 'modified_by' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		foreach ( array( 'recipient_id', 'finalized_by', 'assigned_user_id' ) as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
			}
		}

		foreach ( array( 'total_quantity', 'total_cost' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (float) $row[ $column ];
			}
		}

		return $row;
	}
}
