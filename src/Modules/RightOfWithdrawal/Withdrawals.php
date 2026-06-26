<?php
/**
 * Withdrawal domain logic: what a customer can still withdraw, and what they
 * have already requested. Centralised so the block, the REST endpoint, and the
 * diagnostics all agree.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Merchant\MerchantInfo;
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
	 * Quantities the customer has already requested, summed across their
	 * non-rejected requests. Supports partial withdrawal: an item's remaining
	 * quantity is its purchased quantity minus what's already been requested.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array{items: array<string,int>, fullOrders: array<string,bool>}
	 *         items: line_item_id => total requested qty.
	 *         fullOrders: order_id => true for legacy/whole-order requests that
	 *         carry no per-item detail (the entire order is treated as consumed).
	 */
	public static function requested_quantities( int $user_id ): array {
		$items       = array();
		$full_orders = array();

		foreach ( LogTable::rows_for_user( $user_id, self::blocking_statuses() ) as $row ) {
			$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );

			if ( ! empty( $payload['items'] ) && is_array( $payload['items'] ) ) {
				foreach ( $payload['items'] as $it ) {
					$lid = (string) ( $it['line_item_id'] ?? '' );
					$qty = (int) ( $it['quantity'] ?? 0 );
					if ( '' !== $lid && $qty > 0 ) {
						$items[ $lid ] = ( $items[ $lid ] ?? 0 ) + $qty;
					}
				}
				continue;
			}

			// No per-item detail (legacy or whole-order request): consume the
			// whole order so it can't be requested again.
			$order_ids = json_decode( (string) ( $row['order_ids'] ?? '[]' ), true );
			if ( is_array( $order_ids ) ) {
				foreach ( $order_ids as $oid ) {
					$full_orders[ (string) $oid ] = true;
				}
			}
		}

		return array(
			'items'      => $items,
			'fullOrders' => $full_orders,
		);
	}

	/**
	 * Orders the customer can still withdraw from. Recent orders, minus refunded/
	 * cancelled ones, with each line item annotated with the quantity still
	 * available ("remaining" = purchased − already requested). Items with nothing
	 * left are dropped; an order with no remaining items is dropped entirely.
	 *
	 * Orders without line-item detail are offered as whole-order withdrawals
	 * (line_items empty, whole_order true) and excluded once requested.
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

		$requested = self::requested_quantities( get_current_user_id() );

		// Precomputed set of product ids excluded from withdrawal (e.g. perishable
		// or made-to-order goods). In-memory lookup — no SureCart API calls here.
		$excluded = Exclusions::excluded_set();

		$out = array();
		foreach ( $orders as $order ) {
			if ( ! empty( $order['refunded'] ) ) {
				continue;
			}
			if ( self::is_terminal_status( (string) ( $order['status'] ?? '' ) ) ) {
				continue;
			}
			if ( ! empty( $requested['fullOrders'][ (string) $order['id'] ] ) ) {
				continue; // Whole order already requested.
			}

			$lines = isset( $order['line_items'] ) && is_array( $order['line_items'] ) ? $order['line_items'] : array();

			// No line-item detail → offer the whole order (unless already taken).
			if ( empty( $lines ) ) {
				$order['line_items'] = array();
				$order['whole_order'] = true;
				$out[]                = $order;
				continue;
			}

			$remaining_lines = array();
			foreach ( $lines as $line ) {
				// Skip products the merchant has excluded from withdrawal. The
				// rest of the order stays withdrawable; an order whose items are
				// all excluded ends up with no remaining lines and is dropped.
				if ( $excluded && Exclusions::is_excluded( (string) ( $line['product_id'] ?? '' ), $excluded ) ) {
					continue;
				}
				$purchased = (int) ( $line['quantity'] ?? 1 );
				$already    = (int) ( $requested['items'][ (string) ( $line['id'] ?? '' ) ] ?? 0 );
				$remaining  = max( 0, $purchased - $already );
				if ( $remaining > 0 ) {
					$line['remaining']  = $remaining;
					$remaining_lines[]  = $line;
				}
			}

			if ( ! empty( $remaining_lines ) ) {
				// Keep the order's complete line-item set so the server can later
				// tell whether a selection covers the entire order (for the
				// Partial / Full-order label) — not just the items shown now.
				$order['all_line_items'] = $lines;
				$order['line_items']     = $remaining_lines;
				$order['whole_order']    = false;
				$out[]                   = $order;
			}
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

		/**
		 * Days to keep a finished (resolved/declined) request visible on the
		 * customer's dashboard. Pending requests always show until handled;
		 * finished ones age out so the list doesn't grow forever. This is
		 * display-only — it does not affect duplicate prevention.
		 *
		 * @param int $days Default 30.
		 */
		$history_days = (int) apply_filters( 'sceu_request_history_days', 30 );
		$cutoff       = time() - ( max( 0, $history_days ) * DAY_IN_SECONDS );

		$out = array();
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? self::STATUS_RECEIVED );

			// Finished requests drop off the dashboard after the grace window;
			// pending requests stay until the merchant resolves them.
			if ( self::STATUS_RECEIVED !== $status ) {
				$ts = strtotime( (string) ( $row['created_at'] ?? '' ) );
				if ( $ts && $ts < $cutoff ) {
					continue;
				}
			}

			$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
			$orders  = isset( $payload['orders'] ) && is_array( $payload['orders'] ) ? $payload['orders'] : array();

			$labels  = array();
			$details = array();
			foreach ( $orders as $o ) {
				$ref      = (string) ( $o['number'] ?? $o['id'] ?? '' );
				$labels[] = $ref;

				$items_summary = self::items_summary( $o );
				$details[]     = '' !== $items_summary
					/* translators: 1: order reference, 2: list of items + quantities. */
					? sprintf( __( 'Order %1$s — %2$s', 'surecart-eu-helper' ), $ref, $items_summary )
					/* translators: %s: order reference. */
					: sprintf( __( 'Order %s', 'surecart-eu-helper' ), $ref );
			}

			$out[] = array(
				'request_id'   => (string) ( $payload['request_id'] ?? $row['id'] ?? '' ),
				'status'       => $status,
				'status_label' => self::status_label( $status ),
				'created_at'   => (string) ( $row['created_at'] ?? '' ),
				'orders'       => $labels,
				'details'      => $details,
			);
		}

		return $out;
	}

	/**
	 * Build a "2× Item A, Item B" summary of the items withdrawn from one logged
	 * order (its line_items carry the requested quantity). Empty for whole-order.
	 *
	 * @param array<string, mixed> $order Logged order entry.
	 * @return string
	 */
	public static function items_summary( array $order ): string {
		$lines = isset( $order['line_items'] ) && is_array( $order['line_items'] ) ? $order['line_items'] : array();
		if ( empty( $lines ) ) {
			return '';
		}
		$parts = array();
		foreach ( $lines as $line ) {
			$name = (string) ( $line['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$qty     = (int) ( $line['quantity'] ?? 1 );
			$parts[] = ( $qty > 1 ) ? ( $qty . "\u{00D7} " . $name ) : $name;
		}
		return implode( ', ', array_slice( $parts, 0, 8 ) );
	}

	/**
	 * Merchant-facing summary of what to action for one logged order: each item
	 * as "2 of 3 × Product" (so partial vs full is explicit), or "Entire order"
	 * when no specific items were chosen.
	 *
	 * @param array<string, mixed> $order Logged order entry.
	 * @return string
	 */
	public static function merchant_items_summary( array $order ): string {
		$lines = isset( $order['line_items'] ) && is_array( $order['line_items'] ) ? $order['line_items'] : array();
		if ( empty( $lines ) ) {
			return __( 'Entire order', 'surecart-eu-helper' );
		}
		$parts = array();
		foreach ( $lines as $line ) {
			$name = (string) ( $line['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$qty       = (int) ( $line['quantity'] ?? 1 );
			$purchased = (int) ( $line['purchased'] ?? 0 );
			if ( $purchased > 0 ) {
				$parts[] = sprintf(
					/* translators: 1: quantity to withdraw, 2: quantity purchased, 3: product name. */
					__( '%1$d of %2$d × %3$s', 'surecart-eu-helper' ),
					$qty,
					$purchased,
					$name
				);
			} else {
				$parts[] = ( $qty > 1 ) ? ( $qty . "\u{00D7} " . $name ) : $name;
			}
		}
		return $parts ? implode( ', ', $parts ) : __( 'Entire order', 'surecart-eu-helper' );
	}

	/**
	 * Whether a logged order is a partial withdrawal (at least one item with a
	 * requested quantity below what was purchased, or a subset of items).
	 *
	 * @param array<string, mixed> $order Logged order entry.
	 * @return bool
	 */
	public static function is_partial( array $order ): bool {
		$lines = isset( $order['line_items'] ) && is_array( $order['line_items'] ) ? $order['line_items'] : array();
		if ( empty( $lines ) ) {
			return false; // Whole-order (no item detail) request.
		}
		// Authoritative when present: does the request cover the entire order?
		if ( array_key_exists( 'covers_entire_order', $order ) ) {
			return ! (bool) $order['covers_entire_order'];
		}
		// Legacy fallback (rows logged before this flag existed): can only detect
		// a reduced quantity, not an unselected line item.
		foreach ( $lines as $line ) {
			$qty       = (int) ( $line['quantity'] ?? 0 );
			$purchased = (int) ( $line['purchased'] ?? 0 );
			if ( $purchased > 0 && $qty < $purchased ) {
				return true;
			}
		}
		return false;
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

	/**
	 * Whether a SureCart order status means it's cancelled/refunded and so no
	 * longer withdrawable.
	 *
	 * @param string $status Order status.
	 * @return bool
	 */
	public static function is_terminal_status( string $status ): bool {
		return in_array( strtolower( trim( $status ) ), array( 'canceled', 'cancelled', 'refunded' ), true );
	}

	/**
	 * Generate a human-friendly, unique request reference (e.g. WD-20260626-1A2B3C).
	 *
	 * @return string
	 */
	public static function generate_request_id(): string {
		return 'WD-' . gmdate( 'Ymd' ) . '-' . strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 6 ) );
	}

	/**
	 * The merchant notification recipient: the configured address, else the
	 * resolved SureCart store default.
	 *
	 * @return string
	 */
	public static function merchant_recipient(): string {
		$configured = sanitize_email( (string) Settings::get( 'right_of_withdrawal', 'merchant_email', '' ) );
		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}
		return MerchantInfo::notification_email();
	}

	/**
	 * Whether the chosen items cover the order's ENTIRE original line-item set,
	 * each at its full purchased quantity — so a request that takes one whole
	 * line item out of a multi-item order is still labelled "Partial".
	 *
	 * @param array<string, mixed>                $order  Order carrying all_line_items.
	 * @param array<string, array<string, mixed>> $chosen Chosen items keyed by line id.
	 * @return bool
	 */
	public static function covers_entire_order( array $order, array $chosen ): bool {
		$all = isset( $order['all_line_items'] ) && is_array( $order['all_line_items'] )
			? $order['all_line_items']
			: array();
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
}
