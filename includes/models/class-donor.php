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
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}pd_donors
			 SET total_donated_base = total_donated_base + %f,
			     donation_count     = donation_count + 1,
			     last_donation_at   = %s,
			     first_donation_at  = COALESCE(first_donation_at, %s),
			     updated_at         = %s
			 WHERE id = %d",
			$amount_base,
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			$this->get_id()
		) );
	}

	public function get_id(): int      { return (int) $this->data['id']; }
	public function get_email(): string { return (string) $this->data['email']; }
	public function get_name(): string  {
		return trim( (string) $this->data['first_name'] . ' ' . (string) $this->data['last_name'] );
	}
}
