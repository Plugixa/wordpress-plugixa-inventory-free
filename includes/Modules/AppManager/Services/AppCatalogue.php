<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\AppManager\Services;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\AppState;
use PlugixaInventory\Core\Modules\ModuleRegistry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The App Manager's view of the plugin: every app, its state, and what turning it off would cost.
 *
 * TWO PAYLOADS, AND THE DIFFERENCE BETWEEN THEM IS THE WHOLE PERFORMANCE STORY.
 *
 *   config()  goes onto every admin page. Descriptors and flags only — no queries.
 *   all()     answers one screen. Adds the counts and the blocking reasons, which cost a COUNT
 *             per module that has a table.
 *
 * Putting `usage()` in `config()` would run a dozen COUNT queries on every wp-admin request in the
 * plugin, to render figures that appear on one screen an administrator opens twice a year.
 */
final class AppCatalogue {

	public function __construct(
		private readonly Container $container,
		private readonly ModuleRegistry $modules
	) {}

	/**
	 * The full grid: every app, with its counts and its reasons.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$enabled = $this->modules->enabledMap();
		$flags   = $this->modules->state()->flags();
		$cards   = array();

		foreach ( $this->modules->descriptors() as $id => $descriptor ) {
			$module = $this->modules->module( $id );

			$usage   = array();
			$blocker = null;

			if ( $module instanceof AppInterface ) {
				$usage   = $module->usage( $this->container );
				$blocker = $module->blocker( $this->container );
			}

			$cards[] = $this->card( $descriptor, $enabled, $flags, $usage, $blocker );
		}

		/**
		 * Filter the App Manager grid.
		 *
		 * The door for a card that is not a module. `Core\Settings` is the standing example: it is
		 * a screen everybody has and nobody can switch off, so it has no `Module.php` to describe
		 * itself, and without this filter the grid would quietly omit a third of what the sidebar
		 * shows. An entry added here must set `core` — `set()` refuses to write a flag for an id
		 * the registry does not know, so a toggleable synthetic card would have a button that
		 * always failed.
		 *
		 * @param array<int, array<string, mixed>> $cards The grid.
		 */
		$cards = (array) apply_filters( 'plugixa_inventory_apps', $cards );

		/*
		 * Core first, then the drawers in the order the tab bar lists them, then each module's own
		 * `order` inside a drawer -- which is the order the SIDEBAR teaches. Sorting the cards
		 * alphabetically instead put the App Manager at the top of the core row and the Dashboard
		 * third, which reads as an arbitrary list rather than as this product's shape.
		 */
		$order = array_flip( AppState::CATEGORIES );

		usort(
			$cards,
			static fn( array $a, array $b ): int => array( $order[ $a['category'] ] ?? 99, $a['order'], $a['id'] )
				<=> array( $order[ $b['category'] ] ?? 99, $b['order'], $b['id'] )
		);

		return $cards;
	}

	/**
	 * What the client needs before it renders anything: which apps are on, and what they are.
	 *
	 * NO COUNTS AND NO BLOCKERS HERE — see the class docblock. This runs on every admin page.
	 *
	 * @return array{apps: array<string, bool>, registry: array<int, array<string, mixed>>}
	 */
	public function config(): array {
		$enabled = $this->modules->enabledMap();
		$flags   = $this->modules->state()->flags();

		$registry = array();

		foreach ( $this->modules->descriptors() as $descriptor ) {
			$registry[] = $this->card( $descriptor, $enabled, $flags, array(), null );
		}

		return array(
			'apps'     => $enabled,
			'registry' => $registry,
		);
	}

	/**
	 * Turn one app on or off.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled Wanted state.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error The fresh grid, or why not.
	 */
	public function set( string $id, bool $enabled ): array|WP_Error {
		$descriptors = $this->modules->descriptors();

		if ( ! isset( $descriptors[ $id ] ) ) {
			return new WP_Error(
				'plugixa_inventory_unknown_app',
				__( 'There is no such app.', 'plugixa-inventory' ),
				array( 'status' => 404 )
			);
		}

		$descriptor = $descriptors[ $id ];

		if ( ! empty( $descriptor['core'] ) ) {
			return new WP_Error(
				'plugixa_inventory_core_app',
				__( 'This app is part of the core and cannot be switched off.', 'plugixa-inventory' ),
				array( 'status' => 400 )
			);
		}

		$module  = $this->modules->module( $id );
		$blocker = $module instanceof AppInterface ? $module->blocker( $this->container ) : null;

		if ( null !== $blocker ) {
			return new WP_Error(
				'plugixa_inventory_app_blocked',
				$blocker,
				array( 'status' => 409 )
			);
		}

		if ( $enabled ) {
			$missing = $this->missing_dependencies( $id, $descriptors );

			if ( array() !== $missing ) {
				return new WP_Error(
					'plugixa_inventory_app_dependency',
					sprintf(
						/* translators: %s: comma-separated list of app names. */
						__( 'Switch these on first: %s', 'plugixa-inventory' ),
						implode( ', ', $missing )
					),
					array(
						'status' => 409,
						'deps'   => $missing,
					)
				);
			}
		}

		$this->modules->state()->save( array( $id => $enabled ) );

		/*
		 * The flag is written; the map that was computed from it on `plugins_loaded` is now stale.
		 * Without this the grid returned below is the grid from BEFORE the click — and, worse, a
		 * dependant of the app just switched off would still say it was running.
		 */
		$this->modules->refresh();

		if ( ! $enabled ) {
			$this->stop_cron( $descriptor );
		}

		/**
		 * Fires after an app is switched on or off.
		 *
		 * @param string $id      Module id.
		 * @param bool   $enabled Its new state.
		 */
		do_action( 'plugixa_inventory_app_toggled', $id, $enabled );

		return $this->all();
	}

	/**
	 * Which of this app's dependencies are themselves off.
	 *
	 * Returns NAMES, not ids: this becomes an error message somebody reads. The name is the
	 * English fallback from the dependency's own catalogue key, because the message is assembled
	 * server-side where the module's `Strings::all()` may not have been merged — a disabled
	 * dependency never ran `registerHooks()`, so its catalogue is not in the map.
	 *
	 * @param string                              $id          The app being switched on.
	 * @param array<string, array<string, mixed>> $descriptors Every descriptor.
	 *
	 * @return string[]
	 */
	private function missing_dependencies( string $id, array $descriptors ): array {
		$enabled = $this->modules->enabledMap();
		$missing = array();

		foreach ( (array) ( $descriptors[ $id ]['deps'] ?? array() ) as $dep ) {
			if ( isset( $enabled[ $dep ] ) && ! $enabled[ $dep ] ) {
				$missing[] = $dep;
			}
		}

		return $missing;
	}

	/**
	 * Clear whatever this app had queued.
	 *
	 * SKIPPING `registerHooks()` STOPS NEW SCHEDULING AND NOTHING ELSE. An event already written
	 * into the `cron` option outlives the switch, and WordPress will happily keep firing a hook
	 * with no listeners for as long as the option says to. Nothing breaks — a hook nobody hears is
	 * a no-op — but `wp_next_scheduled()` would go on reporting a run that never happens, which is
	 * exactly what the stock-health panel shows an administrator.
	 *
	 * The descriptor names the hooks, so this clears cron for a module it is not allowed to name.
	 *
	 * @param array<string, mixed> $descriptor The app's descriptor.
	 */
	private function stop_cron( array $descriptor ): void {
		foreach ( (array) ( $descriptor['cron'] ?? array() ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * One card.
	 *
	 * `flag` and `enabled` are both here on purpose — see `AppState`. The button reflects `flag`;
	 * the badge reflects `enabled`; a card where they disagree is an app whose dependency is off,
	 * and the UI has to be able to say so rather than pretend somebody switched it off.
	 *
	 * @param array<string, mixed> $descriptor The descriptor.
	 * @param array<string, bool>  $enabled    Resolved states.
	 * @param array<string, bool>  $flags      Stored flags.
	 * @param string[]             $usage      Figures for the card.
	 * @param string|null          $blocker    Why it cannot be toggled.
	 *
	 * @return array<string, mixed>
	 */
	private function card( array $descriptor, array $enabled, array $flags, array $usage, ?string $blocker ): array {
		$id   = (string) $descriptor['id'];
		$core = (bool) $descriptor['core'];

		return array(
			'id'         => $id,
			'order'      => $descriptor['order'],
			'category'   => $descriptor['category'],
			'core'       => $core,
			'deps'       => $descriptor['deps'],
			'default'    => $descriptor['default'],
			'icon'       => $descriptor['icon'],
			'label_key'  => $descriptor['label_key'],
			'desc_key'   => $descriptor['desc_key'],
			'flag'       => $core ? true : (bool) ( $flags[ $id ] ?? $descriptor['default'] ),
			'enabled'    => (bool) ( $enabled[ $id ] ?? true ),
			'can_toggle' => ! $core && null === $blocker,
			'blocker'    => $blocker,
			'usage'      => array_values( $usage ),
		);
	}
}
