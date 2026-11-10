<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Admin;

use PlugixaInventory\Core\App\Bundle;
use PlugixaInventory\Core\App\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the compiled application on the wp-admin inventory screen, and takes wp-admin off it.
 *
 * WHAT REMAINS HERE IS EVERYTHING THAT IS ABOUT WP-ADMIN, and only that. Loading the bundle and
 * injecting its configuration moved to `Core\App\Bundle` and `Core\App\Config` when
 * `[plugixa_inventory_app]` started mounting the same application on a public page — those are facts about
 * the app, and a second copy of them would drift.
 *
 * The style work below did NOT move, and must not: `KEEP_STYLES`, `DROP_STYLES`,
 * `strip_unused_admin_styles()` and `reset_admin_chrome()` are each an answer to a specific
 * *wp-admin* stylesheet — `forms`, the admin colour scheme's focus ring, `common.css`'s heading
 * colour and its `[role="treeitem"]` rule. None of those load on the front end. Re-using any of it
 * there would be exactly the mistake `reset_admin_chrome()` warns about below: a rule with nothing
 * to fight is not harmless, because anything this file says, MUI loses.
 *
 * The front-end page has the mirror-image problem — the active THEME plays wp-admin's part — and it
 * is solved the same way but with the opposite shape, an allow-list rather than a block-list,
 * because "every theme and every other plugin" is not a list anyone can write down. See
 * `Core\Frontend\AppAssets`.
 */
final class Assets {

	/**
	 * wp-admin stylesheets the inventory screen keeps.
	 *
	 * WordPress still draws the admin bar and the left menu around the app, and they have to keep
	 * working. Everything on this list is here because something visible would break without it —
	 * not because it looked important.
	 *
	 *   dashicons                  the menu's and the admin bar's icons
	 *   admin-bar, admin-menu      the chrome itself
	 *   common                     #wpwrap, #wpcontent, and admin NOTICES — the layout backbone
	 *   buttons                    `.button` inside those notices
	 *   l10n, site-icon            small pieces of the same chrome
	 *   wp-components, wp-commands the Ctrl+K command palette, which is live on this page.
	 *                              wp-commands depends on wp-components; drop one and the other breaks
	 *   wp-auth-check              the session-expiry modal. If the login lapses while the user is in
	 *                              the inventory, this is the only way back in without losing the page
	 *
	 * `media-views` is NOT on this list and is no longer on the drop list either — it is simply not
	 * mentioned, which leaves it alone. It arrives only when `wp_enqueue_media()` asks for it, and
	 * the item form's image picker is what asks. Dropping it while opening the modal would render an
	 * unstyled media library; keeping it unconditionally would load the media CSS on every screen
	 * that has no picker on it. Letting the enqueue decide is the honest middle.
	 *
	 * ITS RULES DO NOT REACH THE APP. `media-views.css` is scoped to `.media-modal`, `.media-frame`,
	 * `.media-menu`, `.attachment` and friends — class selectors that exist nowhere in a MUI tree,
	 * and none of the bare-element selectors that made `forms` unusable here.
	 */
	private const KEEP_STYLES = array(
		'dashicons',
		'admin-bar',
		'admin-menu',
		'common',
		'buttons',
		'l10n',
		'site-icon',
		'wp-components',
		'wp-commands',
		'wp-auth-check',
	);

	/**
	 * wp-admin stylesheets the inventory screen drops.
	 *
	 * These style screens this page does not render: there is no list table here, no post editor, no
	 * widget or menu editor. They are dead weight — and `forms` is worse than dead weight, it is
	 * actively wrong. See strip_unused_admin_styles().
	 *
	 * `media` is the OLD media-upload page's stylesheet and stays dropped. `media-views` — the modal
	 * the picker actually opens — used to be here and was removed; see the KEEP list above.
	 */
	private const DROP_STYLES = array(
		'forms',
		'dashboard',
		'list-tables',
		'edit',
		'revisions',
		'media',
		'themes',
		'about',
		'nav-menus',
		'widgets',
		'wp-pointer',
	);

	/**
	 * Whether the current request is the inventory screen. Set by enqueue(), which only runs there.
	 */
	private bool $on_inventory_screen = false;

	/** Marks the <body> of the inventory screen, so a rule can reach MUI's portals. See reset_admin_chrome(). */
	private const BODY_CLASS = 'plugixa-inventory-screen';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'print_styles_array', array( $this, 'strip_unused_admin_styles' ) );
		add_filter( 'admin_body_class', array( $this, 'mark_body' ) );
	}

	/**
	 * Put a class on the inventory screen's <body>.
	 *
	 * Not decoration. MUI renders every dialog, menu, select popup and tooltip through a Portal into
	 * `document.body` — OUTSIDE `#plugixa-inventory-root`. So a rule scoped to the app's root reaches the
	 * page and misses every dialog on it, which is exactly how a focus ring survived on the Name
	 * field of every create form while the page behind it looked fine.
	 *
	 * A class on the body reaches both. `admin_body_class` is printed after `admin_enqueue_scripts`
	 * has run, so the flag enqueue() sets is already true by the time this is asked.
	 *
	 * @param string $classes Existing body classes.
	 */
	public function mark_body( string $classes ): string {
		return $this->on_inventory_screen ? trim( $classes . ' ' . self::BODY_CLASS ) : $classes;
	}

	/**
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'plugixa-inventory' ) ) {
			return;
		}

		$this->on_inventory_screen = true;

		Bundle::enqueue();
		Config::inject( 'admin' );
		$this->reset_admin_chrome();

		/*
		 * WORDPRESS'S OWN EDITOR, RATHER THAN ONE BUNDLED INTO THE APP.
		 *
		 * An email body and a template body need rich text, and there were two ways to get it: ship a
		 * React editor in the bundle, or use the one already on the machine. This is the same call as
		 * `wp_mail` over an SMTP client, for the same reasons — the bundle is already 1.8 MB and a
		 * second editor would add hundreds of kilobytes; users already know this one, because it is
		 * what they write posts in; and its output is exactly the subset `wp_kses_post()` accepts on
		 * the way back in, which a third-party editor would have to be taught.
		 *
		 * The editor is asked for WITHOUT media buttons. That used to be because `media-views` was
		 * dropped and the modal would have opened unstyled; it is not dropped any more, so the reason
		 * is now the simpler one it always also was — an email body has nowhere to put an attachment.
		 * That is what the Attachments panel is for.
		 */
		wp_enqueue_editor(
			array(
				'tinymce'   => true,
				'quicktags' => true,
			)
		);

		/*
		 * THE MEDIA LIBRARY, FOR THE ITEM IMAGE PICKER.
		 *
		 * `wp.media` does not exist on a page until this is called — it brings the backbone views,
		 * the modal, and the `media-views` stylesheet that draws it. `shared/MediaPicker.tsx` opens
		 * it; without this the button would throw on `wp.media is not a function`.
		 *
		 * IT IS CALLED ON THE WHOLE INVENTORY SCREEN, NOT JUST THE ITEM FORM, because this is one
		 * server-rendered page and the React router decides what is on it — there is no hook that
		 * fires when the user navigates to `/items/97/edit`. The alternative is loading it lazily
		 * from JavaScript, which means shipping our own copy of WordPress's own script loader.
		 */
		wp_enqueue_media();

		/**
		 * Fires on the inventory screen, once the app bundle is enqueued.
		 *
		 * Kept although nothing consumes it today, because it answers a question that has exactly one
		 * correct answer — "am I on the inventory screen?" — and any code that needs to add something to
		 * this page should read that answer here rather than keep its own copy of the check.
		 *
		 * @param string $hook_suffix The admin page being rendered.
		 */
		do_action( 'plugixa_inventory_enqueue_assets', $hook_suffix );
	}

	/**
	 * Take wp-admin's unused stylesheets off this one screen.
	 *
	 * WHY THIS IS A REMOVAL AND NOT A RESET. Do not "fix" it back into one.
	 *
	 * WordPress styles bare form elements — `input[type=text]`, `select`, `textarea` — at specificity
	 * (0,1,1), and their `:focus` state at (0,2,1). Every MUI rule is a single Emotion class, (0,1,0).
	 * WordPress therefore wins on every field in the app, by construction:
	 *
	 *     WORDPRESS  input[type="text"]  (0,1,1)  padding: 0 12px      <-- applied
	 *     emotion    .css-aae3xl         (0,1,0)  padding: 4px 0 5px   <-- lost
	 *
	 * and likewise on min-height, border, border-radius, background-color, color and margin.
	 *
	 * A reset cannot win this. Any selector specific enough to beat (0,1,1) is ALSO more specific
	 * than MUI's (0,1,0) — so a rule that neutralises WordPress's padding clobbers MUI's padding in
	 * the same breath, and the field ends up with neither. There is no specificity that sits between
	 * the two. The conflict has to be removed, not out-shouted.
	 *
	 * And it has to be removed HERE. `wp-admin` is a meta handle that DEPENDS on `forms`, so
	 * wp_dequeue_style('forms') silently does nothing — dependency resolution puts it straight back.
	 * `print_styles_array` fires inside WP_Styles::all_deps(), AFTER resolution, on the final list of
	 * handles about to be printed. A handle removed here is removed even though something depends on
	 * it, and no core object is mutated to do it.
	 *
	 * @param string[] $handles Handles WordPress is about to print.
	 *
	 * @return string[]
	 */
	public function strip_unused_admin_styles( array $handles ): array {
		if ( ! $this->on_inventory_screen ) {
			return $handles;
		}

		return array_values( array_diff( $handles, self::DROP_STYLES ) );
	}

	/**
	 * Strip the padding wp-admin puts around page content.
	 *
	 * The inventory shell is a full-height application, not a document, so it needs the
	 * content column edge-to-edge. There is no stylesheet to attach this to — MUI
	 * injects its styles at runtime through Emotion and the build emits no CSS file —
	 * so it is registered as a src-less handle carrying inline CSS.
	 *
	 * THIS IS DELIBERATELY SMALL, AND IT MUST STAY THAT WAY.
	 *
	 * It used to also reset border, box-shadow, background, min-height, font and colour on every
	 * bare input, and reset the focus ring on top of that — because wp-admin's `forms` stylesheet
	 * was styling MUI's fields out from under it. That stylesheet is no longer loaded on this
	 * screen (see strip_unused_admin_styles), so those rules now have nothing to fight, and a rule
	 * with nothing to fight is not harmless here: every selector below is an ID, (1,x,x), and every
	 * MUI rule is a single Emotion class, (0,1,0). Anything this file says, MUI loses.
	 *
	 * So the test for adding a line here is not "does this look right" — it is: **what wp-admin rule
	 * is this beating, and can that rule be removed instead?** Removing it is nearly always correct.
	 */
	private function reset_admin_chrome(): void {
		$body = 'body.' . self::BODY_CLASS;

		wp_register_style( 'plugixa-inventory-admin', false, array(), PLUGIXA_INVENTORY_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters -- a handle registered with no src on purpose: it exists only to carry wp_add_inline_style(), which is the documented way to attach inline CSS without shipping an empty file.
		wp_enqueue_style( 'plugixa-inventory-admin' );
		wp_add_inline_style(
			'plugixa-inventory-admin',
			// Admin notices are deliberately left alone: suppressing them would hide
			// genuine WordPress and other-plugin warnings from the user.
			'#wpcontent{padding-left:0}'
			. '#wpbody-content{padding-bottom:0}'
			. '#wpfooter{display:none}'
			. '#plugixa-inventory-root{margin:0}'
			// FULL SCREEN MEANS THE INVENTORY, NOT WP-ADMIN. The header's button fullscreens the
			// whole document (the only element every MUI popup is guaranteed to be inside — see
			// useFullscreen in lib/frame.ts), which on its own would just enlarge the admin menu
			// and bar along with the app. While it is fullscreen, WordPress's chrome steps aside.
			//
			// `:fullscreen` rather than a class toggled from React: the browser owns the state, so
			// Escape, F11 and a refused request can never leave the menu hidden behind a stale class.
			// Zeroing the admin-bar variable is what lets the shell's own
			// `calc(100vh - var(--wp-admin--admin-bar--height))` fill the display.
			. 'html:fullscreen{--wp-admin--admin-bar--height:0px;padding-top:0}'
			. 'html:fullscreen #wpadminbar,html:fullscreen #adminmenumain{display:none}'
			. 'html:fullscreen #wpcontent,html:fullscreen #wpfooter{margin-left:0}'
			. 'html:fullscreen #wpbody{padding-top:0}'
			// THE FOCUS RING. This one is not in `forms` and cannot be stripped with it.
			//
			// The admin COLOUR SCHEME (wp-admin/css/colors/*/colors.css) carries its own copy of
			// the rule, in the scheme's own blue:
			//
			//     input[type=text]:focus, …, textarea:focus {
			//         box-shadow: 0 0 0 1.5px var(--wp-admin-theme-color);
			//     }                                              (0,2,1)
			//
			// and that stylesheet is what paints the admin menu and bar, so it stays. MUI shows
			// focus by thickening the fieldset it draws BEHIND the input, and puts no box-shadow on
			// the input itself — so WordPress's ring is not overriding anything, it is simply drawn
			// on top, and the field ends up with both.
			//
			// Scoped to the BODY, not to #plugixa-inventory-root: every dialog, menu and select popup is
			// portaled into document.body, outside the app root. A root-scoped rule fixes the page
			// and misses every form on it. (0,2,2) beats the colour scheme's (0,2,1).
			//
			// box-shadow only. WordPress also sets `border-color` here, but MUI's input has no
			// border to colour — and resetting a border colour we cannot see would mean overriding
			// one MUI might want later.
			. $body . ' input:focus,' . $body . ' select:focus,' . $body . ' textarea:focus'
			. '{box-shadow:none}'
			// THE SAME RING ON LINKS, from `common.css` this time:
			//
			//     a:focus { box-shadow: 0 0 0 1.5px var(--wp-admin-theme-color); }   (0,1,1)
			//
			// `index.css` has always reset it inside #plugixa-inventory-root, and that was enough
			// until the header's account menu grew real rows: its items are links, the menu is
			// portaled into document.body, and a root-scoped reset never reaches it — every row you
			// opened the menu onto wore a blue box. Body-scoped for the reason given above.
			//
			// Keyboard focus is not lost: the theme's own `a:focus-visible` outline still draws.
			. $body . ' a:focus{box-shadow:none}'
			// `common.css` is still loaded — the admin menu and the notices need it — and it
			// hard-codes a near-black colour on h1-h6. MUI's Typography only *inherits* colour, so a
			// bare element rule beats it and every heading sits near-black on the dark theme.
			//
			// Body-scoped for the same reason as above: a DialogTitle is an <h2>, and it is portaled.
			//
			// Colour only. Weight, size and margin are the theme's and every `sx` spacing prop's.
			. $body . ' h1,' . $body . ' h2,' . $body . ' h3,'
			. $body . ' h4,' . $body . ' h5,' . $body . ' h6{color:inherit}'
			// THE TREE CHECKBOX. Also in `common.css`, also unstrippable, and this one does not merely
			// look wrong — it kills the control:
			//
			//     [role="treeitem"] span[aria-hidden] {
			//         display: inline;  position: absolute;      <-- out of flow, onto the label
			//         font-family: dashicons;                    <-- rendered as an icon glyph
			//         pointer-events: none;                      <-- and DEAD to the cursor
			//     }                                       (0,2,1)
			//
			// WordPress means it for its own file trees. But the calendar's resource filter is a MUI
			// TreeView, its rows are `[role="treeitem"]`, and a MUI Checkbox root IS a `<span
			// aria-hidden>` — so the rule lands on it exactly. The resource checkboxes were drawn on
			// top of their own labels and could not be ticked.
			//
			// `span.MuiCheckbox-root`, NOT a bare `span`: WordPress's selector also matches MUI's
			// TouchRipple container, which genuinely wants to be absolutely positioned. Resetting every
			// aria-hidden span in the row would fix the checkbox and break the ripple.
			//
			// (0,4,1) beats (0,2,1). It has to RESTATE MUI's own values — `inline-flex` from
			// PrivateSwitchBase, `relative` from ButtonBase — because once a rule out-specifies another
			// there is no falling back to it: `revert` and `unset` go to the UA default (`inline`) and
			// hand the bug straight back. That coupling is the price of a stylesheet we cannot drop.
			. $body . ' [role="treeitem"] span.MuiCheckbox-root[aria-hidden]'
			. '{display:inline-flex;position:relative;font-family:inherit;font-size:inherit;pointer-events:auto}'
		);
	}
}
