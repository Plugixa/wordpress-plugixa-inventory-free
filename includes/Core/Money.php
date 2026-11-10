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
 * Money arithmetic, in one place.
 *
 * Costing needs it in three places that must agree to the penny: the cost written onto an
 * inventory layer when stock is received, the weighted-average COGS computed when layers are
 * consumed, and the valuation a report sums. Two copies of a rounding rule is how an item
 * received for 3.03 is sold at a cost of 3.02 and the stock account never quite balances —
 * and a valuation that is wrong by a rounding error is worse than one that is obviously wrong,
 * because it is believed.
 *
 * Amounts are stored as DECIMAL(18,4) throughout, which is exact in MySQL. The "never use floats
 * for money" rule is about FLOAT/DOUBLE, which cannot represent 0.10; DECIMAL can. PHP does the
 * arithmetic in float, so every intermediate result is rounded to the currency's own scale here
 * rather than left to drift.
 *
 * NOTE THE FOUR STORED DECIMALS AGAINST A TWO-DECIMAL CURRENCY, because it looks like an
 * inconsistency and is the point. A unit cost is not a price: divide a 100.00 delivery across 3
 * units and the honest per-unit cost is 33.3333, not 33.33. Rounding it at the layer would lose
 * a penny per consumption and leave stock permanently over-valued. So cost per unit keeps four
 * decimals and only the TOTAL is rounded to the currency — which is what `round()` here is for.
 */
final class Money {

	/**
	 * Currencies with no minor unit.
	 *
	 * Rounding a yen amount to two places invents a precision the currency does not have and
	 * prints ¥1,234.57 on a report that a Japanese user will find absurd.
	 */
	public const ZERO_DECIMAL = array( 'JPY', 'KRW', 'VND', 'CLP', 'UGX', 'XOF', 'XAF', 'PYG', 'RWF', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'VUV', 'XPF' );

	/**
	 * Currencies with three minor digits.
	 *
	 * The Gulf dinars are the reason this list is not just "zero or two". A Kuwaiti dinar divides
	 * into 1000 fils, so rounding to two places throws away a real unit of money on every single
	 * amount — small, consistent, and in one direction, which is how it accumulates into a
	 * reconciliation nobody can explain.
	 */
	public const THREE_DECIMAL = array( 'KWD', 'BHD', 'OMR', 'TND', 'IQD', 'JOD', 'LYD' );

	/**
	 * How many decimal places this currency actually has.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function scale( string $currency ): int {
		$currency = strtoupper( $currency );

		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}

		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return 3;
		}

		return 2;
	}

	/**
	 * Round an amount to its currency's scale.
	 *
	 * @param float  $amount   The amount.
	 * @param string $currency ISO 4217 code.
	 */
	public static function round( float $amount, string $currency ): float {
		return round( $amount, self::scale( $currency ) );
	}

	/**
	 * Round a per-unit cost, which is NOT the same operation as rounding money.
	 *
	 * Four decimals, always, regardless of currency — this is the scale the `cost_per_unit`
	 * columns are declared at, and rounding to the currency here is exactly the bug the class
	 * docblock describes.
	 *
	 * @param float $cost Cost for one unit.
	 */
	public static function unit_cost( float $cost ): float {
		return round( $cost, 4 );
	}

	/**
	 * Are two amounts the same money?
	 *
	 * Never compare money with ===. 0.1 + 0.2 is not 0.3 in binary floating point, so a balance
	 * that *is* settled can compare unequal to zero and a stock account stays forever one
	 * hundredth of a penny out. Compare at the currency's own scale.
	 *
	 * @param float  $a        One amount.
	 * @param float  $b        The other.
	 * @param string $currency ISO 4217 code.
	 */
	public static function equal( float $a, float $b, string $currency ): bool {
		return abs( self::round( $a, $currency ) - self::round( $b, $currency ) ) < self::epsilon( $currency );
	}

	/**
	 * Is $a greater than $b, as money?
	 *
	 * @param float  $a        One amount.
	 * @param float  $b        The other.
	 * @param string $currency ISO 4217 code.
	 */
	public static function greater( float $a, float $b, string $currency ): bool {
		return self::round( $a, $currency ) - self::round( $b, $currency ) >= self::epsilon( $currency );
	}

	/**
	 * Half of the smallest unit this currency can express — the largest difference that is
	 * definitely not real money.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	private static function epsilon( string $currency ): float {
		return ( 10 ** -self::scale( $currency ) ) / 2;
	}
}
