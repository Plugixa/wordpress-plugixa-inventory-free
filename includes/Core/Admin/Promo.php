<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Admin;

use PlugixaInventory\Core\App\Edition;
use PlugixaInventory\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Plugixa offer carried by the FREE build's header button.
 *
 * The offer is managed on plugixa.com ("Inside the plugins"). Switching it on there shows it here,
 * switching it off hides it, within one cache period. The request carries only this plugin's slug
 * and `tier=free`: no site URL, no licence, no user data.
 *
 * FREE BUILD ONLY. The premium build carries this file (premium is free plus `pro/`) but never
 * runs it: no request, nothing cached, nothing on screen. A paying customer is not sold the
 * plugin they already have, and a cache filled while the site was still free is never read once
 * it is Pro.
 *
 * Fetched HERE, on the server, and handed to the app through the page config, so the browser never
 * contacts plugixa.com and the app's own source names no outside host.
 */
final class Promo {

	public const ENDPOINT  = 'https://www.plugixa.com/api/v1/promo';
	public const TRANSIENT = 'plugixa_inventory_promo';

	/**
	 * How long one answer is trusted. The same 15 minutes the API allows proxies, so an offer
	 * switched off on plugixa.com is gone from every site within a quarter of an hour.
	 */
	private const TTL = 15 * MINUTE_IN_SECONDS;

	private const SLUG = 'plugixa-inventory';

	/**
	 * The live offer, or null.
	 *
	 * Only on the free build, and only for people who run the inventory: nothing is fetched for a
	 * storekeeper's page load or on a premium site.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		/**
		 * Whether to show the Plugixa offer on the inventory screens.
		 *
		 * @param bool $show Default true on the free build, false on the premium build.
		 */
		if ( ! apply_filters( 'plugixa_inventory_show_promo', 'free' === Edition::current() ) || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return null;
		}

		$cached = get_transient( self::TRANSIENT );

		// An empty array is a cached "no offer"; false is a miss.
		if ( is_array( $cached ) ) {
			return self::still_running( $cached ) ? $cached : null;
		}

		$promo = self::fetch();

		set_transient( self::TRANSIENT, $promo ?? array(), self::ttl( $promo ) );

		return $promo;
	}

	/**
	 * Ask plugixa.com. Any failure is "no offer": a promo must never break or slow the inventory,
	 * and the failure is cached like an answer, so a dead endpoint costs one request per period.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function fetch(): ?array {
		/**
		 * The promo endpoint. For pointing a development site at a local copy of the API.
		 *
		 * @param string $url Default https://www.plugixa.com/api/v1/promo.
		 */
		$endpoint = (string) apply_filters( 'plugixa_inventory_promo_endpoint', self::ENDPOINT );

		$url = add_query_arg(
			array(
				'plugin' => self::SLUG,
				'tier'   => 'free',
			),
			$endpoint
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 3,
				'redirection' => 2,
				// WordPress's default user agent ends with the site URL; the readme promises none is sent.
				'user-agent'  => 'Plugixa Inventory',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['promo'] ) || ! is_array( $body['promo'] ) ) {
			return null;
		}

		$promo = self::sanitize( $body['promo'] );

		return null !== $promo && self::still_running( $promo ) ? $promo : null;
	}

	/**
	 * Keep only the fields the strip draws, each in the shape it expects. The payload is printed
	 * inline on the page, so nothing passes through unchecked.
	 *
	 * @param array<string, mixed> $raw The API's `promo` object.
	 *
	 * @return array<string, mixed>|null Null when the offer has nothing to show or nowhere to link.
	 */
	public static function sanitize( array $raw ): ?array {
		$text = static fn ( string $key ): string => sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );

		$headline = $text( 'headline' );
		$cta_url  = self::http_url( (string) ( $raw['cta_url'] ?? '' ) );
		$ends_at  = self::timestamp( $raw['ends_at'] ?? '' );

		if ( '' === $headline || '' === $cta_url || 0 === $ends_at ) {
			return null;
		}

		$colors = array();
		$given  = is_array( $raw['colors'] ?? null ) ? $raw['colors'] : array();
		$preset = array(
			'bg'          => '#0B0B14',
			'text'        => '#FFFFFF',
			'accent'      => '#FBBF24',
			'accent_text' => '#111111',
		);

		foreach ( $preset as $key => $fallback ) {
			$value          = (string) ( $given[ $key ] ?? '' );
			$colors[ $key ] = 1 === preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? strtoupper( $value ) : $fallback;
		}

		return array(
			'version'        => $text( 'version' ),
			'badge'          => $text( 'badge' ),
			'headline'       => $headline,
			'subtext'        => $text( 'subtext' ),
			'coupon'         => $text( 'coupon' ),
			'discount_label' => $text( 'discount_label' ),
			'cta_label'      => $text( 'cta_label' ),
			'cta_url'        => $cta_url,
			'ends_at'        => gmdate( 'Y-m-d\TH:i:s\Z', $ends_at ),
			'show_countdown' => ! empty( $raw['show_countdown'] ),
			'countdown_to'   => gmdate( 'Y-m-d\TH:i:s\Z', self::timestamp( $raw['countdown_to'] ?? '' ) ?: $ends_at ),
			'countdown_days' => max( 0, (int) ( $raw['countdown_days'] ?? 0 ) ),
			'repeat_seconds' => max( 0, (int) ( $raw['repeat_seconds'] ?? 0 ) ),
			'colors'         => $colors,
		);
	}

	/**
	 * @param array<string, mixed> $promo A sanitised offer, or the cached empty array.
	 */
	private static function still_running( array $promo ): bool {
		return array() !== $promo && self::timestamp( $promo['ends_at'] ?? '' ) > time();
	}

	/**
	 * The cache period, never past the offer's own end.
	 *
	 * @param array<string, mixed>|null $promo The fetched offer.
	 */
	private static function ttl( ?array $promo ): int {
		if ( null === $promo ) {
			return self::TTL;
		}

		return max( 60, min( self::TTL, self::timestamp( $promo['ends_at'] ) - time() ) );
	}

	/**
	 * An absolute http(s) URL, or ''.
	 */
	private static function http_url( string $url ): string {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Unix time of an ISO-8601 UTC string, or 0.
	 *
	 * @param mixed $iso e.g. "2026-11-30T22:59:59Z".
	 */
	private static function timestamp( $iso ): int {
		$time = is_string( $iso ) && '' !== $iso ? strtotime( $iso ) : false;

		return false === $time ? 0 : $time;
	}
}
