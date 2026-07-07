<?php
/**
 * The contract every e-invoicing provider adapter implements.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Contract;

use SureCartEuHelper\Modules\EInvoicing\Domain\Document;

defined( 'ABSPATH' ) || exit;

/**
 * The boundary between the provider-agnostic core and a specific network/service
 * provider. Adding a provider means writing one class that implements this and
 * registering it on `sceu_register_invoice_providers` — no core changes.
 *
 * The core hands the adapter a {@see Document} and receives normalized result
 * objects ({@see SubmissionResult}, {@see ReceiverCapability}, {@see EvidenceResult}).
 * The adapter alone knows the provider's wire format, auth, error codes, and
 * status vocabulary; none of that leaks back into the core.
 */
interface ProviderAdapterInterface {

	/**
	 * Stable machine key, e.g. "storecove". Persisted on each document.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Human label for the provider picker, e.g. "Storecove (Peppol)".
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Provider credential/option fields, in the plugin's settings-field schema so
	 * the admin UI renders them generically. Rendered under the provider's
	 * connection card. Secret fields should set 'secret' => true so they are
	 * stored outside the autoloaded settings option.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings_fields(): array;

	/**
	 * Sender-identity fields the provider needs (e.g. Storecove legalEntityId,
	 * Peppol scheme + identifier), same schema shape. Kept separate from
	 * settings_fields() so the core can treat "who am I on the network"
	 * generically across providers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sender_fields(): array;

	/**
	 * Select the active environment (Environment::SANDBOX|PRODUCTION) before any
	 * network call.
	 *
	 * @param string $environment Environment::*.
	 * @return void
	 */
	public function set_environment( string $environment ): void;

	/**
	 * Whether the configured credentials look complete enough to attempt calls.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Discover whether a receiver can receive a document type on the network.
	 *
	 * @param array<string,mixed> $customer_party Customer party snapshot.
	 * @param string              $document_type  DocumentType::*.
	 * @return ReceiverCapability
	 */
	public function can_receive( array $customer_party, string $document_type ): ReceiverCapability;

	/**
	 * Submit a finalized document. Implementations MUST send $idempotency_token as
	 * the provider's idempotency key so retries never double-send.
	 *
	 * @param Document $document          Document to send.
	 * @param string   $idempotency_token Per-submission idempotency token (GUID).
	 * @return SubmissionResult
	 */
	public function submit( Document $document, string $idempotency_token ): SubmissionResult;

	/**
	 * Poll current delivery status for a previously submitted document.
	 *
	 * @param string $provider_guid Provider submission GUID.
	 * @return SubmissionResult
	 */
	public function fetch_status( string $provider_guid ): SubmissionResult;

	/**
	 * Fetch delivery evidence for a submitted document.
	 *
	 * @param string $provider_guid Provider submission GUID.
	 * @return EvidenceResult
	 */
	public function fetch_evidence( string $provider_guid ): EvidenceResult;

	/**
	 * Verify the signature/authenticity of an inbound webhook request. Used by the
	 * webhook receiver (PR 3). Implementations with no webhook return false.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return bool
	 */
	public function verify_webhook( \WP_REST_Request $request ): bool;

	/**
	 * Normalize a verified inbound webhook payload into a status update, or null
	 * if it carries nothing actionable.
	 *
	 * @param array<string,mixed> $payload Decoded webhook body.
	 * @return SubmissionResult|null
	 */
	public function interpret_webhook( array $payload ): ?SubmissionResult;
}
