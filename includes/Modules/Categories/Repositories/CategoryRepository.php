<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Categories\Repositories;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Modules\Categories\Services\CategoryService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The category catalogue.
 */
final class CategoryRepository extends Repository {

	protected function table(): string {
		return 'categories';
	}

	protected function entity_type(): string {
		return 'category';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array( 'code', 'name', 'description', 'status' );
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
					'options' => CategoryService::STATUSES,
				),
				'code'   => array(
					'label' => __( 'Code', 'plugixa-inventory' ),
					'sql'   => 't.code',
					'type'  => 'text',
				),
			)
		);
	}

	/**
	 * A live category with this code, if there is one.
	 *
	 * The database has no unique index on `code`, deliberately — see the Schema docblock. So
	 * uniqueness is enforced here instead, among the rows that still exist, which is a question
	 * only the application can ask.
	 *
	 * AN EMPTY CODE IS NOT A DUPLICATE OF ANOTHER EMPTY CODE. The column is `NOT NULL default ''`
	 * because dbDelta and MySQL are happier that way, but the Joomla original allows a category
	 * with no code at all, and several of its seeded ones have none. Treating '' as a value would
	 * mean the second uncoded category collides with the first, so the caller checks for a
	 * non-empty code before asking.
	 *
	 * @param string $code    Code to look for.
	 * @param int    $exclude Row id to ignore, so a category does not collide with itself.
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
	 * Every published category, ordered for a picker.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_published(): array {
		return $this->query()
			->where( 't.status = %s', array( 'published' ) )
			->order_by( 't.name', 'ASC' )
			->paginate( 500, 0 )
			->get();
	}

	/**
	 * Give the numeric columns their real types.
	 *
	 * $wpdb returns everything as a string, and "0" is truthy in JavaScript — a client testing an
	 * id to decide whether to render a link is right about the rows that have one and wrong about
	 * the rest.
	 *
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
