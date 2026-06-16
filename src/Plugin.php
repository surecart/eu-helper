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

		if ( is_admin() ) {
			( new SettingsPage( $this->registry ) )->register();
		}

		( new Diagnostics() )->register();
	}
}
