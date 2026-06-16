<?php
/**
 * Shared HTML email scaffolding.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Email;

use SureCartEuHelper\Merchant\MerchantInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps email content in a minimal, client-safe HTML shell and provides the
 * HTML mail headers.
 */
class EmailRenderer {

	/**
	 * HTML headers for wp_mail.
	 *
	 * @return string[]
	 */
	public static function headers(): array {
		return array( 'Content-Type: text/html; charset=UTF-8' );
	}

	/**
	 * Wrap body HTML in a simple branded shell.
	 *
	 * @param string $title Heading/title.
	 * @param string $inner Inner HTML.
	 * @return string
	 */
	public static function wrap( string $title, string $inner ): string {
		$store = esc_html( MerchantInfo::store_name() );

		$html  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1a1a1a;line-height:1.5;">';
		$html .= '<div style="padding:18px 0;border-bottom:2px solid #111;font-size:18px;font-weight:700;">' . $store . '</div>';
		$html .= '<h2 style="font-size:20px;margin:20px 0 12px;">' . esc_html( $title ) . '</h2>';
		$html .= '<div>' . $inner . '</div>';
		$html .= '<div style="margin-top:24px;padding-top:14px;border-top:1px solid #e2e2e2;color:#777;font-size:12px;">';
		$html .= esc_html__( 'This message was sent automatically by SureCart EU Helper.', 'surecart-eu-helper' );
		$html .= '</div></div>';

		return $html;
	}
}
