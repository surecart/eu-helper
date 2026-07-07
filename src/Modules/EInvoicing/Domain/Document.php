<?php
/**
 * The normalized, provider-agnostic invoice/credit-note document.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A point-in-time compliance document, independent of both SureCart and any
 * provider. Built by SureCartOrderMapper (invoices) or CreditNoteFactory (credit
 * notes); consumed by a provider adapter, which alone knows how to turn it into
 * the network's wire format.
 *
 * Sign convention (decided once, here): credit-note line quantities, line totals
 * and document totals are stored NEGATIVE. Whether the network wants negatives or
 * a positive CreditNote document is the adapter's concern, never the core's.
 *
 * Amounts are integer minor units (see {@see Money}). Nested parts (lines, tax
 * lines, parties, totals) are plain arrays with the shapes documented by the
 * static builders below — matching the array-centric style used elsewhere in the
 * plugin (e.g. normalized line items in CustomerContext).
 */
class Document {

	/** @var int|null Local DB id (null until persisted). */
	public $id = null;

	/** @var string One of DocumentType::*. */
	public $type = DocumentType::INVOICE;

	/** @var string Local document number (sequential per series). */
	public $number = '';

	/** @var string Issue date, Y-m-d. */
	public $issue_date = '';

	/** @var string ISO-4217 currency code (uppercase). */
	public $currency = '';

	/** @var string One of DocumentStatus::*. */
	public $status = DocumentStatus::DRAFT;

	/** @var string Originating SureCart order id. */
	public $sc_order_id = '';

	/** @var string Human SureCart order number (e.g. "0003"), for display. */
	public $sc_order_number = '';

	/** @var string|null SureCart refund id (credit notes only). */
	public $sc_refund_id = null;

	/** @var string|null SureCart customer id. */
	public $sc_customer_id = null;

	/** @var int|null Local invoice document a credit note derives from. */
	public $original_document_id = null;

	/** @var string Active provider key (e.g. "storecove"). */
	public $provider_key = '';

	/** @var string|null Provider submission GUID. */
	public $provider_guid = null;

	/** @var string|null Secondary provider reference. */
	public $provider_document_id = null;

	/** @var string Environment the document belongs to (Environment::*). */
	public $environment = Environment::SANDBOX;

	/** @var string Deterministic idempotency key (unique per document). */
	public $idempotency_key = '';

	/** @var array<string,mixed> Merchant (seller) party snapshot. */
	public $merchant = array();

	/** @var array<string,mixed> Customer (buyer) party snapshot. */
	public $customer = array();

	/** @var array<int,array<string,mixed>> Document lines. */
	public $lines = array();

	/** @var array<int,array<string,mixed>> Tax lines grouped by rate. */
	public $tax_lines = array();

	/** @var array<string,int> Totals { net, tax, gross } in minor units. */
	public $totals = array(
		'net'   => 0,
		'tax'   => 0,
		'gross' => 0,
	);

	/**
	 * Build a party (merchant or customer) snapshot array.
	 *
	 * @param array<string,mixed> $data Partial party data.
	 * @return array<string,mixed>
	 */
	public static function party( array $data = array() ): array {
		return array(
			'name'                      => (string) ( $data['name'] ?? '' ),
			'legal_name'                => isset( $data['legal_name'] ) ? (string) $data['legal_name'] : null,
			'tax_id'                    => isset( $data['tax_id'] ) ? (string) $data['tax_id'] : null,
			'email'                     => isset( $data['email'] ) ? (string) $data['email'] : null,
			'country'                   => isset( $data['country'] ) ? strtoupper( (string) $data['country'] ) : null,
			'line1'                     => isset( $data['line1'] ) ? (string) $data['line1'] : null,
			'line2'                     => isset( $data['line2'] ) ? (string) $data['line2'] : null,
			'city'                      => isset( $data['city'] ) ? (string) $data['city'] : null,
			'postal_code'               => isset( $data['postal_code'] ) ? (string) $data['postal_code'] : null,
			'region'                    => isset( $data['region'] ) ? (string) $data['region'] : null,
			// Peppol routing identity (provider-agnostic strings).
			'electronic_address'        => isset( $data['electronic_address'] ) ? (string) $data['electronic_address'] : null,
			'electronic_address_scheme' => isset( $data['electronic_address_scheme'] ) ? (string) $data['electronic_address_scheme'] : null,
		);
	}

	/**
	 * Build a document line array.
	 *
	 * @param array<string,mixed> $data Partial line data.
	 * @return array<string,mixed>
	 */
	public static function line( array $data = array() ): array {
		return array(
			'source_ref'       => (string) ( $data['source_ref'] ?? '' ),
			'product_ref'      => isset( $data['product_ref'] ) ? (string) $data['product_ref'] : null,
			'description'      => (string) ( $data['description'] ?? '' ),
			'quantity'         => (float) ( $data['quantity'] ?? 0 ),
			'unit_code'        => (string) ( $data['unit_code'] ?? 'EA' ),
			'unit_price'       => (int) ( $data['unit_price'] ?? 0 ),
			'line_net'         => (int) ( $data['line_net'] ?? 0 ),
			'tax_rate_percent' => (float) ( $data['tax_rate_percent'] ?? 0 ),
			'tax_category'     => (string) ( $data['tax_category'] ?? 'standard' ),
		);
	}

	/**
	 * Build a tax line array.
	 *
	 * @param array<string,mixed> $data Partial tax-line data.
	 * @return array<string,mixed>
	 */
	public static function tax_line( array $data = array() ): array {
		return array(
			'rate_percent'     => (float) ( $data['rate_percent'] ?? 0 ),
			'category'         => (string) ( $data['category'] ?? 'standard' ),
			'taxable_base'     => (int) ( $data['taxable_base'] ?? 0 ),
			'tax_amount'       => (int) ( $data['tax_amount'] ?? 0 ),
			'exemption_reason' => isset( $data['exemption_reason'] ) ? (string) $data['exemption_reason'] : null,
		);
	}

	/**
	 * Serialise to a plain array (for persistence snapshots).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                   => $this->id,
			'type'                 => $this->type,
			'number'               => $this->number,
			'issue_date'           => $this->issue_date,
			'currency'             => $this->currency,
			'status'               => $this->status,
			'sc_order_id'          => $this->sc_order_id,
			'sc_order_number'      => $this->sc_order_number,
			'sc_refund_id'         => $this->sc_refund_id,
			'sc_customer_id'       => $this->sc_customer_id,
			'original_document_id' => $this->original_document_id,
			'provider_key'         => $this->provider_key,
			'provider_guid'        => $this->provider_guid,
			'provider_document_id' => $this->provider_document_id,
			'environment'          => $this->environment,
			'idempotency_key'      => $this->idempotency_key,
			'merchant'             => $this->merchant,
			'customer'             => $this->customer,
			'lines'                => $this->lines,
			'tax_lines'            => $this->tax_lines,
			'totals'               => $this->totals,
		);
	}

	/**
	 * Hydrate from a plain array.
	 *
	 * @param array<string,mixed> $data Serialised document.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$doc = new self();
		foreach ( $data as $k => $v ) {
			if ( property_exists( $doc, $k ) ) {
				$doc->$k = $v;
			}
		}
		return $doc;
	}
}
