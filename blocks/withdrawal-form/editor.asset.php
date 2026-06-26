<?php
/**
 * Dependency manifest for the withdrawal-form block editor.js (no build step).
 *
 * @package SureCartEuHelper
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n' ),
	'version'      => defined( 'SCEU_VERSION' ) ? SCEU_VERSION : '1.3.0',
);
