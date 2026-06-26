<?php
/**
 * Guest (public-form) order lookup.
 *
 * Finds an order by its display number and verifies the supplied email matches
 * the order, so a logged-out visitor can request a withdrawal. Runs server-side
 * with the site's SureCart token — the visitor never sees credentials, and an
 * order's contents are only returned when the email matches.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Customer\CustomerContext;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up a single order by number + email for the public withdrawal form.
 */
class GuestLookup {

	/**
	 * Find a withdrawable order matching the email + order number.
	 *
	 * Returns the normalised order (ready for the item picker) on an exact
	 * number match whose order email matches, or null otherwise. Null covers
	 * "no such order", "email doesn't match", "already fully refunded", and
	 * "every item is excluded" alike — the caller must not reveal which, so the
	 * form can't be used to probe whether an order or email exists.
	 *
	 * @param string $email  Submitted email.
	 * @param string $number Submitted order number.
	 * @return array<string, mixed>|null Normalised order, or null.
	 */
	public static function find_order( string $email, string $number ): ?array {
		$email  = strtolower( trim( $email ) );
		$number = trim( $number );
		if ( '' === $email || '' === $number || ! class_exists( '\SureCart\Models\Order' ) ) {
			return null;
		}

		try {
			// `query` searches order numbers (fuzzy), so we exact-match below.
			$orders = \SureCart\Models\Order::where( array( 'query' => $number ) )
				->with( array( 'checkout', 'checkout.line_items', 'line_item.price', 'price.product' ) )
				->get();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $orders ) || ! is_array( $orders ) ) {
			return null;
		}

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) ) {
				continue;
			}
			if ( 0 !== strcasecmp( trim( (string) ( $order->number ?? '' ) ), $number ) ) {
				continue; // Not the exact order number.
			}
			if ( 0 !== strcasecmp( self::order_email( $order ), $email ) ) {
				continue; // Email doesn't match this order.
			}
			if ( ! self::within_lookback( $order ) ) {
				continue; // Outside the withdrawal window — treat as not found.
			}
			return self::annotate( $order );
		}

		return null;
	}

	/**
	 * Whether the order falls inside the configured look-back window, matching
	 * the logged-in eligibility rule so a guest can't request withdrawal on an
	 * arbitrarily old order. Unknown timestamps are allowed (fail open to the
	 * email + exclusion + refund checks that still apply).
	 *
	 * @param object $order Order model.
	 * @return bool
	 */
	private static function within_lookback( $order ): bool {
		$created = (int) ( $order->created_at ?? 0 );
		if ( $created <= 0 ) {
			return true;
		}
		$lookback = (int) Settings::get( 'right_of_withdrawal', 'lookback_days', 14 );
		if ( $lookback <= 0 ) {
			return true;
		}
		return $created >= ( time() - ( $lookback * DAY_IN_SECONDS ) );
	}

	/**
	 * The email associated with an order (checkout inherited/typed email).
	 *
	 * @param object $order Order model.
	 * @return string Lower-cased email, or ''.
	 */
	private static function order_email( $order ): string {
		$checkout = $order->checkout ?? null;
		if ( is_object( $checkout ) ) {
			foreach ( array( 'inherited_email', 'email' ) as $key ) {
				$value = $checkout->{$key} ?? '';
				if ( is_string( $value ) && '' !== $value ) {
					return strtolower( trim( $value ) );
				}
			}
		}
		return '';
	}

	/**
	 * Normalise + annotate a matched order for the withdrawal picker: drop
	 * excluded products, set each remaining quantity, and mark whole-order when
	 * there's no line-item detail. Returns null when nothing is withdrawable
	 * (refunded/cancelled, or every item excluded).
	 *
	 * @param object $order Order model.
	 * @return array<string, mixed>|null
	 */
	private static function annotate( $order ): ?array {
		$norm = ( new CustomerContext() )->normalize_order_object( $order );
		if ( empty( $norm ) || empty( $norm['id'] ) ) {
			return null;
		}

		// Mirror the logged-in flow: refunded/cancelled orders aren't withdrawable.
		if ( ! empty( $norm['refunded'] ) ) {
			return null;
		}
		if ( Withdrawals::is_terminal_status( (string) ( $norm['status'] ?? '' ) ) ) {
			return null;
		}

		$lines    = isset( $norm['line_items'] ) && is_array( $norm['line_items'] ) ? $norm['line_items'] : array();
		$excluded = Exclusions::excluded_set();

		// No line-item detail → offer the whole order.
		if ( empty( $lines ) ) {
			$norm['line_items']  = array();
			$norm['whole_order'] = true;
			return $norm;
		}

		$remaining_lines = array();
		foreach ( $lines as $line ) {
			if ( $excluded && Exclusions::is_excluded( (string) ( $line['product_id'] ?? '' ), $excluded ) ) {
				continue;
			}
			$purchased         = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$line['remaining'] = $purchased;
			$remaining_lines[] = $line;
		}

		if ( empty( $remaining_lines ) ) {
			return null; // Every item excluded — treat as not found (free-text fallback).
		}

		$norm['all_line_items'] = $lines;
		$norm['line_items']     = $remaining_lines;
		$norm['whole_order']    = false;
		return $norm;
	}
}
