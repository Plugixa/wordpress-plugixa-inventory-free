<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Http;

use PlugixaInventory\Core\Entities\MassAction;
use PlugixaInventory\Core\Entities\RecordWriter;
use PlugixaInventory\Core\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One route for acting on many records of any registered type.
 *
 * IN CORE, NOT IN A MODULE, for the same reason `RecordWriter` and `RecordLoader` are: the entity is
 * only known at runtime, so no module owns it. It is also why there is ONE route rather than a bulk
 * endpoint per module — the writer registry already knows what can be written, and a per-module copy
 * would be nine chances to forget the permission check.
 */
final class MassActionController extends RestController {

	public function __construct(
		private readonly MassAction $actions,
		private readonly RecordWriter $writer
	) {}

	/**
	 * The entity is chosen per request, so there is no single one to name here.
	 *
	 * The base class wants this for its permission callbacks, which this controller deliberately does
	 * not use: the entity is only known per request, so `can_run()` below asks the entity-level
	 * question itself, and the per-record one stays inside {@see MassAction}.
	 */
	protected function entity_type(): string {
		return '';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/mass-action/(?P<entity_type>[a-z_]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run' ),
					'permission_callback' => array( $this, 'can_run' ),
				),
			)
		);
	}

	/**
	 * The entity-level gate: may you edit — or, for `delete`, delete — this kind of record at all.
	 *
	 * THIS USED TO ASK ONLY WHETHER THE ENTITY EXISTED, under a docblock that called it "are you signed
	 * in to the inventory at all". Nothing here checked that, so a signed-out visitor reached the
	 * service, which refused — but only after validating their action, ids and batch size and answering
	 * each in detail. The refusal belongs before any processing, which is what a permission callback is
	 * for. Plugixa CRM, whose Core this was ported from, found the same gap first.
	 *
	 * It asks the SAME entity-level question {@see MassAction::run()} asks, with the same mapping of
	 * action to capability, so an allowed user sees no change. The service keeps its own check, and the
	 * per-RECORD decision still happens there: a permission callback cannot see the ids.
	 * `RouteGuardTest` calls this as a signed-out visitor and as a subscriber.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_run( WP_REST_Request $request ): bool|WP_Error {
		$entity_type = sanitize_key( (string) $request['entity_type'] );

		if ( ! $this->writer->knows( $entity_type ) ) {
			return $this->forbidden();
		}

		$body       = (array) $request->get_json_params();
		$capability = 'delete' === ( $body['action'] ?? '' ) ? 'delete' : 'edit';

		return Capabilities::user_can( $capability, $entity_type ) ? true : $this->forbidden();
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();

		$result = $this->actions->run(
			sanitize_key( (string) $request['entity_type'] ),
			sanitize_key( (string) ( $body['action'] ?? '' ) ),
			(array) ( $body['ids'] ?? array() ),
			(array) ( $body['values'] ?? array() )
		);

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}
}
