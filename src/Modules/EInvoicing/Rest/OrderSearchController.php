<?php
/**
 * Admin REST endpoint: search SureCart orders for the manual invoice picker.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Rest;

use SureCartEuHelper\Modules\EInvoicing\Mapping\SureCartReader;

defined( 'ABSPATH' ) || exit;

/**
 * Backs the order autocomplete on the E-Invoicing tools card. Admin-only. Returns
 * a small, display-ready list of recent/matching orders so the merchant can pick
 * one by number/customer instead of hunting for an internal order ID.
 */
class OrderSearchController {

	use SureCartReader;

	const NAMESPACE = 'surecart-eu-helper/v1';
	const ROUTE     = '/einvoicing/orders';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Return up to 20 matching/recent orders as { id, main, meta }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$q = (string) $request->get_param( 'q' );

		if ( ! class_exists( '\SureCart\Models\Order' ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$args = array( 'limit' => 20 );
		if ( '' !== $q ) {
			// SureCart order search param (verify): falls back to recent orders if
			// the API ignores it.
			$args['query'] = $q;
		}

		try {
			$orders = \SureCart\Models\Order::where( $args )
				->with( array( 'checkout', 'checkout.customer' ) )
				->get();
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$orders = $this->collection_to_array( $orders );
		$out    = array();

		foreach ( $orders as $order ) {
			$id = (string) ( $this->prop( $order, 'id' ) ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$checkout = $this->prop( $order, 'checkout' );
			$customer = $this->prop( $checkout, 'customer' );

			$number = $this->prop( $order, 'number' );
			$label  = ( null !== $number && '' !== $number ) ? ( '#' . $number ) : ( '#' . substr( $id, 0, 8 ) );

			$name = (string) (
				$this->prop( $customer, 'name' )
				?? $this->prop( $checkout, 'name' )
				?? $this->prop( $customer, 'email' )
				?? $this->prop( $checkout, 'email' )
				?? ''
			);

			$main = '' !== $name ? ( $label . ' — ' . $name ) : $label;

			$meta_parts = array();
			$total      = $this->prop( $order, 'total_amount' );
			if ( ! is_numeric( $total ) ) {
				$total = $this->prop( $checkout, 'total_amount' );
			}
			$currency = strtoupper( (string) ( $this->prop( $order, 'currency' ) ?? $this->prop( $checkout, 'currency' ) ?? '' ) );
			if ( is_numeric( $total ) ) {
				$meta_parts[] = \SureCartEuHelper\Modules\EInvoicing\Domain\Money::format( (int) round( (float) $total ), $currency );
			}
			$created = $this->prop( $order, 'created_at' );
			if ( is_numeric( $created ) && (int) $created > 0 ) {
				$meta_parts[] = gmdate( 'Y-m-d', (int) $created );
			}

			$out[] = array(
				'id'   => $id,
				'main' => $main,
				'meta' => implode( ' · ', $meta_parts ),
			);
		}

		return new \WP_REST_Response( $out, 200 );
	}
}
