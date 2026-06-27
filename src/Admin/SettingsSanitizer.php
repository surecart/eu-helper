<?php
/**
 * Shared sanitizer for the single `sceu_settings` option.
 *
 * Extracted so the classic Settings API save path (SettingsPage::sanitize) and
 * the REST save path (SettingsController) sanitize identically — one schema,
 * one set of rules. Validates each posted value against the field type declared
 * by the owning module's settings_fields().
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Admin;

use SureCartEuHelper\Modules\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless sanitizer for the plugin settings option.
 */
class SettingsSanitizer {

	/**
	 * Sanitize a raw posted settings array against every module's field schema.
	 *
	 * @param mixed          $input    Raw posted value.
	 * @param ModuleRegistry $registry Module registry.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input, ModuleRegistry $registry ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array( 'modules' => array() );

		// Plugin-level: whether to purge all data on uninstall (default off).
		$out['remove_data'] = ! empty( $input['remove_data'] );

		foreach ( $registry->all() as $id => $module ) {
			// Enable flag (hidden 0 + checkbox 1 pattern).
			$out['modules'][ $id ] = ! empty( $input['modules'][ $id ] ) ? true : false;

			$values = isset( $input[ $id ] ) && is_array( $input[ $id ] ) ? $input[ $id ] : array();
			$clean  = array();

			foreach ( $module->settings_fields() as $field ) {
				$key  = $field['key'] ?? '';
				$type = $field['type'] ?? 'text';
				if ( '' === $key ) {
					continue;
				}
				$raw = $values[ $key ] ?? null;

				switch ( $type ) {
					case 'toggle':
						$clean[ $key ] = ! empty( $raw );
						break;
					case 'number':
						$num           = is_numeric( $raw ) ? (int) $raw : (int) ( $field['default'] ?? 0 );
						$min           = isset( $field['min'] ) ? (int) $field['min'] : null;
						$clean[ $key ] = ( null !== $min ) ? max( $min, $num ) : $num;
						break;
					case 'email':
						$clean[ $key ] = sanitize_email( (string) $raw );
						break;
					case 'select':
					case 'radio':
						$allowed       = array_map(
							static function ( $o ) {
								return $o['value'];
							},
							$field['options'] ?? array()
						);
						$clean[ $key ] = in_array( $raw, $allowed, true ) ? $raw : ( $field['default'] ?? '' );
						break;
					case 'product_exclusions':
					case 'collection_exclusions':
						// A list of SureCart ids (UUID-shaped). Strip anything else.
						$ids           = is_array( $raw ) ? $raw : array();
						$clean[ $key ] = array_values(
							array_unique(
								array_filter(
									array_map(
										static function ( $v ) {
											return preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $v );
										},
										$ids
									)
								)
							)
						);
						break;
					default:
						$clean[ $key ] = sanitize_text_field( (string) $raw );
				}
			}

			// Display-only labels for the excluded-product picker, posted alongside
			// it so the admin UI needn't re-fetch product names. Kept only for ids
			// still selected.
			if ( isset( $values['excluded_product_labels'] ) && is_array( $values['excluded_product_labels'] ) ) {
				$labels = array();
				foreach ( $values['excluded_product_labels'] as $pid => $pname ) {
					$pid = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $pid );
					if ( '' !== $pid ) {
						$labels[ $pid ] = sanitize_text_field( (string) $pname );
					}
				}
				if ( ! empty( $clean['excluded_product_ids'] ) ) {
					$labels = array_intersect_key( $labels, array_flip( $clean['excluded_product_ids'] ) );
				} else {
					$labels = array();
				}
				$clean['excluded_product_labels'] = $labels;
			}

			$out[ $id ] = $clean;
		}

		return $out;
	}
}
