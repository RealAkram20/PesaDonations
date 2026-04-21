<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Models\Donation;
use PesaDonations\Payments\Abstract_Gateway;
use PesaDonations\Utils\Logger;

class Pesapal_Gateway extends Abstract_Gateway {

	public function get_id(): string {
		return 'pesapal';
	}

	public function get_name(): string {
		return 'PesaPal';
	}

	public function supports_currency( string $currency ): bool {
		return in_array( strtoupper( $currency ), [ 'UGX', 'KES', 'TZS', 'USD' ], true );
	}

	/**
	 * Submits the order to PesaPal and returns the redirect URL.
	 *
	 * @return array { redirect_url: string, order_tracking_id: string, message?: string }
	 */
	public function init_donation( Donation $donation, array $donor_data ): array {
		$ipn_id = $this->ensure_ipn_registered();
		if ( ! $ipn_id ) {
			wp_send_json_error( [
				'message' => __( 'PesaPal IPN is not registered. Please contact the site administrator.', 'pesa-donations' ),
			], 500 );
		}

		$campaign_id = $donation->get_campaign_id();
		$callback_url = add_query_arg( [
			'pd_callback' => 1,
			'd'           => $donation->get_uuid(),
		], home_url( '/' ) );

		$payload = [
			'id'              => $donation->get_merchant_reference(),
			'currency'        => $donation->get_currency(),
			'amount'          => (float) $donation->get_amount(),
			'description'     => sprintf(
				/* translators: %s: campaign title */
				__( 'Donation to %s', 'pesa-donations' ),
				get_the_title( $campaign_id )
			),
			'callback_url'    => $callback_url,
			'notification_id' => $ipn_id,
			'billing_address' => [
				'email_address' => $donor_data['email'] ?? '',
				'phone_number'  => $donor_data['phone'] ?? '',
				'first_name'    => $donor_data['first_name'] ?? '',
				'last_name'     => $donor_data['last_name'] ?? '',
				'country_code'  => $donor_data['country'] ?? '',
			],
		];

		$response = Pesapal_API::request( 'POST', '/api/Transactions/SubmitOrderRequest', $payload );

		if ( ! $response || empty( $response['redirect_url'] ) || empty( $response['order_tracking_id'] ) ) {
			$err = is_array( $response ) && isset( $response['error']['message'] ) ? $response['error']['message'] : __( 'Unable to reach PesaPal. Please try again.', 'pesa-donations' );
			Logger::error( 'PesaPal SubmitOrder failed', [ 'response' => $response ] );
			wp_send_json_error( [ 'message' => $err ], 500 );
		}

		$donation->update( [
			'order_tracking_id' => sanitize_text_field( $response['order_tracking_id'] ),
			'gateway_response'  => wp_json_encode( $response ),
		] );

		return [
			'redirect_url'      => esc_url_raw( $response['redirect_url'] ),
			'order_tracking_id' => sanitize_text_field( $response['order_tracking_id'] ),
		];
	}

	/**
	 * Registers the IPN URL with PesaPal if not already done.
	 * Returns the IPN ID to use on orders.
	 */
	public function ensure_ipn_registered(): string {
		$stored_env = get_option( 'pd_pesapal_ipn_env' );
		$current_env = $this->get_environment();
		$ipn_id = (string) get_option( 'pd_pesapal_ipn_id' );

		if ( $ipn_id && $stored_env === $current_env ) {
			return $ipn_id;
		}

		$response = Pesapal_API::request( 'POST', '/api/URLSetup/RegisterIPN', [
			'url'                   => self::get_ipn_url(),
			'ipn_notification_type' => 'GET',
		] );

		if ( is_array( $response ) && ! empty( $response['ipn_id'] ) ) {
			update_option( 'pd_pesapal_ipn_id', sanitize_text_field( $response['ipn_id'] ) );
			update_option( 'pd_pesapal_ipn_env', $current_env );
			return (string) $response['ipn_id'];
		}

		Logger::error( 'PesaPal RegisterIPN failed', [ 'response' => $response ] );
		return '';
	}

	public static function get_ipn_url(): string {
		return rest_url( 'pesa-donations/v1/pesapal-ipn' );
	}

	/**
	 * Fetches transaction status for a donation.
	 * Returns normalized status string: pending|completed|failed|reversed.
	 */
	public static function get_transaction_status( string $order_tracking_id ): array {
		$response = Pesapal_API::request( 'GET', '/api/Transactions/GetTransactionStatus', [], [
			'orderTrackingId' => $order_tracking_id,
		] );

		if ( ! is_array( $response ) ) {
			return [ 'status' => 'pending', 'raw' => null ];
		}

		$code = isset( $response['status_code'] ) ? (int) $response['status_code'] : -1;
		$map  = [ 0 => 'pending', 1 => 'completed', 2 => 'failed', 3 => 'reversed' ];
		$status = $map[ $code ] ?? 'pending';

		return [
			'status'         => $status,
			'status_code'    => $code,
			'payment_method' => $response['payment_method'] ?? null,
			'confirmation'   => $response['confirmation_code'] ?? null,
			'raw'            => $response,
		];
	}
}
