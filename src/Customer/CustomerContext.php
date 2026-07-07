<?php
/**
 * Gateway to the current logged-in SureCart customer's data.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Customer;

use function SureCartEuHelper\eu_country_codes;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the current customer once per request and answers the questions the
 * Right of Withdrawal module needs (identity, country, VAT, recent orders). All
 * SureCart API access is funnelled through here and short-transient cached.
 *
 * Failure policy: any unknown/error state resolves to "no match"/empty so the
 * withdrawal notice is hidden rather than wrongly shown.
 */
class CustomerContext {

	/**
	 * Resolved customer id, or false when none. Null = not yet resolved.
	 *
	 * @var string|false|null
	 */
	private $customer_id = null;

	/**
	 * Cached customer payload (array of fields) or false when unavailable.
	 *
	 * @var array<string, mixed>|false|null
	 */
	private $customer = null;

	/**
	 * Per-request memo for recent_orders() keyed by day count.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private $orders_memo = array();

	/**
	 * Resolved mode cache.
	 *
	 * @var string|null
	 */
	private $mode = null;

	/**
	 * Cache TTL for customer/order lookups, in seconds.
	 *
	 * @var int
	 */
	private $ttl;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * Filter the cache lifetime (seconds) for SureCart customer/order lookups.
		 *
		 * @param int $ttl Default 60 seconds.
		 */
		$this->ttl = (int) apply_filters( 'sceu_cache_ttl', 60 );
	}

	/**
	 * Active SureCart mode ("live" or "test"). Defaults to live, matching
	 * SureCart's own default for the current customer.
	 *
	 * @return string
	 */
	public function mode(): string {
		if ( null !== $this->mode ) {
			return $this->mode;
		}

		$this->mode = 'live';

		if ( function_exists( 'SureCart' ) ) {
			try {
				$account = \SureCart::account();
				if ( is_object( $account ) && isset( $account->live_mode ) ) {
					$this->mode = $account->live_mode ? 'live' : 'test';
					return $this->mode;
				}
			} catch ( \Throwable $e ) {
				// Fall through to options.
			}
		}

		foreach ( array( 'surecart_mode', 'sc_mode' ) as $option ) {
			$value = get_option( $option );
			if ( 'test' === $value ) {
				$this->mode = 'test';
				return $this->mode;
			}
			if ( 'live' === $value ) {
				$this->mode = 'live';
				return $this->mode;
			}
		}

		return $this->mode;
	}

	/**
	 * Resolve the current customer id for the logged-in WordPress user.
	 *
	 * Resolution order (whichever the installed SureCart version supports):
	 *  1. \SureCart\Models\User::find( $user_id ) + a customer-id getter.
	 *  2. The `sc_customer_ids` user meta map, keyed by mode (prefer current
	 *     mode, then live, then test).
	 *
	 * @return string|false Customer id, or false when there is none.
	 */
	public function customer_id() {
		if ( null !== $this->customer_id ) {
			return $this->customer_id;
		}

		$this->customer_id = false;

		if ( ! is_user_logged_in() ) {
			return $this->customer_id;
		}

		$user_id = get_current_user_id();
		$mode    = $this->mode();

		// Path 1: the SureCart User model (sanctioned — wraps the meta).
		if ( class_exists( '\SureCart\Models\User' ) ) {
			try {
				$user = \SureCart\Models\User::find( $user_id );
			} catch ( \Throwable $e ) {
				$user = null;
			}
			if ( $user ) {
				foreach ( array( 'getCustomerId', 'customerId' ) as $method ) {
					if ( method_exists( $user, $method ) ) {
						$id = (string) $user->$method( $mode );
						if ( '' !== $id ) {
							$this->customer_id = $id;
							return $this->customer_id;
						}
					}
				}
				if ( isset( $user->customer_id ) ) {
					$id = (string) $user->customer_id;
					if ( '' !== $id ) {
						$this->customer_id = $id;
						return $this->customer_id;
					}
				}
			}
		}

		// Path 2: sc_customer_ids user meta, mode-keyed map.
		$raw = get_user_meta( $user_id, 'sc_customer_ids', true );
		$map = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( is_array( $map ) ) {
			foreach ( array( $mode, 'live', 'test' ) as $key ) {
				if ( ! empty( $map[ $key ] ) ) {
					$id = (string) $map[ $key ];
					if ( '' !== $id ) {
						$this->customer_id = $id;
						return $this->customer_id;
					}
				}
			}
		}

		return $this->customer_id;
	}

	/**
	 * Whether the current request has a resolvable SureCart customer.
	 *
	 * @return bool
	 */
	public function is_customer(): bool {
		return (bool) $this->customer_id();
	}

	/**
	 * Fetch (and cache) the customer with name, email, address + tax id.
	 *
	 * @return array<string, mixed>|false Normalised fields, or false on failure.
	 */
	private function customer() {
		if ( null !== $this->customer ) {
			return $this->customer;
		}

		$this->customer = false;

		$id = $this->customer_id();
		if ( ! $id ) {
			return $this->customer;
		}

		$transient_key = 'sceu_cust_' . md5( $this->mode() . '|' . $id );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			$this->customer = $cached;
			return $this->customer;
		}

		try {
			$customer = \SureCart\Models\Customer::with( array( 'billing_address', 'shipping_address', 'tax_identifier' ) )->find( $id );
		} catch ( \Throwable $e ) {
			return $this->customer; // false — fail safe.
		}

		if ( is_wp_error( $customer ) || empty( $customer ) ) {
			return $this->customer; // false — fail safe.
		}

		$data = array(
			'name'    => $this->extract_name( $customer ),
			'email'   => $this->extract_email( $customer ),
			'country' => $this->extract_country( $customer ),
			'has_vat' => $this->extract_has_vat( is_object( $customer ) ? ( $customer->tax_identifier ?? null ) : null ),
		);

		set_transient( $transient_key, $data, $this->ttl );
		$this->customer = $data;

		return $this->customer;
	}

	/**
	 * Pull the customer's display name, tolerating object/array shapes.
	 *
	 * @param mixed $customer Customer model.
	 * @return string
	 */
	private function extract_name( $customer ): string {
		if ( ! is_object( $customer ) ) {
			return '';
		}
		if ( ! empty( $customer->name ) ) {
			return (string) $customer->name;
		}
		$first = $customer->first_name ?? '';
		$last  = $customer->last_name ?? '';
		$full  = trim( $first . ' ' . $last );
		if ( '' !== $full ) {
			return $full;
		}
		// Last resort: name on the billing address.
		$billing = $customer->billing_address ?? null;
		if ( is_object( $billing ) && ! empty( $billing->name ) ) {
			return (string) $billing->name;
		}
		return '';
	}

	/**
	 * Pull the customer's email, tolerating object shapes.
	 *
	 * @param mixed $customer Customer model.
	 * @return string
	 */
	private function extract_email( $customer ): string {
		if ( is_object( $customer ) && ! empty( $customer->email ) ) {
			return (string) $customer->email;
		}
		return '';
	}

	/**
	 * Pull a country code from the customer's billing (then shipping) address,
	 * tolerating object or array shapes.
	 *
	 * @param mixed $customer Customer model.
	 * @return string Alpha-2 code (uppercase) or empty string.
	 */
	private function extract_country( $customer ): string {
		if ( ! is_object( $customer ) ) {
			return '';
		}
		foreach ( array( 'billing_address', 'shipping_address' ) as $prop ) {
			$address = $customer->$prop ?? null;
			if ( is_object( $address ) && ! empty( $address->country ) ) {
				return strtoupper( (string) $address->country );
			}
			if ( is_array( $address ) && ! empty( $address['country'] ) ) {
				return strtoupper( (string) $address['country'] );
			}
		}
		return '';
	}

	/**
	 * Determine whether a tax identifier represents a business.
	 *
	 * An invalid EU VAT counts as consumer — SureCart taxes such orders as
	 * consumer orders, and we mirror that call. `valid_eu_vat` only applies to
	 * `number_type` `eu_vat`; other types (gb_vat, au_abn, …) and identifiers
	 * without the flag count as business on presence of a number alone.
	 *
	 * @param mixed $tax Expanded tax_identifier object/array, an id string, or null.
	 * @return bool
	 */
	private function extract_has_vat( $tax ): bool {
		if ( empty( $tax ) ) {
			return false;
		}
		if ( is_object( $tax ) ) {
			$number = $tax->number ?? ( $tax->value ?? null );
			if ( empty( $number ) ) {
				return false;
			}
			if ( 'eu_vat' === ( $tax->number_type ?? '' ) && isset( $tax->valid_eu_vat ) ) {
				return (bool) $tax->valid_eu_vat;
			}
			return true;
		}
		if ( is_array( $tax ) ) {
			$number = $tax['number'] ?? ( $tax['value'] ?? null );
			if ( empty( $number ) ) {
				return false;
			}
			if ( 'eu_vat' === ( $tax['number_type'] ?? '' ) && isset( $tax['valid_eu_vat'] ) ) {
				return (bool) $tax['valid_eu_vat'];
			}
			return true;
		}
		// A bare id string still means a tax identifier exists.
		return is_string( $tax ) && '' !== $tax;
	}

	/**
	 * Customer display name (may be empty).
	 *
	 * @return string
	 */
	public function customer_name(): string {
		$customer = $this->customer();
		return $customer ? (string) $customer['name'] : '';
	}

	/**
	 * Customer email (may be empty).
	 *
	 * @return string
	 */
	public function customer_email(): string {
		$customer = $this->customer();
		return $customer ? (string) $customer['email'] : '';
	}

	/**
	 * Customer billing country code (alpha-2, uppercase) or empty string.
	 *
	 * @return string
	 */
	public function country_code(): string {
		$customer = $this->customer();
		return $customer ? (string) $customer['country'] : '';
	}

	/**
	 * Whether the customer has a VAT / tax identifier (business indicator).
	 *
	 * @return bool
	 */
	public function has_vat(): bool {
		$customer = $this->customer();
		return $customer ? (bool) $customer['has_vat'] : false;
	}

	/**
	 * Whether any of the customer's orders within the look-back window carries a
	 * valid VAT on its checkout. This is the verified, per-purchase VAT,
	 * which the customer-level tax_identifier does not reliably mirror — so it is
	 * the authoritative source for the "is a business?" question. Reuses the
	 * already-fetched, transient-cached recent orders (no extra API calls).
	 *
	 * @param int $days Look-back window in days.
	 * @return bool
	 */
	public function has_vat_on_recent_orders( int $days ): bool {
		foreach ( $this->recent_orders( $days ) as $order ) {
			if ( ! empty( $order['has_vat'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the customer has any billing/shipping country on file.
	 *
	 * @return bool
	 */
	public function has_country(): bool {
		return '' !== $this->country_code();
	}

	/**
	 * Whether the customer's country is in the EU list.
	 *
	 * @return bool
	 */
	public function is_eu(): bool {
		$country = $this->country_code();
		if ( '' === $country ) {
			return false;
		}
		return in_array( $country, eu_country_codes(), true );
	}

	/**
	 * Recent orders for the customer, normalised for the withdrawal form's
	 * multi-select. Newest first. Transient-cached.
	 *
	 * @param int $days Look-back window in days.
	 * @return array<int, array<string, mixed>> Each: id, number, created_at, status, total_display, summary.
	 */
	public function recent_orders( int $days ): array {
		$days = max( 1, $days );

		if ( isset( $this->orders_memo[ $days ] ) ) {
			return $this->orders_memo[ $days ];
		}

		$this->orders_memo[ $days ] = array();

		$id = $this->customer_id();
		if ( ! $id ) {
			return $this->orders_memo[ $days ];
		}

		$transient_key = 'sceu_orders_' . md5( $this->mode() . '|' . $id . '|' . $days );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			$this->orders_memo[ $days ] = $cached;
			return $cached;
		}

		$since  = time() - ( $days * DAY_IN_SECONDS );
		$orders = $this->query_orders_since( $id, $since );

		set_transient( $transient_key, $orders, $this->ttl );
		$this->orders_memo[ $days ] = $orders;

		return $orders;
	}

	/**
	 * Query orders created on/after $since and normalise them.
	 *
	 * Uses the documented filter keys: customer_ids[] and created_at[gte]
	 * (Unix seconds). Fails safe to an empty array.
	 *
	 * @param string $customer_id Customer id.
	 * @param int    $since       Unix timestamp lower bound (inclusive).
	 * @return array<int, array<string, mixed>>
	 */
	private function query_orders_since( string $customer_id, int $since ): array {
		// Try richer expansions first, but never let an unsupported `expand`
		// silently disable the feature: on error, retry with less, then none.
		// `checkout.tax_identifier` rides along on every checkout-bearing variant:
		// the verified per-purchase VAT lives there (the customer-level tax id is
		// not reliably kept in sync), and we need it to answer has_vat() correctly
		// for the `non_vat` audience rule — at no extra API cost.
		$expansions = array(
			// Richest: reach the product name via line_item.price.product.
			array( 'checkout', 'checkout.tax_identifier', 'checkout.line_items', 'line_item.price', 'price.product' ),
			array( 'checkout', 'checkout.tax_identifier', 'checkout.line_items', 'line_item.price' ),
			array( 'checkout', 'checkout.tax_identifier', 'checkout.line_items' ),
			array( 'checkout', 'checkout.tax_identifier' ),
			array(),
		);

		$orders = null;
		foreach ( $expansions as $with ) {
			$orders = $this->run_orders_query( $customer_id, $since, $with );
			if ( null !== $orders ) {
				break; // A successful response (possibly empty).
			}
		}

		if ( null === $orders || empty( $orders ) ) {
			return array();
		}

		$items = $this->collection_to_array( $orders );

		// Safety net: if the structured unwrap finds nothing but we clearly have
		// a response, fall back to the original tolerant cast so a shape we did
		// not anticipate can never silently hide the notice.
		if ( empty( $items ) ) {
			$fallback = is_object( $orders ) && isset( $orders->data ) ? $orders->data : $orders;
			if ( is_object( $fallback ) ) {
				$fallback = (array) $fallback;
			}
			$items = is_array( $fallback ) ? $fallback : array();
		}

		if ( empty( $items ) ) {
			return array();
		}

		$normalised = array();
		foreach ( $items as $order ) {
			$row = $this->normalise_order( $order );
			if ( $row ) {
				$normalised[] = $row;
			}
		}

		// Newest first.
		usort(
			$normalised,
			static function ( $a, $b ) {
				return (int) $b['created_at'] <=> (int) $a['created_at'];
			}
		);

		return $normalised;
	}

	/**
	 * Run a single orders query with the given expansions.
	 *
	 * @param string   $customer_id Customer id.
	 * @param int      $since       Unix lower bound.
	 * @param string[] $with        Expansion list (may be empty).
	 * @return mixed|null Response on success (possibly empty), null on error.
	 */
	private function run_orders_query( string $customer_id, int $since, array $with ) {
		try {
			$query = \SureCart\Models\Order::where(
				array(
					'customer_ids' => array( $customer_id ),
					'created_at'   => array( 'gte' => $since ),
				)
			);
			if ( ! empty( $with ) ) {
				$query = $query->with( $with );
			}
			// get() returns the most recent orders (SureCart caps this page at
			// ~10, which is ample for a 14–17 day withdrawal window). This is the
			// proven-working call; paginate() chained after with() proved
			// unreliable, so we stay with get().
			$orders = $query->get();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $orders ) ) {
			return null;
		}

		return $orders;
	}

	/**
	 * Public entry point to normalise an arbitrary SureCart order object into the
	 * flat shape the withdrawal form uses (id, number, line_items with product_id,
	 * etc.). Used by the guest (public-form) lookup, which fetches an order by
	 * number rather than through the logged-in customer. The normalisation helpers
	 * are stateless with respect to the customer, so this is safe to call on any
	 * order.
	 *
	 * @param mixed $order Order model object.
	 * @return array<string, mixed>|null
	 */
	public function normalize_order_object( $order ): ?array {
		return $this->normalise_order( $order );
	}

	/**
	 * Normalise a single order object into a flat array for the form.
	 *
	 * @param mixed $order Order model.
	 * @return array<string, mixed>|null Null when the order has no usable id.
	 */
	private function normalise_order( $order ): ?array {
		if ( ! is_object( $order ) ) {
			return null;
		}

		$id = $order->id ?? '';
		if ( '' === $id ) {
			return null;
		}

		$checkout = $order->checkout ?? null;

		$created = $order->created_at ?? ( is_object( $checkout ) ? ( $checkout->created_at ?? 0 ) : 0 );

		// Amount + currency, preferring the order then its checkout.
		$amount   = $order->total_amount ?? ( is_object( $checkout ) ? ( $checkout->total_amount ?? null ) : null );
		$currency = $order->currency ?? ( is_object( $checkout ) ? ( $checkout->currency ?? '' ) : '' );

		// Best-effort refund signal (SureCart doesn't always surface this on the
		// order; treated as a hint, the request log is the source of truth).
		$refunded_amount = is_object( $checkout ) ? ( $checkout->refunded_amount ?? 0 ) : 0;

		return array(
			'id'            => (string) $id,
			'number'        => $this->extract_order_number( $order ),
			'created_at'    => (int) $created,
			'status'        => (string) ( $order->status ?? '' ),
			'refunded'      => is_numeric( $refunded_amount ) && (int) $refunded_amount > 0,
			'has_vat'       => is_object( $checkout ) ? $this->extract_has_vat( $checkout->tax_identifier ?? null ) : false,
			'total_display' => $this->format_money( $amount, (string) $currency ),
			'summary'       => $this->extract_line_summary( $order ),
			'line_items'    => $this->normalise_line_items( $order, (string) $currency ),
		);
	}

	/**
	 * Normalise an order's line items for partial withdrawal: each carries an id,
	 * display name, purchased quantity, and a per-unit price string. Returns an
	 * empty array when no line-item detail is available (the order is then
	 * offered as a whole-order withdrawal).
	 *
	 * @param mixed  $order    Order model.
	 * @param string $currency Order currency code.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise_line_items( $order, string $currency ): array {
		$items = $this->line_items_for( $order );
		if ( empty( $items ) ) {
			return array();
		}

		$out = array();
		foreach ( $items as $line ) {
			$id = (string) ( $this->prop( $line, 'id' ) ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$name = $this->line_item_name( $line );
			if ( '' === $name ) {
				$name = __( 'Item', 'surecart-eu-helper' );
			}

			$qty = (int) ( $this->prop( $line, 'quantity' ) ?? 1 );
			if ( $qty < 1 ) {
				$qty = 1;
			}

			// Per-unit amount: the price amount, else the line subtotal/total ÷ qty.
			$price = $this->prop( $line, 'price' );
			$unit  = $this->prop( $price, 'amount' );
			if ( ! is_numeric( $unit ) ) {
				$line_total = $this->prop( $line, 'subtotal_amount' );
				if ( ! is_numeric( $line_total ) ) {
					$line_total = $this->prop( $line, 'total_amount' );
				}
				$unit = ( is_numeric( $line_total ) && $qty > 0 ) ? ( (float) $line_total / $qty ) : null;
			}

			$product = $this->prop( $price, 'product' );
			$image   = $this->extract_product_image( $product );

			// Product id rides along on the expand we already do (price.product);
			// captured here so product-level exclusions cost no extra API calls.
			$product_id = (string) ( $this->prop( $product, 'id' ) ?? '' );

			$out[] = array(
				'id'           => $id,
				'product_id'   => $product_id,
				'name'         => $name,
				'quantity'     => $qty,
				'unit_display' => ( null !== $unit ) ? $this->format_money( $unit, $currency ) : '',
				'image'        => $image['src'],
				'image_alt'    => '' !== $image['alt'] ? $image['alt'] : $name,
			);
		}

		return $out;
	}

	/**
	 * Pull a small product thumbnail for the line-item list, tolerating shapes.
	 * SureCart's product exposes `line_item_image` (a thumbnail built for exactly
	 * this), then `preview_image`, then `image_url`. Returns src + alt (empty when
	 * the product has no image).
	 *
	 * @param mixed $product Product model/array.
	 * @return array{src: string, alt: string}
	 */
	private function extract_product_image( $product ): array {
		$empty = array(
			'src' => '',
			'alt' => '',
		);
		if ( empty( $product ) ) {
			return $empty;
		}

		foreach ( array( 'line_item_image', 'preview_image' ) as $key ) {
			$img = $this->prop( $product, $key );
			$src = $this->prop( $img, 'src' );
			if ( is_string( $src ) && '' !== $src ) {
				$alt = $this->prop( $img, 'alt' );
				return array(
					'src' => $src,
					'alt' => is_string( $alt ) ? $alt : '',
				);
			}
		}

		$url = $this->prop( $product, 'image_url' );
		if ( is_string( $url ) && '' !== $url ) {
			return array(
				'src' => $url,
				'alt' => '',
			);
		}

		return $empty;
	}

	/**
	 * A human order reference: the order number when present, else a short id.
	 *
	 * @param object $order Order.
	 * @return string
	 */
	private function extract_order_number( $order ): string {
		if ( isset( $order->number ) && '' !== (string) $order->number ) {
			return (string) $order->number;
		}
		$id = (string) ( $order->id ?? '' );
		return $id ? substr( $id, -8 ) : '';
	}

	/**
	 * Build a readable "2× Product A, Product B" summary of the items in an
	 * order, so the buyer recognises it (the order number alone is meaningless).
	 * Items may live on the order or its checkout; product names are resolved
	 * through whichever expansion succeeded. Visual truncation is left to CSS.
	 *
	 * @param mixed $order Order model (possibly with checkout/line_items).
	 * @return string
	 */
	private function extract_line_summary( $order ): string {
		$items = $this->line_items_for( $order );
		if ( empty( $items ) ) {
			return '';
		}

		$parts = array();
		foreach ( $items as $line ) {
			$name = $this->line_item_name( $line );
			if ( '' === $name ) {
				continue;
			}
			$qty     = (int) ( $this->prop( $line, 'quantity' ) ?? 1 );
			$parts[] = ( $qty > 1 ) ? ( $qty . "\u{00D7} " . $name ) : $name;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		// Cap the list so the string can't grow unbounded; CSS ellipsis handles
		// the visual one-line truncation responsively.
		$capped = array_slice( $parts, 0, 6 );
		$more   = count( $parts ) - count( $capped );
		$text   = implode( ', ', $capped );
		if ( $more > 0 ) {
			$text .= ', …';
		}
		return $text;
	}

	/**
	 * Resolve the line-item collection from an order or its checkout.
	 *
	 * @param mixed $order Order model.
	 * @return array<int, mixed>
	 */
	private function line_items_for( $order ): array {
		if ( ! is_object( $order ) ) {
			return array();
		}

		$line_items = $order->line_items ?? null;
		if ( empty( $line_items ) && isset( $order->checkout ) && is_object( $order->checkout ) ) {
			$line_items = $order->checkout->line_items ?? null;
		}

		return $this->collection_to_array( $line_items );
	}

	/**
	 * Normalise any SureCart list shape into a plain array of items.
	 *
	 * Handles: a plain array; a `{ data: [...] }` wrapper (object or array);
	 * a Traversable/iterable collection; or a single object. Casting a
	 * collection object with `(array)` would expose mangled private keys, so
	 * we explicitly unwrap instead.
	 *
	 * @param mixed $list Candidate list/collection.
	 * @return array<int, mixed>
	 */
	private function collection_to_array( $list ): array {
		if ( is_array( $list ) ) {
			// Could be a list, or an associative array with a `data` key.
			if ( isset( $list['data'] ) && is_array( $list['data'] ) ) {
				return array_values( $list['data'] );
			}
			return array_values( $list );
		}

		if ( is_object( $list ) ) {
			if ( isset( $list->data ) ) {
				return $this->collection_to_array( $list->data );
			}
			if ( method_exists( $list, 'getData' ) ) {
				return $this->collection_to_array( $list->getData() );
			}
			if ( method_exists( $list, 'toArray' ) ) {
				return $this->collection_to_array( $list->toArray() );
			}
			if ( $list instanceof \Traversable ) {
				return array_values( iterator_to_array( $list ) );
			}
		}

		return array();
	}

	/**
	 * Best available display name for a single line item.
	 *
	 * SureCart line items carry no name of their own; the human name lives on
	 * the line item's price → product. We resolve product name, then price name,
	 * then any description/name on the line. Tolerant of object or array shapes.
	 *
	 * @param mixed $line Line item (object or array).
	 * @return string
	 */
	private function line_item_name( $line ): string {
		$price   = $this->prop( $line, 'price' );
		$product = $this->prop( $price, 'product' );

		$product_name = $this->prop( $product, 'name' );
		if ( ! empty( $product_name ) && is_string( $product_name ) ) {
			return $product_name;
		}

		$price_name = $this->prop( $price, 'name' );
		if ( ! empty( $price_name ) && is_string( $price_name ) ) {
			return $price_name;
		}

		foreach ( array( 'description', 'name' ) as $key ) {
			$value = $this->prop( $line, $key );
			if ( ! empty( $value ) && is_string( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Read a property from either an object or an associative array.
	 *
	 * @param mixed  $data Object or array.
	 * @param string $key  Property/key name.
	 * @return mixed|null
	 */
	private function prop( $data, string $key ) {
		if ( is_object( $data ) ) {
			return $data->$key ?? null;
		}
		if ( is_array( $data ) ) {
			return $data[ $key ] ?? null;
		}
		return null;
	}

	/**
	 * Format an integer minor-unit amount with its currency.
	 *
	 * @param mixed  $amount   Amount in minor units (cents), or null.
	 * @param string $currency ISO currency code.
	 * @return string Empty string when no usable amount.
	 */
	private function format_money( $amount, string $currency ): string {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return '';
		}
		$major = number_format_i18n( ( (float) $amount ) / 100, 2 );
		$code  = strtoupper( $currency );
		return $code ? trim( $major . ' ' . $code ) : $major;
	}

	/**
	 * Snapshot of everything the module relies on, for the diagnostic tool.
	 *
	 * @return array<string, mixed>
	 */
	public function debug(): array {
		return array(
			'logged_in'          => is_user_logged_in(),
			'user_id'            => get_current_user_id(),
			'mode'               => $this->mode(),
			'customer_id'        => $this->customer_id(),
			'name'               => $this->customer_name(),
			'email'              => $this->customer_email(),
			'country_code'       => $this->country_code(),
			'is_eu'              => $this->is_eu(),
			'has_vat'            => $this->has_vat(),
			'has_vat_orders_14d' => $this->has_vat_on_recent_orders( 14 ),
			'orders_14d'         => count( $this->recent_orders( 14 ) ),
		);
	}
}
