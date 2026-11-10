<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core\Entities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a payload AS THE CLIENT SENT IT, before anything sanitizes it into something else.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS RUNS BEFORE `sanitize()`, NOT INSIDE `validate()`. Every service sanitized first, and
 * its sanitizer replaced whatever it did not recognise with a default — `in_array( … ) ? $type :
 * 'add_stock'`, `max( 0.0, (float) $cost )`, a date that did not match a pattern became "now". By
 * the time `validate()` ran, "teleport" was already `add_stock` and "abc" was already 0, so there was
 * nothing left to refuse. Posted straight to the REST API, those became real, FINALIZED documents:
 * a stock receipt nobody asked for, and a ledger row dated 0000-00-00. The admin forms never send
 * such values, which is why nothing but a request that bypassed them could show it.
 *
 * THE RULE: a value that was not sent — the key absent, null, or an empty string — is not an answer,
 * and the caller's default still applies. A value that WAS sent and cannot be used is refused, with
 * the field named so the form can attach the message to its input.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class RawInput {

	/**
	 * The largest number any money, quantity or weight column holds: DECIMAL(18,4) leaves fourteen
	 * integer digits. Anything larger fails the insert, which the caller would report as a 500.
	 */
	public const MAX_NUMBER = 99999999999999.0;

	/**
	 * A value from a fixed list.
	 *
	 * @param array<string, mixed> $input   The raw payload.
	 * @param string               $field   The key to check.
	 * @param string[]             $allowed The accepted values.
	 * @param string               $label   What the field is called, translated.
	 * @param bool                 $as_key  Compare as `sanitize_key()` sees it — so "Add_Stock" is
	 *                                      `add_stock` — or exactly, for values that are not keys
	 *                                      (a unit's `*` or `/` operator).
	 */
	public static function choice( array $input, string $field, array $allowed, string $label, bool $as_key = true ): ?WP_Error {
		if ( ! self::given( $input, $field ) ) {
			return null;
		}

		$value = $input[ $field ];

		if ( is_string( $value ) && in_array( $as_key ? sanitize_key( $value ) : trim( $value ), $allowed, true ) ) {
			return null;
		}

		return new WP_Error(
			'plugixa_inventory_invalid_choice',
			sprintf(
				/* translators: 1: the field's name, e.g. "Type". 2: the accepted values, comma separated. */
				__( '%1$s must be one of: %2$s.', 'plugixa-inventory' ),
				$label,
				implode( ', ', $allowed )
			),
			array( 'status' => 422, 'field' => $field, 'allowed' => array_values( $allowed ) )
		);
	}

	/**
	 * The offered option an answer names, as the option is written — or null when it names none.
	 *
	 * FOR A LIST SOMEBODY TYPED, where `choice()` is for a list of keys. A custom field's options are
	 * free text ("<5 kg", "A & B", "Size  L"), and they are stored as written. Sanitizing the answer
	 * before looking for it is what made "<5 kg" unchoosable — `sanitize_text_field()` makes it
	 * "&lt;5 kg" — and what let "<b>Over 10 kg</b>" be stored as the option it is not.
	 *
	 * RETURNS THE OPTION, NOT THE ANSWER. Once the answer is found in the list, the value to store is
	 * the list's own entry, so nothing from the request is kept but the choice itself. Surrounding
	 * space on either side does not count, because a CSV cell often carries some; case does.
	 *
	 * @param mixed    $answer  What the request sent.
	 * @param string[] $options What is offered.
	 */
	public static function offered( mixed $answer, array $options ): ?string {
		if ( ! is_scalar( $answer ) ) {
			return null;
		}

		$answer = trim( (string) $answer );

		foreach ( $options as $option ) {
			if ( is_scalar( $option ) && trim( (string) $option ) === $answer ) {
				return (string) $option;
			}
		}

		return null;
	}

	/**
	 * A number, written the way JSON writes one.
	 *
	 * "12,50" IS REFUSED, NOT READ AS 12.50. On an API it is ambiguous — twelve and a half in most of
	 * Europe, twelve hundred and fifty where a comma separates thousands — and `(float)` read it as 12,
	 * which is the one answer that is certainly wrong. The CSV import does read a site's own separators,
	 * because a spreadsheet is written for people; it converts before it ever reaches a service.
	 *
	 * @param array<string, mixed> $input          The raw payload.
	 * @param string               $field          The key to check.
	 * @param string               $label          What the field is called, translated.
	 * @param bool                 $allow_negative Whether a negative value means something here.
	 */
	public static function number( array $input, string $field, string $label, bool $allow_negative = false ): ?WP_Error {
		if ( ! self::given( $input, $field ) ) {
			return null;
		}

		$value = $input[ $field ];

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( is_bool( $value ) || ! ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) ) {
			return new WP_Error(
				'plugixa_inventory_not_a_number',
				sprintf(
					/* translators: %s: the field's name, e.g. "Cost price". */
					__( '%s must be a number, with a dot for decimals.', 'plugixa-inventory' ),
					$label
				),
				array( 'status' => 422, 'field' => $field )
			);
		}

		$number = (float) $value;

		if ( ! is_finite( $number ) || abs( $number ) > self::MAX_NUMBER ) {
			return new WP_Error(
				'plugixa_inventory_number_too_large',
				sprintf(
					/* translators: %s: the field's name, e.g. "Cost price". */
					__( '%s is too large.', 'plugixa-inventory' ),
					$label
				),
				array( 'status' => 422, 'field' => $field )
			);
		}

		if ( ! $allow_negative && $number < 0 ) {
			return new WP_Error(
				'plugixa_inventory_negative_number',
				sprintf(
					/* translators: %s: the field's name, e.g. "Cost price". */
					__( '%s cannot be negative.', 'plugixa-inventory' ),
					$label
				),
				array( 'status' => 422, 'field' => $field )
			);
		}

		return null;
	}

	/**
	 * A date, or a date and time, that exists on the calendar.
	 *
	 * @param array<string, mixed> $input The raw payload.
	 * @param string               $field The key to check.
	 * @param string               $label What the field is called, translated.
	 */
	public static function date( array $input, string $field, string $label ): ?WP_Error {
		if ( ! self::given( $input, $field ) ) {
			return null;
		}

		if ( is_string( $input[ $field ] ) && null !== self::to_mysql( $input[ $field ] ) ) {
			return null;
		}

		return new WP_Error(
			'plugixa_inventory_invalid_date',
			sprintf(
				/* translators: %s: the field's name, e.g. "Date". */
				__( '%s is not a real date. Use YYYY-MM-DD, optionally followed by a time.', 'plugixa-inventory' ),
				$label
			),
			array( 'status' => 422, 'field' => $field )
		);
	}

	/**
	 * A date the database stores exactly as meant: `Y-m-d H:i:s`, or null when there is none.
	 *
	 * ONE DEFINITION, SHARED BY THE CHECK AND THE STORE. Stock movements, transfers and adjustments each
	 * carried a private copy of this, matching the SHAPE of a date — `\d{4}-\d{2}-\d{2}` — and not
	 * whether it existed, so "2026-13-45" passed and MySQL stored it as zeros. A check and a normaliser
	 * that could disagree about what a date is would reopen that; this is both.
	 *
	 * NAIVE WALL-CLOCK, AND NOTHING IS CONVERTED. A document date is a calendar date the user picked in
	 * their own timezone, unlike the audit columns, which are UTC; it is stored exactly as given.
	 *
	 * @param string $value `Y-m-d`, `Y-m-d H:i` or `Y-m-d H:i:s`, with a space or a `T`.
	 */
	public static function to_mysql( string $value ): ?string {
		$value = trim( $value );

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m ) ) {
			return null;
		}

		$year   = (int) $m[1];
		$month  = (int) $m[2];
		$day    = (int) $m[3];
		$hour   = isset( $m[4] ) ? (int) $m[4] : 0;
		$minute = isset( $m[5] ) ? (int) $m[5] : 0;
		$second = isset( $m[6] ) ? (int) $m[6] : 0;

		// MySQL's DATETIME starts at the year 1000; anything earlier is not a date it can keep.
		if ( $year < 1000 || ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );
	}

	/**
	 * Say which document line a refusal is about, so a document with many lines can be fixed without
	 * guessing which one it meant.
	 *
	 * @param WP_Error             $error The refusal.
	 * @param array<string, mixed> $raw   The line as sent.
	 * @param int                  $index Its position in the payload, from zero.
	 */
	public static function for_line( WP_Error $error, array $raw, int $index ): WP_Error {
		$error->add_data(
			array_merge(
				(array) $error->get_error_data(),
				array(
					'line'    => $index + 1,
					'item_id' => (int) ( $raw['item_id'] ?? 0 ),
				)
			)
		);

		return $error;
	}

	/**
	 * The first refusal among several checks, or null when every one passed.
	 *
	 * @param WP_Error|null ...$checks Results of the checks above, in the order they should be reported.
	 */
	public static function first( ?WP_Error ...$checks ): ?WP_Error {
		foreach ( $checks as $check ) {
			if ( $check instanceof WP_Error ) {
				return $check;
			}
		}

		return null;
	}

	/**
	 * Whether the client said anything at all for this field.
	 *
	 * @param array<string, mixed> $input The raw payload.
	 * @param string               $field The key.
	 */
	private static function given( array $input, string $field ): bool {
		if ( ! array_key_exists( $field, $input ) || null === $input[ $field ] ) {
			return false;
		}

		// Whitespace alone is as empty as an empty string, which is how the sanitizers always read it.
		return ! ( is_string( $input[ $field ] ) && '' === trim( $input[ $field ] ) );
	}
}
