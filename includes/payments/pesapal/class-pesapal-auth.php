<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Utils\Logger;

class Pesapal_Auth {

	private const TRANSIENT_PREFIX = 'pd_pesapal_token_';
	private const TTL              = 4 * MINUTE_IN_SECONDS;

	/**
	 * Token cache key is scoped per environment so switching sandbox ↔
	 * production in the admin doesn't reuse the wrong-env token (which
	 * fails 401 against the new env's API).
	 */
	private static function transient_key(): string {
		$env = (string) get_option( 'pd_pesapal_environment', 'sandbox' );
		return self::TRANSIENT_PREFIX . sanitize_key( $env );
	}

	public static function get_token(): string {
		$cached = get_transient( self::transient_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$token = self::request_token();
		if ( $token ) {
			set_transient( self::transient_key(), $token, self::TTL );
		}
		return $token;
	}

	public static function clear_token(): void {
		// Clear both env caches so a credential change is reflected
		// regardless of which env is currently active.
		delete_transient( self::TRANSIENT_PREFIX . 'sandbox' );
		delete_transient( self::TRANSIENT_PREFIX . 'production' );
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
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'PesaPal auth failed: ' . $response->get_error_message() );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['token'] ) ) {
			Logger::error( 'PesaPal auth returned no token', [ 'body' => $body ] );
			return '';
		}

		return (string) $body['token'];
	}

	public static function base_url(): string {
		$env = get_option( 'pd_pesapal_environment', 'sandbox' );
		return 'production' === $env
			? 'https://pay.pesapal.com/v3'
			: 'https://cybqa.pesapal.com/pesapalv3';
	}
}
