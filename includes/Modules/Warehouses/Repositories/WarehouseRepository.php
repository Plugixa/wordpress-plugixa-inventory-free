<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Warehouses\Repositories;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Modules\Warehouses\Services\WarehouseService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The warehouse list.
 */
final class WarehouseRepository extends Repository {

	protected function table(): string {
		return 'warehouses';
	}

	protected function entity_type(): string {
		return 'warehouse';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array( 'code', 'name', 'address', 'status' );
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'code', 'name', 'status', 'created_at', 'modified_at' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array_merge(
			$this->common_filters(),
			array(
				'status' => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'sql'     => 't.status',
					'type'    => 'select',
					'options' => WarehouseService::STATUSES,
				),
				/*
				 * CODE IS FILTERABLE, NOT ONLY SEARCHABLE. The search box already matches it, but a
				 * search is one box for the whole row — "MAIN" also matches a warehouse whose
				 * address mentions the main road. A filter says which column it means.
				 */
				'code'   => array(
					'label' => __( 'Code', 'plugixa-inventory' ),
					'sql'   => 't.code',
					'type'  => 'text',
				),
			)
		);
	}

	/**
	 * A live warehouse with this code, if there is one.
	 *
	 * @param string $code    Code to look for.
	 * @param int    $exclude Row id to ignore.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_code( string $code, int $exclude = 0 ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE code = %s AND id <> %d AND deleted_at IS NULL LIMIT 1',
				$this->table_name(),
				$code,
				$exclude
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every published warehouse, ordered for a picker.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_published(): array {
		return array_map(
			array( $this, 'cast' ),
			$this->query()
				->where( 't.status = %s', array( 'published' ) )
				->order_by( 't.name', 'ASC' )
				->paginate( 500, 0 )
				->get()
		);
	}

	/**
	 * @param array<string, mixed> $row One row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'created_by', 'modified_by' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		return $row;
	}
}
