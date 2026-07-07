<?php
/**
 * Registry of e-invoicing provider adapters.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\EInvoicing\Domain\Environment;
use SureCartEuHelper\Modules\EInvoicing\Providers\Contract\ProviderAdapterInterface;
use SureCartEuHelper\Modules\EInvoicing\Providers\Storecove\StorecoveAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors the module ModuleRegistry: core registers the built-in providers, then
 * fires `sceu_register_invoice_providers` so add-ons can inject their own. The
 * active provider is chosen from settings, and its environment applied, so
 * callers get a ready-to-use adapter from active().
 */
class ProviderRegistry {

	/** @var array<string, ProviderAdapterInterface> */
	private $providers = array();

	/** @var bool */
	private $registered = false;

	/**
	 * Register a provider (last registration for a key wins, allowing overrides).
	 *
	 * @param ProviderAdapterInterface $provider Provider adapter.
	 * @return void
	 */
	public function register( ProviderAdapterInterface $provider ): void {
		$this->providers[ $provider->key() ] = $provider;
	}

	/**
	 * Register built-in providers, then let add-ons register theirs. Idempotent.
	 *
	 * @return void
	 */
	public function register_providers(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		$this->register( new StorecoveAdapter() );

		do_action( 'sceu_register_invoice_providers', $this );
	}

	/**
	 * All registered providers.
	 *
	 * @return array<string, ProviderAdapterInterface>
	 */
	public function all(): array {
		$this->register_providers();
		return $this->providers;
	}

	/**
	 * Get a provider by key, or null.
	 *
	 * @param string $key Provider key.
	 * @return ProviderAdapterInterface|null
	 */
	public function get( string $key ): ?ProviderAdapterInterface {
		$this->register_providers();
		return $this->providers[ $key ] ?? null;
	}

	/**
	 * The provider selected in settings, with its environment applied. Null when
	 * none is configured.
	 *
	 * @return ProviderAdapterInterface|null
	 */
	public function active(): ?ProviderAdapterInterface {
		$key      = (string) Settings::get( 'einvoicing', 'provider', '' );
		$provider = '' !== $key ? $this->get( $key ) : null;
		if ( $provider ) {
			$env = Environment::normalize( Settings::get( 'einvoicing', 'environment', Environment::SANDBOX ) );
			$provider->set_environment( $env );
		}
		return $provider;
	}

	/**
	 * Option choices for the provider picker.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	public function options(): array {
		$options = array();
		foreach ( $this->all() as $key => $provider ) {
			$options[] = array(
				'value' => $key,
				'label' => $provider->label(),
			);
		}
		return $options;
	}
}
