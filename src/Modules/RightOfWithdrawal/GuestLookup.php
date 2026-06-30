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
		if ( '' === $email || '' === $number
			|| ! class_exists( '\SureCart\Models\Order' )
			|| ! class_exists( '\SureCart\Models\Customer' ) ) {
			return null;
		}

		// Resolve the customer(s) by email, then fetch their orders by `customer_ids`
		// (SureCart's `query` is a fuzzy name/email search, not an order-number
		// match). The email→customer step also gates access to the order.
		$customers = self::customers_for_email( $email );
		if ( empty( $customers ) ) {
			return null;
		}
		$customer_ids = array_values( array_unique( array_column( $customers, 'id' ) ) );

		// Scope to the withdrawal window so the result set stays small and aligned
		// with eligibility (mirrors CustomerContext::recent_orders()).
		$conditions = array( 'customer_ids' => $customer_ids );
		$lookback   = (int) Settings::get( 'right_of_withdrawal', 'lookback_days', 14 );
		if ( $lookback > 0 ) {
			$conditions['created_at'] = array( 'gte' => time() - ( $lookback * DAY_IN_SECONDS ) );
		}

		try {
			$orders = \SureCart\Models\Order::where( $conditions )
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
				continue; // Email doesn't match this order (defence in depth).
			}
			if ( ! self::within_lookback( $order ) ) {
				continue; // Outside the withdrawal window — treat as not found.
			}

			$matched = self::annotate( $order );
			if ( null !== $matched ) {
				// Link the request to the customer that owns this order. An email can
				// map to both a live and a test customer, so pick the one whose mode
				// matches the order.
				$matched['customer_id'] = self::pick_customer_id( $customers, $order );
			}
			return $matched;
		}

		return null;
	}

	/**
	 * SureCart customers whose email exactly matches, each tagged with its mode.
	 * The customer `query` filter searches name + email fuzzily, so we re-check the
	 * email exactly here. A person can have more than one record (e.g. a live and a
	 * test customer).
	 *
	 * @param string $email Lower-cased, trimmed email.
	 * @return array<int, array{id: string, live: bool}>
	 */
	private static function customers_for_email( string $email ): array {
		try {
			$customers = \SureCart\Models\Customer::where( array( 'query' => $email ) )->get();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( is_wp_error( $customers ) || ! is_array( $customers ) ) {
			return array();
		}

		$out = array();
		foreach ( $customers as $customer ) {
			if ( is_object( $customer )
				&& ! empty( $customer->id )
				&& 0 === strcasecmp( trim( (string) ( $customer->email ?? '' ) ), $email ) ) {
				$out[] = array(
					'id'   => (string) $customer->id,
					'live' => ! empty( $customer->live_mode ),
				);
			}
		}

		return $out;
	}

	/**
	 * Pick the customer id that owns an order: the candidate whose mode matches the
	 * order's live/test mode, else the first. Empty when there are no candidates.
	 *
	 * @param array<int, array{id: string, live: bool}> $customers Candidates.
	 * @param object                                    $order     Matched order.
	 * @return string
	 */
	private static function pick_customer_id( array $customers, $order ): string {
		if ( empty( $customers ) ) {
			return '';
		}
		$order_live = ! empty( $order->live_mode );
		foreach ( $customers as $candidate ) {
			if ( $candidate['live'] === $order_live ) {
				return $candidate['id'];
			}
		}
		return $customers[0]['id'];
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
	 * The customer's name from the order's checkout (inherited name, else
	 * first + last). Empty when none.
	 *
	 * @param object $order Order model.
	 * @return string
	 */
	private static function order_customer_name( $order ): string {
		$checkout = $order->checkout ?? null;
		if ( ! is_object( $checkout ) ) {
			return '';
		}
		$name = trim( (string) ( $checkout->inherited_name ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $checkout->first_name ?? '' ) . ' ' . (string) ( $checkout->last_name ?? '' ) );
		}
		return $name;
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

		// Carry the customer name so the verified request shows it in the log (the
		// id is resolved in find_order, where the candidate customers are known).
		$norm['customer_name'] = self::order_customer_name( $order );

		$lines    = isset( $norm['line_items'] ) && is_array( $norm['line_items'] ) ? $norm['line_items'] : array();
		$excluded = Exclusions::excluded_set();

		// No line-item detail → offer the whole order. With no per-line product ids
		// we can't test it against the exclusion set; offered by default (same
		// filter as the logged-in path) so a store can withhold un-verifiable orders.
		if ( empty( $lines ) ) {
			/** This filter is documented in Withdrawals::withdrawable_orders(). */
			if ( ! apply_filters( 'sceu_offer_order_without_line_items', true, $norm, (bool) $excluded ) ) {
				return null;
			}
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
