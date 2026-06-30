<?php
/**
 * Product / collection exclusions for the Right of Withdrawal.
 *
 * Some goods are excluded from the statutory right of withdrawal (e.g.
 * perishable, made-to-order, sealed hygiene, or digital items). This service
 * lets the merchant exclude specific products and/or whole product collections.
 *
 * Performance contract: the customer-facing hot path NEVER calls the SureCart
 * API. Exclusion is a precomputed set of product ids held in WordPress options
 * and checked in memory. Resolving a collection to its member product ids is
 * the only API work; it runs in admin/background (on settings save, a manual
 * refresh, or a scheduled rebuild) and is cached. A customer request that finds
 * a stale cache uses the stale value and schedules a background rebuild — it
 * never blocks on the network.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\RightOfWithdrawal;

use SureCartEuHelper\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and caches the set of product ids excluded from withdrawal.
 */
class Exclusions {

	const MODULE_ID    = 'right_of_withdrawal';
	const CACHE_IDS    = 'sceu_excluded_collection_members';
	const CACHE_HASH   = 'sceu_excluded_collection_members_hash';
	const CACHE_TS     = 'sceu_excluded_collection_members_ts';
	const CRON_HOOK    = 'sceu_rebuild_exclusion_cache';
	const TTL          = 86400; // 24h freshness safety net (DAY_IN_SECONDS).

	/**
	 * Product ids the merchant excluded directly (no lookup needed).
	 *
	 * @return string[]
	 */
	public static function excluded_product_ids_setting(): array {
		return self::id_list( Settings::get( self::MODULE_ID, 'excluded_product_ids', array() ) );
	}

	/**
	 * Collection ids the merchant excluded.
	 *
	 * @return string[]
	 */
	public static function excluded_collection_ids(): array {
		return self::id_list( Settings::get( self::MODULE_ID, 'excluded_collection_ids', array() ) );
	}

	/**
	 * Whether any exclusion is configured at all. When false the whole feature
	 * is a no-op and callers can skip work entirely.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return ! empty( self::excluded_product_ids_setting() ) || ! empty( self::excluded_collection_ids() );
	}

	/**
	 * The complete set of excluded product ids, as a lookup map
	 * ( product_id => true ) for O(1) membership tests on the hot path.
	 *
	 * Direct product ids ∪ cached collection-member ids. Does NOT call the
	 * SureCart API.
	 *
	 * @return array<string, true>
	 */
	public static function excluded_set(): array {
		if ( ! self::is_active() ) {
			return array();
		}
		$ids = array_merge( self::excluded_product_ids_setting(), self::collection_member_ids() );
		return array_fill_keys( $ids, true );
	}

	/**
	 * Is this product excluded? Pass the precomputed set when checking many
	 * items in a loop to avoid rebuilding it each time.
	 *
	 * @param string                   $product_id Product id.
	 * @param array<string, true>|null $set        Optional precomputed set.
	 * @return bool
	 */
	public static function is_excluded( string $product_id, ?array $set = null ): bool {
		if ( '' === $product_id ) {
			return false;
		}
		if ( null === $set ) {
			$set = self::excluded_set();
		}
		return isset( $set[ $product_id ] );
	}

	/**
	 * Cached product ids belonging to the excluded collections.
	 *
	 * Returns the cached value without calling the API. If the cache is missing
	 * or stale (collections changed, or older than the TTL), it schedules a
	 * background rebuild and returns the best value it currently has — so a
	 * customer request never waits on the network.
	 *
	 * @return string[]
	 */
	public static function collection_member_ids(): array {
		$collections = self::excluded_collection_ids();
		if ( empty( $collections ) ) {
			return array();
		}

		$cached = get_option( self::CACHE_IDS, null );
		$hash   = self::hash( $collections );
		$fresh  = is_array( $cached )
			&& get_option( self::CACHE_HASH ) === $hash
			&& ( time() - (int) get_option( self::CACHE_TS, 0 ) ) < self::TTL;

		if ( $fresh ) {
			return $cached;
		}

		// Stale or missing: rebuild in the background, never inline on this
		// (possibly customer-facing) request.
		self::schedule_rebuild();

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Schedule a one-off background rebuild (deduped) unless one is due already.
	 *
	 * @return void
	 */
	public static function schedule_rebuild(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Resolve the excluded collections to their member product ids and persist
	 * the cache. This is the only method that touches the SureCart API; it runs
	 * in admin/background contexts (settings save, manual refresh, cron) — never
	 * on a customer page render.
	 *
	 * @return string[] The resolved product ids.
	 */
	public static function rebuild_cache(): array {
		$collections = self::excluded_collection_ids();
		$ids         = empty( $collections ) ? array() : self::fetch_member_ids( $collections );
		$ids         = array_values( array_unique( $ids ) );

		// autoload = false: this can be large and is only read by the module.
		update_option( self::CACHE_IDS, $ids, false );
		update_option( self::CACHE_HASH, self::hash( $collections ), false );
		update_option( self::CACHE_TS, time(), false );

		return $ids;
	}

	/**
	 * Query SureCart for every product in the given collections.
	 *
	 * @param string[] $collection_ids Collection ids.
	 * @return string[] Product ids.
	 */
	private static function fetch_member_ids( array $collection_ids ): array {
		if ( ! class_exists( '\SureCart\Models\Product' ) ) {
			return array();
		}

		$ids = array();
		foreach ( $collection_ids as $cid ) {
			foreach ( self::paged_get( '\SureCart\Models\Product', array( 'product_collection_ids' => array( $cid ) ) ) as $product ) {
				$pid = self::prop( $product, 'id' );
				if ( '' !== $pid ) {
					$ids[] = $pid;
				}
			}
		}

		return $ids;
	}

	/**
	 * Fetch every record for a SureCart list model matching the given filters,
	 * paging through with the proven where()->get() idiom (get() returns a plain
	 * array of model objects; paginate() returns a Collection wrapper that proved
	 * unreliable, so we page manually).
	 *
	 * @param class-string         $model_class Fully-qualified SureCart model.
	 * @param array<string, mixed> $filters     Query filters.
	 * @return array<int, object> Model objects.
	 */
	private static function paged_get( string $model_class, array $filters ): array {
		$all  = array();
		$page = 1;
		do {
			try {
				$batch = $model_class::where(
					array_merge(
						$filters,
						array(
							'limit' => 100,
							'page'  => $page,
						)
					)
				)->get();
			} catch ( \Throwable $e ) {
				break;
			}

			if ( is_wp_error( $batch ) || ! is_array( $batch ) || empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $model ) {
				$all[] = $model;
			}
			++$page;
		} while ( count( $batch ) >= 100 && $page <= 100 ); // Defensive page cap.

		return $all;
	}

	/**
	 * Read a property off a SureCart model object (magic __get) or array.
	 *
	 * @param mixed  $model Model object or array.
	 * @param string $key   Property/key.
	 * @return string
	 */
	private static function prop( $model, string $key ): string {
		if ( is_object( $model ) ) {
			return (string) ( $model->{$key} ?? '' );
		}
		if ( is_array( $model ) ) {
			return (string) ( $model[ $key ] ?? '' );
		}
		return '';
	}

	/**
	 * All product collections in the store, for the admin picker. Cached briefly
	 * since the admin settings page is the only caller. Never used on the
	 * customer-facing path.
	 *
	 * @return array<int, array{id:string,name:string,products_count:int}>
	 */
	public static function all_collections(): array {
		$cache_key = 'sceu_all_collections';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array();
		if ( class_exists( '\SureCart\Models\ProductCollection' ) ) {
			foreach ( self::paged_get( '\SureCart\Models\ProductCollection', array() ) as $c ) {
				$id = self::prop( $c, 'id' );
				if ( '' !== $id ) {
					$out[] = array(
						'id'             => $id,
						'name'           => self::prop( $c, 'name' ),
						'products_count' => (int) self::prop( $c, 'products_count' ),
					);
				}
			}
		}

		set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Display labels (id => name) for the excluded products, stored alongside the
	 * picker so the admin page needn't re-fetch product names.
	 *
	 * @return array<string, string>
	 */
	public static function product_labels(): array {
		$labels = Settings::get( self::MODULE_ID, 'excluded_product_labels', array() );
		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * Drop the collection cache so the next rebuild starts clean. Call when the
	 * excluded-collection setting changes.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_option( self::CACHE_IDS );
		delete_option( self::CACHE_HASH );
		delete_option( self::CACHE_TS );
	}

	/**
	 * Normalise an option value to a clean list of non-empty string ids.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function id_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $value ) ) );
	}

	/**
	 * Stable hash of a collection-id set, for cache invalidation.
	 *
	 * @param string[] $collection_ids Collection ids.
	 * @return string
	 */
	private static function hash( array $collection_ids ): string {
		sort( $collection_ids );
		return md5( implode( ',', $collection_ids ) );
	}
}
