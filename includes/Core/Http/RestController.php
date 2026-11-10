<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Http;

use PlugixaInventory\Core\Database\Repository;
use PlugixaInventory\Core\Security\Capabilities;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base REST controller.
 *
 * Controllers are deliberately thin: they authorise, coerce request data, hand off
 * to a service, and shape the response. Business logic belongs in the service layer,
 * so that CSV import, premium workflows and REST all reach the same code.
 */
abstract class RestController extends WP_REST_Controller {

	/**
	 * REST namespace shared by the whole plugin.
	 */
	protected $namespace = 'plugixa-inventory/v1';

	/**
	 * The entity this controller serves, used for authorisation.
	 *
	 * register_routes() is not redeclared here: WP_REST_Controller already defines it
	 * concretely, and PHP forbids a child from making an inherited concrete method
	 * abstract. Subclasses simply override it.
	 */
	abstract protected function entity_type(): string;

	/**
	 * Permission callback for read routes.
	 *
	 * The REST cookie nonce (X-WP-Nonce) is verified by WordPress itself before this
	 * runs, so authentication is settled here and only authorisation remains.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return Capabilities::user_can( 'read', $this->entity_type() )
			? true
			: $this->forbidden();
	}

	/**
	 * Permission callback for create routes.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_create( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return Capabilities::user_can( 'create', $this->entity_type() )
			? true
			: $this->forbidden();
	}

	/**
	 * Permission callback for update routes.
	 *
	 * ALSO THE CONCURRENCY CHECK, because this is the one place in the plugin that has both the
	 * request and the current row in hand for every entity — `record_for()` has already loaded it to
	 * answer the record-level permission question. Refusing here costs no service or repository
	 * changes and covers every controller at once. See {@see Concurrency} for why a save that
	 * silently overwrites somebody else's is worth a route of its own to prevent.
	 *
	 * Permission first: whether you may touch this record at all is a more fundamental answer than
	 * whether your copy of it is current, and a user who may not edit it should not learn from the
	 * error message that somebody else just did.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_edit( WP_REST_Request $request ): bool|WP_Error {
		$record = $this->record_for( $request );

		if ( ! Capabilities::user_can( 'edit', $this->entity_type(), $record ) ) {
			return $this->forbidden();
		}

		return Concurrency::check( $request, $record );
	}

	/**
	 * Permission callback for delete routes.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_delete( WP_REST_Request $request ): bool|WP_Error {
		return Capabilities::user_can( 'delete', $this->entity_type(), $this->record_for( $request ) )
			? true
			: $this->forbidden();
	}

	/**
	 * Whether the current user may perform one action on EVERY one of these entity types.
	 *
	 * FOR THE ROUTES THAT ANSWER WITH MORE THAN ONE KIND OF RECORD. A permission callback that asks
	 * only about the controller's own slug authorises the route by its name rather than by what it
	 * returns: stock levels are item data served by the ledger controller, and a warehouse's stock
	 * feed is item data served by the warehouse one. Asking about each type the response contains
	 * means a role that may not read items does not read them through a route named after something
	 * else.
	 *
	 * @param string $action          read|create|edit|delete|export.
	 * @param string ...$entity_types Every entity type the response is made of.
	 */
	protected function allowed_on( string $action, string ...$entity_types ): bool|WP_Error {
		foreach ( $entity_types as $entity_type ) {
			if ( ! Capabilities::user_can( $action, $entity_type ) ) {
				return $this->forbidden();
			}
		}

		return true;
	}

	/**
	 * Load the record a request targets, so record-level checks can see it.
	 *
	 * Overridden by controllers that operate on a single record.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function record_for( WP_REST_Request $request ): ?array {
		unset( $request );

		return null;
	}

	/**
	 * A successful response.
	 *
	 * @param mixed                $data    Payload.
	 * @param array<string, mixed> $meta    Pagination or other metadata.
	 * @param int                  $status  HTTP status.
	 */
	protected function ok( mixed $data, array $meta = array(), int $status = 200 ): WP_REST_Response {
		/**
		 * Filter a successful payload before it leaves the building.
		 *
		 * THE ONE PLACE FIELD-LEVEL ACCESS CAN BE ENFORCED. Every route in the plugin answers
		 * through this method, so a rule applied here cannot be forgotten by a controller — and a
		 * field-level rule that one controller forgets is a field quietly readable through one
		 * route, which looks exactly like a field that was allowed.
		 *
		 * Core has no opinion about which fields those are. The Roles module answers this.
		 *
		 * @param mixed  $data        The payload.
		 * @param string $entity_type The controller's entity slug.
		 */
		$data = apply_filters( 'plugixa_inventory_filter_output', $data, $this->entity_type() );

		$body = array(
			'success' => true,
			'data'    => $data,
		);

		if ( $meta ) {
			$body['meta'] = $meta;
		}

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * An error response.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 */
	protected function fail( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * The standard 403.
	 */
	protected function forbidden(): WP_Error {
		return $this->fail(
			'plugixa_inventory_forbidden',
			__( 'You are not allowed to do that.', 'plugixa-inventory' ),
			rest_authorization_required_code()
		);
	}

	/**
	 * Pagination arguments, clamped.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array{limit: int, offset: int, orderby: string, order: string, search: string}
	 */
	protected function list_args( WP_REST_Request $request ): array {
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 50 );
		$page     = (int) ( $request->get_param( 'page' ) ?? 1 );

		$per_page = max( 1, min( 200, $per_page ) );
		$page     = max( 1, $page );

		return array(
			'limit'   => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => (string) ( $request->get_param( 'orderby' ) ?? 'id' ),
			'order'   => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
			'search'  => sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) ),
		);
	}

	/**
	 * A paged, searchable, trash-aware list — the shape `EntityList` requires.
	 *
	 * WHY THIS IS IN CORE. Five controllers had written, in full:
	 *
	 *     unset( $request );
	 *     return $this->ok( $this->service->all() );
	 *
	 * No paging, no search, no `view=trash`, and a bare array rather than the `{items, total}`
	 * envelope — so none of them could be rendered by `EntityList`, which is why every premium list
	 * in the plugin was a hand-rolled table with none of its behaviour. The free controllers each
	 * grew their own correct version instead, which is four more copies of the same twenty lines.
	 * Nine more document modules are about to need it.
	 *
	 * The two things that are genuinely per-entity stay parameters: which columns a search looks in,
	 * and any extra narrowing. Everything else — the clamped paging, the trash view, the safe sort
	 * column, the total, the page count — is the same everywhere and is decided here.
	 *
	 * `$narrow` may return a `WP_Error` to REFUSE the request rather than narrow it. That matters
	 * for a filter that defines what a page is: silently ignoring an unrecognised value turns a
	 * screen showing one kind of record into one showing every kind, which looks like a working page.
	 *
	 * `$decorate` adds whatever a row needs that is not a column — the members on a team, the
	 * `editable` flag a list reads to decide whether to draw a pencil. Without it this returns the
	 * table and nothing else, which is a silent regression for any list that was showing more than
	 * the table: the column simply renders empty.
	 *
	 * @param WP_REST_Request $request        The request.
	 * @param Repository      $repository     The entity's repository.
	 * @param string[]        $search_columns Columns a search matches, e.g. `array( 't.name' )`.
	 * @param callable|null   $narrow         fn(QueryBuilder, WP_REST_Request): ?WP_Error.
	 * `$decorate_page` is the same idea for work that CANNOT be done a row at a time. Resolving the
	 * name behind a foreign key is the case that forced it: done per row it is one query per row per
	 * column, and a fifty-row page of stock movements was costing ninety-five queries to render
	 * three names. See `resolve_names()`.
	 *
	 * @param callable|null   $decorate       fn(array $row): array, applied to every row.
	 * @param callable|null   $decorate_page  fn(array $rows): array, applied once to the whole page.
	 */
	protected function paged_list(
		WP_REST_Request $request,
		Repository $repository,
		array $search_columns = array(),
		?callable $narrow = null,
		?callable $decorate = null,
		?callable $decorate_page = null
	): WP_REST_Response|WP_Error {
		$args  = $this->list_args( $request );
		$query = $repository->query();

		if ( 'trash' === $request->get_param( 'view' ) ) {
			$query->only_trashed();
		}

		if ( null !== $narrow ) {
			$refused = $narrow( $query, $request );

			if ( $refused instanceof WP_Error ) {
				return $refused;
			}
		}

		// BEFORE count(), which is the next line and must see the same conditions the page will.
		// The empty-term and empty-column guards moved into search() — and dropping the second one
		// here is deliberate: a list whose entity declares no searchable column of its own can still
		// gain one from a module, and the old guard would have skipped the whole clause.
		$query->search( $args['search'], $search_columns );

		$total = $query->count();

		$rows = $query
			->order_by( 't.' . $repository->safe_sort_column( $args['orderby'] ), $args['order'] )
			->paginate( $args['limit'], $args['offset'] )
			->get();

		// Cast when the repository knows how. MySQL hands every column back as a string, and "0" is
		// truthy in JavaScript — a client testing an id to decide whether to show something is right
		// about the rows that have one and wrong about the rest.
		if ( method_exists( $repository, 'cast' ) ) {
			$rows = array_map( array( $repository, 'cast' ), $rows );
		}

		if ( null !== $decorate ) {
			$rows = array_map( $decorate, $rows );
		}

		if ( null !== $decorate_page ) {
			$rows = $decorate_page( $rows );
		}

		return $this->ok(
			$rows,
			array(
				'total' => $total,
				'pages' => (int) ceil( $total / max( 1, $args['limit'] ) ),
			)
		);
	}

	/**
	 * The collection query parameters every list route accepts.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 50,
				'minimum' => 1,
				'maximum' => 200,
			),
			'search'   => array( 'type' => 'string' ),
			'orderby'  => array( 'type' => 'string' ),
			'order'    => array(
				'type' => 'string',
				'enum' => array( 'asc', 'desc' ),
			),
		);
	}
	/**
	 * Fill in the names behind a page's foreign keys, in one lookup per entity type.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE N+1 THIS EXISTS TO KILL. Every list that showed a name behind an id resolved it inside a
	 * per-row decorator:
	 *
	 *     $names = apply_filters( 'plugixa_inventory_record_names', array(), 'item', array( $id ) );
	 *
	 * — one filter call, and one query, per row per column. Measured before this landed: a 50-row
	 * page of stock movements ran 95 queries, 27 transfers ran 38, and the ledger ran 21 for 9 rows.
	 * The comment above one of those decorators said "resolved per row rather than joined: names()
	 * is one batched query per entity type", which was true of the METHOD and false of the way it
	 * was being called — it was handed one id at a time.
	 *
	 * The filter has always taken an ARRAY of ids. Nothing had to change on the answering side; only
	 * the asking, which had to move from per-row to per-page.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * THE OUTPUT KEY IS DECLARED, NOT DERIVED FROM THE ENTITY. Two shapes make derivation wrong: a
	 * transfer has `from_warehouse_id` AND `to_warehouse_id`, both warehouses, and an item's
	 * `default_warehouse_id` is published as `warehouse_name`. Ids are still pooled per ENTITY, so
	 * the transfer's two ends cost one lookup between them.
	 *
	 * A MISSING NAME IS NULL, NOT AN ABSENT KEY. A row whose warehouse was deleted still gets
	 * `warehouse_name => null`, so the client renders its em dash rather than reading `undefined`
	 * and rendering nothing — and so the key set does not vary row to row, which is what
	 * `DecoratedRecordTest` compares.
	 *
	 * @param array<int, array<string, mixed>>          $rows The page.
	 * @param array<string, array{0: string, 1: string}> $map  Output key => [id column, entity slug].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function resolve_names( array $rows, array $map ): array {
		/** Every id we need, pooled by entity so one entity is one lookup however many columns use it. */
		$wanted = array();

		foreach ( $map as list( $field, $entity ) ) {
			foreach ( $rows as $row ) {
				$id = (int) ( $row[ $field ] ?? 0 );

				if ( $id > 0 ) {
					$wanted[ $entity ][ $id ] = true;
				}
			}
		}

		$names = array();

		foreach ( $wanted as $entity => $ids ) {
			$names[ $entity ] = (array) apply_filters(
				'plugixa_inventory_record_names',
				array(),
				$entity,
				array_keys( $ids )
			);
		}

		foreach ( $rows as $index => $row ) {
			foreach ( $map as $out => list( $field, $entity ) ) {
				$id = (int) ( $row[ $field ] ?? 0 );

				$rows[ $index ][ $out ] = $id > 0 ? ( $names[ $entity ][ $id ] ?? null ) : null;
			}
		}

		return $rows;
	}
}
