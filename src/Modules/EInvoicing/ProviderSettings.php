<?php
/**
 * Reader for provider-namespaced settings.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing;

use SureCartEuHelper\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provider sender/identity fields live in the main `sceu_settings` option under
 * the einvoicing module, with their keys prefixed by the provider key
 * ("storecove__legal_entity_id") so two providers never collide. This helper
 * composes/reads those keys so adapters needn't know the storage layout.
 *
 * Secrets are NOT here — those live in {@see Secrets}.
 */
final class ProviderSettings {

	/**
	 * Prefix a provider field key for storage in sceu_settings.
	 *
	 * @param string $provider Provider key.
	 * @param string $field    Field key.
	 * @return string
	 */
	public static function key( string $provider, string $field ): string {
		return $provider . '__' . $field;
	}

	/**
	 * Read a provider setting value.
	 *
	 * @param string $provider Provider key.
	 * @param string $field    Field key.
	 * @param mixed  $default  Fallback.
	 * @return mixed
	 */
	public static function get( string $provider, string $field, $default = '' ) {
		return Settings::get( 'einvoicing', self::key( $provider, $field ), $default );
	}
}
