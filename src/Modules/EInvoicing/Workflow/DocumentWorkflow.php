<?php
/**
 * The single gateway for advancing a document's status.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Workflow;

use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentTable;
use SureCartEuHelper\Modules\EInvoicing\Persistence\EventsTable;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing else mutates a document's status directly. Every move goes through
 * transition(), which reads the authoritative current status from the row (so it
 * is race-safe), refuses illegal/backward edges, sets the relevant timestamp,
 * applies any side columns, and records an event. This mirrors how the Right of
 * Withdrawal module funnels status changes through LogTable::update_status.
 */
final class DocumentWorkflow {

	/**
	 * Attempt to advance a document to a new status.
	 *
	 * @param int    $id  Document id.
	 * @param string $to  Target status (DocumentStatus::*).
	 * @param array  $ctx Optional: 'columns' (extra row columns to set),
	 *                    'message', 'context' (event context array), 'actor',
	 *                    'event_type' (defaults to the target status).
	 * @return bool True if the document is at $to afterwards.
	 */
	public static function transition( int $id, string $to, array $ctx = array() ): bool {
		$row = DocumentTable::find( $id );
		if ( ! $row ) {
			return false;
		}

		$from = (string) $row['status'];
		if ( $from === $to ) {
			return true; // Idempotent no-op.
		}

		if ( ! DocumentStatus::can_transition( $from, $to ) ) {
			EventsTable::record(
				$id,
				'transition_blocked',
				array(
					'from_status' => $from,
					'to_status'   => $to,
					'message'     => 'Illegal transition refused.',
				)
			);
			return false;
		}

		$columns           = isset( $ctx['columns'] ) && is_array( $ctx['columns'] ) ? $ctx['columns'] : array();
		$columns['status'] = $to;

		if ( DocumentStatus::SUBMITTED === $to && empty( $columns['submitted_at'] ) ) {
			$columns['submitted_at'] = current_time( 'mysql' );
		}
		if ( DocumentStatus::DELIVERED === $to && empty( $columns['delivered_at'] ) ) {
			$columns['delivered_at'] = current_time( 'mysql' );
		}

		DocumentTable::update( $id, $columns );

		EventsTable::record(
			$id,
			(string) ( $ctx['event_type'] ?? $to ),
			array(
				'from_status' => $from,
				'to_status'   => $to,
				'actor'       => $ctx['actor'] ?? null,
				'message'     => $ctx['message'] ?? null,
				'context'     => $ctx['context'] ?? null,
			)
		);

		return true;
	}
}
