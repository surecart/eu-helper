<?php
/**
 * Maps Storecove submission/evidence states to the core document status.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Storecove;

use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Quarantines Storecove's status vocabulary. The exact set of submission/evidence
 * statuses and webhook event names must be confirmed against the live Storecove
 * API (verify); this map is conservative — anything it doesn't recognise leaves
 * the document in its current submitted state rather than guessing.
 */
final class StorecoveStatusMap {

	/**
	 * Map a Storecove submission/evidence status string to a core DocumentStatus,
	 * or null when it carries nothing actionable.
	 *
	 * @param string $status Provider status (lowercased by caller is fine).
	 * @return string|null DocumentStatus::* or null.
	 */
	public static function to_core( string $status ): ?string {
		switch ( strtolower( trim( $status ) ) ) {
			case 'delivered':
			case 'sent':
			case 'ok':
				return DocumentStatus::DELIVERED;

			case 'failed':
			case 'error':
			case 'rejected':
			case 'invalid':
				return DocumentStatus::REJECTED;

			case 'processing':
			case 'pending':
			case 'queued':
			case 'in_progress':
				return DocumentStatus::SUBMITTED;
		}

		return null;
	}

	/**
	 * Map a Storecove webhook event_type to a core status (PR 3). Verify the
	 * exact event names against Storecove's webhook docs.
	 *
	 * @param string $event_type Webhook event type.
	 * @return string|null
	 */
	public static function from_event( string $event_type ): ?string {
		switch ( strtolower( trim( $event_type ) ) ) {
			case 'invoice.delivered':
			case 'document.delivered':
			case 'delivered':
				return DocumentStatus::DELIVERED;

			case 'invoice.failed':
			case 'document.failed':
			case 'failed':
				return DocumentStatus::REJECTED;
		}

		return null;
	}
}
