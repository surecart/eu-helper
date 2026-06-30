<?php
/**
 * Gap-free sequential document numbering.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Workflow;

use SureCartEuHelper\Settings;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns the next document number for a series. Invoice numbering must be
 * sequential and gap-free for compliance, so the counter is incremented
 * atomically at the database level (an option-based read-modify-write would race
 * when two orders are confirmed simultaneously).
 *
 * Numbers are formatted as "{prefix}{zero-padded counter}", with the prefix
 * configurable per series in the module settings (defaults INV- / CN-). The
 * exact jurisdiction-required format should be confirmed with the merchant.
 */
final class NumberSequence {

	const PAD = 5;

	/**
	 * Reserve and return the next number for a document type.
	 *
	 * @param string $type DocumentType::*.
	 * @return string
	 */
	public static function next( string $type ): string {
		$counter = self::increment( self::option_name( $type ) );
		return self::prefix( $type ) . str_pad( (string) $counter, self::PAD, '0', STR_PAD_LEFT );
	}

	/**
	 * Atomically increment a counter stored in wp_options and return the new
	 * value. Uses INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID() so the
	 * increment and read are a single, race-free statement.
	 *
	 * @param string $option_name Counter option name.
	 * @return int New counter value (>= 1).
	 */
	private static function increment( string $option_name ): int {
		global $wpdb;

		// LAST_INSERT_ID(1) on the initial insert seeds the counter at 1 *and* makes
		// $wpdb->insert_id return 1 — without it, the first insert would report the
		// wp_options row's auto-increment id (a large, unrelated number).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, LAST_INSERT_ID(1), 'no')
				 ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)",
				$option_name
			)
		);

		$value = (int) $wpdb->insert_id;

		// First insert reports insert_id 0 on some setups; the stored value is 1.
		if ( $value < 1 ) {
			$value = (int) get_option( $option_name, 1 );
		}

		// Keep the option cache in sync with the direct write.
		wp_cache_delete( $option_name, 'options' );

		return $value;
	}

	/**
	 * Counter option name for a series.
	 *
	 * @param string $type DocumentType::*.
	 * @return string
	 */
	private static function option_name( string $type ): string {
		return DocumentType::CREDIT_NOTE === $type
			? 'sceu_einv_seq_credit_note'
			: 'sceu_einv_seq_invoice';
	}

	/**
	 * Configured number prefix for a series.
	 *
	 * @param string $type DocumentType::*.
	 * @return string
	 */
	private static function prefix( string $type ): string {
		if ( DocumentType::CREDIT_NOTE === $type ) {
			return (string) Settings::get( 'einvoicing', 'credit_note_prefix', 'CN-' );
		}
		return (string) Settings::get( 'einvoicing', 'invoice_prefix', 'INV-' );
	}
}
