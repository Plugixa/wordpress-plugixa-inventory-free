<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Http;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The armour every UNAUTHENTICATED route wears.
 *
 * The whole family is wp-admin only, so until the scheduler there was no route a logged-out visitor
 * could reach, and therefore no rate limiter, honeypot or CAPTCHA seam anywhere. A public booking
 * endpoint needs all three, and so will the host's own Web-to-Lead when it lands — so this is host
 * infrastructure, not scheduler code. A public route sets `permission_callback => '__return_true'`
 * (deliberately skipping the cookie-nonce a logged-out visitor cannot have) and then calls this as
 * the FIRST thing inside its handler. Authentication is gone; abuse control replaces it.
 *
 * THREE INDEPENDENT CHECKS, cheapest first:
 *   1. Honeypot   — a form field a human leaves empty and a naive bot fills. Free, catches the bulk.
 *   2. Rate limit — per IP, per action, transient-backed. Stops the same source hammering an endpoint.
 *   3. CAPTCHA    — a filter seam. Nothing is bundled (that would mean an external service and a
 *                   readme disclosure); a site adds Turnstile/hCaptcha by hooking one filter.
 *
 * WHY A HASH OF THE IP, NEVER THE IP. The rate-limit key is a hash so a raw address is not sitting in
 * the options table under a guessable name, and the address never travels in a URL or a response.
 */
final class PublicRequestGuard {

	/**
	 * Run the guard for one public request.
	 *
	 * @param WP_REST_Request      $request The incoming request.
	 * @param array<string, mixed> $options Tuning. Keys:
	 *                                      - action   string Namespaces the rate-limit bucket. Required in spirit.
	 *                                      - limit    int    Requests allowed per window. Default 20.
	 *                                      - window   int    Window length in seconds. Default HOUR_IN_SECONDS.
	 *                                      - honeypot string Name of the field that must be empty. Default '' (off).
	 *
	 * @return true|WP_Error True to proceed; a 400/429 error to refuse.
	 */
	public static function check( WP_REST_Request $request, array $options = array() ): true|WP_Error {
		$action = isset( $options['action'] ) ? sanitize_key( (string) $options['action'] ) : 'public';

		$honeypot = isset( $options['honeypot'] ) ? (string) $options['honeypot'] : '';
		if ( '' !== $honeypot ) {
			$trap = $request->get_param( $honeypot );

			if ( null !== $trap && '' !== trim( (string) $trap ) ) {
				// Silent to the bot's eyes elsewhere, but the route gets a clean refusal it can turn
				// into an innocuous 200 if it wants to. We return an error; the caller decides the face.
				return new WP_Error(
					'plugixa_inventory_rejected',
					__( 'This request could not be processed.', 'plugixa-inventory' ),
					array( 'status' => 400 )
				);
			}
		}

		$limit  = max( 1, (int) ( $options['limit'] ?? 20 ) );
		$window = max( 1, (int) ( $options['window'] ?? HOUR_IN_SECONDS ) );

		$refused = self::rate_limit( $action, $limit, $window );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		/**
		 * Verify a public request against a CAPTCHA/anti-bot service.
		 *
		 * Nothing is registered by default, so the default verdict is "allowed". A site that wants
		 * Turnstile/hCaptcha hooks this, reads its token from the request, and returns false or a
		 * WP_Error to refuse. Returning a WP_Error lets the site control the status and message.
		 *
		 * @param true|false|WP_Error $verdict The running verdict; true unless a filter refuses.
		 * @param WP_REST_Request     $request The request, carrying the CAPTCHA token if any.
		 * @param string              $action  The route's action name.
		 */
		$captcha = apply_filters( 'plugixa_inventory_public_request_captcha', true, $request, $action );

		if ( $captcha instanceof WP_Error ) {
			return $captcha;
		}

		if ( false === $captcha ) {
			return new WP_Error(
				'plugixa_inventory_captcha_failed',
				__( 'The anti-spam check did not pass. Please try again.', 'plugixa-inventory' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Fixed-window per-IP counter in a transient.
	 *
	 * A fixed window (rather than a sliding log) is chosen for the same reason WordPress's own
	 * cron and rate helpers do: one transient read and one write, no per-request row growth. The
	 * cost is the classic edge — a burst straddling two windows can briefly reach 2×limit — which
	 * for a booking form is harmless. What it must never do is fail OPEN silently on a broken object
	 * cache, so the counter is stored even when the read comes back empty.
	 *
	 * @param string $action Bucket name.
	 * @param int    $limit  Requests per window.
	 * @param int    $window Seconds.
	 *
	 * @return true|WP_Error
	 */
	private static function rate_limit( string $action, int $limit, int $window ): true|WP_Error {
		$ip = self::client_ip();

		// No address at all (CLI, a misconfigured proxy) — do not lock the whole site out behind a
		// single empty-string bucket; skip the limit rather than share one counter across everyone.
		if ( '' === $ip ) {
			return true;
		}

		$key = 'plugixa_inventory_rl_' . $action . '_' . hash( 'sha256', $ip . '|' . $action );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'plugixa_inventory_rate_limited',
				__( 'Too many requests. Please wait a little while and try again.', 'plugixa-inventory' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * The requesting client's IP address.
	 *
	 * REMOTE_ADDR only, on purpose. `X-Forwarded-For` is attacker-controlled unless a known proxy sits
	 * in front, so trusting it by default would let anyone reset their own rate-limit bucket by forging
	 * a header. A site behind a real proxy (Cloudflare, a load balancer) hooks the filter to read the
	 * trusted header instead — that is a per-deployment fact this code cannot know.
	 *
	 * @return string The address, or '' if none is available.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) )
			: '';

		if ( '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}

		/**
		 * Filter the IP the inventory attributes a public request to.
		 *
		 * @param string $ip The REMOTE_ADDR-derived address, validated, possibly ''.
		 */
		return (string) apply_filters( 'plugixa_inventory_client_ip', $ip );
	}
}
