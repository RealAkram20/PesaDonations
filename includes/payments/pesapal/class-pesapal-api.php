<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Utils\Logger;

class Pesapal_API {

	/**
	 * Makes an authenticated request to the PesaPal API.
	 *
	 * @param string $method      HTTP method (GET, POST, etc.)
	 * @param string $path        API path starting with /api/...
	 * @param array  $body        Request body for POST/PUT. Ignored for GET.
	 * @param array  $query       Query string parameters (for GET or appended to URL).
	 * @param int    $donation_id Recorded on the gateway log row, so a donation's history can be found.
	 * @return array|null         Decoded JSON response, or null on failure.
	 */
	public static function request( string $method, string $path, array $body = [], array $query = [], int $donation_id = 0 ): ?array {
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
			// Up to three calls chain in one checkout (token, IPN registration, order),
			// so each is bounded well below PHP's usual 30 s request limit.
			'timeout' => 15,
		];

		if ( in_array( $args['method'], [ 'POST', 'PUT', 'PATCH' ], true ) && $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		$log_row = [
			'gateway'             => 'pesapal',
			'direction'           => 'outgoing',
			'endpoint'            => substr( $path . ( $query ? '?' . http_build_query( $query ) : '' ), 0, 255 ),
			'request_body'        => $body ? wp_json_encode( self::redact( $body ) ) : '',
			'related_donation_id' => $donation_id ?: null,
			'created_at'          => current_time( 'mysql' ),
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
		$log_row['response_body'] = is_array( $decoded ) ? wp_json_encode( self::redact( $decoded ) ) : substr( $raw, 0, 2000 );
		self::log( $log_row );

		if ( $status >= 400 ) {
			Logger::error( 'PesaPal API ' . $status, [ 'path' => $path ] );

			// Token may have expired — clear and let next call refresh.
			if ( 401 === $status ) {
				Pesapal_Auth::clear_token();
			}
		}

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Gateway logs keep what explains a payment, not who made it: the
	 * donor's name, email, phone and payment account are masked.
	 */
	private static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			} elseif ( in_array( $key, [ 'email_address', 'phone_number', 'first_name', 'middle_name', 'last_name', 'payment_account', 'line_1', 'line_2', 'postal_code' ], true ) && '' !== (string) $value ) {
				$data[ $key ] = '***';
			}
		}
		return $data;
	}

	private static function log( array $row ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'pd_gateway_logs', $row );
	}
}
