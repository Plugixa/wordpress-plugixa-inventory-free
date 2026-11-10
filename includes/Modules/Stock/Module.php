<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Stock;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Core\Settings;
use PlugixaInventory\Modules\Items\Repositories\ItemRepository;
use PlugixaInventory\Modules\Stock\Http\StockController;
use PlugixaInventory\Modules\Stock\Http\TransactionsController;
use PlugixaInventory\Modules\Stock\Repositories\ItemStockRepository;
use PlugixaInventory\Modules\Stock\Repositories\TransactionRepository;
use PlugixaInventory\Modules\Stock\Services\Costing\CostingStrategy;
use PlugixaInventory\Modules\Stock\Services\Costing\StandardCosting;
use PlugixaInventory\Modules\Stock\Cli\StockCli;
use PlugixaInventory\Modules\Stock\Services\StockEngine;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock: how much there is, where, and every change ever made to it.
 *
 * Owns `item_stock` and `transactions`, and the engine that writes them. The three document
 * modules — StockMovements, Transfers, Adjustments — each depend on this one; none of them depends
 * on another, and this one depends on none of them.
 *
 * IT ALSO ANSWERS THE OTHER MODULES' DELETE GUARDS. Warehouses, Recipients and Items each ask a
 * blockers filter before trashing a row, and each has no idea stock exists. This is the module that
 * knows, so this is where the answers live — which is what lets those three be written, and shipped,
 * before this one existed.
 */
final class Module implements ModuleInterface, AppInterface {

	/**
	 * The costing method every build has, and the one a site falls back to.
	 *
	 * `StandardCosting` keeps no layers: it costs a removal at the item's own price, which is what
	 * an average-cost inventory means. It is the default and it is always available.
	 */
	public const DEFAULT_METHOD = 'standard';

	/**
	 * The costing methods this site can choose between.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * A LIST, NOT A CONSTANT, BECAUSE A METHOD IS A CAPABILITY RATHER THAN A SETTING VALUE.
	 *
	 * This was `const COSTING_METHODS = array( 'standard', 'fifo', 'lifo' )`, and it was read in
	 * three places: to validate a save, to offer the choices, and — implicitly — by the `match`
	 * in `registerServices()` that turned a name into an object. That was fine while one module
	 * implemented all three. It stops being fine the moment layer costing is something a build may
	 * not have: the select would offer FIFO, the sanitizer would accept it, and the container
	 * would hand back average costing anyway. The setting would say one thing and the stock engine
	 * would do another, silently, on every valuation the site ever ran.
	 *
	 * So a method exists when something implements it. Whoever adds a name here must also answer
	 * `plugixa_inventory_costing_strategy` with an object, or the fallback below quietly wins.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return string[]
	 */
	public static function costing_methods(): array {
		/**
		 * Filter the costing methods on offer.
		 *
		 * @param string[] $methods Method keys. `standard` is always first and cannot be removed.
		 */
		$methods = (array) apply_filters( 'plugixa_inventory_costing_methods', array( self::DEFAULT_METHOD ) );

		$methods = array_values( array_unique( array_filter( $methods, 'is_string' ) ) );

		// Always offered, and always first: it is the fallback, so a list without it would let the
		// settings screen refuse the one method that cannot fail.
		return array_merge(
			array( self::DEFAULT_METHOD ),
			array_diff( $methods, array( self::DEFAULT_METHOD ) )
		);
	}

	/** The daily reconcile. Reports drift; never repairs it. */
	public const AUDIT_HOOK = 'plugixa_inventory_stock_audit';

	/** A one-off event that opens cost layers for stock that predates the costing method. */
	public const SEED_HOOK = 'plugixa_inventory_seed_layers';

	/** Which costing method the opening balances were last seeded for. See the transition listener. */
	public const SEEDED_OPTION = 'plugixa_inventory_costing_seeded_for';

	/** Where the last audit's findings are parked for the Stock health panel to read. */
	public const AUDIT_OPTION = 'plugixa_inventory_stock_audit_report';

	public function id(): string {
		return 'stock';
	}

	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 60,
			'category'  => 'core',
			'core'      => true,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_stock',
			'desc_key'  => 'app_stock_desc',
			'icon'      => 'history',
			'cron'      => array(
				self::AUDIT_HOOK,
				self::SEED_HOOK,
			),
		);
	}

	/**
	 * @return string[]
	 */
	public function usage( Container $container ): array {
		unset( $container );

		return array();
	}


	/**
	 * The card's name and description, from this module's OWN catalogue.
	 *
	 * Read back out of `Strings::all()` rather than written again here: the pair is registered
	 * there so `bin/generate-strings.php` and the .pot both see it, and a second copy would be a
	 * second thing to keep in step.
	 *
	 * @return array{label: string, desc: string}
	 */
	public function labels(): array {
		$strings = Strings::all();

		return array(
			'label' => (string) ( $strings['app_stock'] ?? 'stock' ),
			'desc'  => (string) ( $strings['app_stock_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	public function migrate(): void {
		Schema::install();
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	/**
	 * The one explicit binding in the whole plugin.
	 *
	 * `CostingStrategy` is an INTERFACE, and the container autowires concrete classes only — it
	 * cannot guess which implementation an interface means. Everything else in this module
	 * (StockEngine, StockLock, both repositories) is a concrete class resolved from its constructor
	 * types with no help.
	 *
	 * `share()` rather than a factory called per resolution: the strategy holds a per-request cache
	 * of the layers it has touched, and two instances would each hold half of it.
	 *
	 * @param Container $container The plugin container.
	 */
	public function registerServices( Container $container ): void {
		$container->share(
			CostingStrategy::class,
			static function ( Container $c ): CostingStrategy {
				$method = (string) Settings::get( 'inventory_costing_method', self::DEFAULT_METHOD );

				/**
				 * Filter the costing method in force.
				 *
				 * Overrides the stored setting without the settings screen learning about it —
				 * for a site pinned to one method by policy, or a test.
				 *
				 * @param string $method One of `Module::costing_methods()`.
				 */
				$method = (string) apply_filters( 'plugixa_inventory_costing_method', $method );

				/**
				 * Filter the object that costs stock.
				 *
				 * ═══════════════════════════════════════════════════════════════════════════════
				 * THE SEAM ITSELF, AND IT HANDS BACK AN OBJECT RATHER THAN A NAME.
				 *
				 * There used to be a `match` here mapping 'fifo' and 'lifo' onto a layer-costing
				 * class, and a filter on the NAME above described as the premium seam. It was not
				 * one: a name filter can only choose between implementations this file already
				 * names, so the module supplying the implementation had to be the module that
				 * consumes it. Free code would have to name premium code — a fatal on a build
				 * where that class is not on disk, and the exact thing `CoreIndependenceTest`
				 * forbids.
				 *
				 * Answering with the object inverts it. Whoever implements a method constructs it,
				 * from its own dependencies, and this module never learns the class exists.
				 *
				 * A LISTENER MUST CHECK `$method` AND PASS THROUGH WHAT IS NOT ITS OWN. The filter
				 * runs for every method including `standard`, so a listener that answers
				 * unconditionally takes over costing for a site that asked for something else.
				 * ═══════════════════════════════════════════════════════════════════════════════
				 *
				 * @param CostingStrategy|null $strategy  What will cost stock, or null for the default.
				 * @param string               $method    The method in force.
				 * @param Container            $container The plugin container, to resolve dependencies from.
				 */
				$strategy = apply_filters( 'plugixa_inventory_costing_strategy', null, $method, $c );

				/*
				 * ANYTHING UNANSWERED FALLS BACK TO AVERAGE COSTING rather than throwing. Reaching
				 * here with a method nothing implements means either a stored setting left behind
				 * by a build that has been removed, or a filter that returned a name and no object
				 * — and a site whose stock engine refuses to boot because of a stale option is a
				 * worse failure than one that costs at average until somebody notices.
				 */
				$strategy = $strategy instanceof CostingStrategy ? $strategy : new StandardCosting();

				self::track_costing( $strategy, $method );

				return $strategy;
			}
		);

	}

	/**
	 * Keep the seed marker true to what is actually costing stock on this request.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * IN THE FACTORY, BECAUSE THIS IS THE ONE PLACE THAT KNOWS WHAT IS REALLY IN FORCE.
	 *
	 * The settings listener below sees a SAVE. It cannot see the two transitions that happen without
	 * one, and both were real before this, reproduced on Linux:
	 *
	 *   A site on layer costing goes back to the free build. The stored method still names a layer
	 *   method, nothing implements it, and this factory falls back to average costing — while the
	 *   marker still claimed the layers had been seeded. Stock then moved for weeks with nothing
	 *   keeping the layers in step.
	 *
	 *   The same site comes back to the premium build. The stored method is suddenly implemented
	 *   again, no save happens, and the old layers were simply trusted.
	 *
	 * Every stock write resolves the strategy first, so running here puts the bookkeeping ahead of
	 * any quantity that could move. On a site whose marker already agrees — every request of a
	 * settled site — this is one cached option read and nothing else.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param CostingStrategy $strategy What will cost stock on this request.
	 * @param string          $method   The method in force.
	 */
	private static function track_costing( CostingStrategy $strategy, string $method ): void {
		$marker = (string) get_option( self::SEEDED_OPTION, '' );

		if ( ! $strategy->active() ) {
			if ( '' !== $marker ) {
				self::forget_seed();
			}

			return;
		}

		if ( $marker !== $method ) {
			self::queue_seed( $method, '' === $marker );
		}
	}

	/**
	 * Layer costing has stopped being in force: nothing is keeping layers in step from here on.
	 *
	 * WRITTEN AS AN EMPTY STRING AND AUTOLOADED rather than deleted, so the factory's check above
	 * stays a cached read on every request instead of becoming a query on the ones with no marker.
	 */
	private static function forget_seed(): void {
		update_option( self::SEEDED_OPTION, '', true );
	}

	/**
	 * A method that keeps layers is in force: open balances in the background, and say why.
	 *
	 * @param string $method  The method.
	 * @param bool   $restart Whether layer costing is coming BACK after a stretch without it, so
	 *                        whatever layers are on disk no longer describe the stock. See
	 *                        `CostingStrategy::RESTART_OPTION`. True on a first-ever switch too,
	 *                        where it finds nothing to retire and costs nothing.
	 */
	private static function queue_seed( string $method, bool $restart ): void {
		update_option( self::SEEDED_OPTION, $method, true );

		if ( $restart ) {
			update_option( CostingStrategy::RESTART_OPTION, current_time( 'mysql', true ), false );
		}

		/*
		 * SEEDED ON A LATER REQUEST, NOT THIS ONE — see `register_costing_transition()` for why the
		 * container makes that necessary. It is only an accelerator now: the first line to touch any
		 * pair opens that pair's balance itself.
		 */
		if ( ! wp_next_scheduled( self::SEED_HOOK ) ) {
			wp_schedule_single_event( time(), self::SEED_HOOK );
		}

		// A reconcile shortly afterwards, so the Stock health panel reflects the new starting point
		// today rather than after tomorrow's scheduled audit.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::AUDIT_HOOK );
	}

	/**
	 * Does this method keep layers on this build?
	 *
	 * Asked without naming a method: anything but the default that something implements. A stored
	 * name nothing implements — a layer method left behind on the free build — keeps nothing.
	 *
	 * @param string $method The method.
	 */
	private static function keeps_layers( string $method ): bool {
		return self::DEFAULT_METHOD !== $method && in_array( $method, self::costing_methods(), true );
	}

	/**
	 * What to do after a background seeding run, decided from its report alone so it can be tested.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE ONE-OFF EVENT USED TO BE CONSUMED WHATEVER HAPPENED. A run that found the stock lock busy
	 * returned an error and stopped; a run that hit the 500-pair cap reported `remaining` and
	 * stopped. Either way nothing was queued again, the Stock health panel's "open them now" button
	 * only appeared while an event was queued, and so it disappeared at exactly the moment it was
	 * needed. Reproduced on Linux before the fix.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * Busy: try again in a minute. More to do and this run made progress: carry on shortly. More to
	 * do and NO progress: stop — every pair it tried failed, and looping would hold the lock forever;
	 * the daily audit re-queues it while unseeded pairs remain.
	 *
	 * @param array<string, mixed>|WP_Error $report What `StockEngine::seed_costing_layers()` returned.
	 */
	public static function after_seed_run( array|WP_Error $report ): void {
		if ( $report instanceof WP_Error ) {
			if ( 'plugixa_inventory_stock_busy' === $report->get_error_code() && ! wp_next_scheduled( self::SEED_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SEED_HOOK );
			}

			return;
		}

		$layers = (int) ( $report['layers'] ?? 0 );

		if ( (int) ( $report['remaining'] ?? 0 ) > 0 && $layers > 0 && ! wp_next_scheduled( self::SEED_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::SEED_HOOK );
		}

		if ( $layers < 1 ) {
			return;
		}

		/**
		 * Fires when opening cost layers have been created for pre-existing stock.
		 *
		 * @param array<string, mixed> $report What was seeded.
		 */
		do_action( 'plugixa_inventory_layers_seeded', $report );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( TransactionsController::class )->register_routes();
		$container->get( StockController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);

		/*
		 * THIS MODULE'S OPTIONS LEAVE WITH IT. `Core\Uninstaller` drops tables by prefix and deletes
		 * the two options Core owns; its docblock states the rule for everything else -- "a module
		 * that stores one hooks `plugixa_inventory_uninstall` and deletes its own". Only App Manager
		 * ever did, so uninstalling left three of Stock's options behind on the site for ever, one
		 * of them autoloaded into every page request of a site that no longer had the plugin.
		 *
		 * `plugixa_inventory_legacy_packs_dropped` is here too, and it is the odd one out: NOTHING in
		 * this codebase writes it any more. It is a fossil of `LegacyCleanup`, the class deleted in
		 * P1 after it dropped the live warehouses table on every migrate (see `Core\Activator`). The
		 * class went; the autoloaded option it wrote did not, and no module is left to claim it.
		 * Stock adopts it because Stock is where that cleanup used to run.
		 */
		add_action(
			'plugixa_inventory_uninstall',
			static function (): void {
				delete_option( self::AUDIT_OPTION );
				delete_option( self::SEEDED_OPTION );
				delete_option( CostingStrategy::RESTART_OPTION );
				delete_option( StockEngine::SENTINEL_OPTION );
				delete_option( 'plugixa_inventory_legacy_packs_dropped' );
			}
		);

		add_filter( 'plugixa_inventory_entity_types', array( $this, 'register_entity' ) );

		add_filter(
			'plugixa_inventory_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'transaction' === $entity_type
					? array_merge( $fields, $container->get( TransactionRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		/*
		 * NO `plugixa_inventory_record_writers` ENTRY FOR `transaction`, and its absence is a
		 * defence rather than an oversight. That filter is how the CSV importer and mass actions
		 * reach an entity; a ledger that could be written by an importer is not an audit trail.
		 */

		// The ledger is append-only. This catches anything that reaches the base Repository
		// directly, including a future module that has not read TransactionRepository's docblock.
		add_filter(
			'plugixa_inventory_can_delete',
			static function ( bool $allowed, string $entity ): bool {
				return 'transaction' === $entity ? false : $allowed;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_can_purge',
			static function ( bool $allowed, string $entity ): bool {
				return 'transaction' === $entity ? false : $allowed;
			},
			10,
			2
		);

		$this->register_delete_blockers( $container );
		$this->register_settings();
		$this->register_costing_transition( $container );
		$this->register_audit_cron( $container );

		/*
		 * WP-CLI IS A NEW PATTERN FOR THE PLUGIXA FAMILY — `grep -rl "WP_CLI"` across the CRM this
		 * architecture came from returns nothing — and it is here for two specific reasons rather
		 * than for completeness. A rebuild over 50,000 items will outlive any REST timeout, and
		 * `--repair-balances` rewrites an audit column, which should not be one fetch() away from a
		 * browser tab. The command itself holds no logic: it parses flags and prints a table.
		 */
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			StockCli::register( $container );
		}
	}

	/**
	 * Answer the guards that Warehouses, Recipients and Items ask before trashing a row.
	 *
	 * Each of those modules fires a filter collecting SENTENCES rather than a boolean, because "you
	 * cannot delete this" without saying why leaves the user with no move.
	 *
	 * @param Container $container The plugin container.
	 */
	private function register_delete_blockers( Container $container ): void {
		add_filter(
			'plugixa_inventory_warehouse_delete_blockers',
			static function ( array $blockers, int $id ) use ( $container ): array {
				/*
				 * STOCK ON HAND BLOCKS; HISTORY DOES NOT. A warehouse that still holds something is
				 * holding a real quantity somebody is answerable for, and trashing it makes that
				 * quantity unreachable while every total still counts it. A warehouse that merely
				 * APPEARS in the ledger is finished with -- refusing that would mean no warehouse
				 * could ever be retired, which is not a feature.
				 */
				if ( $container->get( ItemStockRepository::class )->warehouse_holds_stock( $id ) ) {
					$blockers[] = __( 'This warehouse still holds stock. Transfer or remove it first.', 'plugixa-inventory' );
				}

				return $blockers;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_recipient_delete_blockers',
			static function ( array $blockers, int $id ) use ( $container ): array {
				if ( $container->get( TransactionRepository::class )->recipient_has_history( $id ) ) {
					$blockers[] = __( 'Stock has been checked out to this person. Their history is part of the audit trail.', 'plugixa-inventory' );
				}

				return $blockers;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_item_delete_blockers',
			static function ( array $blockers, int $id ) use ( $container ): array {
				if ( $container->get( ItemStockRepository::class )->item_holds_stock( $id ) ) {
					$blockers[] = __( 'This item still has stock on hand.', 'plugixa-inventory' );
				}

				if ( $container->get( TransactionRepository::class )->item_has_history( $id ) ) {
					$blockers[] = __( 'This item has stock history, which is part of the audit trail. Unpublish it instead.', 'plugixa-inventory' );
				}

				return $blockers;
			},
			10,
			2
		);
	}

	/**
	 * The costing setting, contributed to the one settings option.
	 *
	 * A module owns its own settings: Core knows nothing about costing, and this is the seam that
	 * keeps it that way.
	 */
	private function register_settings(): void {
		add_filter(
			'plugixa_inventory_settings_defaults',
			static function ( array $defaults ): array {
				$defaults['inventory_costing_method'] = self::DEFAULT_METHOD;

				return $defaults;
			}
		);

		add_filter(
			'plugixa_inventory_sanitize_settings',
			static function ( array $clean, array $input ): array {
				$method = (string) ( $input['inventory_costing_method'] ?? self::DEFAULT_METHOD );
				$stored = (string) Settings::get( 'inventory_costing_method', self::DEFAULT_METHOD );

				/*
				 * ═══════════════════════════════════════════════════════════════════════════════
				 * ONLY A METHOD THIS BUILD CAN RUN MAY REPLACE THE STORED ONE.
				 *
				 * An unrecognised value is still not REFUSED — rejecting a whole save over one stale
				 * select would lose the user's other edits to tell them about a field they did not
				 * touch. But it used to fall back to the default and be WRITTEN, and that was a
				 * silent change of accounting basis: on the free build, where no layer method is
				 * implemented, saving the date format rewrote a stored layer method as average
				 * costing. Reproduced on Linux before the fix. The site came back to the premium
				 * build costing by average, with its layers orphaned.
				 *
				 * So a value this build cannot offer keeps whatever was stored. The strategy factory
				 * still costs at average meanwhile — it falls back at runtime, not on disk — and the
				 * settings screen says so. Choosing a method that IS offered replaces it as before.
				 * ═══════════════════════════════════════════════════════════════════════════════
				 */
				$offered = self::costing_methods();

				if ( in_array( $method, $offered, true ) ) {
					$clean['inventory_costing_method'] = $method;
				} else {
					$clean['inventory_costing_method'] = '' !== $stored ? $stored : self::DEFAULT_METHOD;
				}

				return $clean;
			},
			10,
			2
		);

		add_filter(
			'plugixa_inventory_settings_choices',
			static function ( array $choices ): array {
				$choices['costing_methods'] = self::costing_methods();

				return $choices;
			}
		);
	}

	/**
	 * React to the costing method being changed.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * SWITCHING A LIVE SITE TO FIFO MUST NOT BE AN OUTAGE, and without this it would be.
	 *
	 * Every quantity received while average costing was in force has no cost layer behind it. The
	 * first removal afterwards walks the layers, finds nothing, and is refused with
	 * `costing_unavailable` — on a site where nothing is actually wrong. So the transition seeds
	 * opening layers, reconstructed from the ledger's own receipts so that both the ages and the
	 * costs are the real ones rather than today's date and today's price.
	 *
	 * IT RUNS ON THE TRANSITION, NOT ON EVERY SAVE — and detecting that takes an option, because
	 * `settings_saved` fires AFTER the write, so the previous value is already gone by the time this
	 * listener can look. Without the marker, every save made while FIFO is in force would queue
	 * another seed: each one finds nothing to do, and each one takes the stock lock to find that
	 * out, on a screen people save repeatedly.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param Container $container The plugin container.
	 */
	private function register_costing_transition( Container $container ): void {
		add_action(
			'plugixa_inventory_settings_saved',
			static function ( array $settings ): void {
				// The same effective name the factory uses, so a pinned method is honoured here too.
				$method = (string) apply_filters(
					'plugixa_inventory_costing_method',
					(string) ( $settings['inventory_costing_method'] ?? self::DEFAULT_METHOD )
				);

				/*
				 * LEAVING LAYER COSTING FORGETS THE MARKER. It used to return early for the default
				 * and leave the marker naming the old method, so FIFO -> average -> FIFO found the
				 * marker already matching, queued nothing, and resumed on layers that had not seen
				 * a single movement in between. Asked without naming a method: anything implemented
				 * that is not the default keeps layers; see `keeps_layers()`.
				 */
				if ( ! self::keeps_layers( $method ) ) {
					if ( '' !== (string) get_option( self::SEEDED_OPTION, '' ) ) {
						self::forget_seed();
					}

					return;
				}

				$marker = (string) get_option( self::SEEDED_OPTION, '' );

				if ( $marker === $method ) {
					return;
				}

				/*
				 * ═══════════════════════════════════════════════════════════════════════════════
				 * SEEDED ON A LATER REQUEST, NOT THIS ONE, AND THE REASON IS THE CONTAINER.
				 *
				 * `CostingStrategy` is resolved from the setting, and this request read that
				 * setting BEFORE the save — `StockController` is constructed while routes are
				 * registered, which pulls in `StockEngine`, which pulls in the strategy. Both are
				 * memoised. So seeding here would ask a `StandardCosting` that was built from the
				 * old value to create layers, and `StandardCosting::seed_opening_balances()`
				 * correctly does nothing. The failure is silent and happens only on the one request
				 * where it matters, which is the worst combination available.
				 *
				 * `queue_seed()` schedules a single-event cron instead, which the next request runs
				 * with the real strategy. IT IS ONLY AN ACCELERATOR NOW: the first line to
				 * touch any pair opens that pair's balance itself, so nothing waits on the cron —
				 * which matters when WP-Cron is disabled or the site is quiet.
				 *
				 * An empty marker means layer costing was not in force until now: a restart, and
				 * any layers on disk predate it.
				 * ═══════════════════════════════════════════════════════════════════════════════
				 */
				self::queue_seed( $method, '' === $marker );
			}
		);

		add_action(
			self::SEED_HOOK,
			static function () use ( $container ): void {
				self::after_seed_run( $container->get( StockEngine::class )->seed_costing_layers() );
			}
		);

		add_action(
			'plugixa_inventory_deactivated',
			static function (): void {
				wp_clear_scheduled_hook( self::SEED_HOOK );
			}
		);
	}

	/**
	 * The daily reconcile.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * IT REPORTS AND NEVER REPAIRS, and that is the whole design rather than caution.
	 *
	 * The gap `StockEngine`'s docblock admits to — a PHP fatal between the stock write and the
	 * ledger insert — leaves stock changed with no ledger row, and nothing runs to compensate. This
	 * cron is how that is noticed within a day instead of at the next stock count. But a cron that
	 * silently corrected it would erase the only evidence that something writes outside the engine,
	 * and the next morning's report would be clean whether or not the cause was still there.
	 *
	 * So it writes its findings to an option the Stock health panel reads, and a human decides.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param Container $container The plugin container.
	 */
	private function register_audit_cron( Container $container ): void {
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::AUDIT_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::AUDIT_HOOK );
				}
			}
		);

		add_action(
			self::AUDIT_HOOK,
			static function () use ( $container ): void {
				$engine = $container->get( StockEngine::class );
				$report = $engine->rebuild();

				if ( $report instanceof \WP_Error ) {
					return;
				}

				/*
				 * THE SAFETY NET UNDER THE SEED'S OWN RESCHEDULING. A run that made no progress stops
				 * rather than loop, and a site upgraded from a version whose one-off event was already
				 * spent has nothing queued at all. While any pair still waits for an opening balance,
				 * the daily audit puts the seed back in the queue.
				 */
				if ( ! wp_next_scheduled( self::SEED_HOOK ) && $engine->unseeded_costing_pairs() > 0 ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SEED_HOOK );
				}

				update_option(
					self::AUDIT_OPTION,
					array(
						'ran_at'         => current_time( 'mysql', true ),
						'pairs_checked'  => $report['pairs_checked'],
						'stock_diffs'    => count( $report['stock_diffs'] ),
						'cache_diffs'    => count( $report['cache_diffs'] ),
						'layer_diffs'    => count( $report['layer_diffs'] ),
						'orphan_batches' => count( $report['orphan_batches'] ),
						// The findings themselves, capped. A site with 40,000 drifted pairs has one
						// problem, not 40,000, and storing them all in an option would put a
						// megabyte of JSON in a row that some page will autoload one day.
						'findings'       => array_slice( $report['stock_diffs'], 0, 50 ),
					),
					false
				);

				/**
				 * Fires after the daily stock audit, whatever it found.
				 *
				 * The seam a notifications module hooks to tell somebody. This module deliberately
				 * does not send mail: "who should hear about this" is a policy question that
				 * belongs where the other alert policies live.
				 *
				 * @param array<string, mixed> $report The full findings.
				 */
				do_action( 'plugixa_inventory_stock_audited', $report );
			}
		);

		add_action(
			'plugixa_inventory_deactivated',
			static function (): void {
				wp_clear_scheduled_hook( self::AUDIT_HOOK );
			}
		);
	}

	/**
	 * Describe a ledger row to the generic UI.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['transaction'] = array(
			'label'    => __( 'Transaction', 'plugixa-inventory' ),
			'plural'   => __( 'Transactions', 'plugixa-inventory' ),
			'api_base' => 'transactions',
			// Read-only everywhere. The generic UI reads this to decide whether to draw a Create
			// button and a pencil; without it, both would appear over routes that do not exist.
			'readonly' => true,
			'fields'   => array(
				'created_at'       => array( 'label' => __( 'Date', 'plugixa-inventory' ), 'type' => 'datetime' ),
				'item_id'          => array( 'label' => __( 'Item', 'plugixa-inventory' ), 'type' => 'record', 'entity' => 'item' ),
				'warehouse_id'     => array( 'label' => __( 'Warehouse', 'plugixa-inventory' ), 'type' => 'record', 'entity' => 'warehouse' ),
				'transaction_type' => array( 'label' => __( 'Type', 'plugixa-inventory' ), 'type' => 'select', 'options' => StockEngine::TRANSACTION_TYPES ),
				'quantity_change'  => array( 'label' => __( 'Change', 'plugixa-inventory' ), 'type' => 'number' ),
				'new_quantity'     => array( 'label' => __( 'Balance', 'plugixa-inventory' ), 'type' => 'number' ),
				'total_cost'       => array( 'label' => __( 'Cost', 'plugixa-inventory' ), 'type' => 'money' ),
				'recipient_id'     => array( 'label' => __( 'Recipient', 'plugixa-inventory' ), 'type' => 'record', 'entity' => 'recipient' ),
				'reason'           => array( 'label' => __( 'Reason', 'plugixa-inventory' ), 'type' => 'text' ),
			),
		);

		return $entities;
	}
}
