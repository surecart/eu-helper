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
			'cb'             => '<input type="checkbox" />',
			'created_at'     => __( 'Date', 'surecart-eu-helper' ),
			'customer_name'  => __( 'Customer', 'surecart-eu-helper' ),
			'customer_email' => __( 'Email', 'surecart-eu-helper' ),
			'order_ids'      => __( 'Withdrawing', 'surecart-eu-helper' ),
			'reason'         => __( 'Reason', 'surecart-eu-helper' ),
			'emails'         => __( 'Emails sent', 'surecart-eu-helper' ),
			'ip_address'     => __( 'IP address', 'surecart-eu-helper' ),
			'status'         => __( 'Status', 'surecart-eu-helper' ),
		);
	}

	/**
	 * Row-selection checkbox (enables the bulk actions).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) ( $item['id'] ?? 0 ) );
	}

	/**
	 * Bulk actions offered above/below the table.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete permanently', 'surecart-eu-helper' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		// Honour the "Requests per page" Screen Option (per-user), default 20.
		$per_page     = $this->get_items_per_page( 'sceu_log_per_page', 20 );
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

		// Columns / hidden (from Screen Options) / sortable / primary.
		$this->_column_headers = array(
			$this->get_columns(),
			get_hidden_columns( $this->screen ),
			array(),
			'created_at',
		);
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
				$out = esc_html( $item['created_at'] ?? '' );
				$id  = (int) ( $item['id'] ?? 0 );
				// Permanent delete lives here as a hover row-action (keeps the row
				// compact) — GDPR erasure / test cleanup; re-enables re-requesting.
				if ( $id && current_user_can( 'manage_options' ) ) {
					$delete_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=sceu_delete_log&id=' . $id ),
						'sceu_delete_log_' . $id
					);
					$confirm = esc_js( __( 'Permanently delete this request from the log? This removes the audit record and lets the customer request these items again. This cannot be undone.', 'surecart-eu-helper' ) );
					$out    .= '<div class="row-actions"><span class="delete">'
						. '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . $confirm . '\');">'
						. esc_html__( 'Delete permanently', 'surecart-eu-helper' ) . '</a></span></div>';
				}
				return $out;
			case 'customer_name':
				return $this->customer_link( $item, (string) ( $item['customer_name'] ?? '' ) );
			case 'customer_email':
				return $this->customer_link( $item, (string) ( $item['customer_email'] ?? '' ) );
			case 'ip_address':
				return esc_html( $item['ip_address'] ?? '' );
			case 'emails':
				$payload = json_decode( (string) ( $item['payload'] ?? '{}' ), true );
				$cust    = ! empty( $payload['customer_email_sent'] );
				$merch   = ! empty( $payload['merchant_email_sent'] );
				$id      = (int) ( $item['id'] ?? 0 );
				$mark    = function ( $ok, $label, $which ) use ( $id ) {
					if ( $ok ) {
						$icon   = '✓';
						$col    = '#137333';
						$status = __( 'Sent', 'surecart-eu-helper' );
						$tip    = __( 'Email was handed off to your site successfully.', 'surecart-eu-helper' );
					} else {
						$icon   = '✕';
						$col    = '#a50e0e';
						$status = __( 'Not sent', 'surecart-eu-helper' );
						$tip    = __( 'WordPress could not send this email. This usually means the site has no working mail/SMTP setup (common on staging sites). The withdrawal request itself was still recorded.', 'surecart-eu-helper' );
					}
					$out = '<span style="color:' . $col . ';" title="' . esc_attr( $tip ) . '">'
						. $icon . ' ' . esc_html( $label ) . ': ' . esc_html( $status ) . '</span>';

					// Per-recipient resend. The resent email carries the original
					// request timestamp (not "now"), so it remains a faithful receipt.
					if ( $id && current_user_can( 'manage_options' ) ) {
						$url  = wp_nonce_url(
							admin_url( 'admin-post.php?action=sceu_resend_emails&id=' . $id . '&which=' . $which ),
							'sceu_resend_emails_' . $id
						);
						$out .= ' &middot; <a href="' . esc_url( $url ) . '" style="font-size:12px;">'
							. esc_html( $ok ? __( 'Resend', 'surecart-eu-helper' ) : __( 'Try again', 'surecart-eu-helper' ) )
							. '</a>';
					}
					return $out;
				};
				return $mark( $cust, __( 'Customer', 'surecart-eu-helper' ), 'customer' )
					. '<br />' . $mark( $merch, __( 'Merchant', 'surecart-eu-helper' ), 'merchant' );
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
				return $this->withdrawing_cell( $item );
			default:
				return '';
		}
	}

	/**
	 * Render a customer field (name or email), linked to the SureCart customer
	 * record when the request came from a resolved customer. Guest/unverified
	 * requests have no `customer_id`, so they render as plain text.
	 *
	 * @param array<string, mixed> $item Row.
	 * @param string               $text Display text (name or email).
	 * @return string
	 */
	private function customer_link( array $item, string $text ): string {
		if ( '' === trim( $text ) ) {
			return '&mdash;';
		}

		$customer_id = (string) ( $item['customer_id'] ?? '' );
		if ( '' === $customer_id ) {
			return esc_html( $text );
		}

		return '<a href="' . esc_url( \SureCartEuHelper\Admin\AdminUrl::customer( $customer_id ) ) . '">'
			. esc_html( $text ) . '</a>';
	}

	/**
	 * "Withdrawing" column: for each order, a link to the order plus exactly what
	 * to action — "Entire order", or the specific items as "2 of 3 × Product" —
	 * with a Partial/Full badge so the merchant knows the action at a glance.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	private function withdrawing_cell( array $item ): string {
		$payload = json_decode( (string) ( $item['payload'] ?? '{}' ), true );
		$orders  = ( is_array( $payload ) && ! empty( $payload['orders'] ) && is_array( $payload['orders'] ) )
			? $payload['orders']
			: array();

		// Legacy rows without per-order detail: fall back to order-id links.
		if ( empty( $orders ) ) {
			$ids = json_decode( (string) ( $item['order_ids'] ?? '[]' ), true );
			if ( ! is_array( $ids ) || empty( $ids ) ) {
				return '&mdash;';
			}
			$links = array();
			foreach ( $ids as $id ) {
				$links[] = '<a href="' . esc_url( \SureCartEuHelper\Admin\AdminUrl::order( (string) $id ) ) . '">' . esc_html( (string) $id ) . '</a>';
			}
			return implode( '<br />', $links );
		}

		$blocks = array();
		foreach ( $orders as $order ) {
			$id      = (string) ( $order['id'] ?? '' );
			$ref     = (string) ( $order['number'] ?? '' );
			$ref     = '' !== $ref ? $ref : $id;
			$summary = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::merchant_items_summary( $order );
			$partial = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::is_partial( $order );

			$badge_text = $partial ? __( 'Partial', 'surecart-eu-helper' ) : __( 'Full order', 'surecart-eu-helper' );
			$badge_bg   = $partial ? '#fdf3dd' : '#e6f4ea';
			$badge_fg   = $partial ? '#8a6d00' : '#137333';
			$badge      = '<span style="display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:999px;background:' . $badge_bg . ';color:' . $badge_fg . ';margin-left:6px;">' . esc_html( $badge_text ) . '</span>';

			$blocks[] = '<div style="margin-bottom:8px;">'
				. '<a href="' . esc_url( \SureCartEuHelper\Admin\AdminUrl::order( $id ) ) . '"><strong>' . sprintf(
					/* translators: %s: order reference. */
					esc_html__( 'Order %s', 'surecart-eu-helper' ),
					esc_html( $ref )
				) . '</strong></a>' . $badge
				. '<div style="font-size:12px;color:#444;margin-top:2px;">' . esc_html( $summary ) . '</div>'
				. '</div>';
		}

		return implode( '', $blocks );
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

		// A partial/mixed refund the Sync detected but won't auto-resolve. Only
		// meaningful while the request is still pending; a resolved/declined
		// request has already been actioned, so the prompt would be noise.
		if ( 'received' === $status ) {
			$payload = json_decode( (string) ( $item['payload'] ?? '{}' ), true );
			if ( is_array( $payload ) && ! empty( $payload['refund_review'] ) ) {
				$tip  = __( 'Sync found a partial refund for this order in SureCart. It was not resolved automatically because a partial refund can\'t be matched to a specific request — review and set the status manually.', 'surecart-eu-helper' );
				$out .= ' <span title="' . esc_attr( $tip ) . '" style="display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:999px;background:#fdf3dd;color:#8a6d00;margin-left:6px;">'
					. esc_html__( 'Refund detected — review', 'surecart-eu-helper' ) . '</span>';
			}
		}

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

		// Permanent delete moved to a hover row-action under the Date column to
		// keep this row compact (see column_default 'created_at').
		return $out;
	}
}
