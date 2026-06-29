<?php
/**
 * reCAPTCHA v3 for the public withdrawal form.
 *
 * Reuses SureCart's own reCAPTCHA configuration — the site/secret keys live in
 * plain `surecart_recaptcha_*` WordPress options (no SureCart API token
 * involved), and verification goes through SureCart's own validation service
 * when available. The EU Helper toggle only governs the withdrawal form; if
 * SureCart's keys aren't configured this is a no-op (fail-open) so a
 * misconfiguration never blocks legitimate submitters. The form's honeypot,
 * logged-out nonce, and per-IP rate limit always apply regardless.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

use SureCartEuHelper\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin adapter over SureCart's reCAPTCHA v3 setup, scoped to the withdrawal form.
 */
class Recaptcha {

	const MODULE_ID   = 'right_of_withdrawal';
	const SETTING_KEY = 'recaptcha_enabled';
	const ACTION      = 'sceu_withdrawal';

	/**
	 * Whether reCAPTCHA should run on the withdrawal form: the merchant enabled
	 * it here AND SureCart actually has reCAPTCHA keys configured.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return (bool) Settings::get( self::MODULE_ID, self::SETTING_KEY, false ) && self::is_configured();
	}

	/**
	 * SureCart's stored reCAPTCHA v3 site key (public, used to load the script).
	 *
	 * @return string
	 */
	public static function site_key(): string {
		return (string) get_option( 'surecart_recaptcha_site_key', '' );
	}

	/**
	 * SureCart's stored reCAPTCHA v3 secret key (server-side verification only).
	 *
	 * @return string
	 */
	private static function secret_key(): string {
		return (string) get_option( 'surecart_recaptcha_secret_key', '' );
	}

	/**
	 * Whether SureCart has both reCAPTCHA keys, so we can actually run it.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::site_key() && '' !== self::secret_key();
	}

	/**
	 * Verify a v3 token. Prefers SureCart's own validation service so behaviour
	 * (and any score filter) matches checkout; falls back to a direct siteverify
	 * call. Returns true only on a confirmed pass.
	 *
	 * @param string $token Client grecaptcha token.
	 * @return bool
	 */
	public static function verify( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		if ( class_exists( '\SureCart\WordPress\RecaptchaValidationService' ) ) {
			try {
				// validate() returns true on success or a WP_Error on failure.
				return true === ( new \SureCart\WordPress\RecaptchaValidationService() )->validate( $token );
			} catch ( \Throwable $e ) {
				// Fall through to the direct check.
			}
		}

		return self::verify_directly( $token );
	}

	/**
	 * Direct Google siteverify fallback (mirrors SureCart's request), used only
	 * when SureCart's validation service class isn't available.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private static function verify_directly( string $token ): bool {
		$secret = self::secret_key();
		if ( '' === $secret ) {
			return false;
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'body' => array(
					'secret'   => $secret,
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		return is_object( $data ) && ! empty( $data->success );
	}
}
