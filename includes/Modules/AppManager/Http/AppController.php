<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\AppManager\Http;

use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Core\Modules\AppState;
use PlugixaInventory\Modules\AppManager\Services\AppCatalogue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the app grid, and switch one app on or off.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * BOTH ROUTES TAKE `manage_options`, INCLUDING THE ONE THAT ONLY READS — the same reasoning as
 * `DemoController`, for a stronger reason. This is the discovery route for an endpoint that can
 * make a third of the REST API disappear. Somebody who may not press the button has no business
 * being handed the inventory of what the button can reach.
 *
 * IT IS ALSO THE ESCAPE HATCH, which is why the check is `manage_options` and not
 * `plugixa_inventory_manage`. Administrators hold `manage_options` through WordPress itself, so no
 * arrangement of inventory permissions — including a premium role that revokes everything — can
 * lock the site owner out of the screen that undoes it.
 *
 * ONE APP PER REQUEST, deliberately. A payload of the whole map is a payload that can carry a
 * stale flag for an app the client did not know about, which is exactly how the notification
 * preferences screen wrote five rows for one click in P6. `{ id, enabled }` cannot express a
 * change nobody asked for.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class AppController extends RestController {

	public function __construct( private readonly AppCatalogue $catalogue ) {}

	protected function entity_type(): string {
		return 'settings';
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function can_manage( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return current_user_can( 'manage_options' ) ? true : $this->forbidden();
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/apps',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok(
			array(
				'apps'       => $this->catalogue->all(),
				'categories' => AppState::CATEGORIES,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->catalogue->set(
			(string) $request->get_param( 'id' ),
			(bool) $request->get_param( 'enabled' )
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->ok(
			array(
				'apps'       => $result,
				'categories' => AppState::CATEGORIES,
			)
		);
	}
}
