<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Items\Http;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Http\CrudController;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Items\Services\ItemService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for items.
 */
final class ItemsController extends CrudController {

	public function __construct(
		private readonly ItemService $service,
		private readonly ItemRepository $items
	) {}

	protected function entity_type(): string {
		return 'item';
	}

	protected function api_base(): string {
		return 'items';
	}

	protected function repository(): Repository {
		return $this->items;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name', 't.sku', 't.barcode', 't.serial_number' );
	}

	/**
	 * `?low_stock=1` narrows the list to items at or below their reorder level.
	 *
	 * A CLAUSE RATHER THAN A SEPARATE ROUTE, so it composes with the search box, the category
	 * filter, the sort and the pager the same way every other filter does. A /items/low-stock
	 * endpoint would have to reimplement all four.
	 *
	 * @return callable
	 */
	protected function narrow(): ?callable {
		return static function ( $query, WP_REST_Request $request ): ?WP_Error {
			if ( '' === (string) ( $request->get_param( 'low_stock' ) ?? '' ) ) {
				return null;
			}

			// reorder_level > 0 is part of the definition: zero means "no level set", and without
			// it every item that has never been received reads as low.
			$query->where( 't.reorder_level > 0', array() );
			$query->where( 't.stock_quantity <= t.reorder_level', array() );

			return null;
		};
	}

	/**
	 * The image URL, and the names behind the three foreign keys.
	 *
	 * Resolved per row rather than joined: `names()` is one batched query per entity type, and
	 * keeping the joins out of QueryBuilder keeps the placeholder order it depends on intact.
	 *
	 * @return callable
	 */
	/**
	 * The names behind the three foreign keys, once for the page.
	 *
	 * They used to be resolved inside `decorate()` above, one filter call per row per column. See
	 * `RestController::resolve_names()`. `default_warehouse_id` is published as `warehouse_name` --
	 * the column and the key deliberately differ, which is why the map declares both.
	 */
	protected function decorate_page(): ?callable {
		return fn( array $rows ): array => $this->resolve_names(
			$rows,
			array(
				'category_name'  => array( 'category_id', 'category' ),
				'warehouse_name' => array( 'default_warehouse_id', 'warehouse' ),
				'unit_name'      => array( 'unit_id', 'unit' ),
			)
		);
	}

	protected function decorate(): ?callable {
		return static function ( array $row ): array {
			$image_id = (int) ( $row['image_id'] ?? 0 );

			/*
			 * TWO SIZES, BECAUSE TWO SCREENS WANT DIFFERENT ONES. `image_url` is the 150px
			 * thumbnail the list's 40px avatar wants; `image_url_medium` is what the detail page's
			 * side-column card shows, and at ~300px wide that card would upscale the thumbnail into
			 * a blur. Both are resolved from the same attachment, so they cannot point at different
			 * pictures, and both are null when there is no image or the attachment has gone.
			 */
			$row['image_url'] = $image_id
				? ( wp_get_attachment_image_url( $image_id, 'thumbnail' ) ?: null )
				: null;

			$row['image_url_medium'] = $image_id
				// Falls back to the thumbnail rather than to nothing: an image uploaded before the
				// medium size existed, or with intermediate sizes disabled, still has a thumbnail.
				? ( wp_get_attachment_image_url( $image_id, 'medium' )
					?: ( wp_get_attachment_image_url( $image_id, 'thumbnail' ) ?: null ) )
				: null;

			return $row;
		};
	}

	/**
	 * Through the service, which asks whether the item still holds stock or has ledger history.
	 *
	 * @param int $id Item id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Item id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}

	protected function register_extra_routes(): void {
		register_rest_route(
			$this->namespace,
			'/items/low-stock',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'low_stock' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * The low-stock list, shortest first.
	 *
	 * Exists alongside `?low_stock=1` because the dashboard wants the worst ten and nothing else --
	 * no paging, no filters, no envelope full of meta it will not read.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function low_stock( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) ( $request->get_param( 'limit' ) ?? 10 );

		return $this->ok( $this->items->low_stock( max( 1, min( 100, $limit ) ) ) );
	}
}
