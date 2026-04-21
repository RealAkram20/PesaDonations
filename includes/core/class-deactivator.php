<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

class Deactivator {

	public static function deactivate(): void {
		flush_rewrite_rules();

		// Clear all scheduled PesaDonations cron events.
		$hooks = [
			'pd_daily_fx_rates',
			'pd_purge_gateway_logs',
			'pd_daily_campaign_status',
		];

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
