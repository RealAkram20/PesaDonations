<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Utils\Logger;

class Pesapal_API {

	/**
	 * Makes an authenticated request to the PesaPal API.
	 *
	 * @param string $method  HTTP method (GET, POST, etc.)
	 * @param string $path    API path starting with /api/...
	 * @param array  $body    Request body for POST/PUT. Ignored for GET.
	 * @param array  $query   Query string parameters (for GET or appended to URL).
	 * @return array|null     Decoded JSON response, or null on failure.
	 */
	public static function request( string $method, string $path, array $body = [], array $query = [] ): ?array {
		$token = Pesapal_Auth::get_token();
		if ( ! $token ) {
			return null;
		}

		$url = Pesapal_Auth::base_url() . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'headers' => [
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'timeout' => 30,
		];

		if ( in_array( $args['method'], [ 'POST', 'PUT', 'PATCH' ], true ) && $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		$log_row = [
			'gateway'      => 'pesapal',
			'direction'    => 'outgoing',
			'endpoint'     => $path,
			'request_body' => $args['body'] ?? '',
			'created_at'   => current_time( 'mysql' ),
		];

		if ( is_wp_error( $response ) ) {
			$log_row['response_body'] = $response->get_error_message();
			self::log( $log_row );
			Logger::error( 'PesaPal API error: ' . $response->get_error_message(), [ 'path' => $path ] );
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		$log_row['http_status']   = $status;
		$log_row['response_body'] = $raw;
		self::log( $log_row );

		if ( $status >= 400 ) {
			Logger::error( 'PesaPal API ' . $status, [ 'path' => $path, 'response' => $decoded ] );

			// Token may have expired — clear and let next call refresh.
			if ( 401 === $status ) {
				Pesapal_Auth::clear_token();
			}
		}

		return is_array( $decoded ) ? $decoded : null;
	}

	private static function log( array $row ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'pd_gateway_logs', $row );
	}
}
