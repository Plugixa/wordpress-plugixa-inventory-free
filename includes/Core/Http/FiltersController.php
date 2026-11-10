<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Http;

use PlugixaInventory\Core\Entities\EntityRegistry;
use PlugixaInventory\Core\Filters\FilterCatalogue;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a list may be filtered by — the drawer's source of truth.
 *
 * Core owns the endpoint and knows none of the fields: the entity's own module declares its
 * columns and CustomFields appends whatever has been defined, both through
 * `plugixa_inventory_filterable_fields`. The same seam Search and the dashboard use, for the same reason.
 *
 * AUTHORISED AGAINST THE LIST IT DESCRIBES, NOT AGAINST THIS CONTROLLER. A filter catalogue is not
 * harmless metadata: it carries the list's field names, the options of every select, and labels that
 * are a site's own data (department names, custom-field choices). So "may you see the filters of
 * recipients" is "may you read recipients", asked of the `entity_type` in the URL. It used to be
 * asked of `core`, this controller's own slug, which any role holder passes.
 *
 * THE ANSWER IS THEN NARROWED FIELD BY FIELD. `FilterCatalogue` drops fields the current user may
 * not read before this ever sees them, so two users on the same list can be offered different
 * filters — which is the point, and is why this must not be cached anywhere shared.
 */
final class FiltersController extends RestController {

	public function __construct(
		private readonly FilterCatalogue $catalogue,
		private readonly EntityRegistry $entities
	) {}

	/**
	 * The fallback for anything the base class asks outside a request. The route itself is
	 * authorised per entity by `can_read_filters()`.
	 */
	protected function entity_type(): string {
		return 'core';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/filters/(?P<entity_type>[a-z_]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					// Read on the entity named in the URL, not on this controller.
					'permission_callback' => array( $this, 'can_read_filters' ),
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
	 * May this user read the kind of record whose filters are asked for?
	 *
	 * The same question the list route itself asks, so the drawer is offered to exactly the people
	 * who can open the list it filters.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_filters( WP_REST_Request $request ): bool|WP_Error {
		return $this->allowed_on( 'read', sanitize_key( (string) $request['entity_type'] ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entity_type = (string) $request->get_param( 'entity_type' );
		$declared    = $this->catalogue->for( $entity_type );

		/*
		 * REGISTERED, OR HAVING DECLARED FILTERS. The second half admits lists that are not records:
		 * a module may declare filters through `plugixa_inventory_filterable_fields` for a slug it
		 * never registers as an entity (the notification bell does), and this is where that becomes
		 * reachable. The permission callback has already asked read of that same slug.
		 *
		 * It does not widen anything: a slug nobody declared filters for still has an empty catalogue
		 * and still 404s here, so a typo is answered exactly as before.
		 */
		if ( array() === $declared && ! $this->entities->has( $entity_type ) ) {
			return $this->fail(
				'plugixa_inventory_unknown_entity',
				__( 'There is no such record type.', 'plugixa-inventory' ),
				404
			);
		}

		// An empty list is a valid answer, not a 404: it is what every registered list that has not
		// declared any filters returns, and the client draws no Filters button for it.
		return $this->ok( $this->catalogue->present( $entity_type ) );
	}
}
