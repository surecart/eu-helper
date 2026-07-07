<?php
/**
 * REST endpoint that persists the settings option for the React admin app.
 *
 * Save-only: the initial schema + values are localized into the page at render
 * (see SettingsPage::app_bootstrap), so the app needs no GET round-trip on load.
 * Saving runs the SAME SettingsSanitizer the classic Settings API form uses, and
 * writes via update_option — which fires `update_option_sceu_settings`, so the
 * existing post-save side effects (e.g. the exclusions cache rebuild) still run.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Admin;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only settings save endpoint.
 */
class SettingsController {

	const NAMESPACE = 'surecart-eu-helper/v1';
	const ROUTE     = '/settings';

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
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Sanitize and persist the posted settings.
	 *
	 * The `wp_rest` nonce is enforced by core (apiFetch sends X-WP-Nonce); the
	 * capability is checked in permission_callback. Values are sanitized against
	 * the module field schema before saving.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		$raw   = $request->get_param( 'settings' );
		$clean = SettingsSanitizer::sanitize( $raw, $this->registry );

		update_option( Settings::OPTION, $clean );

		return new \WP_REST_Response(
			array(
				'saved'    => true,
				'settings' => $clean,
			),
			200
		);
	}
}
