<?php
/**
 * WP-Cron submission queue for documents.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Services;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentTable;
use SureCartEuHelper\Modules\EInvoicing\Persistence\EventsTable;
use SureCartEuHelper\Modules\EInvoicing\Persistence\WebhookLogTable;

defined( 'ABSPATH' ) || exit;

/**
 * The queue is `status='queued'` plus the retry columns on each document row —
 * there is no separate job table. A recurring sweeper processes due rows, and a
 * single-event "kick" runs shortly after a document is queued so the merchant
 * doesn't wait for the next interval. Both call the same sweep().
 *
 * Uses native WP-Cron only (the plugin bundles no Action Scheduler).
 */
final class Queue {

	const CRON_SWEEP = 'sceu_einv_sweep';
	const CRON_KICK  = 'sceu_einv_kick';
	const SCHEDULE   = 'sceu_einv_5min';
	const BATCH      = 20;

	/**
	 * Register hooks. Called from Module::boot().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_SWEEP, array( __CLASS__, 'sweep' ) );
		add_action( self::CRON_KICK, array( __CLASS__, 'sweep' ) );

		if ( ! wp_next_scheduled( self::CRON_SWEEP ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_SWEEP );
		}
	}

	/**
	 * Remove the recurring sweep (module disabled / plugin deactivated).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_SWEEP );
		wp_clear_scheduled_hook( self::CRON_KICK );
	}

	/**
	 * Add the 5-minute schedule (WP's built-ins start at hourly).
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 minutes (EU Helper e-invoicing)', 'surecart-eu-helper' ),
			);
		}
		return $schedules;
	}

	/**
	 * Queue a document for sending and kick the sweeper soon.
	 *
	 * @param int $id Document id (unused directly; the sweeper scans for due rows).
	 * @return void
	 */
	public static function kick(): void {
		if ( ! wp_next_scheduled( self::CRON_KICK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_KICK );
		}
	}

	/**
	 * Process due queued documents and poll submitted ones for delivery.
	 *
	 * @return void
	 */
	public static function sweep(): void {
		DocumentTable::maybe_create();
		EventsTable::maybe_create();
		WebhookLogTable::maybe_create();

		$provider_key = (string) Settings::get( 'einvoicing', 'provider', '' );
		if ( '' !== $provider_key && SubmissionService::is_breaker_open( $provider_key ) ) {
			return; // Provider outage — back off entirely until the breaker clears.
		}

		$service = new SubmissionService();

		foreach ( DocumentTable::claimable_ids( self::BATCH ) as $id ) {
			if ( ! DocumentTable::claim( $id ) ) {
				continue; // Lost the race to another sweep.
			}
			try {
				$service->send( $id );
			} catch ( \Throwable $e ) {
				EventsTable::record( $id, 'sweep_error', array( 'message' => $e->getMessage() ) );
				DocumentTable::release( $id );
			}
		}

		// Delivery-status polling (replaced by webhooks in PR 3).
		foreach ( DocumentTable::awaiting_delivery_ids( self::BATCH ) as $id ) {
			try {
				$service->poll_status( $id );
			} catch ( \Throwable $e ) {
				EventsTable::record( $id, 'poll_error', array( 'message' => $e->getMessage() ) );
			}
		}
	}
}
