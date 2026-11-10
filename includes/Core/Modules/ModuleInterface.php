<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Modules;

use PlugixaInventory\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract every inventory module implements, free or premium.
 *
 * A module is a self-contained folder. Adding one means dropping a directory into
 * includes/Modules/ (free) or pro/includes/Modules/ (premium) — no core file is
 * edited, no registry list is appended to. ModuleRegistry discovers it on disk.
 */
interface ModuleInterface {

	/**
	 * Stable machine id, e.g. "contacts". Used for capability names and REST paths.
	 */
	public function id(): string;

	/**
	 * Create the module's database tables. Runs on activation and on version change,
	 * so it must be idempotent — use dbDelta.
	 */
	public function migrate(): void;

	/**
	 * Capabilities this module introduces, mapped to the roles that get them.
	 *
	 * @return array<string, string[]> Capability name => role names.
	 */
	public function capabilities(): array;

	/**
	 * Bind this module's repositories and services into the container.
	 *
	 * @param Container $container The plugin container.
	 */
	public function registerServices( Container $container ): void;

	/**
	 * Register REST routes. Called on rest_api_init.
	 *
	 * @param Container $container The plugin container.
	 */
	public function registerRoutes( Container $container ): void;

	/**
	 * Register WordPress hooks (cron, listeners, filters).
	 *
	 * @param Container $container The plugin container.
	 */
	public function registerHooks( Container $container ): void;
}
