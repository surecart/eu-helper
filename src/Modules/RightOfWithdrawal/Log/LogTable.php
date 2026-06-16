<?php
/**
 * Custom table for withdrawal requests.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `{$prefix}sceu_withdrawal_log` schema and read/write access.
 */
class LogTable {

	const DB_VERSION_OPTION = 'sceu_withdrawal_log_db_version';
	const DB_VERSION        = '1';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sceu_withdrawal_log';
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
			created_at DATETIME NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			customer_id VARCHAR(64) NOT NULL DEFAULT '',
			customer_name VARCHAR(191) NOT NULL DEFAULT '',
			customer_email VARCHAR(191) NOT NULL DEFAULT '',
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			order_ids LONGTEXT NULL,
			payload LONGTEXT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'received',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Ensure the table exists (e.g. when the module is enabled after activation).
	 *
	 * @return void
	 */
	public static function maybe_create(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create();
		}
	}

	/**
	 * Insert a request row.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return int Inserted id (0 on failure).
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$row = array(
			'created_at'     => current_time( 'mysql' ),
			'user_id'        => (int) ( $data['user_id'] ?? 0 ),
			'customer_id'    => (string) ( $data['customer_id'] ?? '' ),
			'customer_name'  => (string) ( $data['customer_name'] ?? '' ),
			'customer_email' => (string) ( $data['customer_email'] ?? '' ),
			'ip_address'     => (string) ( $data['ip_address'] ?? '' ),
			'order_ids'      => wp_json_encode( $data['order_ids'] ?? array() ),
			'payload'        => wp_json_encode( $data['payload'] ?? array() ),
			'status'         => (string) ( $data['status'] ?? 'received' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table_name(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a page of rows, newest first.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $offset   Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rows( int $per_page, int $offset ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
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
	 * All rows (for CSV export).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all_rows(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows for a given WordPress user, newest first.
	 *
	 * @param int      $user_id  User id.
	 * @param string[] $statuses Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_for_user( int $user_id, array $statuses = array() ): array {
		global $wpdb;
		$table = self::table_name();

		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$params       = array_merge( array( $user_id ), array_values( $statuses ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status IN ($placeholders) ORDER BY created_at DESC", $params ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Order ids a user has already submitted requests for, in the given
	 * statuses (defaults to the "blocks re-requesting" set).
	 *
	 * @param int      $user_id  User id.
	 * @param string[] $statuses Statuses that count as "already requested".
	 * @return string[] Flat list of order ids.
	 */
	public static function requested_order_ids( int $user_id, array $statuses = array( 'received', 'resolved' ) ): array {
		$ids = array();
		foreach ( self::rows_for_user( $user_id, $statuses ) as $row ) {
			$decoded = json_decode( (string) ( $row['order_ids'] ?? '[]' ), true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $id ) {
					$ids[] = (string) $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * All rows with a given status (across users), newest first.
	 *
	 * @param string $status Status.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_by_status( string $status ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", $status ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update a row's status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function update_status( int $id, string $status ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->update( self::table_name(), array( 'status' => $status ), array( 'id' => $id ) );
	}
}
