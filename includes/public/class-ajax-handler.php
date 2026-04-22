<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

use PesaDonations\Models\Campaign;
use PesaDonations\Models\Donation;
use PesaDonations\Models\Donor;
use PesaDonations\Utils\Sanitizer;

class Ajax_Handler {

	public function register(): void {
		add_action( 'wp_ajax_pd_init_donation',        [ $this, 'init_donation' ] );
		add_action( 'wp_ajax_nopriv_pd_init_donation', [ $this, 'init_donation' ] );

		add_action( 'wp_ajax_pd_get_gateways',        [ $this, 'get_gateways' ] );
		add_action( 'wp_ajax_nopriv_pd_get_gateways', [ $this, 'get_gateways' ] );

		add_action( 'wp_ajax_pd_lookup_donor',        [ $this, 'lookup_donor' ] );
		add_action( 'wp_ajax_nopriv_pd_lookup_donor', [ $this, 'lookup_donor' ] );
	}

	/**
	 * Look up a returning donor by email or phone. Returns their stored
	 * first/last name, phone, country so the checkout can auto-fill.
	 */
	public function lookup_donor(): void {
		$this->verify_nonce();

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = Sanitizer::phone( $_POST['phone'] ?? '' );

		if ( ! $email && ! $phone ) {
			wp_send_json_success( null );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'pd_donors';
		$donor = null;

		if ( $email ) {
			$donor = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT first_name, last_name, email, phone, country FROM {$table} WHERE email = %s LIMIT 1",
					strtolower( $email )
				),
				ARRAY_A
			);
		}

		if ( ! $donor && $phone ) {
			$donor = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT first_name, last_name, email, phone, country FROM {$table} WHERE phone = %s LIMIT 1",
					$phone
				),
				ARRAY_A
			);
		}

		wp_send_json_success( $donor ?: null );
	}

	public function init_donation(): void {
		$this->verify_nonce();

		$campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		$campaign    = $campaign_id ? Campaign::get( $campaign_id ) : null;

		if ( ! $campaign || ! $campaign->is_active() ) {
			wp_send_json_error( [ 'message' => __( 'Campaign not found.', 'pesa-donations' ) ], 404 );
		}

		$amount   = Sanitizer::amount( $_POST['amount'] ?? 0 );
		$currency = Sanitizer::currency( $_POST['currency'] ?? '' ) ?: $campaign->get_base_currency();
		$gateway  = Sanitizer::gateway( $_POST['gateway'] ?? 'pesapal' );
		$min      = $campaign->get_minimum_amount();

		if ( $amount < $min ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: minimum amount with currency */
					__( 'Minimum donation is %s.', 'pesa-donations' ),
					number_format( $min ) . ' ' . $campaign->get_base_currency()
				),
			], 422 );
		}

		$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone      = Sanitizer::phone( $_POST['phone'] ?? '' );
		$country    = sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) );
		$message    = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$anonymous  = ! empty( $_POST['anonymous'] );

		if ( ! $email && ! $phone ) {
			wp_send_json_error( [ 'message' => __( 'Email or phone number is required.', 'pesa-donations' ) ], 422 );
		}

		$donor = Donor::get_or_create( $email ?: $phone . '@phone.pd', [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'phone'      => $phone,
			'country'    => $country,
		] );

		// Create the donation row.
		$donation_id = Donation::create( [
			'campaign_id'  => $campaign_id,
			'donor_id'     => $donor->get_id(),
			'amount'       => $amount,
			'currency'     => $currency,
			'amount_base'  => $amount,  // FX conversion applied in gateway init.
			'gateway'      => $gateway,
			'donor_name'   => $anonymous ? '' : trim( $first_name . ' ' . $last_name ),
			'donor_email'  => $email,
			'donor_phone'  => $phone,
			'donor_country' => $country,
			'donor_ip'     => $this->get_ip(),
			'is_anonymous' => $anonymous ? 1 : 0,
			'message'      => $message,
		] );

		if ( ! $donation_id ) {
			wp_send_json_error( [ 'message' => __( 'Could not create donation record.', 'pesa-donations' ) ], 500 );
		}

		$donation = Donation::get( $donation_id );

		// Generate the merchant reference now that we have the ID.
		$merchant_ref = Sanitizer::merchant_reference( (string) $campaign_id, (string) $donation_id );
		$donation->update( [ 'merchant_reference' => $merchant_ref ] );

		// Hand off to the gateway.
		$gateway_obj = \PesaDonations\Payments\Gateway_Manager::get( $gateway );
		if ( ! $gateway_obj ) {
			wp_send_json_error( [ 'message' => __( 'Payment gateway not available.', 'pesa-donations' ) ], 400 );
		}

		$donor_data = [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => $email,
			'phone'      => $phone,
			'country'    => $country,
		];

		$result = $gateway_obj->init_donation( $donation, $donor_data );
		wp_send_json_success( $result );
	}

	public function get_gateways(): void {
		$this->verify_nonce();

		$campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		$currency    = Sanitizer::currency( $_POST['currency'] ?? '' );
		$campaign    = $campaign_id ? Campaign::get( $campaign_id ) : null;

		if ( ! $campaign ) {
			wp_send_json_error( [], 404 );
		}

		$available = \PesaDonations\Payments\Gateway_Manager::get_available( $campaign, $currency );
		$response  = [];

		foreach ( $available as $id => $data ) {
			$response[] = [
				'id'     => $id,
				'name'   => $data['gateway']->get_name(),
				'notice' => $data['notice'],
			];
		}

		wp_send_json_success( $response );
	}

	private function verify_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pd_public_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'pesa-donations' ) ], 403 );
		}
	}

	private function get_ip(): string {
		foreach ( [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		return '';
	}
}
