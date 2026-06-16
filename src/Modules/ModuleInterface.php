<?php
/**
 * Contract every EU Helper module implements.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * A module is a self-contained EU-compliance feature. The registry boots only
 * enabled modules; each describes its own settings so the admin page can render
 * them generically.
 */
interface ModuleInterface {

	/**
	 * Stable machine id, e.g. "right_of_withdrawal". Used as the settings key
	 * and the modules[] enable flag.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human label shown on the settings page.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * One-line description shown under the module toggle.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Settings-field schema for this module. Same field-shape convention as the
	 * sibling plugin's conditions:
	 *   [
	 *     'key'     => 'lookback_days',
	 *     'type'    => 'number'|'select'|'radio'|'toggle'|'email'|'text',
	 *     'label'   => 'Look-back window (days)',
	 *     'default' => 14,
	 *     'help'    => 'optional help text',
	 *     'min'     => 1,                                   // number
	 *     'options' => [ ['value'=>..,'label'=>..], ... ],  // select|radio
	 *   ]
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_fields(): array;

	/**
	 * Optional legal/liability disclaimer shown on the settings page (HTML or
	 * plain text). Return '' for no disclaimer.
	 *
	 * @return string
	 */
	public function disclaimer(): string;

	/**
	 * Wire the module's runtime hooks. Called only when the module is enabled.
	 *
	 * @return void
	 */
	public function boot(): void;
}
