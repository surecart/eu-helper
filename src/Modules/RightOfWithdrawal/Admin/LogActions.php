<?php
/**
 * Admin-post controllers for the withdrawal request log.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Admin;

use SureCartEuHelper\Merchant\MerchantInfo;
use SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals;
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable;
use SureCartEuHelper\Modules\RightOfWithdrawal\Email\CustomerEmail;
use SureCartEuHelper\Modules\RightOfWithdrawal\Email\MerchantEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every `admin_post_sceu_*` action for the withdrawal log: CSV export,
 * status changes, the best-effort SureCart sync, deletion, and re-sending
 * notifications. Extracted from the module so the module just wires runtime
 * hooks and these handlers live with a single shared capability/nonce guard.
 */
class LogActions {

	/**
	 * Register the admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_sceu_export_log', array( $this, 'export_csv' ) );
		add_action( 'admin_post_sceu_set_status', array( $this, 'set_status' ) );
		add_action( 'admin_post_sceu_sync_log', array( $this, 'sync_log' ) );
		add_action( 'admin_post_sceu_delete_log', array( $this, 'delete_log' ) );
		add_action( 'admin_post_sceu_resend_emails', array( $this, 'resend_emails' ) );
	}

	/**
	 * Shared guard for every log action: require an admin, then verify the
	 * action's nonce. Dies on failure (the WordPress convention for admin-post).
	 *
	 * @param string $nonce_action Nonce action name (already including any id).
	 * @return void
	 */
	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Read the `id` query arg as a positive integer.
	 *
	 * @return int
	 */
	private function request_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guarded by check_admin_referer in the caller.
		return isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
	}

	/**
	 * Admin action: re-send the customer and/or merchant notification email for
	 * a logged request.
	 *
	 * The email is rebuilt from the stored log row, so the "Received at:"
	 * timestamp reflects when the withdrawal was originally requested — not the
	 * moment it was re-sent. The per-recipient sent flags are updated with the
	 * new outcome so the log reflects reality.
	 *
	 * @return void
	 */
	public function resend_emails(): void {
		$id = $this->request_id();
		$this->guard( 'sceu_resend_emails_' . $id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guarded above.
		$which = isset( $_GET['which'] ) ? sanitize_key( wp_unslash( $_GET['which'] ) ) : 'both';
		if ( ! in_array( $which, array( 'both', 'customer', 'merchant' ), true ) ) {
			$which = 'both';
		}

		$row = $id ? LogTable::find( $id ) : null;
		if ( ! $row ) {
			wp_safe_redirect( admin_url( 'admin.php?page=sceu-withdrawal-log' ) );
			exit;
		}

		$ctx     = $this->ctx_from_row( $row );
		$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$results = array();
		if ( 'merchant' !== $which ) {
			$ok                             = CustomerEmail::send( $ctx );
			$payload['customer_email_sent'] = (bool) $ok;
			$results[]                      = (bool) $ok;
		}
		if ( 'customer' !== $which ) {
			$ok                             = MerchantEmail::send( $ctx );
			$payload['merchant_email_sent'] = (bool) $ok;
			$results[]                      = (bool) $ok;
		}

		LogTable::update_payload( $id, $payload );

		$all_ok = ! empty( $results ) && ! in_array( false, $results, true );
		wp_safe_redirect( admin_url( 'admin.php?page=sceu-withdrawal-log&resent=' . ( $all_ok ? 'ok' : 'fail' ) ) );
		exit;
	}

	/**
	 * Admin action: permanently delete a log row (GDPR erasure / test cleanup).
	 *
	 * The log is append-only for normal use; deletion is deliberate, gated to
	 * admins + nonce, and frees the order to be requested again.
	 *
	 * @return void
	 */
	public function delete_log(): void {
		$id = $this->request_id();
		$this->guard( 'sceu_delete_log_' . $id );

		if ( $id ) {
			LogTable::delete( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sceu-withdrawal-log&deleted=1' ) );
		exit;
	}

	/**
	 * Admin action: change a request's status.
	 *
	 * @return void
	 */
	public function set_status(): void {
		$id = $this->request_id();
		$this->guard( 'sceu_set_status_' . $id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guarded above.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$allowed = array( Withdrawals::STATUS_RECEIVED, Withdrawals::STATUS_RESOLVED, Withdrawals::STATUS_REJECTED );
		if ( $id && in_array( $status, $allowed, true ) ) {
			LogTable::update_status( $id, $status );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sceu-withdrawal-log&updated=1' ) );
		exit;
	}

	/**
	 * Admin action: best-effort sync of pending requests against SureCart.
	 *
	 * For each pending request we classify every order it covers (none / partial /
	 * full refund, where cancelled/void counts as full):
	 *
	 *  - Every order fully refunded/cancelled → mark the request **resolved** (an
	 *    unambiguous outcome).
	 *  - Any refund present but not all full (a partial refund, or a mix) → leave
	 *    the status alone and **flag it for review**. A partial refund cannot be
	 *    safely attributed to one of several pending requests on the same order
	 *    (SureCart refunds are amount-based and carry no per-line detail), so the
	 *    plugin surfaces it for the merchant rather than guessing.
	 *  - No refund anywhere → untouched.
	 *
	 * SureCart does not always surface refunds, so this remains a convenience, not
	 * a guarantee — the merchant can always set status manually.
	 *
	 * @return void
	 */
	public function sync_log(): void {
		$this->guard( 'sceu_sync_log' );

		$resolved = 0;
		$flagged  = 0;
		foreach ( LogTable::rows_by_status( Withdrawals::STATUS_RECEIVED ) as $row ) {
			$ids = json_decode( (string) ( $row['order_ids'] ?? '[]' ), true );
			if ( ! is_array( $ids ) || empty( $ids ) ) {
				continue;
			}

			$all_full = true;
			$any      = false;
			foreach ( $ids as $order_id ) {
				$state = $this->order_refund_state( (string) $order_id );
				if ( 'none' !== $state ) {
					$any = true;
				}
				if ( 'full' !== $state ) {
					$all_full = false;
				}
			}

			if ( $all_full ) {
				LogTable::update_status( (int) $row['id'], Withdrawals::STATUS_RESOLVED );
				++$resolved;
			} elseif ( $any ) {
				// Partial/mixed refund: record a review flag without changing status.
				if ( $this->flag_for_review( $row ) ) {
					++$flagged;
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'sceu-withdrawal-log',
					'synced'  => $resolved,
					'flagged' => $flagged,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Mark a pending request as having a refund detected that needs the merchant's
	 * review (a partial or mixed refund the sync won't auto-resolve). Stores a
	 * `refund_review` flag in the row's JSON payload, merged with what's there.
	 *
	 * @param array<string, mixed> $row Log row (ARRAY_A).
	 * @return bool True when the flag was newly set; false if already flagged or no id.
	 */
	private function flag_for_review( array $row ): bool {
		$id = (int) ( $row['id'] ?? 0 );
		if ( ! $id ) {
			return false;
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		// Idempotent: don't re-flag (and don't inflate the banner count) on repeat syncs.
		if ( ! empty( $payload['refund_review'] ) ) {
			return false;
		}

		$payload['refund_review'] = true;
		LogTable::update_payload( $id, $payload );
		return true;
	}

	/**
	 * Stream the log as a CSV download.
	 *
	 * @return void
	 */
	public function export_csv(): void {
		$this->guard( 'sceu_export_log' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=withdrawal-requests-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at', 'user_id', 'customer_id', 'customer_name', 'customer_email', 'ip_address', 'order_ids', 'withdrawing', 'reason', 'status' ) );

		// Stream in pages so the whole log is never held in memory at once.
		$per_page = 500;
		$offset   = 0;
		do {
			$rows = LogTable::get_rows( $per_page, $offset );
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
				$reason  = is_array( $payload ) ? (string) ( $payload['reason'] ?? '' ) : '';

				// Explicit per-order item detail (mirrors the admin table).
				$detail = '';
				if ( is_array( $payload ) && ! empty( $payload['orders'] ) && is_array( $payload['orders'] ) ) {
					$parts = array();
					foreach ( $payload['orders'] as $order ) {
						$ref     = (string) ( $order['number'] ?? $order['id'] ?? '' );
						$summary = Withdrawals::merchant_items_summary( $order );
						$parts[] = 'Order ' . $ref . ': ' . $summary;
					}
					$detail = implode( ' | ', $parts );
				}

				fputcsv(
					$out,
					array_map(
						array( $this, 'csv_safe' ),
						array(
							$row['id'] ?? '',
							$row['created_at'] ?? '',
							$row['user_id'] ?? '',
							$row['customer_id'] ?? '',
							$row['customer_name'] ?? '',
							$row['customer_email'] ?? '',
							$row['ip_address'] ?? '',
							$row['order_ids'] ?? '',
							$detail,
							$reason,
							$row['status'] ?? '',
						)
					)
				);
			}
			$offset += $per_page;
		} while ( count( $rows ) === $per_page );

		fclose( $out );
		exit;
	}

	/**
	 * Neutralise CSV formula injection: a cell starting with = + - @ (or a
	 * control char) is prefixed with a single quote so spreadsheet apps treat
	 * it as text, not a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public function csv_safe( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Rebuild the email context array from a stored log row, so a notification
	 * can be re-sent exactly as it first went out.
	 *
	 * The "Received at:" timestamp is derived from the row's original
	 * `created_at`, formatted in the site's date/time format — so a re-sent
	 * email remains a faithful receipt of when the request was made.
	 *
	 * @param array<string, mixed> $row Log row (ARRAY_A).
	 * @return array<string, mixed>
	 */
	private function ctx_from_row( array $row ): array {
		$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$merchant_to = sanitize_email( (string) ( $payload['merchant_to'] ?? '' ) );
		if ( '' === $merchant_to || ! is_email( $merchant_to ) ) {
			$merchant_to = Withdrawals::merchant_recipient();
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return array(
			'request_id'     => (string) ( $payload['request_id'] ?? '' ),
			// Original request time, not "now".
			'timestamp'      => mysql2date( $format, (string) ( $row['created_at'] ?? '' ) ),
			'customer_name'  => (string) ( $row['customer_name'] ?? '' ),
			'customer_email' => (string) ( $row['customer_email'] ?? '' ),
			'ip_address'     => (string) ( $row['ip_address'] ?? '' ),
			'reason'         => (string) ( $payload['reason'] ?? '' ),
			'orders'         => isset( $payload['orders'] ) && is_array( $payload['orders'] ) ? $payload['orders'] : array(),
			'merchant_email' => $merchant_to,
			'store_name'     => MerchantInfo::store_name(),
		);
	}

	/**
	 * Classify an order's refund state in SureCart: 'none', 'partial', or 'full'.
	 *
	 * The authoritative refund totals live on the **charge** — a refunded order
	 * keeps its `paid` status (SureCart has no `refunded`/`partially_refunded`
	 * order status; "Partially Refunded" is only a derived display label). The
	 * Order model does not carry the charge, but a checkout has exactly one
	 * successful charge, reachable by checkout id
	 * (`Charge::where( [ 'checkout_ids' => [ … ] ] )`). A cancelled/void order is
	 * treated as 'full'. The checkout's own `refunded_amount` is a last-resort
	 * fallback for when the charge can't be read.
	 *
	 * Fails closed: any unknown/error state resolves to 'none' so a SureCart change
	 * degrades to "do nothing" rather than a wrong status change.
	 *
	 * @param string $order_id Order id.
	 * @return string One of 'none', 'partial', 'full'.
	 */
	private function order_refund_state( string $order_id ): string {
		if ( '' === $order_id || ! class_exists( '\SureCart\Models\Order' ) ) {
			return 'none';
		}
		try {
			$order = \SureCart\Models\Order::with( array( 'checkout' ) )->find( $order_id );
		} catch ( \Throwable $e ) {
			return 'none';
		}
		if ( is_wp_error( $order ) || empty( $order ) || ! is_object( $order ) ) {
			return 'none';
		}

		// Cancelled/void orders are terminal — treat as a full withdrawal outcome.
		if ( Withdrawals::is_terminal_status( (string) ( $order->status ?? '' ) ) ) {
			return 'full';
		}

		// Resolve the checkout (object when expanded, else the id), then read the
		// authoritative refund totals off its charge.
		$checkout    = is_object( $order->checkout ?? null ) ? $order->checkout : null;
		$checkout_id = $checkout
			? (string) ( $checkout->id ?? '' )
			: ( is_string( $order->checkout ?? null ) ? (string) $order->checkout : (string) ( $order->checkout_id ?? '' ) );

		$state = $this->charge_refund_state( $checkout_id );
		if ( null !== $state ) {
			return $state;
		}

		// Last-resort fallback: the checkout itself sometimes carries a refunded
		// total. Call it 'full' when it covers the order total, else 'partial'.
		$refunded = $checkout && is_numeric( $checkout->refunded_amount ?? null ) ? (int) $checkout->refunded_amount : 0;
		if ( $refunded > 0 ) {
			$total = $checkout && is_numeric( $checkout->total_amount ?? null ) ? (int) $checkout->total_amount : 0;
			return ( $total > 0 && $refunded >= $total ) ? 'full' : 'partial';
		}

		return 'none';
	}

	/**
	 * Refund state derived from a checkout's charge(s): 'none', 'partial', 'full',
	 * or null when it cannot be determined (no checkout id, no charge, or an API
	 * error) so the caller can fall back. A checkout normally has a single charge;
	 * we aggregate defensively in case of more than one.
	 *
	 * @param string $checkout_id Checkout id.
	 * @return string|null
	 */
	private function charge_refund_state( string $checkout_id ): ?string {
		if ( '' === $checkout_id || ! class_exists( '\SureCart\Models\Charge' ) ) {
			return null;
		}
		try {
			$charges = \SureCart\Models\Charge::where( array( 'checkout_ids' => array( $checkout_id ) ) )->get();
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( is_wp_error( $charges ) ) {
			return null;
		}

		$list = is_object( $charges ) && isset( $charges->data )
			? $charges->data
			: ( is_array( $charges ) ? $charges : array() );
		if ( empty( $list ) ) {
			return null;
		}

		$amount   = 0;
		$refunded = 0;
		$fully    = false;
		foreach ( $list as $charge ) {
			if ( ! is_object( $charge ) ) {
				continue;
			}
			$amount   += is_numeric( $charge->amount ?? null ) ? (int) $charge->amount : 0;
			$refunded += is_numeric( $charge->refunded_amount ?? null ) ? (int) $charge->refunded_amount : 0;
			if ( ! empty( $charge->fully_refunded ) ) {
				$fully = true;
			}
		}

		if ( $refunded <= 0 && ! $fully ) {
			return 'none';
		}
		if ( $fully || ( $amount > 0 && $refunded >= $amount ) ) {
			return 'full';
		}
		return 'partial';
	}
}
