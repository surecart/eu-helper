<?php
/**
 * Normalized refund event.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A refund, normalized from SureCart's refund/charge shape, that a credit note
 * is built from. SureCart often exposes only a refund amount (not per-line
 * detail), so `line_quantities` may be empty — the CreditNoteFactory falls back
 * to an amount-based adjustment line in that case.
 */
class RefundEvent {

	/** @var string|null SureCart refund id (may be null for a derived/manual refund). */
	public $refund_id = null;

	/** @var int Refunded amount in minor units (positive). */
	public $amount_minor = 0;

	/** @var string ISO currency code. */
	public $currency = '';

	/** @var bool Whether this fully refunds the order. */
	public $is_full = false;

	/** @var array<string,int> Optional map of SureCart line item id => qty refunded. */
	public $line_quantities = array();

	/** @var string|null Refund reason, if known. */
	public $reason = null;

	/**
	 * Convenience constructor.
	 *
	 * @param array<string,mixed> $data Partial data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$e                  = new self();
		$e->refund_id       = isset( $data['refund_id'] ) ? (string) $data['refund_id'] : null;
		$e->amount_minor    = (int) ( $data['amount_minor'] ?? 0 );
		$e->currency        = strtoupper( (string) ( $data['currency'] ?? '' ) );
		$e->is_full         = ! empty( $data['is_full'] );
		$e->line_quantities = isset( $data['line_quantities'] ) && is_array( $data['line_quantities'] ) ? $data['line_quantities'] : array();
		$e->reason          = isset( $data['reason'] ) ? (string) $data['reason'] : null;
		return $e;
	}
}
