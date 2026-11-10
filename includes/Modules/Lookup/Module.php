<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Lookup;

use PlugixaInventory\Core\Container;
use PlugixaInventory\Core\Modules\AppInterface;
use PlugixaInventory\Core\Modules\ModuleInterface;
use PlugixaInventory\Modules\Lookup\Http\LookupController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finding an item from a barcode, a SKU or a serial number.
 *
 * A MODULE WITH NO TABLE AND NO ENTITY, which is a shape this plugin already has twice — `Reports`
 * and `Dashboard` are the others. It owns a QUESTION rather than data: everything it reads belongs
 * to Items and Stock, and it exists so that "what is this thing in my hand" has one answer with one
 * set of rules, rather than being re-implemented slightly differently by every screen that wants a
 * scanner.
 *
 * IT REGISTERS THREE STRINGS AND NO MORE. Its two routes return items, so every word describing a
 * RESULT belongs to Items or to the shell; what is left is the language of the act of scanning,
 * which is this module's and nobody else's. See `Strings`.
 */
final class Module implements ModuleInterface, AppInterface {

	public function id(): string {
		return 'lookup';
	}

	/**
	 * NOTHING TO MIGRATE. Declared rather than omitted because `ModuleInterface` requires it, and an
	 * empty body here is a clearer statement than a `Schema` class with no tables in it.
	 */
	/**
	 * @return array{
	 *     order: int, category: string, core: bool, deps: string[], default: bool,
	 *     label_key: string, desc_key: string, icon: string, cron: string[]
	 * }
	 */
	public function app(): array {
		return array(
			'order'     => 10,
			'category'  => 'tools',
			'core'      => false,
			'deps'      => array(),
			'default'   => true,
			'label_key' => 'app_lookup',
			'desc_key'  => 'app_lookup_desc',
			'icon'      => 'scan',
			'cron'      => array(),
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
			'label' => (string) ( $strings['app_lookup'] ?? 'lookup' ),
			'desc'  => (string) ( $strings['app_lookup_desc'] ?? '' ),
		);
	}

	public function blocker( Container $container ): ?string {
		unset( $container );

		return null;
	}

	public function migrate(): void {}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function registerServices( Container $container ): void {
		unset( $container );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( LookupController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		unset( $container );

		add_filter(
			'plugixa_inventory_admin_i18n_strings',
			static fn( array $strings ): array => array_merge( $strings, Strings::all() )
		);
	}
}
