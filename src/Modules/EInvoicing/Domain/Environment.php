<?php
/**
 * Provider environment constants.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Providers expose separate sandbox and production environments with distinct
 * credentials and base URLs. The environment is part of a document's idempotency
 * key so a sandbox test and a production send are never conflated.
 */
final class Environment {

	const SANDBOX    = 'sandbox';
	const PRODUCTION = 'production';

	/**
	 * All valid environments.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::SANDBOX, self::PRODUCTION );
	}

	/**
	 * Normalise an arbitrary value to a valid environment (defaults to sandbox).
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	public static function normalize( $value ): string {
		return self::PRODUCTION === $value ? self::PRODUCTION : self::SANDBOX;
	}
}
