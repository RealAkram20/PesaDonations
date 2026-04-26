<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

use PesaDonations\Utils\Logger;

/**
 * Handles the recurring background jobs scheduled by Installer:
 *   - pd_purge_gateway_logs   → trim wp_pd_gateway_logs older than retention
 *   - pd_daily_campaign_status → flip end-dated campaigns to "ended"
 *
 * The previous FX-rates cron used the now-sunset exchangerate.host endpoint
 * and produced daily error logs without ever populating a usable transient.
 * It has been removed; multi-currency donations are gated to the campaign's
 * base currency until a working FX source is wired up.
 */
class Cron {

	public function register(): void {
		add_action( 'pd_purge_gateway_logs',    [ $this, 'purge_logs' ] );
		add_action( 'pd_daily_campaign_status', [ $this, 'update_campaign_status' ] );

		// One-time cleanup: clear the legacy FX cron so it stops firing on
		// installs upgraded from earlier builds.
		if ( wp_next_scheduled( 'pd_daily_fx_rates' ) ) {
			wp_clear_scheduled_hook( 'pd_daily_fx_rates' );
		}
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

		// Batch size cap — process at most this many campaigns per cron tick.
		// Re-queue if the result set hits the cap so the next run finishes the
		// rest. This prevents runaway loops when there are many candidates.
		$batch = (int) apply_filters( 'pd_cron_campaign_status_batch', 200 );

		// Find active campaigns whose end_date has passed.
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->postmeta} status ON status.post_id = pm.post_id AND status.meta_key = '_pd_status'
			 WHERE pm.meta_key = '_pd_end_date'
			   AND pm.meta_value != ''
			   AND pm.meta_value < %s
			   AND status.meta_value = 'active'
			 LIMIT %d",
			$today,
			$batch
		) );

		foreach ( $candidates as $post_id ) {
			update_post_meta( (int) $post_id, '_pd_status', 'ended' );
		}

		if ( ! empty( $candidates ) ) {
			Logger::info( 'Campaigns auto-ended', [ 'count' => count( $candidates ), 'ids' => $candidates ] );
		}

		// Reconcile active vs reached campaigns. Two-way: campaigns with a
		// goal that's been hit flip to 'reached'; campaigns previously
		// flipped to 'reached' that no longer meet the goal (raised goal,
		// chargeback, etc.) flip back to 'active'.
		$with_goal = $wpdb->get_results( $wpdb->prepare(
			"SELECT goal.post_id, goal.meta_value AS goal_amount, status.meta_value AS status,
				(SELECT COALESCE(SUM(amount_base),0) FROM {$wpdb->prefix}pd_donations
				 WHERE campaign_id = goal.post_id AND status = 'completed') AS raised
			 FROM {$wpdb->postmeta} goal
			 INNER JOIN {$wpdb->postmeta} status ON status.post_id = goal.post_id AND status.meta_key = '_pd_status'
			 WHERE goal.meta_key = '_pd_goal_amount'
			   AND CAST(goal.meta_value AS DECIMAL(15,2)) > 0
			   AND status.meta_value IN ('active', 'reached')
			 LIMIT %d",
			$batch
		), ARRAY_A );

		foreach ( $with_goal as $row ) {
			$reached = (float) $row['raised'] >= (float) $row['goal_amount'];
			$status  = (string) $row['status'];
			if ( $reached && 'active' === $status ) {
				update_post_meta( (int) $row['post_id'], '_pd_status', 'reached' );
			} elseif ( ! $reached && 'reached' === $status ) {
				update_post_meta( (int) $row['post_id'], '_pd_status', 'active' );
			}
		}

		// If either query saturated the batch, schedule a follow-up run within
		// the next minute so the rest of the work isn't deferred 24h.
		if ( count( $candidates ) >= $batch || count( $with_goal ) >= $batch ) {
			if ( ! wp_next_scheduled( 'pd_daily_campaign_status' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'pd_daily_campaign_status' );
			}
		}
	}
}
