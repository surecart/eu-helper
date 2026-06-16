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
 * Shorthand for the rule.
 */
$rule = static function ( $is_customer, $is_eu, $orders, $has_vat, $apply_to ) {
	return Eligibility::is_eligible( $is_customer, $is_eu, $orders, $has_vat, $apply_to );
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

echo "\n";
if ( 0 === $fail ) {
	echo "ALL PASSING: {$pass} checks\n";
	exit( 0 );
}
echo "FAILED: {$fail} failing, {$pass} passing\n";
exit( 1 );
