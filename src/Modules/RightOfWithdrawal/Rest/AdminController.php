<?php
/**
 * Admin-only REST endpoints for the Right of Withdrawal settings screen:
 * product search + exclusion-cache refresh. Counterpart to GuestController.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Rest;

use SureCartEuHelper\Modules\RightOfWithdrawal\Exclusions;

defined( 'ABSPATH' ) || exit;

/**
 * Admin REST controller for the withdrawal settings screen. Every route is gated
 * on SureCart's product capability (or manage_options).
 */
class AdminController {

	const NAMESPACE     = 'surecart-eu-helper/v1';
	const ROUTE         = '/product-search';
	const ROUTE_REFRESH = '/exclusions/refresh';

	/**
	 * Shared admin capability gate: SureCart's own product capability (what its
	 * admin product screens use), falling back to manage_options for plain admins.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'edit_sc_products' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Register the routes.
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
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Rebuild the exclusion cache in place (the app toasts the result).
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_REFRESH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refresh_exclusions' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Rebuild the excluded-product cache and report how many products it resolved.
	 *
	 * @return \WP_REST_Response
	 */
	public function refresh_exclusions(): \WP_REST_Response {
		$count = 0;
		if ( class_exists( Exclusions::class ) ) {
			try {
				$count = count( Exclusions::rebuild_cache() );
			} catch ( \Throwable $e ) {
				$count = 0;
			}
		}
		return new \WP_REST_Response( array( 'count' => $count ), 200 );
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
