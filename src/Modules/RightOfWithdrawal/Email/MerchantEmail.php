<?php
/**
 * Notification email sent to the merchant.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts the merchant that a customer wants to withdraw, with deep links
 * straight to each affected order in the SureCart admin.
 */
class MerchantEmail {

	/**
	 * Send the notification.
	 *
	 * @param array<string, mixed> $ctx Request context.
	 * @return bool
	 */
	public static function send( array $ctx ): bool {
		$to = sanitize_email( (string) ( $ctx['merchant_email'] ?? '' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$subject = __( 'New right-of-withdrawal request', 'surecart-eu-helper' );
		/**
		 * Filter the merchant notification email subject.
		 *
		 * @param string               $subject Plain-text subject line.
		 * @param array<string, mixed> $ctx     Request context passed to wp_mail().
		 */
		$subject = (string) apply_filters( 'sceu_merchant_email_subject', $subject, $ctx );

		$lines   = array();
		$lines[] = '<p>' . esc_html__( 'A customer has submitted a right-of-withdrawal request. Review the order(s) below and process the cancellation/refund in SureCart.', 'surecart-eu-helper' ) . '</p>';

		$lines[] = '<p><strong>' . esc_html__( 'Customer:', 'surecart-eu-helper' ) . '</strong> ' . esc_html( (string) ( $ctx['customer_name'] ?? '' ) ) . '<br />';
		$lines[] = '<strong>' . esc_html__( 'Email:', 'surecart-eu-helper' ) . '</strong> ' . esc_html( (string) ( $ctx['customer_email'] ?? '' ) ) . '<br />';
		$lines[] = '<strong>' . esc_html__( 'Request reference:', 'surecart-eu-helper' ) . '</strong> ' . esc_html( (string) ( $ctx['request_id'] ?? '' ) ) . '<br />';
		$lines[] = '<strong>' . esc_html__( 'Received at:', 'surecart-eu-helper' ) . '</strong> ' . esc_html( (string) ( $ctx['timestamp'] ?? '' ) ) . '<br />';
		$lines[] = '<strong>' . esc_html__( 'IP address:', 'surecart-eu-helper' ) . '</strong> ' . esc_html( (string) ( $ctx['ip_address'] ?? '' ) ) . '</p>';

		$lines[] = self::orders_block( $ctx['orders'] ?? array() );

		if ( ! empty( $ctx['reason'] ) ) {
			$lines[] = '<p><strong>' . esc_html__( 'Customer note:', 'surecart-eu-helper' ) . '</strong><br />' . nl2br( esc_html( (string) $ctx['reason'] ) ) . '</p>';
		}

		$body = EmailRenderer::wrap( $subject, implode( "\n", $lines ) );
		/**
		 * Filter the merchant notification email HTML body.
		 *
		 * The default body is built from escaped values; this filter runs after
		 * that assembly and its return value is sent to wp_mail() without further
		 * escaping. Hook authors must return safe HTML.
		 *
		 * @param string               $body Complete HTML email (header + body).
		 * @param array<string, mixed> $ctx  Request context passed to wp_mail().
		 */
		$body = (string) apply_filters( 'sceu_merchant_email_body', $body, $ctx );

		return wp_mail( $to, $subject, $body, EmailRenderer::headers() );
	}

	/**
	 * Render the affected orders with a deep-link button each.
	 *
	 * @param array<int, array<string, mixed>> $orders Orders.
	 * @return string
	 */
	private static function orders_block( array $orders ): string {
		if ( empty( $orders ) ) {
			return '';
		}
		$out = '<h4 style="margin:18px 0 8px;">' . esc_html__( 'Orders to process', 'surecart-eu-helper' ) . '</h4>';
		foreach ( $orders as $order ) {
			$ref   = (string) ( $order['number'] ?? $order['id'] ?? '' );
			$id    = (string) ( $order['id'] ?? '' );
			$when  = ! empty( $order['created_at'] ) ? date_i18n( get_option( 'date_format' ), (int) $order['created_at'] ) : '';
			$total = (string) ( $order['total_display'] ?? '' );
			$url   = sceu_order_admin_url( $id );

			$meta = array_filter( array( $when, $total, (string) ( $order['summary'] ?? '' ) ) );

			$out .= '<div style="border:1px solid #e2e2e2;border-radius:6px;padding:12px 14px;margin-bottom:10px;">'
				. '<div style="font-weight:600;margin-bottom:4px;">' . sprintf(
					/* translators: %s: order reference. */
					esc_html__( 'Order %s', 'surecart-eu-helper' ),
					esc_html( $ref )
				) . '</div>'
				. ( $meta ? '<div style="color:#555;font-size:13px;margin-bottom:10px;">' . esc_html( implode( ' · ', $meta ) ) . '</div>' : '' )
				. '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:8px 14px;border-radius:4px;font-size:14px;">'
				. esc_html__( 'Open order in SureCart', 'surecart-eu-helper' ) . '</a>'
				. '</div>';
		}
		return $out;
	}
}
