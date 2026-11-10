<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's settings, and the only thing that knows how they are stored.
 *
 * Everything lives in one `plugixa_inventory_settings` option — one autoloaded row rather than a
 * dozen, each costing its own lookup.
 *
 * WHAT CORE OWNS HERE IS PRESENTATION, AND ONLY PRESENTATION: how a number, a price and a date are
 * rendered. Every setting that belongs to a feature belongs to the module that implements it and
 * arrives through the two filters below — `inventory_costing_method` is the Stock module's,
 * `track_weight_globally` is Items', the demo controls are Demo's. That split is not tidiness: a
 * setting defined next to the code that reads it cannot drift away from it, and a module can be
 * added or removed without this file changing.
 *
 * ONE EXCEPTION, AND IT PROVES THE RULE: `delete_data_on_uninstall`. Its reader is
 * `Core\Uninstaller`, which runs while the plugin is NOT booted, so no module's defaults or
 * sanitizer exist at that moment — the setting has to belong to the one layer that is always there.
 * It also cannot belong to a module that can be switched off in the App Manager, or turning an app
 * off would leave a switch nobody can reach deciding whether the inventory survives a delete.
 *
 * NO `timezone` KEY, deliberately — this is the one place the Joomla component being ported was
 * solving a problem WordPress does not have. Joomla stored its own because the SPA formats dates
 * client-side and had nowhere else to read one from; WordPress has a site timezone already, and a
 * second copy of it is a second answer to one question. Read `wp_timezone_string()`.
 *
 * NO `image_*` KEYS either, for the same shape of reason. Joomla needed eleven of them — upload
 * path, allowed formats, max size, two thumbnail sizes, quality, resize mode, cover position,
 * background colour, auto-orientation, strip metadata — because it has no media pipeline, and it
 * vendored intervention/image to do the work. WordPress has the media library, `add_image_size()`
 * and `wp_generate_attachment_metadata()`. Items therefore carry an attachment id, not a path, and
 * roughly 700 lines of upload helper plus a ~250-file vendor tree do not get ported.
 *
 * The allow-lists are here for a sharper reason than tidiness. `date_format` is interpolated into
 * date(), so a free-text value is a template injection; and because a write replaces the whole
 * option, an unrecognised key is dropped rather than stored — otherwise anything able to reach the
 * settings endpoint could park arbitrary keys in an option WordPress loads on every request,
 * forever.
 */
final class Settings {

	public const OPTION = 'plugixa_inventory_settings';

	/**
	 * Currencies the plugin knows how to format.
	 *
	 * There is no exchange-rate table anywhere in this plugin and there is not meant to be one. An
	 * inventory is valued in the currency it was bought in; the Joomla component this was ported
	 * from has no multi-currency either, and its "currency" setting is purely a display symbol.
	 * Anything that looks like it needs conversion is a costing question, and costing lives in the
	 * Stock module.
	 */
	public const CURRENCIES = array(
		'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'JPY', 'CNY', 'HKD', 'SGD',
		'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'TRY', 'RUB', 'UAH',
		'INR', 'PKR', 'BDT', 'IDR', 'MYR', 'THB', 'PHP', 'VND', 'KRW', 'TWD',
		'AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR', 'ILS', 'EGP',
		'ZAR', 'NGN', 'KES', 'GHS', 'TZS', 'UGX', 'MAD', 'DZD', 'TND', 'XOF', 'XAF',
		'BRL', 'MXN', 'ARS', 'CLP', 'COP', 'PEN', 'UYU',
	);

	/** Date formats offered. Not free text: this string is handed to date(). */
	public const DATE_FORMATS = array( 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd-m-Y', 'j M Y', 'M j, Y' );

	/*
	 * `THEMES` USED TO BE HERE, WITH THE DEFAULT AND THE SANITIZER BELOW. All three moved to the
	 * module that contributes a second theme palette, through the three filters every other module
	 * uses for its own settings: `plugixa_inventory_settings_defaults`,
	 * `plugixa_inventory_sanitize_settings` and `plugixa_inventory_settings_choices`.
	 *
	 * A BUILD THAT CAN DRAW ONE MODE HAS NOTHING TO CHOOSE BETWEEN, so a `theme` setting in Core
	 * would be a stored value nothing reads and a select with one option. `keep_unclaimed()` is
	 * what makes the transition safe in the other direction: a site that already stored `dark`
	 * keeps the value while the module is absent, and finds it again when it comes back.
	 */

	/** Where the currency symbol sits relative to the amount. */
	public const CURRENCY_POSITIONS = array( 'before', 'after' );

	/**
	 * Separators offered for decimals and thousands.
	 *
	 * A NARROW NO-BREAK SPACE IS NOT ON THIS LIST, although SI prefers it for thousands. It is
	 * indistinguishable from a plain space in every admin font, and a user who cannot tell which one
	 * they picked cannot debug a CSV that a spreadsheet then splits in the wrong place.
	 */
	public const SEPARATORS = array( '.', ',', ' ', "'" );

	/**
	 * The defaults, and the one place they are written down.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		/**
		 * Filter the plugin's default settings.
		 *
		 * This is how a module contributes a setting it owns. It must also clean it in
		 * `plugixa_inventory_sanitize_settings`; a key that neither filter claims is discarded from
		 * an incoming payload, though one already on disk survives — see `keep_unclaimed()`.
		 *
		 * @param array<string, mixed> $defaults Default settings.
		 */
		return (array) apply_filters(
			'plugixa_inventory_settings_defaults',
			array(
				'currency'            => 'USD',
				'currency_position'   => 'before',
				'currency_decimals'   => 2,
				'decimal_places'      => 2,
				'decimal_separator'   => '.',
				'thousands_separator' => ',',
				'date_format'         => 'Y-m-d',
				'records_per_page'    => 50,
				// Off: deleting the plugin keeps the inventory. See `Uninstaller`.
				Uninstaller::SETTING  => false,
			)
		);
	}

	/**
	 * The settings as they stand, with defaults filled in for anything unset.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Returned when the key is set nowhere, not even in the defaults.
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		return self::all()[ $key ] ?? $default;
	}

	/**
	 * The currency every amount is stored and summed in.
	 */
	public static function currency(): string {
		return (string) self::get( 'currency', 'USD' );
	}

	/**
	 * Save an untrusted payload, keeping only what is recognised.
	 *
	 * @param array<string, mixed> $input Raw settings, merged over what is already stored.
	 *
	 * @return array<string, mixed> The settings as saved.
	 */
	public static function save( array $input ): array {
		$settings = self::sanitize( array_merge( self::all(), $input ) );
		$settings = self::keep_unclaimed( $settings );

		update_option( self::OPTION, $settings );

		/**
		 * Fires after the settings are saved.
		 *
		 * @param array<string, mixed> $settings The saved settings.
		 */
		do_action( 'plugixa_inventory_settings_saved', $settings );

		return $settings;
	}

	/**
	 * Carry forward settings that are ALREADY STORED and that no filter claimed.
	 *
	 * THIS IS THE DIFFERENCE BETWEEN A LAPSED LICENCE AND A DATA LOSS. `sanitize()` builds the option
	 * from scratch out of the keys Core and the loaded modules claim, and drops everything else. That
	 * is correct while every module is always present -- and it stops being correct the moment a
	 * premium module owns settings, because a premium module is not always present.
	 *
	 * Picture a site whose licence lapses. It now runs the free build, whose filters no longer claim
	 * the premium module's keys. Nothing is broken and nothing warns. Then somebody changes the date
	 * format, and that single save rewrites the option without that configuration in it, because the
	 * running build did not recognise it. Renewing the licence brings the code back to an empty
	 * configuration.
	 *
	 * This is not hypothetical. plugixa-faq shipped it, and its 1.0.3 changelog says so: *"A site
	 * whose licence had lapsed lost its saved AI configuration, API keys included, the first time
	 * settings were saved."*
	 *
	 * WHAT IS PRESERVED IS ONLY WHAT WAS ALREADY ON DISK, never an unrecognised key from the payload.
	 * That distinction is the whole safety of it: the option is autoloaded on every request, so
	 * letting a request park arbitrary data in it would be a denial of service with a REST endpoint
	 * in front of it. An unknown key that is already stored was written by a build that DID claim it,
	 * and is the property of code that may come back.
	 *
	 * @param array<string, mixed> $clean The sanitised settings, ready to store.
	 *
	 * @return array<string, mixed>
	 */
	private static function keep_unclaimed( array $clean ): array {
		$stored = (array) get_option( self::OPTION, array() );

		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $clean ) ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Clean an untrusted settings payload.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$currency  = strtoupper( sanitize_text_field( (string) ( $input['currency'] ?? 'USD' ) ) );
		$position  = sanitize_text_field( (string) ( $input['currency_position'] ?? 'before' ) );
		$format    = sanitize_text_field( (string) ( $input['date_format'] ?? 'Y-m-d' ) );
		$decimal   = (string) ( $input['decimal_separator'] ?? '.' );
		$thousands = (string) ( $input['thousands_separator'] ?? ',' );

		$clean = array(
			'currency'          => in_array( $currency, self::CURRENCIES, true ) ? $currency : 'USD',
			'currency_position' => in_array( $position, self::CURRENCY_POSITIONS, true ) ? $position : 'before',
			// Four is the widest any real currency goes; the clamp is what stops a submitted 40
			// from reaching number_format(), which would happily return a 40-decimal string.
			'currency_decimals' => max( 0, min( 4, (int) ( $input['currency_decimals'] ?? 2 ) ) ),
			// Quantities, not money. Six matches the precision of the unit-conversion factors, so
			// a converted quantity can still be displayed exactly.
			'decimal_places'    => max( 0, min( 6, (int) ( $input['decimal_places'] ?? 2 ) ) ),
			'date_format'       => in_array( $format, self::DATE_FORMATS, true ) ? $format : 'Y-m-d',
			// A page size of 100000 is not a preference, it is a denial of service against
			// the site's own database.
			'records_per_page'  => max( 10, min( 200, (int) ( $input['records_per_page'] ?? 50 ) ) ),
			// A strict boolean, because `Uninstaller::run()` erases only on `true` exactly — a stored
			// "1" or "yes" must never be the thing that decides an inventory's fate.
			Uninstaller::SETTING => true === rest_sanitize_boolean( $input[ Uninstaller::SETTING ] ?? false ),
		);

		$clean['decimal_separator']   = in_array( $decimal, self::SEPARATORS, true ) ? $decimal : '.';
		$clean['thousands_separator'] = in_array( $thousands, self::SEPARATORS, true ) ? $thousands : ',';

		/*
		 * THE TWO SEPARATORS MAY NOT BE THE SAME CHARACTER. "1.234.56" is not a number anybody can
		 * read back, and more to the point it is not a number a spreadsheet can parse out of an
		 * exported CSV -- which is where these two settings actually get used in anger. Rejecting
		 * the pair rather than the field is deliberate: either one alone is a legal choice, and it
		 * is only the combination that is broken, so the fix is to put the pair back to a known-good
		 * default instead of guessing which of the two the user meant to change.
		 */
		if ( $clean['decimal_separator'] === $clean['thousands_separator'] ) {
			$clean['decimal_separator']   = '.';
			$clean['thousands_separator'] = ',';
		}

		/**
		 * Sanitise settings a module owns.
		 *
		 * A module adds its own keys here — Core never learns what they are. Anything no filter
		 * claims is dropped from the PAYLOAD; a key that is already stored survives, via
		 * `keep_unclaimed()`, so a build that cannot see a module's settings cannot delete them
		 * either.
		 *
		 * @param array<string, mixed> $clean Settings Core has already cleaned.
		 * @param array<string, mixed> $input The raw payload.
		 */
		return (array) apply_filters( 'plugixa_inventory_sanitize_settings', $clean, $input );
	}
}
