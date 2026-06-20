<?php
/**
 * Admin settings page.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Admin;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Merchant\MerchantInfo;
use SureCartEuHelper\Modules\ModuleRegistry;
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogListTable;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "SureCart EU Helper" menu: a module enable/disable list plus each
 * registered module's own settings fields, saved into the single `sceu_settings`
 * option via the Settings API. Also hosts the withdrawal-request log viewer.
 */
class SettingsPage {

	const GROUP    = 'sceu_settings_group';
	const PAGE     = 'sceu-settings';
	const LOG_PAGE = 'sceu-withdrawal-log';

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param ModuleRegistry $registry Registry.
	 */
	public function __construct( ModuleRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Hook into the admin.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Register the menu + submenus.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'SureCart EU Helper', 'surecart-eu-helper' ),
			__( 'EU Helper', 'surecart-eu-helper' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-shield-alt',
			59
		);

		add_submenu_page(
			self::PAGE,
			__( 'Settings', 'surecart-eu-helper' ),
			__( 'Settings', 'surecart-eu-helper' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( Settings::is_module_enabled( 'right_of_withdrawal' ) ) {
			add_submenu_page(
				self::PAGE,
				__( 'Withdrawal Log', 'surecart-eu-helper' ),
				__( 'Withdrawal Log', 'surecart-eu-helper' ),
				'manage_options',
				self::LOG_PAGE,
				array( $this, 'render_log_page' )
			);
		}
	}

	/**
	 * Register the single settings option with a sanitize callback.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize the posted settings against each module's field schema.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array( 'modules' => array() );

		foreach ( $this->registry->all() as $id => $module ) {
			// Enable flag (hidden 0 + checkbox 1 pattern).
			$out['modules'][ $id ] = ! empty( $input['modules'][ $id ] ) ? true : false;

			$values = isset( $input[ $id ] ) && is_array( $input[ $id ] ) ? $input[ $id ] : array();
			$clean  = array();

			foreach ( $module->settings_fields() as $field ) {
				$key  = $field['key'] ?? '';
				$type = $field['type'] ?? 'text';
				if ( '' === $key ) {
					continue;
				}
				$raw = $values[ $key ] ?? null;

				switch ( $type ) {
					case 'toggle':
						$clean[ $key ] = ! empty( $raw );
						break;
					case 'number':
						$num           = is_numeric( $raw ) ? (int) $raw : (int) ( $field['default'] ?? 0 );
						$min           = isset( $field['min'] ) ? (int) $field['min'] : null;
						$clean[ $key ] = ( null !== $min ) ? max( $min, $num ) : $num;
						break;
					case 'email':
						$clean[ $key ] = sanitize_email( (string) $raw );
						break;
					case 'select':
					case 'radio':
						$allowed       = array_map(
							static function ( $o ) {
								return $o['value'];
							},
							$field['options'] ?? array()
						);
						$clean[ $key ] = in_array( $raw, $allowed, true ) ? $raw : ( $field['default'] ?? '' );
						break;
					default:
						$clean[ $key ] = sanitize_text_field( (string) $raw );
				}
			}

			$out[ $id ] = $clean;
		}

		return $out;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'SureCart EU Helper', 'surecart-eu-helper' ); ?></h1>
			<p><?php echo esc_html__( 'Enable the EU-compliance modules you need and configure each one below.', 'surecart-eu-helper' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<?php foreach ( $this->registry->all() as $id => $module ) : ?>
					<?php $enabled = Settings::is_module_enabled( $id ); ?>
					<h2 style="margin-top:2em;"><?php echo esc_html( $module->label() ); ?></h2>
					<p class="description"><?php echo esc_html( $module->description() ); ?></p>

					<?php $disclaimer = $module->disclaimer(); ?>
					<?php if ( '' !== $disclaimer ) : ?>
						<div class="notice notice-info inline" style="margin:8px 0 4px;padding:10px 12px;border-left-color:#dba617;">
							<p style="margin:0;"><strong><?php echo esc_html__( 'Your responsibility', 'surecart-eu-helper' ); ?>:</strong> <?php echo esc_html( $disclaimer ); ?></p>
						</div>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable module', 'surecart-eu-helper' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[modules][<?php echo esc_attr( $id ); ?>]" value="0" />
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( Settings::OPTION ); ?>[modules][<?php echo esc_attr( $id ); ?>]"
										value="1" <?php checked( $enabled ); ?> />
									<?php echo esc_html__( 'Active', 'surecart-eu-helper' ); ?>
								</label>
							</td>
						</tr>

						<?php foreach ( $module->settings_fields() as $field ) : ?>
							<?php $this->render_field( $id, $field ); ?>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single settings field row.
	 *
	 * @param string               $module_id Module id.
	 * @param array<string, mixed> $field     Field schema.
	 * @return void
	 */
	private function render_field( string $module_id, array $field ): void {
		$key     = $field['key'] ?? '';
		$type    = $field['type'] ?? 'text';
		$label   = $field['label'] ?? $key;
		$help    = $field['help'] ?? '';
		$default = $field['default'] ?? '';
		$name    = Settings::OPTION . '[' . $module_id . '][' . $key . ']';
		$id_attr = 'sceu-' . $module_id . '-' . $key;

		$value = Settings::get( $module_id, $key, $default );

		// Pre-fill merchant email from SureCart when unset.
		$placeholder = '';
		if ( 'merchant_email' === $key && ( '' === $value || null === $value ) ) {
			$placeholder = MerchantInfo::notification_email();
		}
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id_attr ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( 'select' === $type || 'radio' === $type ) : ?>
					<?php foreach ( (array) ( $field['options'] ?? array() ) as $opt ) : ?>
						<?php if ( 'radio' === $type ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="radio" name="<?php echo esc_attr( $name ); ?>"
									value="<?php echo esc_attr( $opt['value'] ); ?>"
									<?php checked( $value, $opt['value'] ); ?> />
								<?php echo esc_html( $opt['label'] ); ?>
							</label>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( 'select' === $type ) : ?>
						<select id="<?php echo esc_attr( $id_attr ); ?>" name="<?php echo esc_attr( $name ); ?>">
							<?php foreach ( (array) ( $field['options'] ?? array() ) as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt['value'] ); ?>" <?php selected( $value, $opt['value'] ); ?>>
									<?php echo esc_html( $opt['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

				<?php elseif ( 'toggle' === $type ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
					<label>
						<input type="checkbox" id="<?php echo esc_attr( $id_attr ); ?>"
							name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> />
						<?php echo esc_html( $field['checkbox_label'] ?? __( 'Enabled', 'surecart-eu-helper' ) ); ?>
					</label>

				<?php elseif ( 'number' === $type ) : ?>
					<input type="number" id="<?php echo esc_attr( $id_attr ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>"
						<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : ''; ?>
						class="small-text" />

				<?php elseif ( 'email' === $type ) : ?>
					<input type="email" id="<?php echo esc_attr( $id_attr ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						class="regular-text" />

				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id_attr ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>"
						class="regular-text" />
				<?php endif; ?>

				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the withdrawal-request log viewer.
	 *
	 * @return void
	 */
	public function render_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$table = new LogListTable();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Withdrawal Requests', 'surecart-eu-helper' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Request status updated.', 'surecart-eu-helper' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Request permanently deleted from the log.', 'surecart-eu-helper' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['synced'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible"><p>
					<?php
					/* translators: %d: number of requests marked resolved. */
					echo esc_html( sprintf( __( 'Sync complete. %d request(s) marked resolved.', 'surecart-eu-helper' ), (int) $_GET['synced'] ) );
					?>
				</p></div>
			<?php endif; ?>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sceu_sync_log' ), 'sceu_sync_log' ) ); ?>">
					<?php echo esc_html__( 'Sync statuses', 'surecart-eu-helper' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sceu_export_log' ), 'sceu_export_log' ) ); ?>">
					<?php echo esc_html__( 'Export CSV', 'surecart-eu-helper' ); ?>
				</a>
			</p>
			<p class="description"><?php echo esc_html__( 'Sync checks SureCart for refunded/cancelled orders and marks matching requests resolved. SureCart does not always report refunds on the order, so set status manually when needed.', 'surecart-eu-helper' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::LOG_PAGE ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
