<?php
/**
 * Resolves merchant/store details from SureCart for pre-filling settings.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Merchant;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the SureCart store account so the merchant-notification email can be
 * pre-filled. Falls back to the WordPress admin email. Cached briefly.
 */
class MerchantInfo {

	/**
	 * Best-guess merchant notification email.
	 *
	 * Tries the SureCart account first, then the WP admin email.
	 *
	 * @return string
	 */
	public static function notification_email(): string {
		$cached = get_transient( 'sceu_merchant_email' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$email = '';

		// 1) SureCart account helper, when available.
		if ( function_exists( 'SureCart' ) ) {
			try {
				$account = \SureCart::account();
				$email   = self::email_from_account( $account );
			} catch ( \Throwable $e ) {
				$email = '';
			}
		}

		// 2) SureCart Account model.
		if ( '' === $email && class_exists( '\SureCart\Models\Account' ) ) {
			try {
				$account = \SureCart\Models\Account::find();
				$email   = self::email_from_account( $account );
			} catch ( \Throwable $e ) {
				$email = '';
			}
		}

		// 3) WordPress admin email fallback.
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email', '' );
		}

		$email = sanitize_email( $email );

		if ( '' !== $email ) {
			set_transient( 'sceu_merchant_email', $email, HOUR_IN_SECONDS );
		}

		/**
		 * Filter the resolved merchant notification email.
		 *
		 * @param string $email Resolved email.
		 */
		return (string) apply_filters( 'sceu_merchant_email', $email );
	}

	/**
	 * Pull a plausible notification email off an account object, tolerating
	 * the various field names SureCart may use.
	 *
	 * @param mixed $account Account object/array.
	 * @return string
	 */
	private static function email_from_account( $account ): string {
		if ( is_object( $account ) ) {
			foreach ( array( 'notification_email', 'support_email', 'email' ) as $key ) {
				if ( ! empty( $account->$key ) && is_string( $account->$key ) ) {
					return $account->$key;
				}
			}
		}
		if ( is_array( $account ) ) {
			foreach ( array( 'notification_email', 'support_email', 'email' ) as $key ) {
				if ( ! empty( $account[ $key ] ) && is_string( $account[ $key ] ) ) {
					return $account[ $key ];
				}
			}
		}
		return '';
	}

	/**
	 * Store/site name for email branding.
	 *
	 * @return string
	 */
	public static function store_name(): string {
		$name = (string) get_bloginfo( 'name' );
		return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
}
