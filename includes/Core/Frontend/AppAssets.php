<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Frontend;

use PlugixaInventory\Core\App\Bundle;
use PlugixaInventory\Core\App\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the front-end inventory page loads, and what it takes off the page to make room.
 *
 * THIS IS `Core\Admin\Assets` SEEN IN A MIRROR, and the reflection is not symmetrical. Both files
 * solve one problem: WordPress — or here, the active theme — styles bare form elements at
 * specificity (0,1,1), every MUI rule is one Emotion class at (0,1,0), and no selector sits between
 * them, so a reset that neutralises the theme's padding destroys MUI's padding in the same
 * declaration. The conflict has to be removed rather than out-shouted, and `print_styles_array` is
 * where you remove it: it fires inside `WP_Styles::all_deps()`, AFTER dependency resolution, on the
 * front end exactly as in wp-admin.
 *
 * THE SHAPE IS INVERTED, AND THAT IS THE WHOLE DIFFERENCE. In wp-admin the opponent is enumerable —
 * core ships a known set of stylesheets, so `Assets::DROP_STYLES` names the twelve to remove and
 * everything else is left alone. Out here the opponent is "whichever theme this site runs, plus
 * whatever forty plugins enqueue", which is not a list anybody can write down. So this filter is an
 * ALLOW-LIST: a handful of seeds plus their dependency closure survive, and everything else goes.
 * A block-list here would be permanent whack-a-mole, with a support ticket per theme.
 *
 * THE STRIP AND THE TEMPLATE SWAP ARE ONE DECISION, NOT TWO. Removing the theme's stylesheet while
 * still rendering the theme's header, navigation and footer produces an *unstyled theme*, which is
 * worse than either extreme. {@see AppShortcode} therefore swaps in a bare document whenever it
 * strips, and both key off the same predicate.
 *
 * WHAT DOES NOT APPEAR HERE, ON PURPOSE: the focus-ring rule, the `h1-h6` colour rule and the
 * `[role="treeitem"]` checkbox rule that `Assets::reset_admin_chrome()` carries. Every one of those
 * fights a stylesheet wp-admin loads and the front end does not — `colors` is registered with
 * `wp-admin` as a dependency, and `common.css` is not enqueued out here at all. Copying them across
 * would be the exact mistake that file warns about: a rule with nothing to fight is not harmless,
 * because anything it says, MUI loses.
 */
final class AppAssets {

	/**
	 * Stylesheets the inventory page keeps, before their dependencies are added.
	 *
	 * Each is here because something visible or load-bearing breaks without it:
	 *
	 *   plugixa-inventory-app  the application's own stylesheet
	 *   plugixa-inventory-app-reset  the bare document's own reset; see PAGE_HANDLE below
	 *   admin-bar        the toolbar — and it carries two inline rules WordPress attaches to this
	 *                    handle rather than a file: the print-hiding rule, and the
	 *                    `html { margin-top: 32px }` bump. It also defines
	 *                    `--wp-admin--admin-bar--height`, which the app's shell already reads to
	 *                    size itself, so keeping this handle is what makes the layout come out right
	 *                    with no client change at all
	 *   editor-buttons   wp-includes/css/editor.css — the ONLY styling for `.wp-editor-container`,
	 *                    `.quicktags-toolbar` and `.mce-container` that lives outside wp-admin/.
	 *                    Drop it and every email body renders as a naked column of buttons
	 *   wp-auth-check    the session-expiry modal. See enqueue() for why it needs re-hooking by hand
	 *   media-views      the media library modal, and `imgareaselect` the cropper it opens. BOTH ARE
	 *   imgareaselect    NEEDED OR THE ENQUEUE IS POINTLESS: this filter is an allow-list, so calling
	 *                    `wp_enqueue_media()` while these are absent from the list produces a modal
	 *                    with no stylesheet — a column of unstyled links over the app. Their own
	 *                    dependencies (`buttons`, `dashicons`, `wp-mediaelement`) arrive with them
	 *                    through keep(), and all three are class-scoped — `.wp-core-ui .button`,
	 *                    `.mejs-*` — so none of them reaches a bare input and none can fight MUI
	 */
	private const KEEP_SEEDS = array(
		Bundle::HANDLE,
		self::PAGE_HANDLE,
		'admin-bar',
		'editor-buttons',
		'wp-auth-check',
		'media-views',
		'imgareaselect',
	);

	/**
	 * The bare document's own two-rule stylesheet.
	 *
	 * IT IS IN `KEEP_SEEDS` BECAUSE `print_styles_array` FIRES FOR A PRINT BY HANDLE TOO. The filter
	 * lives in `WP_Styles::all_deps()`, which `do_items()` calls whether it was handed the whole
	 * queue or one handle, so `wp_print_styles( self::PAGE_HANDLE )` from the template goes through
	 * {@see self::strip_theme_styles()} exactly like everything else. Today it would survive anyway,
	 * because the template prints before `wp_enqueue_scripts` has set `$on_app_page` — but that is
	 * an ordering accident, and the page losing its own reset is not a failure anybody would
	 * attribute to this list.
	 */
	public const PAGE_HANDLE = 'plugixa-inventory-app-reset';

	/**
	 * Set by enqueue(), which only runs on an inventory page a permitted user is looking at.
	 */
	private bool $on_app_page = false;

	public function on_app_page(): bool {
		return $this->on_app_page;
	}

	/**
	 * Register the bare document's own stylesheet, ready for the template to print by handle.
	 *
	 * CALLED FROM `take_over_template`, NOT FROM `enqueue()`, AND THE TIMING IS THE WHOLE REASON.
	 * `enqueue()` runs on `wp_enqueue_scripts`, which fires from inside `wp_head()` — and this
	 * stylesheet has to be on the page BEFORE `wp_head()`, so that the admin bar's
	 * `html { margin-top: 32px !important }` prints after it and wins. `template_include` is the
	 * last hook before the document is built, and it is already where the swap to the bare template
	 * is decided, so the registration and the decision to need it stay together.
	 *
	 * Not enqueued, only registered: the template prints it explicitly. Enqueuing would ALSO put it
	 * in the `wp_head()` batch and emit the same link twice.
	 *
	 * VERSIONED BY MTIME, NOT BY THE PLUGIN VERSION, for the reason `Bundle::asset_version()` gives
	 * and this file learned the hard way. The version only moves on release, so every edit to this
	 * stylesheet between releases shipped under `?ver=1.0.0` and browsers went on serving the copy
	 * they already had. The rule that zeroes `--wp-admin--admin-bar--height` inside the frame was
	 * added, served correctly by the web server, and still absent in the browser — which reads as
	 * the rule not working rather than as a stale file.
	 */
	public function register_page_style(): void {
		$path = PLUGIXA_INVENTORY_PATH . 'assets/frontend/css/app-page.css';

		$mtime = file_exists( $path ) ? filemtime( $path ) : false;

		wp_register_style(
			self::PAGE_HANDLE,
			PLUGIXA_INVENTORY_URL . 'assets/frontend/css/app-page.css',
			array(),
			false !== $mtime ? (string) $mtime : PLUGIXA_INVENTORY_VERSION
		);
	}

	/**
	 * Put the application on this page.
	 *
	 * Called from the shortcode's render path and from `wp_enqueue_scripts`, and only ever after the
	 * capability gate has passed — a visitor who cannot use the inventory must not be handed a 1.5 MB
	 * bundle, and must certainly not be handed a REST nonce printed inline on a public URL.
	 *
	 * NOTHING HAPPENS DURING AN ADMIN REQUEST, AND THE REASON IS A BUG THAT SHIPPED.
	 *
	 * Opening the page that holds `[plugixa_inventory_app]` in the block editor rendered wp-admin with no
	 * stylesheets at all — the menu as a bulleted list, the toolbar as bare boxes. The editor
	 * preloads the post through `rest_preload_api_request` INSIDE the admin page load;
	 * `WP_REST_Posts_Controller` builds `content.rendered` with `apply_filters( 'the_content', … )`;
	 * that runs the shortcode; the capability gate passes because it is an administrator; and this
	 * method set `$on_app_page`. `print_styles_array` then fired for the admin screen itself and
	 * {@see AppShortcode::strip_theme_styles()} took wp-admin apart with the allow-list built for a
	 * theme. The giveaway was what survived: the admin bar and nothing else, which is exactly
	 * KEEP_SEEDS.
	 *
	 * A shortcode rendered during an admin request is the editor asking what the content WOULD look
	 * like — a serialisation, not a mount. It must acquire no assets and must not change how the
	 * screen around it is drawn.
	 */
	public function enqueue(): void {
		if ( is_admin() || $this->on_app_page ) {
			return;
		}

		$this->on_app_page = true;

		Bundle::enqueue();
		Config::inject( 'page' );

		/*
		 * THE MEDIA LIBRARY, WHICH THIS PATH WENT WITHOUT FOR ITS WHOLE LIFE. `Core\Admin\Assets`
		 * makes this call and this file did not, so `wp.media` was undefined out here and
		 * `shared/MediaPicker.tsx` — which treats a missing factory as "not an exception" and
		 * returns — did nothing at all when somebody clicked Choose image. It failed silently, on a
		 * screen that otherwise worked, which is why it survived so long.
		 *
		 * It matters more now than it did: inside the embed's iframe there is no wp-admin to fall
		 * back to, so this is the only place the picker can ever open.
		 *
		 * `media-views` is deliberately on neither of `Core\Admin\Assets`'s style lists — it arrives
		 * only because this asks for it, and its rules are scoped to `.media-modal` and friends, so
		 * it does not repeat what `forms` did to the MUI fields.
		 */
		wp_enqueue_media();

		/*
		 * WordPress's own editor, for the email and template bodies — the same call the admin screen
		 * makes, and it works out here: `enqueue_default_editor()` branches to `wp_print_footer_scripts`
		 * when it is not in wp-admin, and enqueues `editor-buttons` on the way past.
		 *
		 * (Core's `wp_enqueue_editor()` takes no arguments. The admin caller passes an array that is
		 * silently discarded; not copying that here.)
		 */
		wp_enqueue_editor();

		$this->enqueue_auth_check();
		$this->do_not_cache();
	}

	/**
	 * Put the session-expiry modal back, because the front end does not get it for free.
	 *
	 * `wp_auth_check_load()` is hooked to `admin_enqueue_scripts` and nothing else, so on a front-end
	 * page there is no interim-login modal at all. That is a real regression rather than a cosmetic
	 * one: `Assets::KEEP_STYLES` keeps this handle in wp-admin precisely because it is "the only way
	 * back in without losing the page", and an inventory page is exactly where somebody has a half-filled
	 * dialog open when their cookie lapses. Without it the app just starts returning 401s with no
	 * explanation and no way to re-authenticate that does not cost the unsaved work.
	 *
	 * Registered by hand rather than by calling `wp_auth_check_load()`, which cannot run here: its
	 * first act is `get_current_screen()`, and there is no screen on the front end.
	 */
	private function enqueue_auth_check(): void {
		wp_enqueue_style( 'wp-auth-check' );
		wp_enqueue_script( 'wp-auth-check' );

		add_action( 'wp_print_footer_scripts', 'wp_auth_check_html', 5 );
	}

	/**
	 * A per-session REST nonce goes on this page, so the page must not be cached.
	 *
	 * A cached copy would serve one staff member's nonce to every other visitor of the page. The
	 * nonce is verified server-side as well, so a cache that ignores the hint costs a rejected
	 * request rather than a crossed session — but the hint is free and most caches honour it.
	 *
	 * DELIBERATELY UNPREFIXED, which is the one thing that makes it work: `DONOTCACHEPAGE` is an
	 * ecosystem-wide contract read by WP Super Cache, W3 Total Cache, LiteSpeed, WP Rocket and most
	 * hosts' page caches. A prefixed version would satisfy the sniff and be read by nothing.
	 */
	private function do_not_cache(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- see above; an ecosystem contract, not a constant of ours.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- see above.
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Take the theme's stylesheets off this one page.
	 *
	 * @param string[] $handles Handles WordPress is about to print.
	 *
	 * @return string[]
	 */
	public function strip_theme_styles( array $handles ): array {
		/*
		 * NEVER IN WP-ADMIN, whatever the flag says. `enqueue()` already refuses to set it there, so
		 * this is the second lock rather than the first — and it is on the method that does the
		 * damage, so a future caller that sets the flag by some other route still cannot dismantle
		 * the admin. The allow-list below was built against a THEME; pointed at wp-admin it removes
		 * `common`, `forms` and `admin-menu` and leaves an unusable screen.
		 */
		if ( is_admin() || ! $this->on_app_page ) {
			return $handles;
		}

		return array_values( array_intersect( $handles, $this->keep() ) );
	}

	/**
	 * The seeds plus everything they depend on, transitively.
	 *
	 * THE CLOSURE IS COMPUTED, NOT HAND-LISTED, and that is the point of the method. All four seeds
	 * depend on `dashicons` today, and writing "dashicons" into the seed list would work right up
	 * until a core release moved a dependency — at which point the toolbar would silently lose its
	 * glyphs on this one page and nothing would say why. Asking `WP_Styles` what a handle needs
	 * cannot go stale.
	 *
	 * @return string[]
	 */
	private function keep(): array {
		/**
		 * Filter the stylesheets the inventory page keeps.
		 *
		 * The escape hatch for a site whose theme genuinely provides something the application needs
		 * — a webfont, an icon set. Dependencies of anything added here are resolved too, so one
		 * handle is enough. Adding the theme's main stylesheet back defeats the strip and hands the
		 * specificity fight straight back; that is the site's call to make, not ours to prevent.
		 *
		 * @param string[] $seeds Handles to keep, before dependency resolution.
		 */
		$seeds = (array) apply_filters( 'plugixa_inventory_app_page_keep_styles', self::KEEP_SEEDS );

		$styles = wp_styles();
		$keep   = array();
		$queue  = array_values( array_map( 'strval', $seeds ) );

		while ( array() !== $queue ) {
			$handle = array_shift( $queue );

			if ( isset( $keep[ $handle ] ) ) {
				continue;
			}

			$keep[ $handle ] = true;

			$registered = $styles->registered[ $handle ] ?? null;

			if ( null !== $registered ) {
				foreach ( (array) $registered->deps as $dep ) {
					$queue[] = (string) $dep;
				}
			}
		}

		return array_keys( $keep );
	}

	/**
	 * Remove the core stylesheets that are echoed on `wp_head` rather than enqueued.
	 *
	 * THE HONEST LIMIT OF THE STRIP. `print_styles_array` reaches only what goes through `WP_Styles`,
	 * and a handful of core features print their CSS straight into the head — the Customizer's
	 * "Additional CSS", the custom-logo rules, and the theme.json global styles a block theme emits.
	 * Those are a different queue, so they need a different removal, and unhooking them by name is
	 * the whole of it.
	 *
	 * What this still cannot reach is a theme or page builder that echoes its own critical CSS on
	 * `wp_head` under a name we do not know. There is no general answer to that; the escape hatch is
	 * an iframe, and it is documented on {@see AppShortcode}.
	 */
	public function remove_inline_theme_css(): void {
		if ( ! $this->on_app_page ) {
			return;
		}

		remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
		remove_action( 'wp_head', '_custom_logo_header_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
	}
}
