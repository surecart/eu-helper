<?php
/**
 * Withdrawal domain logic: what a customer can still withdraw, and what they
 * have already requested. Centralised so the block, the REST endpoint, and the
 * diagnostics all agree.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

use SureCartEuHelper\Customer\CustomerContext;
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable;

defined( 'ABSPATH' ) || exit;

/**
 * Request lifecycle statuses:
 *  - received: submitted, awaiting the merchant.
 *  - resolved: the merchant has handled it (refunded/cancelled). Done.
 *  - rejected: the merchant declined it; the order may be requested again.
 */
class Withdrawals {

	const STATUS_RECEIVED = 'received';
	const STATUS_RESOLVED = 'resolved';
	const STATUS_REJECTED = 'rejected';

	/**
	 * Statuses that should block an order from being requested again.
	 *
	 * @return string[]
	 */
	public static function blocking_statuses(): array {
		return array( self::STATUS_RECEIVED, self::STATUS_RESOLVED );
	}

	/**
	 * Orders the customer can still withdraw from: recent orders, minus any
	 * already requested (per the log), minus any that look refunded/cancelled.
	 *
	 * @param CustomerContext $customer Customer gateway.
	 * @param int             $lookback Look-back window in days.
	 * @return array<int, array<string, mixed>>
	 */
	public static function withdrawable_orders( CustomerContext $customer, int $lookback ): array {
		$orders = $customer->recent_orders( $lookback );
		if ( empty( $orders ) ) {
			return array();
		}

		$already = array_flip( LogTable::requested_order_ids( get_current_user_id(), self::blocking_statuses() ) );

		$out = array();
		foreach ( $orders as $order ) {
			if ( isset( $already[ (string) $order['id'] ] ) ) {
				continue; // Already has an open/resolved request.
			}
			if ( ! empty( $order['refunded'] ) ) {
				continue; // Already refunded.
			}
			if ( in_array( strtolower( (string) ( $order['status'] ?? '' ) ), array( 'canceled', 'cancelled', 'refunded' ), true ) ) {
				continue;
			}
			$out[] = $order;
		}

		return $out;
	}

	/**
	 * The customer's requests to surface on the dashboard (pending, resolved, and
	 * declined), normalised for display. Declined requests are included so the
	 * customer can see the outcome of a request they submitted, rather than the
	 * dashboard silently reverting to its initial state.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function requests_for_display( int $user_id ): array {
		$rows = LogTable::rows_for_user( $user_id, array( self::STATUS_RECEIVED, self::STATUS_RESOLVED, self::STATUS_REJECTED ) );

		$out = array();
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
			$orders  = isset( $payload['orders'] ) && is_array( $payload['orders'] ) ? $payload['orders'] : array();

			$labels = array();
			foreach ( $orders as $o ) {
				$ref      = (string) ( $o['number'] ?? $o['id'] ?? '' );
				$labels[] = $ref;
			}

			$status = (string) ( $row['status'] ?? self::STATUS_RECEIVED );

			$out[] = array(
				'request_id'   => (string) ( $payload['request_id'] ?? $row['id'] ?? '' ),
				'status'       => $status,
				'status_label' => self::status_label( $status ),
				'created_at'   => (string) ( $row['created_at'] ?? '' ),
				'orders'       => $labels,
			);
		}

		return $out;
	}

	/**
	 * Whether the customer has any pending (received) request.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function has_pending( int $user_id ): bool {
		return ! empty( LogTable::rows_for_user( $user_id, array( self::STATUS_RECEIVED ) ) );
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case self::STATUS_RESOLVED:
				return __( 'Completed', 'surecart-eu-helper' );
			case self::STATUS_REJECTED:
				return __( 'Declined', 'surecart-eu-helper' );
			case self::STATUS_RECEIVED:
			default:
				return __( 'Pending review', 'surecart-eu-helper' );
		}
	}
}
