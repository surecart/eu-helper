<?php
/**
 * Append-only audit/lifecycle log for documents.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Owns `{$prefix}sceu_einv_document_events`. An invoice is a multi-transition
 * process (map → validate → submit attempts → deliver, plus retries) that an
 * auditor will want to sort and paginate — so the full history lives in its own
 * indexable table, not a JSON blob on the document row. The document row keeps
 * only the latest attempt's request/response for at-a-glance display.
 */
class EventsTable {

	const DB_VERSION_OPTION = 'sceu_einv_events_db_version';
	const DB_VERSION        = '1';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceu_einv_document_events';
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
			document_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			event_type VARCHAR(40) NOT NULL DEFAULT '',
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NULL,
			actor VARCHAR(40) NOT NULL DEFAULT 'system',
			message TEXT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY  (id),
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
	 * Record an event.
	 *
	 * @param int                 $document_id Document id.
	 * @param string              $event_type  Event type (e.g. 'submitted').
	 * @param array<string,mixed> $data        Optional from_status/to_status/actor/message/context.
	 * @return int Inserted id (0 on failure).
	 */
	public static function record( int $document_id, string $event_type, array $data = array() ): int {
		global $wpdb;

		$row = array(
			'document_id' => $document_id,
			'created_at'  => current_time( 'mysql' ),
			'event_type'  => $event_type,
			'from_status' => isset( $data['from_status'] ) ? (string) $data['from_status'] : null,
			'to_status'   => isset( $data['to_status'] ) ? (string) $data['to_status'] : null,
			'actor'       => (string) ( $data['actor'] ?? self::current_actor() ),
			'message'     => isset( $data['message'] ) ? (string) $data['message'] : null,
			'context'     => isset( $data['context'] ) ? wp_json_encode( $data['context'] ) : null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table_name(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Events for a document, newest first.
	 *
	 * @param int $document_id Document id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_document( int $document_id ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE document_id = %d ORDER BY id DESC", $document_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Best-effort actor label for the current context.
	 *
	 * @return string
	 */
	private static function current_actor(): string {
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		$uid = get_current_user_id();
		return $uid ? ( 'user:' . $uid ) : 'system';
	}
}
