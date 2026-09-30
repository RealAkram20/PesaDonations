<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

class Donation {

	/**
	 * The only status moves allowed, from => [to]. A completed gift only ever
	 * becomes reversed, and reversed is final; nothing goes back to pending.
	 * A failed or cancelled PesaPal order can still be paid on a retry.
	 */
	public const MOVES = [
		'pending'   => [ 'completed', 'failed', 'cancelled' ],
		'failed'    => [ 'completed' ],
		'cancelled' => [ 'completed' ],
		'completed' => [ 'reversed' ],
		'reversed'  => [],
	];

	/**
	 * Who gave, as one value: the donor record, else the email, else the phone.
	 * Phone-only gifts store an empty donor_email, so counting emails missed
	 * them. CONCAT (not CAST) keeps the column's collation on migrated tables.
	 */
	public const WHO_SQL = "COALESCE(CONCAT('#', donor_id), NULLIF(donor_email, ''), NULLIF(donor_phone, ''))";

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
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donations WHERE merchant_reference = %s ORDER BY id LIMIT 1", $ref ),
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

	/** Non-status fields only; status goes through transition(). */
	public function update( array $data ): bool {
		global $wpdb;
		unset( $data['status'], $data['completed_at'] );
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

	/**
	 * The one writer of a donation's status. Moves the row only if its current
	 * status allows it (see MOVES), atomically: of two callers racing (the
	 * donor's callback and PesaPal's IPN arrive together), exactly one wins.
	 * Side effects run only for the winner: donor totals, campaign totals and
	 * the pd_donation_{status} action that sends receipts and alerts.
	 *
	 * @param array $fields  Other columns to write in the same UPDATE.
	 * @param array $context Passed to the action: 'source' (ipn, callback,
	 *                       reconcile, admin) and 'notify' (email the donor).
	 * @return bool Whether this call moved the donation.
	 */
	public function transition( string $to, array $fields = [], array $context = [] ): bool {
		$from = array_keys( array_filter( self::MOVES, static fn( array $tos ): bool => in_array( $to, $tos, true ) ) );
		if ( ! $from ) {
			return false;
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$set = array_merge( $fields, [ 'status' => $to, 'updated_at' => $now ] );
		if ( 'completed' === $to ) {
			$set['completed_at'] = $now;
		}

		$assign = [];
		$args   = [];
		foreach ( $set as $col => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/', (string) $col ) ) {
				continue;
			}
			if ( null === $value ) {
				$assign[] = "`{$col}` = NULL";
				continue;
			}
			$assign[] = "`{$col}` = %s";
			$args[]   = (string) $value;
		}
		$args[] = $this->get_id();
		$in     = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$args   = array_merge( $args, $from );

		$moved = 1 === (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}pd_donations SET " . implode( ', ', $assign ) . " WHERE id = %d AND status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL
			$args
		) );
		if ( ! $moved ) {
			return false;
		}

		$previous   = $this->get_status();
		$this->data = array_merge( $this->data, $set );

		if ( $this->get_donor_id() ) {
			Donor::recalculate( $this->get_donor_id() );
		}
		Campaign_Totals::flush();

		/**
		 * Fires once per real status change, e.g. pd_donation_completed.
		 *
		 * @param Donation $donation
		 * @param array    $context  source, notify, previous
		 */
		do_action( 'pd_donation_' . $to, $this, $context + [ 'previous' => $previous, 'source' => 'unknown', 'notify' => true ] );
		return true;
	}

	/**
	 * SQL (no user input) selecting donations that count as money received.
	 * Once the gateway is live, test payments made in sandbox never count;
	 * rows from before the environment was recorded (NULL) count as real.
	 */
	public static function counted_sql( string $alias = '' ): string {
		$a   = '' !== $alias ? $alias . '.' : '';
		$sql = "{$a}status = 'completed'";
		if ( 'production' === get_option( 'pd_pesapal_environment', 'sandbox' ) ) {
			$sql .= " AND ( {$a}environment IS NULL OR {$a}environment <> 'sandbox' )";
		}
		return $sql;
	}

	public function get_id(): int        { return (int) $this->data['id']; }
	public function get_uuid(): string   { return (string) $this->data['uuid']; }
	public function get_campaign_id(): int { return (int) $this->data['campaign_id']; }
	public function get_donor_id(): int  { return (int) ( $this->data['donor_id'] ?? 0 ); }
	public function get_amount(): float  { return (float) $this->data['amount']; }
	public function get_currency(): string { return (string) $this->data['currency']; }
	public function get_status(): string { return (string) $this->data['status']; }
	public function get_gateway(): string { return (string) $this->data['gateway']; }
	public function get_merchant_reference(): string { return (string) $this->data['merchant_reference']; }
	public function get_tracking_id(): string { return (string) ( $this->data['order_tracking_id'] ?? '' ); }
	public function get_environment(): string { return (string) ( $this->data['environment'] ?? '' ); }
	public function is_test(): bool      { return 'sandbox' === $this->get_environment(); }
	public function get_donor_email(): string { return (string) $this->data['donor_email']; }
	public function get_donor_name(): string { return (string) $this->data['donor_name']; }
	public function get_completed_at(): string { return (string) ( $this->data['completed_at'] ?? '' ); }
	public function get_created_at(): string { return (string) ( $this->data['created_at'] ?? '' ); }
}
