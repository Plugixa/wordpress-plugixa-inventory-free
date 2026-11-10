<?php
/**
 * The bare document the inventory application page renders into.
 *
 * NO `get_header()` AND NO `get_footer()`, DELIBERATELY. {@see \PlugixaInventory\Core\Frontend\AppAssets}
 * removes the theme's stylesheets from this page, because a theme's `input[type=text]` rule beats
 * every MUI rule by construction and no reset can sit between them. Rendering the theme's header and
 * navigation without the theme's CSS would produce an unstyled theme wrapped around a styled
 * application, which is worse than either extreme — so the strip and this template are one decision.
 *
 * `wp_footer()` IS MANDATORY, not decorative. The application is enqueued with
 * `wp_enqueue_script_module()`, and on a CLASSIC theme WordPress prints both the import map and the
 * modules themselves on `wp_footer`. Omit the call and no bundle reaches the page at all — and
 * because `main.tsx` guards on `document.readyState` rather than erroring, the symptom is a blank
 * white page with nothing in the console.
 *
 * A theme may replace this file wholesale by shipping `plugixa-inventory-app.php`; see
 * `AppShortcode::take_over_template()`.
 *
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php
	/*
	 * The UA default is `body { margin: 8px }`, and with the theme's stylesheet gone there is
	 * nothing else to say otherwise until React boots and MUI's CssBaseline — which is injected at
	 * runtime by Emotion, not shipped in the built stylesheet — sets it to zero. Without these two
	 * lines the app visibly jumps 8px on mount.
	 *
	 * Printed BEFORE wp_head() on purpose: the admin bar attaches `html { margin-top: 32px
	 * !important }` inline to its own handle, and that has to win. It does, both because it is
	 * !important and because it is printed after this.
	 *
	 * A FILE PRINTED BY HANDLE, NOT AN INLINE <style> BLOCK, AND NOT `wp_add_inline_style`.
	 * wordpress.org rejects a raw style tag in shipped PHP, and the sniff that is supposed to catch
	 * one cannot: `WordPress.WP.EnqueuedResources` matches only tags carrying src or href, so the
	 * inline block that used to sit here was invisible to PHPCS and to Plugin Check, and both gates
	 * passed while it was live. `wp_print_styles()` prints THIS HANDLE ONLY — calling `wp_head()`
	 * earlier instead would drag the whole queue, and the theme with it, onto the page.
	 * {@see \PlugixaInventory\Core\Frontend\AppAssets::register_page_style()} for why the handle is
	 * registered from `template_include` rather than from `wp_enqueue_scripts`.
	 */
	wp_print_styles( \PlugixaInventory\Core\Frontend\AppAssets::PAGE_HANDLE );
	wp_head();
	?>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();

/*
 * The loop, so `the_content()` runs and the shortcode with it. The application mounts into the
 * element that shortcode returns; nothing else on the page is ours.
 */
while ( have_posts() ) {
	the_post();
	the_content();
}

wp_footer();
?>
</body>
</html>
