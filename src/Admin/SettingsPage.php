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
use SureCartEuHelper\Modules\RightOfWithdrawal\Rest\AdminController;
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

		// Settings-page concerns load whenever the plugin is active — NOT gated by
		// a module's enable toggle (that toggle only governs front-end behaviour),
		// so the settings UI stays styled and usable even when a module is off.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action(
			'rest_api_init',
			static function () {
				( new AdminController() )->register_routes();
			}
		);
		add_action( 'admin_post_sceu_refresh_exclusions', array( $this, 'refresh_exclusions' ) );
	}

	/**
	 * Enqueue the settings shell + exclusions-picker assets on the EU Helper
	 * settings page. Versioned by file mtime so updates are never served stale.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}

		$settings_css = SCEU_DIR . 'assets/admin-settings.css';
		wp_enqueue_style(
			'sceu-admin-settings',
			SCEU_URL . 'assets/admin-settings.css',
			array( 'dashicons' ),
			file_exists( $settings_css ) ? (string) filemtime( $settings_css ) : SCEU_VERSION
		);
		$settings_js = SCEU_DIR . 'assets/admin-settings.js';
		wp_enqueue_script(
			'sceu-admin-settings',
			SCEU_URL . 'assets/admin-settings.js',
			array(),
			file_exists( $settings_js ) ? (string) filemtime( $settings_js ) : SCEU_VERSION,
			true
		);

		$css = SCEU_DIR . 'assets/admin-exclusions.css';
		$js  = SCEU_DIR . 'assets/admin-exclusions.js';
		wp_enqueue_style(
			'sceu-admin-exclusions',
			SCEU_URL . 'assets/admin-exclusions.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : SCEU_VERSION
		);
		wp_enqueue_script(
			'sceu-admin-exclusions',
			SCEU_URL . 'assets/admin-exclusions.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : SCEU_VERSION,
			true
		);
		wp_localize_script(
			'sceu-admin-exclusions',
			'sceuExclusions',
			array(
				'searchUrl' => rest_url( AdminController::NAMESPACE . AdminController::ROUTE ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'noResults' => __( 'No matching products', 'surecart-eu-helper' ),
				),
			)
		);
	}

	/**
	 * Admin action: rebuild the product-exclusion cache now.
	 *
	 * @return void
	 */
	public function refresh_exclusions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		check_admin_referer( 'sceu_refresh_exclusions' );

		$count = count( Exclusions::rebuild_cache() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE,
					'exclusions_synced' => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
		$modules     = $this->registry->all();
		$first_label = '';
		foreach ( $modules as $sceu_first_module ) {
			$first_label = $sceu_first_module->label();
			break;
		}
		?>
		<div class="sceu-app" style="<?php echo esc_attr( $style ); ?>">
			<header class="sceu-app__bar">
				<div class="sceu-app__brand">
					<span class="sceu-app__badge dashicons dashicons-shield-alt" aria-hidden="true"></span>
					<span class="sceu-app__name"><?php echo esc_html__( 'SureCart EU Helper', 'surecart-eu-helper' ); ?></span>
					<span class="sceu-app__sep" aria-hidden="true">&rsaquo;</span>
					<span class="sceu-app__crumb" data-sceu-crumb><?php echo esc_html( $first_label ); ?></span>
				</div>
				<span class="sceu-app__meta">v<?php echo esc_html( SCEU_VERSION ); ?></span>
			</header>

			<div class="sceu-app__body">
				<nav class="sceu-app__nav" aria-label="<?php echo esc_attr__( 'EU Helper modules', 'surecart-eu-helper' ); ?>">
					<?php $sceu_first = true; ?>
					<?php foreach ( $modules as $id => $module ) : ?>
						<a class="sceu-nav__item<?php echo $sceu_first ? ' is-active' : ''; ?>"
							href="#<?php echo esc_attr( $id ); ?>"
							data-sceu-tab="<?php echo esc_attr( $id ); ?>">
							<span class="dashicons dashicons-<?php echo esc_attr( 'right_of_withdrawal' === $id ? 'shield-alt' : 'admin-generic' ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $module->label() ); ?>
						</a>
						<?php $sceu_first = false; ?>
					<?php endforeach; ?>
				</nav>

				<div class="sceu-app__content">
					<div class="sceu-app__inner">
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

						<?php $sceu_first = true; ?>
						<?php foreach ( $modules as $id => $module ) : ?>
							<?php $enabled = Settings::is_module_enabled( $id ); ?>
							<section class="sceu-panel<?php echo $sceu_first ? ' is-active' : ''; ?>" data-sceu-panel="<?php echo esc_attr( $id ); ?>" <?php echo $sceu_first ? '' : 'hidden'; ?>>
								<div class="sceu-panel__head">
									<h2 class="sceu-panel__title">
										<span class="dashicons dashicons-<?php echo esc_attr( 'right_of_withdrawal' === $id ? 'shield-alt' : 'admin-generic' ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( $module->label() ); ?>
									</h2>
									<button type="submit" class="sceu-btn--primary"><?php echo esc_html__( 'Save', 'surecart-eu-helper' ); ?></button>
								</div>
								<?php if ( '' !== $module->description() ) : ?>
									<p class="sceu-panel__desc"><?php echo esc_html( $module->description() ); ?></p>
								<?php endif; ?>

								<?php $disclaimer = $module->disclaimer(); ?>
								<?php if ( '' !== $disclaimer ) : ?>
									<p class="sceu-card__note">
										<strong><?php echo esc_html__( 'Your responsibility', 'surecart-eu-helper' ); ?>:</strong>
										<?php echo esc_html( $disclaimer ); ?>
									</p>
								<?php endif; ?>

								<div class="sceu-card sceu-card--compact">
									<div class="sceu-switch-row">
										<span class="sceu-switch-row__label"><?php echo esc_html__( 'Enable module', 'surecart-eu-helper' ); ?></span>
										<label class="sceu-switch">
											<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[modules][<?php echo esc_attr( $id ); ?>]" value="0" />
											<input type="checkbox" class="sceu-switch__input"
												name="<?php echo esc_attr( Settings::OPTION ); ?>[modules][<?php echo esc_attr( $id ); ?>]"
												value="1" <?php checked( $enabled ); ?> />
											<span class="sceu-switch__track"><span class="sceu-switch__thumb"></span></span>
											<span class="sceu-switch__text"><?php echo esc_html__( 'Active', 'surecart-eu-helper' ); ?></span>
										</label>
									</div>
								</div>

								<?php
								$sceu_fields   = $module->settings_fields();
								$sceu_sections = method_exists( $module, 'settings_sections' ) ? $module->settings_sections() : array();
								$sceu_grouped  = array();
								foreach ( $sceu_fields as $sceu_f ) {
									$sceu_grouped[ $sceu_f['section'] ?? '_default' ][] = $sceu_f;
								}
								// Section order first, then any ungrouped fields.
								$sceu_order = array_keys( $sceu_sections );
								foreach ( array_keys( $sceu_grouped ) as $sceu_k ) {
									if ( ! in_array( $sceu_k, $sceu_order, true ) ) {
										$sceu_order[] = $sceu_k;
									}
								}
								foreach ( $sceu_order as $sceu_skey ) :
									if ( empty( $sceu_grouped[ $sceu_skey ] ) ) {
										continue;
									}
									$sceu_sec = $sceu_sections[ $sceu_skey ] ?? array();
									?>
									<?php if ( ! empty( $sceu_sec['title'] ) ) : ?>
										<div class="sceu-section__head">
											<h3 class="sceu-section__title"><?php echo esc_html( $sceu_sec['title'] ); ?></h3>
											<?php if ( ! empty( $sceu_sec['description'] ) ) : ?>
												<p class="sceu-section__desc"><?php echo esc_html( $sceu_sec['description'] ); ?></p>
											<?php endif; ?>
										</div>
									<?php endif; ?>
									<div class="sceu-card">
										<?php foreach ( $sceu_grouped[ $sceu_skey ] as $sceu_field ) : ?>
											<?php $this->render_field( $id, $sceu_field ); ?>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</section>
							<?php $sceu_first = false; ?>
						<?php endforeach; ?>
					</form>
					</div>
				</div>
			</div>
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

		// Toggle fields render as a switch row (label left, switch right) with the
		// explanation below — matching SureCart's toggle style.
		if ( 'toggle' === $type ) {
			?>
			<div class="sceu-field sceu-field--toggle">
				<div class="sceu-switch-row">
					<span class="sceu-switch-row__label"><?php echo esc_html( $label ); ?></span>
					<label class="sceu-switch">
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
						<input type="checkbox" class="sceu-switch__input" id="<?php echo esc_attr( $id_attr ); ?>"
							name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> />
						<span class="sceu-switch__track"><span class="sceu-switch__thumb"></span></span>
					</label>
				</div>
				<?php if ( $help ) : ?>
					<p class="sceu-field__help"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
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
						<ul class="sceu-checklist">
							<?php foreach ( $collections as $col ) : ?>
								<li class="sceu-checklist__item">
									<label>
										<input type="checkbox"
											name="<?php echo esc_attr( $name ); ?>[]"
											value="<?php echo esc_attr( $col['id'] ); ?>"
											<?php checked( in_array( $col['id'], $selected_cols, true ) ); ?> />
										<span><?php echo esc_html( $col['name'] ); ?></span>
									</label>
									<span class="sceu-checklist__count">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of products in the collection. */
												_n( '%d product', '%d products', (int) $col['products_count'], 'surecart-eu-helper' ),
												(int) $col['products_count']
											)
										);
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="sceu-refresh-row">
							<a class="sceu-btn--secondary"
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sceu_refresh_exclusions' ), 'sceu_refresh_exclusions' ) ); ?>">
								<?php echo esc_html__( 'Refresh excluded product list', 'surecart-eu-helper' ); ?>
							</a>
						</p>
						<p class="sceu-field__help"><?php echo esc_html__( 'Rebuilds the cached list of products in the excluded collections. Runs automatically when you save, on a schedule, and when you add products to a collection.', 'surecart-eu-helper' ); ?></p>
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
