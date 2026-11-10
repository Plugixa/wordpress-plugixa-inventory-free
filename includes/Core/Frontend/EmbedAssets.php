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
 * The two files the PARENT page needs — the page with the theme still on it.
 *
 * THIS IS THE OPPOSITE OF {@see AppAssets} IN EVERY RESPECT, WHICH IS WHY IT IS NOT IN IT. That
 * class removes the theme's stylesheets and puts a 1.5MB bundle on the page. This one adds two small
 * files and touches nothing else, because on this page the theme is the point and the bundle lives
 * inside the frame. Folding the two together would mean one class whose every method began by asking
 * which arrangement it was in.
 *
 * REGISTERED ALWAYS, ENQUEUED ONLY WHERE THE APP ACTUALLY RENDERS. `wp_register_*` emits no tag, so
 * a page with no inventory on it pays an array entry and nothing else. The enqueue happens twice on
 * purpose — once from `wp_enqueue_scripts` so the stylesheet lands in the `<head>`, and once from
 * the render callback as the backstop for a widget, a template part or a page builder that
 * `has_shortcode()` cannot see. Enqueuing twice is a no-op; missing it entirely is an unstyled,
 * un-resized frame.
 *
 * NO `DONOTCACHEPAGE` HERE, AND THAT IS A REAL IMPROVEMENT OVER THE TAKEOVER. This document carries
 * no REST nonce and no configuration — both live inside the frame, which sets the constant itself.
 * So the outer page is ordinary cacheable HTML, and a visitor who may not use the inventory gets a
 * cacheable notice rather than a page the host is told never to store.
 */
final class EmbedAssets {

	public const STYLE = 'plugixa-inventory-embed';

	public const SCRIPT = 'plugixa-inventory-embed';

	private bool $registered = false;

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		$base = PLUGIXA_INVENTORY_URL . 'assets/frontend/';
		$path = PLUGIXA_INVENTORY_PATH . 'assets/frontend/';

		wp_register_style(
			self::STYLE,
			$base . 'css/app-embed.css',
			array(),
			$this->version( $path . 'css/app-embed.css' )
		);

		wp_register_script(
			self::SCRIPT,
			$base . 'js/app-embed.js',
			array(),
			$this->version( $path . 'js/app-embed.js' ),
			true
		);
	}

	/**
	 * Put the frame's stylesheet and its sizing script on this page.
	 *
	 * REGISTERS FIRST, RATHER THAN CHECKING WHETHER REGISTRATION HAPPENED. A theme or page builder
	 * that calls `do_shortcode()` before `wp_enqueue_scripts` would otherwise reach a handle that
	 * does not exist yet and silently enqueue nothing — a frame with no styles and no height. Doing
	 * the registration here as well costs one guarded early return and cannot be got wrong.
	 */
	public function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		$this->register();

		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );
	}

	/**
	 * The file's own mtime, for the same reason `Bundle::asset_version()` uses one: these two files
	 * change without the plugin version moving, and a stale copy of the sizing script is a frame
	 * stuck at the wrong height with nothing on screen to explain it.
	 *
	 * @param string $path Absolute path to the asset.
	 */
	private function version( string $path ): string {
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;

		return false !== $mtime ? (string) $mtime : PLUGIXA_INVENTORY_VERSION;
	}
}
