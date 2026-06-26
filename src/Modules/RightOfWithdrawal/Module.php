<?php
/**
 * Right of Withdrawal module.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\ModuleInterface;
use SureCartEuHelper\Modules\RightOfWithdrawal\Rest\WithdrawalController;
use SureCartEuHelper\Modules\RightOfWithdrawal\Exclusions;
use SureCartEuHelper\Modules\RightOfWithdrawal\Form\PublicForm;
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable;
use SureCartEuHelper\Modules\RightOfWithdrawal\Admin\LogActions;

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
				'section'     => 'eligibility',
				'type'    => 'number',
				'label'   => __( 'Look-back window (days)', 'surecart-eu-helper' ),
				'default' => 14,
				'min'     => 1,
				'help'    => __( 'Show the notice for orders placed within this many days. The statutory window is usually 14 days, but you may extend it (e.g. 16–17) when goods are shipped and the clock starts on delivery.', 'surecart-eu-helper' ),
			),
			array(
				'key'     => 'apply_to',
				'section'     => 'eligibility',
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
				'section'            => 'eligibility',
				'type'           => 'toggle',
				'label'          => __( 'Customers without a country', 'surecart-eu-helper' ),
				'checkbox_label' => __( 'Show the notice to customers who have no country on file', 'surecart-eu-helper' ),
				'default'        => true,
				'help'           => __( 'Some checkout customers never have a country collected. When enabled, the notice is still shown to them. Customers with a known non-EU country are always excluded.', 'surecart-eu-helper' ),
			),
			array(
				'key'     => 'merchant_email',
				'section'     => 'notifications',
				'type'    => 'email',
				'label'   => __( 'Merchant notification email', 'surecart-eu-helper' ),
				'default' => '',
				'help'    => __( 'Where withdrawal requests are sent. Leave blank to use your SureCart store email (shown as the placeholder).', 'surecart-eu-helper' ),
			),
			array(
				'key'            => 'form_display',
				'section'            => 'form',
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
			array(
				'key'   => 'excluded_collection_ids',
				'section'   => 'exclusions',
				'type'  => 'collection_exclusions',
				'label' => __( 'Excluded collections', 'surecart-eu-helper' ),
				'help'  => __( 'Products in these collections are never offered for withdrawal. This is the easiest way to exclude many products at once (e.g. a "Digital downloads" or "Perishables" collection).', 'surecart-eu-helper' ),
			),
			array(
				'key'   => 'excluded_product_ids',
				'section'   => 'exclusions',
				'type'  => 'product_exclusions',
				'label' => __( 'Excluded products', 'surecart-eu-helper' ),
				'help'  => __( 'Search and add individual products to exclude. Use this for one-off exclusions on top of any excluded collections.', 'surecart-eu-helper' ),
			),
		);
	}

	/**
	 * Ordered settings sub-sections, each rendered as its own card with a
	 * heading + description (mirroring SureCart's settings layout). Fields are
	 * grouped by their `section` key.
	 *
	 * @return array<string, array{title:string,description:string}>
	 */
	public function settings_sections(): array {
		return array(
			'eligibility'   => array(
				'title'       => __( 'Eligibility', 'surecart-eu-helper' ),
				'description' => __( 'Who sees the withdrawal notice, and for which orders.', 'surecart-eu-helper' ),
			),
			'notifications' => array(
				'title'       => __( 'Notifications', 'surecart-eu-helper' ),
				'description' => __( 'Where withdrawal requests are sent.', 'surecart-eu-helper' ),
			),
			'form'          => array(
				'title'       => __( 'Customer form', 'surecart-eu-helper' ),
				'description' => __( 'How the withdrawal form appears to customers.', 'surecart-eu-helper' ),
			),
			'exclusions'    => array(
				'title'       => __( 'Product exclusions', 'surecart-eu-helper' ),
				'description' => __( 'Products that are never offered for withdrawal — for example perishable, made-to-order, or digital goods.', 'surecart-eu-helper' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_shortcode( 'sceu_withdrawal_form', array( $this, 'render_shortcode' ) );
		add_action(
			'rest_api_init',
			function () {
				( new WithdrawalController() )->register_routes();
				( new \SureCartEuHelper\Modules\RightOfWithdrawal\Rest\GuestController() )->register_routes();
			}
		);
		// When the excluded-collection set changes, rebuild the cached membership.
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );

		// Admin-post handlers for the request log live in their own controller.
		( new LogActions() )->register();

		// Keep this module's schema current on plugin upgrade (the central
		// `sceu_upgrade` hook fires once per version bump).
		add_action( 'sceu_upgrade', array( LogTable::class, 'maybe_create' ) );

		// Background rebuild of the collection→product-id exclusion cache, so the
		// customer-facing path never resolves collections inline.
		add_action( Exclusions::CRON_HOOK, array( Exclusions::class, 'rebuild_cache' ) );
	}

	/**
	 * Rebuild the exclusion cache when the excluded-collection set changes.
	 *
	 * @param mixed $old Old option value.
	 * @param mixed $new New option value.
	 * @return void
	 */
	public function on_settings_updated( $old, $new ): void {
		$extract = static function ( $value ): array {
			$ids = ( is_array( $value ) && isset( $value['right_of_withdrawal']['excluded_collection_ids'] ) )
				? (array) $value['right_of_withdrawal']['excluded_collection_ids']
				: array();
			$ids = array_map( 'strval', $ids );
			sort( $ids );
			return $ids;
		};

		if ( $extract( $old ) !== $extract( $new ) ) {
			Exclusions::flush_cache();
			Exclusions::rebuild_cache();
		}
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
		// Register the shared form style/script handles first so the withdrawal-form
		// block.json can reference the `sceu-withdrawal-form` style handle for both
		// its editor preview and front-end render.
		PublicForm::register_assets();

		register_block_type( SCEU_DIR . 'blocks/right-of-withdrawal' );
		register_block_type( SCEU_DIR . 'blocks/withdrawal-form' );
	}

	/**
	 * Render the public withdrawal form via shortcode (for non-block-editor
	 * sites). Shares one renderer with the block so markup + assets match.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'heading'         => '',
				'intro'           => '',
				'submit_label'    => '',
				'confirm_label'   => '',
				'success_message' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'sceu_withdrawal_form'
		);

		return PublicForm::render( $atts );
	}
}
