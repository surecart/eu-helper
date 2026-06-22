<?php
/**
 * Plugin Name:       SureCart EU Helper
 * Plugin URI:        https://wpcrafter.com/
 * Description:       Modular helper that adds EU merchant-compliance features to SureCart stores. Module 1: Right of Withdrawal — a customer-area block + form letting EU consumers request withdrawal/cancellation/refund of recent orders, with merchant + customer notifications and an on-site request log.
 * Version:           1.5.5
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            SureCart Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       surecart-eu-helper
 * Domain Path:       /languages
 *
 * @package SureCartEuHelper
 */

defined( 'ABSPATH' ) || exit;

define( 'SCEU_VERSION', '1.5.5' );
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
		$path     = SCEU_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
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
 * The module owns the schema; activation just delegates so the table exists
 * even before any module is booted on a normal request.
 */
register_activation_hook(
	__FILE__,
	function () {
		require_once SCEU_DIR . 'src/Modules/RightOfWithdrawal/Log/LogTable.php';
		\SureCartEuHelper\Modules\RightOfWithdrawal\Log\LogTable::create();
	}
);

/**
 * Boot the plugin once all plugins are loaded, so we can detect SureCart.
 */
add_action(
	'plugins_loaded',
	function () {
		// Guard: SureCart must be active for any of this to make sense.
		if ( ! class_exists( '\SureCart\Models\Customer' ) ) {
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
