<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

use DateTimeImmutable;

/**
 * One fundraising period of a repeating campaign: a week, a month, a school term.
 *
 * Dates are site-local calendar days, inclusive at both ends. A donation counts
 * toward the period when it was made between counts_from() and the end of the
 * last day, so money given in the holiday before a term counts toward that term.
 */
class Campaign_Period {

	public function __construct(
		private string $key,
		private string $label,
		private DateTimeImmutable $start,
		private DateTimeImmutable $end,
		private DateTimeImmutable $counts_from,
		private string $counts_from_at = ''
	) {}

	/** Stable id: the start date for fixed repeats, the row id for custom periods. */
	public function get_key(): string {
		return $this->key;
	}

	public function get_label(): string {
		return $this->label;
	}

	public function get_start(): DateTimeImmutable {
		return $this->start;
	}

	public function get_end(): DateTimeImmutable {
		return $this->end;
	}

	public function get_counts_from(): DateTimeImmutable {
		return $this->counts_from;
	}

	/** First moment a donation counts, as a MySQL datetime in site time. */
	public function window_start_sql(): string {
		// Period one starts when repeating was switched on, to the second.
		return '' !== $this->counts_from_at ? $this->counts_from_at : $this->counts_from->format( 'Y-m-d 00:00:00' );
	}

	/** Last moment a donation counts, as a MySQL datetime in site time. */
	public function window_end_sql(): string {
		return $this->end->format( 'Y-m-d 23:59:59' );
	}

	public function has_started( DateTimeImmutable $today ): bool {
		return $this->start->format( 'Y-m-d' ) <= $today->format( 'Y-m-d' );
	}

	/** The day after the last day: when the next period's counting begins. */
	public function get_reset_date(): DateTimeImmutable {
		return $this->end->modify( '+1 day' );
	}

	/** "1 Sep – 5 Dec 2026", or "1 Nov 2026 – 31 Jan 2027" across years. */
	public function get_date_range(): string {
		return self::format_range( $this->start, $this->end );
	}

	public static function format_range( DateTimeImmutable $start, DateTimeImmutable $end ): string {
		$same_year = $start->format( 'Y' ) === $end->format( 'Y' );
		return sprintf(
			'%s – %s',
			self::format_day( $start, ! $same_year ),
			self::format_day( $end, true )
		);
	}

	public static function format_day( DateTimeImmutable $day, bool $with_year = true ): string {
		return wp_date( $with_year ? 'j M Y' : 'j M', $day->getTimestamp(), $day->getTimezone() );
	}
}
