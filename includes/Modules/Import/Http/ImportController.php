<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Http;

use PlugixaInventory\Core\Http\RawResponse;
use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Modules\Import\Repositories\ImportRepository;
use PlugixaInventory\Modules\Import\Repositories\ImportRowRepository;
use PlugixaInventory\Modules\Import\Services\ImportService;
use PlugixaInventory\Modules\Import\Services\Importer;
use PlugixaInventory\Modules\Import\Services\ImportTargets;
use PlugixaInventory\Modules\Import\Services\Reverter;
use PlugixaInventory\Modules\Export\Services\CsvWriter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The import routes.
 *
 * THE RUN IS A ROW AND NOT A REQUEST, which is what shapes this. Uploading, mapping and running are
 * three calls against one server-side record, and running is called as many times as the file needs
 * — so every route here takes an id, and none of them holds state between calls. That is what makes
 * an import resumable after a closed browser rather than something that has to be started again.
 */
final class ImportController extends RestController {

	public function __construct(
		private readonly ImportService $service,
		private readonly Importer $importer,
		private readonly Reverter $reverter,
		private readonly ImportRepository $imports,
		private readonly ImportRowRepository $rows,
		private readonly ImportTargets $targets
	) {}

	protected function entity_type(): string {
		return 'import';
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function record_for( WP_REST_Request $request ): ?array {
		$id = (int) ( $request['id'] ?? 0 );

		return $id > 0 ? $this->imports->find( $id ) : null;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/imports',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'store' ),
					'permission_callback' => array( $this, 'can_create' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/imports/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					// The run's file and sample rows: create on the entity the run imports into.
					'permission_callback' => array( $this, 'can_view_run' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/imports/(?P<id>\d+)/mapping',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'map' ),
					// Create on the run's own entity; the service also asks edit for an update mode.
					'permission_callback' => array( $this, 'can_change_run' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/imports/(?P<id>\d+)/run',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run' ),
					// Writes records of the run's entity: create on it. Each row is checked again as written.
					'permission_callback' => array( $this, 'can_change_run' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/imports/(?P<id>\d+)/revert',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'revert' ),
					// Removes what the run created: delete on the run's own entity.
					'permission_callback' => array( $this, 'can_revert_run' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/imports/(?P<id>\d+)/errors',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'errors' ),
					// The run's problem rows, quoted from the file: create on the run's entity.
					'permission_callback' => array( $this, 'can_view_run' ),
				),
			)
		);

		// What can be imported at all, and what each column may become — the wizard's whole input.
		register_rest_route(
			$this->namespace,
			'/import-targets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'targets' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * May this user look at one run — its file's header, sample rows and problem rows?
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_view_run( WP_REST_Request $request ): bool|WP_Error {
		return $this->may_on_run( $request, 'read', 'create' );
	}

	/**
	 * May this user remap or run one run?
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_change_run( WP_REST_Request $request ): bool|WP_Error {
		return $this->may_on_run( $request, 'edit', 'create' );
	}

	/**
	 * May this user undo one run?
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_revert_run( WP_REST_Request $request ): bool|WP_Error {
		return $this->may_on_run( $request, 'delete', 'delete' );
	}

	/**
	 * The shared check behind the three callbacks above.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * A RUN IS ASKED ABOUT THE RECORDS IT WRITES, NOT ONLY ABOUT "IMPORT". The `import` slug alone
	 * means "may use the import screen at all" — in the premium Roles module, may create SOMETHING —
	 * so on its own it let somebody who may only add categories open a colleague's item import, read
	 * its sample rows, remap it and run it. The question has to be asked of the run's own
	 * `entity_type`, and it is the same question `ImportService::create()` and
	 * `Reverter::revert()` ask: create to bring rows in, delete to take them back out.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param WP_REST_Request $request       The request.
	 * @param string          $import_action What is asked of the `import` slug: read|edit|delete.
	 * @param string          $target_action What is asked of the run's entity type: create|delete.
	 */
	private function may_on_run( WP_REST_Request $request, string $import_action, string $target_action ): bool|WP_Error {
		/*
		 * The capability BEFORE the lookup. Loading first would answer "not found" for a missing id
		 * and "forbidden" for a real one, telling somebody with no import access which runs exist.
		 */
		$allowed = $this->allowed_on( $import_action, $this->entity_type() );

		if ( true !== $allowed ) {
			return $allowed;
		}

		$run = $this->record_for( $request );

		// A run that does not exist is the handler's 404 to give, not a refusal.
		if ( null === $run ) {
			return true;
		}

		// With the run itself, so record-level rules see it, and then of the records it writes.
		if (
			! Capabilities::user_can( $import_action, $this->entity_type(), $run )
			|| ! Capabilities::user_can( $target_action, (string) $run['entity_type'] )
		) {
			return $this->forbidden();
		}

		return true;
	}

	/**
	 * The import history.
	 *
	 * NO ROW DECORATOR, AND THAT IS THE FIX, NOT AN OMISSION. `paged_list()` already casts every row
	 * through `ImportRepository::cast()`, because the repository has one. This used to pass that same
	 * cast in again as the decorator, and the cast is not idempotent: the second pass turned the
	 * decoded `mapping` and `options` back into the string "Array" — two PHP warnings a row — and
	 * recomputed `has_file` from the `disk_name` the first pass had already removed. Every run was
	 * listed with an empty mapping and no file. `ImportListTest` compares a listed run with the run's
	 * own view and fails on either.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->paged_list(
			$request,
			$this->imports,
			array( 't.file_name' )
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$described = $this->service->describe( (int) $request['id'] );

		return $described instanceof WP_Error ? $described : $this->ok( $described );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) ) {
			return $this->fail( 'plugixa_inventory_no_file', __( 'No file was uploaded.', 'plugixa-inventory' ), 400 );
		}

		$created = $this->service->create( $file, (array) $request->get_params() );

		return $created instanceof WP_Error ? $created : $this->ok( $created, array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function map( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();

		$mapped = $this->service->map(
			(int) $request['id'],
			(array) ( $body['mapping'] ?? array() ),
			(array) ( $body['options'] ?? array() )
		);

		return $mapped instanceof WP_Error ? $mapped : $this->ok( $mapped );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$progress = $this->importer->run( (int) $request['id'] );

		return $progress instanceof WP_Error ? $progress : $this->ok( $progress );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function revert( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->reverter->revert( (int) $request['id'] );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Every problem this run had, as a file.
	 *
	 * A DOWNLOAD RATHER THAN A LONGER TABLE. Four hundred failures is not something anybody reads on
	 * screen, and truncating the list to fifty and calling it the report would hand somebody a file
	 * that silently omits most of what went wrong. Beside their own spreadsheet in a second sheet,
	 * four hundred rows keyed by line number is a thing they can actually work through.
	 *
	 * It returns a file rather than the usual JSON envelope, which is the one place a controller in
	 * this plugin does not go through `ok()` — the marker header is what tells Core to send the body
	 * untouched, and without it the downloaded .csv would contain a quoted JSON string.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function errors( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		$import = $this->imports->find( $id );

		if ( null === $import ) {
			return $this->service->not_found();
		}

		$rows = array();

		foreach ( $this->rows->all_problems( $id ) as $problem ) {
			$rows[] = array(
				(int) $problem['line_number'],
				(string) $problem['action'],
				(string) $problem['message'],
			);
		}

		$csv = CsvWriter::build(
			array(
				__( 'Line', 'plugixa-inventory' ),
				__( 'What happened', 'plugixa-inventory' ),
				__( 'Detail', 'plugixa-inventory' ),
			),
			$rows
		);

		$response = new WP_REST_Response( $csv );

		$response->header( RawResponse::HEADER, '1' );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		// Built from an id and a date, never from the uploaded file's name — a name carrying a quote
		// or a newline would break out of this header.
		$response->header( 'Content-Disposition', 'attachment; filename="' . sprintf( 'import-%d-problems-%s.csv', $id, gmdate( 'Y-m-d' ) ) . '"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function targets( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( $this->targets->all() );
	}
}
