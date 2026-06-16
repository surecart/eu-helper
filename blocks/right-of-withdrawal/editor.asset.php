<?php
/**
 * Dependency manifest for editor.js.
 *
 * Hand-written (no build step): declares the wp-* packages editor.js relies on
 * so WordPress loads them first. `wp-i18n` here is also what triggers core to
 * auto-load this block's script translations (textdomain is set in block.json).
 *
 * @package SureCartEuHelper
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n' ),
	'version'      => defined( 'SCEU_VERSION' ) ? SCEU_VERSION : '1.4.0',
);
