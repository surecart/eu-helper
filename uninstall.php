<?php
/**
 * Uninstall cleanup for SureCart EU Helper.
 *
 * Runs only when the plugin is deleted from the Plugins screen. Removes the
 * custom log table plus every option/transient the plugin created (all are
 * `sceu_`-prefixed) so nothing is left behind.
 *
 * @package SureCartEuHelper
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Only purge when the merchant has explicitly opted in via the "Remove Plugin
// Data" setting. Read the raw option directly — the plugin is not bootstrapped
// during uninstall, so the Settings class/autoloader is unavailable.
$sceu_settings = get_option( 'sceu_settings' );
if ( ! is_array( $sceu_settings ) || empty( $sceu_settings['remove_data'] ) ) {
	return;
}

global $wpdb;

// Drop the withdrawal-request log table.
$sceu_table = $wpdb->prefix . 'sceu_withdrawal_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$sceu_table}`" );

// Delete all sceu_-prefixed options and transients (settings, version, db
// version, secrets, exclusion cache, brand-colour/search transients, etc.).
$sceu_like_option    = $wpdb->esc_like( 'sceu_' ) . '%';
$sceu_like_transient = $wpdb->esc_like( '_transient_sceu_' ) . '%';
$sceu_like_timeout   = $wpdb->esc_like( '_transient_timeout_sceu_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$sceu_like_option,
		$sceu_like_transient,
		$sceu_like_timeout
	)
);
