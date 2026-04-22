<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

class Donor {

	private array $data;

	private function __construct( array $data ) {
		$this->data = $data;
	}

	public static function get_or_create( string $email, array $extra = [] ): self {
		global $wpdb;

		$email = strtolower( sanitize_email( $email ) );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donors WHERE email = %s", $email ),
			ARRAY_A
		);

		if ( $row ) {
			return new self( $row );
		}

		$now  = current_time( 'mysql' );
		$data = array_merge( [
			'email'      => $email,
			'created_at' => $now,
			'updated_at' => $now,
		], $extra );

		$wpdb->insert( $wpdb->prefix . 'pd_donors', $data );
		$data['id'] = $wpdb->insert_id;
		return new self( $data );
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
				 WHERE donor_id = %d AND status = 'completed'",
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
		$ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}pd_donors" );
		foreach ( $ids as $id ) {
			self::recalculate( (int) $id );
		}
		return count( $ids );
	}

	public function get_id(): int      { return (int) $this->data['id']; }
	public function get_email(): string { return (string) $this->data['email']; }
	public function get_name(): string  {
		return trim( (string) $this->data['first_name'] . ' ' . (string) $this->data['last_name'] );
	}
}
