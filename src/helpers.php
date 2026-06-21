<?php
/**
 * Global helper functions (no namespace) so they are safe to call from block
 * render.php, which executes in the global namespace.
 *
 * @package SureCartEuHelper
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sceu_order_admin_url' ) ) {
	/**
	 * Build a deep link to a single order inside the SureCart admin.
	 *
	 * An individual SureCart order opens via the `edit` action with the order
	 * id as a query arg (e.g. admin.php?page=sc-orders&action=edit&id=<id>).
	 * Filterable in case the route changes.
	 *
	 * @param string $order_id SureCart order id.
	 * @return string Absolute admin URL.
	 */
	function sceu_order_admin_url( $order_id ) {
		$default = add_query_arg(
			array(
				'page'   => 'sc-orders',
				'action' => 'edit',
				'id'     => (string) $order_id,
			),
			admin_url( 'admin.php' )
		);

		/**
		 * Filter the admin deep link for a SureCart order.
		 *
		 * @param string $url      Default admin order URL.
		 * @param string $order_id The order id.
		 */
		return (string) esc_url( apply_filters( 'sceu_order_admin_url', $default, (string) $order_id ) );
	}
}

if ( ! function_exists( 'sceu_client_ip' ) ) {
	/**
	 * Resolve the visitor's IP for the audit log.
	 *
	 * Behind a CDN/proxy (e.g. Cloudflare), $_SERVER['REMOTE_ADDR'] is the
	 * proxy's IP, not the visitor's, so we prefer the headers proxies use to
	 * pass the original client IP. CF-Connecting-IP is set by Cloudflare, which
	 * strips any client-supplied value, so it is trustworthy on Cloudflare sites.
	 * The X-Forwarded-* headers can be spoofed when the site is NOT actually
	 * behind a proxy that sets them — acceptable for an informational audit
	 * field, not a security control. Site owners can override via the filter.
	 *
	 * @return string A valid IP address, or '' when none can be determined.
	 */
	function sceu_client_ip() {
		$candidates = array();

		// Cloudflare's real-visitor header first (most trustworthy here).
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}
		// X-Forwarded-For may be a comma list; the left-most is the client.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) as $part ) {
				$candidates[] = trim( $part );
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$resolved = '';
		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$resolved = $candidate;
				break;
			}
		}

		/**
		 * Filter the resolved client IP (e.g. to read a different trusted proxy
		 * header). Return '' to record no IP.
		 *
		 * @param string $resolved Best-effort client IP.
		 */
		$resolved = (string) apply_filters( 'sceu_request_ip', $resolved );

		return filter_var( $resolved, FILTER_VALIDATE_IP ) ? $resolved : '';
	}
}
