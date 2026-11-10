<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Http;

use PlugixaInventory\Core\Entities\EntityRegistry;
use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One request that seeds the whole admin app.
 *
 * The React app needs the entity catalogue, the current user's permissions and the
 * assignable-user list before it can render anything. Fetching those separately
 * costs three round trips on every page load, so they are served together and the
 * client seeds its query cache from the result.
 */
final class BootstrapController extends RestController {

	public function __construct( private readonly EntityRegistry $entities ) {}

	protected function entity_type(): string {
		return 'core';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/bootstrap',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_bootstrap' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/meta/entities',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_entities' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_bootstrap( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$payload = array(
			'entities'    => $this->entities->all(),
			'permissions' => Capabilities::map(),
			'user'        => $this->current_user(),
			// Settings::all() rather than the raw option: a site whose option row is missing
			// (activation hook skipped by a file-copy update) would otherwise boot the app
			// with no currency at all instead of the default.
			'settings'    => Settings::all(),
		);

		/**
		 * Filter the admin application's startup payload.
		 *
		 * Premium modules add their own datasets here rather than making the client
		 * issue extra requests.
		 *
		 * @param array<string, mixed> $payload The bootstrap payload.
		 */
		$payload = (array) apply_filters( 'plugixa_inventory_bootstrap_payload', $payload );

		return $this->ok( $payload );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_entities( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( $this->entities->all() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function current_user(): array {
		$user = wp_get_current_user();

		return array(
			'id'     => $user->ID,
			'name'   => $user->display_name,
			'email'  => $user->user_email,
			'avatar' => get_avatar_url( $user->ID ),
		);
	}
}
