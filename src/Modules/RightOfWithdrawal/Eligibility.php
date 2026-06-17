<?php
/**
 * Pure eligibility rule for the Right of Withdrawal notice.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for "should this customer see the withdrawal
 * notice?". Kept side-effect free so it can be unit tested without WordPress.
 */
class Eligibility {

	/**
	 * Decide whether the notice/form should be available.
	 *
	 * @param bool   $is_customer             Resolvable SureCart customer for this request.
	 * @param bool   $is_eu                   Billing country is in the EU.
	 * @param bool   $has_country             Customer has any country on file.
	 * @param int    $order_count             Number of orders inside the look-back window.
	 * @param bool   $has_vat                 Customer has a VAT/tax identifier (business).
	 * @param string $apply_to                'all' or 'non_vat'.
	 * @param bool   $include_unknown_country Treat "no country on file" as eligible.
	 * @return bool
	 */
	public static function is_eligible( bool $is_customer, bool $is_eu, bool $has_country, int $order_count, bool $has_vat, string $apply_to, bool $include_unknown_country ): bool {
		if ( ! $is_customer ) {
			return false;
		}
		// Geography: an EU billing country, or — when enabled — no country on file
		// at all. A known non-EU country is never eligible.
		if ( ! $is_eu && ! ( $include_unknown_country && ! $has_country ) ) {
			return false;
		}
		if ( $order_count < 1 ) {
			return false;
		}
		if ( 'non_vat' === $apply_to && $has_vat ) {
			return false;
		}
		return true;
	}
}
