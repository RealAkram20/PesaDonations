<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

use PesaDonations\Models\Campaign;
use PesaDonations\Utils\Logger;

/**
 * The daily background jobs scheduled by Installer:
 *   - pd_purge_gateway_logs    → trim wp_pd_gateway_logs and the log files older than retention
 *   - pd_daily_campaign_status → flip end-dated campaigns to "ended", full ones to "reached"
 * (pd_daily_fx_rates was removed in 1.2.0: nothing read its result, and the
 * provider now requires a key. The installer clears the old schedule.)
 */
class Cron {

	public function register(): void {
		add_action( 'pd_purge_gateway_logs',    [ $this, 'purge_logs' ] );
		add_action( 'pd_daily_campaign_status', [ $this, 'update_campaign_status' ] );
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

		// The plugin's own log files hold error details and, on failed mail, addresses.
		Logger::purge_older_than( $days );
	}

	// -------------------------------------------------------------------------
	// Campaign Status (auto-end past-deadline campaigns)
	// -------------------------------------------------------------------------

	public function update_campaign_status(): void {
		global $wpdb;

		// Site-local day: end dates are typed in the site's timezone.
		$today = current_time( 'Y-m-d' );

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
		// Raised is the current period's for a repeating campaign; Campaign_Cycles
		// sets it back to "active" when the next period begins.
		$active_with_goal = $wpdb->get_col(
			"SELECT goal.post_id
			 FROM {$wpdb->postmeta} goal
			 INNER JOIN {$wpdb->postmeta} status ON status.post_id = goal.post_id AND status.meta_key = '_pd_status'
			 WHERE goal.meta_key = '_pd_goal_amount'
			   AND CAST(goal.meta_value AS DECIMAL(15,2)) > 0
			   AND status.meta_value = 'active'"
		);

		foreach ( $active_with_goal as $post_id ) {
			$campaign = Campaign::get( (int) $post_id );
			if ( $campaign && $campaign->get_raised_amount() >= $campaign->get_goal_amount() ) {
				update_post_meta( (int) $post_id, '_pd_status', 'reached' );
			}
		}
	}
}
