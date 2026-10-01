<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Utils\Logger;

class Pesapal_Auth {

	private const TTL = 4 * MINUTE_IN_SECONDS;

	public static function get_token(): string {
		$cached = get_transient( self::transient() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$token = self::request_token();
		if ( $token ) {
			set_transient( self::transient(), $token, self::TTL );
		}
		return $token;
	}

	public static function clear_token(): void {
		delete_transient( self::transient() );
	}

	/**
	 * Keyed by environment and consumer key: a token issued for the sandbox,
	 * or for another merchant's keys, is never sent after a settings change.
	 */
	private static function transient(): string {
		return 'pd_pesapal_token_' . substr( md5( self::environment() . '|' . get_option( 'pd_pesapal_consumer_key' ) ), 0, 12 );
	}

	private static function request_token(): string {
		$key    = (string) get_option( 'pd_pesapal_consumer_key' );
		$secret = (string) get_option( 'pd_pesapal_consumer_secret' );

		if ( ! $key || ! $secret ) {
			Logger::error( 'PesaPal auth: missing credentials' );
			return '';
		}

		$url = self::base_url() . '/api/Auth/RequestToken';

		$response = wp_remote_post( $url, [
			'headers' => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( [
				'consumer_key'    => $key,
				'consumer_secret' => $secret,
			] ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'PesaPal auth failed: ' . $response->get_error_message() );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['token'] ) ) {
			Logger::error( 'PesaPal auth returned no token', [ 'http' => wp_remote_retrieve_response_code( $response ) ] );
			return '';
		}

		return (string) $body['token'];
	}

	public static function environment(): string {
		return 'production' === get_option( 'pd_pesapal_environment', 'sandbox' ) ? 'production' : 'sandbox';
	}

	public static function base_url(): string {
		return 'production' === self::environment()
			? 'https://pay.pesapal.com/v3'
			: 'https://cybqa.pesapal.com/pesapalv3';
	}
}
