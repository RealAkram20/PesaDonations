<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

/**
 * Each campaign's current-period totals (raised, donors), computed for many
 * campaigns at once and cached together.
 *
 * One transient holds every campaign's figures for five minutes, so a page of
 * forty campaign cards costs one cache read, not two per card. Missing or
 * stale entries (a new period began) are filled in at most two queries: one
 * GROUP BY for one-time campaigns and one UNION ALL of the repeating
 * campaigns' windows, each branch on the (campaign_id, created_at) index.
 *
 * Only money in the campaign's own currency counts toward its progress, and
 * only money that is real (Donation::counted_sql()). Any status change calls
 * flush(), which drops the whole entry: completions are rare, recomputing is cheap.
 */
class Campaign_Totals {

	private const CACHE = 'pd_campaign_totals';
	private const TTL   = 5 * MINUTE_IN_SECONDS;

	/** @var array<int, array{period: string, raised: float, donors: int}>|null */
	private static ?array $memo = null;

	/** @return array{raised: float, donors: int} */
	public static function get( Campaign $campaign ): array {
		self::prime( [ $campaign ] );
		$row = self::$memo[ $campaign->get_id() ] ?? null;
		return [ 'raised' => (float) ( $row['raised'] ?? 0 ), 'donors' => (int) ( $row['donors'] ?? 0 ) ];
	}

	/** @param Campaign[] $campaigns */
	public static function prime( array $campaigns ): void {
		self::load();
		$need = [];
		foreach ( $campaigns as $c ) {
			$row = self::$memo[ $c->get_id() ] ?? null;
			if ( ! $row || $row['period'] !== self::period_key( $c ) ) {
				$need[ $c->get_id() ] = $c;
			}
		}
		if ( ! $need ) {
			return;
		}
		foreach ( self::compute( $need ) as $id => $row ) {
			self::$memo[ $id ] = $row;
		}
		set_transient( self::CACHE, self::$memo, self::TTL );
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
		self::$memo = null;
	}

	private static function load(): void {
		if ( null === self::$memo ) {
			$cached     = get_transient( self::CACHE );
			self::$memo = is_array( $cached ) ? $cached : [];
		}
	}

	private static function period_key( Campaign $c ): string {
		$period = $c->get_current_period();
		return $period ? $period->get_key() : '';
	}

	/**
	 * @param array<int, Campaign> $campaigns
	 * @return array<int, array{period: string, raised: float, donors: int}>
	 */
	private static function compute( array $campaigns ): array {
		global $wpdb;
		$table   = $wpdb->prefix . 'pd_donations';
		$counted = Donation::counted_sql();
		$out     = [];
		$once    = [];
		$windows = [];
		$args    = [];

		foreach ( $campaigns as $id => $c ) {
			$out[ $id ] = [ 'period' => self::period_key( $c ), 'raised' => 0.0, 'donors' => 0 ];
			$period     = $c->get_current_period();
			if ( $period ) {
				$windows[] = "SELECT %d AS cid, COALESCE(SUM(amount_base), 0) AS raised, COUNT(DISTINCT " . Donation::WHO_SQL . ") AS donors
					FROM {$table} WHERE campaign_id = %d AND currency = %s AND {$counted} AND created_at BETWEEN %s AND %s";
				array_push( $args, $id, $id, $c->get_base_currency(), $period->window_start_sql(), $period->window_end_sql() );
			} else {
				$once[ $id ] = $c->get_base_currency();
			}
		}

		$rows = [];
		if ( $once ) {
			$in   = implode( ',', array_fill( 0, count( $once ), '%d' ) );
			$rows = array_merge( $rows, $wpdb->get_results( $wpdb->prepare(
				"SELECT campaign_id AS cid, currency, COALESCE(SUM(amount_base), 0) AS raised, COUNT(DISTINCT " . Donation::WHO_SQL . ") AS donors
				 FROM {$table} WHERE campaign_id IN ({$in}) AND {$counted}
				 GROUP BY campaign_id, currency",
				array_keys( $once )
			), ARRAY_A ) );
		}
		if ( $windows ) {
			$rows = array_merge( $rows, $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $windows ), $args ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		foreach ( $rows as $r ) {
			$id = (int) $r['cid'];
			// One-time campaigns come back per currency: keep the campaign's own.
			if ( isset( $r['currency'] ) && $r['currency'] !== ( $once[ $id ] ?? '' ) ) {
				continue;
			}
			$out[ $id ]['raised'] = (float) $r['raised'];
			$out[ $id ]['donors'] = (int) $r['donors'];
		}
		return $out;
	}
}
