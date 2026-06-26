<?php
/**
 * Maps a core Document to a Storecove document-submission payload.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Storecove;

use SureCartEuHelper\Modules\EInvoicing\Domain\Document;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;

defined( 'ABSPATH' ) || exit;

/**
 * The ONLY place a core Document becomes Storecove JSON. Storecove uses decimal
 * amounts (not minor units) and prefers `taxSystem: tax_line_percentages`, so
 * the per-line/per-subtotal tax percentages drive the document. The credit-note
 * sign decision lives here too: the core stores credit notes as negative; this
 * mapper translates to Storecove's representation.
 *
 * Several field names/shapes are marked "verify" — they should be confirmed
 * against a live Storecove sandbox submission, where the exact accepted JSON is
 * easy to validate. Nothing here leaks back into the core.
 */
class StorecoveDocumentMapper {

	/**
	 * Build the full /document_submissions request body.
	 *
	 * @param Document $doc               Source document.
	 * @param int      $legal_entity_id   Storecove sender legal entity id.
	 * @param string   $idempotency_token Per-submission idempotency token.
	 * @return array<string,mixed>
	 */
	public function to_submission( Document $doc, int $legal_entity_id, string $idempotency_token ): array {
		$body = array(
			'legalEntityId'   => $legal_entity_id,
			'idempotencyGuid' => $idempotency_token,
			'routing'         => $this->routing( $doc ),
			'document'        => array(
				// Storecove distinguishes credit notes; verify the exact enum value.
				'documentType' => DocumentType::CREDIT_NOTE === $doc->type ? 'creditnote' : 'invoice',
				'invoice'      => $this->invoice( $doc ),
			),
		);

		return $body;
	}

	/**
	 * Receiver routing. Prefer a Peppol electronic address; fall back to email so
	 * a sandbox test can still be delivered to a mailbox.
	 *
	 * @param Document $doc Document.
	 * @return array<string,mixed>
	 */
	private function routing( Document $doc ): array {
		$customer = $doc->customer;
		$routing  = array();

		$scheme     = (string) ( $customer['electronic_address_scheme'] ?? '' );
		$identifier = (string) ( $customer['electronic_address'] ?? '' );
		if ( '' !== $scheme && '' !== $identifier ) {
			$routing['eIdentifiers'] = array(
				array(
					'scheme'     => $scheme,
					'identifier' => $identifier,
				),
			);
		}

		$email = (string) ( $customer['email'] ?? '' );
		if ( empty( $routing['eIdentifiers'] ) && '' !== $email ) {
			$routing['emails'] = array( $email );
		}

		return $routing;
	}

	/**
	 * The invoice object.
	 *
	 * @param Document $doc Document.
	 * @return array<string,mixed>
	 */
	private function invoice( Document $doc ): array {
		return array(
			'invoiceNumber'           => $doc->number,
			'issueDate'               => $doc->issue_date,
			'documentCurrencyCode'    => $doc->currency,
			'taxSystem'               => 'tax_line_percentages',
			'amountIncludingTax'      => $this->decimal( (int) ( $doc->totals['gross'] ?? 0 ), $doc->currency ),
			'accountingSupplierParty' => array( 'party' => $this->party( $doc->merchant ) ),
			'accountingCustomerParty' => array( 'party' => $this->party( $doc->customer ) ),
			'invoiceLines'            => $this->lines( $doc ),
			'taxSubtotals'            => $this->tax_subtotals( $doc ),
		);
	}

	/**
	 * A Storecove party from a core party snapshot.
	 *
	 * @param array<string,mixed> $p Party snapshot.
	 * @return array<string,mixed>
	 */
	private function party( array $p ): array {
		$party = array(
			'companyName' => (string) ( $p['legal_name'] ?? $p['name'] ?? '' ),
			'address'     => array(
				'street1' => (string) ( $p['line1'] ?? '' ),
				'street2' => (string) ( $p['line2'] ?? '' ),
				'city'    => (string) ( $p['city'] ?? '' ),
				'zip'     => (string) ( $p['postal_code'] ?? '' ),
				'county'  => (string) ( $p['region'] ?? '' ),
				'country' => (string) ( $p['country'] ?? '' ),
			),
		);

		$tax_id = (string) ( $p['tax_id'] ?? '' );
		if ( '' !== $tax_id ) {
			// Verify: Storecove expects tax registrations with a country + number.
			$party['taxRegistrations'] = array(
				array(
					'taxRegistrationNumber' => $tax_id,
					'country'               => (string) ( $p['country'] ?? '' ),
				),
			);
		}

		$scheme     = (string) ( $p['electronic_address_scheme'] ?? '' );
		$identifier = (string) ( $p['electronic_address'] ?? '' );
		if ( '' !== $scheme && '' !== $identifier ) {
			$party['publicIdentifiers'] = array(
				array(
					'scheme' => $scheme,
					'id'     => $identifier,
				),
			);
		}

		$email = (string) ( $p['email'] ?? '' );
		if ( '' !== $email ) {
			$party['contact'] = array( 'email' => $email );
		}

		return $party;
	}

	/**
	 * Invoice lines.
	 *
	 * @param Document $doc Document.
	 * @return array<int,array<string,mixed>>
	 */
	private function lines( Document $doc ): array {
		$out = array();
		foreach ( $doc->lines as $i => $line ) {
			$out[] = array(
				'lineId'             => (string) ( $line['source_ref'] ?? ( $i + 1 ) ),
				'name'               => (string) ( $line['description'] ?? '' ),
				'quantity'           => (float) ( $line['quantity'] ?? 0 ),
				'unitCode'           => (string) ( $line['unit_code'] ?? 'EA' ),
				'amountExcludingTax' => $this->decimal( (int) ( $line['line_net'] ?? 0 ), $doc->currency ),
				'tax'                => array(
					'percentage' => (float) ( $line['tax_rate_percent'] ?? 0 ),
					'category'   => $this->tax_category( (string) ( $line['tax_category'] ?? 'standard' ) ),
					'country'    => (string) ( $doc->merchant['country'] ?? '' ),
				),
			);
		}
		return $out;
	}

	/**
	 * Tax subtotals grouped by rate.
	 *
	 * @param Document $doc Document.
	 * @return array<int,array<string,mixed>>
	 */
	private function tax_subtotals( Document $doc ): array {
		$out = array();
		foreach ( $doc->tax_lines as $tax ) {
			$out[] = array(
				'taxableAmount' => $this->decimal( (int) ( $tax['taxable_base'] ?? 0 ), $doc->currency ),
				'taxAmount'     => $this->decimal( (int) ( $tax['tax_amount'] ?? 0 ), $doc->currency ),
				'percentage'    => (float) ( $tax['rate_percent'] ?? 0 ),
				'category'      => $this->tax_category( (string) ( $tax['category'] ?? 'standard' ) ),
				'country'       => (string) ( $doc->merchant['country'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Map the core tax category to Storecove's vocabulary (verify exact values).
	 *
	 * @param string $category Core category.
	 * @return string
	 */
	private function tax_category( string $category ): string {
		$map = array(
			'standard'       => 'standard',
			'zero'           => 'zero_rated',
			'exempt'         => 'exempt',
			'reverse_charge' => 'reverse_charge',
		);
		return $map[ $category ] ?? 'standard';
	}

	/**
	 * Convert minor units to a Storecove decimal amount. Zero-decimal currencies
	 * (e.g. JPY) are passed through as integers.
	 *
	 * @param int    $minor    Amount in minor units (signed).
	 * @param string $currency ISO currency.
	 * @return float
	 */
	private function decimal( int $minor, string $currency ): float {
		$zero_decimal = array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF', 'XOF', 'XAF' );
		if ( in_array( strtoupper( $currency ), $zero_decimal, true ) ) {
			return (float) $minor;
		}
		return round( $minor / 100, 2 );
	}
}
