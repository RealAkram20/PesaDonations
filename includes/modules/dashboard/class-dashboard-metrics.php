<?php
declare( strict_types=1 );

namespace PesaDonations\Modules\Dashboard;

use PesaDonations\Models\Donation;
use DateTimeImmutable;
use PesaDonations\CPT\Campaign_CPT;
use PesaDonations\Models\Campaign;
use PesaDonations\Models\Campaign_Period;
use PesaDonations\Models\Campaign_Schedule;
use PesaDonations\Models\Campaign_Totals;
use PesaDonations\Models\Open_Donation;

/**
 * The numbers behind the staff dashboard. Read-only.
 *
 * Money is summed as counted: each gift's amount_base, in its base_currency
 * (its campaign's currency, converted at checkout at the day's rate since 1.3;
 * docs/adr/0003). The totals are the default currency's; gifts counted in
 * another currency (a USD campaign, or a gift from before 1.3) are listed
 * beside them, never added. Dates are site-local like created_at, and a donation is placed
 * by when it was made (created_at), the same rule the campaign periods use.
 *
 * Sized for a few hundred campaigns and a few hundred thousand donations: the
 * range queries scan (created_at) or (campaign_id, created_at); the new-donor
 * split looks each person up by index (donor_id) instead of grouping history.
 */
class Dashboard_Metrics {

	/** Presets: days, or months for 12m. */
	public const RANGES = [ '7d' => 7, '30d' => 30, '90d' => 90, '12m' => 12 ];

	/**
	 * Who a donation belongs to: the donor record, else the email, else the phone.
	 * Never NULL where it is used, so a NOT IN cannot silently match nothing.
	 */
	private const WHO = Donation::WHO_SQL;

	private string $table;
	private string $currency;
	private DateTimeImmutable $today;
	/** @var \WP_Post[]|null */
	private ?array $open_posts = null;

	public function __construct( ?DateTimeImmutable $today = null ) {
		global $wpdb;
		$this->table    = $wpdb->prefix . 'pd_donations';
		$this->currency = Open_Donation::currency();
		$this->today    = $today ?? new DateTimeImmutable( 'today', wp_timezone() );
	}

	// -------------------------------------------------------------------------
	// Range-scoped: follows the range picker
	// -------------------------------------------------------------------------

	public function build( string $range ): array {
		$range = array_key_exists( $range, self::RANGES ) ? $range : '30d';
		$w     = $this->window( $range );

		return [
			'range'            => $range,
			'currency'         => $this->currency,
			'from'             => $w['from']->format( 'Y-m-d' ),
			'to'               => $this->today->format( 'Y-m-d' ),
			'label'            => Campaign_Period::format_range( $w['from'], $this->today ),
			'kpis'             => $this->kpis( $w ),
			'series'           => $this->series( $range, $w ),
			'by_campaign'      => $this->by_campaign( $w ),
			'other_currencies' => $this->other_currencies( $w ),
			'donors'           => $this->donors( $w ),
			'donations_url'    => $this->donations_url( [ 'pd_from' => $w['from']->format( 'Y-m-d' ), 'pd_to' => $this->today->format( 'Y-m-d' ), 'pd_status' => 'completed' ] ),
		];
	}

	/**
	 * @return array{from: DateTimeImmutable, to: string, prev_from: string, prev_to: string}
	 */
	private function window( string $range ): array {
		if ( '12m' === $range ) {
			$from      = $this->today->modify( 'first day of this month' )->modify( '-11 months' );
			$prev_from = $from->modify( '-12 months' );
			$prev_to   = $this->today->modify( '-12 months' );
		} else {
			$days      = self::RANGES[ $range ];
			$from      = $this->today->modify( '-' . ( $days - 1 ) . ' days' );
			$prev_from = $from->modify( '-' . $days . ' days' );
			$prev_to   = $from->modify( '-1 day' );
		}
		return [
			'from'      => $from,
			'to'        => $this->today->format( 'Y-m-d 23:59:59' ),
			'prev_from' => $prev_from->format( 'Y-m-d 00:00:00' ),
			'prev_to'   => $prev_to->format( 'Y-m-d 23:59:59' ),
		];
	}

	private function kpis( array $w ): array {
		$now  = $this->totals( $w['from']->format( 'Y-m-d 00:00:00' ), $w['to'] );
		$prev = $this->totals( $w['prev_from'], $w['prev_to'] );
		$day  = $this->totals( $this->today->format( 'Y-m-d 00:00:00' ), $w['to'] );

		return [
			'raised'  => [ 'value' => $now['raised'], 'previous' => $prev['raised'] ],
			'gifts'   => [ 'value' => $now['gifts'], 'previous' => $prev['gifts'] ],
			// No gifts, no average: null renders as a dash, never as 0.
			'average' => [
				'value'    => $now['gifts'] ? $now['raised'] / $now['gifts'] : null,
				'previous' => $prev['gifts'] ? $prev['raised'] / $prev['gifts'] : null,
			],
			'donors'  => [ 'value' => $now['donors'], 'previous' => $prev['donors'] ],
			'today'   => $day['raised'],
		];
	}

	/** @return array{raised: float, gifts: int, donors: int} */
	private function totals( string $from, string $to ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount_base), 0) AS raised, COUNT(*) AS gifts, COUNT(DISTINCT " . self::WHO . ") AS donors
			 FROM {$this->table}
			 WHERE " . Donation::counted_sql() . " AND base_currency = %s AND created_at BETWEEN %s AND %s",
			$this->currency,
			$from,
			$to
		), ARRAY_A );
		return [
			'raised' => (float) ( $row['raised'] ?? 0 ),
			'gifts'  => (int) ( $row['gifts'] ?? 0 ),
			'donors' => (int) ( $row['donors'] ?? 0 ),
		];
	}

	/** One bucket per day (7/30/90 days) or per month (12 months), zero-filled. */
	private function series( string $range, array $w ): array {
		global $wpdb;
		$monthly = '12m' === $range;
		$key_sql = $monthly ? "DATE_FORMAT(created_at, '%%Y-%%m')" : 'DATE(created_at)';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$key_sql} AS k,
			        SUM(CASE WHEN campaign_id = 0 THEN amount_base ELSE 0 END) AS open_amount,
			        SUM(CASE WHEN campaign_id <> 0 THEN amount_base ELSE 0 END) AS campaign_amount,
			        COUNT(*) AS gifts
			 FROM {$this->table}
			 WHERE " . Donation::counted_sql() . " AND base_currency = %s AND created_at BETWEEN %s AND %s
			 GROUP BY k",
			$this->currency,
			$w['from']->format( 'Y-m-d 00:00:00' ),
			$w['to']
		), OBJECT_K );

		// Calendar days in UTC: stepping site-time midnights with '+1 day' stuck at
		// 01:00 after a day with no local midnight (DST in Cairo, Beirut,
		// Santiago), and today's bucket dropped off the chart.
		$utc    = new \DateTimeZone( 'UTC' );
		$out    = [];
		$cursor = new DateTimeImmutable( $w['from']->format( 'Y-m-d' ), $utc );
		$last   = new DateTimeImmutable( $this->today->format( 'Y-m-d' ), $utc );
		while ( $cursor <= $last ) {
			$key   = $cursor->format( $monthly ? 'Y-m' : 'Y-m-d' );
			$row   = $rows[ $key ] ?? null;
			$out[] = [
				'key'      => $key,
				'label'    => wp_date( $monthly ? 'M Y' : 'j M', $cursor->getTimestamp(), $utc ),
				'short'    => wp_date( $monthly ? 'M' : 'j', $cursor->getTimestamp(), $utc ),
				'campaign' => $row ? (float) $row->campaign_amount : 0.0,
				'open'     => $row ? (float) $row->open_amount : 0.0,
				'gifts'    => $row ? (int) $row->gifts : 0,
				'partial'  => $monthly && $key === $this->today->format( 'Y-m' ),
			];
			$cursor = $cursor->modify( $monthly ? 'first day of next month' : '+1 day' );
		}
		return $out;
	}

	/** Top campaigns in the range, then the rest folded into one row, then open donations. */
	private function by_campaign( array $w ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT campaign_id, SUM(amount_base) AS amount, COUNT(*) AS gifts
			 FROM {$this->table}
			 WHERE " . Donation::counted_sql() . " AND base_currency = %s AND created_at BETWEEN %s AND %s
			 GROUP BY campaign_id
			 ORDER BY amount DESC",
			$this->currency,
			$w['from']->format( 'Y-m-d 00:00:00' ),
			$w['to']
		), ARRAY_A );

		$filter = [ 'pd_from' => $w['from']->format( 'Y-m-d' ), 'pd_to' => $this->today->format( 'Y-m-d' ), 'pd_status' => 'completed' ];
		$out    = [];
		$open   = null;
		$rest   = [ 'amount' => 0.0, 'gifts' => 0, 'count' => 0 ];

		foreach ( $rows as $row ) {
			$id = (int) $row['campaign_id'];
			if ( Open_Donation::CAMPAIGN_ID === $id ) {
				$open = [
					'label'  => Open_Donation::label(),
					'amount' => (float) $row['amount'],
					'gifts'  => (int) $row['gifts'],
					'url'    => $this->donations_url( $filter + [ 'pd_campaign_filter' => 'general' ] ),
					'kind'   => 'open',
				];
				continue;
			}
			if ( count( $out ) < 6 ) {
				$title = get_the_title( $id );
				$out[] = [
					/* translators: %d: campaign id */
					'label'  => '' !== $title ? html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) : sprintf( __( 'Deleted campaign #%d', 'pesa-donations' ), $id ),
					'amount' => (float) $row['amount'],
					'gifts'  => (int) $row['gifts'],
					'url'    => $this->donations_url( $filter + [ 'pd_campaign_filter' => $id ] ),
					'kind'   => 'campaign',
				];
			} else {
				$rest['amount'] += (float) $row['amount'];
				$rest['gifts']  += (int) $row['gifts'];
				$rest['count']++;
			}
		}
		if ( $rest['count'] ) {
			$out[] = [
				/* translators: %d: number of campaigns */
				'label'  => sprintf( _n( '%d other campaign', '%d other campaigns', $rest['count'], 'pesa-donations' ), $rest['count'] ),
				'amount' => $rest['amount'],
				'gifts'  => $rest['gifts'],
				'url'    => $this->donations_url( $filter ),
				'kind'   => 'other',
			];
		}
		if ( $open ) {
			$out[] = $open;
		}
		return $out;
	}

	private function other_currencies( array $w ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT base_currency AS currency, SUM(amount_base) AS amount, COUNT(*) AS gifts
			 FROM {$this->table}
			 WHERE " . Donation::counted_sql() . " AND base_currency <> %s AND created_at BETWEEN %s AND %s
			 GROUP BY base_currency ORDER BY gifts DESC",
			$this->currency,
			$w['from']->format( 'Y-m-d 00:00:00' ),
			$w['to']
		), ARRAY_A );
		return array_map( static fn( array $r ): array => [
			'currency' => (string) $r['currency'],
			'amount'   => (float) $r['amount'],
			'gifts'    => (int) $r['gifts'],
		], $rows );
	}

	private function donors( array $w ): array {
		global $wpdb;
		$from = $w['from']->format( 'Y-m-d 00:00:00' );

		// New = no completed gift before the range; returning = at least one.
		// Checked per person with an EXISTS on an index (donor_id, the usual
		// case), instead of grouping every completed donation ever made on each
		// load: that grew with the site's history into an on-disk temp table.
		$counted = Donation::counted_sql( 'p' );
		$split   = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS donors,
			        COALESCE(SUM(CASE
			          WHEN r.did IS NOT NULL THEN EXISTS( SELECT 1 FROM {$this->table} p WHERE p.donor_id = r.did AND p.created_at < %s AND {$counted} )
			          WHEN r.em <> '' THEN EXISTS( SELECT 1 FROM {$this->table} p WHERE p.donor_id IS NULL AND p.donor_email = r.em AND p.created_at < %s AND {$counted} )
			          ELSE EXISTS( SELECT 1 FROM {$this->table} p WHERE p.donor_id IS NULL AND p.donor_phone = r.ph AND p.created_at < %s AND {$counted} )
			        END), 0) AS returning_donors
			 FROM ( SELECT " . self::WHO . " AS who, MAX(donor_id) AS did, MAX(donor_email) AS em, MAX(donor_phone) AS ph
			        FROM {$this->table}
			        WHERE " . Donation::counted_sql() . " AND base_currency = %s AND created_at BETWEEN %s AND %s
			        GROUP BY who HAVING who IS NOT NULL ) r",
			$from,
			$from,
			$from,
			$this->currency,
			$from,
			$w['to']
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$split = [
			'new_donors'       => (int) ( $split['donors'] ?? 0 ) - (int) ( $split['returning_donors'] ?? 0 ),
			'returning_donors' => (int) ( $split['returning_donors'] ?? 0 ),
		];

		$top = $wpdb->get_results( $wpdb->prepare(
			"SELECT " . self::WHO . " AS who, MAX(donor_name) AS name, MAX(donor_email) AS email, MAX(donor_phone) AS phone,
			        MAX(is_anonymous) AS anonymous, SUM(amount_base) AS amount, COUNT(*) AS gifts
			 FROM {$this->table}
			 WHERE " . Donation::counted_sql() . " AND base_currency = %s AND created_at BETWEEN %s AND %s
			 GROUP BY who HAVING who IS NOT NULL
			 ORDER BY amount DESC LIMIT 5",
			$this->currency,
			$from,
			$w['to']
		), ARRAY_A );

		return [
			'new'       => (int) ( $split['new_donors'] ?? 0 ),
			'returning' => (int) ( $split['returning_donors'] ?? 0 ),
			'top'       => array_map( fn( array $r ): array => $this->donor_row( $r ), $top ),
		];
	}

	// -------------------------------------------------------------------------
	// "Now": independent of the range picker
	// -------------------------------------------------------------------------

	public function now(): array {
		$campaigns = $this->campaigns();
		return [
			'campaigns' => $campaigns,
			'attention' => $this->attention( $campaigns ),
			'lapsed'    => $this->lapsed(),
		];
	}

	/** Every open campaign: this period's progress, time left and pace. Behind first. */
	private function campaigns(): array {
		// Every open campaign (the list stopped at 100 before), with meta primed
		// by the query and every total read in one batch: this was several
		// queries per campaign on each dashboard load.
		$campaigns = array_values( array_filter(
			array_map( static fn( \WP_Post $post ): Campaign => new Campaign( $post ), $this->open_campaign_posts() ),
			static fn( Campaign $c ): bool => $c->accepts_donations()
		) );
		Campaign_Totals::prime( $campaigns );

		$types = Campaign_Schedule::types();
		$out   = [];
		foreach ( $campaigns as $c ) {
			$out[] = $this->campaign_row( $c, $types );
		}

		$rank = [ 'behind' => 0, 'on_track' => 1, 'upcoming' => 2, 'open' => 3, 'reached' => 4 ];
		usort( $out, static function ( array $a, array $b ) use ( $rank ): int {
			return [ $rank[ $a['pace'] ], $a['days_left'] ?? PHP_INT_MAX ] <=> [ $rank[ $b['pace'] ], $b['days_left'] ?? PHP_INT_MAX ];
		} );
		return $out;
	}

	/** @return \WP_Post[] Published campaigns marked active or reached (fetched once per request). */
	private function open_campaign_posts(): array {
		return $this->open_posts ??= get_posts( [
			'post_type'              => Campaign_CPT::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [ [ 'key' => '_pd_status', 'value' => [ 'active', 'reached' ], 'compare' => 'IN' ] ],
		] );
	}

	private function campaign_row( Campaign $c, array $types ): array {
		$period  = $c->get_current_period();
		$goal    = $c->get_goal_amount();
		$raised  = $c->get_raised_amount();
		$start   = null;
		$end     = null;
		$started = true;

		if ( $period ) {
			$start   = $period->get_start();
			$end     = $period->get_end();
			$started = $period->has_started( $this->today );
		} elseif ( $c->get_end_date() ) {
			$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $c->get_end_date(), wp_timezone() ) ?: null;
			$start = new DateTimeImmutable( get_post_field( 'post_date', $c->get_id() ), wp_timezone() );
			$start = $start->setTime( 0, 0 );
		}

		$days_left = $end ? (int) $this->today->diff( $end )->format( '%r%a' ) + 1 : null;
		$starts_in = ( $start && ! $started ) ? (int) $this->today->diff( $start )->days : null;
		$elapsed   = null;
		if ( $start && $end && $started ) {
			$span    = max( 1, (int) $start->diff( $end )->days + 1 );
			$elapsed = min( 1.0, ( (int) $start->diff( $this->today )->days + 1 ) / $span );
		}
		$progress = $goal > 0 ? $raised / $goal : null;

		// Behind: the share of the goal raised trails the share of the time gone by
		// more than 15 points, once a fifth of the period has passed.
		if ( null !== $progress && $progress >= 1 ) {
			$pace = 'reached';
		} elseif ( ! $started ) {
			$pace = 'upcoming';
		} elseif ( null === $progress || null === $elapsed ) {
			$pace = 'open';
		} elseif ( $elapsed >= 0.2 && $progress < $elapsed - 0.15 ) {
			$pace = 'behind';
		} else {
			$pace = 'on_track';
		}

		$window = $period
			? [ 'pd_from' => $period->get_counts_from()->format( 'Y-m-d' ), 'pd_to' => $period->get_end()->format( 'Y-m-d' ) ]
			: [];

		return [
			'id'           => $c->get_id(),
			'title'        => html_entity_decode( $c->get_title(), ENT_QUOTES, 'UTF-8' ),
			'repeat'       => $c->repeats() ? ( $types[ $c->get_cycle_type() ] ?? '' ) : '',
			'period'       => $period ? $period->get_label() : '',
			'period_note'  => $c->get_period_note(),
			'end_label'    => $end ? Campaign_Period::format_day( $end ) : '',
			'raised'       => $raised,
			'goal'         => $goal > 0 ? $goal : null,
			'progress'     => $progress,
			'elapsed'      => $elapsed,
			'days_left'    => $started ? $days_left : null,
			'starts_in'    => $starts_in,
			'pace'         => $pace,
			'currency'     => $c->get_base_currency(),
			'edit_url'     => (string) get_edit_post_link( $c->get_id(), 'raw' ),
			'donations_url' => $this->donations_url( $window + [ 'pd_campaign_filter' => $c->get_id(), 'pd_status' => 'completed' ] ),
			'is_last_period' => $period && Campaign_Schedule::CUSTOM === $c->get_cycle_type()
				&& $period->get_end()->format( 'Y-m-d' ) === max( array_column( $c->get_custom_periods(), 'end' ) ),
		];
	}

	private function attention( array $campaigns ): array {
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$items = [];

		// Checkouts that never confirmed: older than an hour (PesaPal usually answers
		// in minutes) and younger than two weeks (older ones are abandoned carts).
		$stale = $this->payments(
			"status = 'pending' AND created_at BETWEEN %s AND %s",
			[ $now->modify( '-14 days' )->format( 'Y-m-d H:i:s' ), $now->modify( '-1 hour' )->format( 'Y-m-d H:i:s' ) ]
		);
		if ( $stale['total'] ) {
			$items[] = [
				'kind'     => 'pending',
				'severity' => 'warning',
				/* translators: %d: number of payments */
				'title'    => sprintf( _n( '%d payment not confirmed', '%d payments not confirmed', $stale['total'], 'pesa-donations' ), $stale['total'] ),
				'detail'   => __( 'Pending for over an hour', 'pesa-donations' ),
				'url'      => $this->donations_url( [ 'pd_status' => 'pending' ] ),
				'rows'     => $stale['rows'],
			];
		}

		$failed = $this->payments(
			"status IN ('failed', 'reversed') AND created_at >= %s",
			[ $now->modify( '-7 days' )->format( 'Y-m-d H:i:s' ) ]
		);
		if ( $failed['total'] ) {
			$items[] = [
				'kind'     => 'failed',
				'severity' => 'critical',
				/* translators: %d: number of payments */
				'title'    => sprintf( _n( '%d payment failed or reversed', '%d payments failed or reversed', $failed['total'], 'pesa-donations' ), $failed['total'] ),
				'detail'   => __( 'Last 7 days', 'pesa-donations' ),
				'url'      => $this->donations_url( [ 'pd_status' => 'failed' ] ),
				'rows'     => $failed['rows'],
			];
		}

		foreach ( $campaigns as $c ) {
			if ( $c['is_last_period'] ) {
				$items[] = [
					'kind'     => 'no_next_period',
					'severity' => ( null !== $c['days_left'] && $c['days_left'] <= 21 ) ? 'warning' : 'info',
					/* translators: %s: campaign name */
					'title'    => sprintf( __( '%s has no next period', 'pesa-donations' ), $c['title'] ),
					/* translators: %s: date */
					'detail'   => sprintf( __( 'Ends after %s', 'pesa-donations' ), $c['end_label'] ),
					'url'      => $c['edit_url'],
					'rows'     => [],
				];
			} elseif ( '' === $c['repeat'] && null !== $c['days_left'] && $c['days_left'] <= 14 ) {
				$items[] = [
					'kind'     => 'ending',
					'severity' => 'info',
					/* translators: %s: campaign name */
					'title'    => sprintf( __( '%s ends soon', 'pesa-donations' ), $c['title'] ),
					/* translators: %d: days */
					'detail'   => sprintf( _n( '%d day left', '%d days left', $c['days_left'], 'pesa-donations' ), $c['days_left'] ),
					'url'      => $c['edit_url'],
					'rows'     => [],
				];
			}

			$stats = get_post_meta( $c['id'], '_pd_cycle_reminder_stats', true );
			if ( is_array( $stats ) && ! empty( $stats['failed'] ) ) {
				$items[] = [
					'kind'     => 'reminders',
					'severity' => 'warning',
					/* translators: 1: number of emails, 2: campaign name */
					'title'    => sprintf( _n( '%1$d reminder email failed for %2$s', '%1$d reminder emails failed for %2$s', (int) $stats['failed'], 'pesa-donations' ), (int) $stats['failed'], $c['title'] ),
					'detail'   => (string) ( $stats['label'] ?? '' ),
					'url'      => $c['edit_url'],
					'rows'     => [],
				];
			}
		}

		$weight = [ 'critical' => 0, 'warning' => 1, 'info' => 2 ];
		usort( $items, static fn( array $a, array $b ): int => $weight[ $a['severity'] ] <=> $weight[ $b['severity'] ] );
		return $items;
	}

	/**
	 * Donors who gave to a repeating campaign last period and not yet this one.
	 * One query for all campaigns (it was one per campaign, capped at 100), and
	 * the total counts people, not person-campaign pairs.
	 */
	private function lapsed(): array {
		global $wpdb;

		$parts = [];
		$args  = [];
		$meta  = [];
		foreach ( $this->open_campaign_posts() as $post ) {
			$c = new Campaign( $post );
			if ( ! $c->repeats() || ! $c->accepts_donations() ) {
				continue;
			}
			$current = $c->get_current_period();
			$prev    = $current ? $c->get_schedule()->previous( $current ) : null;
			if ( ! $prev ) {
				continue;
			}
			$id          = $c->get_id();
			$meta[ $id ] = [ 'campaign' => html_entity_decode( $c->get_title(), ENT_QUOTES, 'UTF-8' ), 'period' => $prev->get_label() ];
			$parts[]     = "SELECT %d AS cid, p.who, MAX(p.donor_name) AS name, MAX(p.donor_email) AS email, MAX(p.donor_phone) AS phone,
			                       MAX(p.is_anonymous) AS anonymous, SUM(p.amount_base) AS amount, COUNT(*) AS gifts
			                FROM ( SELECT " . self::WHO . " AS who, donor_name, donor_email, donor_phone, is_anonymous, amount_base
			                       FROM {$this->table}
			                       WHERE campaign_id = %d AND " . Donation::counted_sql() . " AND base_currency = %s AND created_at BETWEEN %s AND %s ) p
			                WHERE p.who IS NOT NULL AND p.who NOT IN (
			                       SELECT " . self::WHO . " FROM {$this->table}
			                       WHERE campaign_id = %d AND " . Donation::counted_sql() . " AND created_at BETWEEN %s AND %s
			                         AND " . self::WHO . " IS NOT NULL )
			                GROUP BY p.who";
			array_push( $args, $id, $id, $this->currency, $prev->window_start_sql(), $prev->window_end_sql(), $id, $current->window_start_sql(), $current->window_end_sql() );
		}
		if ( ! $parts ) {
			return [ 'total' => 0, 'rows' => [] ];
		}

		$found = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $parts ), $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = [];
		foreach ( $found as $r ) {
			$rows[] = $this->donor_row( $r ) + $meta[ (int) $r['cid'] ];
		}
		usort( $rows, static fn( array $a, array $b ): int => $b['amount'] <=> $a['amount'] );
		return [
			'total' => count( array_unique( array_column( $found, 'who' ) ) ),
			'rows'  => array_slice( $rows, 0, 6 ),
		];
	}

	// -------------------------------------------------------------------------
	// Shaping
	// -------------------------------------------------------------------------

	/** Allow-listed donor fields only. */
	private function donor_row( array $r ): array {
		$email = (string) ( $r['email'] ?? '' );
		if ( str_ends_with( $email, '@phone.pd' ) ) {
			$email = '';
		}
		$name = trim( (string) ( $r['name'] ?? '' ) );
		return [
			'name'      => '' !== $name ? $name : ( $email ?: (string) ( $r['phone'] ?? '' ) ),
			'email'     => $email,
			'anonymous' => ! empty( $r['anonymous'] ),
			'amount'    => (float) $r['amount'],
			'gifts'     => (int) $r['gifts'],
		];
	}

	/**
	 * A count and the five latest rows for a payment condition. Two plain queries:
	 * a window function would need MySQL 8, and client hosts still run 5.7.
	 *
	 * @param string $where Trusted SQL with %s placeholders for $args.
	 * @return array{total: int, rows: array}
	 */
	private function payments( string $where, array $args ): array {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $total ) {
			return [ 'total' => 0, 'rows' => [] ];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, merchant_reference, amount, currency, donor_name, donor_email, created_at
			 FROM {$this->table} WHERE {$where} ORDER BY created_at DESC LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args
		), ARRAY_A );
		return [ 'total' => $total, 'rows' => array_map( fn( array $r ): array => $this->payment_row( $r ), $rows ) ];
	}

	private function payment_row( array $r ): array {
		return [
			'reference' => (string) $r['merchant_reference'],
			'amount'    => (float) $r['amount'],
			'currency'  => (string) $r['currency'],
			'donor'     => trim( (string) $r['donor_name'] ) ?: (string) $r['donor_email'],
			'when'      => mysql2date( 'j M, H:i', (string) $r['created_at'] ),
			'url'       => add_query_arg( [ 'page' => 'pd-donation-edit', 'id' => (int) $r['id'] ], admin_url( 'admin.php' ) ),
		];
	}

	private function donations_url( array $args ): string {
		return add_query_arg( array_merge( [ 'page' => 'pd-donations' ], $args ), admin_url( 'admin.php' ) );
	}
}
