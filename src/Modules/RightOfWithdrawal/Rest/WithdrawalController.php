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
						'required' => false,
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
					),
					'items'     => array(
						'required' => false,
						'type'     => 'array',
					),
					'email'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'name'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'reason'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Only logged-in users with a resolvable SureCart customer may submit.
	 *
	 * The cookie auth scheme already enforces the X-WP-Nonce, but we verify it
	 * explicitly so the CSRF guarantee does not silently depend on which auth
	 * scheme handled the request (mirroring the guest endpoints' explicit check).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permission( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
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

		$customer        = new CustomerContext();
		$lookback        = (int) Settings::get( 'right_of_withdrawal', 'lookback_days', 14 );
		$apply_to        = (string) Settings::get( 'right_of_withdrawal', 'apply_to', 'all' );
		$include_unknown = (bool) Settings::get( 'right_of_withdrawal', 'include_unknown_country', true );

		// Re-check eligibility on the server — never trust the client. The
		// withdrawable set already excludes orders the customer has previously
		// requested (preventing duplicate requests) and any refunded ones.
		$eligible_orders = Withdrawals::withdrawable_orders( $customer, $lookback );
		$is_eligible     = Eligibility::is_eligible(
			$customer->is_customer(),
			$customer->is_eu(),
			$customer->has_country(),
			count( $eligible_orders ),
			$customer->has_vat(),
			$apply_to,
			$include_unknown
		);
		if ( ! $is_eligible ) {
			return new \WP_Error( 'sceu_not_eligible', __( 'You are not eligible for this request, or you have already requested these orders.', 'surecart-eu-helper' ), array( 'status' => 403 ) );
		}

		// Validate the submitted selection (items + whole orders) against the
		// server's withdrawable set, clamping quantities to what's still
		// available. Never trust the client.
		$selected = $this->resolve_selection( $request, $eligible_orders );
		if ( empty( $selected['orders'] ) ) {
			return new \WP_Error( 'sceu_no_selection', __( 'Please select at least one valid item.', 'surecart-eu-helper' ), array( 'status' => 400 ) );
		}

		// Confirmation delivery always goes to the verified account email — never a
		// client-supplied address — so the request endpoint can't be abused to relay
		// store-branded mail to arbitrary recipients. The submitted email is kept
		// only as logged context (see $submitted_email below).
		$email           = $customer->customer_email();
		$submitted_email = sanitize_email( (string) $request->get_param( 'email' ) );
		$submitted_email = ( $submitted_email && is_email( $submitted_email ) ) ? $submitted_email : '';

		$resolved_name  = $customer->customer_name();
		$submitted_name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$name           = '' !== $submitted_name ? $submitted_name : $resolved_name;

		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		$request_id = Withdrawals::generate_request_id();
		$timestamp  = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		$ctx = array(
			'request_id'     => $request_id,
			'timestamp'      => $timestamp,
			'user_id'        => get_current_user_id(),
			'customer_id'    => (string) $customer->customer_id(),
			'customer_name'  => $name,
			'customer_email' => $email,
			'ip_address'     => (string) \sceu_client_ip(),
			'reason'         => $reason,
			'orders'         => $selected['orders'],
			'order_ids'      => wp_list_pluck( $selected['orders'], 'id' ),
			'items'          => $selected['items'],
			'merchant_email' => Withdrawals::merchant_recipient(),
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
					'orders'         => $selected['orders'],
					'items'          => $selected['items'],
					'merchant_to'    => $ctx['merchant_email'],
					// Email the customer typed in the form, for support reference only.
					// The confirmation was delivered to the verified account email above.
					'contact_email_provided' => $submitted_email,
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
				// Display data so the client can render the new request row without
				// a page reload (mirrors Withdrawals::requests_for_display()).
				'request'    => array(
					'id'          => $request_id,
					'status'      => Withdrawals::STATUS_RECEIVED,
					'statusLabel' => Withdrawals::status_label( Withdrawals::STATUS_RECEIVED ),
					'date'        => $timestamp,
					'orders'      => array_map(
						static function ( $order ) {
							return (string) ( $order['number'] ?: $order['id'] );
						},
						$selected['orders']
					),
					'details'     => array_map(
						static function ( $order ) {
							$ref     = (string) ( $order['number'] ?: $order['id'] );
							$summary = Withdrawals::items_summary( $order );
							return '' !== $summary
								/* translators: 1: order reference, 2: items + quantities. */
								? sprintf( __( 'Order %1$s — %2$s', 'surecart-eu-helper' ), $ref, $summary )
								/* translators: %s: order reference. */
								: sprintf( __( 'Order %s', 'surecart-eu-helper' ), $ref );
						},
						$selected['orders']
					),
				),
			),
			200
		);
	}

	/**
	 * Validate the submitted selection against the withdrawable set.
	 *
	 * Supports partial withdrawal: `items` is an array of
	 * { order_id, line_item_id, quantity }; quantities are clamped to each
	 * item's remaining amount. `order_ids` covers whole-order withdrawals for
	 * orders that have no line-item detail. Anything not currently withdrawable
	 * is silently dropped.
	 *
	 * @param \WP_REST_Request                 $request         Request.
	 * @param array<int, array<string, mixed>> $eligible_orders Withdrawable orders.
	 * @return array{orders: array<int, array<string,mixed>>, items: array<int, array<string,mixed>>}
	 */
	private function resolve_selection( \WP_REST_Request $request, array $eligible_orders ): array {
		// Index withdrawable orders and their remaining line items.
		$index = array();
		foreach ( $eligible_orders as $order ) {
			$lines = array();
			foreach ( (array) ( $order['line_items'] ?? array() ) as $li ) {
				$lines[ (string) $li['id'] ] = $li;
			}
			$index[ (string) $order['id'] ] = array(
				'order' => $order,
				'lines' => $lines,
				'whole' => ! empty( $order['whole_order'] ) || empty( $lines ),
			);
		}

		$chosen = array(); // order_id => array( order, whole, items[ line_id => row ] ).

		// Itemised selections.
		foreach ( (array) $request->get_param( 'items' ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$oid = (string) ( $it['order_id'] ?? '' );
			$lid = (string) ( $it['line_item_id'] ?? '' );
			$qty = (int) ( $it['quantity'] ?? 0 );
			if ( '' === $oid || '' === $lid || $qty < 1 ) {
				continue;
			}
			if ( ! isset( $index[ $oid ] ) || $index[ $oid ]['whole'] ) {
				continue;
			}
			if ( ! isset( $index[ $oid ]['lines'][ $lid ] ) ) {
				continue;
			}
			$line      = $index[ $oid ]['lines'][ $lid ];
			$remaining = (int) ( $line['remaining'] ?? $line['quantity'] ?? 0 );
			if ( $remaining < 1 ) {
				continue;
			}
			$qty = min( $qty, $remaining );

			if ( ! isset( $chosen[ $oid ] ) ) {
				$chosen[ $oid ] = array(
					'order' => $index[ $oid ]['order'],
					'whole' => false,
					'items' => array(),
				);
			}
			$chosen[ $oid ]['items'][ $lid ] = array(
				'id'           => $lid,
				'name'         => (string) ( $line['name'] ?? '' ),
				'quantity'     => $qty,
				// Original purchased quantity, so the log/merchant email can show
				// "2 of 3" and make partial vs full withdrawals explicit.
				'purchased'    => (int) ( $line['quantity'] ?? $qty ),
				'unit_display' => (string) ( $line['unit_display'] ?? '' ),
			);
		}

		// Whole-order selections (orders with no line-item detail).
		foreach ( array_map( 'strval', (array) $request->get_param( 'order_ids' ) ) as $oid ) {
			if ( ! isset( $index[ $oid ] ) || ! $index[ $oid ]['whole'] ) {
				continue;
			}
			$chosen[ $oid ] = array(
				'order' => $index[ $oid ]['order'],
				'whole' => true,
				'items' => array(),
			);
		}

		$orders     = array();
		$flat_items = array();
		foreach ( $chosen as $oid => $c ) {
			$order = $c['order'];
			$items = array_values( $c['items'] );
			$orders[] = array(
				'id'            => (string) $order['id'],
				'number'        => (string) ( $order['number'] ?? '' ),
				'total_display' => (string) ( $order['total_display'] ?? '' ),
				'whole_order'   => $c['whole'],
				// True only when the selection covers EVERY line item in the
				// order at its full purchased quantity (so the Partial / Full
				// label can't mislabel a single line item as the whole order).
				'covers_entire_order' => Withdrawals::covers_entire_order( $order, $c['items'] ),
				'line_items'    => $items,
			);
			foreach ( $items as $li ) {
				$flat_items[] = array(
					'order_id'     => (string) $order['id'],
					'line_item_id' => (string) $li['id'],
					'quantity'     => (int) $li['quantity'],
					'name'         => (string) $li['name'],
				);
			}
		}

		return array(
			'orders' => $orders,
			'items'  => $flat_items,
		);
	}
}
