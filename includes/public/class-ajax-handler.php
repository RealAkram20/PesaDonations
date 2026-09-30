<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

use PesaDonations\Models\Campaign;
use PesaDonations\Models\Donation;
use PesaDonations\Models\Donor;
use PesaDonations\Models\Open_Donation;
use PesaDonations\Payments\Gateway_Manager;
use PesaDonations\Utils\Countries;
use PesaDonations\Utils\Sanitizer;

class Ajax_Handler {

	/** Checkout attempts allowed per window, per IP + email (and per IP alone, looser). */
	private const ATTEMPTS_PER_DONOR = 6;
	private const ATTEMPTS_PER_IP    = 40;
	private const WINDOW             = 10 * MINUTE_IN_SECONDS;

	public function register(): void {
		add_action( 'wp_ajax_pd_init_donation',        [ $this, 'init_donation' ] );
		add_action( 'wp_ajax_nopriv_pd_init_donation', [ $this, 'init_donation' ] );

		// A fresh nonce for pages a cache has kept longer than a nonce lives.
		add_action( 'wp_ajax_pd_nonce',        [ $this, 'fresh_nonce' ] );
		add_action( 'wp_ajax_nopriv_pd_nonce', [ $this, 'fresh_nonce' ] );

		add_action( 'wp_ajax_pd_campaign_details',        [ $this, 'campaign_details' ] );
		add_action( 'wp_ajax_nopriv_pd_campaign_details', [ $this, 'campaign_details' ] );

		// The returning-donor lookup (pd_lookup_donor) was removed in 1.2.0: it
		// answered any visitor's email or phone with a donor's name, phone and
		// country. The checkout now remembers a donor's details on their own device.
	}

	/**
	 * admin-ajax is never page-cached, so the checkout asks here for a nonce at
	 * submit time; the one printed into a cached page expires in 12–24 h.
	 */
	public function fresh_nonce(): void {
		nocache_headers();
		wp_send_json_success( [ 'nonce' => wp_create_nonce( 'pd_public_nonce' ) ] );
	}

	/** The story and gallery for the details modal, loaded when it opens. */
	public function campaign_details(): void {
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only.
		$campaign = $id ? Campaign::get( $id ) : null;
		if ( ! $campaign || 'publish' !== get_post_status( $id ) ) {
			wp_send_json_error( [], 404 );
		}
		// The story's filters (embeds, shortcodes) read the current post: without
		// it, an embed is fetched from its provider again on every request.
		global $post;
		$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		$details = $campaign->to_details_array();
		wp_reset_postdata();
		wp_send_json_success( $details );
	}

	public function init_donation(): void {
		$this->verify_nonce();

		$campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		$campaign    = null;

		if ( Open_Donation::CAMPAIGN_ID === $campaign_id ) {
			// Open donation: no campaign. Currency and minimum come from settings, never the browser.
			if ( ! Open_Donation::is_enabled() ) {
				wp_send_json_error( [ 'message' => __( 'Open donations are not accepted at the moment.', 'pesa-donations' ) ], 404 );
			}
			$base_currency = Open_Donation::currency();
			$currency      = $base_currency;
			$min           = Open_Donation::min_amount();
		} else {
			$campaign = Campaign::get( $campaign_id );
			if ( ! $campaign || ! $campaign->accepts_donations() ) {
				wp_send_json_error( [ 'message' => __( 'Campaign not found.', 'pesa-donations' ) ], 404 );
			}
			$base_currency = $campaign->get_base_currency();
			$currency      = $this->allowed_currency( $campaign, Sanitizer::currency( $_POST['currency'] ?? '' ) );
			$min           = $campaign->get_minimum_amount();
		}

		$amount = Sanitizer::amount( $_POST['amount'] ?? '', $currency );
		if ( null === $amount ) {
			wp_send_json_error( [ 'message' => __( 'Enter the amount as a number, for example 50000.', 'pesa-donations' ) ], 422 );
		}
		// The minimum is set in the base currency; it is compared only in it.
		if ( $currency === $base_currency && $amount < $min ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: minimum amount with currency */
					__( 'Minimum donation is %s.', 'pesa-donations' ),
					number_format( $min ) . ' ' . $base_currency
				),
			], 422 );
		}

		// The gateway is settled before anything is written.
		$gateway     = Sanitizer::gateway( $_POST['gateway'] ?? 'pesapal' );
		$gateway_obj = $gateway ? Gateway_Manager::get( $gateway ) : null;
		if ( ! $gateway_obj || ! $gateway_obj->is_enabled() || ! $gateway_obj->supports_currency( $currency ) ) {
			wp_send_json_error( [ 'message' => __( 'This payment method is not available for this currency.', 'pesa-donations' ) ], 400 );
		}

		$first_name = mb_substr( sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), 0, 100 );
		$last_name  = mb_substr( sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ), 0, 100 );
		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$email      = strlen( $email ) <= 150 ? $email : '';
		$phone      = mb_substr( Sanitizer::phone( $_POST['phone'] ?? '' ), 0, 30 );
		$country    = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) wp_unslash( $_POST['country'] ?? '' ) ), 0, 2 ) );
		$country    = Countries::is_valid( $country ) ? $country : '';
		$message    = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ), 0, 2000 );
		// Only where the campaign offers the checkbox (Allow Anonymous Donations).
		$anonymous  = ! empty( $_POST['anonymous'] ) && 'false' !== $_POST['anonymous'] && $campaign && $campaign->allows_anonymous();

		// Fields the form has always asked for; stored now instead of dropped.
		$address = array_filter( [
			'line_1'      => mb_substr( sanitize_text_field( wp_unslash( $_POST['address1'] ?? '' ) ), 0, 200 ),
			'line_2'      => mb_substr( sanitize_text_field( wp_unslash( $_POST['address2'] ?? '' ) ), 0, 200 ),
			'city'        => mb_substr( sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ), 0, 100 ),
			'state'       => mb_substr( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ), 0, 100 ),
			'postal_code' => mb_substr( sanitize_text_field( wp_unslash( $_POST['zip'] ?? '' ) ), 0, 20 ),
		] );
		$referral   = sanitize_text_field( wp_unslash( $_POST['how_heard'] ?? '' ) );
		$referral   = in_array( $referral, array_map( 'strval', (array) get_option( 'pd_referral_sources', [] ) ), true ) ? $referral : '';
		$updates    = ! empty( $_POST['updates'] ) && 'false' !== $_POST['updates'];
		$is_org     = ! empty( $_POST['is_org'] ) && 'false' !== $_POST['is_org'];

		if ( ! $email && ! $phone ) {
			wp_send_json_error( [ 'message' => __( 'Email or phone number is required.', 'pesa-donations' ) ], 422 );
		}

		$this->throttle( $email ?: $phone );

		$donor = Donor::get_or_create( $email ?: $phone . '@phone.pd', [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'phone'      => $phone,
			'country'    => $country,
		] );

		$donation_id = Donation::create( [
			'campaign_id'   => $campaign_id,
			'donor_id'      => $donor->get_id(),
			'amount'        => $amount,
			'currency'      => $currency,
			'amount_base'   => $amount, // No currency conversion exists; totals never add currencies together.
			'gateway'       => $gateway,
			'environment'   => $gateway_obj->get_environment(),
			'donor_name'    => $anonymous ? '' : trim( $first_name . ' ' . $last_name ),
			'donor_email'   => $email,
			'donor_phone'   => $phone,
			'donor_country' => $country,
			'donor_ip'      => $this->get_ip(),
			'is_anonymous'  => $anonymous ? 1 : 0,
			'message'       => $message,
			'donor_address' => $address ? wp_json_encode( $address ) : null,
			'referral_source' => $referral ?: null,
			'wants_updates' => $updates ? 1 : 0,
			'is_organization' => $is_org ? 1 : 0,
		] );

		if ( ! $donation_id ) {
			wp_send_json_error( [ 'message' => __( 'Could not create donation record.', 'pesa-donations' ) ], 500 );
		}

		$donation = Donation::get( $donation_id );

		// Generate the merchant reference now that we have the ID. PD-GEN-… for an open donation.
		$merchant_ref = Sanitizer::merchant_reference( $campaign_id ? (string) $campaign_id : 'GEN', (string) $donation_id );
		$donation->update( [ 'merchant_reference' => $merchant_ref ] );
		$donation = Donation::get( $donation_id );

		$result = $gateway_obj->init_donation( $donation, [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => $email,
			'phone'      => $phone,
			'country'    => $country,
			'address'    => $address,
		] );

		if ( is_wp_error( $result ) ) {
			// The attempt is recorded as failed, not left pending forever.
			$donation->transition( 'failed', [], [ 'source' => 'checkout', 'notify' => false ] );
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 502 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * The campaign's own currency, unless the campaign lets donors switch or a
	 * sponsorship plan is priced in another one. The browser does not choose.
	 */
	private function allowed_currency( Campaign $campaign, string $requested ): string {
		$base    = $campaign->get_base_currency();
		$allowed = [ $base ];
		if ( $campaign->allows_currency_switch() ) {
			$allowed = array_merge( $allowed, array_map( 'strtoupper', (array) get_option( 'pd_enabled_currencies', [] ) ) );
		}
		foreach ( $campaign->get_sponsorship_plans() as $plan ) {
			$allowed[] = $plan['currency'];
		}
		return in_array( $requested, $allowed, true ) ? $requested : $base;
	}

	/**
	 * Keyed on IP + donor, with a looser IP-only ceiling: East African carriers
	 * put thousands of phones behind one address, so IP alone would block a
	 * whole network. Each attempt writes rows and places a real PesaPal order.
	 */
	private function throttle( string $who ): void {
		$ip   = $this->get_ip();
		$keys = [
			'pd_rl_d_' . md5( $ip . '|' . strtolower( $who ) ) => self::ATTEMPTS_PER_DONOR,
			'pd_rl_i_' . md5( $ip )                            => self::ATTEMPTS_PER_IP,
		];
		foreach ( $keys as $key => $limit ) {
			$count = (int) get_transient( $key );
			if ( $count >= $limit ) {
				wp_send_json_error( [ 'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'pesa-donations' ) ], 429 );
			}
		}
		foreach ( $keys as $key => $limit ) {
			set_transient( $key, (int) get_transient( $key ) + 1, self::WINDOW );
		}
	}

	private function verify_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pd_public_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'pesa-donations' ), 'code' => 'nonce' ], 403 );
		}
	}

	/** REMOTE_ADDR only: forwarded headers are set by the client and can be anything. */
	private function get_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
		return substr( $ip, 0, 45 );
	}
}
