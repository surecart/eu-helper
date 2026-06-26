<?php
/**
 * Global helper functions (no namespace) so they are safe to call from block
 * render.php, which executes in the global namespace.
 *
 * @package SureCartEuHelper
 */

defined( 'ABSPATH' ) || exit;

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

if ( ! function_exists( 'sceu_rate_limit_ip' ) ) {
	/**
	 * Resolve a spoof-resistant client identity for rate limiting / abuse keys.
	 *
	 * SECURITY: `sceu_client_ip()` trusts proxy headers (X-Forwarded-For, etc.)
	 * which a client can forge on any site NOT actually behind a normalising
	 * proxy — fine for an informational audit field, but unusable as a throttle
	 * key (an attacker would just rotate the header to win a fresh bucket). So
	 * this defaults to the un-forgeable REMOTE_ADDR.
	 *
	 * Sites genuinely behind a reverse proxy/CDN (where every visitor shares one
	 * REMOTE_ADDR) can opt in to header-based resolution via the
	 * `sceu_trust_proxy_headers` filter, which is the operator asserting their
	 * edge strips client-supplied forwarding headers.
	 *
	 * @return string A valid IP address, or '' when none can be determined.
	 */
	function sceu_rate_limit_ip() {
		/**
		 * Opt in to trusting proxy headers for the rate-limit key. Only enable
		 * this when the site sits behind a proxy/CDN that overwrites the
		 * forwarding headers (e.g. Cloudflare), otherwise the limiter is
		 * bypassable.
		 *
		 * @param bool $trust Whether forwarded headers are trustworthy here.
		 */
		if ( apply_filters( 'sceu_trust_proxy_headers', false ) ) {
			return sceu_client_ip();
		}

		$remote = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}
}
