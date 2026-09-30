<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

class Donor {

	private array $data;

	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * The donor with this email, created if new. A returning donor's empty
	 * fields (name, phone, country) are filled from this gift; what is already
	 * on record is never overwritten from a public form.
	 */
	public static function get_or_create( string $email, array $extra = [] ): self {
		global $wpdb;

		$table = $wpdb->prefix . 'pd_donors';
		$email = strtolower( sanitize_email( $email ) );
		$find  = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ), ARRAY_A );
		$row   = $find();

		if ( ! $row ) {
			$now = current_time( 'mysql' );
			$wpdb->insert( $table, array_merge( [
				'email'      => $email,
				'created_at' => $now,
				'updated_at' => $now,
			], $extra ) );
			// Two first gifts from one address at once: the unique key refuses
			// the second insert, and that request uses the first one's row.
			// Before, it went on with donor id 0.
			$row = $find();
			if ( ! $row ) {
				return new self( array_merge( [ 'id' => 0, 'email' => $email, 'first_name' => '', 'last_name' => '' ], $extra ) );
			}
			return new self( $row );
		}

		$fill = [];
		foreach ( [ 'first_name', 'last_name', 'phone', 'country' ] as $field ) {
			if ( '' === (string) ( $row[ $field ] ?? '' ) && '' !== (string) ( $extra[ $field ] ?? '' ) ) {
				$fill[ $field ] = $extra[ $field ];
			}
		}
		if ( $fill ) {
			$wpdb->update( $table, $fill + [ 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row['id'] ] );
			$row = array_merge( $row, $fill );
		}
		return new self( $row );
	}

	public function record_donation( float $amount_base ): void {
		self::recalculate( $this->get_id() );
	}

	/**
	 * Rebuild a donor's aggregate stats (count, total, first/last) from the
	 * donations table. Only counts rows with status='completed'. Call this
	 * after any donation status change, creation, or deletion.
	 */
	public static function recalculate( int $donor_id ): void {
		if ( $donor_id <= 0 ) {
			return;
		}
		global $wpdb;
		$donations = $wpdb->prefix . 'pd_donations';
		$donors    = $wpdb->prefix . 'pd_donors';

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as cnt,
				        COALESCE(SUM(amount_base), 0) as total,
				        MIN(created_at) as first_at,
				        MAX(created_at) as last_at
				 FROM {$donations}
				 WHERE donor_id = %d AND " . Donation::counted_sql() . "",
				$donor_id
			),
			ARRAY_A
		);

		$wpdb->update(
			$donors,
			[
				'donation_count'     => (int) ( $stats['cnt']      ?? 0 ),
				'total_donated_base' => (float) ( $stats['total']  ?? 0 ),
				'first_donation_at'  => $stats['first_at'] ?: null,
				'last_donation_at'   => $stats['last_at']  ?: null,
				'updated_at'         => current_time( 'mysql' ),
			],
			[ 'id' => $donor_id ]
		);
	}

	/**
	 * Recalculate aggregates for every donor in the system. Useful for
	 * repairing historical data after a migration or schema change.
	 */
	public static function recalculate_all(): int {
		global $wpdb;
		$donors    = $wpdb->prefix . 'pd_donors';
		$donations = $wpdb->prefix . 'pd_donations';
		// One statement for every donor (it was two queries per donor, which
		// timed out on a large list). Same rule as recalculate().
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$donors} r
			 LEFT JOIN (
				SELECT donor_id, COUNT(*) AS cnt, SUM(amount_base) AS total, MIN(created_at) AS first_at, MAX(created_at) AS last_at
				FROM {$donations}
				WHERE donor_id IS NOT NULL AND " . Donation::counted_sql() . "
				GROUP BY donor_id
			 ) s ON s.donor_id = r.id
			 SET r.donation_count     = COALESCE(s.cnt, 0),
			     r.total_donated_base = COALESCE(s.total, 0),
			     r.first_donation_at  = s.first_at,
			     r.last_donation_at   = s.last_at,
			     r.updated_at         = %s",
			current_time( 'mysql' )
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$donors}" );
	}

	/**
	 * Completed totals per currency for these donors: [donor_id => [currency => amount]].
	 * The stored total_donated_base adds currencies together, so screens show these.
	 *
	 * @param int[] $donor_ids
	 * @return array<int, array<string, float>>
	 */
	public static function totals_by_currency( array $donor_ids ): array {
		$donor_ids = array_values( array_filter( array_map( 'intval', $donor_ids ) ) );
		if ( ! $donor_ids ) {
			return [];
		}
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $donor_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT donor_id, currency, SUM(amount) AS total
			 FROM {$wpdb->prefix}pd_donations
			 WHERE donor_id IN ({$in}) AND " . Donation::counted_sql() . '
			 GROUP BY donor_id, currency',
			$donor_ids
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r['donor_id'] ][ (string) $r['currency'] ] = (float) $r['total'];
		}
		return $out;
	}

	/** A donor who gave by phone only is stored as "{phone}@phone.pd": that is not an address to show or write to. */
	public static function is_placeholder_email( string $email ): bool {
		return str_ends_with( strtolower( $email ), '@phone.pd' );
	}

	public function get_id(): int      { return (int) $this->data['id']; }
	public function get_email(): string { return (string) $this->data['email']; }
	public function get_name(): string  {
		return trim( (string) $this->data['first_name'] . ' ' . (string) $this->data['last_name'] );
	}
}
