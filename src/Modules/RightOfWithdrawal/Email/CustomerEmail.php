<?php
/**
 * Confirmation email sent to the customer.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Timestamped receipt confirming the withdrawal request was received.
 */
class CustomerEmail {

	/**
	 * Send the confirmation.
	 *
	 * @param array<string, mixed> $ctx Request context.
	 * @return bool
	 */
	public static function send( array $ctx ): bool {
		$to = sanitize_email( (string) ( $ctx['customer_email'] ?? '' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$store = (string) ( $ctx['store_name'] ?? '' );

		/* translators: %s: store name. */
		$subject = sprintf( __( 'We received your withdrawal request — %s', 'surecart-eu-helper' ), $store );
		$subject = (string) apply_filters( 'sceu_customer_email_subject', $subject, $ctx );

		$lines   = array();
		$lines[] = '<p>' . sprintf(
			/* translators: %s: customer name. */
			esc_html__( 'Hello %s,', 'surecart-eu-helper' ),
			esc_html( (string) ( $ctx['customer_name'] ?? '' ) )
		) . '</p>';
		$lines[] = '<p>' . esc_html__( 'This confirms that we have received your request to withdraw from the following order(s). Our team will process it and follow up with you.', 'surecart-eu-helper' ) . '</p>';
		$lines[] = self::orders_table( $ctx['orders'] ?? array() );
		$lines[] = '<p><strong>' . esc_html__( 'Request reference:', 'surecart-eu-helper' ) . '</strong> ' . esc_html( (string) ( $ctx['request_id'] ?? '' ) ) . '<br />';
		$lines[] = '<strong>' . esc_html__( 'Received at:', 'surecart-eu-helper' ) . '</strong> ' . esc_html( (string) ( $ctx['timestamp'] ?? '' ) ) . '</p>';

		if ( ! empty( $ctx['reason'] ) ) {
			$lines[] = '<p><strong>' . esc_html__( 'Your note:', 'surecart-eu-helper' ) . '</strong><br />' . nl2br( esc_html( (string) $ctx['reason'] ) ) . '</p>';
		}

		$body = EmailRenderer::wrap( $subject, implode( "\n", $lines ) );
		$body = (string) apply_filters( 'sceu_customer_email_body', $body, $ctx );

		return wp_mail( $to, $subject, $body, EmailRenderer::headers() );
	}

	/**
	 * Render the selected orders as an HTML table.
	 *
	 * @param array<int, array<string, mixed>> $orders Orders.
	 * @return string
	 */
	private static function orders_table( array $orders ): string {
		if ( empty( $orders ) ) {
			return '';
		}
		$rows = '';
		foreach ( $orders as $order ) {
			$ref   = (string) ( $order['number'] ?? $order['id'] ?? '' );
			$when  = ! empty( $order['created_at'] ) ? date_i18n( get_option( 'date_format' ), (int) $order['created_at'] ) : '';
			$total = (string) ( $order['total_display'] ?? '' );
			$rows .= '<tr>'
				. '<td style="padding:6px 10px;border:1px solid #e2e2e2;">' . esc_html( $ref ) . '</td>'
				. '<td style="padding:6px 10px;border:1px solid #e2e2e2;">' . esc_html( $when ) . '</td>'
				. '<td style="padding:6px 10px;border:1px solid #e2e2e2;">' . esc_html( $total ) . '</td>'
				. '</tr>';
		}
		return '<table style="border-collapse:collapse;margin:16px 0;">'
			. '<thead><tr>'
			. '<th style="padding:6px 10px;border:1px solid #e2e2e2;text-align:left;">' . esc_html__( 'Order', 'surecart-eu-helper' ) . '</th>'
			. '<th style="padding:6px 10px;border:1px solid #e2e2e2;text-align:left;">' . esc_html__( 'Date', 'surecart-eu-helper' ) . '</th>'
			. '<th style="padding:6px 10px;border:1px solid #e2e2e2;text-align:left;">' . esc_html__( 'Total', 'surecart-eu-helper' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}
}
