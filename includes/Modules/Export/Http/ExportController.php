<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Export\Http;

use PlugixaInventory\Core\Http\RawResponse;
use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Modules\Export\Services\EntityExporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Export button that sits on every list screen.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * ONE ROUTE, AND IT USED TO LIVE IN THE REPORTS CONTROLLER. That controller's own comment said why
 * it should not: *"NOT UNDER `/reports/`, because it is not one. This is the button on every list
 * screen, and it belongs to the entity being exported rather than to this module — Reports only
 * owns it because Reports is where the CSV writer lives."*
 *
 * It stopped being a note about tidiness the moment Reports became premium. Exporting a list is the
 * counterpart of importing one, import is free, and a free build whose every list grew an Export
 * button that answered `rest_no_route` would be worse than one with no button at all. So the CSV
 * writer, the exporter and this route moved to a module of their own, and Reports keeps the routes
 * that really are reports.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHAT IT MUST NEVER DO is let a request name a column. The entity type is looked up in the entity
 * registry and an unknown one is a 400 before any query runs; `EntityExporter` chooses the columns
 * from the registered descriptor. Nothing from the wire reaches a column name.
 */
final class ExportController extends RestController {

	public function __construct(
		private readonly EntityExporter $exporter
	) {}

	/**
	 * THE ENTITY BEING EXPORTED, NOT A TYPE OF ITS OWN.
	 *
	 * There is no `export` entity to check against — the permission question is always "may you
	 * export ITEMS", answered per request in `can_export()` below from the URL. This is only the
	 * fallback for anything the base class asks outside a request.
	 */
	protected function entity_type(): string {
		return 'report';
	}

	/**
	 * Permission callback: may this user export, AND read, the entity named in the URL?
	 *
	 * A SEPARATE ACTION FROM `read`, because they are separately grantable and the difference is
	 * not academic: reading a total on screen and walking out with the row-level file behind it are
	 * different amounts of trust, and `Capabilities::user_can()` has carried `export` in its
	 * documented vocabulary since it was written. Free answers both with a capability each; the
	 * premium Roles module answers them per entity.
	 *
	 * BOTH ARE ASKED OF THE ENTITY IN THE URL, AND BOTH ARE ASKED HERE. This used to ask `export` of
	 * this controller's own slug and leave "may you read THIS kind of record" to the handler — a
	 * route authorised by its name while the question that mattered waited until the request was
	 * already being processed. Exporting widens what you may do with what you can see, never what
	 * you can see, so an entity you may not read is not one you may export.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_export( WP_REST_Request $request ): bool|WP_Error {
		$entity_type = sanitize_key( (string) $request['entity_type'] );

		$read = $this->allowed_on( 'read', $entity_type );

		return true === $read ? $this->allowed_on( 'export', $entity_type ) : $read;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/export/(?P<entity_type>[a-z_]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'export_entity' ),
					// Read AND export on the entity named in the URL.
					'permission_callback' => array( $this, 'can_export' ),
					'args'                => array(
						'entity_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * One entity's list, as CSV.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_entity( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Already authorised against this entity, for both read and export, by `can_export()`.
		$entity_type = sanitize_key( (string) $request['entity_type'] );

		$export = $this->exporter->export( $entity_type, (string) ( $request->get_param( 'search' ) ?? '' ) );

		if ( null === $export ) {
			return $this->fail(
				'plugixa_inventory_unknown_entity',
				__( 'There is nothing of that type to export.', 'plugixa-inventory' ),
				404
			);
		}

		return RawResponse::csv( $export['filename'], $export['csv'] );
	}
}
