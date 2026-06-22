<?php
/**
 * Normalized receiver-discovery result.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a receiver can be reached on a network for a given document type — the
 * normalized result of an adapter's discovery call (e.g. Storecove
 * POST /discovery/receives).
 */
class ReceiverCapability {

	/** @var bool Whether the receiver can receive the document. */
	public $can_receive = false;

	/** @var string|null Network (e.g. "peppol"). */
	public $network = null;

	/** @var string[] Provider-declared supported document types. */
	public $document_types = array();

	/** @var string|null Reason when !can_receive (or when discovery is unavailable). */
	public $reason = null;

	/**
	 * Build a positive capability.
	 *
	 * @param string   $network        Network.
	 * @param string[] $document_types Supported types.
	 * @return self
	 */
	public static function yes( string $network = 'peppol', array $document_types = array() ): self {
		$c                 = new self();
		$c->can_receive    = true;
		$c->network        = $network;
		$c->document_types = $document_types;
		return $c;
	}

	/**
	 * Build a negative/unknown capability.
	 *
	 * @param string $reason Why.
	 * @return self
	 */
	public static function no( string $reason ): self {
		$c              = new self();
		$c->can_receive = false;
		$c->reason      = $reason;
		return $c;
	}
}
