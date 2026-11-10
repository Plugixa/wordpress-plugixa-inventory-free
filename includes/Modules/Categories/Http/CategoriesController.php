<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Categories\Http;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Http\CrudController;
use PlugixaInventory\Modules\Categories\Repositories\CategoryRepository;
use PlugixaInventory\Modules\Categories\Services\CategoryService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for categories.
 *
 * The seven routes come from CrudController. What is here is the two things that are actually
 * specific to a category: which columns a search looks in, and the fact that restoring one can
 * collide with a code taken while it sat in the trash.
 */
final class CategoriesController extends CrudController {

	public function __construct(
		private readonly CategoryService $service,
		private readonly CategoryRepository $categories
	) {}

	protected function entity_type(): string {
		return 'category';
	}

	protected function api_base(): string {
		return 'categories';
	}

	protected function repository(): Repository {
		return $this->categories;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name', 't.code', 't.description' );
	}

	/**
	 * Through the SERVICE, because restoring can collide with a code claimed while this row was in
	 * the trash, and that is business logic rather than transport.
	 *
	 * @param int $id Category id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}
}
