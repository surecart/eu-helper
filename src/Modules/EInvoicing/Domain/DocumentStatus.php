<?php
/**
 * Document lifecycle statuses + allowed transitions.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The document state machine.
 *
 *   draft → mapped → validated → queued → submitted → delivered
 *                                   ↘ failed (retryable → back to queued)
 *        {validated|queued|submitted} → rejected (terminal)
 *
 * `DocumentWorkflow` is the only place that advances a document, and it consults
 * the edge table here. `failed` is a retryable holding state (the queue can move
 * it back to `queued`); `delivered` and `rejected` are terminal.
 */
final class DocumentStatus {

	const DRAFT     = 'draft';
	const MAPPED    = 'mapped';
	const VALIDATED = 'validated';
	const QUEUED    = 'queued';
	const SUBMITTED = 'submitted';
	const DELIVERED = 'delivered';
	const FAILED    = 'failed';
	const REJECTED  = 'rejected';

	/**
	 * All statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::MAPPED,
			self::VALIDATED,
			self::QUEUED,
			self::SUBMITTED,
			self::DELIVERED,
			self::FAILED,
			self::REJECTED,
		);
	}

	/**
	 * Terminal statuses that never transition further.
	 *
	 * @return string[]
	 */
	public static function terminal(): array {
		return array( self::DELIVERED, self::REJECTED );
	}

	/**
	 * Allowed transitions: from-status => [to-statuses].
	 *
	 * @return array<string, string[]>
	 */
	public static function transitions(): array {
		return array(
			self::DRAFT     => array( self::MAPPED, self::REJECTED ),
			self::MAPPED    => array( self::VALIDATED, self::REJECTED ),
			self::VALIDATED => array( self::QUEUED, self::REJECTED ),
			self::QUEUED    => array( self::SUBMITTED, self::FAILED, self::REJECTED ),
			self::SUBMITTED => array( self::DELIVERED, self::FAILED, self::REJECTED ),
			self::FAILED    => array( self::QUEUED, self::REJECTED ),
			self::DELIVERED => array(),
			self::REJECTED  => array(),
		);
	}

	/**
	 * Whether a transition from $from to $to is permitted.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		$map = self::transitions();
		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	/**
	 * Whether a status is one we'd consider "sent or beyond" (so we must never
	 * submit again).
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_sent_or_beyond( string $status ): bool {
		return in_array( $status, array( self::SUBMITTED, self::DELIVERED ), true );
	}

	/**
	 * Human-readable label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::DRAFT     => __( 'Draft', 'surecart-eu-helper' ),
			self::MAPPED    => __( 'Mapped', 'surecart-eu-helper' ),
			self::VALIDATED => __( 'Validated', 'surecart-eu-helper' ),
			self::QUEUED    => __( 'Queued', 'surecart-eu-helper' ),
			self::SUBMITTED => __( 'Submitted', 'surecart-eu-helper' ),
			self::DELIVERED => __( 'Delivered', 'surecart-eu-helper' ),
			self::FAILED    => __( 'Failed', 'surecart-eu-helper' ),
			self::REJECTED  => __( 'Rejected', 'surecart-eu-helper' ),
		);
		return $labels[ $status ] ?? $status;
	}
}
