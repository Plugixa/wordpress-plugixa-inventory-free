<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Frontend;

use PlugixaInventory\Core\App\Bundle;
use PlugixaInventory\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What `[plugixa_inventory_app]` puts on the page.
 *
 * THREE OUTCOMES AND NO FOURTH: the application's mount point for somebody who may use the
 * inventory and asked for the bare document; an iframe onto that same document for somebody who
 * asked for it inside the theme; and one short notice for everybody else.
 *
 * (This said "two outcomes and no third" for as long as the takeover was the only arrangement. The
 * third is the embedded one — see {@see AppShortcode} for why it has to be an iframe and not a div.
 * The refusal is untouched and is still the only answer to the question of access.)
 *
 * THE NOTICE IS THE SAME FOR ALL THREE WAYS OF NOT BEING ALLOWED IN — signed out, signed in without
 * the capability, and signed in as a portal customer. This is the rule `PortalRenderer` already
 * follows in the other direction, and it matters more here: this page has a public URL, so a
 * distinct "you are signed in but not staff" message would confirm to any visitor that the site runs
 * an inventory, and a redirect for the portal case would confirm to a customer that the site knows exactly
 * who they are. Neither visitor can act on that answer differently — both need to talk to whoever
 * grants access — so neither is told.
 *
 * IT IS ALSO THE SAME IN BOTH ARRANGEMENTS. A refused visitor gets the ordinary themed page and the
 * notice whether the shortcode asked for an embed or a takeover, because the arrangement is a
 * decision about the app and there is no app to arrange.
 *
 * A portal user is deliberately NOT bounced to the portal for the same reason, and for a second one:
 * Core is not allowed to know that the Portal module exists (`CoreIndependenceTest`), and a bounce
 * would mean naming it.
 *
 * NOTHING IS ENQUEUED ON THE WAY TO THE NOTICE. No bundle, no configuration, no REST nonce, no
 * `DONOTCACHEPAGE`, no template swap and no style strip — the page is an ordinary themed page
 * carrying a paragraph. That is both the safe answer and the cheap one.
 */
final class AppRenderer {

	private readonly EmbedRenderer $embed_renderer;

	public function __construct(
		private readonly AppAssets $assets,
		EmbedAssets $embed
	) {
		$this->embed_renderer = new EmbedRenderer( $embed );
	}

	/**
	 * Whether the current visitor may use the inventory.
	 *
	 * The same capability the admin menu is registered under, and the same one
	 * `Capabilities::user_can()` asks for before any record-level rule is consulted. Deliberately not
	 * a second capability of its own: "may reach the inventory from the front end" is not a different
	 * question from "may reach the inventory", and a separate capability would be one more thing for a
	 * site to get wrong in a direction that opens rather than closes.
	 *
	 * Every narrower rule — Roles, team scoping, field-level ACL — is applied by the REST API this
	 * page then talks to, exactly as it is for wp-admin. This gate is the door, not the policy.
	 */
	public function is_permitted(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * @param string $mode   One of AppShortcode::MODE_EMBED or ::MODE_FULL, already normalised.
	 * @param string $width  Requested embed width; ignored for the takeover.
	 * @param int    $height Requested embed height in pixels, 0 for the stylesheet's own answer.
	 */
	public function render( string $mode, string $width = EmbedRenderer::WIDTH_WIDE, int $height = 0 ): string {
		if ( ! $this->is_permitted() ) {
			return $this->no_access();
		}

		if ( AppShortcode::MODE_FULL === $mode ) {
			$this->assets->enqueue();

			return '<div class="plugixa-inventory-app">' . Bundle::mount_html() . '</div>';
		}

		return $this->embed_renderer->render( $width, $height );
	}

	/**
	 * One notice, three audiences, byte-identical output. See the class docblock.
	 *
	 * The sign-in link is shown even to somebody already signed in, and that is the point rather
	 * than an oversight: the alternative is a different page for a logged-in visitor, which is the
	 * distinction this method exists to refuse to make. `PortalRenderer::signed_out()` accepts the
	 * same small oddity for the same reason.
	 */
	private function no_access(): string {
		$login = wp_login_url( $this->current_url() );

		return '<div class="plugixa-inventory-app plugixa-inventory-app--no-access">'
			. '<p>' . esc_html__( 'Sign in to open the inventory.', 'plugixa-inventory' ) . '</p>'
			. '<p><a class="plugixa-inventory-app__signin-link" href="' . esc_url( $login ) . '">'
			. esc_html__( 'Sign in', 'plugixa-inventory' ) . '</a></p>'
			. '</div>';
	}

	/**
	 * Where to come back to after signing in.
	 *
	 * `get_permalink()` rather than the raw request, because the raw request is attacker-controlled
	 * and `wp_login_url()` puts whatever it is given into `redirect_to`. WordPress validates that
	 * against the site host on the way out, so this is belt and braces — but the permalink of the
	 * post we are rendering is both safe by construction and the right destination anyway.
	 */
	private function current_url(): string {
		$post = get_post();

		if ( $post instanceof \WP_Post ) {
			$link = get_permalink( $post );

			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}

		return home_url( '/' );
	}
}
