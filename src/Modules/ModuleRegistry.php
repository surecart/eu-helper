<?php
/**
 * Registry of available EU Helper modules.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\RightOfWithdrawal\Module as RightOfWithdrawalModule;
// use SureCartEuHelper\Modules\EInvoicing\Module as EInvoicingModule;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every registered module, keyed by id.
 *
 * Core registers the v1 modules, then fires `sceu_register_modules` so add-ons
 * can register more. Only enabled modules are booted at runtime.
 */
class ModuleRegistry {

	/**
	 * Registered modules, keyed by id.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private $modules = array();

	/**
	 * Whether registration has run.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Register a module. Last registration for an id wins.
	 *
	 * @param ModuleInterface $module Module to register.
	 * @return void
	 */
	public function register( ModuleInterface $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/**
	 * Register core modules and let third parties add their own.
	 * Idempotent — safe to call more than once.
	 *
	 * @return void
	 */
	public function register_modules(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		$this->register( new RightOfWithdrawalModule() );

		// Enable back it when the e-invoicing module is ready for production use.
		// $this->register( new EInvoicingModule() );

		/**
		 * Register additional EU Helper modules.
		 *
		 * @param ModuleRegistry $registry The registry instance.
		 */
		do_action( 'sceu_register_modules', $this );
	}

	/**
	 * Boot every enabled module's runtime hooks.
	 *
	 * @return void
	 */
	public function boot_enabled(): void {
		foreach ( $this->modules as $id => $module ) {
			if ( Settings::is_module_enabled( $id ) ) {
				$module->boot();
			}
		}
	}

	/**
	 * Get a module by id, or null if unknown.
	 *
	 * @param string $id Module id.
	 * @return ModuleInterface|null
	 */
	public function get( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * All registered modules, keyed by id.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}
}
