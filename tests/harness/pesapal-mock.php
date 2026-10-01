<?php
defined( 'ABSPATH' ) || exit; // A must-use plugin for the throwaway test site only.
/**
 * THROWAWAY TEST SITE ONLY (D:\pdwp). Answers PesaPal's API from this site so
 * the whole payment path runs without the network.
 * The status PesaPal "reports" comes from option pd_mock_status (default 1 = COMPLETED);
 * pd_mock_amount_delta lets a test make the reported amount disagree.
 * Every intercepted call is counted in option pd_mock_calls.
 */
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( ! str_contains( (string) $url, 'pesapal.com/' ) ) {
		return $pre;
	}
	$calls   = (array) get_option( 'pd_mock_calls', [] );
	$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
	$calls[] = $path;
	update_option( 'pd_mock_calls', $calls, false );

	$json = static fn( array $body, int $code = 200 ) => [
		'headers'  => [],
		'body'     => wp_json_encode( $body ),
		'response' => [ 'code' => $code, 'message' => 'OK' ],
		'cookies'  => [],
		'filename' => null,
	];

	if ( str_ends_with( $path, '/api/Auth/RequestToken' ) ) {
		return $json( [ 'token' => 'mock-token', 'expiryDate' => gmdate( 'c', time() + 300 ) ] );
	}
	if ( str_ends_with( $path, '/api/URLSetup/RegisterIPN' ) ) {
		return $json( [ 'ipn_id' => 'mock-ipn-1' ] );
	}
	if ( str_ends_with( $path, '/api/Transactions/SubmitOrderRequest' ) ) {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		return $json( [
			'order_tracking_id' => 'trk-' . md5( (string) ( $body['id'] ?? '' ) ),
			'merchant_reference' => (string) ( $body['id'] ?? '' ),
			'redirect_url'      => 'https://pay.example.test/iframe/' . rawurlencode( (string) ( $body['id'] ?? '' ) ),
		] );
	}
	if ( str_ends_with( $path, '/api/Transactions/GetTransactionStatus' ) ) {
		if ( get_option( 'pd_mock_fail' ) ) {
			return new WP_Error( 'http_request_failed', 'mock: PesaPal unreachable' );
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT merchant_reference, amount, currency FROM {$wpdb->prefix}pd_donations WHERE order_tracking_id = %s",
			(string) ( $q['orderTrackingId'] ?? '' )
		), ARRAY_A );
		if ( ! $row ) {
			return $json( [ 'status_code' => 0, 'error' => [ 'message' => 'unknown order' ] ] );
		}
		return $json( [
			'status_code'        => (int) get_option( 'pd_mock_status', 1 ),
			'merchant_reference' => $row['merchant_reference'],
			'amount'             => (float) $row['amount'] + (float) get_option( 'pd_mock_amount_delta', 0 ),
			'currency'           => $row['currency'],
			'payment_method'     => 'MTN Mobile Money',
			'confirmation_code'  => 'MOCK' . substr( md5( $row['merchant_reference'] ), 0, 8 ),
		] );
	}
	return $json( [ 'error' => 'unmocked ' . $path ], 404 );
}, 10, 3 );
