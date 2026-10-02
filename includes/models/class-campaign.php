<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

use PesaDonations\CPT\Campaign_CPT;
use WP_Post;

class Campaign {

	private WP_Post $post;
	private array $meta = [];
	private ?Campaign_Schedule $schedule = null;

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

	/** The story, unless the post is password-protected and this visitor has not entered it. */
	public function get_content(): string {
		if ( post_password_required( $this->post ) ) {
			return '';
		}
		return (string) apply_filters( 'the_content', $this->post->post_content );
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
			// 'large' (1024 px), not the original upload: each swipe is on mobile data.
			$full  = wp_get_attachment_image_url( $id, 'large' );
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

	/**
	 * Plans as {name, amount, currency}, cleaned: a positive number and a
	 * three-letter currency (the campaign's when absent). Anything else is
	 * dropped, so a stored value can never reach the page as code.
	 */
	public function get_sponsorship_plans(): array {
		$plans = $this->meta( '_pd_sponsorship_plans' );
		if ( is_string( $plans ) ) {
			$plans = json_decode( $plans, true );
		}
		$out = [];
		foreach ( is_array( $plans ) ? $plans : [] as $plan ) {
			$amount   = is_array( $plan ) && is_numeric( $plan['amount'] ?? null ) ? (float) $plan['amount'] : 0.0;
			$currency = strtoupper( (string) ( $plan['currency'] ?? '' ) );
			if ( $amount <= 0 ) {
				continue;
			}
			$out[] = [
				'name'     => sanitize_text_field( (string) ( $plan['name'] ?? '' ) ),
				'amount'   => $amount,
				'currency' => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : $this->get_base_currency(),
			];
		}
		return $out;
	}

	/**
	 * Quick-pick amounts in the campaign's own currency only: the form always
	 * submits the base currency, so a "10,000 UGX" button on a USD campaign
	 * would ask the donor's card for USD 10,000.
	 */
	public function get_suggested_amounts(): array {
		$amounts = $this->meta( '_pd_suggested_amounts' );
		if ( is_string( $amounts ) ) {
			$amounts = json_decode( $amounts, true );
		}
		$base = $this->get_base_currency();
		$out  = [];
		foreach ( is_array( $amounts ) ? $amounts : [] as $row ) {
			$amount   = is_array( $row ) && is_numeric( $row['amount'] ?? null ) ? (float) $row['amount'] : 0.0;
			$currency = strtoupper( (string) ( $row['currency'] ?? $base ) );
			if ( $amount > 0 && $currency === $base ) {
				$out[] = [ 'amount' => $amount, 'currency' => $base ];
			}
		}
		return $out;
	}

	/**
	 * Blank (or "0", which 1.1.0 stored for blank) means the site default, and
	 * that default is set in UGX: it applies to UGX campaigns only. Before, a
	 * USD campaign with no minimum demanded "5,000 USD".
	 */
	public function get_minimum_amount(): float {
		$raw = $this->meta( '_pd_minimum_amount' );
		if ( is_numeric( $raw ) && (float) $raw > 0 ) {
			return (float) $raw;
		}
		return 'UGX' === $this->get_base_currency() ? (float) get_option( 'pd_minimum_amount_ugx', 5000 ) : 0.0;
	}

	public function allows_recurring(): bool {
		return (bool) $this->meta( '_pd_allow_recurring' );
	}

	public function allows_anonymous(): bool {
		return (bool) $this->meta( '_pd_allow_anonymous' );
	}

	/**
	 * Whether donors may give in another currency here: on site-wide (Settings →
	 * General) unless this campaign is set to its own currency only. The 1.2
	 * per-campaign "Allow Donor to Switch Currency" box (saved off on nearly
	 * every campaign) no longer applies.
	 */
	public function allows_currency_switch(): bool {
		return \PesaDonations\Utils\Currencies::choice_enabled() && '1' !== (string) $this->meta( '_pd_single_currency' );
	}

	public function show_progress_bar(): bool {
		return (bool) $this->meta( '_pd_show_progress_bar' );
	}

	public function show_donor_count(): bool {
		return (bool) $this->meta( '_pd_show_donor_count' );
	}

	/**
	 * What the campaign's checkbox says. The old default for a never-saved
	 * sponsorship compared the category with "child", which get_category() no
	 * longer returns, so it was always off; it stays off, matching the editor.
	 */
	public function checkout_requires_address(): bool {
		return '1' === (string) $this->meta( '_pd_checkout_require_address' );
	}

	public function is_active(): bool {
		return 'active' === $this->get_status() && 'publish' === $this->post->post_status;
	}

	/**
	 * Whether the checkout may take a donation now. A campaign that reached its
	 * goal still accepts gifts (the daily job marks it "reached" for display only).
	 * Closed once the end date has passed or the last custom period is over, even
	 * before the scheduled job has flipped the status.
	 */
	public function accepts_donations(): bool {
		if ( 'publish' !== $this->post->post_status || ! in_array( $this->get_status(), [ 'active', 'reached' ], true ) ) {
			return false;
		}
		$end = $this->get_end_date();
		if ( $end && $end < current_time( 'Y-m-d' ) ) {
			return false;
		}
		return ! $this->repeats() || null !== $this->get_current_period();
	}

	// -------------------------------------------------------------------------
	// Duration & repeat
	// -------------------------------------------------------------------------

	public function get_cycle_type(): string {
		return (string) ( $this->meta( '_pd_cycle_type' ) ?: Campaign_Schedule::ONCE );
	}

	/** @return array<int, array{id: string, label: string, start: string, end: string}> */
	public function get_custom_periods(): array {
		$raw = $this->meta( '_pd_cycle_periods' );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_values( $raw ) : [];
	}

	public function get_schedule(): Campaign_Schedule {
		return $this->schedule ??= new Campaign_Schedule(
			$this->get_cycle_type(),
			(string) $this->meta( '_pd_cycle_start' ),
			$this->get_custom_periods(),
			(string) $this->meta( '_pd_cycle_since' )
		);
	}

	public function repeats(): bool {
		return $this->get_schedule()->repeats();
	}

	/**
	 * The period donations count toward now; null for a one-time campaign, and
	 * after the campaign's end date (cards showed "November 2026 · Ends 30 Nov"
	 * with nothing raised on a campaign that had closed).
	 */
	public function get_current_period(): ?Campaign_Period {
		$end = $this->get_end_date();
		if ( $end && $end < current_time( 'Y-m-d' ) ) {
			return null;
		}
		return $this->get_schedule()->current();
	}

	/** The period a donation made at $created_at (site time) counted toward. */
	public function get_period_for_donation( string $created_at ): ?Campaign_Period {
		if ( ! $this->repeats() || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $created_at ) ) {
			return null;
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $created_at, 0, 10 ), wp_timezone() );
		return $day ? $this->get_schedule()->period_for( $day ) : null;
	}

	public function sends_cycle_reminders(): bool {
		// Never saved means the admin has not turned it off: on by default.
		return '0' !== (string) $this->meta( '_pd_cycle_reminders' );
	}

	/** "Ends 5 Dec 2026", or "Starts 25 May 2026" when the period has not begun. */
	public function get_period_note(): string {
		$period = $this->get_current_period();
		if ( ! $period ) {
			return '';
		}
		if ( ! $period->has_started( $this->get_schedule()->today() ) ) {
			/* translators: %s: date the period starts */
			return sprintf( __( 'Starts %s', 'pesa-donations' ), Campaign_Period::format_day( $period->get_start() ) );
		}
		/* translators: %s: last day of the period */
		return sprintf( __( 'Ends %s', 'pesa-donations' ), Campaign_Period::format_day( $period->get_end() ) );
	}

	// -------------------------------------------------------------------------
	// Aggregates (cached)
	// -------------------------------------------------------------------------

	/** Raised in the current period for a repeating campaign, all time otherwise. */
	public function get_raised_amount(): float {
		return $this->current_totals()['raised'];
	}

	public function get_donor_count(): int {
		return $this->current_totals()['donors'];
	}

	/**
	 * Completed donations made between two site-time datetimes (inclusive).
	 * Empty bounds mean unbounded.
	 *
	 * @return array{raised: float, donors: int, count: int}
	 */
	public function get_totals_between( string $from = '', string $to = '' ): array {
		global $wpdb;
		// Same rule as the progress bar: real money, in the campaign's own currency.
		$sql  = "SELECT COALESCE(SUM(amount_base), 0) AS raised, COUNT(DISTINCT " . Donation::WHO_SQL . ") AS donors, COUNT(*) AS cnt
				 FROM {$wpdb->prefix}pd_donations
				 WHERE campaign_id = %d AND base_currency = %s AND " . Donation::counted_sql();
		$args = [ $this->get_id(), $this->get_base_currency() ];
		if ( '' !== $from ) {
			$sql   .= ' AND created_at >= %s';
			$args[] = $from;
		}
		if ( '' !== $to ) {
			$sql   .= ' AND created_at <= %s';
			$args[] = $to;
		}
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'raised' => (float) ( $row['raised'] ?? 0 ),
			'donors' => (int) ( $row['donors'] ?? 0 ),
			'count'  => (int) ( $row['cnt'] ?? 0 ),
		];
	}

	/** Current totals from the shared cache (Campaign_Totals), per period. */
	private function current_totals(): array {
		return Campaign_Totals::get( $this );
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

	/**
	 * Card text: the manual excerpt, or the story's first words as plain text.
	 * Never runs the_content (a story holding a browse shortcode would recurse,
	 * and forty cards would run every content filter forty times).
	 */
	public function get_summary( int $words = 40 ): string {
		if ( post_password_required( $this->post ) ) {
			return '';
		}
		$text = has_excerpt( $this->post )
			? $this->post->post_excerpt
			: wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $this->post->post_content ) ) );
		return html_entity_decode( wp_trim_words( $text, $words, '…' ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * What a card and the details header need, as plain text (rendered with
	 * x-text, so entities are decoded here). The story and the gallery are not
	 * included: they load when the details open (to_details_array()).
	 */
	public function to_json_array(): array {
		$plain  = static fn( string $s ): string => html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
		$show   = $this->show_progress_bar();
		$period = $this->get_current_period();
		return [
			'id'           => $this->get_id(),
			'title'        => $plain( $this->get_title() ),
			'display_title' => $plain( ( $this->is_sponsorship() ? $this->get_beneficiary_name() : '' ) ?: $this->get_title() ),
			'summary'      => $this->get_summary( 11 ),
			'thumbnail'    => $this->get_thumbnail_url( 'medium_large' ),
			'thumbnail_lg' => $this->get_thumbnail_url( 'large' ),
			'category'     => $this->get_category(),
			'is_sponsorship' => $this->is_sponsorship(),
			'beneficiary'  => $plain( $this->get_beneficiary_name() ),
			'location'     => $plain( $this->get_beneficiary_location() ),
			'birthday'     => $this->get_beneficiary_birthday(),
			'code'         => $plain( $this->get_beneficiary_code() ),
			'currency'     => $this->get_base_currency(),
			'goal'         => $this->get_goal_amount(),
			'goal_fmt'     => number_format( $this->get_goal_amount() ),
			// Totals are published only where the campaign shows its progress.
			'raised'       => $show ? $this->get_raised_amount() : null,
			'raised_fmt'   => $show ? number_format( $this->get_raised_amount() ) : '',
			'progress'     => $show ? $this->get_progress_percent() : 0,
			'show_bar'     => $show,
			// Precomputed: "goal > 0" inside an Alpine attribute is broken by
			// wptexturize, which block themes run over the whole finished page.
			'has_progress' => $show && $this->get_goal_amount() > 0,
			// One bit, for the "Fully funded" filter, without publishing the totals.
			'funded'       => $this->get_goal_amount() > 0 && $this->get_progress_percent() >= 100,
			'checkout_url' => $this->get_checkout_url(),
			'period_label' => $period ? $period->get_label() : '',
			'period_note'  => $this->get_period_note(),
		];
	}

	/** The story (through the_content, as on the campaign's own page) and the gallery. */
	public function to_details_array(): array {
		return [
			'id'      => $this->get_id(),
			'content' => $this->get_content(),
			'gallery' => $this->get_gallery_images( 'medium' ),
		];
	}
}
