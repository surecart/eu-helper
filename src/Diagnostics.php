<?php
/**
 * Diagnostic shortcode for debugging eligibility on a live site.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper;

use SureCartEuHelper\Customer\CustomerContext;

defined( 'ABSPATH' ) || exit;

/**
 * Registers [sceu_debug]. Drop it on any page and view it while logged in as the
 * customer you're testing — it prints what CustomerContext resolves plus the
 * Right of Withdrawal eligibility decision, so you can see exactly why the
 * notice does or doesn't show. Logged-in users only; shows only own data.
 */
class Diagnostics {

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'sceu_debug', array( $this, 'render' ) );
	}

	/**
	 * Render the diagnostic table.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p><em>' . esc_html__( 'SureCart EU Helper debug: log in as the customer you want to test.', 'surecart-eu-helper' ) . '</em></p>';
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$customer = new CustomerContext();
		$rows     = array();
		foreach ( $customer->debug() as $key => $value ) {
			$rows[] = array( $key, $this->format( $value ) );
		}

		// Right of Withdrawal eligibility, using current settings.
		$lookback = (int) Settings::get( 'right_of_withdrawal', 'lookback_days', 14 );
		$apply_to = (string) Settings::get( 'right_of_withdrawal', 'apply_to', 'all' );

		$all_orders   = $customer->recent_orders( $lookback );
		$withdrawable = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::withdrawable_orders( $customer, $lookback );
		$requests     = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::requests_for_display( get_current_user_id() );

		$is_vat_business = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::is_vat_business( $customer, $lookback );
		$vat_ok          = ( 'non_vat' !== $apply_to ) || ! $is_vat_business;
		$module_on = Settings::is_module_enabled( 'right_of_withdrawal' );
		$base_ok   = $customer->is_customer() && $customer->is_eu() && $vat_ok;
		$shows     = $module_on && $base_ok && ( ! empty( $withdrawable ) || ! empty( $requests ) );

		$rows[] = array( 'module: right_of_withdrawal enabled', $this->format( $module_on ) );
		$rows[] = array( 'setting: lookback_days', $this->format( $lookback ) );
		$rows[] = array( 'setting: apply_to', $this->format( $apply_to ) );
		$rows[] = array( 'recent orders in window', $this->format( count( $all_orders ) ) );
		$rows[] = array( 'withdrawable (after exclusions)', $this->format( count( $withdrawable ) ) );
		$rows[] = array( 'existing requests', $this->format( count( $requests ) ) );
		$rows[] = array( 'treated as VAT business', $this->format( $is_vat_business ) );
		$rows[] = array( 'vat check passes', $this->format( $vat_ok ) );
		$rows[] = array( '=> block displays', $this->format( $shows ) );

		$html  = '<table class="sceu-debug" style="border-collapse:collapse;font:13px/1.5 monospace;">';
		$html .= '<caption style="text-align:left;font-weight:bold;padding:4px 0;">SureCart EU Helper — diagnostic</caption>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			$html .= '<td style="border:1px solid #ddd;padding:4px 8px;">' . esc_html( $row[0] ) . '</td>';
			$html .= '<td style="border:1px solid #ddd;padding:4px 8px;">' . wp_kses_post( $row[1] ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</table>';

		return $html;
	}

	/**
	 * Pretty-print a value for the table.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function format( $value ): string {
		if ( is_bool( $value ) ) {
			return $value
				? '<strong style="color:#137333;">true</strong>'
				: '<strong style="color:#a50e0e;">false</strong>';
		}
		if ( null === $value || false === $value || '' === $value ) {
			return '<span style="color:#a50e0e;">(empty)</span>';
		}
		return esc_html( (string) $value );
	}
}
