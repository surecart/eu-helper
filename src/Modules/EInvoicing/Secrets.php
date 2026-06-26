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
 * exposed throughout the settings UI).
 *
 * Secrets are ENCRYPTED AT REST by default, reusing SureCart's own
 * `\SureCart\Support\Encryption` (AES-256-CTR keyed off the site's
 * LOGGED_IN_KEY/SALT or the SURECART_ENCRYPTION_* constants) — the same scheme
 * SureCart uses for its API token. A site may fully override the scheme via the
 * `sceu_einv_encrypt` / `sceu_einv_decrypt` filters; if neither has a callback,
 * the default encryption is applied. Legacy plaintext values written before
 * encryption are still readable (decrypt falls back to the raw value).
 *
 * For maximum hardening a value can be supplied via a wp-config constant named
 * `SCEU_SECRET_<UPPERCASE_KEY>` (e.g.
 * `SCEU_SECRET_STORECOVE__PRODUCTION__API_KEY`), which takes precedence on read
 * so the production credential never has to live in the database at all.
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
		// A wp-config constant always wins, so prod keys need never touch the DB.
		$override = self::constant_override( $key );
		if ( null !== $override ) {
			return $override;
		}

		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
			return '';
		}
		$stored = (string) $all[ $key ];
		if ( '' === $stored ) {
			return '';
		}
		return self::decrypt( $stored, $key );
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
			$all[ $key ] = self::encrypt_value( $value, $key );
		}

		// autoload=false: secrets must never load on every request.
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Encrypt a value for storage.
	 *
	 * Public so callers that persist the secrets option themselves (e.g. the
	 * settings-page sanitize callback) share one encryption routine. A site can
	 * fully override the scheme via the `sceu_einv_encrypt` filter (it must pair
	 * with `sceu_einv_decrypt`); otherwise SureCart's at-rest encryption is used.
	 *
	 * @param string $value Plain value.
	 * @param string $key   Namespaced key (passed to the override filter).
	 * @return string
	 */
	public static function encrypt_value( string $value, string $key ): string {
		if ( has_filter( 'sceu_einv_encrypt' ) ) {
			/**
			 * Override how a secret is encrypted before storage.
			 *
			 * @param string $value Plain value.
			 * @param string $key   Namespaced secret key.
			 */
			return (string) apply_filters( 'sceu_einv_encrypt', $value, $key );
		}

		if ( class_exists( '\SureCart\Support\Encryption' ) ) {
			$encrypted = \SureCart\Support\Encryption::encrypt( $value );
			if ( is_string( $encrypted ) && '' !== $encrypted ) {
				return $encrypted;
			}
		}

		// Last resort (e.g. openssl unavailable): store as-is rather than lose the
		// value. Encryption is best-effort, mirroring SureCart's own behaviour.
		return $value;
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Stored (encrypted) value.
	 * @param string $key    Namespaced key (passed to the override filter).
	 * @return string
	 */
	private static function decrypt( string $stored, string $key ): string {
		if ( has_filter( 'sceu_einv_decrypt' ) ) {
			/**
			 * Override how a secret is decrypted on read.
			 *
			 * @param string $stored Stored value.
			 * @param string $key    Namespaced secret key.
			 */
			return (string) apply_filters( 'sceu_einv_decrypt', $stored, $key );
		}

		if ( class_exists( '\SureCart\Support\Encryption' ) ) {
			$decrypted = \SureCart\Support\Encryption::decrypt( $stored );
			// decrypt() returns false when $stored is not our ciphertext (e.g. a
			// legacy plaintext value) — fall back to the raw value so it still reads.
			if ( is_string( $decrypted ) ) {
				return $decrypted;
			}
		}

		return $stored;
	}

	/**
	 * Read a `SCEU_SECRET_<UPPERCASE_KEY>` wp-config constant for this key.
	 *
	 * @param string $key Namespaced key.
	 * @return string|null The constant value, or null when not defined/empty.
	 */
	private static function constant_override( string $key ): ?string {
		$constant = 'SCEU_SECRET_' . strtoupper( $key );
		if ( defined( $constant ) ) {
			$value = (string) constant( $constant );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return null;
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
