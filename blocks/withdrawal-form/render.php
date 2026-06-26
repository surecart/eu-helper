<?php
/**
 * Server render for the public Withdrawal Request Form block.
 *
 * Delegates to the shared PublicForm renderer so the block and the
 * [sceu_withdrawal_form] shortcode produce identical markup + assets.
 *
 * @package SureCartEuHelper
 *
 * @var array $attributes Block attributes (provided by WordPress).
 */

defined( 'ABSPATH' ) || exit;

$sceu_atts = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();

echo \SureCartEuHelper\Modules\RightOfWithdrawal\Form\PublicForm::render(
	array(
		'heading'         => (string) ( $sceu_atts['heading'] ?? '' ),
		'intro'           => (string) ( $sceu_atts['intro'] ?? '' ),
		'submit_label'    => (string) ( $sceu_atts['submit_label'] ?? '' ),
		'confirm_label'   => (string) ( $sceu_atts['confirm_label'] ?? '' ),
		'success_message' => (string) ( $sceu_atts['success_message'] ?? '' ),
	)
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PublicForm::render returns escaped markup.
