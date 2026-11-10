<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Frontend;

use PlugixaInventory\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A "who am I / sign out" strip on the front end, for development only.
 *
 * WHY IT EXISTS. The plugin renders for three different audiences — staff through
 * `[plugixa_inventory_app]`, customers through `[plugixa_inventory_portal]`, and anybody at all through
 * `[plugixa_inventory_kb]` — and the ONLY way to check that each sees what it should is to be each of them
 * in turn. That means signing out and in repeatedly, and nothing on the front end offers a way to do
 * it: the portal has no sign-out, the app's account menu links to wp-admin, and a portal user is
 * fenced out of wp-admin entirely, so the usual `/wp-admin/` route to a logout link is closed to
 * exactly the account you most need to switch away from.
 *
 * ------------------------------------------------------------------------------------------------
 * IT IS OFF IN PRODUCTION BY DEFAULT, AND THE GATE IS WORDPRESS'S OWN.
 *
 * `wp_get_environment_type()` returns `'production'` unless a site sets `WP_ENVIRONMENT_TYPE`, so
 * this renders nothing on a site that has not deliberately declared itself otherwise. That is
 * stronger than gating on `WP_DEBUG`, which plenty of live sites turn on to chase a bug and which
 * would then start putting a sign-out control on their public pages.
 *
 * To switch it on, in `wp-config.php`:
 *
 *     define( 'WP_ENVIRONMENT_TYPE', 'local' );
 *
 * It also renders nothing for a logged-out visitor — there is nothing to sign out of, and a strip
 * saying "not signed in" on a cached public page would be both useless and wrong the moment the page
 * was served to somebody who was.
 * ------------------------------------------------------------------------------------------------
 *
 * WHAT IT DELIBERATELY IS NOT. It does not switch users, because that would mean either handling
 * passwords or minting a session for an arbitrary account — a one-click impersonation endpoint is
 * the single most dangerous thing this plugin could grow, and "it only runs in development" is not a
 * property that survives somebody copying a database. It signs you out. You sign back in yourself.
 */
final class DevBar {

	public function register_hooks(): void {
		add_action( 'wp_footer', array( $this, 'render' ), 999 );
	}

	/**
	 * Whether this site wants the bar. The ENVIRONMENT question, and only that.
	 *
	 * The filter deliberately does not get a say in whether somebody is signed in — see render().
	 */
	private function enabled(): bool {
		/**
		 * Filter whether the development identity bar renders.
		 *
		 * The escape hatch in both directions: a staging site that reports itself as production can
		 * turn it on, and a local site that does not want it on a particular page can turn it off.
		 * Returning true on a production site is the caller's decision to make and their consequence
		 * to own — which is why the default cannot be reached by accident.
		 *
		 * @param bool $enabled Whether this site wants the bar.
		 */
		return (bool) apply_filters( 'plugixa_inventory_dev_bar', 'production' !== wp_get_environment_type() );
	}

	public function render(): void {
		/*
		 * THE SESSION CHECK COMES FIRST AND IS NOT FILTERABLE, and the split was found by a test
		 * rather than designed in. Both questions used to be folded into the one value the filter
		 * saw, so `add_filter( 'plugixa_inventory_dev_bar', '__return_true' )` — the documented way for a
		 * staging site to switch it on — also forced it to draw for a logged-out visitor: an empty
		 * display name, no roles, and a sign-out link for a session that does not exist.
		 *
		 * "Which sites want this" is a judgement a site can reasonably override. "Is there anybody to
		 * sign out" is a fact, and a filter has no business answering it.
		 */
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! $this->enabled() ) {
			return;
		}

		$user = wp_get_current_user();

		/*
		 * The roles verbatim, which is how this stays useful without Core learning what a portal user
		 * is. `plugixa_inventory_portal` simply appears in the list — Core names no module, `CoreIndependenceTest`
		 * stays green, and the strip still tells you the one thing you came to find out.
		 */
		$roles = implode( ', ', array_map( 'strval', (array) $user->roles ) );
		$roles = '' !== $roles ? $roles : 'none';

		$manage = user_can( $user->ID, Capabilities::MANAGE )
			? __( 'Inventory: yes', 'plugixa-inventory' )
			: __( 'Inventory: no', 'plugixa-inventory' );

		/*
		 * Back to the page you were on. `get_permalink()` rather than the raw request, for the reason
		 * `AppRenderer::current_url()` gives: the request is attacker-controlled and this value goes
		 * into `redirect_to`. WordPress validates it against the site host anyway, so this is belt and
		 * braces — but a permalink cannot be anything but ours.
		 */
		$post   = get_post();
		$here   = $post instanceof \WP_Post ? (string) get_permalink( $post ) : home_url( '/' );
		$logout = wp_logout_url( '' !== $here ? $here : home_url( '/' ) );

		/*
		 * Inline, and small enough to read. A separate stylesheet would mean a handle to register, a
		 * file in `assets/frontend/`, and a line in the release script's ship list — three pieces of
		 * production plumbing for something that must never reach production.
		 */
		printf(
			'<div style="%s">
				<strong>%s</strong>
				<span style="opacity:.75">%s</span>
				<span style="opacity:.75">%s</span>
				<a href="%s" style="color:inherit">%s</a>
				<span style="opacity:.5">%s</span>
			</div>',
			'position:fixed;inset-auto 12px 12px auto;bottom:12px;right:12px;z-index:99998;'
				. 'display:flex;gap:12px;align-items:center;padding:8px 14px;border-radius:8px;'
				. 'background:#111;color:#fff;font:13px/1.4 system-ui,sans-serif;'
				. 'box-shadow:0 2px 12px rgba(0,0,0,.35)',
			esc_html( (string) $user->display_name ),
			esc_html( $roles ),
			esc_html( $manage ),
			esc_url( $logout ),
			esc_html__( 'Sign out', 'plugixa-inventory' ),
			esc_html__( 'dev only', 'plugixa-inventory' )
		);
	}
}
