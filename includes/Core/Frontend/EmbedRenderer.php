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
 * The iframe that puts the inventory inside the theme.
 *
 * IT POINTS AT THE PAGE IT IS ON. The `src` is this post's own permalink plus
 * `?plugixa_inventory_view=app`, which {@see AppShortcode} answers with the bare application
 * document — the same template, the same style strip and the same bundle the full-page arrangement
 * uses. Nothing about the app is duplicated for the embedded case, so the two cannot drift apart.
 *
 * `allow="fullscreen"` IS THE WHOLE OF THE FULL-SCREEN FEATURE ON THIS SIDE. With it, code inside
 * the frame may call `requestFullscreen()` itself, so the app's own toolbar button needs no message
 * passed out to the parent and no cooperation from this class beyond the attribute. The app
 * fullscreens its own `documentElement`, which is why every portalled dialog and menu comes with it
 * — they are children of that document's `body`, and would simply not render if the app tried to
 * fullscreen its mount element instead.
 *
 * NO `loading="lazy"`. The app is the reason the visitor opened the page; deferring it until it
 * scrolls into view would trade the thing they came for against bytes they have already decided to
 * spend.
 *
 * WHY THE WIDTH IS NOT SIMPLY 100%. A shortcode renders inside whatever container the theme puts
 * the post content in, and for a block theme that is the constrained column — 645px in Twenty
 * Twenty-Five. The app's sidebar alone is 240px, and below the `md` breakpoint the shell drops to
 * its narrow layout, so a frame left in that column shows a phone-shaped inventory on a desktop.
 * The width therefore has to escape the column, and it does that with WordPress's own alignment
 * classes rather than with CSS of ours — see {@see self::ALIGNMENTS}, which records what happened
 * when this fought the theme instead.
 */
final class EmbedRenderer {

	/** The theme's own wide alignment. The default. */
	public const WIDTH_WIDE = 'wide';

	/** The full window, breaking out of the theme's layout entirely. */
	public const WIDTH_FULL = 'full';

	/** The ordinary text column. Honest but cramped; offered because some sites will want it. */
	public const WIDTH_CONTENT = 'content';

	private const WIDTHS = array(
		self::WIDTH_WIDE,
		self::WIDTH_FULL,
		self::WIDTH_CONTENT,
	);

	/**
	 * WordPress's OWN alignment vocabulary, which is how the width is actually applied.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * THE FIRST VERSION OF THIS FOUGHT THE THEME WITH CSS AND LOST, VISIBLY.
	 *
	 * A block theme's constrained layout emits `.wp-block-post-content-is-layout-constrained >
	 * :where(:not(.alignleft):not(.alignright):not(.alignfull)) { max-width: <content-size> }`.
	 * That selector is specificity (0,1,0) — exactly the same as a single class of our own — so a
	 * `.plugixa-inventory-embed--wide { width: … ; max-width: … }` rule TIES with it and loses on
	 * source order, because global styles print inline in the head and this plugin's stylesheet is
	 * a linked file. The measured result was a 645px frame shoved to the right of the column, with
	 * the app inside it dropping to its narrow layout because 645 is below the `md` breakpoint.
	 *
	 * Look at that selector again, though: it EXCLUDES the alignment classes. So the supported way
	 * to be wider than the text column is not to out-specify the rule, it is to be the case the
	 * rule already declines to match. `alignwide` and `alignfull` are read by every block theme,
	 * are what the editor's own width control writes, and pick up each theme's own wide size and
	 * its global padding offsets for free.
	 *
	 * The cost is honest and small: a CLASSIC theme may style neither class, in which case the
	 * frame is simply as wide as its container. That is the right failure — the app fills what it
	 * is given — and it is what happened on those themes before any of this existed.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 */
	private const ALIGNMENTS = array(
		self::WIDTH_WIDE    => 'alignwide',
		self::WIDTH_FULL    => 'alignfull',
		self::WIDTH_CONTENT => '',
	);

	/**
	 * Below this the app has less room than its own sidebar plus a usable table.
	 *
	 * Not a clamp on what a site may ask for — it is the floor the stylesheet applies when nobody
	 * asks at all, kept here so the two numbers that describe the frame live together.
	 */
	private const MIN_HEIGHT = 320;

	public function __construct( private readonly EmbedAssets $assets ) {}

	/**
	 * @param string $width  One of self::WIDTHS; anything else falls back to wide.
	 * @param int    $height Height in pixels, or 0 to let the stylesheet decide.
	 */
	public function render( string $width, int $height = 0 ): string {
		$src = $this->frame_url();

		/*
		 * No permalink means no frame to point at — a preview of an unsaved post, or a shortcode run
		 * outside the loop. Rendering an iframe with an empty src would load the current page inside
		 * itself, which is a browser-hanging recursion rather than a cosmetic failure.
		 */
		if ( '' === $src ) {
			return '';
		}

		$this->assets->enqueue();

		$width = $this->width( $width );

		$classes = array( 'plugixa-inventory-embed', 'plugixa-inventory-embed--' . $width );

		$alignment = self::ALIGNMENTS[ $width ];

		if ( '' !== $alignment ) {
			$classes[] = $alignment;
		}

		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $this->style( $height ) . '>'
			. '<iframe class="plugixa-inventory-embed__frame"'
			. ' src="' . esc_url( $src ) . '"'
			. ' title="' . esc_attr__( 'Inventory', 'plugixa-inventory' ) . '"'
			. ' allow="fullscreen"'
			. '></iframe>'
			. '</div>';
	}

	/**
	 * This post, asking for the bare application document.
	 *
	 * `get_permalink()` rather than the current request URL: the request may carry pagination,
	 * tracking parameters or a preview nonce, none of which the frame should inherit, and one of
	 * which it must not.
	 */
	private function frame_url(): string {
		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$link = get_permalink( $post );

		if ( ! is_string( $link ) || '' === $link ) {
			return '';
		}

		return (string) add_query_arg( AppShortcode::VIEW_QUERY, AppShortcode::VIEW_APP, $link );
	}

	/**
	 * @param string $width Requested width keyword.
	 */
	private function width( string $width ): string {
		$width = sanitize_key( $width );

		return in_array( $width, self::WIDTHS, true ) ? $width : self::WIDTH_WIDE;
	}

	/**
	 * The one inline style attribute, and only when a site asked for a specific height.
	 *
	 * A custom PROPERTY rather than `height` itself, so the stylesheet keeps ownership of the rule
	 * and can still apply its minimum and its full-screen override on top. An inline `height` would
	 * win over both and leave a site that typed a small number with an app it cannot use.
	 *
	 * A style ATTRIBUTE is not what `WordPress.WP.EnqueuedResources` objects to — that sniff is about
	 * `<style>` and `<script>` tags, and a per-instance value genuinely cannot live in a stylesheet.
	 *
	 * @param int $height Requested height in pixels; 0 means the stylesheet decides.
	 */
	private function style( int $height ): string {
		if ( $height <= 0 ) {
			return '';
		}

		$height = max( self::MIN_HEIGHT, $height );

		return ' style="--pxi-embed-height:' . esc_attr( (string) $height ) . 'px"';
	}
}
