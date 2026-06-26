<?php
/**
 * Admin list table of e-invoicing documents.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Admin;

use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;
use SureCartEuHelper\Modules\EInvoicing\Domain\Money;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentTable;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Mirrors the Right of Withdrawal LogListTable: paginated rows, status badges,
 * and per-row nonce-protected action links to admin-post.php handlers. Read-only
 * view of the document record; actions live on Module.
 */
class DocumentsListTable extends \WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sceu_einv_document',
				'plural'   => 'sceu_einv_documents',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'created_at' => __( 'Date', 'surecart-eu-helper' ),
			'type'       => __( 'Type', 'surecart-eu-helper' ),
			'order'      => __( 'Order', 'surecart-eu-helper' ),
			'customer'   => __( 'Customer', 'surecart-eu-helper' ),
			'total'      => __( 'Total', 'surecart-eu-helper' ),
			'status'     => __( 'Status', 'surecart-eu-helper' ),
			'provider'   => __( 'Provider', 'surecart-eu-helper' ),
		);
	}

	/**
	 * Load rows.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page     = self::PER_PAGE;
		$current_page = $this->get_pagenum();
		$total        = DocumentTable::count();

		$this->items = DocumentTable::get_rows( $per_page, ( $current_page - 1 ) * $per_page );

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
	 * Default cell rendering.
	 *
	 * @param array<string,mixed> $item        Row.
	 * @param string              $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( (string) ( $item['created_at'] ?? '' ) );

			case 'type':
				return $this->type_cell( $item );

			case 'order':
				return $this->order_cell( $item );

			case 'customer':
				return $this->customer_cell( $item );

			case 'total':
				return esc_html( Money::format( (int) ( $item['gross_minor'] ?? 0 ), (string) ( $item['currency'] ?? '' ) ) );

			case 'status':
				return $this->status_cell( $item );

			case 'provider':
				return $this->provider_cell( $item );
		}
		return '';
	}

	/**
	 * Type + number, with a credit note linking back to its invoice.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	private function type_cell( array $item ): string {
		$type   = (string) ( $item['document_type'] ?? '' );
		$number = (string) ( $item['document_number'] ?? '' );
		$label  = DocumentType::CREDIT_NOTE === $type ? __( 'Credit note', 'surecart-eu-helper' ) : __( 'Invoice', 'surecart-eu-helper' );

		$html = '<strong>' . esc_html( $label ) . '</strong>';
		if ( '' !== $number ) {
			$html .= '<br><span class="description">' . esc_html( $number ) . '</span>';
		}
		if ( DocumentType::CREDIT_NOTE === $type && ! empty( $item['original_document_id'] ) ) {
			$html .= '<br><span class="description">' . sprintf(
				/* translators: %d: invoice document id. */
				esc_html__( '↳ for invoice #%d', 'surecart-eu-helper' ),
				(int) $item['original_document_id']
			) . '</span>';
		}
		return $html;
	}

	/**
	 * Order link (deep-links into SureCart admin).
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	private function order_cell( array $item ): string {
		$order_id = (string) ( $item['surecart_order_id'] ?? '' );
		if ( '' === $order_id ) {
			return '&mdash;';
		}

		// Show the human order number (e.g. "#0003"); the internal id is only used
		// to build the deep link into the SureCart admin.
		$totals = json_decode( (string) ( $item['totals_snapshot'] ?? '{}' ), true );
		$number = is_array( $totals ) ? (string) ( $totals['order_number'] ?? '' ) : '';
		$text   = '' !== $number ? ( '#' . $number ) : __( 'View order', 'surecart-eu-helper' );

		$url  = \SureCartEuHelper\Admin\AdminUrl::order( (string) $order_id );
		$html = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );

		$refund = (string) ( $item['surecart_refund_id'] ?? '' );
		if ( '' !== $refund ) {
			$html .= '<br><span class="description">' . esc_html__( 'from a refund', 'surecart-eu-helper' ) . '</span>';
		}
		return $html;
	}

	/**
	 * Customer name + country from the snapshot.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	private function customer_cell( array $item ): string {
		$snapshot = json_decode( (string) ( $item['customer_snapshot'] ?? '{}' ), true );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}
		$name    = (string) ( $snapshot['name'] ?? '' );
		$country = (string) ( $snapshot['country'] ?? '' );
		$html    = $name ? esc_html( $name ) : '&mdash;';
		if ( '' !== $country ) {
			$html .= '<br><span class="description">' . esc_html( $country ) . '</span>';
		}
		return $html;
	}

	/**
	 * Local status badge.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	private function status_cell( array $item ): string {
		$status = (string) ( $item['status'] ?? '' );
		$colors = array(
			DocumentStatus::DELIVERED => '#137333',
			DocumentStatus::SUBMITTED => '#1a5dab',
			DocumentStatus::QUEUED    => '#8a6d00',
			DocumentStatus::FAILED    => '#a50e0e',
			DocumentStatus::REJECTED  => '#a50e0e',
		);
		$color = $colors[ $status ] ?? '#50575e';

		$html  = '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . esc_attr( $color ) . ';color:#fff;font-size:11px;">';
		$html .= esc_html( DocumentStatus::label( $status ) );
		$html .= '</span>';
		$html .= $this->row_actions_for( $item );
		return $html;
	}

	/**
	 * Provider GUID / attempts / last error, with a raw payload viewer.
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	private function provider_cell( array $item ): string {
		$html  = esc_html( (string) ( $item['provider_key'] ?? '' ) );
		$env   = (string) ( $item['environment'] ?? '' );
		if ( '' !== $env ) {
			$html .= ' <span class="description">(' . esc_html( $env ) . ')</span>';
		}

		$guid = (string) ( $item['provider_guid'] ?? '' );
		if ( '' !== $guid ) {
			$html .= '<br><span class="description" title="' . esc_attr( $guid ) . '">' . esc_html( substr( $guid, 0, 13 ) . '…' ) . '</span>';
		}

		$attempts = (int) ( $item['attempts'] ?? 0 );
		$max      = (int) ( $item['max_attempts'] ?? 0 );
		if ( $attempts > 0 ) {
			$html .= '<br><span class="description">' . esc_html( sprintf( '%d/%d attempts', $attempts, $max ) ) . '</span>';
		}

		$error = (string) ( $item['error_message'] ?? '' );
		if ( '' !== $error ) {
			$html .= '<br><span style="color:#a50e0e;">' . esc_html( $this->truncate( $error, 120 ) ) . '</span>';
		}

		// Raw request/response viewer.
		$request  = (string) ( $item['payload_json'] ?? '' );
		$response = (string) ( $item['response_log'] ?? '' );
		if ( '' !== $request || '' !== $response ) {
			$html .= '<details style="margin-top:4px;"><summary>' . esc_html__( 'View raw', 'surecart-eu-helper' ) . '</summary>';
			if ( '' !== $request ) {
				$html .= '<pre style="max-width:340px;white-space:pre-wrap;overflow:auto;max-height:240px;font-size:11px;">' . esc_html( $request ) . '</pre>';
			}
			if ( '' !== $response ) {
				$html .= '<pre style="max-width:340px;white-space:pre-wrap;overflow:auto;max-height:240px;font-size:11px;">' . esc_html( $response ) . '</pre>';
			}
			$html .= '</details>';
		}

		return $html;
	}

	/**
	 * Per-row action links (nonce-protected admin-post URLs).
	 *
	 * @param array<string,mixed> $item Row.
	 * @return string
	 */
	private function row_actions_for( array $item ): string {
		$id     = (int) ( $item['id'] ?? 0 );
		$status = (string) ( $item['status'] ?? '' );
		$base   = admin_url( 'admin-post.php' );
		$links  = array();

		if ( in_array( $status, array( DocumentStatus::VALIDATED, DocumentStatus::MAPPED ), true ) ) {
			$links['submit'] = $this->action_link( $base, 'sceu_einv_submit', $id, __( 'Submit now', 'surecart-eu-helper' ) );
		}
		if ( DocumentStatus::FAILED === $status ) {
			$links['retry'] = $this->action_link( $base, 'sceu_einv_retry', $id, __( 'Retry now', 'surecart-eu-helper' ) );
		}
		if ( in_array( $status, array( DocumentStatus::SUBMITTED, DocumentStatus::DELIVERED ), true ) ) {
			$links['evidence'] = $this->action_link( $base, 'sceu_einv_evidence', $id, __( 'Fetch evidence', 'surecart-eu-helper' ) );
		}
		$links['delete'] = $this->action_link( $base, 'sceu_einv_delete', $id, __( 'Delete', 'surecart-eu-helper' ), true );

		return $this->row_actions( $links );
	}

	/**
	 * Build one nonce-protected action link.
	 *
	 * @param string $base    admin-post.php URL.
	 * @param string $action  Action name.
	 * @param int    $id      Document id.
	 * @param string $label   Link text.
	 * @param bool   $confirm Whether to confirm.
	 * @return string
	 */
	private function action_link( string $base, string $action, int $id, string $label, bool $confirm = false ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'id'     => $id,
				),
				$base
			),
			$action . '_' . $id
		);

		$onclick = $confirm ? ' onclick="return confirm(\'' . esc_js( __( 'Are you sure?', 'surecart-eu-helper' ) ) . '\');"' : '';
		return '<a href="' . esc_url( $url ) . '"' . $onclick . '>' . esc_html( $label ) . '</a>';
	}

	/**
	 * Truncate a string for display.
	 *
	 * @param string $text Text.
	 * @param int    $len  Max length.
	 * @return string
	 */
	private function truncate( string $text, int $len ): string {
		return strlen( $text ) > $len ? substr( $text, 0, $len ) . '…' : $text;
	}
}
