<?php
/**
 * Tolerant readers for SureCart models.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Small helpers shared by the order mapper and refund reader for reading
 * SureCart's object/array shapes safely. Mirrors the proven idioms in
 * CustomerContext (prop / collection_to_array / the expansion-fallback ladder)
 * without coupling this module to that customer-session class.
 */
trait SureCartReader {

	/**
	 * Read a property from an object or associative array.
	 *
	 * @param mixed  $data Object or array.
	 * @param string $key  Property/key name.
	 * @return mixed|null
	 */
	protected function prop( $data, string $key ) {
		if ( is_object( $data ) ) {
			return $data->$key ?? null;
		}
		if ( is_array( $data ) ) {
			return $data[ $key ] ?? null;
		}
		return null;
	}

	/**
	 * Normalise any SureCart list shape into a plain array of items.
	 *
	 * @param mixed $list Candidate list/collection.
	 * @return array<int,mixed>
	 */
	protected function collection_to_array( $list ): array {
		if ( is_array( $list ) ) {
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
	 * Fetch a SureCart order by id, expanding as much line/party detail as the
	 * API allows, falling back to leaner expansions on error (the ladder mirrors
	 * CustomerContext::query_orders_since). Returns null on failure.
	 *
	 * @param string $order_id Order id.
	 * @return object|null
	 */
	protected function fetch_order( string $order_id ) {
		if ( '' === $order_id || ! class_exists( '\SureCart\Models\Order' ) ) {
			return null;
		}

		$ladders = array(
			array( 'checkout', 'checkout.line_items', 'line_item.price', 'price.product', 'checkout.customer', 'customer.tax_identifier' ),
			array( 'checkout', 'checkout.line_items', 'line_item.price', 'price.product' ),
			array( 'checkout', 'checkout.line_items', 'line_item.price' ),
			array( 'checkout', 'checkout.line_items' ),
			array( 'checkout' ),
		);

		foreach ( $ladders as $with ) {
			try {
				$order = \SureCart\Models\Order::with( $with )->find( $order_id );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_wp_error( $order ) && is_object( $order ) ) {
				return $order;
			}
		}

		return null;
	}
}
