<?php
/**
 * Standalone truth-table tests for the Right of Withdrawal eligibility rule.
 *
 * No WordPress install required — defines the minimal stub the class needs,
 * then exercises every combination. Run:  php tests/test-eligibility.php
 *
 * @package SureCartEuHelper
 */

// The class only guards on ABSPATH; define it so the file loads.
define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../src/Modules/RightOfWithdrawal/Eligibility.php';

use SureCartEuHelper\Modules\RightOfWithdrawal\Eligibility;

$pass = 0;
$fail = 0;

/**
 * Assert helper.
 *
 * @param string $label    Test description.
 * @param bool   $actual   Result.
 * @param bool   $expected Expectation.
 * @return void
 */
function check( string $label, bool $actual, bool $expected ): void {
	global $pass, $fail;
	if ( $actual === $expected ) {
		++$pass;
		echo "  PASS  {$label}\n";
	} else {
		++$fail;
		echo "  FAIL  {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

/**
 * Shorthand for the rule. The last two args default to the common case (a
 * country is on file; the unknown-country toggle is on) so the pre-existing
 * checks below read unchanged.
 */
$rule = static function ( $is_customer, $is_eu, $orders, $has_vat, $apply_to, $has_country = true, $include_unknown = true ) {
	return Eligibility::is_eligible( $is_customer, $is_eu, $has_country, $orders, $has_vat, $apply_to, $include_unknown );
};

echo "Right of Withdrawal — eligibility truth table\n";

// Happy path.
check( 'EU consumer, 1 order, apply_to=all → eligible', $rule( true, true, 1, false, 'all' ), true );
check( 'EU consumer, 3 orders, apply_to=non_vat → eligible', $rule( true, true, 3, false, 'non_vat' ), true );

// Not a customer / logged out.
check( 'No customer → not eligible', $rule( false, true, 2, false, 'all' ), false );

// Outside EU.
check( 'Non-EU customer → not eligible', $rule( true, false, 2, false, 'all' ), false );

// No orders in window.
check( 'EU customer, 0 orders → not eligible', $rule( true, true, 0, false, 'all' ), false );

// VAT / business handling.
check( 'EU business (VAT), apply_to=non_vat → not eligible', $rule( true, true, 2, true, 'non_vat' ), false );
check( 'EU business (VAT), apply_to=all → eligible', $rule( true, true, 2, true, 'all' ), true );

// Combined negatives.
check( 'Non-EU business, apply_to=non_vat → not eligible', $rule( true, false, 5, true, 'non_vat' ), false );
check( 'EU business, 0 orders, apply_to=all → not eligible', $rule( true, true, 0, true, 'all' ), false );

// No country on file — gated by the include_unknown_country toggle.
// Signature: $rule( is_customer, is_eu, orders, has_vat, apply_to, has_country, include_unknown ).
check( 'No country, toggle on, 1 order → eligible', $rule( true, false, 1, false, 'all', false, true ), true );
check( 'No country, toggle off → not eligible', $rule( true, false, 1, false, 'all', false, false ), false );
check( 'No country, toggle on, 0 orders → not eligible', $rule( true, false, 0, false, 'all', false, true ), false );
check( 'No country, toggle on, VAT business, apply_to=non_vat → not eligible', $rule( true, false, 2, true, 'non_vat', false, true ), false );
check( 'No country, toggle on, VAT business, apply_to=all → eligible', $rule( true, false, 2, true, 'all', false, true ), true );

// A known non-EU country is never eligible, even with the toggle on.
check( 'Known non-EU country, toggle on → not eligible', $rule( true, false, 2, false, 'all', true, true ), false );

echo "\n";
if ( 0 === $fail ) {
	echo "ALL PASSING: {$pass} checks\n";
	exit( 0 );
}
echo "FAILED: {$fail} failing, {$pass} passing\n";
exit( 1 );
