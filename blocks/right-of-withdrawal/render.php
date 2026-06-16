<?php
/**
 * Server render for the Right of Withdrawal block.
 *
 * Runs in the GLOBAL namespace — reference plugin classes with their fully
 * qualified names, never `use` or short names.
 *
 * @package SureCartEuHelper
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner content (unused).
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

// Module must be enabled.
if ( ! \SureCartEuHelper\Settings::is_module_enabled( 'right_of_withdrawal' ) ) {
	return '';
}

$sceu_customer = new \SureCartEuHelper\Customer\CustomerContext();

// Base eligibility: a logged-in resolvable customer, in the EU, passing the VAT
// rule. (The order requirement is handled below — the block also shows when the
// customer has no withdrawable orders left but has already-submitted requests.)
$sceu_apply_to = (string) \SureCartEuHelper\Settings::get( 'right_of_withdrawal', 'apply_to', 'all' );
$sceu_lookback = (int) \SureCartEuHelper\Settings::get( 'right_of_withdrawal', 'lookback_days', 14 );

if ( ! $sceu_customer->is_customer() || ! $sceu_customer->is_eu() ) {
	return '';
}
if ( 'non_vat' === $sceu_apply_to && $sceu_customer->has_vat() ) {
	return '';
}

// Orders still withdrawable (excludes already-requested + refunded), plus the
// customer's existing requests to show status for.
$sceu_orders   = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::withdrawable_orders( $sceu_customer, $sceu_lookback );
$sceu_requests = \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::requests_for_display( get_current_user_id() );

// Nothing to withdraw and no requests to show → render nothing.
if ( empty( $sceu_orders ) && empty( $sceu_requests ) ) {
	return '';
}

$sceu_has_withdrawable = ! empty( $sceu_orders );

// Resolve display strings: block override, else translatable default.
$sceu_heading = ! empty( $attributes['heading'] )
	? $attributes['heading']
	: __( 'Right of Withdrawal', 'surecart-eu-helper' );

// Heading tag is merchant-selectable so it won't inherit an oversized theme
// heading style unpredictably. Allow-list guards the output tag.
$sceu_heading_level = isset( $attributes['headingLevel'] ) ? strtolower( (string) $attributes['headingLevel'] ) : 'h3';
if ( ! in_array( $sceu_heading_level, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ) {
	$sceu_heading_level = 'h3';
}

$sceu_intro = ! empty( $attributes['intro'] )
	? $attributes['intro']
	/* translators: default withdrawal explanation. */
	: __( 'You have the right to withdraw from your purchase within 14 days of receiving your order. Click below to start the withdrawal process.', 'surecart-eu-helper' );

$sceu_button = ! empty( $attributes['buttonLabel'] )
	? $attributes['buttonLabel']
	: __( 'Withdraw from contract here', 'surecart-eu-helper' );

$sceu_modal_title = ! empty( $attributes['modalTitle'] )
	? $attributes['modalTitle']
	: __( 'Request a withdrawal', 'surecart-eu-helper' );

$sceu_confirmation = ! empty( $attributes['confirmationMessage'] )
	? $attributes['confirmationMessage']
	: __( 'Thank you. Your withdrawal request has been received and a confirmation has been emailed to you.', 'surecart-eu-helper' );

// Shown instead of the intro when there are no orders left to withdraw but the
// customer has already submitted request(s).
$sceu_submitted_intro = __( 'You have submitted a withdrawal request. You can review its status below.', 'surecart-eu-helper' );

$sceu_display = (string) \SureCartEuHelper\Settings::get( 'right_of_withdrawal', 'form_display', 'modal' );

// Block appearance settings (merchant-chosen).
$sceu_scheme = isset( $attributes['colorScheme'] ) ? (string) $attributes['colorScheme'] : 'auto';
if ( ! in_array( $sceu_scheme, array( 'auto', 'light', 'dark' ), true ) ) {
	$sceu_scheme = 'auto';
}
$sceu_container = ( isset( $attributes['container'] ) && 'none' === $attributes['container'] ) ? 'none' : 'card';

// Summary status for the inline pill in the "submitted" state.
$sceu_summary_status = '';
foreach ( $sceu_requests as $sceu_req ) {
	if ( 'received' === $sceu_req['status'] ) {
		$sceu_summary_status = 'received';
		break;
	}
	if ( 'resolved' === $sceu_req['status'] ) {
		$sceu_summary_status = 'resolved';
	}
}
$sceu_summary_label = '' !== $sceu_summary_status
	? \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::status_label( $sceu_summary_status )
	: '';

// SureCart store primary colour for the buttons (empty => CSS falls back to
// the theme's primary preset).
$sceu_primary      = \SureCartEuHelper\Merchant\BrandColor::primary();
$sceu_primary_text = \SureCartEuHelper\Merchant\BrandColor::primary_text();

// Build the orders payload for the form.
$sceu_order_payload = array();
foreach ( $sceu_orders as $sceu_order ) {
	$sceu_when  = ! empty( $sceu_order['created_at'] ) ? date_i18n( get_option( 'date_format' ), (int) $sceu_order['created_at'] ) : '';
	$sceu_meta  = array_filter( array( $sceu_when, (string) $sceu_order['total_display'] ) );
	$sceu_label = sprintf(
		/* translators: %s: order number/reference. */
		__( 'Order %s', 'surecart-eu-helper' ),
		(string) ( $sceu_order['number'] ?: $sceu_order['id'] )
	);

	$sceu_order_payload[] = array(
		'id'      => (string) $sceu_order['id'],
		'label'   => $sceu_label,
		'meta'    => implode( ' · ', $sceu_meta ),
		'summary' => (string) $sceu_order['summary'],
	);
}

// Build the requests payload (status list) for the dashboard.
$sceu_request_payload = array();
foreach ( $sceu_requests as $sceu_request ) {
	$sceu_request_payload[] = array(
		'id'          => (string) $sceu_request['request_id'],
		'status'      => (string) $sceu_request['status'],
		'statusLabel' => (string) $sceu_request['status_label'],
		'date'        => (string) $sceu_request['created_at'],
		'orders'      => array_map( 'strval', (array) $sceu_request['orders'] ),
	);
}

$sceu_uid = wp_unique_id( 'sceu-row-' );

$sceu_data = array(
	'restUrl'      => esc_url_raw( rest_url( 'surecart-eu-helper/v1/withdrawal-request' ) ),
	'nonce'        => wp_create_nonce( 'wp_rest' ),
	'display'      => 'inline' === $sceu_display ? 'inline' : 'modal',
	'scheme'       => $sceu_scheme,
	'modalTitle'   => $sceu_modal_title,
	'confirmation' => $sceu_confirmation,
	'primaryColor' => $sceu_primary,
	'primaryText'  => $sceu_primary_text,
	'customer'     => array(
		'name'  => $sceu_customer->customer_name(),
		'email' => $sceu_customer->customer_email(),
	),
	'orders'       => $sceu_order_payload,
	'requests'     => $sceu_request_payload,
	'requestsTitle' => __( 'Your withdrawal requests', 'surecart-eu-helper' ),
	'strings'      => array(
		'name'        => __( 'Your name', 'surecart-eu-helper' ),
		'email'       => __( 'Your email', 'surecart-eu-helper' ),
		'orders'      => __( 'Select the orders you want to withdraw from', 'surecart-eu-helper' ),
		'reason'      => __( 'Reason (optional)', 'surecart-eu-helper' ),
		'submit'      => __( 'Submit request', 'surecart-eu-helper' ),
		'cancel'      => __( 'Cancel', 'surecart-eu-helper' ),
		'close'       => __( 'Close', 'surecart-eu-helper' ),
		'selectOne'   => __( 'Please select at least one order.', 'surecart-eu-helper' ),
		'sending'     => __( 'Sending…', 'surecart-eu-helper' ),
		'error'       => __( 'Something went wrong. Please try again.', 'surecart-eu-helper' ),
		'viewRequests' => __( 'View my requests', 'surecart-eu-helper' ),
		'requestOrders' => __( 'Orders', 'surecart-eu-helper' ),
		'requestDate' => __( 'Submitted', 'surecart-eu-helper' ),
	),
);

// Expose the brand colour as a CSS variable on the wrapper (for the trigger
// button). The modal/inline form set it themselves from the JSON payload, since
// the modal is appended to <body>, outside this wrapper.
$sceu_inline_style = '';
if ( '' !== $sceu_primary ) {
	$sceu_inline_style = '--sceu-primary:' . $sceu_primary . ';';
	if ( '' !== $sceu_primary_text ) {
		$sceu_inline_style .= '--sceu-primary-text:' . $sceu_primary_text . ';';
	}
}

$sceu_classes = implode(
	' ',
	array(
		'sceu-row',
		'sceu-row--scheme-' . $sceu_scheme,
		'card' === $sceu_container ? 'sceu-row--card' : 'sceu-row--plain',
	)
);

$sceu_wrapper = get_block_wrapper_attributes(
	array_filter(
		array(
			'class' => $sceu_classes,
			'style' => $sceu_inline_style,
		)
	)
);

// Load the front-end assets ONLY now — i.e. only on pages where the block
// actually renders for an eligible customer. Nothing is enqueued elsewhere.
wp_enqueue_script( 'sceu-row-view' );
wp_enqueue_style( 'sceu-row-view' );

// Output is captured by WordPress's block render (echo, do not return markup).
?>
<div <?php echo $sceu_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes is escaped. ?>
	data-sceu-uid="<?php echo esc_attr( $sceu_uid ); ?>"
	data-sceu-display="<?php echo esc_attr( $sceu_data['display'] ); ?>">

	<div class="sceu-row__notice">
		<div class="sceu-row__text">
			<<?php echo esc_html( $sceu_heading_level ); ?> class="sceu-row__heading"><?php echo esc_html( $sceu_heading ); ?></<?php echo esc_html( $sceu_heading_level ); ?>>
			<p class="sceu-row__intro"><?php echo esc_html( $sceu_has_withdrawable ? $sceu_intro : $sceu_submitted_intro ); ?></p>
		</div>
		<div class="sceu-row__actions">
			<?php if ( ! $sceu_has_withdrawable && '' !== $sceu_summary_label ) : ?>
				<span class="sceu-badge sceu-badge--<?php echo esc_attr( $sceu_summary_status ); ?>"><?php echo esc_html( $sceu_summary_label ); ?></span>
			<?php endif; ?>
			<?php if ( $sceu_has_withdrawable ) : ?>
				<button type="button" class="sceu-row__trigger sceu-btn sceu-btn--primary wp-element-button">
					<?php echo esc_html( $sceu_button ); ?>
				</button>
			<?php endif; ?>
			<?php if ( ! empty( $sceu_request_payload ) ) : ?>
				<button type="button" class="sceu-row__requests-trigger sceu-btn sceu-btn--secondary">
					<?php echo esc_html__( 'View my requests', 'surecart-eu-helper' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<script type="application/json" class="sceu-row__data">
		<?php echo wp_json_encode( $sceu_data, JSON_HEX_TAG | JSON_HEX_AMP ); ?>
	</script>
</div>
<?php
