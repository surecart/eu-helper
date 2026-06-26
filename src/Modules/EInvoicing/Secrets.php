<?php
/**
 * Secret storage for provider credentials.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing;

defined( 'ABSPATH' ) || exit;

/**
 * Stores provider API keys/secrets in a dedicated, NON-autoloaded option, kept
 * out of the main `sceu_settings` blob (which is autoloaded on every request and
 * exposed throughout the settings UI). Values pass through the `sceu_einv_encrypt`
 * / `sceu_einv_decrypt` filters on the way in/out so a future release can add
 * at-rest encryption without changing callers (PR 4 hardening); by default the
 * filters are pass-through.
 *
 * Keys are namespaced by provider + environment, e.g. "storecove__sandbox__api_key".
 */
final class Secrets {

	const OPTION = 'sceu_einv_secrets';

	/**
	 * Compose a namespaced secret key.
	 *
	 * @param string $provider    Provider key.
	 * @param string $environment Environment::*.
	 * @param string $field       Field key (e.g. 'api_key').
	 * @return string
	 */
	public static function key( string $provider, string $environment, string $field ): string {
		return $provider . '__' . $environment . '__' . $field;
	}

	/**
	 * Read a secret. Returns '' when unset.
	 *
	 * @param string $key Namespaced key.
	 * @return string
	 */
	public static function get( string $key ): string {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
			return '';
		}
		$stored = (string) $all[ $key ];
		if ( '' === $stored ) {
			return '';
		}
		return (string) apply_filters( 'sceu_einv_decrypt', $stored, $key );
	}

	/**
	 * Whether a secret is set and non-empty.
	 *
	 * @param string $key Namespaced key.
	 * @return bool
	 */
	public static function has( string $key ): bool {
		return '' !== self::get( $key );
	}

	/**
	 * Store a secret (NON-autoloaded). An empty value clears it.
	 *
	 * @param string $key   Namespaced key.
	 * @param string $value Plain value.
	 * @return void
	 */
	public static function set( string $key, string $value ): void {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		if ( '' === $value ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = (string) apply_filters( 'sceu_einv_encrypt', $value, $key );
		}

		// autoload=false: secrets must never load on every request.
		update_option( self::OPTION, $all, false );
	}

	/**
	 * A masked preview of a secret for display (e.g. "••••••1a2b"), or '' if unset.
	 *
	 * @param string $key Namespaced key.
	 * @return string
	 */
	public static function masked( string $key ): string {
		$value = self::get( $key );
		if ( '' === $value ) {
			return '';
		}
		$tail = substr( $value, -4 );
		return '••••••' . $tail;
	}
}
