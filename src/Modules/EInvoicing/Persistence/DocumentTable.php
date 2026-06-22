<?php
/**
 * Custom table for outbound compliance documents (invoices + credit notes).
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Persistence;

use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `{$prefix}sceu_einv_documents` schema and access. Mirrors the
 * dbDelta + DB_VERSION pattern of the withdrawal LogTable. JSON columns are
 * LONGTEXT encoded with wp_json_encode (no native JSON type, for MySQL/MariaDB
 * portability and dbDelta cleanliness). The queue *is* status='queued' plus the
 * retry columns on the row — there is no separate job table.
 */
class DocumentTable {

	const DB_VERSION_OPTION = 'sceu_einv_documents_db_version';
	const DB_VERSION        = '1';

	/** How long a claimed (locked) row may stay in-flight before it is reclaimed. */
	const LOCK_STALE_SECONDS = 600;

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceu_einv_documents';
	}

	/**
	 * Create or upgrade the table via dbDelta. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			module_key VARCHAR(64) NOT NULL DEFAULT 'einvoicing',
			document_type VARCHAR(20) NOT NULL DEFAULT 'invoice',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			document_number VARCHAR(64) NOT NULL DEFAULT '',
			surecart_order_id VARCHAR(64) NOT NULL DEFAULT '',
			surecart_refund_id VARCHAR(64) NULL,
			surecart_customer_id VARCHAR(64) NULL,
			provider_key VARCHAR(64) NOT NULL DEFAULT '',
			provider_document_id VARCHAR(128) NULL,
			provider_guid VARCHAR(191) NULL,
			original_document_id BIGINT(20) UNSIGNED NULL,
			environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
			currency VARCHAR(3) NOT NULL DEFAULT '',
			gross_minor BIGINT(20) NOT NULL DEFAULT 0,
			totals_snapshot LONGTEXT NULL,
			tax_snapshot LONGTEXT NULL,
			merchant_snapshot LONGTEXT NULL,
			customer_snapshot LONGTEXT NULL,
			line_items_snapshot LONGTEXT NULL,
			payload_json LONGTEXT NULL,
			payload_xml LONGTEXT NULL,
			request_log LONGTEXT NULL,
			response_log LONGTEXT NULL,
			error_code VARCHAR(64) NULL,
			error_message TEXT NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts INT UNSIGNED NOT NULL DEFAULT 8,
			next_attempt_at DATETIME NULL,
			locked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			submitted_at DATETIME NULL,
			delivered_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY surecart_order_id (surecart_order_id),
			KEY surecart_refund_id (surecart_refund_id),
			KEY document_type (document_type),
			KEY status (status),
			KEY original_document_id (original_document_id),
			KEY sweeper (status, next_attempt_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Ensure the table exists (e.g. module enabled after activation).
	 *
	 * @return void
	 */
	public static function maybe_create(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create();
		}
	}

	/**
	 * Insert a new document row. Returns the new id, or 0 if the insert failed —
	 * notably when the UNIQUE idempotency_key already exists (the duplicate guard).
	 *
	 * @param array<string,mixed> $data Column values.
	 * @return int
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );
		$row = array(
			'module_key'           => (string) ( $data['module_key'] ?? 'einvoicing' ),
			'document_type'        => (string) ( $data['document_type'] ?? 'invoice' ),
			'status'               => (string) ( $data['status'] ?? DocumentStatus::DRAFT ),
			'document_number'      => (string) ( $data['document_number'] ?? '' ),
			'surecart_order_id'    => (string) ( $data['surecart_order_id'] ?? '' ),
			'surecart_refund_id'   => isset( $data['surecart_refund_id'] ) ? (string) $data['surecart_refund_id'] : null,
			'surecart_customer_id' => isset( $data['surecart_customer_id'] ) ? (string) $data['surecart_customer_id'] : null,
			'provider_key'         => (string) ( $data['provider_key'] ?? '' ),
			'original_document_id' => isset( $data['original_document_id'] ) ? (int) $data['original_document_id'] : null,
			'environment'          => (string) ( $data['environment'] ?? 'sandbox' ),
			'currency'             => (string) ( $data['currency'] ?? '' ),
			'gross_minor'          => (int) ( $data['gross_minor'] ?? 0 ),
			'totals_snapshot'      => wp_json_encode( $data['totals_snapshot'] ?? array() ),
			'tax_snapshot'         => wp_json_encode( $data['tax_snapshot'] ?? array() ),
			'merchant_snapshot'    => wp_json_encode( $data['merchant_snapshot'] ?? array() ),
			'customer_snapshot'    => wp_json_encode( $data['customer_snapshot'] ?? array() ),
			'line_items_snapshot'  => wp_json_encode( $data['line_items_snapshot'] ?? array() ),
			'payload_json'         => isset( $data['payload_json'] ) ? (string) $data['payload_json'] : null,
			'idempotency_key'      => (string) ( $data['idempotency_key'] ?? '' ),
			'max_attempts'         => (int) ( $data['max_attempts'] ?? 8 ),
			'created_at'           => $now,
			'updated_at'           => $now,
		);

		// Suppress the dup-key warning; a false return is the expected dedupe path.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table_name(), $row );
		$wpdb->suppress_errors( $suppress );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a single row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch a row by its idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_idempotency( string $key ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * All documents for an order, newest first.
	 *
	 * @param string $order_id SureCart order id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_by_order( string $order_id ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE surecart_order_id = %s ORDER BY id DESC", $order_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Credit notes derived from a given invoice document.
	 *
	 * @param int $invoice_id Local invoice document id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function credit_notes_for_invoice( int $invoice_id ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE original_document_id = %d AND document_type = 'credit_note' ORDER BY id DESC",
				$invoice_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Page of rows for the admin list, newest first.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $offset   Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_rows( int $per_page, int $offset ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total row count.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * All rows (CSV export).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_rows(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update arbitrary columns on a row (always refreshes updated_at).
	 *
	 * @param int                 $id   Row id.
	 * @param array<string,mixed> $data Columns to set.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
	}

	/**
	 * UTC "now" as a MySQL datetime. The scheduling columns (next_attempt_at,
	 * locked_at) are stored and compared in UTC so the queue is timezone-safe;
	 * created_at/updated_at use site-local time for display, and are never
	 * compared against these.
	 *
	 * @param int $offset_seconds Seconds to add (may be negative).
	 * @return string
	 */
	public static function utc_now( int $offset_seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset_seconds );
	}

	/**
	 * Claim a queued, due, unlocked row for processing. Returns true only if this
	 * caller won the row (affected-rows === 1), so overlapping cron runs and a
	 * manual retry can never double-submit. Stale locks are reclaimable.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function claim( int $id ): bool {
		global $wpdb;
		$table = self::table_name();
		$now   = self::utc_now();
		$stale = self::utc_now( - self::LOCK_STALE_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET locked_at = %s WHERE id = %d AND status = %s AND ( locked_at IS NULL OR locked_at < %s )",
				$now,
				$id,
				DocumentStatus::QUEUED,
				$stale
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Release a row's lock.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function release( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table_name(), array( 'locked_at' => null ), array( 'id' => $id ) );
	}

	/**
	 * IDs of rows that are claimable now: queued, due, and unlocked (or stale).
	 *
	 * @param int $limit Max rows.
	 * @return int[]
	 */
	public static function claimable_ids( int $limit = 20 ): array {
		global $wpdb;
		$table = self::table_name();
		$now   = self::utc_now();
		$stale = self::utc_now( - self::LOCK_STALE_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE status = %s
				   AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
				   AND ( locked_at IS NULL OR locked_at < %s )
				 ORDER BY next_attempt_at ASC, id ASC
				 LIMIT %d",
				DocumentStatus::QUEUED,
				$now,
				$stale,
				$limit
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * IDs of submitted-but-not-delivered rows, for delivery-status polling.
	 *
	 * @param int $limit Max rows.
	 * @return int[]
	 */
	public static function awaiting_delivery_ids( int $limit = 20 ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND provider_guid IS NOT NULL ORDER BY id ASC LIMIT %d",
				DocumentStatus::SUBMITTED,
				$limit
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Delete a row (test cleanup / GDPR).
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}
}
