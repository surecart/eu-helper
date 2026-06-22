<?php
/**
 * Builds the merchant (seller) party snapshot from the module settings.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Mapping;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Merchant\MerchantInfo;
use SureCartEuHelper\Modules\EInvoicing\Domain\Document;

defined( 'ABSPATH' ) || exit;

/**
 * SureCart's Account does not reliably expose a full registered legal address or
 * VAT number, so the merchant's invoicing identity is collected in the module
 * settings (the "Business invoicing profile" section) and read here. Falls back
 * to the SureCart store name / email where a field is blank.
 */
final class MerchantProfile {

	/**
	 * Build the merchant party snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public static function party(): array {
		$name = self::get( 'merchant_legal_name' );
		if ( '' === $name ) {
			$name = MerchantInfo::store_name();
		}

		$email = self::get( 'merchant_email' );
		if ( '' === $email ) {
			$email = MerchantInfo::notification_email();
		}

		return Document::party(
			array(
				'name'                      => $name,
				'legal_name'                => $name,
				'tax_id'                    => self::get( 'merchant_vat' ),
				'email'                     => $email,
				'country'                   => self::get( 'merchant_country' ),
				'line1'                     => self::get( 'merchant_address_line1' ),
				'line2'                     => self::get( 'merchant_address_line2' ),
				'city'                      => self::get( 'merchant_city' ),
				'postal_code'               => self::get( 'merchant_postal_code' ),
				'region'                    => self::get( 'merchant_region' ),
				'electronic_address'        => self::get( 'merchant_peppol_id' ),
				'electronic_address_scheme' => self::get( 'merchant_peppol_scheme' ),
			)
		);
	}

	/**
	 * Read a merchant-profile setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function get( string $key ): string {
		return trim( (string) Settings::get( 'einvoicing', $key, '' ) );
	}
}
