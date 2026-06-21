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
use SureCartEuHelper\Modules\RightOfWithdrawal\Exclusions;
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
					case 'product_exclusions':
					case 'collection_exclusions':
						// A list of SureCart ids (UUID-shaped). Strip anything else.
						$ids           = is_array( $raw ) ? $raw : array();
						$clean[ $key ] = array_values(
							array_unique(
								array_filter(
									array_map(
										static function ( $v ) {
											return preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $v );
										},
										$ids
									)
								)
							)
						);
						break;
					default:
						$clean[ $key ] = sanitize_text_field( (string) $raw );
				}
			}

			// Display-only labels for the excluded-product picker, posted alongside
			// it so the admin UI needn't re-fetch product names. Kept only for ids
			// still selected.
			if ( isset( $values['excluded_product_labels'] ) && is_array( $values['excluded_product_labels'] ) ) {
				$labels = array();
				foreach ( $values['excluded_product_labels'] as $pid => $pname ) {
					$pid = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $pid );
					if ( '' !== $pid ) {
						$labels[ $pid ] = sanitize_text_field( (string) $pname );
					}
				}
				if ( ! empty( $clean['excluded_product_ids'] ) ) {
					$labels = array_intersect_key( $labels, array_flip( $clean['excluded_product_ids'] ) );
				} else {
					$labels = array();
				}
				$clean['excluded_product_labels'] = $labels;
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
		// Tint the page with the store's SureCart brand colour so the save button
		// and accents match the merchant's storefront.
		$primary = \SureCartEuHelper\Merchant\BrandColor::primary();
		$ptext   = \SureCartEuHelper\Merchant\BrandColor::primary_text();
		$style   = '';
		if ( '' !== $primary ) {
			$style .= '--sceu-primary:' . $primary . ';';
			if ( '' !== $ptext ) {
				$style .= '--sceu-primary-text:' . $ptext . ';';
			}
		}
		?>
		<div class="wrap sceu-settings" style="<?php echo esc_attr( $style ); ?>">
			<div class="sceu-settings__header">
				<h1 class="sceu-settings__title"><?php echo esc_html__( 'SureCart EU Helper', 'surecart-eu-helper' ); ?></h1>
				<p class="sceu-settings__subtitle"><?php echo esc_html__( 'Enable the EU-compliance modules you need and configure each one below.', 'surecart-eu-helper' ); ?></p>
			</div>

			<?php if ( isset( $_GET['exclusions_synced'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of products resolved from excluded collections. */
							_n(
								'Excluded-product list refreshed: %d product is currently in your excluded collections.',
								'Excluded-product list refreshed: %d products are currently in your excluded collections.',
								(int) $_GET['exclusions_synced'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
								'surecart-eu-helper'
							),
							(int) $_GET['exclusions_synced'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php" class="sceu-settings__form">
				<?php settings_fields( self::GROUP ); ?>

				<?php foreach ( $this->registry->all() as $id => $module ) : ?>
					<?php $enabled = Settings::is_module_enabled( $id ); ?>
					<section class="sceu-card">
						<header class="sceu-card__header">
							<h2 class="sceu-card__title"><?php echo esc_html( $module->label() ); ?></h2>
							<p class="sceu-card__desc"><?php echo esc_html( $module->description() ); ?></p>
						</header>
						<div class="sceu-card__body">
							<?php $disclaimer = $module->disclaimer(); ?>
							<?php if ( '' !== $disclaimer ) : ?>
								<p class="sceu-card__note">
									<strong><?php echo esc_html__( 'Your responsibility', 'surecart-eu-helper' ); ?>:</strong>
									<?php echo esc_html( $disclaimer ); ?>
								</p>
							<?php endif; ?>

							<div class="sceu-field sceu-field--toggle">
								<span class="sceu-field__label" style="margin:0;"><?php echo esc_html__( 'Enable module', 'surecart-eu-helper' ); ?></span>
								<label class="sceu-switch">
									<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[modules][<?php echo esc_attr( $id ); ?>]" value="0" />
									<input type="checkbox" class="sceu-switch__input"
										name="<?php echo esc_attr( Settings::OPTION ); ?>[modules][<?php echo esc_attr( $id ); ?>]"
										value="1" <?php checked( $enabled ); ?> />
									<span class="sceu-switch__track"><span class="sceu-switch__thumb"></span></span>
									<span class="sceu-switch__text"><?php echo esc_html__( 'Active', 'surecart-eu-helper' ); ?></span>
								</label>
							</div>

							<?php foreach ( $module->settings_fields() as $field ) : ?>
								<?php $this->render_field( $id, $field ); ?>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>

				<div class="sceu-settings__actions">
					<button type="submit" class="sceu-btn--primary"><?php echo esc_html__( 'Save changes', 'surecart-eu-helper' ); ?></button>
				</div>
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
		<div class="sceu-field sceu-field--<?php echo esc_attr( $type ); ?>">
			<label class="sceu-field__label" for="<?php echo esc_attr( $id_attr ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="sceu-field__control">
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

				<?php elseif ( 'collection_exclusions' === $type ) : ?>
					<?php
					$selected_cols = is_array( $value ) ? array_map( 'strval', $value ) : array();
					$collections   = Exclusions::all_collections();
					?>
					<?php if ( empty( $collections ) ) : ?>
						<p class="description"><?php echo esc_html__( 'No product collections found (or SureCart is unavailable). Create collections in SureCart to exclude products in bulk.', 'surecart-eu-helper' ); ?></p>
					<?php else : ?>
						<fieldset>
							<?php foreach ( $collections as $col ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox"
										name="<?php echo esc_attr( $name ); ?>[]"
										value="<?php echo esc_attr( $col['id'] ); ?>"
										<?php checked( in_array( $col['id'], $selected_cols, true ) ); ?> />
									<?php echo esc_html( $col['name'] ); ?>
									<span class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of products in the collection. */
												_n( '(%d product)', '(%d products)', (int) $col['products_count'], 'surecart-eu-helper' ),
												(int) $col['products_count']
											)
										);
										?>
									</span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p style="margin-top:8px;">
							<a class="button button-secondary"
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sceu_refresh_exclusions' ), 'sceu_refresh_exclusions' ) ); ?>">
								<?php echo esc_html__( 'Refresh excluded product list', 'surecart-eu-helper' ); ?>
							</a>
							<span class="description" style="margin-left:8px;">
								<?php echo esc_html__( "Rebuilds the cached list of products in the excluded collections. Runs automatically when you save, on a schedule, and after you add products to a collection.", 'surecart-eu-helper' ); ?>
							</span>
						</p>
					<?php endif; ?>

				<?php elseif ( 'product_exclusions' === $type ) : ?>
					<?php
					$selected_ids = is_array( $value ) ? array_map( 'strval', $value ) : array();
					$labels       = Exclusions::product_labels();
					?>
					<div class="sceu-excl" data-sceu-excl>
						<input type="search" class="sceu-excl__search regular-text"
							placeholder="<?php echo esc_attr__( 'Search products by name…', 'surecart-eu-helper' ); ?>"
							autocomplete="off" aria-label="<?php echo esc_attr__( 'Search products to exclude', 'surecart-eu-helper' ); ?>" />
						<ul class="sceu-excl__results" hidden></ul>
						<ul class="sceu-excl__chips">
							<?php foreach ( $selected_ids as $pid ) : ?>
								<?php $pname = $labels[ $pid ] ?? $pid; ?>
								<li class="sceu-excl__chip" data-id="<?php echo esc_attr( $pid ); ?>">
									<span class="sceu-excl__chip-label"><?php echo esc_html( $pname ); ?></span>
									<button type="button" class="sceu-excl__remove" aria-label="<?php echo esc_attr__( 'Remove', 'surecart-eu-helper' ); ?>">&times;</button>
									<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $pid ); ?>" />
									<input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[' . $module_id . '][excluded_product_labels][' . $pid . ']' ); ?>" value="<?php echo esc_attr( $pname ); ?>" />
								</li>
							<?php endforeach; ?>
						</ul>
						<template class="sceu-excl__template">
							<li class="sceu-excl__chip" data-id="">
								<span class="sceu-excl__chip-label"></span>
								<button type="button" class="sceu-excl__remove" aria-label="<?php echo esc_attr__( 'Remove', 'surecart-eu-helper' ); ?>">&times;</button>
								<input type="hidden" data-name-ids="<?php echo esc_attr( $name ); ?>[]" value="" />
								<input type="hidden" data-name-labels="<?php echo esc_attr( Settings::OPTION . '[' . $module_id . '][excluded_product_labels]' ); ?>" value="" />
							</li>
						</template>
					</div>

				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id_attr ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( (string) $value ); ?>"
						class="regular-text" />
				<?php endif; ?>
			</div>
			<?php if ( $help ) : ?>
				<p class="sceu-field__help"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
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
			<?php if ( isset( $_GET['resent'] ) && 'ok' === $_GET['resent'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Email re-sent. The "Emails sent" column shows the current delivery result.', 'surecart-eu-helper' ); ?></p></div>
			<?php elseif ( isset( $_GET['resent'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'Tried to re-send, but WordPress reported the email could not be sent. This usually means the site has no working email/SMTP setup. See the "Emails sent" column below.', 'surecart-eu-helper' ); ?></p></div>
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
