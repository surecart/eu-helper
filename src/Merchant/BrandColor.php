<?php
/**
 * Resolves the SureCart store's primary/brand colour.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Merchant;

defined( 'ABSPATH' ) || exit;

/**
 * SureCart stores a brand hex colour in its branding settings (exposed inside
 * its web components as `--sc-color-primary-500`, which lives in a shadow DOM
 * we can't read from CSS). We resolve the hex server-side so the withdrawal
 * block's buttons can match the store. Always filterable, with a graceful
 * fallback chain to the theme's primary preset.
 */
class BrandColor {

	/**
	 * Resolved brand colour as a hex string (e.g. #1a73e8), or '' if unknown.
	 *
	 * @return string
	 */
	public static function primary(): string {
		$cached = get_transient( 'sceu_brand_color' );
		if ( is_string( $cached ) ) {
			$cached = self::filter( $cached );
			return $cached;
		}

		$color = '';

		if ( function_exists( 'SureCart' ) ) {
			try {
				$color = self::color_from_account( \SureCart::account() );
			} catch ( \Throwable $e ) {
				$color = '';
			}
		}

		if ( '' === $color && class_exists( '\SureCart\Models\Account' ) ) {
			try {
				$color = self::color_from_account( \SureCart\Models\Account::find() );
			} catch ( \Throwable $e ) {
				$color = '';
			}
		}

		$color = self::sanitize_hex( $color );

		// Cache even an empty result briefly to avoid repeat lookups.
		set_transient( 'sceu_brand_color', $color, HOUR_IN_SECONDS );

		return self::filter( $color );
	}

	/**
	 * Readable text colour to sit on the primary colour. Uses a brand-provided
	 * value when present, else computes black/white by contrast, else ''.
	 *
	 * @return string
	 */
	public static function primary_text(): string {
		$primary = self::primary();
		if ( '' === $primary ) {
			return '';
		}

		/**
		 * Filter the text colour used on the primary button.
		 *
		 * @param string $text    Computed contrast colour.
		 * @param string $primary The primary colour.
		 */
		return (string) apply_filters( 'sceu_primary_text_color', self::contrast_color( $primary ), $primary );
	}

	/**
	 * Apply the overridable filter to a resolved primary colour.
	 *
	 * @param string $color Hex colour or ''.
	 * @return string
	 */
	private static function filter( string $color ): string {
		/**
		 * Filter the resolved SureCart primary colour used by the block buttons.
		 *
		 * @param string $color Hex colour (may be empty).
		 */
		return self::sanitize_hex( (string) apply_filters( 'sceu_primary_color', $color ) );
	}

	/**
	 * Pull a hex colour off an account object/array, tolerating field names.
	 *
	 * @param mixed $account Account.
	 * @return string
	 */
	private static function color_from_account( $account ): string {
		$keys = array( 'brand_color', 'color', 'primary_color' );

		foreach ( $keys as $key ) {
			if ( is_object( $account ) && ! empty( $account->$key ) && is_string( $account->$key ) ) {
				return $account->$key;
			}
			if ( is_array( $account ) && ! empty( $account[ $key ] ) && is_string( $account[ $key ] ) ) {
				return $account[ $key ];
			}
		}

		// Some shapes nest branding under a `brand` object.
		$brand = is_object( $account ) ? ( $account->brand ?? null ) : ( is_array( $account ) ? ( $account['brand'] ?? null ) : null );
		if ( $brand ) {
			return self::color_from_account( $brand );
		}

		return '';
	}

	/**
	 * Validate + normalise a hex colour (#rgb or #rrggbb).
	 *
	 * @param string $color Candidate.
	 * @return string Normalised hex or ''.
	 */
	private static function sanitize_hex( string $color ): string {
		$color = trim( $color );
		if ( '' === $color ) {
			return '';
		}
		if ( '#' !== $color[0] ) {
			$color = '#' . $color;
		}
		return preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ? $color : '';
	}

	/**
	 * Choose black or white text for legibility on a hex background.
	 *
	 * @param string $hex Background hex (#rgb or #rrggbb).
	 * @return string
	 */
	private static function contrast_color( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		// Perceived luminance (ITU-R BT.601).
		$luma = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
		return $luma > 0.6 ? '#111111' : '#ffffff';
	}
}
