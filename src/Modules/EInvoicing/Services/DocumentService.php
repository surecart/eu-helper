<?php
/**
 * Creates and enqueues invoice documents (idempotently).
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Services;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;
use SureCartEuHelper\Modules\EInvoicing\Mapping\SureCartOrderMapper;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentTable;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentRepository;
use SureCartEuHelper\Modules\EInvoicing\Persistence\EventsTable;
use SureCartEuHelper\Modules\EInvoicing\Workflow\DocumentValidator;
use SureCartEuHelper\Modules\EInvoicing\Workflow\DocumentWorkflow;

defined( 'ABSPATH' ) || exit;

/**
 * The entry point for "make sure there's an invoice for this order". Idempotent:
 * the document's UNIQUE idempotency key means a second call (the paid hook firing
 * twice, or a manual create racing it) returns the existing document rather than
 * creating a duplicate. Honours the `auto_send` setting — generate only, or
 * generate and queue for transmission.
 */
class DocumentService {

	/** @var DocumentRepository */
	private $repo;

	public function __construct() {
		$this->repo = new DocumentRepository();
	}

	/**
	 * Ensure an invoice document exists for an order, optionally enqueueing it.
	 *
	 * @param string    $order_id    SureCart order id.
	 * @param bool|null $send         Override the auto_send setting (null = use setting).
	 * @return array{document_id:int,created:bool,queued:bool,error:string}
	 */
	public function ensure_invoice_for_order( string $order_id, ?bool $send = null ): array {
		DocumentTable::maybe_create();
		EventsTable::maybe_create();

		$result = array(
			'document_id' => 0,
			'created'     => false,
			'queued'      => false,
			'error'       => '',
		);

		$doc = ( new SureCartOrderMapper() )->from_order( $order_id );
		if ( ! $doc ) {
			$result['error'] = __( 'Could not read the order from SureCart.', 'surecart-eu-helper' );
			return $result;
		}

		if ( '' === $doc->provider_key ) {
			$result['error'] = __( 'No e-invoicing provider is selected in the settings.', 'surecart-eu-helper' );
			return $result;
		}

		// Idempotent insert: returns the existing document if one already exists.
		$existing = DocumentTable::find_by_idempotency( $doc->idempotency_key );
		if ( $existing ) {
			$result['document_id'] = (int) $existing['id'];
			return $result;
		}

		$persisted = $this->repo->insert( $doc );
		if ( ! $persisted || ! $persisted->id ) {
			$result['error'] = __( 'Could not save the invoice document.', 'surecart-eu-helper' );
			return $result;
		}

		$id                    = (int) $persisted->id;
		$result['document_id'] = $id;
		$result['created']     = true;

		EventsTable::record( $id, 'created', array( 'to_status' => DocumentStatus::DRAFT, 'message' => __( 'Invoice document created from order.', 'surecart-eu-helper' ) ) );

		// draft → mapped (mapping already succeeded).
		DocumentWorkflow::transition( $id, DocumentStatus::MAPPED, array( 'event_type' => 'mapped' ) );

		// mapped → validated, or stay mapped with the problems recorded.
		$errors = DocumentValidator::validate( $persisted );
		if ( ! empty( $errors ) ) {
			EventsTable::record( $id, 'validation_failed', array( 'context' => array( 'errors' => $errors ) ) );
			$result['error'] = implode( ' ', $errors );
			return $result;
		}
		DocumentWorkflow::transition( $id, DocumentStatus::VALIDATED, array( 'event_type' => 'validated' ) );

		// Submit now if auto_send is on (or explicitly requested).
		$should_send = ( null !== $send ) ? $send : (bool) Settings::get( 'einvoicing', 'auto_send', false );
		if ( $should_send ) {
			$result['queued'] = $this->enqueue( $id );
		}

		return $result;
	}

	/**
	 * Move a validated (or failed) document into the send queue and kick the
	 * sweeper. Used by auto-send and the manual "Submit now" / "Retry" actions.
	 *
	 * @param int $id Document id.
	 * @return bool
	 */
	public function enqueue( int $id ): bool {
		$row = DocumentTable::find( $id );
		if ( ! $row ) {
			return false;
		}
		$status = (string) $row['status'];
		if ( ! in_array( $status, array( DocumentStatus::VALIDATED, DocumentStatus::FAILED ), true ) ) {
			return false;
		}

		$ok = DocumentWorkflow::transition(
			$id,
			DocumentStatus::QUEUED,
			array(
				'event_type' => 'queued',
				'columns'    => array(
					'next_attempt_at' => DocumentTable::utc_now(),
					'locked_at'       => null,
				),
			)
		);

		if ( $ok ) {
			Queue::kick();
		}
		return $ok;
	}
}
