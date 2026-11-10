<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Notifications\Http;

use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Modules\Notifications\AlertType;
use PlugixaInventory\Modules\Notifications\Services\AlertSubscriptionService;
use PlugixaInventory\Modules\Notifications\Services\NotificationService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bell.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * EVERY ROUTE HERE IS ABOUT THE CALLER, AND THE CALLER IS TAKEN FROM THE SESSION — NEVER FROM THE
 * REQUEST.
 *
 * There is no `?user_id=` on any of these, and there must never be one. A notification is addressed
 * to a person, so "whose bell" is not a parameter to be validated but a fact WordPress already
 * knows. A route that accepted a user id would need a rule about who may read somebody else's
 * notifications, and the correct rule is that nobody may — which is expressed far more reliably by
 * having nowhere to say it.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * NOT A `CrudController`, and the absences are the point: there is no POST and no PUT for a
 * notification. The plugin writes them; a person reads, reads-all and dismisses. A route that
 * created one would let anybody put anything in anybody's bell.
 */
final class NotificationsController extends RestController {

	public function __construct(
		private readonly NotificationService $notifications,
		private readonly AlertSubscriptionService $subscriptions
	) {}

	protected function entity_type(): string {
		return 'notification';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/notifications',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'per_page' => array( 'type' => 'integer', 'required' => false, 'default' => 30 ),
						'unread'   => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/unread-count',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'unread_count' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/read-all',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'read_all' ),
					// Scoped to the caller by design: marks only the session user's own bell read.
					'permission_callback' => array( $this, 'can_use_own_bell' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'preferences' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'save_preferences' ),
					// Scoped to the caller by design: saves only the session user's own alert choices.
					'permission_callback' => array( $this, 'can_use_own_bell' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/(?P<id>\d+)/read',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'read' ),
					// The notification must belong to the session user; anybody else's is a 404.
					'permission_callback' => array( $this, 'can_act_on_own_notification' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'dismiss' ),
					// The notification must belong to the session user; anybody else's is a 404.
					'permission_callback' => array( $this, 'can_act_on_own_notification' ),
				),
			)
		);
	}

	/**
	 * May this user change their OWN bell — mark it all read, or save their alert choices?
	 *
	 * READ, AND NOT A WRITE CAPABILITY, and that is the design rather than an oversight. These write
	 * nothing but the caller's own state, keyed by the session user id; no route here accepts a user
	 * id, so there is nobody else's to reach. Gating them on `edit` would mean somebody who may use
	 * the plugin but change nothing in it could never clear their own notifications.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_use_own_bell( WP_REST_Request $request ): bool|WP_Error {
		return $this->can_read( $request );
	}

	/**
	 * May this user mark read, or dismiss, THIS notification?
	 *
	 * Only if it is theirs — checked HERE, against the row, before the request is processed. The
	 * service checks again. Not `can_delete` even for dismiss, for the reason `can_use_own_bell()`
	 * gives: dismissing clears your own bell, it does not delete a record.
	 *
	 * SOMEBODY ELSE'S NOTIFICATION IS "NOT FOUND", NOT "FORBIDDEN", matching the service: telling a
	 * stranger that a notification exists is itself a small leak, and no capability ever permits it.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_act_on_own_notification( WP_REST_Request $request ): bool|WP_Error {
		$allowed = $this->can_read( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		$row = $this->notifications->find_for( (int) $request['id'], get_current_user_id() );

		return null === $row ? $this->notifications->not_found() : true;
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok(
			$this->notifications->inbox(
				get_current_user_id(),
				(int) $request->get_param( 'per_page' ),
				(bool) $request->get_param( 'unread' )
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function unread_count( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( array( 'unread' => $this->notifications->unread_count( get_current_user_id() ) ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$done = $this->notifications->mark_read( (int) $request['id'], get_current_user_id() );

		return $done instanceof WP_Error ? $done : $this->ok( array( 'read' => true ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function read_all( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( array( 'read' => $this->notifications->mark_all_read( get_current_user_id() ) ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$done = $this->notifications->dismiss( (int) $request['id'], get_current_user_id() );

		return $done instanceof WP_Error ? $done : $this->ok( array( 'dismissed' => true ) );
	}

	/**
	 * The caller's alert preferences, and the vocabulary to render them.
	 *
	 * THE TYPES COME WITH THEIR LABELS AND DESCRIPTIONS, so the settings screen is built from what
	 * the server knows rather than from a second list in the client that drifts the day a type is
	 * added.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function preferences( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$types = array();

		foreach ( AlertType::all() as $type => $spec ) {
			$types[] = array(
				'type'        => $type,
				'label'       => $spec['label'],
				'description' => $spec['description'],
			);
		}

		return $this->ok(
			array(
				'types'       => $types,
				'preferences' => $this->subscriptions->for_user( get_current_user_id() ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_preferences( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}

		$preferences = isset( $body['preferences'] ) && is_array( $body['preferences'] )
			? $body['preferences']
			: (array) $body;

		$done = $this->subscriptions->save( get_current_user_id(), $preferences );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $this->ok( array( 'preferences' => $this->subscriptions->for_user( get_current_user_id() ) ) );
	}
}
