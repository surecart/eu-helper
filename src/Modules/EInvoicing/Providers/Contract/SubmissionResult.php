<?php
/**
 * Normalized result of a provider submission / status check.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Contract;

use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * What an adapter returns from submit()/fetch_status()/interpret_webhook(). The
 * core branches only on these normalized fields — never on provider JSON. `raw`
 * holds the opaque provider blob for audit/debug only and must never drive core
 * logic.
 */
class SubmissionResult {

	/** @var string Provider key. */
	public $provider_key = '';

	/** @var string|null Provider submission GUID. */
	public $provider_guid = null;

	/** @var string|null Secondary provider reference. */
	public $provider_document_id = null;

	/** @var string Mapped core status (DocumentStatus::*). */
	public $status = DocumentStatus::SUBMITTED;

	/** @var string|null Normalized error code. */
	public $error_code = null;

	/** @var string|null Human-readable error message. */
	public $error_message = null;

	/** @var bool Whether a failure is transient (retryable) vs permanent (reject). */
	public $retryable = false;

	/** @var array<string,mixed> Opaque provider payload, stored for audit only. */
	public $raw = array();

	/**
	 * Build a successful submission result.
	 *
	 * @param string              $provider_key Provider key.
	 * @param string              $guid         Provider GUID.
	 * @param string              $status       Core status (defaults submitted).
	 * @param array<string,mixed> $raw          Raw provider response.
	 * @return self
	 */
	public static function success( string $provider_key, string $guid, string $status = DocumentStatus::SUBMITTED, array $raw = array() ): self {
		$r                = new self();
		$r->provider_key  = $provider_key;
		$r->provider_guid = $guid;
		$r->status        = $status;
		$r->raw           = $raw;
		return $r;
	}

	/**
	 * Build a transient failure (eligible for retry).
	 *
	 * @param string              $provider_key Provider key.
	 * @param string              $code         Error code.
	 * @param string              $message      Error message.
	 * @param array<string,mixed> $raw          Raw provider response.
	 * @return self
	 */
	public static function transient_failure( string $provider_key, string $code, string $message, array $raw = array() ): self {
		$r                = new self();
		$r->provider_key  = $provider_key;
		$r->status        = DocumentStatus::FAILED;
		$r->error_code    = $code;
		$r->error_message = $message;
		$r->retryable     = true;
		$r->raw           = $raw;
		return $r;
	}

	/**
	 * Build a permanent rejection (do not retry).
	 *
	 * @param string              $provider_key Provider key.
	 * @param string              $code         Error code.
	 * @param string              $message      Error message.
	 * @param array<string,mixed> $raw          Raw provider response.
	 * @return self
	 */
	public static function rejected( string $provider_key, string $code, string $message, array $raw = array() ): self {
		$r                = new self();
		$r->provider_key  = $provider_key;
		$r->status        = DocumentStatus::REJECTED;
		$r->error_code    = $code;
		$r->error_message = $message;
		$r->retryable     = false;
		$r->raw           = $raw;
		return $r;
	}
}
