<?php
/**
 * REST endpoint for submitting a withdrawal request.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Rest;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Customer\CustomerContext;
use SureCartEuHelper\Merchant\MerchantInfo;
use SureCartEuHelper\Modules\RightOfWithdrawal\Eligibility;
use SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals;
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable;
use SureCartEuHelper\Modules\RightOfWithdrawal\Email\CustomerEmail;
use SureCartEuHelper\Modules\RightOfWithdrawal\Email\MerchantEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Receives the form submission, re-validates everything server-side (never
 * trusting the client), logs the request, and sends both emails.
 */
class WithdrawalController {

	const NAMESPACE = 'surecart-eu-helper/v1';
	const ROUTE     = '/withdrawal-request';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'order_ids' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
	}

	/**
	 * Only logged-in users with a resolvable SureCart customer may submit. The
	 * cookie nonce (X-WP-Nonce) is validated by the REST infrastructure.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return ( new CustomerContext() )->is_customer();
	}

	/**
	 * Handle the submission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		if ( ! Settings::is_module_enabled( 'right_of_withdrawal' ) ) {
			return new \WP_Error( 'sceu_module_disabled', __( 'This feature is not available.', 'surecart-eu-helper' ), array( 'status' => 403 ) );
		}

		// Throttle: block rapid repeat submissions (prevents email-spam abuse).
		$throttle_key = 'sceu_wd_throttle_' . get_current_user_id();
		if ( get_transient( $throttle_key ) ) {
			return new \WP_Error( 'sceu_too_many', __( 'Please wait a moment before submitting another request.', 'surecart-eu-helper' ), array( 'status' => 429 ) );
		}
		/**
		 * Filter the per-user cool-down (seconds) between withdrawal submissions.
		 *
		 * @param int $seconds Default 20.
		 */
		set_transient( $throttle_key, 1, (int) apply_filters( 'sceu_submission_cooldown', 20 ) );

		$customer = new CustomerContext();
		$lookback = (int) Settings::get( 'right_of_withdrawal', 'lookback_days', 14 );
		$apply_to = (string) Settings::get( 'right_of_withdrawal', 'apply_to', 'all' );

		// Re-check eligibility on the server — never trust the client. The
		// withdrawable set already excludes orders the customer has previously
		// requested (preventing duplicate requests) and any refunded ones.
		$eligible_orders = Withdrawals::withdrawable_orders( $customer, $lookback );
		$is_eligible     = Eligibility::is_eligible(
			$customer->is_customer(),
			$customer->is_eu(),
			count( $eligible_orders ),
			$customer->has_vat(),
			$apply_to
		);
		if ( ! $is_eligible ) {
			return new \WP_Error( 'sceu_not_eligible', __( 'You are not eligible for this request, or you have already requested these orders.', 'surecart-eu-helper' ), array( 'status' => 403 ) );
		}

		// Intersect submitted ids with the server's eligible set.
		$submitted = array_map( 'strval', (array) $request->get_param( 'order_ids' ) );
		$selected  = array();
		foreach ( $eligible_orders as $order ) {
			if ( in_array( (string) $order['id'], $submitted, true ) ) {
				$selected[] = $order;
			}
		}

		if ( empty( $selected ) ) {
			return new \WP_Error( 'sceu_no_selection', __( 'Please select at least one valid order.', 'surecart-eu-helper' ), array( 'status' => 400 ) );
		}

		// Verified identity: prefer the resolved customer; allow a confirmed email.
		$resolved_email = $customer->customer_email();
		$submitted_email = sanitize_email( (string) $request->get_param( 'email' ) );
		$email          = ( $submitted_email && is_email( $submitted_email ) ) ? $submitted_email : $resolved_email;

		$resolved_name  = $customer->customer_name();
		$submitted_name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$name           = '' !== $submitted_name ? $submitted_name : $resolved_name;

		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		$request_id = $this->generate_request_id();
		$timestamp  = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		$ctx = array(
			'request_id'     => $request_id,
			'timestamp'      => $timestamp,
			'user_id'        => get_current_user_id(),
			'customer_id'    => (string) $customer->customer_id(),
			'customer_name'  => $name,
			'customer_email' => $email,
			'ip_address'     => $this->ip_address(),
			'reason'         => $reason,
			'orders'         => $selected,
			'order_ids'      => wp_list_pluck( $selected, 'id' ),
			'merchant_email' => $this->merchant_email(),
			'store_name'     => MerchantInfo::store_name(),
		);

		/**
		 * Fires after a withdrawal request is validated, before notifications.
		 * Lets add-ons hook in (e.g. auto-cancel).
		 *
		 * @param array<string, mixed> $ctx Request context.
		 */
		do_action( 'sceu_withdrawal_request_received', $ctx );

		// Notifications — capture results so the merchant can diagnose delivery.
		$customer_sent = CustomerEmail::send( $ctx );
		$merchant_sent = MerchantEmail::send( $ctx );

		// Always log (the request log is the source of truth for what has been
		// requested, and powers the dashboard status + duplicate prevention).
		LogTable::maybe_create();
		LogTable::insert(
			array(
				'user_id'        => $ctx['user_id'],
				'customer_id'    => $ctx['customer_id'],
				'customer_name'  => $ctx['customer_name'],
				'customer_email' => $ctx['customer_email'],
				'ip_address'     => $ctx['ip_address'],
				'order_ids'      => $ctx['order_ids'],
				'payload'        => array(
					'request_id'     => $request_id,
					'reason'         => $reason,
					'orders'         => $selected,
					'merchant_to'    => $ctx['merchant_email'],
					'customer_email_sent' => (bool) $customer_sent,
					'merchant_email_sent' => (bool) $merchant_sent,
				),
				'status'         => Withdrawals::STATUS_RECEIVED,
			)
		);

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'request_id' => $request_id,
				'timestamp'  => $timestamp,
			),
			200
		);
	}

	/**
	 * Effective merchant email: the configured value, else the resolved default.
	 *
	 * @return string
	 */
	private function merchant_email(): string {
		$configured = sanitize_email( (string) Settings::get( 'right_of_withdrawal', 'merchant_email', '' ) );
		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}
		return MerchantInfo::notification_email();
	}

	/**
	 * Generate a human-friendly request reference.
	 *
	 * @return string
	 */
	private function generate_request_id(): string {
		$suffix = strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 6 ) );
		return 'WD-' . gmdate( 'Ymd' ) . '-' . $suffix;
	}

	/**
	 * Best-effort client IP for the audit log.
	 *
	 * @return string
	 */
	private function ip_address(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the recorded client IP (e.g. to read a trusted proxy header).
		 *
		 * @param string $ip Default REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'sceu_request_ip', $ip );
		return ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
	}
}
