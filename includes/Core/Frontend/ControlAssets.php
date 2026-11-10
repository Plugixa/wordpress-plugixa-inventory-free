<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the shared front-end control stylesheet. Registers it, and nothing else.
 *
 * IT IS NEVER ENQUEUED HERE, AND THAT IS THE POINT. Three surfaces need it — the lead form, the
 * customer portal and the public help centre — and each of them already knows exactly when it is on
 * a page, because each already registers its own stylesheet conditionally. So each declares this
 * handle as a DEPENDENCY of its own, and WordPress enqueues it wherever any of them is enqueued,
 * exactly once, and never anywhere else.
 *
 * Enqueueing it from here would mean re-deriving "is one of the three shortcodes on this page" a
 * fourth time, in the one place that does not already know — and a page with none of them on it
 * would start paying for a stylesheet nothing on it uses. `wp_register_style()` on its own emits no
 * tag, so the cost of this class on an ordinary page is an array entry.
 *
 * IT LIVES IN CORE BECAUSE ALL THREE MODULES NEED IT AND NONE OWNS IT. Putting it in whichever
 * module happened to want it first would make the other two depend on that module by name, which is
 * exactly the coupling `CoreIndependenceTest` exists to prevent in the other direction.
 */
final class ControlAssets {

	/** Declare this in a style's `$deps` to get the control floor with it. */
	public const HANDLE = 'plugixa-inventory-controls';

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_style(
			self::HANDLE,
			PLUGIXA_INVENTORY_URL . 'assets/frontend/controls.css',
			array(),
			PLUGIXA_INVENTORY_VERSION
		);
	}
}
