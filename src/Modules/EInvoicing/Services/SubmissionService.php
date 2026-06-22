<?php
/**
 * Orchestrates submitting a queued document to its provider, idempotently.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Services;

use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentTable;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentRepository;
use SureCartEuHelper\Modules\EInvoicing\Persistence\EventsTable;
use SureCartEuHelper\Modules\EInvoicing\Providers\ProviderRegistry;
use SureCartEuHelper\Modules\EInvoicing\Workflow\DocumentWorkflow;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one document, with two layers of duplicate protection:
 *  - a stable per-document GUID, generated once and persisted before the first
 *    network call, sent to the provider as its idempotency token so a retry
 *    after a lost response can't create a second legal document;
 *  - the state machine: only a `queued` row is sendable, and a row at
 *    `submitted`/`delivered` is never re-sent.
 *
 * Transient failures back off and stay queued (up to max attempts); permanent
 * rejections are terminal. A short circuit-breaker pauses sending during a
 * provider outage so a burst of failures doesn't exhaust the retry budget.
 */
class SubmissionService {

	const MAX_BACKOFF       = 21600; // 6 hours.
	const BASE_BACKOFF      = 120;   // 2 minutes.
	const BREAKER_THRESHOLD = 4;
	const BREAKER_TTL       = 900;   // 15 minutes.

	/** @var DocumentRepository */
	private $repo;

	/** @var ProviderRegistry */
	private $registry;

	public function __construct() {
		$this->repo     = new DocumentRepository();
		$this->registry = new ProviderRegistry();
	}

	/**
	 * Submit a single document by id. The caller (queue) must already have
	 * claimed the row. Returns true on a successful submission.
	 *
	 * @param int $id Document id.
	 * @return bool
	 */
	public function send( int $id ): bool {
		$row = DocumentTable::find( $id );
		if ( ! $row ) {
			return false;
		}

		$status = (string) $row['status'];
		if ( DocumentStatus::is_sent_or_beyond( $status ) ) {
			return true; // Already sent — never send twice.
		}
		if ( DocumentStatus::QUEUED !== $status ) {
			return false; // Only queued rows are sendable.
		}

		$provider = $this->registry->active();
		if ( ! $provider ) {
			$this->reject( $id, 'no_provider', __( 'No e-invoicing provider is configured.', 'surecart-eu-helper' ) );
			return false;
		}

		$doc = $this->repo->hydrate( $row );
		$provider->set_environment( $doc->environment );

		// Stable idempotency token: generate once, persist before sending so a
		// retry reuses it and the provider de-duplicates server-side.
		$token = (string) ( $row['provider_guid'] ?? '' );
		if ( '' === $token ) {
			$token = wp_generate_uuid4();
			DocumentTable::update( $id, array( 'provider_guid' => $token ) );
			$doc->provider_guid = $token;
		}

		$result = $provider->submit( $doc, $token );

		if ( DocumentStatus::SUBMITTED === $result->status && $result->provider_guid ) {
			$this->clear_breaker( $provider->key() );
			DocumentWorkflow::transition(
				$id,
				DocumentStatus::SUBMITTED,
				array(
					'event_type' => 'submitted',
					'message'    => __( 'Submitted to provider.', 'surecart-eu-helper' ),
					'columns'    => array(
						'provider_guid'        => $result->provider_guid,
						'provider_document_id' => $result->provider_document_id,
						'response_log'         => wp_json_encode( $result->raw ),
						'error_code'           => null,
						'error_message'        => null,
						'locked_at'            => null,
						'attempts'             => (int) $row['attempts'] + 1,
					),
					'context'    => array( 'guid' => $result->provider_guid ),
				)
			);
			return true;
		}

		if ( $result->retryable ) {
			$this->bump_breaker( $provider->key() );
			$this->schedule_retry( $row, $result->error_code, $result->error_message, $result->raw );
			return false;
		}

		// Permanent rejection.
		$this->clear_breaker( $provider->key() );
		$this->reject( $id, (string) $result->error_code, (string) $result->error_message, $result->raw );
		return false;
	}

	/**
	 * Poll a submitted document's delivery status (PR 1 has no webhooks).
	 *
	 * @param int $id Document id.
	 * @return void
	 */
	public function poll_status( int $id ): void {
		$row = DocumentTable::find( $id );
		if ( ! $row || DocumentStatus::SUBMITTED !== (string) $row['status'] ) {
			return;
		}
		$guid = (string) ( $row['provider_guid'] ?? '' );
		if ( '' === $guid ) {
			return;
		}

		$provider = $this->registry->active();
		if ( ! $provider ) {
			return;
		}
		$provider->set_environment( (string) $row['environment'] );

		$result = $provider->fetch_status( $guid );

		if ( DocumentStatus::DELIVERED === $result->status ) {
			DocumentWorkflow::transition(
				$id,
				DocumentStatus::DELIVERED,
				array(
					'event_type' => 'delivered',
					'message'    => __( 'Provider reports delivery.', 'surecart-eu-helper' ),
					'columns'    => array( 'response_log' => wp_json_encode( $result->raw ) ),
				)
			);
		} elseif ( DocumentStatus::REJECTED === $result->status ) {
			$this->reject( $id, (string) $result->error_code, (string) $result->error_message, $result->raw );
		}
	}

	/**
	 * Back off a transient failure: bump attempts, schedule the next try, and
	 * fall through to `failed` once max attempts are exhausted.
	 *
	 * @param array<string,mixed> $row     Document row.
	 * @param string|null         $code    Error code.
	 * @param string|null         $message Error message.
	 * @param array<string,mixed> $raw     Raw provider response.
	 * @return void
	 */
	private function schedule_retry( array $row, ?string $code, ?string $message, array $raw ): void {
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'] + 1;
		$max      = (int) $row['max_attempts'];

		$columns = array(
			'attempts'      => $attempts,
			'error_code'    => $code,
			'error_message' => $message,
			'response_log'  => wp_json_encode( $raw ),
			'locked_at'     => null,
		);

		if ( $attempts >= $max ) {
			DocumentWorkflow::transition(
				$id,
				DocumentStatus::FAILED,
				array(
					'event_type' => 'failed',
					'message'    => sprintf(
						/* translators: %d: attempt count. */
						__( 'Giving up after %d attempts. Needs attention.', 'surecart-eu-helper' ),
						$attempts
					),
					'columns'    => $columns,
				)
			);
			return;
		}

		$delay                      = (int) min( self::BASE_BACKOFF * pow( 2, $attempts ), self::MAX_BACKOFF ) + wp_rand( 0, 60 );
		$columns['next_attempt_at'] = DocumentTable::utc_now( $delay );

		// Stay queued; just record the attempt + reschedule.
		DocumentTable::update( $id, $columns );
		EventsTable::record(
			$id,
			'submit_attempt',
			array(
				'message' => $message,
				'context' => array(
					'attempt'         => $attempts,
					'next_attempt_at' => $columns['next_attempt_at'],
					'error_code'      => $code,
				),
			)
		);
	}

	/**
	 * Move a document to the terminal rejected state.
	 *
	 * @param int                 $id      Document id.
	 * @param string              $code    Error code.
	 * @param string              $message Error message.
	 * @param array<string,mixed> $raw     Raw provider response.
	 * @return void
	 */
	private function reject( int $id, string $code, string $message, array $raw = array() ): void {
		DocumentWorkflow::transition(
			$id,
			DocumentStatus::REJECTED,
			array(
				'event_type' => 'rejected',
				'message'    => $message,
				'columns'    => array(
					'error_code'    => $code,
					'error_message' => $message,
					'response_log'  => wp_json_encode( $raw ),
					'locked_at'     => null,
				),
			)
		);
	}

	/**
	 * Circuit breaker: whether sending is currently paused for a provider.
	 *
	 * @param string $provider_key Provider key.
	 * @return bool
	 */
	public static function is_breaker_open( string $provider_key ): bool {
		return (bool) get_transient( 'sceu_einv_provider_down_' . $provider_key );
	}

	/**
	 * Count a consecutive transient failure; trip the breaker at the threshold.
	 *
	 * @param string $provider_key Provider key.
	 * @return void
	 */
	private function bump_breaker( string $provider_key ): void {
		$key   = 'sceu_einv_fail_streak_' . $provider_key;
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, self::BREAKER_TTL );
		if ( $count >= self::BREAKER_THRESHOLD ) {
			set_transient( 'sceu_einv_provider_down_' . $provider_key, 1, self::BREAKER_TTL );
		}
	}

	/**
	 * Clear the failure streak + breaker after a success.
	 *
	 * @param string $provider_key Provider key.
	 * @return void
	 */
	private function clear_breaker( string $provider_key ): void {
		delete_transient( 'sceu_einv_fail_streak_' . $provider_key );
		delete_transient( 'sceu_einv_provider_down_' . $provider_key );
	}
}
