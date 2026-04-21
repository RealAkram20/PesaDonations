<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

class Donation {

	private array $data;

	private function __construct( array $data ) {
		$this->data = $data;
	}

	public static function get( int $id ): ?self {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donations WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ? new self( $row ) : null;
	}

	public static function get_by_uuid( string $uuid ): ?self {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donations WHERE uuid = %s", $uuid ),
			ARRAY_A
		);
		return $row ? new self( $row ) : null;
	}

	public static function get_by_merchant_ref( string $ref ): ?self {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donations WHERE merchant_reference = %s", $ref ),
			ARRAY_A
		);
		return $row ? new self( $row ) : null;
	}

	public static function create( array $data ): int|false {
		global $wpdb;

		$now    = current_time( 'mysql' );
		$insert = array_merge( [
			'uuid'               => wp_generate_uuid4(),
			'status'             => 'pending',
			'is_recurring'       => 0,
			'is_anonymous'       => 0,
			'fx_rate'            => 1.0,
			'created_at'         => $now,
			'updated_at'         => $now,
		], $data );

		$result = $wpdb->insert( $wpdb->prefix . 'pd_donations', $insert );
		return $result ? $wpdb->insert_id : false;
	}

	public function update( array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$result = $wpdb->update(
			$wpdb->prefix . 'pd_donations',
			$data,
			[ 'id' => $this->get_id() ]
		);
		if ( false !== $result ) {
			$this->data = array_merge( $this->data, $data );
			return true;
		}
		return false;
	}

	public function get_id(): int        { return (int) $this->data['id']; }
	public function get_uuid(): string   { return (string) $this->data['uuid']; }
	public function get_campaign_id(): int { return (int) $this->data['campaign_id']; }
	public function get_amount(): float  { return (float) $this->data['amount']; }
	public function get_currency(): string { return (string) $this->data['currency']; }
	public function get_status(): string { return (string) $this->data['status']; }
	public function get_gateway(): string { return (string) $this->data['gateway']; }
	public function get_merchant_reference(): string { return (string) $this->data['merchant_reference']; }
	public function get_donor_email(): string { return (string) $this->data['donor_email']; }
	public function get_donor_name(): string { return (string) $this->data['donor_name']; }
}
