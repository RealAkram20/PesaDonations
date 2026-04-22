<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Models\Donation;
use PesaDonations\Models\Donor;
use PesaDonations\Utils\Logger;
use WP_REST_Request;
use WP_REST_Response;

class Pesapal_IPN {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_action( 'template_redirect', [ $this, 'handle_callback_redirect' ], 1 );
	}

	public function register_route(): void {
		register_rest_route( 'pesa-donations/v1', '/pesapal-ipn', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $this, 'handle_ipn' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle_ipn( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();
		$tracking_id = sanitize_text_field( (string) ( $params['OrderTrackingId'] ?? '' ) );
		$merchant_ref = sanitize_text_field( (string) ( $params['OrderMerchantReference'] ?? '' ) );
		$notification_type = sanitize_text_field( (string) ( $params['OrderNotificationType'] ?? 'IPNCHANGE' ) );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'pd_gateway_logs', [
			'gateway'      => 'pesapal',
			'direction'    => 'incoming',
			'endpoint'     => 'pesapal-ipn',
			'request_body' => wp_json_encode( $params ),
			'created_at'   => current_time( 'mysql' ),
		] );

		if ( ! $tracking_id || ! $merchant_ref ) {
			return new WP_REST_Response( [ 'status' => 400, 'error' => 'missing params' ], 400 );
		}

		$donation = Donation::get_by_merchant_ref( $merchant_ref );
		if ( ! $donation ) {
			Logger::error( 'PesaPal IPN: donation not found', [ 'ref' => $merchant_ref ] );
			return new WP_REST_Response( [ 'status' => 404 ], 404 );
		}

		$this->sync_status( $donation, $tracking_id );

		return new WP_REST_Response( [
			'orderNotificationType'  => $notification_type,
			'orderTrackingId'        => $tracking_id,
			'orderMerchantReference' => $merchant_ref,
			'status'                 => 200,
		], 200 );
	}

	/**
	 * Runs on every page load. Intercepts ?pd_callback=1&d=UUID from PesaPal
	 * after the donor completes payment.
	 */
	public function handle_callback_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Support both 'pd_d' (current) and 'd' (legacy) for safety.
		$uuid_raw = $_GET['pd_d'] ?? ( $_GET['d'] ?? '' );
		if ( empty( $_GET['pd_callback'] ) || empty( $uuid_raw ) ) {
			return;
		}

		$uuid = sanitize_text_field( wp_unslash( $uuid_raw ) );
		$donation = Donation::get_by_uuid( $uuid );
		if ( ! $donation ) {
			wp_die( esc_html__( 'Invalid donation reference.', 'pesa-donations' ) );
		}

		$tracking_id = sanitize_text_field( wp_unslash( $_GET['OrderTrackingId'] ?? $donation->get_merchant_reference() ) );

		$this->sync_status( $donation, $tracking_id );

		$thank_you_id = (int) get_option( 'pd_thank_you_page_id' );
		$redirect = $thank_you_id
			? add_query_arg( 'pd_d', $uuid, get_permalink( $thank_you_id ) )
			: home_url( '/?pd_thankyou=1&pd_d=' . $uuid );

		// Output an HTML page that breaks out of the iframe if loaded inside one.
		// When user completes (or cancels) payment in the iframe popup, PesaPal
		// redirects the iframe here. We need to redirect the TOP window instead.
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'Redirecting…', 'pesa-donations' ); ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; color: #333; }
.box { text-align: center; }
</style>
</head>
<body>
<div class="box">
	<p><?php esc_html_e( 'Finalizing your donation…', 'pesa-donations' ); ?></p>
</div>
<script>
(function(){
	var url = <?php echo wp_json_encode( $redirect ); ?>;
	try {
		if (window.top && window.top !== window.self) {
			window.top.location.replace(url);
		} else {
			window.location.replace(url);
		}
	} catch (e) {
		window.location.replace(url);
	}
})();
</script>
<noscript>
	<meta http-equiv="refresh" content="0; url=<?php echo esc_url( $redirect ); ?>">
	<p><a href="<?php echo esc_url( $redirect ); ?>"><?php esc_html_e( 'Continue', 'pesa-donations' ); ?></a></p>
</noscript>
</body>
</html>
		<?php
		exit;
	}

	private function sync_status( Donation $donation, string $tracking_id ): void {
		// Refresh tracking id from response if not yet stored.
		if ( $tracking_id && ! $donation->update( [ 'order_tracking_id' => $tracking_id ] ) ) {
			// no-op
		}

		$result = Pesapal_Gateway::get_transaction_status( $tracking_id );
		$update = [
			'status'      => $result['status'],
			'status_code' => $result['status_code'] ?? null,
		];

		if ( ! empty( $result['payment_method'] ) ) {
			$update['payment_method'] = $result['payment_method'];
		}
		if ( ! empty( $result['confirmation'] ) ) {
			$update['confirmation_code'] = $result['confirmation'];
		}
		if ( 'completed' === $result['status'] ) {
			$update['completed_at'] = current_time( 'mysql' );
		}
		if ( ! empty( $result['raw'] ) ) {
			$update['gateway_response'] = wp_json_encode( $result['raw'] );
		}

		$donation->update( $update );

		// Refresh donor aggregates whenever the status changes.
		global $wpdb;
		$donor_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT donor_id FROM {$wpdb->prefix}pd_donations WHERE id = %d",
			$donation->get_id()
		) );
		if ( $donor_id ) {
			\PesaDonations\Models\Donor::recalculate( $donor_id );
		}

		// Bust campaign progress caches when a donation completes or reverses.
		if ( in_array( $result['status'], [ 'completed', 'reversed' ], true ) ) {
			delete_transient( 'pd_raised_' . $donation->get_campaign_id() );
			delete_transient( 'pd_donors_' . $donation->get_campaign_id() );
		}

		if ( 'completed' === $result['status'] ) {
			do_action( 'pd_donation_completed', $donation );
		} elseif ( 'failed' === $result['status'] ) {
			do_action( 'pd_donation_failed', $donation );
		}
	}
}
