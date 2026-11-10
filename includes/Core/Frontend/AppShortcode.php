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
 * `[plugixa_inventory_app]` — the inventory on a page of the site, for staff.
 *
 * The application is the same one wp-admin loads, unchanged, and it needed no changes: `App.tsx`
 * already routes with a `HashRouter` *because* WordPress owns the real URL, and `main.tsx` mounts
 * into whatever `#plugixa-inventory-root` it finds. So `/inventory/#/items` works for the same reason
 * `admin.php?page=plugixa-inventory#/contacts` does.
 *
 * NO NEW AUTHORISATION CODE EXISTS FOR THIS, WHICH IS THE FEATURE. The page talks to the same REST
 * API, so `Capabilities::user_can()`, the Roles scoping and the field-level guard all apply exactly
 * as they do in wp-admin. A front end that had its own idea of who may see what would be a second
 * policy to keep in step with the first, and that is where an inventory leaks.
 *
 * ---
 *
 * TWO ARRANGEMENTS, AND THE SECOND ONE IS THE DEFAULT.
 *
 * `mode="embed"` (the default) renders an IFRAME inside the themed page, so the site's header,
 * navigation and footer stay where they are. `mode="full"` — and the `?plugixa_inventory_view=app`
 * request that the iframe itself loads — renders the bare TAKEOVER: this plugin's own document, with
 * the theme's stylesheets removed.
 *
 * WHY AN IFRAME RATHER THAN A DIV, WHICH IS THE WHOLE DESIGN. A theme styles bare inputs at
 * specificity (0,1,1) and every MUI rule is one Emotion class at (0,1,0), with nothing in between —
 * so the app cannot be made to survive an arbitrary theme's stylesheet, and a reset specific enough
 * to beat the theme also beats MUI. Worse, the app's own theme overrides bare `a`, `a:hover` and
 * `a:focus-visible` UNSCOPED, and `<CssBaseline/>` repaints `body`: mounted straight into a themed
 * page it would restyle every link in the site's header and footer and repaint the page background.
 *
 * A shadow root does not solve it either. MUI portals every Dialog, Menu, Tooltip, Snackbar, Select
 * and Autocomplete to `document.body` — some eighty-five call sites — and each would need a
 * `container` default-prop; Emotion would need a cache pointed inside the shadow tree; and
 * `wp.media`, which is WordPress's and not ours, would still land outside the boundary. CSS cascade
 * layers do not close it either, because an `!important` declaration in a LOWER layer beats an
 * unlayered normal one, so a theme with `input { padding: … !important }` wins anyway.
 *
 * The iframe is a real isolation boundary, and it pays for itself twice more: inside a frame the
 * frame IS the viewport, so `Layout`'s `height: calc(100vh - …)` becomes true rather than a
 * lie, and its `useMediaQuery` breakpoints — which are viewport queries — start reporting the
 * frame's width, so a narrow embed gets the existing collapsed-sidebar layout for free.
 *
 * THE FRAME LOADS THIS SAME PAGE. Its `src` is the permalink plus `?plugixa_inventory_view=app`,
 * which takes the takeover path below — same template, same style strip, same bundle, same
 * `Config::inject( 'page' )`. There is no second bundle and no duplicated render path, and that is
 * deliberate: the embedded arrangement cannot drift from the full-page one because it *is* the
 * full-page one.
 *
 * ---
 *
 * ON ATTRIBUTES. This class used to refuse them outright. The reason it gave was specific — a
 * `route=` attribute would have to be reconciled against the fragment on every navigation, because
 * `HashRouter` already deep-links — and that reason still holds: there is still no `route`. It never
 * argued against describing the ARRANGEMENT, which is what `mode`, `width` and `height` do, and a
 * layout choice cannot fight the router because the router does not know about layout.
 *
 * Still no Gutenberg block. A block would have to advertise both arrangements or pick one, and the
 * takeover half still cannot honestly appear in an editor that shows it surrounded by other blocks
 * which will not survive the style strip.
 */
final class AppShortcode {

	public const TAG = 'plugixa_inventory_app';

	/**
	 * The query variable that asks this page for the bare application document.
	 *
	 * A REGISTERED QUERY VAR RATHER THAN A RAW `$_GET` READ, and the reason is not tidiness. Reading
	 * the superglobal means a `WordPress.Security.NonceVerification` suppression on every call site,
	 * on a value that grants nothing — the capability gate is a separate question answered by
	 * {@see AppRenderer::is_permitted()}. Going through `query_vars` also means the value is parsed
	 * once, by WordPress, and every reader downstream sees the same answer.
	 */
	public const VIEW_QUERY = 'plugixa_inventory_view';

	/** The only value {@see self::VIEW_QUERY} recognises. */
	public const VIEW_APP = 'app';

	/** Inside the themed page, in an iframe. The default. */
	public const MODE_EMBED = 'embed';

	/** The bare document, no theme. What this shortcode used to do unconditionally. */
	public const MODE_FULL = 'full';

	/**
	 * Body class marking the document the iframe itself loads.
	 *
	 * The stylesheet hangs one rule off it; see `assets/frontend/css/app-page.css`.
	 */
	public const FRAME_BODY_CLASS = 'plugixa-inventory-app-frame';

	private readonly AppAssets $assets;

	private readonly EmbedAssets $embed;

	private readonly AppRenderer $renderer;

	public function __construct() {
		$this->assets   = new AppAssets();
		$this->embed    = new EmbedAssets();
		$this->renderer = new AppRenderer( $this->assets, $this->embed );
	}

	public function register_hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );

		/*
		 * The embed's own two files. It registers them itself rather than being hooked from here,
		 * which keeps the rule the docblock below states — every callback on one of OUR hooks is a
		 * method on THIS class — true of both objects rather than true of neither.
		 */
		$this->embed->register_hooks();

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'remove_inline_theme_css' ), 21 );

		add_filter( 'template_include', array( $this, 'take_over_template' ), 99 );
		add_filter( 'print_styles_array', array( $this, 'strip_theme_styles' ) );
		add_filter( 'body_class', array( $this, 'mark_body' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_in_frame' ) );
	}

	/**
	 * @param string[] $vars Recognised public query variables.
	 *
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::VIEW_QUERY;

		return $vars;
	}

	/**
	 * Everything hooked above is a method on THIS class, delegating where the work lives, so that
	 * the public surface and the hook list are the same list. A test of these must drive its own
	 * instance directly rather than go through the global filters — the booted plugin has already
	 * registered a different instance on those same hooks, so `apply_filters()` would be asking that
	 * one instead. {@see \PlugixaInventory\Tests\AppShortcodeTest}, which does exactly that.
	 *
	 * @param string[] $handles Handles WordPress is about to print.
	 *
	 * @return string[]
	 */
	public function strip_theme_styles( array $handles ): array {
		return $this->assets->strip_theme_styles( $handles );
	}

	public function remove_inline_theme_css(): void {
		$this->assets->remove_inline_theme_css();
	}

	/**
	 * Whether this request is the bare application document — the one the iframe loads.
	 *
	 * Safe to ask from `show_admin_bar` as well as from the template and enqueue hooks: WordPress
	 * hooks `_wp_admin_bar_init()` to `template_redirect`, which runs after the main query has been
	 * parsed, so `get_query_var()` has an answer by then.
	 */
	public function is_frame_request(): bool {
		return self::VIEW_APP === (string) get_query_var( self::VIEW_QUERY );
	}

	/**
	 * The admin bar belongs to the page AROUND the frame, not inside it.
	 *
	 * Two of them would stack — one in the parent document and one in the frame, the second scrolling
	 * with the app. The parent keeps its own, which is also the way back out of the inventory.
	 *
	 * Nothing to undo for the full-page arrangement: there the frame request IS the page somebody is
	 * looking at, and it keeps the bar.
	 *
	 * @param bool $show Whether WordPress intends to show the toolbar.
	 */
	public function hide_admin_bar_in_frame( bool $show ): bool {
		return $this->is_frame_request() ? false : $show;
	}

	/**
	 * Whether this request is an inventory page being looked at by somebody who may use it, in the
	 * arrangement that takes the whole document over.
	 *
	 * BOTH HALVES, AND THE CAPABILITY HALF IS NOT AN OPTIMISATION. Everything downstream keys off
	 * this one answer — the template swap, the style strip, the bundle, the nonce. A visitor who
	 * cannot use the inventory must get an ordinary themed page with a notice on it, which means they
	 * must fail this test rather than pass it and be turned away later.
	 *
	 * THE EMBEDDED ARRANGEMENT DELIBERATELY ANSWERS FALSE HERE. Its whole point is that the theme
	 * survives, so it must not strip styles and must not swap the template; the document it renders
	 * is an ordinary page that happens to contain an iframe.
	 */
	public function is_app_page(): bool {
		$is = false;

		if ( is_singular() ) {
			$post = get_post();

			$is = $post instanceof \WP_Post
				&& has_shortcode( (string) $post->post_content, self::TAG )
				&& ( $this->is_frame_request() || $this->post_wants_takeover( $post ) )
				&& $this->renderer->is_permitted();
		}

		/**
		 * Filter whether the current request is the bare inventory application document.
		 *
		 * `has_shortcode()` can only read `post_content`, so a page builder that stores its layout
		 * in post meta renders the shortcode without this ever seeing it — the app would mount
		 * inside the theme and fight its stylesheet. Such a site opts its page in here.
		 *
		 * NOTE THAT THIS NOW MEANS "TAKEOVER", NOT MERELY "HAS THE APP ON IT". Since the embedded
		 * arrangement became the default, a page carrying the shortcode is ordinarily NOT an app
		 * page by this test. Returning true here asks for the bare document.
		 *
		 * A filter that returns true still has to get past the capability gate in the renderer, so
		 * this cannot be used to hand the inventory to somebody who may not use it.
		 *
		 * @param bool $is Whether this is the bare inventory application document.
		 */
		return (bool) apply_filters( 'plugixa_inventory_is_app_page', $is );
	}

	/**
	 * Whether this page is showing the app inside the theme.
	 *
	 * The mirror of {@see self::is_app_page()} and deliberately exclusive with it, so the two
	 * arrangements can never both be true for one request and enqueue each other's assets.
	 */
	public function is_embed_page(): bool {
		if ( $this->is_frame_request() || ! is_singular() ) {
			return false;
		}

		$post = get_post();

		return $post instanceof \WP_Post
			&& has_shortcode( (string) $post->post_content, self::TAG )
			&& ! $this->post_wants_takeover( $post )
			&& $this->renderer->is_permitted();
	}

	/**
	 * Whether any `[plugixa_inventory_app]` on this post asked for the bare document.
	 *
	 * ONE `mode="full"` ANYWHERE ON THE POST DECIDES FOR THE WHOLE POST, because the thing being
	 * chosen is the DOCUMENT — the template and the stylesheet queue are not per-block. A post
	 * carrying both arrangements is a contradiction somebody wrote by accident, and resolving it
	 * towards the takeover is the reading that leaves the app usable rather than the one that leaves
	 * it fighting a stylesheet.
	 *
	 * Escaped shortcodes (`[[plugixa_inventory_app]]`) are skipped: WordPress renders those as
	 * literal text, so a page documenting the tag must not become an app page by mentioning it.
	 */
	private function post_wants_takeover( \WP_Post $post ): bool {
		$content = (string) $post->post_content;

		if ( ! has_shortcode( $content, self::TAG ) ) {
			return false;
		}

		$found = preg_match_all(
			'/' . get_shortcode_regex( array( self::TAG ) ) . '/',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		if ( ! $found ) {
			return false;
		}

		foreach ( $matches as $match ) {
			// Group 1 is the opening bracket of an escaped `[[shortcode]]`; group 3 the attributes.
			if ( '[' === ( $match[1] ?? '' ) ) {
				continue;
			}

			$atts = shortcode_parse_atts( $match[3] ?? '' );

			if ( self::MODE_FULL === $this->mode( is_array( $atts ) ? $atts : array() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The arrangement one shortcode asked for, normalised.
	 *
	 * Anything unrecognised is the default rather than an error: a typo in an attribute should leave
	 * a working inventory on the page, not a blank one.
	 *
	 * @param array<string, mixed> $atts Raw shortcode attributes.
	 */
	private function mode( array $atts ): string {
		$mode = isset( $atts['mode'] ) ? sanitize_key( (string) $atts['mode'] ) : self::MODE_EMBED;

		return self::MODE_FULL === $mode ? self::MODE_FULL : self::MODE_EMBED;
	}

	/**
	 * Enqueue in the head rather than waiting for the shortcode to run.
	 *
	 * `the_content()` runs long after `wp_enqueue_scripts`, so leaving it to the render callback
	 * would put the stylesheet in the footer and show the app unstyled first. The callback enqueues
	 * as well, as the backstop for a widget or template `is_app_page()` cannot see.
	 */
	public function maybe_enqueue(): void {
		if ( $this->is_app_page() ) {
			$this->assets->enqueue();

			return;
		}

		if ( $this->is_embed_page() ) {
			$this->embed->enqueue();
		}
	}

	/**
	 * Render the whole page ourselves, with no theme around it.
	 *
	 * `template_include` fires before `get_header()` and before `wp_enqueue_scripts`, so deciding
	 * here is early enough for the strip to take effect on the same request.
	 *
	 * `locate_template()` first, so a theme that wants to supply its own shell can — it is handed a
	 * page with no theme chrome, which is a reasonable thing to want to influence.
	 *
	 * @param string $template The template WordPress resolved.
	 */
	public function take_over_template( string $template ): string {
		if ( ! $this->is_app_page() ) {
			return $template;
		}

		$override = locate_template( array( 'plugixa-inventory-app.php' ) );

		if ( '' !== $override ) {
			return $override;
		}

		/*
		 * Only for OUR template. A theme that has replaced the document owns its own styling, and
		 * registering a handle it will never print would just leave a dead entry in WP_Styles.
		 */
		$this->assets->register_page_style();

		return __DIR__ . '/views/app-page.php';
	}

	/**
	 * @param string[] $classes Existing body classes.
	 *
	 * @return string[]
	 */
	public function mark_body( array $classes ): array {
		if ( ! $this->is_app_page() ) {
			return $classes;
		}

		$classes[] = 'plugixa-inventory-app-page';

		/*
		 * The frame needs one rule the standalone document must not get: zeroing
		 * `--wp-admin--admin-bar--height`. `Layout` subtracts that variable from `100vh`, with a
		 * 32px fallback for when it is undefined — and it IS undefined in here, because the admin
		 * bar this document does not render is what defines it. Without this class the frame would
		 * carry 32px of dead space at the bottom, on every page, for a toolbar that is not there.
		 */
		if ( $this->is_frame_request() ) {
			$classes[] = self::FRAME_BODY_CLASS;
		}

		return $classes;
	}

	/**
	 * @param array<string, mixed>|string $atts Shortcode attributes: mode, width, height.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'mode'   => self::MODE_EMBED,
				'width'  => EmbedRenderer::WIDTH_WIDE,
				'height' => 0,
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		/*
		 * The frame request wins over whatever the attributes say. It is this same shortcode running
		 * inside the iframe it produced, and a second iframe there would nest for ever.
		 */
		$mode = $this->is_frame_request() ? self::MODE_FULL : $this->mode( $atts );

		return $this->renderer->render( $mode, (string) $atts['width'], (int) $atts['height'] );
	}
}
