<?php
/**
 * Front-end (public) withdrawal form, shared by the block and the shortcode.
 *
 * Renders the initial email + order-number lookup step and enqueues the script
 * that drives the rest of the flow (item picker on a verified match, or a
 * free-text fallback). Logged-out friendly; a logged-out nonce survives page
 * caching for the nonce's lifetime.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal\Form;

use SureCartEuHelper\Modules\RightOfWithdrawal\Rest\GuestController;
use SureCartEuHelper\Modules\RightOfWithdrawal\Recaptcha;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the public withdrawal form and wires its assets.
 */
class PublicForm {

	const HANDLE = 'sceu-withdrawal-form';

	/**
	 * Register the script + style once (no-op if already registered).
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style( self::HANDLE, SCEU_URL . 'assets/withdrawal-form.css', array(), self::asset_version( 'assets/withdrawal-form.css' ) );
		wp_register_script( self::HANDLE, SCEU_URL . 'assets/withdrawal-form.js', array(), self::asset_version( 'assets/withdrawal-form.js' ), true );
	}

	/**
	 * Asset version derived from the file's modification time, so a changed file
	 * always gets a fresh URL and can't be served stale by a page/CDN cache
	 * (which keys on the URL). Falls back to the plugin version.
	 *
	 * @param string $relative_path Path under the plugin dir.
	 * @return string
	 */
	private static function asset_version( string $relative_path ): string {
		$file = SCEU_DIR . $relative_path;
		$mtime = file_exists( $file ) ? filemtime( $file ) : 0;
		return $mtime ? (string) $mtime : SCEU_VERSION;
	}

	/**
	 * Default, translatable copy. Merged with block/shortcode overrides.
	 *
	 * @return array<string, string>
	 */
	private static function defaults(): array {
		return array(
			'heading'        => __( 'Withdraw from a purchase', 'surecart-eu-helper' ),
			'intro'          => __( 'Enter the email you ordered with and your order number to start a withdrawal request.', 'surecart-eu-helper' ),
			'submit_label'   => __( 'Find my order', 'surecart-eu-helper' ),
			'confirm_label'  => __( 'Confirm withdrawal', 'surecart-eu-helper' ),
			'success_message' => __( 'Thank you. Your withdrawal request has been received and a confirmation has been emailed to you.', 'surecart-eu-helper' ),
		);
	}

	/**
	 * Render the form markup and enqueue its assets.
	 *
	 * @param array<string, mixed> $atts Heading/intro/etc. overrides.
	 * @return string HTML.
	 */
	public static function render( array $atts = array() ): string {
		self::register_assets();
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		// Optional spam protection: reuse SureCart's reCAPTCHA v3 when the merchant
		// enabled it here and SureCart's keys are configured. The script defines
		// window.grecaptcha; the form executes it just before a submit.
		$recaptcha = null;
		if ( Recaptcha::active() ) {
			wp_enqueue_script(
				'sceu-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( Recaptcha::site_key() ),
				array(),
				null, // Google's URL — no version query.
				true
			);
			$recaptcha = array(
				'siteKey' => Recaptcha::site_key(),
				'action'  => Recaptcha::ACTION,
			);
		}

		$copy = wp_parse_args( array_filter( $atts, 'strlen' ), self::defaults() );

		wp_localize_script(
			self::HANDLE,
			'sceuWF',
			array(
				'lookupUrl' => rest_url( GuestController::NAMESPACE . GuestController::LOOKUP_ROUTE ),
				'submitUrl' => rest_url( GuestController::NAMESPACE . GuestController::SUBMIT_ROUTE ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				// Present only when reCAPTCHA is active for this form; null otherwise.
				'recaptcha' => $recaptcha,
				'confirmLabel'   => $copy['confirm_label'],
				'successMessage' => $copy['success_message'],
				// Unverified (free-text) path: no confirmation email is sent, so the
				// message must not claim one was.
				'successUnverified' => __( 'Thank you. We have received your request and will follow up with you by email.', 'surecart-eu-helper' ),
				'i18n'      => array(
					'notFound'    => __( "We couldn't match that order. You can still tell us what you'd like to withdraw from below.", 'surecart-eu-helper' ),
					'describe'    => __( 'Describe what you would like to withdraw from', 'surecart-eu-helper' ),
					'sendRequest' => __( 'Send request', 'surecart-eu-helper' ),
					'continue'    => __( 'Continue', 'surecart-eu-helper' ),
					'back'        => __( 'Back', 'surecart-eu-helper' ),
					'searchAgain' => __( 'Look up a different order', 'surecart-eu-helper' ),
					'entireOrder' => __( 'Entire order', 'surecart-eu-helper' ),
					'withdrawing' => __( 'You are withdrawing:', 'surecart-eu-helper' ),
					'itemsHint'   => __( 'Choose how many of each item to withdraw.', 'surecart-eu-helper' ),
					/* translators: %d: quantity available to withdraw. */
					'available'   => __( '%d available', 'surecart-eu-helper' ),
					/* translators: %s: product name. */
					'qtyLabel'    => __( 'Quantity to withdraw of %s', 'surecart-eu-helper' ),
					/* translators: %s: product name. */
					'incLabel'    => __( 'Increase quantity of %s', 'surecart-eu-helper' ),
					/* translators: %s: product name. */
					'decLabel'    => __( 'Decrease quantity of %s', 'surecart-eu-helper' ),
					'orderLabel'  => __( 'Order', 'surecart-eu-helper' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'surecart-eu-helper' ),
					'nothingChosen' => __( 'Please choose at least one item, or use the description box.', 'surecart-eu-helper' ),
				),
			)
		);

		ob_start();
		?>
		<div class="sceu-wf" data-sceu-wf>
			<?php if ( '' !== $copy['heading'] ) : ?>
				<h3 class="sceu-wf__heading"><?php echo esc_html( $copy['heading'] ); ?></h3>
			<?php endif; ?>
			<?php if ( '' !== $copy['intro'] ) : ?>
				<p class="sceu-wf__intro"><?php echo esc_html( $copy['intro'] ); ?></p>
			<?php endif; ?>

			<form class="sceu-wf__lookup" data-sceu-step="lookup" novalidate>
				<div class="sceu-form-control sceu-form-control--has-label">
					<label class="sceu-form-control__label" for="sceu-wf-email">
						<?php echo esc_html__( 'Email address', 'surecart-eu-helper' ); ?>
						<span class="sceu-form-control__required" aria-hidden="true">*</span>
					</label>
					<div class="sceu-form-control__input">
						<input class="sceu-wf__input" type="email" id="sceu-wf-email" name="email" autocomplete="email" required aria-required="true" aria-describedby="sceu-wf-error" />
					</div>
				</div>
				<div class="sceu-form-control sceu-form-control--has-label">
					<label class="sceu-form-control__label" for="sceu-wf-number">
						<?php echo esc_html__( 'Order number', 'surecart-eu-helper' ); ?>
						<span class="sceu-form-control__required" aria-hidden="true">*</span>
					</label>
					<div class="sceu-form-control__input">
						<input class="sceu-wf__input" type="text" id="sceu-wf-number" name="order_number" autocomplete="off" required aria-required="true" aria-describedby="sceu-wf-error" />
					</div>
				</div>
				<div class="sceu-wf__hp" aria-hidden="true">
					<label><?php echo esc_html__( 'Leave this field empty', 'surecart-eu-helper' ); ?>
						<input type="text" name="hp" tabindex="-1" autocomplete="off" />
					</label>
				</div>
				<button type="submit" class="sceu-wf__submit"><?php echo esc_html( $copy['submit_label'] ); ?></button>
				<p class="sceu-wf__error" id="sceu-wf-error" role="alert" hidden></p>
			</form>

			<div class="sceu-wf__result" data-sceu-result role="region" aria-label="<?php echo esc_attr__( 'Your withdrawal request', 'surecart-eu-helper' ); ?>" tabindex="-1" hidden></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
