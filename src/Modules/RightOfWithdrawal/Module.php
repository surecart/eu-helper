<?php
/**
 * Right of Withdrawal module.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

use SureCartEuHelper\Modules\ModuleInterface;
use SureCartEuHelper\Modules\RightOfWithdrawal\Rest\WithdrawalController;
use SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals;
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the customer-area block, the submission REST endpoint, and the log
 * export. Booted only when the module is enabled.
 */
class Module implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'right_of_withdrawal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Right of Withdrawal', 'surecart-eu-helper' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Show EU consumers a withdrawal notice + form in their customer area, letting them request cancellation/refund of recent orders. Notifies you and the customer, and logs each request.', 'surecart-eu-helper' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function disclaimer(): string {
		return __( 'Please note: it is your responsibility as the merchant to understand and comply with the right-of-withdrawal laws that apply to your business and customers. This feature is provided as a helpful tool to collect and route withdrawal requests — it does not constitute legal advice, and we accept no liability for how it is configured or used. When in doubt, consult a qualified professional.', 'surecart-eu-helper' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields(): array {
		return array(
			array(
				'key'     => 'lookback_days',
				'type'    => 'number',
				'label'   => __( 'Look-back window (days)', 'surecart-eu-helper' ),
				'default' => 14,
				'min'     => 1,
				'help'    => __( 'Show the notice for orders placed within this many days. The statutory window is usually 14 days, but you may extend it (e.g. 16–17) when goods are shipped and the clock starts on delivery.', 'surecart-eu-helper' ),
			),
			array(
				'key'     => 'apply_to',
				'type'    => 'radio',
				'label'   => __( 'Apply to', 'surecart-eu-helper' ),
				'default' => 'all',
				'options' => array(
					array(
						'value' => 'all',
						'label' => __( 'All customers', 'surecart-eu-helper' ),
					),
					array(
						'value' => 'non_vat',
						'label' => __( 'Only customers without a VAT number (consumers)', 'surecart-eu-helper' ),
					),
				),
				'help'    => __( 'Choosing the second option hides the notice from customers who have a VAT/tax number on file, since they are treated as businesses.', 'surecart-eu-helper' ),
			),
			array(
				'key'            => 'include_unknown_country',
				'type'           => 'toggle',
				'label'          => __( 'Customers without a country', 'surecart-eu-helper' ),
				'checkbox_label' => __( 'Show the notice to customers who have no country on file', 'surecart-eu-helper' ),
				'default'        => true,
				'help'           => __( 'Some checkout customers never have a country collected. When enabled, the notice is still shown to them. Customers with a known non-EU country are always excluded.', 'surecart-eu-helper' ),
			),
			array(
				'key'     => 'merchant_email',
				'type'    => 'email',
				'label'   => __( 'Merchant notification email', 'surecart-eu-helper' ),
				'default' => '',
				'help'    => __( 'Where withdrawal requests are sent. Leave blank to use your SureCart store email (shown as the placeholder).', 'surecart-eu-helper' ),
			),
			array(
				'key'            => 'form_display',
				'type'           => 'radio',
				'label'          => __( 'Form display', 'surecart-eu-helper' ),
				'default'        => 'modal',
				'options'        => array(
					array(
						'value' => 'modal',
						'label' => __( 'Modal (opens in an accessible dialog)', 'surecart-eu-helper' ),
					),
					array(
						'value' => 'inline',
						'label' => __( 'Inline (expands within the page)', 'surecart-eu-helper' ),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action(
			'rest_api_init',
			function () {
				( new WithdrawalController() )->register_routes();
			}
		);
		add_action( 'admin_post_sceu_export_log', array( $this, 'export_csv' ) );
		add_action( 'admin_post_sceu_set_status', array( $this, 'set_status' ) );
		add_action( 'admin_post_sceu_sync_log', array( $this, 'sync_log' ) );
	}

	/**
	 * Admin action: change a request's status.
	 *
	 * @return void
	 */
	public function set_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'sceu_set_status_' . $id );

		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$allowed = array( Withdrawals::STATUS_RECEIVED, Withdrawals::STATUS_RESOLVED, Withdrawals::STATUS_REJECTED );
		if ( $id && in_array( $status, $allowed, true ) ) {
			LogTable::update_status( $id, $status );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sceu-withdrawal-log&updated=1' ) );
		exit;
	}

	/**
	 * Admin action: best-effort sync of pending requests against SureCart.
	 *
	 * For each pending request, if every order it covers now looks
	 * refunded/cancelled in SureCart, mark the request resolved. SureCart does
	 * not always surface refunds on the order, so this is a convenience, not a
	 * guarantee — the merchant can always set status manually.
	 *
	 * @return void
	 */
	public function sync_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		check_admin_referer( 'sceu_sync_log' );

		$synced = 0;
		foreach ( LogTable::rows_by_status( Withdrawals::STATUS_RECEIVED ) as $row ) {
			$ids = json_decode( (string) ( $row['order_ids'] ?? '[]' ), true );
			if ( ! is_array( $ids ) || empty( $ids ) ) {
				continue;
			}
			$all_done = true;
			foreach ( $ids as $order_id ) {
				if ( ! $this->order_looks_refunded( (string) $order_id ) ) {
					$all_done = false;
					break;
				}
			}
			if ( $all_done ) {
				LogTable::update_status( (int) $row['id'], Withdrawals::STATUS_RESOLVED );
				++$synced;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sceu-withdrawal-log&synced=' . $synced ) );
		exit;
	}

	/**
	 * Best-effort: does this order appear refunded/cancelled in SureCart?
	 *
	 * @param string $order_id Order id.
	 * @return bool
	 */
	private function order_looks_refunded( string $order_id ): bool {
		if ( '' === $order_id || ! class_exists( '\SureCart\Models\Order' ) ) {
			return false;
		}
		try {
			$order = \SureCart\Models\Order::with( array( 'checkout' ) )->find( $order_id );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( is_wp_error( $order ) || empty( $order ) || ! is_object( $order ) ) {
			return false;
		}

		$status = strtolower( (string) ( $order->status ?? '' ) );
		if ( in_array( $status, array( 'canceled', 'cancelled', 'refunded' ), true ) ) {
			return true;
		}

		$checkout = $order->checkout ?? null;
		$refunded = is_object( $checkout ) ? ( $checkout->refunded_amount ?? 0 ) : 0;
		return is_numeric( $refunded ) && (int) $refunded > 0;
	}

	/**
	 * Register the block.
	 *
	 * All assets are declared in block.json with `file:./` paths and registered
	 * by core from the block's directory: editor.js (+ editor.asset.php for its
	 * wp-* deps and translations), editor.css (editor-only), view.css (front-end
	 * + editor preview), and view.js (Interactivity API script module). Core
	 * also auto-enqueues view.css only when the block actually renders content.
	 *
	 * @return void
	 */
	public function register_block(): void {
		register_block_type( SCEU_DIR . 'blocks/right-of-withdrawal' );
	}

	/**
	 * Stream the log as a CSV download.
	 *
	 * @return void
	 */
	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		check_admin_referer( 'sceu_export_log' );

		$rows = LogTable::all_rows();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=withdrawal-requests-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at', 'user_id', 'customer_id', 'customer_name', 'customer_email', 'ip_address', 'order_ids', 'status' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					array( $this, 'csv_safe' ),
					array(
						$row['id'] ?? '',
						$row['created_at'] ?? '',
						$row['user_id'] ?? '',
						$row['customer_id'] ?? '',
						$row['customer_name'] ?? '',
						$row['customer_email'] ?? '',
						$row['ip_address'] ?? '',
						$row['order_ids'] ?? '',
						$row['status'] ?? '',
					)
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Neutralise CSV formula injection: a cell starting with = + - @ (or a
	 * control char) is prefixed with a single quote so spreadsheet apps treat
	 * it as text, not a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public function csv_safe( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
