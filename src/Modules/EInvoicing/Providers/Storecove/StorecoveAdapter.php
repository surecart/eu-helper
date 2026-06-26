<?php
/**
 * Storecove provider adapter (Peppol, V2 API). The first provider.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Storecove;

use SureCartEuHelper\Modules\EInvoicing\Secrets;
use SureCartEuHelper\Modules\EInvoicing\ProviderSettings;
use SureCartEuHelper\Modules\EInvoicing\Domain\Document;
use SureCartEuHelper\Modules\EInvoicing\Domain\Environment;
use SureCartEuHelper\Modules\EInvoicing\Providers\Contract\ProviderAdapterInterface;
use SureCartEuHelper\Modules\EInvoicing\Providers\Contract\SubmissionResult;
use SureCartEuHelper\Modules\EInvoicing\Providers\Contract\ReceiverCapability;
use SureCartEuHelper\Modules\EInvoicing\Providers\Contract\EvidenceResult;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the provider contract against Storecove. All Storecove specifics —
 * auth, JSON shape, status vocabulary, error handling — live in this folder and
 * never leak into the core. Adding a different provider means cloning this folder
 * for the new API, not touching the document model or workflows.
 */
class StorecoveAdapter implements ProviderAdapterInterface {

	const KEY = 'storecove';

	/** @var string Active environment (Environment::*). */
	private $environment = Environment::SANDBOX;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return self::KEY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Storecove (Peppol)', 'surecart-eu-helper' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields(): array {
		// Secret credentials — rendered in the connection card and stored outside
		// the autoloaded settings option (see Secrets). One key per environment.
		return array(
			array(
				'key'    => 'api_key',
				'type'   => 'text',
				'secret' => true,
				'label'  => __( 'API key', 'surecart-eu-helper' ),
				'help'   => __( 'Your Storecove API key. Use a sandbox key while testing and a production key when going live.', 'surecart-eu-helper' ),
			),
			array(
				'key'    => 'webhook_secret',
				'type'   => 'text',
				'secret' => true,
				'label'  => __( 'Webhook signing secret', 'surecart-eu-helper' ),
				'help'   => __( 'Used to verify inbound delivery webhooks (optional until webhooks are enabled).', 'surecart-eu-helper' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function sender_fields(): array {
		// Non-secret sender identity — stored in the main settings option.
		return array(
			array(
				'key'     => 'legal_entity_id',
				'type'    => 'text',
				'label'   => __( 'Storecove legal entity ID', 'surecart-eu-helper' ),
				'help'    => __( 'The sender LegalEntity ID from your Storecove account (app.storecove.com). Required to send.', 'surecart-eu-helper' ),
				'section' => 'sender',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_environment( string $environment ): void {
		$this->environment = Environment::normalize( $environment );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key() && $this->legal_entity_id() > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_receive( array $customer_party, string $document_type ): ReceiverCapability {
		$scheme     = (string) ( $customer_party['electronic_address_scheme'] ?? '' );
		$identifier = (string) ( $customer_party['electronic_address'] ?? '' );
		if ( '' === $scheme || '' === $identifier ) {
			return ReceiverCapability::no( __( 'No Peppol electronic address on file for the customer.', 'surecart-eu-helper' ) );
		}

		$result = $this->client()->post(
			'/discovery/receives',
			array(
				'documentTypes' => array( 'invoice' ),
				'network'       => 'peppol',
				'metaScheme'    => 'iso6523-actorid-upis',
				'scheme'        => $scheme,
				'identifier'    => $identifier,
			)
		);

		if ( ! $result['ok'] ) {
			return ReceiverCapability::no( $result['error'] );
		}

		$json = $result['json'];
		$ok   = ( isset( $json['code'] ) && 'OK' === $json['code'] ) || ! empty( $json['exists'] );
		if ( $ok ) {
			$types = isset( $json['processes'] ) && is_array( $json['processes'] ) ? $json['processes'] : array( 'invoice' );
			return ReceiverCapability::yes( 'peppol', $types );
		}

		return ReceiverCapability::no( __( 'Receiver is not reachable on Peppol.', 'surecart-eu-helper' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function submit( Document $document, string $idempotency_token ): SubmissionResult {
		if ( '' === $this->api_key() ) {
			return SubmissionResult::rejected( self::KEY, 'not_configured', __( 'No Storecove API key is set for this environment.', 'surecart-eu-helper' ) );
		}
		if ( $this->legal_entity_id() <= 0 ) {
			return SubmissionResult::rejected( self::KEY, 'no_legal_entity', __( 'No Storecove legal entity ID is set.', 'surecart-eu-helper' ) );
		}

		$payload = ( new StorecoveDocumentMapper() )->to_submission( $document, $this->legal_entity_id(), $idempotency_token );
		$result  = $this->client()->post( '/document_submissions', $payload );

		// Stash the request payload so the document log can show exactly what we sent.
		$raw = array(
			'request'  => $payload,
			'response' => $result['json'],
			'code'     => $result['code'],
		);

		if ( $result['ok'] ) {
			$guid = (string) ( $result['json']['guid'] ?? '' );
			if ( '' === $guid ) {
				return SubmissionResult::transient_failure( self::KEY, 'no_guid', __( 'Storecove accepted the request but returned no GUID.', 'surecart-eu-helper' ), $raw );
			}
			return SubmissionResult::success( self::KEY, $guid, \SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus::SUBMITTED, $raw );
		}

		if ( ! empty( $result['transient'] ) ) {
			return SubmissionResult::transient_failure( self::KEY, 'http_' . $result['code'], $result['error'], $raw );
		}

		return SubmissionResult::rejected( self::KEY, 'http_' . $result['code'], $result['error'], $raw );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_status( string $provider_guid ): SubmissionResult {
		$result = $this->client()->get( '/document_submissions/' . rawurlencode( $provider_guid ) );

		if ( ! $result['ok'] ) {
			if ( ! empty( $result['transient'] ) ) {
				return SubmissionResult::transient_failure( self::KEY, 'http_' . $result['code'], $result['error'], $result['json'] );
			}
			return SubmissionResult::rejected( self::KEY, 'http_' . $result['code'], $result['error'], $result['json'] );
		}

		$status = (string) ( $result['json']['status'] ?? '' );
		$mapped = StorecoveStatusMap::to_core( $status );
		$out    = SubmissionResult::success( self::KEY, $provider_guid, $mapped ?? \SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus::SUBMITTED, $result['json'] );
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_evidence( string $provider_guid ): EvidenceResult {
		$result = $this->client()->get( '/document_submissions/' . rawurlencode( $provider_guid ) . '/evidence' );
		if ( ! $result['ok'] ) {
			return EvidenceResult::none( $result['error'] );
		}
		return EvidenceResult::found( 'application/json', $result['raw'], $result['json'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Webhooks land in PR 3; until a signing secret is configured we reject
	 * verification so nothing can be spoofed.
	 */
	public function verify_webhook( \WP_REST_Request $request ): bool {
		$secret = Secrets::get( Secrets::key( self::KEY, $this->environment, 'webhook_secret' ) );
		if ( '' === $secret ) {
			return false;
		}
		// Verify: confirm Storecove's signature scheme + header name before relying
		// on this. Conservative default (deny) until implemented in PR 3.
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function interpret_webhook( array $payload ): ?SubmissionResult {
		$event = (string) ( $payload['event_type'] ?? $payload['event'] ?? '' );
		$status = StorecoveStatusMap::from_event( $event );
		if ( null === $status ) {
			return null;
		}
		$guid = (string) ( $payload['guid'] ?? '' );
		$out  = new SubmissionResult();
		$out->provider_key  = self::KEY;
		$out->provider_guid = '' !== $guid ? $guid : null;
		$out->status        = $status;
		$out->raw           = $payload;
		return $out;
	}

	/**
	 * The API key for the active environment.
	 *
	 * @return string
	 */
	private function api_key(): string {
		return Secrets::get( Secrets::key( self::KEY, $this->environment, 'api_key' ) );
	}

	/**
	 * The configured sender legal entity id.
	 *
	 * @return int
	 */
	private function legal_entity_id(): int {
		return (int) ProviderSettings::get( self::KEY, 'legal_entity_id', 0 );
	}

	/**
	 * A client bound to the active environment's API key.
	 *
	 * @return StorecoveClient
	 */
	private function client(): StorecoveClient {
		return new StorecoveClient( $this->api_key() );
	}
}
