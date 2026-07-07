<?php
/**
 * Plugin wiring.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper;

use SureCartEuHelper\Modules\ModuleRegistry;
use SureCartEuHelper\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that registers modules, boots the enabled ones, and wires the admin.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private $registry;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — pull in non-class helpers and build the registry.
	 */
	private function __construct() {
		require_once SCEU_DIR . 'src/eu-countries.php';
		$this->registry = new ModuleRegistry();
	}

	/**
	 * The module registry (exposed for the admin page + diagnostics).
	 *
	 * @return ModuleRegistry
	 */
	public function registry(): ModuleRegistry {
		return $this->registry;
	}

	/**
	 * Boot everything.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->registry->register_modules();
		$this->registry->boot_enabled();

		// Not gated by is_admin(): it wires a REST route, and REST is not an admin context.
		( new SettingsPage( $this->registry ) )->register();

		( new Diagnostics() )->register();

		$this->maybe_upgrade();
	}

	/**
	 * Fire a one-time upgrade routine when the stored version changes.
	 *
	 * Enabled modules hook `sceu_upgrade` (in their boot()) to bring their own
	 * schema up to date, giving a single, decoupled migration path instead of
	 * relying on plugin re-activation. Runs at most once per version bump.
	 *
	 * @return void
	 */
	private function maybe_upgrade(): void {
		$stored = (string) get_option( 'sceu_version', '' );
		if ( SCEU_VERSION === $stored ) {
			return;
		}

		/**
		 * Fires once when the plugin version changes (fresh install or upgrade).
		 *
		 * @param string $stored  Previously stored version ('' on first run).
		 * @param string $current Current plugin version.
		 */
		do_action( 'sceu_upgrade', $stored, SCEU_VERSION );

		update_option( 'sceu_version', SCEU_VERSION );
	}
}
