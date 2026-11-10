<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which build is installed: free or premium.
 *
 * The premium build is the free plugin plus a `pro/` folder, so the folder is the answer. NO
 * LICENCE TEST, and there must never be one: a licence is Freemius's business, the free build has
 * to work with no account at all, and `ProOverlayTest` forbids a licence gate in free code.
 *
 * It answers one question, for the admin app: whether to show the free build's Pro features page
 * and its gold buttons. The premium build carries every free file, that page included, so without
 * this a paying customer would be told to upgrade.
 *
 * `Bundle::build_dir()` and `ModuleRegistry` also look at `pro/`, and deliberately not through
 * here: the first asks whether the premium BUNDLE was built, the second scans the folder for
 * modules. Neither is asking which edition this is.
 */
final class Edition {

	/**
	 * The premium plans on plugixa.com, where the free build's "See Pro plans" goes.
	 *
	 * Here and not in the React source: the app is handed it through the page config, so the
	 * app's own code names no outside host.
	 */
	public const PRICING_URL = 'https://www.plugixa.com/plugins/plugixa-inventory#pricing';

	/**
	 * `premium` when the `pro/` folder is on disk, `free` otherwise.
	 */
	public static function current(): string {
		return is_dir( PLUGIXA_INVENTORY_PATH . 'pro' ) ? 'premium' : 'free';
	}
}
