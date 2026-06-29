<?php
/**
 * Admin-only REST endpoint backing the product-exclusion picker.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Proxies a name search against SureCart products for the settings-page picker.
 * Admin-only; results are cached briefly so repeated keystrokes don't hammer the
 * SureCart API.
 */
class AdminController {

	const NAMESPACE = 'surecart-eu-helper/v1';
	const ROUTE     = '/product-search';

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
	 * Search products by name.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( strlen( $q ) < 2 || ! class_exists( '\SureCart\Models\Product' ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$cache_key = 'sceu_psearch_' . md5( strtolower( $q ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return new \WP_REST_Response( $cached, 200 );
		}

		$out = array();
		try {
			// get() returns a plain array of Product model objects (paginate()
			// returns a Collection wrapper that proved unreliable here).
			$products = \SureCart\Models\Product::where(
				array(
					'query' => $q,
					'limit' => 20,
				)
			)->get();
		} catch ( \Throwable $e ) {
			$products = array();
		}

		if ( ! is_wp_error( $products ) && is_array( $products ) ) {
			foreach ( $products as $p ) {
				$id   = is_object( $p ) ? (string) ( $p->id ?? '' ) : '';
				$name = is_object( $p ) ? (string) ( $p->name ?? '' ) : '';
				if ( '' !== $id ) {
					$out[] = array(
						'id'   => $id,
						'name' => '' !== $name ? $name : $id,
					);
				}
			}
		}

		set_transient( $cache_key, $out, 5 * MINUTE_IN_SECONDS );
		return new \WP_REST_Response( $out, 200 );
	}
}
