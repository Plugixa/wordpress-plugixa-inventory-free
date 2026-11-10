<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Modules\Import\Services;

use PlugixaInventory\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A cell is a string. A field is not.
 *
 * THIS EXISTS BECAUSE THE ENTITY SERVICES FORGIVE, AND AN IMPORT MUST NOT. Their leniency is right
 * where it lives: `ItemService::sanitize()` falls back to `consumable` for an unrecognised
 * `item_type` and to `published` for an unrecognised `status`, and that is correct for a form save,
 * where losing nine good edits because one select is stale would be the worse failure. The plugin
 * writes that argument out in two places.
 *
 * Applied to a spreadsheet the same leniency is a disaster of a different size. A `Type` column
 * whose values say "Asset" instead of "asset" does not produce one wrong record; it produces four
 * thousand consumables that cannot be checked out at all, because the stock engine enforces the
 * item-type/movement-type pairing. Nobody sees it until somebody tries to lend one.
 *
 * So every cell is refused HERE, before the service is called, and the fallback never fires. That is
 * the single most important thing this class does.
 *
 * IT IS NOT `FieldType::coerce()`. That class belongs to custom fields and speaks their twelve-type
 * vocabulary; this speaks the entity descriptor's. Custom-field cells are deliberately passed
 * through untouched so `FieldType` remains their only interpreter — two opinions about what a
 * checkbox is would be one too many.
 */
final class CellCoercer {

	/** What a spreadsheet writes for true. */
	private const TRUTHY = array( '1', 'y', 'yes', 'true', 'on', 't' );

	/** And for false. */
	private const FALSY = array( '0', 'n', 'no', 'false', 'off', 'f', '' );

	/**
	 * Turn one cell into the value the service expects, or refuse it.
	 *
	 * @param string               $field The field name, for the message.
	 * @param array<string, mixed> $definition The descriptor's entry.
	 * @param string               $cell  The raw cell.
	 *
	 * @return mixed|WP_Error
	 */
	public function coerce( string $field, array $definition, string $cell ): mixed {
		$label = (string) ( $definition['label'] ?? $field );
		$value = trim( $cell );

		return match ( (string) ( $definition['type'] ?? 'text' ) ) {
			'select'          => $this->select( $label, $definition, $value ),
			'boolean'         => $this->boolean( $label, $value ),
			'number', 'money' => $this->number( $label, $value ),
			default           => $value,
		};
	}

	/**
	 * One of the field's own options, in the field's own casing.
	 *
	 * RETURNING THE CANONICAL SPELLING IS LOAD-BEARING, NOT TIDINESS. `ItemService` matches
	 * `weight_unit` with an array lookup, which is case-sensitive: a file saying `KG` would be
	 * stored as an empty unit, and the derived `weight_base` written as zero. The weight then looks
	 * correct on the item's own form and sums to nothing in every report that totals it — the kind
	 * of wrong that is only noticed a quarter later.
	 *
	 * @param string               $label      Field label, for the message.
	 * @param array<string, mixed> $definition The descriptor's entry.
	 * @param string               $value      The trimmed cell.
	 *
	 * @return string|WP_Error
	 */
	private function select( string $label, array $definition, string $value ): string|WP_Error {
		$options = array_map( 'strval', (array) ( $definition['options'] ?? array() ) );

		if ( array() === $options ) {
			return $value;
		}

		foreach ( $options as $option ) {
			if ( 0 === strcasecmp( $option, $value ) ) {
				return $option;
			}
		}

		return new WP_Error(
			'plugixa_inventory_import_bad_choice',
			sprintf(
				/* translators: 1: a column label, 2: the value in the file, 3: a comma-separated list of accepted values. */
				__( '%1$s: “%2$s” is not one of %3$s.', 'plugixa-inventory' ),
				$label,
				$value,
				implode( ', ', $options )
			)
		);
	}

	/**
	 * The spellings of true and false a spreadsheet actually contains.
	 *
	 * An EMPTY cell never reaches here — the importer drops empty cells before coercion, because an
	 * empty cell means "this file has no value for that", not "set it to false". It is in the falsy
	 * list anyway so this class is correct on its own terms.
	 *
	 * @param string $label Field label, for the message.
	 * @param string $value The trimmed cell.
	 *
	 * @return int|WP_Error
	 */
	private function boolean( string $label, string $value ): int|WP_Error {
		$folded = strtolower( $value );

		if ( in_array( $folded, self::TRUTHY, true ) ) {
			return 1;
		}

		if ( in_array( $folded, self::FALSY, true ) ) {
			return 0;
		}

		return new WP_Error(
			'plugixa_inventory_import_not_a_boolean',
			sprintf(
				/* translators: 1: a column label, 2: the value in the file. */
				__( '%1$s: “%2$s” is not a yes or a no.', 'plugixa-inventory' ),
				$label,
				$value
			)
		);
	}

	/**
	 * A number, read with this site's own separators.
	 *
	 * NO "LAST SEPARATOR WINS" GUESSING. The tempting heuristic — whichever of `.` and `,` appears
	 * last is the decimal point — reads `1,234` as `1.234` on a site that writes thousands with a
	 * comma, and that is how a factor of a thousand gets into an inventory valuation. This reads the
	 * separators the site is already configured with, accepts a plain `.` decimal when the site does
	 * not use `.` for thousands, and refuses anything else rather than picking.
	 *
	 * NEGATIVES ARE REFUSED RATHER THAN CLAMPED. `ItemService` clamps with `max( 0.0, … )`, so `-5`
	 * for a price would be stored as `0` with no complaint at all. No writable field on any of the
	 * five importable entities has a meaningful negative value, so refusing is safe — and a file
	 * with negative prices in it is a file whose author needs telling.
	 *
	 * @param string $label Field label, for the message.
	 * @param string $value The trimmed cell.
	 *
	 * @return float|WP_Error
	 */
	private function number( string $label, string $value ): float|WP_Error {
		$thousands = (string) Settings::get( 'thousands_separator', ',' );
		$decimal   = (string) Settings::get( 'decimal_separator', '.' );

		$clean = $value;

		// Currency symbols and the spaces around them. A money column pasted from another system
		// very often carries one, and it is never part of the number.
		$clean = preg_replace( '/[\p{Sc}]/u', '', $clean ) ?? $clean;
		$clean = str_replace( array( ' ', "\xC2\xA0", "\xE2\x80\xAF" ), '', $clean );

		if ( '' !== $thousands ) {
			$clean = str_replace( $thousands, '', $clean );
		}

		if ( '' !== $decimal && '.' !== $decimal ) {
			$clean = str_replace( $decimal, '.', $clean );
		}

		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return new WP_Error(
				'plugixa_inventory_import_not_a_number',
				sprintf(
					/* translators: 1: a column label, 2: the value in the file. */
					__( '%1$s: “%2$s” is not a number.', 'plugixa-inventory' ),
					$label,
					$value
				)
			);
		}

		$number = (float) $clean;

		if ( $number < 0 ) {
			return new WP_Error(
				'plugixa_inventory_import_negative',
				sprintf(
					/* translators: 1: a column label, 2: the value in the file. */
					__( '%1$s: “%2$s” cannot be negative.', 'plugixa-inventory' ),
					$label,
					$value
				)
			);
		}

		return $number;
	}
}
