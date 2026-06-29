<?php
/**
 * Maps Document value objects to and from `sceu_einv_documents` rows.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Persistence;

use SureCartEuHelper\Modules\EInvoicing\Domain\Document;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that knows how a Document's parts map onto table columns:
 * scalar identity/lifecycle fields to their own columns, and the merchant /
 * customer / lines / tax / totals to the immutable *_snapshot JSON columns. A
 * persisted document is never re-derived from live SureCart data — these
 * snapshots are the source of truth for later credit notes.
 */
class DocumentRepository {

	/**
	 * Persist a new Document. On success the Document's id is populated and it is
	 * returned. If an identical document already exists (UNIQUE idempotency_key),
	 * the existing row is hydrated and returned instead — the duplicate guard.
	 *
	 * @param Document $doc Document to insert.
	 * @return Document|null The persisted (or pre-existing) document, or null on hard failure.
	 */
	public function insert( Document $doc ): ?Document {
		$id = DocumentTable::insert(
			array(
				'document_type'        => $doc->type,
				'status'               => $doc->status,
				'document_number'      => $doc->number,
				'surecart_order_id'    => $doc->sc_order_id,
				'surecart_refund_id'   => $doc->sc_refund_id,
				'surecart_customer_id' => $doc->sc_customer_id,
				'provider_key'         => $doc->provider_key,
				'original_document_id' => $doc->original_document_id,
				'environment'          => $doc->environment,
				'currency'             => $doc->currency,
				'gross_minor'          => (int) ( $doc->totals['gross'] ?? 0 ),
				// Stash the human order number alongside the totals snapshot for display
				// (the row only stores the order id, which isn't merchant-facing).
				'totals_snapshot'      => array_merge( $doc->totals, array( 'order_number' => $doc->sc_order_number ) ),
				'tax_snapshot'         => $doc->tax_lines,
				'merchant_snapshot'    => $doc->merchant,
				'customer_snapshot'    => $doc->customer,
				'line_items_snapshot'  => $doc->lines,
				'idempotency_key'      => $doc->idempotency_key,
			)
		);

		if ( $id > 0 ) {
			$doc->id = $id;
			return $doc;
		}

		// Insert failed — most likely the UNIQUE idempotency_key already exists.
		return $this->find_by_idempotency( $doc->idempotency_key );
	}

	/**
	 * Hydrate a Document from a raw table row.
	 *
	 * @param array<string,mixed> $row Row (ARRAY_A).
	 * @return Document
	 */
	public function hydrate( array $row ): Document {
		$doc                       = new Document();
		$doc->id                   = isset( $row['id'] ) ? (int) $row['id'] : null;
		$doc->type                 = (string) ( $row['document_type'] ?? 'invoice' );
		$doc->number               = (string) ( $row['document_number'] ?? '' );
		$doc->currency             = (string) ( $row['currency'] ?? '' );
		$doc->status               = (string) ( $row['status'] ?? 'draft' );
		$doc->sc_order_id          = (string) ( $row['surecart_order_id'] ?? '' );
		$doc->sc_refund_id         = isset( $row['surecart_refund_id'] ) ? (string) $row['surecart_refund_id'] : null;
		$doc->sc_customer_id       = isset( $row['surecart_customer_id'] ) ? (string) $row['surecart_customer_id'] : null;
		$doc->original_document_id = isset( $row['original_document_id'] ) ? (int) $row['original_document_id'] : null;
		$doc->provider_key         = (string) ( $row['provider_key'] ?? '' );
		$doc->provider_guid        = isset( $row['provider_guid'] ) ? (string) $row['provider_guid'] : null;
		$doc->provider_document_id = isset( $row['provider_document_id'] ) ? (string) $row['provider_document_id'] : null;
		$doc->environment          = (string) ( $row['environment'] ?? 'sandbox' );
		$doc->idempotency_key      = (string) ( $row['idempotency_key'] ?? '' );
		$doc->merchant             = $this->decode( $row['merchant_snapshot'] ?? '' );
		$doc->customer             = $this->decode( $row['customer_snapshot'] ?? '' );
		$doc->lines                = $this->decode( $row['line_items_snapshot'] ?? '' );
		$doc->tax_lines            = $this->decode( $row['tax_snapshot'] ?? '' );
		$totals                    = $this->decode( $row['totals_snapshot'] ?? '' );
		$doc->totals               = array(
			'net'   => (int) ( $totals['net'] ?? 0 ),
			'tax'   => (int) ( $totals['tax'] ?? 0 ),
			'gross' => (int) ( $totals['gross'] ?? ( $row['gross_minor'] ?? 0 ) ),
		);
		$doc->sc_order_number      = (string) ( $totals['order_number'] ?? '' );

		return $doc;
	}

	/**
	 * Find and hydrate by id.
	 *
	 * @param int $id Document id.
	 * @return Document|null
	 */
	public function find( int $id ): ?Document {
		$row = DocumentTable::find( $id );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Find and hydrate by idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return Document|null
	 */
	public function find_by_idempotency( string $key ): ?Document {
		$row = DocumentTable::find_by_idempotency( $key );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Decode a JSON snapshot column to an array.
	 *
	 * @param mixed $raw Raw column value.
	 * @return array<string,mixed>
	 */
	private function decode( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
