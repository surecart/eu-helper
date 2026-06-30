<?php
/**
 * SureCart admin deep links.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds deep links into the SureCart admin (orders, customers).
 *
 * SureCart already exposes a routing helper for this —
 * `\SureCart::getUrl()->edit( $resource, $id )` (see SureCart's own
 * AdminURLService / AdminRouteService) — and uses it throughout its codebase,
 * so we depend on it rather than reimplementing the route. Because it is a
 * SureCart call, it is wrapped in try/catch and falls back to building the
 * known route ourselves, so a SureCart change degrades gracefully instead of
 * fatalling. Each builder stays filterable in case a site needs to override it.
 */
class AdminUrl {

	/**
	 * Deep link to a single order inside the SureCart admin.
	 *
	 * @param string $order_id SureCart order id.
	 * @return string Absolute admin URL.
	 */
	public static function order( string $order_id ): string {
		return self::edit( 'order', $order_id, 'sc-orders', 'sceu_order_admin_url' );
	}

	/**
	 * Deep link to a single customer inside the SureCart admin.
	 *
	 * @param string $customer_id SureCart customer id.
	 * @return string Absolute admin URL.
	 */
	public static function customer( string $customer_id ): string {
		return self::edit( 'customer', $customer_id, 'sc-customers', 'sceu_customer_admin_url' );
	}

	/**
	 * Resolve a SureCart admin "edit" URL, preferring SureCart's own router and
	 * falling back to the known route. The result is filterable.
	 *
	 * @param string $resource      SureCart route key (e.g. 'order', 'customer').
	 * @param string $id            Record id.
	 * @param string $fallback_page Admin page slug used if SureCart's router is unavailable.
	 * @param string $filter        Filter name applied to the final URL.
	 * @return string Absolute admin URL.
	 */
	private static function edit( string $resource, string $id, string $fallback_page, string $filter ): string {
		$url = '';

		// Prefer SureCart's own admin URL service so links track its routing
		// (page slugs, future changes) instead of a value we hard-code here.
		if ( class_exists( '\SureCart' ) ) {
			try {
				$url = (string) \SureCart::getUrl()->edit( $resource, $id );
			} catch ( \Throwable $e ) {
				$url = '';
			}
		}

		// Fallback: build the documented route ourselves so the link still works
		// if SureCart's router is absent or changes shape.
		if ( '' === $url ) {
			$url = add_query_arg(
				array(
					'page'   => $fallback_page,
					'action' => 'edit',
					'id'     => $id,
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Filter the resolved SureCart admin deep link.
		 *
		 * @param string $url Resolved admin URL.
		 * @param string $id  The record id.
		 */
		return (string) esc_url( apply_filters( $filter, $url, $id ) );
	}
}
