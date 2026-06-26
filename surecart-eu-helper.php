<?php
/**
 * Plugin Name:       SureCart EU Helper
 * Plugin URI:        https://wpcrafter.com/
 * Description:       Modular helper that adds EU merchant-compliance features to SureCart stores. Module 1: Right of Withdrawal — a customer-area block + form letting EU consumers request withdrawal/cancellation/refund of recent orders, with merchant + customer notifications and an on-site request log.
 * Version:           1.5.7
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Requires Plugins:  surecart
 * Author:            SureCart Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       surecart-eu-helper
 * Domain Path:       /languages
 *
 * @package SureCartEuHelper
 */

defined( 'ABSPATH' ) || exit;

define( 'SCEU_VERSION', '1.5.7' );
define( 'SCEU_FILE', __FILE__ );
define( 'SCEU_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCEU_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4 style autoloader for the SureCartEuHelper\ namespace.
 * Avoids a Composer build step — the plugin runs as-is once dropped in.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'SureCartEuHelper\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$relative = substr( $class, $len );

		// Defence-in-depth: only ever resolve well-formed namespace tokens, so a
		// crafted class name can never reach outside src/ via traversal segments.
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}
		$path = SCEU_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

// Global, namespace-free helper functions (safe to call from block render.php).
require_once SCEU_DIR . 'src/helpers.php';

/**
 * Load translations from the plugin's /languages folder (where Loco Translate
 * and WPML save them). The plugin slug matches the text domain, so WordPress
 * also just-in-time loads translations from wp-content/languages/plugins/.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'surecart-eu-helper', false, dirname( plugin_basename( SCEU_FILE ) ) . '/languages' );
	}
);

/**
 * Activation: create the withdrawal log table.
 *
 * Guarded by the same stable SureCart sentinel as boot — the `Requires Plugins`
 * header blocks activation without SureCart on WP 6.5+, and this guard covers
 * older cores so we never leave an orphan table behind a missing dependency.
 * The schema is owned by the module; runtime also self-heals via
 * `LogTable::maybe_create()`, so this is just an early convenience.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! sceu_surecart_is_active() ) {
			return;
		}
		require_once SCEU_DIR . 'src/Modules/RightOfWithdrawal/Log/LogTable.php';
		\SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable::create();
	}
);

/**
 * Whether SureCart is present and loaded.
 *
 * Gates on the `SURECART_PLUGIN_FILE` constant — SureCart's own bootstrap
 * defines it and it is stable across releases, unlike model class names which
 * can move between major versions.
 *
 * @return bool
 */
function sceu_surecart_is_active(): bool {
	return defined( 'SURECART_PLUGIN_FILE' );
}

/**
 * Boot the plugin once all plugins are loaded, so we can detect SureCart.
 */
add_action(
	'plugins_loaded',
	function () {
		// Guard: SureCart must be active for any of this to make sense.
		if ( ! sceu_surecart_is_active() ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'SureCart EU Helper requires the SureCart plugin to be installed and active.', 'surecart-eu-helper' );
					echo '</p></div>';
				}
			);
			return;
		}

		\SureCartEuHelper\Plugin::instance()->init();
	}
);
