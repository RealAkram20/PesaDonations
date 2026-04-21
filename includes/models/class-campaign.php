<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

use PesaDonations\CPT\Campaign_CPT;
use WP_Post;

class Campaign {

	private WP_Post $post;
	private array $meta = [];

	public function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	public static function get( int $id ): ?self {
		$post = get_post( $id );
		if ( ! $post || Campaign_CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return new self( $post );
	}

	// -------------------------------------------------------------------------
	// Getters
	// -------------------------------------------------------------------------

	public function get_id(): int {
		return $this->post->ID;
	}

	public function get_title(): string {
		return get_the_title( $this->post );
	}

	public function get_excerpt(): string {
		return get_the_excerpt( $this->post );
	}

	public function get_content(): string {
		return apply_filters( 'the_content', $this->post->post_content );
	}

	public function get_thumbnail_url( string $size = 'medium' ): string {
		return get_the_post_thumbnail_url( $this->post, $size ) ?: '';
	}

	public function get_category(): string {
		$raw = (string) $this->meta( '_pd_category' );
		// Backward-compat: legacy values map to one of the two new categories.
		$legacy_map = [
			'child'    => 'sponsorship',
			'school'   => 'project',
			'hospital' => 'project',
			'medical'  => 'project',
			'other'    => 'project',
		];
		return $legacy_map[ $raw ] ?? ( $raw ?: 'project' );
	}

	public function is_sponsorship(): bool {
		return 'sponsorship' === $this->get_category();
	}

	public function is_project(): bool {
		return 'project' === $this->get_category();
	}

	public function get_gallery_ids(): array {
		$raw = $this->meta( '_pd_gallery_ids' );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : [];
		}
		return is_array( $raw ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : [];
	}

	public function get_gallery_images( string $size = 'medium' ): array {
		$ids = $this->get_gallery_ids();
		$out = [];
		foreach ( $ids as $id ) {
			$thumb = wp_get_attachment_image_url( $id, $size );
			$full  = wp_get_attachment_image_url( $id, 'full' );
			if ( $thumb && $full ) {
				$out[] = [
					'id'    => $id,
					'thumb' => $thumb,
					'full'  => $full,
					'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				];
			}
		}
		return $out;
	}

	public function get_goal_amount(): float {
		return (float) $this->meta( '_pd_goal_amount' );
	}

	public function get_base_currency(): string {
		return (string) ( $this->meta( '_pd_base_currency' ) ?: get_option( 'pd_default_currency', 'UGX' ) );
	}

	public function get_status(): string {
		return (string) ( $this->meta( '_pd_status' ) ?: 'active' );
	}

	public function get_end_date(): string {
		return (string) $this->meta( '_pd_end_date' );
	}

	public function get_beneficiary_name(): string {
		return (string) $this->meta( '_pd_beneficiary_name' );
	}

	public function get_beneficiary_location(): string {
		return (string) $this->meta( '_pd_beneficiary_location' );
	}

	public function get_beneficiary_birthday(): string {
		return (string) $this->meta( '_pd_beneficiary_birthday' );
	}

	public function get_beneficiary_code(): string {
		return (string) $this->meta( '_pd_beneficiary_code' );
	}

	public function get_sponsorship_plans(): array {
		$plans = $this->meta( '_pd_sponsorship_plans' );
		if ( is_string( $plans ) ) {
			$plans = json_decode( $plans, true );
		}
		return is_array( $plans ) ? $plans : [];
	}

	public function get_suggested_amounts(): array {
		$amounts = $this->meta( '_pd_suggested_amounts' );
		if ( is_string( $amounts ) ) {
			$amounts = json_decode( $amounts, true );
		}
		return is_array( $amounts ) ? $amounts : [];
	}

	public function get_minimum_amount(): float {
		return (float) ( $this->meta( '_pd_minimum_amount' ) ?: get_option( 'pd_minimum_amount_ugx', 5000 ) );
	}

	public function allows_recurring(): bool {
		return (bool) $this->meta( '_pd_allow_recurring' );
	}

	public function allows_anonymous(): bool {
		return (bool) $this->meta( '_pd_allow_anonymous' );
	}

	public function allows_currency_switch(): bool {
		return (bool) $this->meta( '_pd_allow_currency_switch' );
	}

	public function show_progress_bar(): bool {
		return (bool) $this->meta( '_pd_show_progress_bar' );
	}

	public function show_donor_count(): bool {
		return (bool) $this->meta( '_pd_show_donor_count' );
	}

	public function checkout_requires_address(): bool {
		$val = $this->meta( '_pd_checkout_require_address' );
		return '' === $val ? ( 'child' === $this->get_category() ) : (bool) $val;
	}

	public function is_active(): bool {
		return 'active' === $this->get_status() && 'publish' === $this->post->post_status;
	}

	// -------------------------------------------------------------------------
	// Aggregates (cached)
	// -------------------------------------------------------------------------

	public function get_raised_amount(): float {
		$transient = 'pd_raised_' . $this->get_id();
		$cached    = get_transient( $transient );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		global $wpdb;
		$raised = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(amount_base) FROM {$wpdb->prefix}pd_donations WHERE campaign_id = %d AND status = 'completed'",
			$this->get_id()
		) );

		set_transient( $transient, $raised, 5 * MINUTE_IN_SECONDS );
		return $raised;
	}

	public function get_donor_count(): int {
		$transient = 'pd_donors_' . $this->get_id();
		$cached    = get_transient( $transient );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT donor_email) FROM {$wpdb->prefix}pd_donations WHERE campaign_id = %d AND status = 'completed'",
			$this->get_id()
		) );

		set_transient( $transient, $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}

	public function get_progress_percent(): float {
		$goal = $this->get_goal_amount();
		if ( $goal <= 0 ) {
			return 0.0;
		}
		return min( 100.0, round( ( $this->get_raised_amount() / $goal ) * 100, 1 ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function meta( string $key ): mixed {
		if ( ! array_key_exists( $key, $this->meta ) ) {
			$this->meta[ $key ] = get_post_meta( $this->post->ID, $key, true );
		}
		return $this->meta[ $key ];
	}

	public function get_checkout_url(): string {
		$page_id = (int) get_option( 'pd_checkout_page_id' );
		if ( ! $page_id ) {
			return '';
		}
		return add_query_arg( 'pd_cid', $this->get_id(), get_permalink( $page_id ) );
	}

	public function to_json_array(): array {
		return [
			'id'           => $this->get_id(),
			'title'        => $this->get_title(),
			'excerpt'      => $this->get_excerpt(),
			'content'      => $this->get_content(),
			'thumbnail'    => $this->get_thumbnail_url( 'medium_large' ),
			'thumbnail_lg' => $this->get_thumbnail_url( 'large' ),
			'category'     => $this->get_category(),
			'is_sponsorship' => $this->is_sponsorship(),
			'beneficiary'  => $this->get_beneficiary_name(),
			'location'     => $this->get_beneficiary_location(),
			'birthday'     => $this->get_beneficiary_birthday(),
			'code'         => $this->get_beneficiary_code(),
			'plans'        => $this->get_sponsorship_plans(),
			'currency'     => $this->get_base_currency(),
			'goal'         => $this->get_goal_amount(),
			'goal_fmt'     => number_format( $this->get_goal_amount() ),
			'raised'       => $this->get_raised_amount(),
			'raised_fmt'   => number_format( $this->get_raised_amount() ),
			'donors'       => $this->get_donor_count(),
			'progress'     => $this->get_progress_percent(),
			'show_bar'     => $this->show_progress_bar(),
			'gallery'      => $this->get_gallery_images( 'medium' ),
			'checkout_url' => $this->get_checkout_url(),
		];
	}
}
