<?php
/**
 * Normalized delivery-evidence result.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery evidence retrieved from a provider (e.g. Storecove
 * GET /document_submissions/{guid}/evidence), normalized for storage/download.
 */
class EvidenceResult {

	/** @var bool Whether evidence is available. */
	public $available = false;

	/** @var string|null MIME type (e.g. application/pdf, application/json). */
	public $mime_type = null;

	/** @var string|null Evidence contents (text or base64). */
	public $contents = null;

	/** @var string|null Reason when unavailable. */
	public $reason = null;

	/** @var array<string,mixed> Opaque provider payload. */
	public $raw = array();

	/**
	 * Build an available-evidence result.
	 *
	 * @param string $mime_type MIME type.
	 * @param string $contents  Contents.
	 * @param array  $raw       Raw provider payload.
	 * @return self
	 */
	public static function found( string $mime_type, string $contents, array $raw = array() ): self {
		$e            = new self();
		$e->available = true;
		$e->mime_type = $mime_type;
		$e->contents  = $contents;
		$e->raw       = $raw;
		return $e;
	}

	/**
	 * Build an unavailable-evidence result.
	 *
	 * @param string $reason Why.
	 * @return self
	 */
	public static function none( string $reason ): self {
		$e         = new self();
		$e->reason = $reason;
		return $e;
	}
}
