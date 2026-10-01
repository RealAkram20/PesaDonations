<?php
declare( strict_types=1 );

namespace PesaDonations\Modules\Campaign_Cycles;

use PesaDonations\Models\Donation;
use PesaDonations\CPT\Campaign_CPT;
use PesaDonations\Models\Campaign;
use PesaDonations\Models\Campaign_Period;
use PesaDonations\Models\Campaign_Totals;
use PesaDonations\Modules\Email_Notifications\Email_Notifications;
use PesaDonations\Utils\Logger;

/**
 * Moves repeating campaigns into their next period and asks last period's
 * donors to give again.
 *
 * The reset itself needs no job: a campaign's totals are always computed for
 * its current period. This hourly job does what must happen once per new period:
 *   - a campaign marked "Goal Reached" goes back to "Active";
 *   - a custom-period campaign whose last period is over is marked "Ended";
 *   - last period's donors get one email each, if the campaign allows it.
 *
 * A new period is claimed with a conditional UPDATE on `_pd_cycle_current`, so
 * two overlapping cron runs cannot both send. Emails go out BATCH_SIZE at a time,
 * resuming after the last address sent (keyset on email). One run sends batches
 * for up to RUN_SECONDS, then hands over to the next run, so a cron request
 * stays short on shared hosting. The next run is booked before any email goes
 * out: a run that dies half way is resumed from the cursor, not abandoned.
 */
class Campaign_Cycles {

	public const BATCH_SIZE  = 50;
	private const RUN_SECONDS = 20;
	private const HOOK        = 'pd_send_cycle_reminders';

	public function register(): void {
		add_action( 'pd_hourly_campaign_cycles', [ $this, 'roll_over' ] );
		add_action( self::HOOK, [ $this, 'send_reminder_batch' ], 10, 2 );
		add_action( 'template_redirect', [ $this, 'handle_opt_out' ] );
	}

	public function roll_over(): void {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_pd_cycle_type'
			 INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_pd_status'
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND t.meta_value NOT IN ('', 'once')
			   AND s.meta_value IN ('active', 'reached')",
			Campaign_CPT::POST_TYPE
		) );

		foreach ( $ids as $id ) {
			$campaign = Campaign::get( (int) $id );
			if ( $campaign ) {
				$this->roll_campaign( $campaign );
			}
		}
	}

	public function roll_campaign( Campaign $campaign ): void {
		$id      = $campaign->get_id();
		$current = $campaign->get_current_period();

		if ( ! $current ) {
			if ( $campaign->repeats() ) {
				// Every custom period is over and none was added.
				update_post_meta( $id, '_pd_status', 'ended' );
				Logger::info( 'Campaign ended: no periods left', [ 'campaign' => $id ] );
			}
			return;
		}

		$key    = $current->get_key();
		$stored = (string) get_post_meta( $id, '_pd_cycle_current', true );
		if ( $stored === $key ) {
			$this->resume_if_stalled( $campaign, $key );
			return;
		}
		if ( '' === $stored ) {
			// First sighting (the editor normally records it on save): nothing to announce.
			update_post_meta( $id, '_pd_cycle_current', $key );
			return;
		}
		if ( ! $this->claim( $id, $stored, $key ) ) {
			return;
		}

		if ( 'reached' === $campaign->get_status() ) {
			update_post_meta( $id, '_pd_status', 'active' );
		}
		Campaign_Totals::flush();

		Logger::info( 'Campaign moved to a new period', [ 'campaign' => $id, 'from' => $stored, 'to' => $key ] );

		if ( $campaign->sends_cycle_reminders() && $campaign->get_schedule()->previous( $current ) ) {
			update_post_meta( $id, '_pd_cycle_reminder_cursor', '' );
			// Written now, so the hourly job can tell a run that never happened
			// from a finished one, and restart it (resume_if_stalled).
			update_post_meta( $id, '_pd_cycle_reminder_stats', [
				'period' => $key, 'label' => $current->get_label(), 'sent' => 0, 'failed' => 0, 'done' => false,
			] );
			wp_schedule_single_event( time(), self::HOOK, [ $id, $key ] );
		}
	}

	/** Reminders for this period started and did not finish, and nothing is booked: book the next run. */
	private function resume_if_stalled( Campaign $campaign, string $key ): void {
		if ( ! $campaign->sends_cycle_reminders() ) {
			return; // Switched off meanwhile: nothing to resume.
		}
		$stats = get_post_meta( $campaign->get_id(), '_pd_cycle_reminder_stats', true );
		// 'done' exists only in stats written by this version; older ones are left alone.
		if ( ! is_array( $stats ) || ( $stats['period'] ?? '' ) !== $key || ! array_key_exists( 'done', $stats ) || ! empty( $stats['done'] ) ) {
			return;
		}
		$args = [ $campaign->get_id(), $key ];
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			Logger::info( 'Period reminders resumed after a stalled run', [ 'campaign' => $campaign->get_id(), 'period' => $key ] );
			wp_schedule_single_event( time(), self::HOOK, $args );
		}
	}

	/**
	 * Emails the next batch of last period's donors. Stops quietly when the
	 * campaign has moved on, closed, or had reminders switched off meanwhile.
	 */
	public function send_reminder_batch( $campaign_id, $period_key ): void {
		// Logged before every guard: "never ran" and "ran and declined" must not look alike.
		$context  = [ 'campaign' => (int) $campaign_id, 'period' => (string) $period_key ];
		$campaign = Campaign::get( (int) $campaign_id );
		if ( ! $campaign || ! $campaign->accepts_donations() || ! $campaign->sends_cycle_reminders() ) {
			Logger::info( 'Period reminders skipped: campaign closed or reminders off', $context );
			return;
		}
		$current = $campaign->get_current_period();
		if ( ! $current || $current->get_key() !== (string) $period_key ) {
			Logger::info( 'Period reminders skipped: a later period has begun', $context );
			return;
		}
		$previous = $campaign->get_schedule()->previous( $current );
		if ( ! $previous ) {
			Logger::info( 'Period reminders skipped: no earlier period', $context );
			return;
		}

		$id   = $campaign->get_id();
		$args = [ $id, $current->get_key() ];

		// Booked before the first email: if this run dies (a PHP timeout, a hung
		// mail server), that run resumes from the cursor. It is moved sooner, or
		// removed, when this run ends normally.
		wp_clear_scheduled_hook( self::HOOK, $args );
		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK, $args );

		$mailer = new Email_Notifications();
		$stats  = get_post_meta( $id, '_pd_cycle_reminder_stats', true );
		if ( ! is_array( $stats ) || ( $stats['period'] ?? '' ) !== $current->get_key() ) {
			$stats = [ 'period' => $current->get_key(), 'label' => $current->get_label(), 'sent' => 0, 'failed' => 0 ];
		}

		$deadline = microtime( true ) + self::RUN_SECONDS;
		$sent_now = 0;
		do {
			$rows = $this->next_batch( $campaign, $current, $previous );
			foreach ( $rows as $row ) {
				if ( is_email( $row['email'] ) && $mailer->send_cycle_reminder( $campaign, $current, $previous, $row['email'], (string) $row['name'] ) ) {
					$stats['sent']++;
				} else {
					$stats['failed']++;
				}
				++$sent_now;
				// Advance past failures too: a bad address must not stall everyone after it.
				update_post_meta( $id, '_pd_cycle_reminder_cursor', $row['email'] );
			}
		} while ( count( $rows ) === self::BATCH_SIZE && microtime( true ) < $deadline );

		$finished      = count( $rows ) < self::BATCH_SIZE;
		$stats['done'] = $finished;
		// Shown in the campaign editor, so the admin can see the reminders went out.
		$stats['updated'] = current_time( 'mysql' );
		update_post_meta( $id, '_pd_cycle_reminder_stats', $stats );
		Logger::info( 'Period reminders run', $context + [ 'emails' => $sent_now, 'sent_total' => $stats['sent'], 'failed_total' => $stats['failed'], 'done' => $finished ] );

		wp_clear_scheduled_hook( self::HOOK, $args );
		if ( ! $finished ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, $args );
		}
	}

	/**
	 * The next donors of the previous period, after the cursor: completed gifts,
	 * a real address, not opted out, and not already given toward this period
	 * (a "give again" email to someone who just gave reads as a mistake).
	 */
	private function next_batch( Campaign $campaign, Campaign_Period $current, Campaign_Period $previous ): array {
		global $wpdb;
		$cursor = (string) get_post_meta( $campaign->get_id(), '_pd_cycle_reminder_cursor', true );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT d.donor_email AS email, MAX(d.donor_name) AS name
			 FROM {$wpdb->prefix}pd_donations d
			 LEFT JOIN {$wpdb->prefix}pd_donors r ON r.email = d.donor_email
			 WHERE d.campaign_id = %d
			   AND " . Donation::counted_sql( 'd' ) . "
			   AND d.created_at BETWEEN %s AND %s
			   AND d.donor_email <> '' AND d.donor_email NOT LIKE %s
			   AND d.donor_email > %s
			   AND COALESCE(r.reminders_opt_out, 0) = 0
			   AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->prefix}pd_donations n
				WHERE n.campaign_id = d.campaign_id AND n.donor_email = d.donor_email
				  AND " . Donation::counted_sql( 'n' ) . "
				  AND n.created_at BETWEEN %s AND %s
			   )
			 GROUP BY d.donor_email
			 ORDER BY d.donor_email
			 LIMIT %d",
			$campaign->get_id(),
			$previous->window_start_sql(),
			$previous->window_end_sql(),
			'%@phone.pd',
			$cursor,
			$current->window_start_sql(),
			$current->window_end_sql(),
			self::BATCH_SIZE
		), ARRAY_A ) ?: [];
	}

	/** Atomic compare-and-set on the recorded period: exactly one caller wins. */
	private function claim( int $campaign_id, string $from, string $to ): bool {
		global $wpdb;
		// At least one row: postmeta has no unique key, and a duplicated
		// _pd_cycle_current row made "exactly 1" fail forever, silently.
		$won = 1 <= (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = %s
			 WHERE post_id = %d AND meta_key = '_pd_cycle_current' AND meta_value = %s",
			$to,
			$campaign_id,
			$from
		) );
		wp_cache_delete( $campaign_id, 'post_meta' );
		return $won;
	}

	// -------------------------------------------------------------------------
	// Opt-out
	// -------------------------------------------------------------------------

	public static function opt_out_url( string $email ): string {
		$email = strtolower( $email );
		return add_query_arg( [
			'pd_optout' => rawurlencode( $email ),
			'pd_sig'    => self::signature( $email ),
		], home_url( '/' ) );
	}

	private static function signature( string $email ): string {
		return substr( wp_hash( 'pd_optout|' . $email ), 0, 24 );
	}

	/**
	 * The link shows a confirm button and only the POST opts out, so a mail
	 * scanner that prefetches links cannot unsubscribe anyone.
	 */
	public function handle_opt_out(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- signed link, no session.
		if ( empty( $_GET['pd_optout'] ) || empty( $_GET['pd_sig'] ) ) {
			return;
		}
		if ( ! is_string( $_GET['pd_optout'] ) || ! is_string( $_GET['pd_sig'] ) ) {
			// ?pd_optout[]=x made sanitize_email() throw: a 500 and a fatal per scanner hit.
			wp_die( esc_html__( 'This link is not valid.', 'pesa-donations' ), '', [ 'response' => 400 ] );
		}
		$email = strtolower( sanitize_email( wp_unslash( $_GET['pd_optout'] ) ) );
		$sig   = sanitize_text_field( wp_unslash( $_GET['pd_sig'] ) );
		$title = __( 'Email preferences', 'pesa-donations' );

		if ( ! $email || ! hash_equals( self::signature( $email ), $sig ) ) {
			wp_die( esc_html__( 'This link is not valid. Please use the link from your most recent email.', 'pesa-donations' ), esc_html( $title ), [ 'response' => 400 ] );
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			global $wpdb;
			// An upsert: the reminders go to donation emails, and a deleted donor
			// row used to make this update nothing while the page said "Done".
			$now   = current_time( 'mysql' );
			$saved = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}pd_donors (email, reminders_opt_out, created_at, updated_at)
				 VALUES (%s, 1, %s, %s)
				 ON DUPLICATE KEY UPDATE reminders_opt_out = 1, updated_at = VALUES(updated_at)",
				$email,
				$now,
				$now
			) );
			if ( false === $saved ) {
				Logger::error( 'Reminder opt-out not saved', [ 'db_error' => $wpdb->last_error ] );
				wp_die( esc_html__( 'Something went wrong and your choice was not saved. Please try again later.', 'pesa-donations' ), esc_html( $title ), [ 'response' => 500 ] );
			}
			wp_die(
				esc_html__( 'Done. You will not receive "new period" reminders from us again. Your receipts are not affected.', 'pesa-donations' ),
				esc_html( $title ),
				[ 'response' => 200 ]
			);
		}

		$message = sprintf(
			'<p>%s</p><form method="post"><button type="submit" class="button">%s</button></form>',
			esc_html( sprintf(
				/* translators: %s: email address */
				__( 'Stop emails asking %s to give again when a new campaign period starts?', 'pesa-donations' ),
				$email
			) ),
			esc_html__( 'Yes, stop these emails', 'pesa-donations' )
		);
		wp_die( wp_kses( $message, [ 'p' => [], 'form' => [ 'method' => [] ], 'button' => [ 'type' => [], 'class' => [] ] ] ), esc_html( $title ), [ 'response' => 200 ] );
		// phpcs:enable
	}
}
