<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which apps are switched on: the stored flags, and the rules that turn them into an answer.
 *
 * TWO DIFFERENT WORDS FOR TWO DIFFERENT THINGS, and keeping them apart is most of this class.
 *
 *   flag     what an administrator last chose, or the app's default. Stored.
 *   enabled  whether it actually runs, after `core` and dependencies are taken into account.
 *
 * They come apart constantly and the UI has to show both: an app whose dependency is off is not
 * running, but nobody turned it off, and the card must not claim they did — otherwise switching
 * the dependency back on would leave the dependant mysteriously still dark. `flag` is what the
 * button reflects; `enabled` is what the rest of the plugin obeys.
 *
 * A SEPARATE OPTION, NOT A KEY INSIDE `plugixa_inventory_settings`. Three reasons, in order of how
 * much they matter:
 *
 * 1. This is read during `plugins_loaded`, before `ModuleRegistry::registerHooks()`, which is
 *    earlier than anything asks for settings. Autoloaded, so it costs no query.
 * 2. `Core\Settings` is a flat map of scalars with a sanitizer per key. A nested array of module
 *    flags would need a bespoke rule on a contract fourteen other modules share.
 * 3. The App Manager then owns its own storage and its own cleanup, exactly as the Demo module
 *    owns `plugixa_inventory_demo`.
 */
final class AppState {

	public const OPTION = 'plugixa_inventory_apps';

	/**
	 * The five drawers a card can be filed in. Not module names — Core may name these.
	 */
	public const CATEGORIES = array( 'core', 'operations', 'insight', 'configuration', 'tools' );

	/**
	 * The stored map, read once per request.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $flags = null;

	/**
	 * What an administrator last chose, per module id.
	 *
	 * @return array<string, bool>
	 */
	public function flags(): array {
		if ( null === $this->flags ) {
			$stored = get_option( self::OPTION, array() );

			$this->flags = array();

			foreach ( (array) $stored as $id => $on ) {
				if ( is_string( $id ) ) {
					$this->flags[ $id ] = (bool) $on;
				}
			}
		}

		return $this->flags;
	}

	/**
	 * Resolve descriptors plus stored flags into one yes/no per module.
	 *
	 * THREE RULES, AND THE THIRD IS THE ONE THAT WILL SAVE SOMEBODY:
	 *
	 * - `core` wins over everything and never consults the flag. A core app has no off switch, so a
	 *   stale `false` sitting in the option from an earlier version cannot black out the dashboard.
	 * - Otherwise the flag decides, defaulting to the descriptor's `default` when nobody has chosen.
	 * - A module with no descriptor at all is ENABLED. Those arrive through the
	 *   `plugixa_inventory_modules` filter — an add-on plugin's module, which knows nothing about
	 *   this screen. Defaulting an unknown module to OFF would mean installing an add-on and
	 *   finding it silently inert, with the explanation on a screen that does not list it.
	 *
	 * Dependencies resolve to a FIXED POINT rather than in one pass: if C needs B and B needs A,
	 * turning A off has to reach C, and the descriptors are in filesystem order, not dependency
	 * order. The loop is bounded by the module count because each pass can only ever turn things
	 * off, never back on.
	 *
	 * @param array<string, array<string, mixed>> $descriptors Module id => `AppInterface::app()`.
	 *
	 * @return array<string, bool> Module id => actually running.
	 */
	public function resolve( array $descriptors ): array {
		$flags   = $this->flags();
		$enabled = array();

		foreach ( $descriptors as $id => $descriptor ) {
			if ( ! empty( $descriptor['core'] ) ) {
				$enabled[ $id ] = true;
				continue;
			}

			$enabled[ $id ] = (bool) ( $flags[ $id ] ?? ( $descriptor['default'] ?? true ) );
		}

		$passes = count( $descriptors );

		for ( $pass = 0; $pass < $passes; $pass++ ) {
			$settled = true;

			foreach ( $descriptors as $id => $descriptor ) {
				if ( ! $enabled[ $id ] || ! empty( $descriptor['core'] ) ) {
					continue;
				}

				foreach ( (array) ( $descriptor['deps'] ?? array() ) as $dep ) {
					// A dependency nobody declared is not a dependency that is off.
					if ( isset( $enabled[ $dep ] ) && ! $enabled[ $dep ] ) {
						$enabled[ $id ] = false;
						$settled        = false;
						break;
					}
				}
			}

			if ( $settled ) {
				break;
			}
		}

		return $enabled;
	}

	/**
	 * Write some flags, leaving every other stored flag exactly as it was.
	 *
	 * IT MERGES, IT NEVER REPLACES, AND THAT IS THE FREE/PREMIUM ROUND TRIP. On a free build the
	 * premium modules are not on disk, so they are not in the registry and not in this payload. A
	 * whole-map write would therefore erase the record that a premium user had ever switched them
	 * on — and they would come back from a licence lapse to find their choices reset by a screen
	 * that could not even see them. `Core\Settings::keep_unclaimed()` exists for exactly this
	 * reason on the settings option; this reproduces the behaviour rather than inheriting it,
	 * because the two options have nothing else in common.
	 *
	 * @param array<string, bool> $patch Module id => on.
	 *
	 * @return array<string, bool> The stored map afterwards.
	 */
	public function save( array $patch ): array {
		$flags = $this->flags();

		foreach ( $patch as $id => $on ) {
			if ( is_string( $id ) && '' !== $id ) {
				$flags[ $id ] = (bool) $on;
			}
		}

		ksort( $flags );

		update_option( self::OPTION, $flags );

		$this->flags = $flags;

		return $flags;
	}

	/**
	 * Forget everything. The uninstaller's half of `OPTION`.
	 */
	public function forget(): void {
		delete_option( self::OPTION );

		$this->flags = null;
	}
}
