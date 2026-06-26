<?php
/**
 * Public (logged-out) REST endpoints for the front-end withdrawal form.
 *
 * Two routes, both unauthenticated but defended with a logged-out nonce, a
 * per-IP rate limit, and a honeypot:
 *  - guest-lookup: find an order by number + email; on a match, return its
 *    withdrawable items so the visitor can pick what to withdraw.
 *  - guest-withdrawal-request: record the request. If the order verifies, the
 *    selected items are validated server-side against a fresh lookup; otherwise
 *    the visitor's free-text description is logged as an unverified request for
 *    the merchant to handle.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Rest;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Merchant\MerchantInfo;
use SureCartEuHelper\Modules\RightOfWithdrawal\GuestLookup;
use SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals;
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable;
use SureCartEuHelper\Modules\RightOfWithdrawal\Email\CustomerEmail;
use SureCartEuHelper\Modules\RightOfWithdrawal\Email\MerchantEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the public withdrawal form's lookup + submit.
 */
class GuestController {

	const NAMESPACE     = 'surecart-eu-helper/v1';
	const LOOKUP_ROUTE  = '/guest-lookup';
	const SUBMIT_ROUTE  = '/guest-withdrawal-request';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // Public; defended in-handler (nonce + rate limit + honeypot).
		);

		// Shared input contract. Handlers still re-sanitise and re-validate every
		// value server-side (and never trust the client), but declaring the schema
		// keeps the public surface explicit and consistent with SureCart's REST.
		$common_args = array(
			'email'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
			),
			'order_number' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hp'           => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		$submit_args = array_merge(
			$common_args,
			array(
				'name'   => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'reason' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'items'  => array(
					'type'     => 'array',
					'required' => false,
				),
			)
		);

		register_rest_route( self::NAMESPACE, self::LOOKUP_ROUTE, array_merge( $base, array( 'callback' => array( $this, 'lookup' ), 'args' => $common_args ) ) );
		register_rest_route( self::NAMESPACE, self::SUBMIT_ROUTE, array_merge( $base, array( 'callback' => array( $this, 'submit' ), 'args' => $submit_args ) ) );
	}

	/**
	 * Look up an order by email + number.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup( \WP_REST_Request $request ) {
		$guard = $this->guard( $request, 'lookup', 15 );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		// A filled honeypot: behave exactly like "not found" so bots learn nothing.
		if ( $this->is_bot( $request ) ) {
			return new \WP_REST_Response( array( 'found' => false ), 200 );
		}

		$email  = sanitize_email( (string) $request->get_param( 'email' ) );
		$number = sanitize_text_field( (string) $request->get_param( 'order_number' ) );
		if ( '' === $email || ! is_email( $email ) || '' === $number ) {
			return new \WP_REST_Response( array( 'found' => false ), 200 );
		}

		$order = GuestLookup::find_order( $email, $number );
		if ( null === $order ) {
			// Never reveal which of email/number was wrong.
			return new \WP_REST_Response( array( 'found' => false ), 200 );
		}

		return new \WP_REST_Response(
			array(
				'found' => true,
				'order' => $this->public_order( $order ),
			),
			200
		);
	}

	/**
	 * Record a withdrawal request from the public form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$guard = $this->guard( $request, 'submit', 8 );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( $this->is_bot( $request ) ) {
			// Pretend success without doing anything.
			return new \WP_REST_Response( array( 'success' => true ), 200 );
		}

		$email  = sanitize_email( (string) $request->get_param( 'email' ) );
		$number = sanitize_text_field( (string) $request->get_param( 'order_number' ) );
		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		if ( '' === $email || ! is_email( $email ) || '' === $number ) {
			return new \WP_Error( 'sceu_invalid', __( 'Please provide your email and order number.', 'surecart-eu-helper' ), array( 'status' => 400 ) );
		}

		// Re-verify the order from scratch (never trust the client's claim that it
		// was found). A fresh lookup also re-applies exclusions.
		$order = GuestLookup::find_order( $email, $number );

		if ( null !== $order ) {
			return $this->submit_verified( $request, $order, $email, $name, $reason );
		}

		// Not found / not verified → free-text fallback. Require a description so
		// the merchant has something to act on.
		if ( '' === $reason ) {
			return new \WP_Error( 'sceu_need_detail', __( 'We could not match that order, so please describe what you would like to withdraw from.', 'surecart-eu-helper' ), array( 'status' => 422 ) );
		}
		return $this->submit_unverified( $email, $number, $name, $reason );
	}

	/**
	 * Verified path: validate the selected items against the looked-up order,
	 * log, and notify.
	 *
	 * @param \WP_REST_Request     $request Request.
	 * @param array<string, mixed> $order   Looked-up order (annotated).
	 * @param string               $email   Verified email.
	 * @param string               $name    Submitted name.
	 * @param string               $reason  Optional note.
	 * @return \WP_REST_Response
	 */
	private function submit_verified( \WP_REST_Request $request, array $order, string $email, string $name, string $reason ): \WP_REST_Response {
		$selected = $this->resolve_items( $request, $order );
		if ( empty( $selected['line_items'] ) && empty( $order['whole_order'] ) ) {
			// Nothing valid chosen; fall back to treating it as a free-text note if
			// one was given, else error.
			if ( '' !== $reason ) {
				return $this->submit_unverified( $email, (string) $order['number'], $name, $reason );
			}
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Please select at least one item to withdraw.', 'surecart-eu-helper' ),
				),
				200
			);
		}

		$order_payload = array(
			'id'                  => (string) $order['id'],
			'number'              => (string) ( $order['number'] ?? '' ),
			'total_display'       => (string) ( $order['total_display'] ?? '' ),
			'whole_order'         => ! empty( $order['whole_order'] ),
			'covers_entire_order' => $this->covers_entire_order( $order, $selected['line_items'] ),
			'line_items'          => array_values( $selected['line_items'] ),
		);

		$ctx = $this->build_ctx( $email, $name, $reason, array( $order_payload ), $selected['flat'], true );
		$this->dispatch( $ctx, true );

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'request_id' => $ctx['request_id'],
			),
			200
		);
	}

	/**
	 * Unverified path: log the visitor's free-text request and notify the
	 * merchant to verify manually.
	 *
	 * @param string $email  Email.
	 * @param string $number Order number as typed.
	 * @param string $name   Name.
	 * @param string $reason Free-text description.
	 * @return \WP_REST_Response
	 */
	private function submit_unverified( string $email, string $number, string $name, string $reason ): \WP_REST_Response {
		// Prefix the typed order reference into the note so it travels with the log
		// and the merchant email even though we couldn't verify it.
		$note = trim(
			sprintf(
				/* translators: 1: order number the visitor typed, 2: their description. */
				__( 'Unverified request. Order number given: %1$s. Details: %2$s', 'surecart-eu-helper' ),
				$number,
				$reason
			)
		);

		$ctx = $this->build_ctx( $email, $name, $note, array(), array(), false );
		$this->dispatch( $ctx, false );

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'request_id' => $ctx['request_id'],
				'unverified' => true,
			),
			200
		);
	}

	/**
	 * Validate the submitted items against the looked-up order, clamping each
	 * quantity to what the order actually allows.
	 *
	 * @param \WP_REST_Request     $request Request.
	 * @param array<string, mixed> $order   Looked-up order.
	 * @return array{line_items: array<int, array<string,mixed>>, flat: array<int, array<string,mixed>>}
	 */
	private function resolve_items( \WP_REST_Request $request, array $order ): array {
		$allowed = array();
		foreach ( (array) ( $order['line_items'] ?? array() ) as $li ) {
			$allowed[ (string) $li['id'] ] = $li;
		}

		$chosen = array();
		$flat   = array();
		foreach ( (array) $request->get_param( 'items' ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$lid = (string) ( $it['line_item_id'] ?? '' );
			$qty = (int) ( $it['quantity'] ?? 0 );
			if ( '' === $lid || $qty < 1 || ! isset( $allowed[ $lid ] ) ) {
				continue;
			}
			$line = $allowed[ $lid ];
			$max  = (int) ( $line['remaining'] ?? $line['quantity'] ?? 0 );
			if ( $max < 1 ) {
				continue;
			}
			$qty             = min( $qty, $max );
			$chosen[ $lid ]  = array(
				'id'           => $lid,
				'name'         => (string) ( $line['name'] ?? '' ),
				'quantity'     => $qty,
				'purchased'    => (int) ( $line['quantity'] ?? $qty ),
				'unit_display' => (string) ( $line['unit_display'] ?? '' ),
			);
			$flat[]          = array(
				'order_id'     => (string) $order['id'],
				'line_item_id' => $lid,
				'quantity'     => $qty,
				'name'         => (string) ( $line['name'] ?? '' ),
			);
		}

		return array(
			'line_items' => $chosen,
			'flat'       => $flat,
		);
	}

	/**
	 * Whether the chosen items cover the order's entire original line-item set.
	 *
	 * @param array<string, mixed>                $order  Looked-up order.
	 * @param array<string, array<string, mixed>> $chosen Chosen items keyed by line id.
	 * @return bool
	 */
	private function covers_entire_order( array $order, array $chosen ): bool {
		$all = isset( $order['all_line_items'] ) && is_array( $order['all_line_items'] ) ? $order['all_line_items'] : array();
		if ( empty( $all ) ) {
			return false;
		}
		foreach ( $all as $line ) {
			$id        = (string) ( $line['id'] ?? '' );
			$purchased = (int) ( $line['quantity'] ?? 0 );
			if ( '' === $id || $purchased < 1 ) {
				return false;
			}
			$picked = isset( $chosen[ $id ] ) ? (int) $chosen[ $id ]['quantity'] : 0;
			if ( $picked < $purchased ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build the notification/log context for a guest submission.
	 *
	 * @param string                           $email    Email.
	 * @param string                           $name     Name.
	 * @param string                           $reason   Note / description.
	 * @param array<int, array<string, mixed>> $orders   Order payloads (may be empty).
	 * @param array<int, array<string, mixed>> $items    Flat item list (may be empty).
	 * @param bool                             $verified Whether the order was verified.
	 * @return array<string, mixed>
	 */
	private function build_ctx( string $email, string $name, string $reason, array $orders, array $items, bool $verified ): array {
		$suffix = strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 6 ) );

		return array(
			'request_id'     => 'WD-' . gmdate( 'Ymd' ) . '-' . $suffix,
			'timestamp'      => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'user_id'        => 0,
			'customer_id'    => '',
			'customer_name'  => '' !== $name ? $name : __( 'Customer', 'surecart-eu-helper' ),
			'customer_email' => $email,
			'ip_address'     => $this->ip_address(),
			'reason'         => $reason,
			'orders'         => $orders,
			'order_ids'      => wp_list_pluck( $orders, 'id' ),
			'items'          => $items,
			'merchant_email' => $this->merchant_email(),
			'store_name'     => MerchantInfo::store_name(),
			'guest'          => true,
			'verified'       => $verified,
		);
	}

	/**
	 * Send the emails and write the log row for a guest submission.
	 *
	 * @param array<string, mixed> $ctx      Context.
	 * @param bool                 $verified Whether the order was verified.
	 * @return void
	 */
	private function dispatch( array $ctx, bool $verified ): void {
		// Only email the customer when the order + email were verified. On the
		// unverified (free-text) path the email is unconfirmed, so sending a
		// store-branded confirmation there would let the form be abused to relay
		// mail to arbitrary addresses. The merchant is always notified.
		$customer_sent = $verified ? CustomerEmail::send( $ctx ) : false;
		$merchant_sent = MerchantEmail::send( $ctx );

		LogTable::maybe_create();
		LogTable::insert(
			array(
				'user_id'        => 0,
				'customer_id'    => '',
				'customer_name'  => $ctx['customer_name'],
				'customer_email' => $ctx['customer_email'],
				'ip_address'     => $ctx['ip_address'],
				'order_ids'      => $ctx['order_ids'],
				'payload'        => array(
					'request_id'          => $ctx['request_id'],
					'reason'              => $ctx['reason'],
					'orders'              => $ctx['orders'],
					'items'               => $ctx['items'],
					'merchant_to'         => $ctx['merchant_email'],
					'source'              => 'guest_form',
					'verified'            => $verified,
					'customer_email_sent' => (bool) $customer_sent,
					'merchant_email_sent' => (bool) $merchant_sent,
				),
				'status'         => Withdrawals::STATUS_RECEIVED,
			)
		);
	}

	/**
	 * Shape an order for the public response (no internal ids beyond what the
	 * picker needs to submit).
	 *
	 * @param array<string, mixed> $order Looked-up order.
	 * @return array<string, mixed>
	 */
	private function public_order( array $order ): array {
		$items = array();
		foreach ( (array) ( $order['line_items'] ?? array() ) as $li ) {
			$items[] = array(
				'id'           => (string) $li['id'],
				'name'         => (string) ( $li['name'] ?? '' ),
				'max'          => (int) ( $li['remaining'] ?? $li['quantity'] ?? 1 ),
				'unit_display' => (string) ( $li['unit_display'] ?? '' ),
				'image'        => (string) ( $li['image'] ?? '' ),
				'image_alt'    => (string) ( $li['image_alt'] ?? '' ),
			);
		}
		return array(
			'number'        => (string) ( $order['number'] ?? '' ),
			'total_display' => (string) ( $order['total_display'] ?? '' ),
			'whole_order'   => ! empty( $order['whole_order'] ),
			'items'         => $items,
		);
	}

	/**
	 * Shared request guard: logged-out nonce + per-IP rate limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $bucket  Rate-limit bucket name.
	 * @param int              $max     Max requests per window (10 min).
	 * @return true|\WP_Error
	 */
	private function guard( \WP_REST_Request $request, string $bucket, int $max ) {
		$nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'sceu_bad_nonce', __( 'Your session expired. Please reload the page and try again.', 'surecart-eu-helper' ), array( 'status' => 403 ) );
		}

		// Throttle on a spoof-resistant identity (REMOTE_ADDR by default), NOT the
		// proxy-header-derived audit IP, which a client can forge to win a fresh
		// bucket on every request. See sceu_rate_limit_ip().
		$key   = 'sceu_guest_' . $bucket . '_' . md5( (string) \sceu_rate_limit_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new \WP_Error( 'sceu_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'surecart-eu-helper' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Honeypot check: a real browser leaves the hidden field empty.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_bot( \WP_REST_Request $request ): bool {
		return '' !== trim( (string) $request->get_param( 'hp' ) );
	}

	/**
	 * Effective merchant notification email.
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
	 * Best-effort client IP for rate limiting + the audit log.
	 *
	 * @return string
	 */
	private function ip_address(): string {
		// Resolves the real visitor IP behind CDNs/proxies (e.g. Cloudflare),
		// rather than the proxy's REMOTE_ADDR.
		return (string) \sceu_client_ip();
	}
}
