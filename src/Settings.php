<?php
/**
 * Options access helper.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the single `sceu_settings` option.
 *
 * Shape:
 *   [
 *     'modules' => [ 'right_of_withdrawal' => true|false, ... ],
 *     'right_of_withdrawal' => [ 'lookback_days' => 14, ... ],
 *   ]
 */
class Settings {

	const OPTION = 'sceu_settings';

	/**
	 * Get the full settings array.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module_id Module id.
	 * @return bool
	 */
	public static function is_module_enabled( string $module_id ): bool {
		$all = self::all();
		return ! empty( $all['modules'][ $module_id ] );
	}

	/**
	 * Get a single setting for a module.
	 *
	 * @param string $module_id Module id.
	 * @param string $key       Setting key.
	 * @param mixed  $default   Fallback when unset.
	 * @return mixed
	 */
	public static function get( string $module_id, string $key, $default = null ) {
		$all = self::all();
		if ( isset( $all[ $module_id ][ $key ] ) ) {
			return $all[ $module_id ][ $key ];
		}
		return $default;
	}

	/**
	 * Get a module's full settings array.
	 *
	 * @param string $module_id Module id.
	 * @return array<string, mixed>
	 */
	public static function for_module( string $module_id ): array {
		$all = self::all();
		return isset( $all[ $module_id ] ) && is_array( $all[ $module_id ] ) ? $all[ $module_id ] : array();
	}
}
