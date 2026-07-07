<?php
/**
 * Inline admin SVG icons.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Line-style SVG icons for the admin UI, matching SureCart's thin stroke glyphs.
 *
 * Centralised so every admin view shares one icon set. The markup is trusted
 * and static (no user data) and is coloured via `currentColor`, so the
 * active-nav / primary colours apply through CSS.
 */
final class Icons {

	/**
	 * SVG markup for an icon key.
	 *
	 * Accepts the dashicon-style names returned by module icon resolvers
	 * (e.g. `shield-alt`) and falls back to a generic settings glyph.
	 *
	 * @param string $name Icon key.
	 * @return string SVG markup.
	 */
	public static function svg( string $name ): string {
		switch ( $name ) {
			case 'shield-alt':
			case 'shield':
				$paths = '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>';
				break;
			default:
				$paths = '<line x1="4" y1="8" x2="20" y2="8"></line><line x1="4" y1="16" x2="20" y2="16"></line><circle cx="9" cy="8" r="2"></circle><circle cx="15" cy="16" r="2"></circle>';
		}

		return '<svg class="sceu-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	}
}
