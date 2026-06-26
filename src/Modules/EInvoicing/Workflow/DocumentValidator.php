<?php
/**
 * Pre-submission document validation.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Workflow;

use SureCartEuHelper\Modules\EInvoicing\Domain\Document;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;

defined( 'ABSPATH' ) || exit;

/**
 * Gates the mapped → validated transition. Checks the document is internally
 * coherent and carries the minimum a network needs, before it can be queued for
 * submission. Provider-specific routing checks (e.g. "can the receiver get a
 * Peppol invoice?") are the adapter's job, not this generic validator.
 */
final class DocumentValidator {

	/** Tolerated rounding difference (minor units) between totals and line sums. */
	const ROUNDING_TOLERANCE = 2;

	/**
	 * Validate a document. Returns a list of human-readable problems; an empty
	 * list means the document is valid.
	 *
	 * @param Document $doc Document to validate.
	 * @return string[]
	 */
	public static function validate( Document $doc ): array {
		$errors = array();

		if ( '' === $doc->currency ) {
			$errors[] = __( 'Missing currency.', 'surecart-eu-helper' );
		}
		if ( '' === $doc->number ) {
			$errors[] = __( 'Missing document number.', 'surecart-eu-helper' );
		}
		if ( empty( $doc->lines ) ) {
			$errors[] = __( 'Document has no line items.', 'surecart-eu-helper' );
		}

		// Merchant (seller) identity — this is YOUR business, set under
		// E-Invoicing → Business invoicing profile (not the customer's details).
		$merchant = $doc->merchant;
		if ( empty( $merchant['name'] ) && empty( $merchant['legal_name'] ) ) {
			$errors[] = __( 'Your business legal name is missing — set it under E-Invoicing → Business invoicing profile.', 'surecart-eu-helper' );
		}
		if ( empty( $merchant['country'] ) ) {
			$errors[] = __( 'Your business country is missing — set the Country code under E-Invoicing → Business invoicing profile.', 'surecart-eu-helper' );
		}

		// Customer (buyer) identity.
		$customer = $doc->customer;
		if ( empty( $customer['name'] ) && empty( $customer['legal_name'] ) ) {
			$errors[] = __( 'Missing customer name.', 'surecart-eu-helper' );
		}

		// Totals must reconcile with the line items (within rounding).
		$line_net = 0;
		foreach ( $doc->lines as $line ) {
			$line_net += (int) ( $line['line_net'] ?? 0 );
		}
		$declared_net = (int) ( $doc->totals['net'] ?? 0 );
		if ( abs( $declared_net - $line_net ) > self::ROUNDING_TOLERANCE ) {
			$errors[] = sprintf(
				/* translators: 1: declared net, 2: summed net. */
				__( 'Net total (%1$d) does not match the sum of line nets (%2$d).', 'surecart-eu-helper' ),
				$declared_net,
				$line_net
			);
		}

		// Gross should equal net + tax (within rounding).
		$gross = (int) ( $doc->totals['gross'] ?? 0 );
		$net   = (int) ( $doc->totals['net'] ?? 0 );
		$tax   = (int) ( $doc->totals['tax'] ?? 0 );
		if ( abs( $gross - ( $net + $tax ) ) > self::ROUNDING_TOLERANCE ) {
			$errors[] = __( 'Gross total does not equal net plus tax.', 'surecart-eu-helper' );
		}

		// Credit notes must reference the invoice they reverse.
		if ( DocumentType::CREDIT_NOTE === $doc->type && empty( $doc->original_document_id ) ) {
			$errors[] = __( 'Credit note is not linked to an original invoice.', 'surecart-eu-helper' );
		}

		return $errors;
	}
}
