<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Models\Donation;
use PesaDonations\Payments\Abstract_Gateway;
use PesaDonations\Utils\Logger;
use WP_Error;

class Pesapal_Gateway extends Abstract_Gateway {

	private const IPN_ID_OPTION  = 'pd_pesapal_ipn_id';
	private const IPN_SIG_OPTION = 'pd_pesapal_ipn_sig';

	public function get_id(): string {
		return 'pesapal';
	}

	public function get_name(): string {
		return 'PesaPal';
	}

	public function get_environment(): string {
		return Pesapal_Auth::environment();
	}

	public function supports_currency( string $currency ): bool {
		return in_array( strtoupper( $currency ), [ 'UGX', 'KES', 'TZS', 'USD' ], true );
	}

	/**
	 * Submits the order to PesaPal. Returns the redirect, or a WP_Error the
	 * caller turns into a message (and a failed donation row).
	 *
	 * @return array{redirect_url: string, order_tracking_id: string}|WP_Error
	 */
	public function init_donation( Donation $donation, array $donor_data ): array|WP_Error {
		$ipn_id = $this->ensure_ipn_registered();
		if ( ! $ipn_id ) {
			return new WP_Error( 'pd_ipn', __( 'Online payments are not set up yet. Please contact the organisation.', 'pesa-donations' ) );
		}

		$campaign_id  = $donation->get_campaign_id();
		$callback_url = add_query_arg( [
			'pd_callback' => 1,
			'pd_d'        => $donation->get_uuid(),
		], home_url( '/' ) );

		// PesaPal refuses a description over 100 characters; titles arrive texturized.
		$target      = $campaign_id ? get_the_title( $campaign_id ) : get_bloginfo( 'name' );
		$description = sprintf(
			/* translators: %s: campaign title */
			__( 'Donation to %s', 'pesa-donations' ),
			wp_strip_all_tags( html_entity_decode( (string) $target, ENT_QUOTES, 'UTF-8' ) )
		);

		$payload = [
			'id'              => $donation->get_merchant_reference(),
			'currency'        => $donation->get_currency(),
			'amount'          => round( $donation->get_amount(), 2 ),
			'description'     => mb_substr( $description, 0, 100 ),
			'callback_url'    => $callback_url,
			'notification_id' => $ipn_id,
			'billing_address' => array_merge( [
				'email_address' => $donor_data['email'] ?? '',
				'phone_number'  => $donor_data['phone'] ?? '',
				'first_name'    => $donor_data['first_name'] ?? '',
				'last_name'     => $donor_data['last_name'] ?? '',
				'country_code'  => $donor_data['country'] ?? '',
			], array_intersect_key( (array) ( $donor_data['address'] ?? [] ), array_flip( [ 'line_1', 'line_2', 'city', 'state', 'postal_code' ] ) ) ),
		];

		$response = Pesapal_API::request( 'POST', '/api/Transactions/SubmitOrderRequest', $payload, [], $donation->get_id() );

		if ( ! $response || empty( $response['redirect_url'] ) || empty( $response['order_tracking_id'] ) ) {
			$message = is_array( $response ) && isset( $response['error']['message'] ) ? (string) $response['error']['message'] : '';
			Logger::error( 'PesaPal SubmitOrder failed', [ 'donation' => $donation->get_id(), 'message' => $message ] );
			return new WP_Error( 'pd_gateway', $message ?: __( 'Unable to reach PesaPal. Please try again.', 'pesa-donations' ) );
		}

		$donation->update( [ 'order_tracking_id' => sanitize_text_field( $response['order_tracking_id'] ) ] );

		return [
			'redirect_url'      => esc_url_raw( $response['redirect_url'] ),
			'order_tracking_id' => sanitize_text_field( $response['order_tracking_id'] ),
		];
	}

	/**
	 * Registers this site's IPN URL with PesaPal once, and again whenever the
	 * environment, the consumer key or the URL itself changes (a site moved
	 * from staging keeps pointing PesaPal at staging otherwise).
	 */
	public function ensure_ipn_registered(): string {
		$ipn_id = (string) get_option( self::IPN_ID_OPTION );
		if ( $ipn_id && get_option( self::IPN_SIG_OPTION ) === self::ipn_signature() ) {
			return $ipn_id;
		}

		$response = Pesapal_API::request( 'POST', '/api/URLSetup/RegisterIPN', [
			'url'                   => self::get_ipn_url(),
			'ipn_notification_type' => 'GET',
		] );

		if ( is_array( $response ) && ! empty( $response['ipn_id'] ) ) {
			update_option( self::IPN_ID_OPTION, sanitize_text_field( $response['ipn_id'] ) );
			update_option( self::IPN_SIG_OPTION, self::ipn_signature() );
			update_option( 'pd_pesapal_ipn_env', Pesapal_Auth::environment() ); // read by the settings screen
			return (string) $response['ipn_id'];
		}

		Logger::error( 'PesaPal RegisterIPN failed' );
		return '';
	}

	/** Forget the registration so the next payment registers again. */
	public static function forget_ipn(): void {
		delete_option( self::IPN_SIG_OPTION );
	}

	public static function is_ipn_current(): bool {
		return (bool) get_option( self::IPN_ID_OPTION ) && get_option( self::IPN_SIG_OPTION ) === self::ipn_signature();
	}

	private static function ipn_signature(): string {
		return md5( Pesapal_Auth::environment() . '|' . get_option( 'pd_pesapal_consumer_key' ) . '|' . self::get_ipn_url() );
	}

	public static function get_ipn_url(): string {
		return rest_url( 'pesa-donations/v1/pesapal-ipn' );
	}

	/**
	 * Asks PesaPal what happened to an order.
	 *
	 * @return array{status: ?string, status_code: int, payment_method: ?string, confirmation: ?string, raw: array}|null
	 *         null when PesaPal could not be asked (or answered nonsense): the caller changes nothing.
	 *         status is null for INVALID (0): nothing to record yet.
	 */
	public static function get_transaction_status( string $order_tracking_id, int $donation_id = 0 ): ?array {
		$response = Pesapal_API::request( 'GET', '/api/Transactions/GetTransactionStatus', [], [
			'orderTrackingId' => $order_tracking_id,
		], $donation_id );

		if ( ! is_array( $response ) || ! isset( $response['status_code'] ) || ! in_array( (int) $response['status_code'], [ 0, 1, 2, 3 ], true ) ) {
			return null;
		}

		$code = (int) $response['status_code'];
		$map  = [ 0 => null, 1 => 'completed', 2 => 'failed', 3 => 'reversed' ];

		return [
			'status'         => $map[ $code ],
			'status_code'    => $code,
			'payment_method' => isset( $response['payment_method'] ) ? (string) $response['payment_method'] : null,
			'confirmation'   => isset( $response['confirmation_code'] ) ? (string) $response['confirmation_code'] : null,
			'raw'            => $response,
		];
	}

	/**
	 * The settled transaction must be THIS donation: same merchant reference,
	 * same currency, same amount. (Kugawana's matches(), D:\OS\references\
	 * payment-gateways.md.) PesaPal omitting the amount or currency is
	 * tolerated; a present-but-different value is not.
	 */
	public static function matches( Donation $donation, array $status ): bool {
		$raw = $status['raw'] ?? [];
		$ref = (string) ( $raw['merchant_reference'] ?? '' );
		if ( '' === $ref || ! hash_equals( $donation->get_merchant_reference(), $ref ) ) {
			return false;
		}
		if ( ! empty( $raw['currency'] ) && strtoupper( (string) $raw['currency'] ) !== strtoupper( $donation->get_currency() ) ) {
			return false;
		}
		if ( isset( $raw['amount'] ) && is_numeric( $raw['amount'] ) && abs( (float) $raw['amount'] - $donation->get_amount() ) >= 0.01 ) {
			return false;
		}
		return true;
	}
}
