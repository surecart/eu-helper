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
$sceu_apply_to        = (string) \SureCartEuHelper\Settings::get( 'right_of_withdrawal', 'apply_to', 'all' );
$sceu_lookback        = (int) \SureCartEuHelper\Settings::get( 'right_of_withdrawal', 'lookback_days', 14 );
$sceu_include_unknown = (bool) \SureCartEuHelper\Settings::get( 'right_of_withdrawal', 'include_unknown_country', true );

if ( ! $sceu_customer->is_customer() ) {
	return '';
}
// Geography: EU country, or (when enabled) no country on file. Known non-EU is excluded.
if ( ! $sceu_customer->is_eu() && ! ( $sceu_include_unknown && ! $sceu_customer->has_country() ) ) {
	return '';
}
if ( 'non_vat' === $sceu_apply_to && \SureCartEuHelper\Modules\RightOfWithdrawal\Withdrawals::is_vat_business( $sceu_customer, $sceu_lookback ) ) {
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
	$sceu_heading_level = 'h4';
}

$sceu_intro = ! empty( $attributes['intro'] )
	? $attributes['intro']
	/* translators: default withdrawal explanation. */
	: __( 'You have the right to withdraw from your purchase within 14 days of receiving your order.', 'surecart-eu-helper' );

$sceu_button = ! empty( $attributes['buttonLabel'] )
	? $attributes['buttonLabel']
	: __( 'Withdraw from contract', 'surecart-eu-helper' );

$sceu_modal_title = ! empty( $attributes['modalTitle'] )
	? $attributes['modalTitle']
	: __( 'Request a withdrawal', 'surecart-eu-helper' );

// Step-2 confirmation button label (the legally-required separate confirmation
// function). Merchant-configurable; defaults to a translatable string.
$sceu_confirm = ! empty( $attributes['confirmButtonLabel'] )
	? $attributes['confirmButtonLabel']
	: __( 'Confirm withdrawal', 'surecart-eu-helper' );

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

// Build the orders payload for the form, including per-item remaining quantities
// so the customer can withdraw whole orders or specific items/quantities.
$sceu_order_payload = array();
foreach ( $sceu_orders as $sceu_order ) {
	$sceu_when  = ! empty( $sceu_order['created_at'] ) ? date_i18n( get_option( 'date_format' ), (int) $sceu_order['created_at'] ) : '';
	$sceu_meta  = array_filter( array( $sceu_when, (string) $sceu_order['total_display'] ) );
	$sceu_label = sprintf(
		/* translators: %s: order number/reference. */
		__( 'Order %s', 'surecart-eu-helper' ),
		(string) ( $sceu_order['number'] ?: $sceu_order['id'] )
	);

	$sceu_line_items = array();
	foreach ( (array) ( $sceu_order['line_items'] ?? array() ) as $sceu_li ) {
		$sceu_max = (int) ( $sceu_li['remaining'] ?? $sceu_li['quantity'] ?? 1 );
		$sceu_line_items[] = array(
			'id'        => (string) $sceu_li['id'],
			'name'      => (string) $sceu_li['name'],
			'max'       => $sceu_max,
			// Per-item quantity to withdraw, co-located on the item so the
			// Interactivity per-row state can never cross-wire between items.
			'qty'       => 0,
			/* translators: %d: quantity available to withdraw. */
			'availText' => sprintf( _n( '%d available', '%d available', $sceu_max, 'surecart-eu-helper' ), $sceu_max ),
			'unitText'  => (string) ( $sceu_li['unit_display'] ?? '' ),
			'image'     => (string) ( $sceu_li['image'] ?? '' ),
			'imageAlt'  => (string) ( $sceu_li['image_alt'] ?? '' ),
		);
	}

	$sceu_order_payload[] = array(
		'id'         => (string) $sceu_order['id'],
		'label'      => $sceu_label,
		'meta'       => implode( ' · ', $sceu_meta ),
		'summary'    => (string) $sceu_order['summary'],
		'wholeOrder' => ! empty( $sceu_order['whole_order'] ) || empty( $sceu_line_items ),
		// Per-order checkbox state, co-located on the order object.
		'selected'   => false,
		'lineItems'  => $sceu_line_items,
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
		'details'     => array_map( 'strval', (array) ( $sceu_request['details'] ?? array() ) ),
	);
}

$sceu_uid     = wp_unique_id( 'sceu-row-' );
$sceu_ns      = 'surecart-eu-helper';
$sceu_display = 'inline' === $sceu_display ? 'inline' : 'modal';

// Shared (per-page) Interactivity state: the endpoint, the REST nonce, and the
// handful of strings the store needs at runtime. Printed once per page.
wp_interactivity_state(
	$sceu_ns,
	array(
		'restUrl' => esc_url_raw( rest_url( 'surecart-eu-helper/v1/withdrawal-request' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'i18n'    => array(
			// Reset value for the confirm button (matches $sceu_confirm).
			'submit'           => $sceu_confirm,
			'sending'          => __( 'Sending…', 'surecart-eu-helper' ),
			'selectOne'        => __( 'Please select at least one order.', 'surecart-eu-helper' ),
			'error'            => __( 'Something went wrong. Please try again.', 'surecart-eu-helper' ),
			'notEligible'      => __( 'You are not eligible for this request. This feature is available to EU customers with recent orders.', 'surecart-eu-helper' ),
			'invalidNonce'     => __( 'Your session has expired. Please refresh the page and try again.', 'surecart-eu-helper' ),
			'moduleDisabled'   => __( 'This feature is not available.', 'surecart-eu-helper' ),
			'tooMany'          => __( 'Please wait a moment before submitting another request.', 'surecart-eu-helper' ),
			'noSelection'      => __( 'Please select at least one valid order.', 'surecart-eu-helper' ),
			// Label words the client uses to build a newly-submitted request row.
			'ordersLabel'      => __( 'Orders', 'surecart-eu-helper' ),
			'submittedLabel'   => __( 'Submitted', 'surecart-eu-helper' ),
			/* translators: %d: quantity available to withdraw. */
			'availableTemplate' => __( '%d available', 'surecart-eu-helper' ),
			/* translators: %s: product name. Accessible label for the quantity input. */
			'qtyLabel'          => __( 'Quantity to withdraw of %s', 'surecart-eu-helper' ),
			/* translators: %s: product name. */
			'incLabel'          => __( 'Increase quantity of %s', 'surecart-eu-helper' ),
			/* translators: %s: product name. */
			'decLabel'          => __( 'Decrease quantity of %s', 'surecart-eu-helper' ),
			/* translators: 1: product name, 2: chosen quantity, 3: available quantity. Screen-reader announcement. */
			'qtyAnnounce'       => __( '%1$s: withdrawing %2$s of %3$s', 'surecart-eu-helper' ),
			'entireOrder'       => __( 'Entire order', 'surecart-eu-helper' ),
		),
	)
);

// Client-side requests payload: pre-rendered display strings + per-status
// booleans so the data-wp-each list (and badge colours) render correctly
// server-side without relying on JS state getters.
$sceu_date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$sceu_requests_client = array();
foreach ( $sceu_request_payload as $sceu_request ) {
	$sceu_req_orders = ! empty( $sceu_request['orders'] ) ? implode( ', ', $sceu_request['orders'] ) : '';
	// Format the stored timestamp the same way the REST response does, so a
	// freshly-submitted row (appended client-side) matches the rest.
	$sceu_req_date = '';
	if ( '' !== $sceu_request['date'] ) {
		$sceu_ts       = strtotime( (string) $sceu_request['date'] );
		$sceu_req_date = $sceu_ts ? date_i18n( $sceu_date_fmt, $sceu_ts ) : (string) $sceu_request['date'];
	}
	$sceu_requests_client[] = array(
		'id'          => $sceu_request['id'],
		'statusLabel' => $sceu_request['statusLabel'],
		'ordersText'  => __( 'Orders', 'surecart-eu-helper' ) . ': ' . $sceu_req_orders,
		'detailsText' => ! empty( $sceu_request['details'] ) ? implode( '; ', $sceu_request['details'] ) : '',
		'dateText'    => '' !== $sceu_req_date ? __( 'Submitted', 'surecart-eu-helper' ) . ': ' . $sceu_req_date : '',
		'isReceived'  => 'received' === $sceu_request['status'],
		'isResolved'  => 'resolved' === $sceu_request['status'],
		'isRejected'  => 'rejected' === $sceu_request['status'],
	);
}

// Per-instance local state for this block, hydrated into data-wp-context. The
// email is the verified account email and is never edited (security fix #1); the
// confirmation is always delivered to it server-side regardless of this value.
$sceu_context = array(
	'panel'        => 'none', // none | form | review | requests | confirmation.
	'display'      => $sceu_display,
	'submitting'   => false,
	'submitLabel'  => $sceu_confirm,
	'status'       => '',
	'name'         => $sceu_customer->customer_name(),
	'email'        => $sceu_customer->customer_email(),
	'reason'       => '',
	'modalTitle'   => $sceu_modal_title,
	'reviewTitle'  => __( 'Confirm your withdrawal', 'surecart-eu-helper' ),
	'reviewSummary' => '',
	'requestsTitle' => __( 'Your withdrawal requests', 'surecart-eu-helper' ),
	// Per-order item summary of the just-submitted request, shown on the
	// confirmation screen so it's explicit what was withdrawn.
	'confirmedDetails' => array(),
	'confirmedHeading' => __( 'You requested to withdraw:', 'surecart-eu-helper' ),
	// Reactive lists/flags so the UI updates after a submission without a reload.
	'orders'       => $sceu_order_payload,
	'requests'     => $sceu_requests_client,
	'hasOrders'    => $sceu_has_withdrawable,
	'hasRequests'  => ! empty( $sceu_requests_client ),
);

// Expose the brand colour as CSS variables on the wrapper. The modal now lives
// inside the wrapper (not appended to <body>), so the vars inherit naturally.
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

// The front-end style (view.css) and the Interactivity API view module
// (view.js) are declared in block.json ("style" / "viewScriptModule") and
// enqueued by core when the block renders. Because every code path above
// returns '' for ineligible visitors, core's empty-content handling keeps the
// style off pages where the block produces no output.

/**
 * Build the form markup once so it can be placed inside either the modal dialog
 * or the inline panel. Captured to a string via output buffering.
 */
ob_start();
?>
<form class="sceu-form" novalidate data-wp-on--submit="actions.review">
	<div class="sceu-field">
		<label class="sceu-field__label" for="<?php echo esc_attr( $sceu_uid ); ?>-name"><?php echo esc_html__( 'Your name', 'surecart-eu-helper' ); ?></label>
		<input type="text" class="sceu-field__input" id="<?php echo esc_attr( $sceu_uid ); ?>-name"
			value="<?php echo esc_attr( $sceu_customer->customer_name() ); ?>"
			data-wp-bind--value="context.name" data-wp-on--input="actions.setName" />
	</div>
	<div class="sceu-field">
		<label class="sceu-field__label" for="<?php echo esc_attr( $sceu_uid ); ?>-email"><?php echo esc_html__( 'Your email', 'surecart-eu-helper' ); ?></label>
		<input type="email" class="sceu-field__input sceu-field__input--readonly" id="<?php echo esc_attr( $sceu_uid ); ?>-email"
			value="<?php echo esc_attr( $sceu_customer->customer_email() ); ?>" readonly aria-readonly="true" />
	</div>
	<div class="sceu-field">
		<span class="sceu-field__label"><?php echo esc_html__( 'Select the orders you want to withdraw from', 'surecart-eu-helper' ); ?></span>
		<div class="sceu-orders" role="group" aria-label="<?php echo esc_attr__( 'Select the orders you want to withdraw from', 'surecart-eu-helper' ); ?>">
			<template data-wp-each--order="context.orders" data-wp-each-key="context.order.id">
				<div class="sceu-orders__item" data-wp-bind--data-sceu-order="context.order.id">
					<label class="sceu-orders__head">
						<input type="checkbox" class="sceu-orders__cb"
							data-wp-bind--value="context.order.id"
							data-wp-bind--checked="context.order.selected"
							data-wp-on--change="actions.toggleOrder" />
						<span class="sceu-orders__text">
							<span class="sceu-orders__label" data-wp-text="context.order.label"></span>
							<span class="sceu-orders__summary" data-wp-bind--title="context.order.summary" data-wp-text="context.order.summary" data-wp-bind--hidden="!context.order.summary"></span>
							<span class="sceu-orders__meta" data-wp-text="context.order.meta" data-wp-bind--hidden="!context.order.meta"></span>
						</span>
					</label>
					<div class="sceu-items" data-wp-bind--hidden="!state.showItems">
						<p class="sceu-items__hint"><?php echo esc_html__( 'Choose how many of each item to withdraw.', 'surecart-eu-helper' ); ?></p>
						<template data-wp-each--item="context.order.lineItems" data-wp-each-key="context.item.id">
							<div class="sceu-item">
								<img class="sceu-item__img" data-wp-bind--src="context.item.image" data-wp-bind--alt="context.item.imageAlt" data-wp-bind--hidden="!context.item.image" alt="" />
								<span class="sceu-item__text">
									<span class="sceu-item__name" data-wp-text="context.item.name"></span>
									<span class="sceu-item__avail" data-wp-text="context.item.availText"></span>
								</span>
								<span class="sceu-item__qty">
									<button type="button" class="sceu-step"
										data-wp-on--click="actions.decItem"
										data-wp-bind--disabled="state.decDisabled"
										data-wp-bind--data-sceu-item="context.item.id"
										data-wp-bind--aria-label="state.decLabel">&minus;</button>
									<input type="number" class="sceu-item__input" min="0" step="1"
										data-wp-bind--max="context.item.max"
										data-wp-bind--value="context.item.qty"
										data-wp-on--input="actions.setItemQty"
										data-wp-bind--data-sceu-item="context.item.id"
										data-wp-bind--aria-label="state.itemQtyLabel" />
									<button type="button" class="sceu-step"
										data-wp-on--click="actions.incItem"
										data-wp-bind--disabled="state.incDisabled"
										data-wp-bind--data-sceu-item="context.item.id"
										data-wp-bind--aria-label="state.incLabel">+</button>
								</span>
								<span class="sceu-sr-only" role="status" aria-live="polite" data-wp-text="state.itemAnnounce"></span>
							</div>
						</template>
					</div>
				</div>
			</template>
		</div>
	</div>
	<div class="sceu-field">
		<label class="sceu-field__label" for="<?php echo esc_attr( $sceu_uid ); ?>-reason"><?php echo esc_html__( 'Reason (optional)', 'surecart-eu-helper' ); ?></label>
		<textarea class="sceu-field__input" id="<?php echo esc_attr( $sceu_uid ); ?>-reason" rows="3" data-wp-on--input="actions.setReason"></textarea>
	</div>
	<p class="sceu-form__status" role="status" aria-live="polite" data-wp-text="context.status"></p>
	<div class="sceu-form__actions">
		<sc-button type="primary" submit><?php echo esc_html__( 'Continue', 'surecart-eu-helper' ); ?></sc-button>
		<sc-button type="text" data-wp-on--click="actions.close"><?php echo esc_html__( 'Cancel', 'surecart-eu-helper' ); ?></sc-button>
	</div>
</form>
<?php
$sceu_form_html = ob_get_clean();

/**
 * Build the requests list once (read-only).
 */
ob_start();
?>
<div class="sceu-requests">
	<template data-wp-each--request="context.requests" data-wp-each-key="context.request.id">
		<div class="sceu-requests__item">
			<div class="sceu-requests__head">
				<span class="sceu-requests__orders" data-wp-text="context.request.ordersText"></span>
				<span class="sceu-badge"
					data-wp-class--sceu-badge--received="context.request.isReceived"
					data-wp-class--sceu-badge--resolved="context.request.isResolved"
					data-wp-class--sceu-badge--rejected="context.request.isRejected"
					data-wp-text="context.request.statusLabel"></span>
			</div>
			<div class="sceu-requests__items" data-wp-text="context.request.detailsText" data-wp-bind--hidden="!context.request.detailsText"></div>
			<div class="sceu-requests__date" data-wp-text="context.request.dateText" data-wp-bind--hidden="!context.request.dateText"></div>
		</div>
	</template>
</div>
<?php
$sceu_requests_html = ob_get_clean();

/**
 * Build the review/confirmation step (step 2). It reproduces the declaration the
 * consumer entered (name, email, selected orders, optional reason) and carries
 * the separate confirmation function required by § 356a — the actual submission
 * only happens here, on the dedicated confirm button.
 */
ob_start();
?>
<div class="sceu-review">
	<p class="sceu-review__lead"><?php echo esc_html__( 'Please review your withdrawal declaration before confirming.', 'surecart-eu-helper' ); ?></p>
	<dl class="sceu-review__summary">
		<dt class="sceu-review__term"><?php echo esc_html__( 'Your name', 'surecart-eu-helper' ); ?></dt>
		<dd class="sceu-review__detail" data-wp-text="context.name"></dd>
		<dt class="sceu-review__term"><?php echo esc_html__( 'Your email', 'surecart-eu-helper' ); ?></dt>
		<dd class="sceu-review__detail" data-wp-text="context.email"></dd>
		<dt class="sceu-review__term"><?php echo esc_html__( 'Orders', 'surecart-eu-helper' ); ?></dt>
		<dd class="sceu-review__detail">
			<div class="sceu-review__orders" data-wp-text="context.reviewSummary"></div>
		</dd>
		<div data-wp-bind--hidden="!context.reason">
			<dt class="sceu-review__term"><?php echo esc_html__( 'Reason (optional)', 'surecart-eu-helper' ); ?></dt>
			<dd class="sceu-review__detail" data-wp-text="context.reason"></dd>
		</div>
	</dl>
	<p class="sceu-form__status" role="status" aria-live="polite" data-wp-text="context.status"></p>
	<div class="sceu-form__actions">
		<sc-button type="primary" data-wp-on--click="actions.submit" data-wp-bind--disabled="context.submitting" data-wp-text="context.submitLabel"><?php echo esc_html( $sceu_confirm ); ?></sc-button>
		<sc-button type="default" data-wp-on--click="actions.back" data-wp-bind--disabled="context.submitting"><?php echo esc_html__( 'Back', 'surecart-eu-helper' ); ?></sc-button>
	</div>
</div>
<?php
$sceu_review_html = ob_get_clean();

// Output is captured by WordPress's block render (echo, do not return markup).
?>
<div <?php echo $sceu_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes is escaped. ?>
	data-wp-interactive="<?php echo esc_attr( $sceu_ns ); ?>"
	<?php echo wp_interactivity_data_wp_context( $sceu_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes. ?>
	data-wp-watch="callbacks.onPanelChange"
	data-sceu-uid="<?php echo esc_attr( $sceu_uid ); ?>"
	data-sceu-display="<?php echo esc_attr( $sceu_display ); ?>">

	<div class="sceu-row__notice">
		<div class="sceu-row__text">
			<<?php echo esc_html( $sceu_heading_level ); ?> class="sceu-row__heading"><?php echo esc_html( $sceu_heading ); ?></<?php echo esc_html( $sceu_heading_level ); ?>>
			<p class="sceu-row__intro">
				<span data-wp-bind--hidden="!context.hasOrders"><?php echo esc_html( $sceu_intro ); ?></span>
				<span data-wp-bind--hidden="context.hasOrders"><?php echo esc_html( $sceu_submitted_intro ); ?></span>
			</p>
		</div>
		<div class="sceu-row__actions">
			<?php if ( ! $sceu_has_withdrawable && '' !== $sceu_summary_label ) : ?>
				<span class="sceu-badge sceu-badge--<?php echo esc_attr( $sceu_summary_status ); ?>"><?php echo esc_html( $sceu_summary_label ); ?></span>
			<?php endif; ?>
			<sc-button type="primary" data-wp-on--click="actions.openForm" data-wp-bind--hidden="!context.hasOrders"><?php echo esc_html( $sceu_button ); ?></sc-button>
			<sc-button type="default" data-wp-on--click="actions.openRequests" data-wp-bind--hidden="!context.hasRequests"><?php echo esc_html__( 'View my requests', 'surecart-eu-helper' ); ?></sc-button>
		</div>
	</div>

	<?php if ( 'modal' === $sceu_display ) : ?>
		<div class="sceu-modal" data-wp-bind--hidden="!state.isOpen" data-wp-on--click="actions.onOverlayClick" hidden>
			<div class="sceu-modal__dialog sceu-modal--<?php echo esc_attr( $sceu_scheme ); ?>"
				role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $sceu_uid ); ?>-mtitle"
				data-wp-on--keydown="actions.onDialogKeydown">
				<div class="sceu-modal__head">
					<h2 class="sceu-modal__title" id="<?php echo esc_attr( $sceu_uid ); ?>-mtitle" tabindex="-1" data-wp-text="state.heading"><?php echo esc_html( $sceu_modal_title ); ?></h2>
					<button type="button" class="sceu-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'surecart-eu-helper' ); ?>" data-wp-on--click="actions.close">&times;</button>
				</div>
				<div data-wp-bind--hidden="!state.isForm"><?php echo $sceu_form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></div>
				<div data-wp-bind--hidden="!state.isReview"><?php echo $sceu_review_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></div>
				<div data-wp-bind--hidden="!state.isRequests"><?php echo $sceu_requests_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></div>
				<div class="sceu-row__confirmation" role="status" aria-live="polite" data-wp-bind--hidden="!state.isConfirmation">
				<p class="sceu-row__confirmation-msg"><?php echo esc_html( $sceu_confirmation ); ?></p>
				<div class="sceu-confirmation__summary" data-wp-bind--hidden="!state.hasConfirmed">
					<p class="sceu-confirmation__heading" data-wp-text="context.confirmedHeading"></p>
					<ul class="sceu-confirmation__items">
						<template data-wp-each--line="context.confirmedDetails" data-wp-each-key="context.line">
							<li data-wp-text="context.line"></li>
						</template>
					</ul>
				</div>
			</div>
			</div>
		</div>
	<?php else : ?>
		<div class="sceu-row__panel" data-wp-bind--hidden="!state.isForm"><?php echo $sceu_form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></div>
		<div class="sceu-row__panel" data-wp-bind--hidden="!state.isReview"><?php echo $sceu_review_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></div>
		<div class="sceu-row__panel" data-wp-bind--hidden="!state.isRequests">
			<?php echo $sceu_requests_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
			<div class="sceu-form__actions">
				<button type="button" class="sceu-form__cancel" data-wp-on--click="actions.close"><?php echo esc_html__( 'Close', 'surecart-eu-helper' ); ?></button>
			</div>
		</div>
		<div class="sceu-row__confirmation" role="status" aria-live="polite" data-wp-bind--hidden="!state.isConfirmation">
				<p class="sceu-row__confirmation-msg"><?php echo esc_html( $sceu_confirmation ); ?></p>
				<div class="sceu-confirmation__summary" data-wp-bind--hidden="!state.hasConfirmed">
					<p class="sceu-confirmation__heading" data-wp-text="context.confirmedHeading"></p>
					<ul class="sceu-confirmation__items">
						<template data-wp-each--line="context.confirmedDetails" data-wp-each-key="context.line">
							<li data-wp-text="context.line"></li>
						</template>
					</ul>
				</div>
			</div>
	<?php endif; ?>
</div>
<?php
