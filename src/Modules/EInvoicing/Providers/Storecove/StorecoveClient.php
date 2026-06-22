<?php
/**
 * Thin HTTP client for the Storecove V2 API.
 *
 * @package SureCartEuHelper
 */

namespace SureCartEuHelper\Modules\EInvoicing\Providers\Storecove;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_remote_request for Storecove: Bearer auth, JSON in/out, and a single
 * normalized response shape. Storecove uses one base URL; the sandbox is
 * selected by using a sandbox API key (so the environment chooses which stored
 * key this client is constructed with). The base URL is filterable for testing.
 */
class StorecoveClient {

	const BASE_URL = 'https://api.storecove.com/api/v2';

	/** @var string */
	private $api_key;

	/**
	 * @param string $api_key Bearer API key for the active environment.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Base URL (filterable).
	 *
	 * @return string
	 */
	private function base_url(): string {
		return (string) apply_filters( 'sceu_einv_storecove_base_url', self::BASE_URL );
	}

	/**
	 * POST JSON.
	 *
	 * @param string              $path Path beginning with '/'.
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed>
	 */
	public function post( string $path, array $body ): array {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * GET.
	 *
	 * @param string $path Path beginning with '/'.
	 * @return array<string,mixed>
	 */
	public function get( string $path ): array {
		return $this->request( 'GET', $path, null );
	}

	/**
	 * Perform a request and normalize the response to:
	 *   [ 'ok' => bool, 'code' => int, 'json' => array, 'raw' => string,
	 *     'error' => string, 'transient' => bool ]
	 *
	 * 'transient' marks network errors / 429 / 5xx (worth retrying) vs 4xx
	 * (permanent — bad content/credentials).
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path.
	 * @param array<string,mixed>|null $body   Body (POST).
	 * @return array<string,mixed>
	 */
	private function request( string $method, string $path, ?array $body ): array {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'        => false,
				'code'      => 0,
				'json'      => array(),
				'raw'       => '',
				'error'     => $response->get_error_message(),
				'transient' => true, // Network failure — retry.
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			$json = array();
		}

		$ok = $code >= 200 && $code < 300;

		return array(
			'ok'        => $ok,
			'code'      => $code,
			'json'      => $json,
			'raw'       => $raw,
			'error'     => $ok ? '' : self::error_message( $code, $json, $raw ),
			'transient' => ! $ok && ( 429 === $code || $code >= 500 ),
		);
	}

	/**
	 * Best-effort human error from a Storecove error body.
	 *
	 * @param int                 $code HTTP code.
	 * @param array<string,mixed> $json Decoded body.
	 * @param string              $raw  Raw body.
	 * @return string
	 */
	private static function error_message( int $code, array $json, string $raw ): string {
		if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
			return $json['message'];
		}
		if ( isset( $json['errors'] ) ) {
			return wp_json_encode( $json['errors'] );
		}
		return 'HTTP ' . $code . ( '' !== $raw ? ': ' . substr( $raw, 0, 300 ) : '' );
	}
}
