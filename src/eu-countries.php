<?php
/**
 * EU country list helper.
 *
 * Kept in one place so the definition is easy to audit and maintain
 * (e.g. if the EU membership list changes). ISO 3166-1 alpha-2 codes.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Return the list of EU member-state country codes (alpha-2, uppercase).
 *
 * Filterable so a site can adjust the list (e.g. include EEA states, or
 * scope to a single country for a country-specific rule).
 *
 * @return string[]
 */
function eu_country_codes(): array {
	$codes = array(
		'AT', // Austria.
		'BE', // Belgium.
		'BG', // Bulgaria.
		'HR', // Croatia.
		'CY', // Cyprus.
		'CZ', // Czechia.
		'DK', // Denmark.
		'EE', // Estonia.
		'FI', // Finland.
		'FR', // France.
		'DE', // Germany.
		'GR', // Greece.
		'HU', // Hungary.
		'IE', // Ireland.
		'IT', // Italy.
		'LV', // Latvia.
		'LT', // Lithuania.
		'LU', // Luxembourg.
		'MT', // Malta.
		'NL', // Netherlands.
		'PL', // Poland.
		'PT', // Portugal.
		'RO', // Romania.
		'SK', // Slovakia.
		'SI', // Slovenia.
		'ES', // Spain.
		'SE', // Sweden.
	);

	/**
	 * Filter the list of country codes treated as "EU".
	 *
	 * @param string[] $codes Alpha-2 uppercase country codes.
	 */
	return (array) apply_filters( 'sceu_eu_country_codes', $codes );
}
