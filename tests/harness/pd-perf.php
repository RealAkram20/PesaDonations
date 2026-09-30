<?php
defined( 'ABSPATH' ) || exit; // A must-use plugin for the throwaway test site only.
/**
 * Throwaway measurement for the PesaDonations efficiency pass (D:\pdwp only).
 * One JSON line per request: time, queries (all and PesaDonations'), repeated SQL, memory.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
register_shutdown_function( static function () {
	global $wpdb;
	if ( ! isset( $wpdb->queries ) || ( defined( 'WP_CLI' ) && WP_CLI && empty( $GLOBALS['pd_perf_cli'] ) ) ) {
		return;
	}
	$total = 0.0;
	$pd    = 0;
	$pd_ms = 0.0;
	$same  = [];
	$pd_shapes = [];
	foreach ( $wpdb->queries as $q ) {
		$total += (float) $q[1];
		// Normalise literals so an N+1 shows up as one statement repeated.
		$shape = preg_replace( [ "/'[^']*'/", '/\b\d+\b/' ], [ "'?'", '?' ], $q[0] );
		$same[ $shape ] = ( $same[ $shape ] ?? 0 ) + 1;
		$is_pd  = false !== stripos( $q[2], 'PesaDonations' ) || false !== stripos( $q[0], 'pd_' );
		if ( $is_pd ) {
			$pd++;
			$pd_ms += (float) $q[1];
			$pd_shapes[ $shape ] = ( $pd_shapes[ $shape ] ?? 0 ) + 1;
		}
	}
	arsort( $pd_shapes );
	arsort( $same );
	$top = array_slice( array_filter( $same, static fn( $n ) => $n > 3 ), 0, 5, true );
	$line = [
		'uri'      => $_SERVER['REQUEST_URI'] ?? ( $GLOBALS['pd_perf_label'] ?? 'cli' ),
		'ms'       => round( ( microtime( true ) - ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) ) * 1000, 1 ),
		'queries'  => count( $wpdb->queries ),
		'db_ms'    => round( $total * 1000, 1 ),
		'pd_q'     => $pd,
		'pd_db_ms' => round( $pd_ms * 1000, 1 ),
		'mem_mb'   => round( memory_get_peak_usage() / 1048576, 1 ),
		'repeated' => array_map( static fn( $s, $n ) => $n . 'x ' . substr( preg_replace( '/\s+/', ' ', $s ), 0, 160 ), array_keys( $top ), $top ),
		'pd_sql'   => array_map( static fn( $s, $n ) => $n . 'x ' . substr( preg_replace( '/\s+/', ' ', $s ), 0, 140 ), array_keys( array_slice( $pd_shapes, 0, 8, true ) ), array_slice( $pd_shapes, 0, 8, true ) ),
	];
	file_put_contents( 'D:/pdtest/perf/requests.log', wp_json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX );
} );
