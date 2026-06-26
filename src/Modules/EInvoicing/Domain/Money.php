<?php
/**
 * Minor-unit money helpers.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * All amounts in this module are integer minor units (e.g. 1999 = €19.99) plus
 * an ISO-4217 currency code — matching SureCart, which stores amounts in cents.
 * This avoids floating-point drift in tax/total arithmetic. These helpers format
 * for display only; arithmetic stays in integers everywhere else.
 */
final class Money {

	/**
	 * Zero-decimal currencies (amount is already the major unit, no cents).
	 *
	 * @return string[]
	 */
	private static function zero_decimal(): array {
		return array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF', 'XOF', 'XAF', 'BIF', 'DJF', 'GNF', 'KMF', 'PYG', 'RWF', 'UGX', 'VUV', 'XPF' );
	}

	/**
	 * Format a minor-unit amount for human display.
	 *
	 * @param int    $minor    Amount in minor units (may be negative).
	 * @param string $currency ISO currency code.
	 * @return string
	 */
	public static function format( int $minor, string $currency ): string {
		$currency = strtoupper( $currency );
		$decimals = in_array( $currency, self::zero_decimal(), true ) ? 0 : 2;
		$divisor  = $decimals > 0 ? 100 : 1;
		$value    = $minor / $divisor;
		return number_format_i18n( $value, $decimals ) . ' ' . $currency;
	}

	/**
	 * Coerce a possibly-float/string amount to integer minor units.
	 *
	 * @param mixed $value Raw amount (already in minor units when from SureCart).
	 * @return int
	 */
	public static function to_minor( $value ): int {
		return (int) round( (float) $value );
	}
}
