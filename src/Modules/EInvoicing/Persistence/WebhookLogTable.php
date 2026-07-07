<?php
/**
 * Inbound provider-webhook log + idempotency ledger.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Owns `{$prefix}sceu_einv_webhook_log`. Two jobs: a security/debug record of
 * every inbound provider webhook, and the inbound idempotency ledger — the
 * UNIQUE (provider_key, provider_event_id) lets a redelivered event be detected
 * and acknowledged without re-processing. Populated in PR 3 (webhooks); created
 * now so the schema is stable from the first release.
 */
class WebhookLogTable {

	const DB_VERSION_OPTION = 'sceu_einv_webhook_log_db_version';
	const DB_VERSION        = '1';

	/** Max stored body length (defensive cap). */
	const BODY_CAP = 64000;

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceu_einv_webhook_log';
	}

	/**
	 * Create or upgrade the table via dbDelta.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			provider_key VARCHAR(64) NOT NULL DEFAULT '',
			provider_event_id VARCHAR(191) NOT NULL DEFAULT '',
			event_type VARCHAR(80) NOT NULL DEFAULT '',
			signature_valid TINYINT(1) NOT NULL DEFAULT 0,
			source_ip VARCHAR(45) NOT NULL DEFAULT '',
			document_id BIGINT(20) UNSIGNED NULL,
			matched_guid VARCHAR(191) NULL,
			http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			headers LONGTEXT NULL,
			body LONGTEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_event (provider_key, provider_event_id),
			KEY document_id (document_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Ensure the table exists.
	 *
	 * @return void
	 */
	public static function maybe_create(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create();
		}
	}

	/**
	 * Record an inbound webhook. Returns the new id, or 0 when the
	 * (provider, event id) pair already exists — the duplicate-delivery guard.
	 *
	 * @param array<string,mixed> $data Row data.
	 * @return int
	 */
	public static function record( array $data ): int {
		global $wpdb;

		$body = (string) ( $data['body'] ?? '' );
		if ( strlen( $body ) > self::BODY_CAP ) {
			$body = substr( $body, 0, self::BODY_CAP );
		}

		$row = array(
			'created_at'        => current_time( 'mysql' ),
			'provider_key'      => (string) ( $data['provider_key'] ?? '' ),
			'provider_event_id' => (string) ( $data['provider_event_id'] ?? '' ),
			'event_type'        => (string) ( $data['event_type'] ?? '' ),
			'signature_valid'   => ! empty( $data['signature_valid'] ) ? 1 : 0,
			'source_ip'         => (string) ( $data['source_ip'] ?? '' ),
			'document_id'       => isset( $data['document_id'] ) ? (int) $data['document_id'] : null,
			'matched_guid'      => isset( $data['matched_guid'] ) ? (string) $data['matched_guid'] : null,
			'http_status'       => (int) ( $data['http_status'] ?? 0 ),
			'headers'           => isset( $data['headers'] ) ? wp_json_encode( $data['headers'] ) : null,
			'body'              => $body,
		);

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table_name(), $row );
		$wpdb->suppress_errors( $suppress );

		return $ok ? (int) $wpdb->insert_id : 0;
	}
}
