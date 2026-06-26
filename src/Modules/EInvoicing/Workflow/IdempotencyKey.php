<?php
/**
 * Deterministic idempotency-key derivation.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Workflow;

use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the local, human-readable idempotency key stored uniquely on each
 * document. The key answers "is there already a document for this exact source
 * event?" — so the paid hook firing twice, or a poller racing a manual action,
 * can never create two documents (a UNIQUE index on the column enforces it).
 *
 * The key includes the environment so a sandbox test and a production send are
 * distinct documents. This local key is separate from the per-submission GUID we
 * send to the provider as its idempotency token (see SubmissionService).
 */
final class IdempotencyKey {

	/**
	 * Key for an invoice generated from an order.
	 *
	 * @param string $order_id    SureCart order id.
	 * @param string $environment Environment::*.
	 * @return string
	 */
	public static function for_invoice( string $order_id, string $environment ): string {
		return self::build( DocumentType::INVOICE, $environment, array( $order_id ) );
	}

	/**
	 * Key for a credit note generated from a refund.
	 *
	 * @param string      $order_id    SureCart order id.
	 * @param string|null $refund_id   SureCart refund id (null/empty for a full
	 *                                 manual refund with no id).
	 * @param string      $environment Environment::*.
	 * @return string
	 */
	public static function for_credit_note( string $order_id, ?string $refund_id, string $environment ): string {
		$ref = ( null !== $refund_id && '' !== $refund_id ) ? $refund_id : ( 'full:' . $order_id );
		return self::build( DocumentType::CREDIT_NOTE, $environment, array( $ref ) );
	}

	/**
	 * Key for a manual credit note that has no SureCart refund id (e.g. a
	 * merchant-initiated adjustment). Includes a caller-supplied discriminator so
	 * multiple manual credit notes against one order stay distinct.
	 *
	 * @param string $order_id      SureCart order id.
	 * @param string $discriminator Stable hash of the manual line/amount/time.
	 * @param string $environment   Environment::*.
	 * @return string
	 */
	public static function for_manual_credit_note( string $order_id, string $discriminator, string $environment ): string {
		return self::build( DocumentType::CREDIT_NOTE, $environment, array( 'manual', $order_id, $discriminator ) );
	}

	/**
	 * Assemble a colon-delimited key, e.g. "inv:sandbox:ord_123".
	 *
	 * @param string   $type        DocumentType::*.
	 * @param string   $environment Environment::*.
	 * @param string[] $parts       Discriminating parts.
	 * @return string
	 */
	private static function build( string $type, string $environment, array $parts ): string {
		$prefix = DocumentType::CREDIT_NOTE === $type ? 'cn' : 'inv';
		return implode( ':', array_merge( array( $prefix, $environment ), $parts ) );
	}
}
