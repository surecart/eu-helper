<?php
/**
 * Document type constants.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The kinds of outbound compliance document this module produces. A credit note
 * is a local sidecar document (SureCart never creates a new order on refund); it
 * always links back to the invoice it reverses.
 *
 * PHP 7.4 target — no native enums, so these are string constants.
 */
final class DocumentType {

	const INVOICE     = 'invoice';
	const CREDIT_NOTE = 'credit_note';

	/**
	 * All valid types.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::INVOICE, self::CREDIT_NOTE );
	}

	/**
	 * Whether a value is a known document type.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}
}
