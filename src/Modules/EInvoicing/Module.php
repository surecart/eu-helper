<?php
/**
 * E-Invoicing / Peppol module.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Merchant\BrandColor;
use SureCartEuHelper\Modules\ModuleInterface;
use SureCartEuHelper\Modules\EInvoicing\Domain\Document;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;
use SureCartEuHelper\Modules\EInvoicing\Domain\Environment;
use SureCartEuHelper\Modules\EInvoicing\Mapping\MerchantProfile;
use SureCartEuHelper\Modules\EInvoicing\Persistence\DocumentTable;
use SureCartEuHelper\Modules\EInvoicing\Persistence\EventsTable;
use SureCartEuHelper\Modules\EInvoicing\Persistence\WebhookLogTable;
use SureCartEuHelper\Modules\EInvoicing\Providers\ProviderRegistry;
use SureCartEuHelper\Modules\EInvoicing\Rest\OrderSearchController;
use SureCartEuHelper\Modules\EInvoicing\Services\DocumentService;
use SureCartEuHelper\Modules\EInvoicing\Services\Queue;
use SureCartEuHelper\Modules\EInvoicing\Admin\DocumentsListTable;

defined( 'ABSPATH' ) || exit;

/**
 * The e-invoicing module. Generates a local invoice document when an order is
 * paid (and via a manual action), optionally submits it to a provider (Storecove
 * first) per the auto_send setting, and exposes a document log. Provider-agnostic
 * throughout — Storecove specifics live entirely in the provider adapter.
 */
class Module implements ModuleInterface {

	const LOG_PAGE = 'sceu-einvoicing-log';

	/** @var ProviderRegistry */
	private $providers;

	/** @var string The document-log page hook (set when the submenu is added). */
	private $page_hook = '';

	public function __construct() {
		$this->providers = new ProviderRegistry();

		// Settings-storage concerns must work even when the module is disabled
		// (you configure credentials before enabling), so they are registered at
		// construction, not in boot(). Admin-only.
		if ( is_admin() ) {
			add_action( 'sceu_register_settings', array( $this, 'register_extra_settings' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'einvoicing';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'E-Invoicing (Peppol)', 'surecart-eu-helper' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Generate electronic invoices from SureCart orders and transmit them over a Peppol provider (Storecove). Keeps a local document log with delivery status; credit notes from refunds arrive in a later release.', 'surecart-eu-helper' );
	}

	/**
	 * Dashicon (without the `dashicons-` prefix) for this module's nav + panel
	 * icon. Read by the settings page via an optional-method check.
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'media-document';
	}

	/**
	 * {@inheritDoc}
	 */
	public function disclaimer(): string {
		return __( 'It is your responsibility to ensure the invoices you transmit meet the legal and tax requirements of your jurisdiction. This module is a tool to map and send documents — it is not legal or tax advice. Test thoroughly in the sandbox environment before going live.', 'surecart-eu-helper' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields(): array {
		$first_key  = '';
		foreach ( $this->providers->all() as $key => $provider ) {
			$first_key = $key;
			break;
		}
		$active_key = (string) Settings::get( 'einvoicing', 'provider', $first_key );

		$fields = array(
			array(
				'key'     => 'provider',
				'section' => 'provider',
				'type'    => 'select',
				'label'   => __( 'Provider', 'surecart-eu-helper' ),
				'default' => $first_key,
				'options' => $this->providers->options(),
				'help'    => __( 'The e-invoicing network provider to transmit through.', 'surecart-eu-helper' ),
			),
			array(
				'key'     => 'environment',
				'section' => 'provider',
				'type'    => 'radio',
				'label'   => __( 'Environment', 'surecart-eu-helper' ),
				'default' => Environment::SANDBOX,
				'options' => array(
					array(
						'value' => Environment::SANDBOX,
						'label' => __( 'Sandbox (testing)', 'surecart-eu-helper' ),
					),
					array(
						'value' => Environment::PRODUCTION,
						'label' => __( 'Production (live)', 'surecart-eu-helper' ),
					),
				),
				'help'    => __( 'Always validate in Sandbox first. Production transmits real, legally-binding documents.', 'surecart-eu-helper' ),
			),
		);

		// Active provider's sender-identity fields, keyed with the provider prefix
		// so two providers never collide (read back via ProviderSettings).
		$provider = '' !== $active_key ? $this->providers->get( $active_key ) : null;
		if ( $provider ) {
			foreach ( $provider->sender_fields() as $field ) {
				$field['key']     = ProviderSettings::key( $active_key, (string) ( $field['key'] ?? '' ) );
				$field['section'] = $field['section'] ?? 'sender';
				$fields[]         = $field;
			}
		}

		// Merchant business invoicing profile.
		$fields = array_merge( $fields, $this->merchant_fields() );

		// Behaviour.
		$fields[] = array(
			'key'            => 'auto_send',
			'section'        => 'behaviour',
			'type'           => 'toggle',
			'label'          => __( 'Automatic submission', 'surecart-eu-helper' ),
			'checkbox_label' => __( 'Transmit invoices automatically when an order is paid', 'surecart-eu-helper' ),
			'default'        => false,
			'help'           => __( 'When off, invoices are generated locally and you transmit them with a click from the document log. Leave off until you have validated in the sandbox.', 'surecart-eu-helper' ),
		);
		$fields[] = array(
			'key'     => 'invoice_prefix',
			'section' => 'behaviour',
			'type'    => 'text',
			'label'   => __( 'Invoice number prefix', 'surecart-eu-helper' ),
			'default' => 'INV-',
		);
		$fields[] = array(
			'key'     => 'credit_note_prefix',
			'section' => 'behaviour',
			'type'    => 'text',
			'label'   => __( 'Credit note number prefix', 'surecart-eu-helper' ),
			'default' => 'CN-',
		);

		return $fields;
	}

	/**
	 * Merchant invoicing-profile field schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function merchant_fields(): array {
		$text = function ( $key, $label, $help = '' ) {
			return array(
				'key'     => $key,
				'section' => 'business',
				'type'    => 'text',
				'label'   => $label,
				'help'    => $help,
				'default' => '',
			);
		};

		return array(
			$text( 'merchant_legal_name', __( 'Legal business name', 'surecart-eu-helper' ), __( 'Defaults to your SureCart store name when blank.', 'surecart-eu-helper' ) ),
			$text( 'merchant_vat', __( 'VAT / tax number', 'surecart-eu-helper' ) ),
			$text( 'merchant_country', __( 'Country code', 'surecart-eu-helper' ), __( 'Two-letter ISO code, e.g. DE, FR, NL.', 'surecart-eu-helper' ) ),
			$text( 'merchant_address_line1', __( 'Address line 1', 'surecart-eu-helper' ) ),
			$text( 'merchant_address_line2', __( 'Address line 2', 'surecart-eu-helper' ) ),
			$text( 'merchant_city', __( 'City', 'surecart-eu-helper' ) ),
			$text( 'merchant_postal_code', __( 'Postal code', 'surecart-eu-helper' ) ),
			$text( 'merchant_region', __( 'Region / state', 'surecart-eu-helper' ) ),
			array(
				'key'     => 'merchant_email',
				'section' => 'business',
				'type'    => 'email',
				'label'   => __( 'Invoicing email', 'surecart-eu-helper' ),
				'default' => '',
				'help'    => __( 'Defaults to your SureCart store email when blank.', 'surecart-eu-helper' ),
			),
			$text( 'merchant_peppol_scheme', __( 'Peppol scheme', 'surecart-eu-helper' ), __( 'Your Peppol electronic-address scheme (e.g. 9930 for DE:VAT). Optional.', 'surecart-eu-helper' ) ),
			$text( 'merchant_peppol_id', __( 'Peppol identifier', 'surecart-eu-helper' ), __( 'Your Peppol participant identifier. Optional.', 'surecart-eu-helper' ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_sections(): array {
		return array(
			'provider'  => array(
				'title'       => __( 'Provider', 'surecart-eu-helper' ),
				'description' => __( 'Which network provider to send through, and whether you are testing or live.', 'surecart-eu-helper' ),
			),
			'sender'    => array(
				'title'       => __( 'Sender identity', 'surecart-eu-helper' ),
				'description' => __( 'Provider-specific identifiers for you as the sender.', 'surecart-eu-helper' ),
			),
			'business'  => array(
				'title'       => __( 'Business invoicing profile', 'surecart-eu-helper' ),
				'description' => __( 'Your legal seller details, printed on every invoice. SureCart does not store a full registered address, so set it here.', 'surecart-eu-helper' ),
			),
			'behaviour' => array(
				'title'       => __( 'Behaviour', 'surecart-eu-helper' ),
				'description' => __( 'How and when invoices are transmitted, and how they are numbered.', 'surecart-eu-helper' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot(): void {
		// Background submission queue (WP-Cron).
		Queue::register();

		// Generate an invoice when an order is paid. The exact hook + the order id
		// on the checkout object must be confirmed on the live install (verify);
		// the manual "Create invoice" tool is the reliable fallback.
		add_action( 'surecart/checkout_confirmed', array( $this, 'on_order_paid' ), 10, 2 );

		// Admin: document-log submenu + actions.
		add_action( 'sceu_admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_post_sceu_einv_create_invoice', array( $this, 'handle_create_invoice' ) );
		add_action( 'admin_post_sceu_einv_submit', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_sceu_einv_retry', array( $this, 'handle_retry' ) );
		add_action( 'admin_post_sceu_einv_evidence', array( $this, 'handle_evidence' ) );
		add_action( 'admin_post_sceu_einv_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_sceu_einv_send_test', array( $this, 'handle_send_test' ) );

		// Admin order-search endpoint for the manual invoice picker.
		add_action(
			'rest_api_init',
			static function () {
				( new OrderSearchController() )->register_routes();
			}
		);
	}

	/**
	 * Register the secrets option on the settings group so it saves with the main
	 * Save button, and ensure it exists non-autoloaded.
	 *
	 * @param string $group Settings group.
	 * @return void
	 */
	public function register_extra_settings( string $group ): void {
		add_option( Secrets::OPTION, array(), '', 'no' );
		register_setting(
			$group,
			Secrets::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_secrets' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize posted secrets: a blank field keeps the existing value (so the
	 * masked placeholder need not be re-typed); a non-empty field replaces it.
	 *
	 * @param mixed $input Posted secrets.
	 * @return array<string,string>
	 */
	public function sanitize_secrets( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = get_option( Secrets::OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		foreach ( $input as $key => $value ) {
			$key   = preg_replace( '/[^a-z0-9_]/', '', (string) $key );
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $key || '' === $value ) {
				continue; // Blank = keep existing.
			}
			$current[ $key ] = Secrets::encrypt_value( $value, $key );
		}

		return $current;
	}

	/**
	 * Render the provider connection (credentials) card inside the module's
	 * settings panel. Secret inputs post under the separate, non-autoloaded
	 * secrets option (see register_extra_settings); blank = keep existing.
	 *
	 * @param string $module_id Panel module id.
	 * @return void
	 */
	public function render_settings_extra( string $module_id ): void {
		if ( $this->id() !== $module_id ) {
			return;
		}

		$active_key = (string) Settings::get( 'einvoicing', 'provider', '' );
		if ( '' === $active_key ) {
			foreach ( $this->providers->all() as $key => $p ) {
				$active_key = $key;
				break;
			}
		}
		$provider = '' !== $active_key ? $this->providers->get( $active_key ) : null;
		if ( ! $provider ) {
			return;
		}

		$secret_fields = $provider->settings_fields();
		?>
		<div class="sceu-section__head">
			<h3 class="sceu-section__title"><?php echo esc_html__( 'Connection', 'surecart-eu-helper' ); ?></h3>
			<p class="sceu-section__desc">
				<?php echo esc_html__( 'API credentials, stored separately from the rest of the settings. Leave a field blank to keep the saved value.', 'surecart-eu-helper' ); ?>
			</p>
		</div>
		<div class="sceu-card">
			<?php foreach ( array( Environment::SANDBOX, Environment::PRODUCTION ) as $env ) : ?>
				<h4 style="margin:0.5em 0;"><?php echo esc_html( Environment::SANDBOX === $env ? __( 'Sandbox', 'surecart-eu-helper' ) : __( 'Production', 'surecart-eu-helper' ) ); ?></h4>
				<?php foreach ( $secret_fields as $field ) : ?>
					<?php
					$fkey   = (string) ( $field['key'] ?? '' );
					$skey   = Secrets::key( $active_key, $env, $fkey );
					$masked = Secrets::masked( $skey );
					$name   = Secrets::OPTION . '[' . $skey . ']';
					$id_at  = 'sceu-secret-' . $skey;
					?>
					<div class="sceu-field">
						<label class="sceu-field__label" for="<?php echo esc_attr( $id_at ); ?>"><?php echo esc_html( (string) ( $field['label'] ?? $fkey ) ); ?></label>
						<div class="sceu-field__control">
							<input type="password" autocomplete="off" id="<?php echo esc_attr( $id_at ); ?>"
								name="<?php echo esc_attr( $name ); ?>"
								placeholder="<?php echo esc_attr( '' !== $masked ? $masked : __( 'Not set', 'surecart-eu-helper' ) ); ?>" />
						</div>
						<?php if ( ! empty( $field['help'] ) ) : ?>
							<p class="sceu-field__help"><?php echo esc_html( (string) $field['help'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Add the document-log submenu when the module is enabled.
	 *
	 * @param string $parent Parent menu slug.
	 * @return void
	 */
	public function add_menu( string $parent ): void {
		$this->page_hook = (string) add_submenu_page(
			$parent,
			__( 'E-Invoicing', 'surecart-eu-helper' ),
			__( 'E-Invoicing', 'surecart-eu-helper' ),
			'manage_options',
			self::LOG_PAGE,
			array( $this, 'render_documents_page' )
		);
	}

	/**
	 * Load the shared settings shell styles + this page's table styles on the
	 * document-log page (the shared CSS otherwise only loads on the settings page).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( '' === $this->page_hook || $hook !== $this->page_hook ) {
			return;
		}

		$shell = SCEU_DIR . 'assets/admin-settings.css';
		wp_enqueue_style(
			'sceu-admin-settings',
			SCEU_URL . 'assets/admin-settings.css',
			array( 'dashicons' ),
			file_exists( $shell ) ? (string) filemtime( $shell ) : SCEU_VERSION
		);

		$css = SCEU_DIR . 'assets/admin-einvoicing.css';
		wp_enqueue_style(
			'sceu-admin-einvoicing',
			SCEU_URL . 'assets/admin-einvoicing.css',
			array( 'sceu-admin-settings' ),
			file_exists( $css ) ? (string) filemtime( $css ) : SCEU_VERSION
		);

		$js = SCEU_DIR . 'assets/admin-einvoicing.js';
		wp_enqueue_script(
			'sceu-admin-einvoicing',
			SCEU_URL . 'assets/admin-einvoicing.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : SCEU_VERSION,
			true
		);
		wp_localize_script(
			'sceu-admin-einvoicing',
			'sceuEinv',
			array(
				'searchUrl' => rest_url( OrderSearchController::NAMESPACE . OrderSearchController::ROUTE ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'searching' => __( 'Searching…', 'surecart-eu-helper' ),
					'none'      => __( 'No matching orders', 'surecart-eu-helper' ),
				),
			)
		);
	}

	/**
	 * Add a body class on the document-log page so the full-bleed tint + footer
	 * hide can target it (mirrors the settings page).
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function admin_body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && '' !== $this->page_hook && $screen->id === $this->page_hook ) {
			$classes .= ' sceu-fullbleed';
		}
		return $classes;
	}

	/**
	 * Render the document log + manual tools.
	 *
	 * @return void
	 */
	public function render_documents_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		DocumentTable::maybe_create();
		EventsTable::maybe_create();
		WebhookLogTable::maybe_create();

		$table = new DocumentsListTable();
		$table->prepare_items();

		// Tint the shell with the store's SureCart brand colour, like the settings page.
		$primary = BrandColor::primary();
		$ptext   = BrandColor::primary_text();
		$style   = '';
		if ( '' !== $primary ) {
			$style .= '--sceu-primary:' . $primary . ';';
			if ( '' !== $ptext ) {
				$style .= '--sceu-primary-text:' . $ptext . ';';
			}
		}
		$post_url = admin_url( 'admin-post.php' );
		?>
		<div class="sceu-app sceu-app--page" style="<?php echo esc_attr( $style ); ?>">
			<header class="sceu-app__bar">
				<div class="sceu-app__brand">
					<span class="sceu-app__badge dashicons dashicons-shield-alt" aria-hidden="true"></span>
					<span class="sceu-app__name"><?php echo esc_html__( 'SureCart EU Helper', 'surecart-eu-helper' ); ?></span>
					<span class="sceu-app__sep" aria-hidden="true">&rsaquo;</span>
					<span class="sceu-app__crumb"><?php echo esc_html__( 'E-Invoicing documents', 'surecart-eu-helper' ); ?></span>
				</div>
				<span class="sceu-app__meta">v<?php echo esc_html( SCEU_VERSION ); ?></span>
			</header>

			<div class="sceu-app__content">
				<div class="sceu-app__inner sceu-app__inner--wide">
					<div class="sceu-panel__head">
						<h2 class="sceu-panel__title">
							<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
							<?php echo esc_html__( 'E-Invoicing documents', 'surecart-eu-helper' ); ?>
						</h2>
					</div>

					<?php $this->admin_notice(); ?>

					<div class="sceu-section__head">
						<h3 class="sceu-section__title"><?php echo esc_html__( 'Tools', 'surecart-eu-helper' ); ?></h3>
						<p class="sceu-section__desc"><?php echo esc_html__( 'Create an invoice for an existing order, or check your provider connection.', 'surecart-eu-helper' ); ?></p>
					</div>
					<div class="sceu-card">
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="sceu_einv_create_invoice" />
							<?php wp_nonce_field( 'sceu_einv_create_invoice' ); ?>
							<div class="sceu-einv-tools">
								<div class="sceu-field sceu-orderpicker" data-sceu-orderpicker>
									<label class="sceu-field__label" for="sceu-einv-order"><?php echo esc_html__( 'Order', 'surecart-eu-helper' ); ?></label>
									<div class="sceu-field__control">
										<input type="search" id="sceu-einv-order" class="sceu-orderpicker__input" autocomplete="off"
											placeholder="<?php echo esc_attr__( 'Search by order number, customer, or email…', 'surecart-eu-helper' ); ?>" />
										<ul class="sceu-orderpicker__results" role="listbox" hidden></ul>
									</div>
									<div class="sceu-orderpicker__selected" hidden>
										<span class="sceu-orderpicker__selected-label"></span>
										<button type="button" class="sceu-orderpicker__clear" aria-label="<?php echo esc_attr__( 'Choose a different order', 'surecart-eu-helper' ); ?>">&times;</button>
									</div>
									<input type="hidden" name="order_id" class="sceu-orderpicker__value" value="" />
								</div>
								<button type="submit" class="sceu-btn--primary"><?php echo esc_html__( 'Create invoice', 'surecart-eu-helper' ); ?></button>
							</div>
							<p class="sceu-field__help"><?php echo esc_html__( 'Pick the order to invoice. New paid orders are invoiced automatically when Automatic submission is on.', 'surecart-eu-helper' ); ?></p>
						</form>

						<div class="sceu-einv-tools__sep" aria-hidden="true"></div>

						<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="sceu-einv-tools__row">
							<input type="hidden" name="action" value="sceu_einv_send_test" />
							<?php wp_nonce_field( 'sceu_einv_send_test' ); ?>
							<button type="submit" class="sceu-btn--secondary"><?php echo esc_html__( 'Send test document to sandbox', 'surecart-eu-helper' ); ?></button>
							<span class="description"><?php echo esc_html__( 'Validates your credentials and sender setup by submitting a minimal test invoice.', 'surecart-eu-helper' ); ?></span>
						</form>
					</div>

					<div class="sceu-section__head">
						<h3 class="sceu-section__title"><?php echo esc_html__( 'Documents', 'surecart-eu-helper' ); ?></h3>
						<p class="sceu-section__desc"><?php echo esc_html__( 'Every invoice and credit note, with its local and provider status.', 'surecart-eu-helper' ); ?></p>
					</div>
					<div class="sceu-card sceu-card--table">
						<form method="get">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::LOG_PAGE ); ?>" />
							<?php $table->display(); ?>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Show a transient admin notice from a redirect.
	 *
	 * @return void
	 */
	private function admin_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['sceu_einv_notice'] ) ) {
			$msg = sanitize_text_field( wp_unslash( (string) $_GET['sceu_einv_notice'] ) );
			$ok  = empty( $_GET['sceu_einv_error'] );
			printf(
				'<div class="sceu-notice sceu-notice--%s">%s</div>',
				$ok ? 'success' : 'error',
				esc_html( $msg )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Hook: an order was paid — generate (and maybe send) its invoice.
	 *
	 * @param mixed $checkout Checkout object.
	 * @param mixed $request  REST request (unused).
	 * @return void
	 */
	public function on_order_paid( $checkout, $request = null ): void {
		$order_id = '';
		if ( is_object( $checkout ) ) {
			$order_id = (string) ( $checkout->order_id ?? '' );
			if ( '' === $order_id && isset( $checkout->order ) && is_object( $checkout->order ) ) {
				$order_id = (string) ( $checkout->order->id ?? '' );
			}
		}
		if ( '' === $order_id ) {
			return; // Could not resolve the order id (verify the checkout shape).
		}

		( new DocumentService() )->ensure_invoice_for_order( $order_id );
	}

	/**
	 * Admin action: create an invoice from a typed order id.
	 *
	 * @return void
	 */
	public function handle_create_invoice(): void {
		$this->guard( 'sceu_einv_create_invoice' );

		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['order_id'] ) ) : '';
		if ( '' === $order_id ) {
			$this->redirect_notice( __( 'Enter a SureCart order ID.', 'surecart-eu-helper' ), false );
		}

		$result = ( new DocumentService() )->ensure_invoice_for_order( $order_id, false );
		if ( '' !== $result['error'] ) {
			$this->redirect_notice( $result['error'], false );
		}
		$this->redirect_notice(
			$result['created']
				? __( 'Invoice document created.', 'surecart-eu-helper' )
				: __( 'An invoice document already existed for that order.', 'surecart-eu-helper' ),
			true
		);
	}

	/**
	 * Admin action: queue a validated document for submission.
	 *
	 * @return void
	 */
	public function handle_submit(): void {
		$id = $this->guard_row( 'sceu_einv_submit' );
		$ok = ( new DocumentService() )->enqueue( $id );
		$this->redirect_notice(
			$ok ? __( 'Queued for submission.', 'surecart-eu-helper' ) : __( 'Document could not be queued.', 'surecart-eu-helper' ),
			$ok
		);
	}

	/**
	 * Admin action: retry a failed document.
	 *
	 * @return void
	 */
	public function handle_retry(): void {
		$id = $this->guard_row( 'sceu_einv_retry' );
		$ok = ( new DocumentService() )->enqueue( $id );
		$this->redirect_notice(
			$ok ? __( 'Re-queued for submission.', 'surecart-eu-helper' ) : __( 'Document could not be re-queued.', 'surecart-eu-helper' ),
			$ok
		);
	}

	/**
	 * Admin action: fetch + download delivery evidence.
	 *
	 * @return void
	 */
	public function handle_evidence(): void {
		$id  = $this->guard_row( 'sceu_einv_evidence' );
		$row = DocumentTable::find( $id );
		$guid = $row ? (string) ( $row['provider_guid'] ?? '' ) : '';
		if ( '' === $guid ) {
			$this->redirect_notice( __( 'No provider reference to fetch evidence for.', 'surecart-eu-helper' ), false );
		}

		$provider = $this->providers->active();
		if ( ! $provider ) {
			$this->redirect_notice( __( 'No provider configured.', 'surecart-eu-helper' ), false );
		}
		$provider->set_environment( (string) $row['environment'] );
		$evidence = $provider->fetch_evidence( $guid );

		if ( ! $evidence->available ) {
			$this->redirect_notice( $evidence->reason ?: __( 'Evidence is not available yet.', 'surecart-eu-helper' ), false );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $evidence->mime_type ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename=evidence-' . sanitize_file_name( $guid ) . '.json' );
		echo $evidence->contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Admin action: delete a document (test cleanup).
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		$id = $this->guard_row( 'sceu_einv_delete' );
		DocumentTable::delete( $id );
		$this->redirect_notice( __( 'Document deleted.', 'surecart-eu-helper' ), true );
	}

	/**
	 * Admin action: submit a synthetic test invoice to the sandbox.
	 *
	 * @return void
	 */
	public function handle_send_test(): void {
		$this->guard( 'sceu_einv_send_test' );

		$provider = $this->providers->active();
		if ( ! $provider ) {
			$this->redirect_notice( __( 'Select a provider first.', 'surecart-eu-helper' ), false );
		}
		$env = Environment::normalize( Settings::get( 'einvoicing', 'environment', Environment::SANDBOX ) );
		$provider->set_environment( $env );
		if ( ! $provider->is_configured() ) {
			$this->redirect_notice( __( 'The provider is not fully configured (API key and sender identity).', 'surecart-eu-helper' ), false );
		}

		$result = $provider->submit( $this->build_test_document( $env ), wp_generate_uuid4() );

		if ( DocumentStatus::SUBMITTED === $result->status && $result->provider_guid ) {
			$this->redirect_notice(
				sprintf(
					/* translators: %s: provider GUID. */
					__( 'Test document accepted. Provider reference: %s', 'surecart-eu-helper' ),
					$result->provider_guid
				),
				true
			);
		}
		$this->redirect_notice(
			sprintf(
				/* translators: %s: error message. */
				__( 'Test submission failed: %s', 'surecart-eu-helper' ),
				(string) $result->error_message
			),
			false
		);
	}

	/**
	 * Build a minimal synthetic invoice for the sandbox connectivity test.
	 *
	 * @param string $env Environment.
	 * @return Document
	 */
	private function build_test_document( string $env ): Document {
		$merchant = MerchantProfile::party();

		$doc              = new Document();
		$doc->type        = DocumentType::INVOICE;
		$doc->number      = 'TEST-' . gmdate( 'YmdHis' );
		$doc->issue_date  = gmdate( 'Y-m-d' );
		$doc->currency    = 'EUR';
		$doc->environment = $env;
		$doc->provider_key = (string) Settings::get( 'einvoicing', 'provider', '' );
		$doc->merchant    = $merchant;
		$doc->customer    = Document::party(
			array(
				'name'    => __( 'Sandbox Test Customer', 'surecart-eu-helper' ),
				'email'   => (string) ( $merchant['email'] ?? '' ),
				'country' => (string) ( $merchant['country'] ?? '' ),
			)
		);
		$doc->lines       = array(
			Document::line(
				array(
					'source_ref'       => '1',
					'description'       => __( 'Sandbox test line', 'surecart-eu-helper' ),
					'quantity'         => 1,
					'unit_price'       => 100,
					'line_net'         => 100,
					'tax_rate_percent' => 0,
					'tax_category'     => 'zero',
				)
			),
		);
		$doc->tax_lines = array(
			Document::tax_line(
				array(
					'rate_percent' => 0,
					'category'     => 'zero',
					'taxable_base' => 100,
					'tax_amount'   => 0,
				)
			),
		);
		$doc->totals = array(
			'net'   => 100,
			'tax'   => 0,
			'gross' => 100,
		);

		return $doc;
	}

	/**
	 * Capability + nonce guard for a no-id action.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Capability + per-row nonce guard; returns the row id.
	 *
	 * @param string $action Nonce action (suffixed with the id).
	 * @return int
	 */
	private function guard_row( string $action ): int {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( $action . '_' . $id );
		return $id;
	}

	/**
	 * Redirect back to the document log with a notice and exit.
	 *
	 * @param string $message Notice text.
	 * @param bool   $ok      Success vs error.
	 * @return void
	 */
	private function redirect_notice( string $message, bool $ok ): void {
		$args = array(
			'page'             => self::LOG_PAGE,
			'sceu_einv_notice' => rawurlencode( $message ),
		);
		if ( ! $ok ) {
			$args['sceu_einv_error'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
