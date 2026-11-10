<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock\Http;

use PlugixaInventory\Core\Http\RestController;
use PlugixaInventory\Core\Security\Capabilities;
use PlugixaInventory\Core\Settings;
use PlugixaInventory\Modules\Stock\Module;
use PlugixaInventory\Modules\Stock\Services\StockEngine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock health: does everything the plugin says about quantities still follow from the ledger?
 *
 * THREE ROUTES, AND THE INTERESTING PART IS WHAT SEPARATES THEM.
 *
 *   GET  /stock/health       — the last audit's findings. Reading a report is reading: `can_read`.
 *   POST /stock/reconcile    — run the comparison now: `can_reconcile`, the settings capability,
 *                              plus `manage_options` when it is asked to correct anything.
 *   POST /stock/seed-layers  — open cost layers for stock that predates the costing method:
 *                              `can_seed`, the settings capability.
 *
 * NEITHER POST IS A READ, AND NEITHER IS GATED LIKE ONE. Both take the stock lock, which every
 * finalize on the site waits for, and seeding writes rows. They are asked of the capability that
 * chooses the costing method, `plugixa_inventory_manage_settings` — the same check
 * `SettingsController::can_manage_settings()` makes — because choosing a layer method is what
 * queues a seed in the first place, and the button only brings forward what the cron would do.
 *
 * `POST /stock/reconcile` WITHOUT `apply` WRITES NOTHING, which is why it is a POST rather than a
 * GET anyway: it takes the stock lock and walks every pair on the site, and a GET is allowed to be
 * retried, prefetched and cached by things that assume it is cheap and idempotent. It is idempotent;
 * it is not cheap.
 *
 * `apply` AND `repair_balances` ADDITIONALLY REQUIRE `manage_options`. Correcting a stock quantity
 * is not an inventory operation — it is an admission that something wrote outside the engine — and
 * the person who does it should be the person who would be called about it. The CLI exists so the
 * heavier flag has a home that is not a browser tab.
 *
 * EACH ROUTE HAS ITS OWN CALLBACK. Seeding used to share `can_reconcile()`, whose only escalation
 * is on those two flags — which the seed route does not declare, so for it the callback asked
 * nothing beyond "may read". A callback whose answer depends on request parameters is only correct
 * on the route that declares them.
 */
final class StockController extends RestController {

	public function __construct( private readonly StockEngine $engine ) {}

	protected function entity_type(): string {
		return 'transaction';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/stock/health',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'health' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/stock/reconcile',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'reconcile' ),
					// Settings capability; `manage_options` as well when `apply` or `repair_balances` is set.
					'permission_callback' => array( $this, 'can_reconcile' ),
					'args'                => array(
						'item'            => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'apply'           => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
						'repair_balances' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/stock/seed-layers',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'seed' ),
					// Writes cost layers: the settings capability, which is what chose the costing method.
					'permission_callback' => array( $this, 'can_seed' ),
					'args'                => array(
						'item' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Whether this user may run a reconcile, and whether they may let it write.
	 *
	 * ONE CALLBACK FOR BOTH QUESTIONS, because a permission callback that answered only the first
	 * would leave the second to the handler — and a check that lives in a handler is a check the
	 * next route added to this file will forget. It is registered on `/stock/reconcile` ONLY: the
	 * escalation below reads `apply` and `repair_balances`, which no other route declares.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_reconcile( WP_REST_Request $request ): bool|WP_Error {
		$base = $this->can_seed( $request );

		if ( true !== $base ) {
			return $base;
		}

		$writes = (bool) $request->get_param( 'apply' ) || (bool) $request->get_param( 'repair_balances' );

		if ( $writes && ! current_user_can( 'manage_options' ) ) {
			return $this->fail(
				'plugixa_inventory_forbidden',
				__( 'Correcting stock quantities is restricted to administrators.', 'plugixa-inventory' ),
				403
			);
		}

		return true;
	}

	/**
	 * Whether this user may open cost layers — and the floor under a reconcile.
	 *
	 * THE CAPABILITY THAT CHOOSES THE COSTING METHOD, because that choice is what makes a seed
	 * necessary: switching to a layer method queues exactly this run on the cron. The button only
	 * brings it forward, so it needs no more than the switch did, and no less — seeding writes
	 * layers and takes the lock every finalize waits on, which is not something reading grants.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_seed( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return current_user_can( Capabilities::MANAGE_SETTINGS ) ? true : $this->forbidden();
	}

	/**
	 * What the last daily audit found.
	 *
	 * READS AN OPTION RATHER THAN RUNNING ANYTHING, which is the point: this is what the Stock
	 * health panel loads on open, and a screen that reconciled the whole site every time somebody
	 * glanced at it would take the stock lock on page load.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$audit = (array) get_option( Module::AUDIT_OPTION, array() );

		return $this->ok(
			array(
				'costing_method' => (string) Settings::get( 'inventory_costing_method', 'standard' ),
				'last_audit'     => array() === $audit ? null : $audit,
				'next_audit'     => wp_next_scheduled( Module::AUDIT_HOOK ) ?: null,
				// A pending seed is a real state the user can be in — they switched to FIFO and the
				// cron has not fired yet — and it is the difference between "no layers yet" meaning
				// "wait a moment" and meaning "something is wrong".
				'seed_pending'   => (bool) wp_next_scheduled( Module::SEED_HOOK ),
				/*
				 * WHAT THE PANEL'S "OPEN THEM NOW" ALERT IS DRIVEN FROM, NOT `seed_pending`. Previously the
				 * alert showed only while an event was queued, so a run that failed or stopped at its
				 * cap consumed the event and took the button with it — at exactly the moment stock was
				 * still waiting. This is the state itself: pairs holding stock with nothing to cost it
				 * from. Zero under average costing.
				 */
				'unseeded_pairs' => $this->engine->unseeded_costing_pairs(),
				'orphan_batches' => $this->engine->orphan_batches(),
			)
		);
	}

	/**
	 * Compare every denormalised quantity against the ledger.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function reconcile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item = (int) ( $request->get_param( 'item' ) ?? 0 );

		$report = $this->engine->rebuild(
			$item > 0 ? $item : null,
			(bool) $request->get_param( 'apply' ),
			(bool) $request->get_param( 'repair_balances' )
		);

		if ( $report instanceof WP_Error ) {
			return $report;
		}

		return $this->ok( $report );
	}

	/**
	 * Open cost layers for stock that predates the costing method.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function seed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item = (int) ( $request->get_param( 'item' ) ?? 0 );

		$report = $this->engine->seed_costing_layers( $item > 0 ? $item : null );

		if ( $report instanceof WP_Error ) {
			return $report;
		}

		return $this->ok( $report );
	}
}
