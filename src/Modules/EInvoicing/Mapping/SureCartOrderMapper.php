<?php
/**
 * Maps a SureCart order into a normalized invoice Document (with snapshotting).
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Mapping;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\EInvoicing\Domain\Document;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;
use SureCartEuHelper\Modules\EInvoicing\Domain\Environment;
use SureCartEuHelper\Modules\EInvoicing\Workflow\IdempotencyKey;
use SureCartEuHelper\Modules\EInvoicing\Workflow\NumberSequence;

defined( 'ABSPATH' ) || exit;

/**
 * The only core class that reads SureCart order models. It produces an
 * immutable {@see Document} snapshot — once built and persisted, the module
 * never re-derives merchant/customer/line data from SureCart for that document,
 * so later product/customer edits cannot corrupt an issued invoice or a credit
 * note derived from it.
 *
 * Tax handling for MVP: SureCart's per-line tax field shape is not yet confirmed
 * (verify on live data), so this computes a single blended VAT rate from the
 * order's net + tax totals and assigns it across the lines / one tax subtotal —
 * exact for the common single-rate EU order. Multi-rate breakdown is a later
 * refinement once the live line/tax fields are confirmed.
 */
class SureCartOrderMapper {

	use SureCartReader;

	/**
	 * Build an invoice Document from a SureCart order id.
	 *
	 * @param string $order_id SureCart order id.
	 * @return Document|null Null when the order can't be read.
	 */
	public function from_order( string $order_id ): ?Document {
		$order = $this->fetch_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$checkout = $this->prop( $order, 'checkout' );
		$currency = strtoupper( (string) ( $this->prop( $order, 'currency' ) ?? $this->prop( $checkout, 'currency' ) ?? '' ) );

		$net   = $this->amount( $order, $checkout, 'subtotal_amount' );
		$tax   = $this->amount( $order, $checkout, 'tax_amount' );
		$gross = $this->amount( $order, $checkout, 'total_amount' );
		if ( 0 === $gross ) {
			$gross = $net + $tax;
		}

		$rate  = $net > 0 ? round( $tax / $net * 100, 2 ) : 0.0;
		$lines = $this->lines( $order, $checkout, $rate, $net );

		$environment = Environment::normalize( Settings::get( 'einvoicing', 'environment', Environment::SANDBOX ) );

		$doc                  = new Document();
		$doc->type            = DocumentType::INVOICE;
		$doc->number          = NumberSequence::next( DocumentType::INVOICE );
		$doc->issue_date      = $this->issue_date( $order, $checkout );
		$doc->currency        = $currency;
		$doc->sc_order_id      = (string) ( $this->prop( $order, 'id' ) ?? $order_id );
		$doc->sc_order_number  = (string) ( $this->prop( $order, 'number' ) ?? '' );
		$doc->sc_customer_id   = (string) ( $this->prop( $order, 'customer_id' ) ?? '' );
		$doc->provider_key     = (string) Settings::get( 'einvoicing', 'provider', '' );
		$doc->environment      = $environment;
		$doc->idempotency_key  = IdempotencyKey::for_invoice( $doc->sc_order_id, $environment );
		$doc->merchant         = MerchantProfile::party();
		$doc->customer         = $this->customer_party( $order, $checkout );
		$doc->lines            = $lines;
		$doc->tax_lines        = $this->tax_lines( $rate, $net, $tax, $doc->merchant['country'] ?? '' );
		$doc->totals           = array(
			'net'   => $net,
			'tax'   => $tax,
			'gross' => $gross,
		);

		return $doc;
	}

	/**
	 * Read a minor-unit amount, preferring the order then its checkout.
	 *
	 * @param mixed  $order    Order.
	 * @param mixed  $checkout Checkout.
	 * @param string $field    Amount field.
	 * @return int
	 */
	private function amount( $order, $checkout, string $field ): int {
		$value = $this->prop( $order, $field );
		if ( ! is_numeric( $value ) ) {
			$value = $this->prop( $checkout, $field );
		}
		return is_numeric( $value ) ? (int) round( (float) $value ) : 0;
	}

	/**
	 * Issue date (Y-m-d) from the order/checkout created timestamp, else today.
	 *
	 * @param mixed $order    Order.
	 * @param mixed $checkout Checkout.
	 * @return string
	 */
	private function issue_date( $order, $checkout ): string {
		$created = $this->prop( $order, 'created_at' );
		if ( ! is_numeric( $created ) ) {
			$created = $this->prop( $checkout, 'created_at' );
		}
		if ( is_numeric( $created ) && (int) $created > 0 ) {
			return gmdate( 'Y-m-d', (int) $created );
		}
		return gmdate( 'Y-m-d' );
	}

	/**
	 * Build document lines from the order's line items. When the order's net
	 * total can't be tied to line subtotals, falls back to a single summary line
	 * so a document can still be produced (verify against live line fields).
	 *
	 * @param mixed  $order    Order.
	 * @param mixed  $checkout Checkout.
	 * @param float  $rate     Blended tax rate percent.
	 * @param int    $net      Order net (minor units).
	 * @return array<int,array<string,mixed>>
	 */
	private function lines( $order, $checkout, float $rate, int $net ): array {
		$items = $this->line_items( $order, $checkout );

		$out = array();
		foreach ( $items as $line ) {
			$id   = (string) ( $this->prop( $line, 'id' ) ?? '' );
			$qty  = (int) ( $this->prop( $line, 'quantity' ) ?? 1 );
			$qty  = $qty < 1 ? 1 : $qty;
			$name = $this->line_name( $line );

			$line_net = $this->prop( $line, 'subtotal_amount' );
			if ( ! is_numeric( $line_net ) ) {
				$line_net = $this->prop( $line, 'total_amount' );
			}
			$line_net = is_numeric( $line_net ) ? (int) round( (float) $line_net ) : 0;

			$price      = $this->prop( $line, 'price' );
			$unit_price = $this->prop( $price, 'amount' );
			$unit_price = is_numeric( $unit_price ) ? (int) round( (float) $unit_price ) : ( $qty > 0 ? (int) round( $line_net / $qty ) : $line_net );

			$product    = $this->prop( $price, 'product' );
			$product_id = (string) ( $this->prop( $product, 'id' ) ?? '' );

			$out[] = Document::line(
				array(
					'source_ref'       => '' !== $id ? $id : (string) ( count( $out ) + 1 ),
					'product_ref'      => $product_id,
					'description'      => '' !== $name ? $name : __( 'Item', 'surecart-eu-helper' ),
					'quantity'         => $qty,
					'unit_price'       => $unit_price,
					'line_net'         => $line_net,
					'tax_rate_percent' => $rate,
					'tax_category'     => $rate > 0 ? 'standard' : 'zero',
				)
			);
		}

		// Fallback: no usable line detail — emit one summary line for the net.
		if ( empty( $out ) ) {
			$out[] = Document::line(
				array(
					'source_ref'       => '1',
					'description'      => __( 'Order total', 'surecart-eu-helper' ),
					'quantity'         => 1,
					'unit_price'       => $net,
					'line_net'         => $net,
					'tax_rate_percent' => $rate,
					'tax_category'     => $rate > 0 ? 'standard' : 'zero',
				)
			);
			return $out;
		}

		// Reconcile to the order net. SureCart keeps order-level discounts off the
		// individual line items, so the line subtotals can sum to more than the
		// order's net. Add a single discount/adjustment line for the difference so
		// the document always balances (validator requires net == sum of line nets).
		if ( $net > 0 ) {
			$sum = 0;
			foreach ( $out as $line ) {
				$sum += (int) $line['line_net'];
			}
			$diff = $net - $sum;
			if ( 0 !== $diff ) {
				$out[] = Document::line(
					array(
						'source_ref'       => 'adjustment',
						'description'      => $diff < 0 ? __( 'Discount / adjustment', 'surecart-eu-helper' ) : __( 'Adjustment', 'surecart-eu-helper' ),
						'quantity'         => 1,
						'unit_price'       => $diff,
						'line_net'         => $diff,
						'tax_rate_percent' => $rate,
						'tax_category'     => $rate > 0 ? 'standard' : 'zero',
					)
				);
			}
		}

		return $out;
	}

	/**
	 * The order's line items (order, then checkout).
	 *
	 * @param mixed $order    Order.
	 * @param mixed $checkout Checkout.
	 * @return array<int,mixed>
	 */
	private function line_items( $order, $checkout ): array {
		$items = $this->prop( $order, 'line_items' );
		if ( empty( $items ) ) {
			$items = $this->prop( $checkout, 'line_items' );
		}
		return $this->collection_to_array( $items );
	}

	/**
	 * Best display name for a line (product → price → line description).
	 *
	 * @param mixed $line Line item.
	 * @return string
	 */
	private function line_name( $line ): string {
		$price   = $this->prop( $line, 'price' );
		$product = $this->prop( $price, 'product' );
		foreach ( array( $this->prop( $product, 'name' ), $this->prop( $price, 'name' ), $this->prop( $line, 'description' ) ) as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Single blended tax subtotal (MVP). Empty when there is no tax.
	 *
	 * @param float  $rate    Rate percent.
	 * @param int    $net     Net base.
	 * @param int    $tax     Tax amount.
	 * @param string $country Merchant country.
	 * @return array<int,array<string,mixed>>
	 */
	private function tax_lines( float $rate, int $net, int $tax, string $country ): array {
		if ( 0 === $tax && 0.0 === $rate ) {
			return array(
				Document::tax_line(
					array(
						'rate_percent' => 0,
						'category'     => 'zero',
						'taxable_base' => $net,
						'tax_amount'   => 0,
					)
				),
			);
		}

		return array(
			Document::tax_line(
				array(
					'rate_percent' => $rate,
					'category'     => 'standard',
					'taxable_base' => $net,
					'tax_amount'   => $tax,
				)
			),
		);
	}

	/**
	 * Build the customer (buyer) party snapshot from the order's checkout/customer.
	 *
	 * @param mixed $order    Order.
	 * @param mixed $checkout Checkout.
	 * @return array<string,mixed>
	 */
	private function customer_party( $order, $checkout ): array {
		$customer = $this->prop( $checkout, 'customer' );
		if ( empty( $customer ) ) {
			$customer = $this->prop( $order, 'customer' );
		}

		$billing = $this->prop( $checkout, 'billing_address' );
		if ( empty( $billing ) ) {
			$billing = $this->prop( $customer, 'billing_address' );
		}

		$name = (string) ( $this->prop( $checkout, 'name' ) ?? $this->prop( $customer, 'name' ) ?? $this->prop( $billing, 'name' ) ?? '' );
		if ( '' === $name ) {
			$first = (string) ( $this->prop( $customer, 'first_name' ) ?? '' );
			$last  = (string) ( $this->prop( $customer, 'last_name' ) ?? '' );
			$name  = trim( $first . ' ' . $last );
		}

		$email = (string) ( $this->prop( $checkout, 'email' ) ?? $this->prop( $customer, 'email' ) ?? '' );

		// VAT / tax identifier from checkout or customer.
		$tax_obj = $this->prop( $checkout, 'tax_identifier' );
		if ( empty( $tax_obj ) ) {
			$tax_obj = $this->prop( $customer, 'tax_identifier' );
		}
		$vat = (string) ( $this->prop( $tax_obj, 'number' ) ?? $this->prop( $tax_obj, 'value' ) ?? '' );

		return Document::party(
			array(
				'name'        => $name,
				'legal_name'  => $name,
				'tax_id'      => $vat,
				'email'       => $email,
				'country'     => (string) ( $this->prop( $billing, 'country' ) ?? '' ),
				'line1'       => (string) ( $this->prop( $billing, 'line_1' ) ?? $this->prop( $billing, 'line1' ) ?? '' ),
				'line2'       => (string) ( $this->prop( $billing, 'line_2' ) ?? $this->prop( $billing, 'line2' ) ?? '' ),
				'city'        => (string) ( $this->prop( $billing, 'city' ) ?? '' ),
				'postal_code' => (string) ( $this->prop( $billing, 'postal_code' ) ?? '' ),
				'region'      => (string) ( $this->prop( $billing, 'state' ) ?? $this->prop( $billing, 'region' ) ?? '' ),
			)
		);
	}
}
