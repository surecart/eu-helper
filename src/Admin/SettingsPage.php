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
use SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable;

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
	 * Per-user screen option storing the log table's "per page" choice.
	 */
	const LOG_PER_PAGE = 'sceu_log_per_page';

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private $registry;

	/**
	 * The withdrawal-log page hook (returned by add_submenu_page), used to wire
	 * its Screen Options on load-{hook}. Empty until the submenu is registered.
	 *
	 * @var string
	 */
	private $log_hook = '';

	/**
	 * The log list table, built on the log page's load hook so its columns are
	 * known to the screen (Screen Options) and reused when the page renders.
	 *
	 * @var LogListTable|null
	 */
	private $log_table = null;

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
		// After all modules have added their submenus, push "Settings" to the bottom.
		add_action( 'admin_menu', array( $this, 'reorder_submenu' ), 999 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );

		// Settings-page concerns load whenever the plugin is active — NOT gated by
		// a module's enable toggle (that toggle only governs front-end behaviour),
		// so the settings UI stays styled and usable even when a module is off.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		// Render the brand bar above #wpbody so the Screen Options tab sits beneath it.
		add_action( 'in_admin_header', array( $this, 'render_admin_header' ) );
		$registry = $this->registry;
		add_action(
			'rest_api_init',
			static function () use ( $registry ) {
				( new AdminController() )->register_routes();
				( new SettingsController( $registry ) )->register_routes();
			}
		);

		// Persist the "per page" Screen Option (core saves it only if a filter allows).
		add_filter(
			'set_screen_option_' . self::LOG_PER_PAGE,
			static function ( $status, $option, $value ) {
				return max( 1, min( 200, (int) $value ) );
			},
			10,
			3
		);
	}

	/**
	 * Enqueue the settings shell + the React settings app. The exclusions picker
	 * and every field now live in the app (packages/admin/settings); there is no
	 * server-rendered fallback. The shared shell stylesheet still loads on both
	 * the settings page and the withdrawal-log page. Versioned by file mtime so
	 * updates are never served stale.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) && false === strpos( $hook, self::LOG_PAGE ) ) {
			return;
		}

		// The React app loads only on the settings page, not the log page.
		$app = ( false !== strpos( $hook, self::PAGE ) ) ? $this->app_asset() : null;

		$shell_deps = array( 'dashicons' );
		if ( null !== $app ) {
			$shell_deps[] = 'wp-components';
			wp_enqueue_style( 'wp-components' );
		}

		$settings_css = SCEU_DIR . 'assets/admin-settings.css';
		wp_enqueue_style(
			'sceu-admin-settings',
			SCEU_URL . 'assets/admin-settings.css',
			$shell_deps,
			file_exists( $settings_css ) ? (string) filemtime( $settings_css ) : SCEU_VERSION
		);

		// Make the log page's server-rendered .sceu-notice banners dismissible.
		$notice_js = SCEU_DIR . 'assets/admin-notice.js';
		wp_enqueue_script(
			'sceu-admin-notice',
			SCEU_URL . 'assets/admin-notice.js',
			array(),
			file_exists( $notice_js ) ? (string) filemtime( $notice_js ) : SCEU_VERSION,
			true
		);
		wp_localize_script(
			'sceu-admin-notice',
			'sceuNotice',
			array( 'dismissLabel' => __( 'Dismiss this notice', 'surecart-eu-helper' ) )
		);

		if ( null !== $app ) {
			wp_enqueue_script( 'sceu-settings-app', $app['url'], $app['deps'], $app['version'], true );
			wp_set_script_translations( 'sceu-settings-app', 'surecart-eu-helper' );
			wp_add_inline_script(
				'sceu-settings-app',
				'window.sceuSettingsApp = ' . wp_json_encode( $this->app_bootstrap() ) . ';',
				'before'
			);
		}
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
			$this->log_hook = (string) add_submenu_page(
				self::PAGE,
				__( 'Withdrawal Log', 'surecart-eu-helper' ),
				__( 'Withdrawal Log', 'surecart-eu-helper' ),
				'manage_options',
				self::LOG_PAGE,
				array( $this, 'render_log_page' )
			);
			if ( '' !== $this->log_hook ) {
				add_action( 'load-' . $this->log_hook, array( $this, 'on_log_load' ) );
			}
		}

		/**
		 * Let enabled modules attach their own submenu pages under this menu (e.g.
		 * the E-Invoicing document log). A module hooks this in its boot(), so only
		 * enabled modules — whose boot() ran — add a page.
		 *
		 * @todo Enable back the action when the e-invoicing module is ready for production use.
		 *
		 * @param string $parent Parent menu slug.
		 */
		// do_action( 'sceu_admin_menu', self::PAGE );
	}

	/**
	 * Wire the log table's Screen Options (per-page + column toggles) on load.
	 *
	 * @return void
	 */
	public function on_log_load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Requests per page', 'surecart-eu-helper' ),
				'default' => 20,
				'option'  => self::LOG_PER_PAGE,
			)
		);

		// Build the table now so its columns register for the Screen Options toggles.
		$this->log_table = new LogListTable();
		add_filter( 'manage_' . $this->log_hook . '_columns', array( $this, 'log_table_columns' ) );

		// Process the "Delete permanently" bulk action before the page renders.
		$this->maybe_handle_bulk_delete();
	}

	/**
	 * Handle the "Delete permanently" bulk action: verify nonce + capability,
	 * delete the checked rows, redirect with a count.
	 *
	 * @return void
	 */
	private function maybe_handle_bulk_delete(): void {
		if ( ! $this->log_table || 'delete' !== $this->log_table->current_action() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'surecart-eu-helper' ) );
		}
		// WP_List_Table prints this nonce via wp_nonce_field( 'bulk-{plural}' ).
		check_admin_referer( 'bulk-withdrawal_requests' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$ids     = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) : array();
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( $id && LogTable::delete( $id ) ) {
				++$deleted;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::LOG_PAGE . '&deleted=' . $deleted ) );
		exit;
	}

	/**
	 * Expose the log table's columns to the screen so Screen Options can list them.
	 *
	 * @param array<string, string> $columns Columns passed by the filter.
	 * @return array<string, string>
	 */
	public function log_table_columns( $columns ): array {
		return $this->log_table ? $this->log_table->get_columns() : (array) $columns;
	}

	/**
	 * Move the "Settings" entry to the bottom of the EU Helper submenu, below the
	 * module pages (Withdrawal Log, E-Invoicing, …). Runs after every submenu has
	 * been registered.
	 *
	 * @return void
	 */
	public function reorder_submenu(): void {
		global $submenu;
		if ( empty( $submenu[ self::PAGE ] ) || ! is_array( $submenu[ self::PAGE ] ) ) {
			return;
		}

		$settings = array();
		$rest     = array();
		foreach ( $submenu[ self::PAGE ] as $item ) {
			if ( isset( $item[2] ) && self::PAGE === $item[2] ) {
				$settings[] = $item;
			} else {
				$rest[] = $item;
			}
		}

		$submenu[ self::PAGE ] = array_values( array_merge( $rest, $settings ) );
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
		return SettingsSanitizer::sanitize( $input, $this->registry );
	}

	/**
	 * Locate the built React settings app, or null when it hasn't been built
	 * (e.g. a source clone without `npm run build`) — in which case the page
	 * falls back to the server-rendered Settings API form.
	 *
	 * @return array{url:string,deps:array<int,string>,version:string}|null
	 */
	private function app_asset() {
		$js  = SCEU_DIR . 'build/admin/settings.js';
		$php = SCEU_DIR . 'build/admin/settings.asset.php';
		if ( ! file_exists( $js ) ) {
			return null;
		}
		$asset = file_exists( $php ) ? include $php : array();
		return array(
			'url'     => SCEU_URL . 'build/admin/settings.js',
			'deps'    => isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
				? $asset['dependencies']
				: array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			'version' => isset( $asset['version'] ) ? (string) $asset['version'] : (string) filemtime( $js ),
		);
	}

	/**
	 * Build the bootstrap payload the React app reads on load: the same module +
	 * field schema the PHP renderer uses, current values, brand colours, REST
	 * paths, and the exclusion-picker data. Localized into the page so the app
	 * needs no GET round-trip before first paint.
	 *
	 * @return array<string, mixed>
	 */
	private function app_bootstrap(): array {
		$modules = array();
		foreach ( $this->registry->all() as $id => $module ) {
			$modules[] = array(
				'id'          => $id,
				'label'       => $module->label(),
				'description' => $module->description(),
				'disclaimer'  => $module->disclaimer(),
				'icon'        => $this->module_icon( $id, $module ),
				'enabled'     => Settings::is_module_enabled( $id ),
				'sections'    => method_exists( $module, 'settings_sections' ) ? $module->settings_sections() : array(),
				'fields'      => $module->settings_fields(),
				'values'      => Settings::for_module( $id ),
			);
		}

		return array(
			'restPath'                 => SettingsController::NAMESPACE . SettingsController::ROUTE,
			'productSearchPath'        => AdminController::NAMESPACE . AdminController::ROUTE,
			'version'                  => SCEU_VERSION,
			'removeData'               => ! empty( Settings::all()['remove_data'] ),
			'brand'                    => array(
				'primary'     => \SureCartEuHelper\Merchant\BrandColor::primary(),
				'primaryText' => \SureCartEuHelper\Merchant\BrandColor::primary_text(),
			),
			'merchantEmailPlaceholder' => MerchantInfo::notification_email(),
			'refreshExclusionsPath'    => AdminController::NAMESPACE . AdminController::ROUTE_REFRESH,
			'collections'              => class_exists( Exclusions::class ) ? Exclusions::all_collections() : array(),
			'productLabels'            => class_exists( Exclusions::class ) ? Exclusions::product_labels() : array(),
			'modules'                  => $modules,
		);
	}

	/**
	 * Render the brand header bar on our admin screens via `in_admin_header`, so
	 * it sits above #wpbody and the Screen Options tab falls into the strip below.
	 *
	 * @return void
	 */
	public function render_admin_header(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$is_log      = false !== strpos( $screen->id, self::LOG_PAGE );
		$is_settings = ! $is_log && false !== strpos( $screen->id, self::PAGE );
		if ( ! $is_log && ! $is_settings ) {
			return;
		}

		// Tint with the store's SureCart brand colour (badge + accents).
		$primary = \SureCartEuHelper\Merchant\BrandColor::primary();
		$ptext   = \SureCartEuHelper\Merchant\BrandColor::primary_text();
		$style   = '';
		if ( '' !== $primary ) {
			$style .= '--sceu-primary:' . $primary . ';';
			if ( '' !== $ptext ) {
				$style .= '--sceu-primary-text:' . $ptext . ';';
			}
		}

		$crumb = $is_log
			? __( 'Withdrawal requests', 'surecart-eu-helper' )
			: __( 'Settings', 'surecart-eu-helper' );
		?>
		<header class="sceu-app__bar sceu-app__bar--global" style="<?php echo esc_attr( $style ); ?>">
			<div class="sceu-app__brand">
				<span class="sceu-app__badge"><?php echo Icons::svg( 'shield-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted static SVG. ?></span>
				<span class="sceu-app__name"><?php echo esc_html__( 'SureCart EU Helper', 'surecart-eu-helper' ); ?></span>
				<span class="sceu-app__sep" aria-hidden="true">&rsaquo;</span>
				<span class="sceu-app__crumb"><?php echo esc_html( $crumb ); ?></span>
			</div>
			<span class="sceu-app__meta">v<?php echo esc_html( SCEU_VERSION ); ?></span>
		</header>
		<?php
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

		// React is the only settings UI; when the build is missing, show a notice.
		if ( null === $this->app_asset() ) {
			printf(
				'<div class="sceu-app" style="%1$s"><div class="sceu-app__content"><div class="sceu-app__inner"><div class="notice notice-error"><p>%2$s</p></div></div></div></div>',
				esc_attr( $style ),
				esc_html__( 'The EU Helper settings interface has not been built. Run "npm ci && npm run build" in the plugin directory, or install the packaged release.', 'surecart-eu-helper' )
			);
			return;
		}

		// Skeleton shown until the React app mounts, so the layout doesn't jump.
		$skeleton = sprintf(
			'<div class="sceu-app__skeleton" role="status">' .
				'<span class="screen-reader-text">%1$s</span>' .
				// Header is rendered separately via in_admin_header.
				'<div class="sceu-skel__body">' .
					'<div class="sceu-skel__nav">' .
						'<span class="sceu-skel sceu-skel__nav-item"></span>' .
						'<span class="sceu-skel sceu-skel__nav-item"></span>' .
						'<span class="sceu-skel sceu-skel__nav-item"></span>' .
					'</div>' .
					'<div class="sceu-skel__content">' .
						'<span class="sceu-skel sceu-skel__heading"></span>' .
						'<span class="sceu-skel sceu-skel__text"></span>' .
						'<span class="sceu-skel sceu-skel__text sceu-skel__text--short"></span>' .
						'<div class="sceu-skel sceu-skel__card"></div>' .
						'<div class="sceu-skel sceu-skel__card"></div>' .
					'</div>' .
				'</div>' .
			'</div>',
			esc_html__( 'Loading settings…', 'surecart-eu-helper' )
		);

		printf(
			'<div class="sceu-app" style="%1$s"><div id="sceu-settings-root" class="sceu-app__mount">%2$s</div></div>',
			esc_attr( $style ),
			$skeleton // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static skeleton markup; the only dynamic value (SR text) is escaped above.
		);
	}

	/**
	 * Dashicon (without the `dashicons-` prefix) for a module's nav + panel icon.
	 * A module may declare its own via an optional icon() method; otherwise the
	 * Right of Withdrawal shield, then a generic fallback.
	 *
	 * @param string $module_id Module id.
	 * @param object $module    Module instance.
	 * @return string
	 */
	private function module_icon( string $module_id, $module ): string {
		if ( method_exists( $module, 'icon' ) ) {
			$icon = (string) $module->icon();
			if ( '' !== $icon ) {
				return $icon;
			}
		}
		return 'right_of_withdrawal' === $module_id ? 'shield-alt' : 'admin-generic';
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
		// Reuse the instance built on load; fall back if that hook didn't run.
		$table = $this->log_table ?? new LogListTable();
		$table->prepare_items();

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
		<div class="sceu-app sceu-app--page" style="<?php echo esc_attr( $style ); ?>">
			<?php // The brand header bar is rendered globally via in_admin_header. ?>
			<div class="sceu-app__content">
				<div class="sceu-app__inner sceu-app__inner--wide">
					<div class="sceu-panel__head">
						<h2 class="sceu-panel__title">
							<?php echo Icons::svg( 'shield-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted static SVG. ?>
							<?php echo esc_html__( 'Withdrawal requests', 'surecart-eu-helper' ); ?>
						</h2>
					</div>

					<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended ?>
					<?php if ( isset( $_GET['status_error'] ) && 'overclaim' === $_GET['status_error'] ) : ?>
						<div class="sceu-notice sceu-notice--warning">
							<?php echo esc_html__( "Couldn't reset this request to pending: those units are already covered by current pending or completed requests for the order, so reactivating it would claim more than was purchased. Resolve, decline, or delete the other request(s) first.", 'surecart-eu-helper' ); ?>
						</div>
					<?php elseif ( isset( $_GET['updated'] ) ) : ?>
						<div class="sceu-notice sceu-notice--success"><?php echo esc_html__( 'Request status updated.', 'surecart-eu-helper' ); ?></div>
					<?php endif; ?>
					<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<?php if ( isset( $_GET['deleted'] ) && (int) $_GET['deleted'] > 0 ) : ?>
						<?php $sceu_deleted = (int) $_GET['deleted']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
						<div class="sceu-notice sceu-notice--success">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of requests deleted. */
									_n(
										'%d request permanently deleted from the log.',
										'%d requests permanently deleted from the log.',
										$sceu_deleted,
										'surecart-eu-helper'
									),
									$sceu_deleted
								)
							);
							?>
						</div>
					<?php endif; ?>
					<?php if ( isset( $_GET['synced'] ) ) : ?>
						<div class="sceu-notice sceu-notice--info">
							<?php
							$synced_count  = (int) $_GET['synced'];
							$flagged_count = isset( $_GET['flagged'] ) ? (int) $_GET['flagged'] : 0;
							/* translators: %d: number of requests marked resolved. */
							echo esc_html( sprintf( __( 'Sync complete. %d request(s) marked resolved.', 'surecart-eu-helper' ), $synced_count ) );
							if ( $flagged_count > 0 ) {
								echo ' ';
								echo esc_html(
									sprintf(
										/* translators: %d: number of requests flagged for review. */
										_n(
											'%d request has a partial refund flagged for review — set its status manually.',
											'%d requests have a partial refund flagged for review — set their status manually.',
											$flagged_count,
											'surecart-eu-helper'
										),
										$flagged_count
									)
								);
							}
							?>
						</div>
					<?php endif; ?>
					<?php if ( isset( $_GET['resent'] ) && 'ok' === $_GET['resent'] ) : ?>
						<div class="sceu-notice sceu-notice--success"><?php echo esc_html__( 'Email re-sent. The "Emails sent" column shows the current delivery result.', 'surecart-eu-helper' ); ?></div>
					<?php elseif ( isset( $_GET['resent'] ) ) : ?>
						<div class="sceu-notice sceu-notice--warning"><?php echo esc_html__( 'Tried to re-send, but WordPress reported the email could not be sent. This usually means the site has no working email/SMTP setup. See the "Emails sent" column below.', 'surecart-eu-helper' ); ?></div>
					<?php endif; ?>
					<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

					<div class="sceu-toolbar">
						<a class="sceu-btn--primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sceu_sync_log' ), 'sceu_sync_log' ) ); ?>">
							<?php echo esc_html__( 'Sync statuses', 'surecart-eu-helper' ); ?>
						</a>
						<a class="sceu-btn--secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sceu_export_log' ), 'sceu_export_log' ) ); ?>">
							<?php echo esc_html__( 'Export CSV', 'surecart-eu-helper' ); ?>
						</a>
					</div>
					<p class="sceu-field__help" style="margin:0 0 1.5em;"><?php echo esc_html__( 'Sync checks SureCart for refunds and cancellations. Fully refunded or cancelled orders are marked resolved. A partial refund is flagged for review instead of resolved — it can\'t be matched to a specific request, so set the status manually. SureCart does not always report refunds, so set status manually when needed.', 'surecart-eu-helper' ); ?></p>

					<div class="sceu-card sceu-card--table">
						<form method="post">
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
	 * Add the full-bleed body class on the settings + withdrawal-log pages so the
	 * shared shell styling (tint + hidden footer) applies.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public function admin_body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ( false !== strpos( $screen->id, self::PAGE ) || false !== strpos( $screen->id, self::LOG_PAGE ) ) ) {
			$classes .= ' sceu-fullbleed';
		}
		return $classes;
	}
}
