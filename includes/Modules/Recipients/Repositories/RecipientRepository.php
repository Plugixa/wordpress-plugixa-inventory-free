<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Recipients\Repositories;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Modules\Recipients\Services\RecipientService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The recipient list.
 */
final class RecipientRepository extends Repository {

	protected function table(): string {
		return 'recipients';
	}

	protected function entity_type(): string {
		return 'recipient';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array( 'name', 'email', 'phone', 'department', 'user_id', 'notes', 'status' );
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'name', 'email', 'department', 'status', 'created_at', 'modified_at' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array_merge(
			$this->common_filters(),
			array(
				'status'     => array(
					'label'   => __( 'Status', 'plugixa-inventory' ),
					'sql'     => 't.status',
					'type'    => 'select',
					'options' => RecipientService::STATUSES,
				),
				'department' => array(
					'label'   => __( 'Department', 'plugixa-inventory' ),
					'sql'     => 't.department',
					'type'    => 'select',
					// ROWS, not constants: departments are whatever the user typed, so the choices have
					// to come from the data. `labels` is the seam Repository documents for exactly this.
					'labels'  => $this->departments(),
					'options' => array_keys( $this->departments() ),
				),
			)
		);
	}

	/**
	 * Every distinct department in use, as value => label.
	 *
	 * @return array<string, string>
	 */
	public function departments(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT department FROM %i WHERE department <> '' AND deleted_at IS NULL ORDER BY department ASC",
				$this->table_name()
			)
		);

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row ] = (string) $row;
		}

		return $map;
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

		if ( array_key_exists( 'user_id', $row ) ) {
			$row['user_id'] = null === $row['user_id'] ? null : (int) $row['user_id'];
		}

		return $row;
	}
}
