<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Works out a campaign's fundraising periods from its repeat settings.
 *
 * Fixed repeats step from the first period's start date and never drift: a
 * monthly campaign starting 31 Jan runs 31 Jan–27 Feb, 28 Feb–30 Mar, 31 Mar–29 Apr
 * (the month-end clamp ported from D:\OS\tools\expenses.py `_nth`). Custom
 * periods are typed in by the admin, e.g. school terms, and may have gaps:
 * a donation made in a gap counts toward the next period.
 *
 * The first period also counts donations made after the repeat was switched on
 * but before that period started ($since), and nothing earlier, so switching an
 * old campaign to monthly does not pour its whole history into the first month.
 *
 * Every date here is a calendar day held as midnight UTC; only "today" is read
 * in the site's timezone. Held in the site timezone, a day with no local
 * midnight (daylight saving starting at 00:00, as in Cairo or Beirut) became
 * 01:00, every later period copied that time, and each period's first day
 * still belonged to the one before: the reset and the reminders came a day late.
 */
class Campaign_Schedule {

	public const ONCE      = 'once';
	public const WEEKLY    = 'weekly';
	public const MONTHLY   = 'monthly';
	public const QUARTERLY = 'quarterly';
	public const BIANNUAL  = 'biannual';
	public const YEARLY    = 'yearly';
	public const CUSTOM    = 'custom';

	/** Months per step for the month-based repeats. */
	private const MONTH_STEPS = [
		self::MONTHLY   => 1,
		self::QUARTERLY => 3,
		self::BIANNUAL  => 6,
		self::YEARLY    => 12,
	];

	private DateTimeZone $tz;
	private DateTimeZone $utc;
	private ?DateTimeImmutable $anchor;
	private ?DateTimeImmutable $since;
	/** When the repeat was switched on, to the second (site time), for period one's window. */
	private string $since_at = '';

	/** @var array<int, array{id: string, label: string, start: DateTimeImmutable, end: DateTimeImmutable}> */
	private array $custom = [];

	/**
	 * @param string $type   One of the constants above; anything else is treated as ONCE.
	 * @param string $anchor First period's start (Y-m-d) for fixed repeats.
	 * @param array  $custom Rows of {id, label, start, end} for CUSTOM.
	 * @param string $since  When the repeat was switched on (Y-m-d or MySQL datetime).
	 */
	public function __construct(
		private string $type,
		string $anchor = '',
		array $custom = [],
		string $since = '',
		?DateTimeZone $tz = null
	) {
		$this->tz     = $tz ?? wp_timezone();
		$this->utc    = new DateTimeZone( 'UTC' );
		$this->type   = array_key_exists( $type, self::types() ) ? $type : self::ONCE;
		$this->anchor = $this->parse_day( $anchor );
		$this->since  = $this->parse_day( substr( $since, 0, 10 ) );
		if ( $this->since && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since ) ) {
			$this->since_at = $since;
		}

		foreach ( $custom as $row ) {
			$start = $this->parse_day( (string) ( $row['start'] ?? '' ) );
			$end   = $this->parse_day( (string) ( $row['end'] ?? '' ) );
			if ( ! $start || ! $end || $end < $start ) {
				continue;
			}
			$this->custom[] = [
				'id'    => (string) ( $row['id'] ?? $start->format( 'Y-m-d' ) ),
				'label' => trim( (string) ( $row['label'] ?? '' ) ),
				'start' => $start,
				'end'   => $end,
			];
		}
		usort( $this->custom, static fn( array $a, array $b ): int => $a['start'] <=> $b['start'] );

		if ( self::CUSTOM !== $this->type && ! $this->anchor ) {
			// A fixed repeat with no start date starts from when it was switched on.
			$this->anchor = $this->since;
		}
	}

	/** Repeat options in the order the admin sees them. */
	public static function types(): array {
		return [
			self::ONCE      => __( 'One time (never resets)', 'pesa-donations' ),
			self::WEEKLY    => __( 'Every week', 'pesa-donations' ),
			self::MONTHLY   => __( 'Every month', 'pesa-donations' ),
			self::QUARTERLY => __( 'Every 3 months', 'pesa-donations' ),
			self::BIANNUAL  => __( 'Every 6 months', 'pesa-donations' ),
			self::YEARLY    => __( 'Every year', 'pesa-donations' ),
			self::CUSTOM    => __( 'Custom periods (e.g. school terms)', 'pesa-donations' ),
		];
	}

	public function get_type(): string {
		return $this->type;
	}

	public function repeats(): bool {
		if ( self::CUSTOM === $this->type ) {
			return ! empty( $this->custom );
		}
		return self::ONCE !== $this->type && null !== $this->anchor;
	}

	/** Today's date in the site's timezone, as a calendar day (midnight UTC). */
	public function today(): DateTimeImmutable {
		return $this->day_of( new DateTimeImmutable( 'now', $this->tz ) );
	}

	/** The calendar day of any moment, as it reads on its own clock. */
	private function day_of( DateTimeImmutable $moment ): DateTimeImmutable {
		return new DateTimeImmutable( $moment->format( 'Y-m-d' ), $this->utc );
	}

	/**
	 * The period donations count toward today: the one running now, or the next
	 * one when today falls in a gap or before the first. Null for a one-time
	 * campaign, or when every custom period is over.
	 */
	public function current(): ?Campaign_Period {
		return $this->period_for( $this->today() );
	}

	/**
	 * The period a donation made on $day counts toward. Null before the first
	 * period's counting began: a gift from before repeating was switched on was
	 * labelled with period one, while the totals rightly left it out.
	 */
	public function period_for( DateTimeImmutable $day ): ?Campaign_Period {
		$day   = $this->day_of( $day );
		$index = $this->index_for( $day );
		if ( null === $index ) {
			return null;
		}
		$period = $this->period_at( $index );
		if ( 0 === $index && $day < $this->day_of( $period->get_counts_from() ) ) {
			return null;
		}
		return $period;
	}

	/** The period just before $period, or null if it is the first. */
	public function previous( Campaign_Period $period ): ?Campaign_Period {
		$index = $this->index_for( $period->get_end() );
		return ( null === $index || 0 === $index ) ? null : $this->period_at( $index - 1 );
	}

	/**
	 * Up to $limit periods ending with the current one, oldest first. When every
	 * custom period is over, the last ones.
	 *
	 * @return Campaign_Period[]
	 */
	public function recent( int $limit = 12 ): array {
		if ( ! $this->repeats() ) {
			return [];
		}
		$last = $this->index_for( $this->today() );
		if ( null === $last ) {
			$last = count( $this->custom ) - 1;
		}
		$out = [];
		for ( $i = max( 0, $last - $limit + 1 ); $i <= $last; $i++ ) {
			$out[] = $this->period_at( $i );
		}
		return $out;
	}

	public function first(): ?Campaign_Period {
		return $this->repeats() ? $this->period_at( 0 ) : null;
	}

	// -------------------------------------------------------------------------
	// Index arithmetic
	// -------------------------------------------------------------------------

	private function index_for( DateTimeImmutable $day ): ?int {
		if ( ! $this->repeats() ) {
			return null;
		}

		if ( self::CUSTOM === $this->type ) {
			foreach ( $this->custom as $i => $row ) {
				if ( $row['end'] >= $day ) {
					return $i;
				}
			}
			return null;
		}

		$day = $this->day_of( $day );
		if ( $day < $this->anchor ) {
			return 0;
		}

		if ( self::WEEKLY === $this->type ) {
			return intdiv( (int) $this->anchor->diff( $day )->days, 7 );
		}

		$step   = self::MONTH_STEPS[ $this->type ];
		$months = ( (int) $day->format( 'Y' ) - (int) $this->anchor->format( 'Y' ) ) * 12
			+ ( (int) $day->format( 'n' ) - (int) $this->anchor->format( 'n' ) );
		$n = intdiv( max( 0, $months ), $step );

		// The clamp can put a start a day or two either side of the naive guess.
		while ( $n > 0 && $this->start_of( $n ) > $day ) {
			$n--;
		}
		while ( $this->start_of( $n + 1 ) <= $day ) {
			$n++;
		}
		return $n;
	}

	private function period_at( int $n ): Campaign_Period {
		if ( self::CUSTOM === $this->type ) {
			$row         = $this->custom[ $n ];
			$counts_from = 0 === $n
				? $this->earliest( $row['start'] )
				: $this->custom[ $n - 1 ]['end']->modify( '+1 day' );
			$label       = '' !== $row['label']
				? $row['label']
				: Campaign_Period::format_range( $row['start'], $row['end'] );
			return new Campaign_Period( $row['id'], $label, $row['start'], $row['end'], $counts_from, 0 === $n ? $this->earliest_at( $row['start'] ) : '' );
		}

		$start = $this->start_of( $n );
		$end   = $this->start_of( $n + 1 )->modify( '-1 day' );
		return new Campaign_Period(
			$start->format( 'Y-m-d' ),
			$this->fixed_label( $start, $end ),
			$start,
			$end,
			0 === $n ? $this->earliest( $start ) : $start,
			0 === $n ? $this->earliest_at( $start ) : ''
		);
	}

	/** Start of the n-th fixed period: the anchor's day of month, clamped, never drifting. */
	private function start_of( int $n ): DateTimeImmutable {
		if ( self::WEEKLY === $this->type ) {
			return $this->anchor->modify( '+' . ( 7 * $n ) . ' days' );
		}
		$months = $n * self::MONTH_STEPS[ $this->type ];
		$y      = (int) $this->anchor->format( 'Y' );
		$m      = (int) $this->anchor->format( 'n' ) - 1 + $months;
		$year   = $y + intdiv( $m, 12 );
		$month  = $m % 12 + 1;
		$day    = min( (int) $this->anchor->format( 'j' ), self::days_in_month( $month, $year ) );
		return $this->anchor->setDate( $year, $month, $day );
	}

	/** Without the calendar extension, which some hosts leave out. */
	private static function days_in_month( int $month, int $year ): int {
		return (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) ) )->format( 't' );
	}

	private function earliest( DateTimeImmutable $start ): DateTimeImmutable {
		return ( $this->since && $this->since < $start ) ? $this->since : $start;
	}

	/** Period one's first counted moment when it begins part way through a day: when repeating was switched on. */
	private function earliest_at( DateTimeImmutable $start ): string {
		return ( '' !== $this->since_at && $this->since <= $start ) ? $this->since_at : '';
	}

	private function fixed_label( DateTimeImmutable $start, DateTimeImmutable $end ): string {
		if ( self::WEEKLY === $this->type ) {
			/* translators: %s: first day of the week, e.g. 6 Oct 2026 */
			return sprintf( __( 'Week of %s', 'pesa-donations' ), Campaign_Period::format_day( $start ) );
		}
		if ( self::MONTHLY === $this->type && '1' === $start->format( 'j' ) ) {
			// In UTC, the day's own zone: in the site's zone west of UTC the 1st read as the month before.
			return wp_date( 'F Y', $start->getTimestamp(), $this->utc );
		}
		if ( self::YEARLY === $this->type && '01-01' === $start->format( 'm-d' ) ) {
			return $start->format( 'Y' );
		}
		return Campaign_Period::format_range( $start, $end );
	}

	private function parse_day( string $value ): ?DateTimeImmutable {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $this->utc );
		return ( $day && $day->format( 'Y-m-d' ) === $value ) ? $day : null;
	}
}
