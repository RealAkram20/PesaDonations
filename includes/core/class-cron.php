<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

use PesaDonations\Utils\Logger;

/**
 * Handles the three recurring background jobs scheduled by Installer:
 *   - pd_daily_fx_rates       → fetch FX rates from exchangerate.host
 *   - pd_purge_gateway_logs   → trim wp_pd_gateway_logs older than retention
 *   - pd_daily_campaign_status → flip end-dated campaigns to "ended"
 */
class Cron {

	public function register(): void {
		add_action( 'pd_daily_fx_rates',        [ $this, 'refresh_fx_rates' ] );
		add_action( 'pd_purge_gateway_logs',    [ $this, 'purge_logs' ] );
		add_action( 'pd_daily_campaign_status', [ $this, 'update_campaign_status' ] );
	}

	// -------------------------------------------------------------------------
	// FX Rates
	// -------------------------------------------------------------------------

	public function refresh_fx_rates(): void {
		$base    = (string) get_option( 'pd_default_currency', 'UGX' );
		$symbols = (array) get_option( 'pd_enabled_currencies', [ 'UGX', 'KES', 'TZS', 'USD' ] );
		$symbols = array_values( array_unique( array_filter( array_map( 'strtoupper', $symbols ) ) ) );

		if ( empty( $symbols ) ) {
			return;
		}

		$url = add_query_arg( [
			'base'    => strtoupper( $base ),
			'symbols' => implode( ',', $symbols ),
		], 'https://api.exchangerate.host/latest' );

		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			Logger::error( 'FX rates fetch failed: ' . $response->get_error_message() );
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			Logger::error( 'FX rates HTTP ' . $status );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['rates'] ) ) {
			Logger::error( 'FX rates returned invalid payload' );
			return;
		}

		set_transient( 'pd_fx_rates', [
			'base'      => strtoupper( $base ),
			'rates'     => $body['rates'],
			'fetched'   => time(),
		], DAY_IN_SECONDS );

		Logger::info( 'FX rates refreshed', [ 'base' => $base, 'count' => count( $body['rates'] ) ] );
	}

	// -------------------------------------------------------------------------
	// Log Purging
	// -------------------------------------------------------------------------

	public function purge_logs(): void {
		global $wpdb;

		$days = max( 7, (int) get_option( 'pd_log_retention_days', 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}pd_gateway_logs WHERE created_at < %s",
			$cutoff
		) );

		if ( $deleted > 0 ) {
			Logger::info( 'Gateway logs purged', [ 'deleted' => $deleted, 'older_than' => $cutoff ] );
		}
	}

	// -------------------------------------------------------------------------
	// Campaign Status (auto-end past-deadline campaigns)
	// -------------------------------------------------------------------------

	public function update_campaign_status(): void {
		global $wpdb;

		$today = gmdate( 'Y-m-d' );

		// Find active campaigns whose end_date has passed.
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->postmeta} status ON status.post_id = pm.post_id AND status.meta_key = '_pd_status'
			 WHERE pm.meta_key = '_pd_end_date'
			   AND pm.meta_value != ''
			   AND pm.meta_value < %s
			   AND status.meta_value = 'active'",
			$today
		) );

		foreach ( $candidates as $post_id ) {
			update_post_meta( (int) $post_id, '_pd_status', 'ended' );
		}

		if ( ! empty( $candidates ) ) {
			Logger::info( 'Campaigns auto-ended', [ 'count' => count( $candidates ), 'ids' => $candidates ] );
		}

		// Also: flip campaigns that hit 100% to "reached" (visual only — donations still allowed).
		$active_with_goal = $wpdb->get_results(
			"SELECT goal.post_id, goal.meta_value AS goal_amount,
				(SELECT COALESCE(SUM(amount_base),0) FROM {$wpdb->prefix}pd_donations
				 WHERE campaign_id = goal.post_id AND status = 'completed') AS raised
			 FROM {$wpdb->postmeta} goal
			 INNER JOIN {$wpdb->postmeta} status ON status.post_id = goal.post_id AND status.meta_key = '_pd_status'
			 WHERE goal.meta_key = '_pd_goal_amount'
			   AND CAST(goal.meta_value AS DECIMAL(15,2)) > 0
			   AND status.meta_value = 'active'",
			ARRAY_A
		);

		foreach ( $active_with_goal as $row ) {
			if ( (float) $row['raised'] >= (float) $row['goal_amount'] ) {
				update_post_meta( (int) $row['post_id'], '_pd_status', 'reached' );
			}
		}
	}
}
