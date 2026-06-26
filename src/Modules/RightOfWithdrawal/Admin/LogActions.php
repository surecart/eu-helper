<?php
/**
 * Admin-post controllers for the withdrawal request log.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Admin;

use SureCartEuHelper\Settings;
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
	 * For each pending request, if every order it covers now looks
	 * refunded/cancelled in SureCart, mark the request resolved. SureCart does
	 * not always surface refunds on the order, so this is a convenience, not a
	 * guarantee — the merchant can always set status manually.
	 *
	 * @return void
	 */
	public function sync_log(): void {
		$this->guard( 'sceu_sync_log' );

		$synced = 0;
		foreach ( LogTable::rows_by_status( Withdrawals::STATUS_RECEIVED ) as $row ) {
			$ids = json_decode( (string) ( $row['order_ids'] ?? '[]' ), true );
			if ( ! is_array( $ids ) || empty( $ids ) ) {
				continue;
			}
			$all_done = true;
			foreach ( $ids as $order_id ) {
				if ( ! $this->order_looks_refunded( (string) $order_id ) ) {
					$all_done = false;
					break;
				}
			}
			if ( $all_done ) {
				LogTable::update_status( (int) $row['id'], Withdrawals::STATUS_RESOLVED );
				++$synced;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sceu-withdrawal-log&synced=' . $synced ) );
		exit;
	}

	/**
	 * Stream the log as a CSV download.
	 *
	 * @return void
	 */
	public function export_csv(): void {
		$this->guard( 'sceu_export_log' );

		$rows = LogTable::all_rows();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=withdrawal-requests-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at', 'user_id', 'customer_id', 'customer_name', 'customer_email', 'ip_address', 'order_ids', 'withdrawing', 'reason', 'status' ) );
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
			$merchant_to = $this->effective_merchant_email();
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
	 * Effective merchant notification email: the configured value, else the
	 * resolved SureCart store default. Mirrors the REST controller's resolver,
	 * used only as a fallback for old rows that predate storing the recipient.
	 *
	 * @return string
	 */
	private function effective_merchant_email(): string {
		$configured = sanitize_email( (string) Settings::get( 'right_of_withdrawal', 'merchant_email', '' ) );
		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}
		return MerchantInfo::notification_email();
	}

	/**
	 * Best-effort: does this order appear refunded/cancelled in SureCart?
	 *
	 * @param string $order_id Order id.
	 * @return bool
	 */
	private function order_looks_refunded( string $order_id ): bool {
		if ( '' === $order_id || ! class_exists( '\SureCart\Models\Order' ) ) {
			return false;
		}
		try {
			$order = \SureCart\Models\Order::with( array( 'checkout' ) )->find( $order_id );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( is_wp_error( $order ) || empty( $order ) || ! is_object( $order ) ) {
			return false;
		}

		$status = strtolower( (string) ( $order->status ?? '' ) );
		if ( in_array( $status, array( 'canceled', 'cancelled', 'refunded' ), true ) ) {
			return true;
		}

		$checkout = $order->checkout ?? null;
		$refunded = is_object( $checkout ) ? ( $checkout->refunded_amount ?? 0 ) : 0;
		return is_numeric( $refunded ) && (int) $refunded > 0;
	}
}
