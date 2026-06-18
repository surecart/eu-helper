<?php
/**
 * Admin list table for withdrawal requests.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Log;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read-only viewer for the withdrawal log table.
 */
class LogListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'withdrawal_request',
				'plural'   => 'withdrawal_requests',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created_at'     => __( 'Date', 'surecart-eu-helper' ),
			'customer_name'  => __( 'Customer', 'surecart-eu-helper' ),
			'customer_email' => __( 'Email', 'surecart-eu-helper' ),
			'order_ids'      => __( 'Orders', 'surecart-eu-helper' ),
			'reason'         => __( 'Reason', 'surecart-eu-helper' ),
			'emails'         => __( 'Emails sent', 'surecart-eu-helper' ),
			'ip_address'     => __( 'IP address', 'surecart-eu-helper' ),
			'status'         => __( 'Status', 'surecart-eu-helper' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total        = LogTable::count();

		$this->items = LogTable::get_rows( $per_page, ( $current_page - 1 ) * $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array<string, mixed> $item        Row.
	 * @param string               $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( $item['created_at'] ?? '' );
			case 'customer_name':
				return esc_html( $item['customer_name'] ?? '' );
			case 'customer_email':
				return esc_html( $item['customer_email'] ?? '' );
			case 'ip_address':
				return esc_html( $item['ip_address'] ?? '' );
			case 'emails':
				$payload = json_decode( (string) ( $item['payload'] ?? '{}' ), true );
				$cust    = ! empty( $payload['customer_email_sent'] );
				$merch   = ! empty( $payload['merchant_email_sent'] );
				$mark    = function ( $ok, $label ) {
					$icon = $ok ? '✓' : '✕';
					$col  = $ok ? '#137333' : '#a50e0e';
					return '<span style="color:' . $col . ';">' . $icon . '</span> ' . esc_html( $label );
				};
				return $mark( $cust, __( 'Customer', 'surecart-eu-helper' ) ) . '<br />' . $mark( $merch, __( 'Merchant', 'surecart-eu-helper' ) );
			case 'reason':
				$payload = json_decode( (string) ( $item['payload'] ?? '{}' ), true );
				$reason  = is_array( $payload ) ? trim( (string) ( $payload['reason'] ?? '' ) ) : '';
				if ( '' === $reason ) {
					return '&mdash;';
				}
				$limit = 80;
				// Short reason: show inline.
				if ( mb_strlen( $reason ) <= $limit ) {
					return nl2br( esc_html( $reason ) );
				}
				// Long reason: truncated summary that expands to the full text on click.
				$summary = mb_substr( $reason, 0, $limit );
				return '<details class="sceu-reason"><summary style="cursor:pointer;">'
					. esc_html( $summary ) . '&hellip;</summary>'
					. '<div style="margin-top:4px;">' . nl2br( esc_html( $reason ) ) . '</div>'
					. '</details>';
			case 'status':
				return $this->status_cell( $item );
			case 'order_ids':
				$ids = json_decode( (string) ( $item['order_ids'] ?? '[]' ), true );
				if ( ! is_array( $ids ) || empty( $ids ) ) {
					return '&mdash;';
				}
				$links = array();
				foreach ( $ids as $id ) {
					$links[] = '<a href="' . esc_url( sceu_order_admin_url( (string) $id ) ) . '">' . esc_html( (string) $id ) . '</a>';
				}
				return implode( '<br />', $links );
			default:
				return '';
		}
	}

	/**
	 * Status column: current status label plus merchant actions to change it.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	private function status_cell( array $item ): string {
		$id     = (int) ( $item['id'] ?? 0 );
		$status = (string) ( $item['status'] ?? 'received' );
		$label  = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::status_label( $status );

		$out = '<strong>' . esc_html( $label ) . '</strong>';

		$actions = array(
			'resolved' => __( 'Mark resolved', 'surecart-eu-helper' ),
			'rejected' => __( 'Mark declined', 'surecart-eu-helper' ),
			'received' => __( 'Reset to pending', 'surecart-eu-helper' ),
		);
		unset( $actions[ $status ] ); // Don't offer the current status.

		$links = array();
		foreach ( $actions as $new_status => $text ) {
			$url     = wp_nonce_url(
				admin_url( 'admin-post.php?action=sceu_set_status&id=' . $id . '&status=' . $new_status ),
				'sceu_set_status_' . $id
			);
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
		}

		if ( $links ) {
			$out .= '<div style="margin-top:4px;font-size:12px;">' . implode( ' | ', $links ) . '</div>';
		}

		return $out;
	}
}
