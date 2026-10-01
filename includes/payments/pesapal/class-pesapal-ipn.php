<?php
declare( strict_types=1 );

namespace PesaDonations\Payments\Pesapal;

use PesaDonations\Models\Donation;
use PesaDonations\Utils\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PesaPal's two doorbells (the donor's browser coming back, and the IPN) and
 * the hourly re-check. None of them carries a payment status: each asks
 * PesaPal about THIS donation's own order and accepts the answer only if it
 * matches the donation (D:\OS\references\payment-gateways.md, the four rules).
 */
class Pesapal_IPN {

	public const RECONCILE_HOOK = 'pd_reconcile_payments';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_action( 'template_redirect', [ $this, 'handle_callback_redirect' ], 1 );
		add_action( self::RECONCILE_HOOK, [ $this, 'reconcile' ] );
	}

	public function register_route(): void {
		register_rest_route( 'pesa-donations/v1', '/pesapal-ipn', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $this, 'handle_ipn' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * PesaPal expects JSON echoing the notification, with status 200 (handled)
	 * or 500 (try again later).
	 */
	public function handle_ipn( WP_REST_Request $request ): WP_REST_Response {
		$params            = $request->get_params();
		$tracking_id       = sanitize_text_field( (string) ( $params['OrderTrackingId'] ?? '' ) );
		$merchant_ref      = sanitize_text_field( (string) ( $params['OrderMerchantReference'] ?? '' ) );
		$notification_type = sanitize_text_field( (string) ( $params['OrderNotificationType'] ?? 'IPNCHANGE' ) );

		if ( ! $tracking_id || ! $merchant_ref ) {
			return new WP_REST_Response( [ 'status' => 400, 'error' => 'missing params' ], 400 );
		}

		// An unknown reference is answered without logging or calling out, so
		// random requests cost nothing and fill nothing.
		$donation = Donation::get_by_merchant_ref( $merchant_ref );
		if ( ! $donation ) {
			return new WP_REST_Response( [ 'status' => 404 ], 404 );
		}

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'pd_gateway_logs', [
			'gateway'             => 'pesapal',
			'direction'           => 'incoming',
			'endpoint'            => 'pesapal-ipn',
			'request_body'        => wp_json_encode( [ 'OrderTrackingId' => $tracking_id, 'OrderMerchantReference' => $merchant_ref, 'OrderNotificationType' => $notification_type ] ),
			'related_donation_id' => $donation->get_id(),
			'created_at'          => current_time( 'mysql' ),
		] );

		$handled = $this->sync( $donation, $tracking_id, 'ipn' );
		$status  = $handled ? 200 : 500;

		return new WP_REST_Response( [
			'orderNotificationType'  => $notification_type,
			'orderTrackingId'        => $tracking_id,
			'orderMerchantReference' => $merchant_ref,
			'status'                 => $status,
		], $status );
	}

	/**
	 * Intercepts ?pd_callback=1&pd_d=UUID when PesaPal sends the donor back.
	 */
	public function handle_callback_redirect(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- PesaPal's redirect; nothing here is trusted.
		// Support both 'pd_d' (current) and 'd' (legacy).
		$uuid_raw = $_GET['pd_d'] ?? ( $_GET['d'] ?? '' );
		if ( empty( $_GET['pd_callback'] ) || empty( $uuid_raw ) ) {
			return;
		}

		$uuid     = sanitize_text_field( wp_unslash( $uuid_raw ) );
		$donation = Donation::get_by_uuid( $uuid );
		if ( ! $donation ) {
			wp_die( esc_html__( 'Invalid donation reference.', 'pesa-donations' ) );
		}

		$this->sync( $donation, sanitize_text_field( wp_unslash( (string) ( $_GET['OrderTrackingId'] ?? '' ) ) ), 'callback' );
		// phpcs:enable

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

	/**
	 * Hourly: donations whose IPN never came (or came while PesaPal was down)
	 * are asked about again, for three days. Bounded per run.
	 */
	public function reconcile(): void {
		global $wpdb;
		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}pd_donations
			 WHERE status = 'pending' AND gateway = 'pesapal' AND order_tracking_id IS NOT NULL AND order_tracking_id <> ''
			   AND created_at BETWEEN %s AND %s
			 ORDER BY created_at DESC LIMIT 25",
			$now->modify( '-3 days' )->format( 'Y-m-d H:i:s' ),
			$now->modify( '-10 minutes' )->format( 'Y-m-d H:i:s' )
		) );
		foreach ( $ids as $id ) {
			$donation = Donation::get( (int) $id );
			if ( $donation ) {
				$this->sync( $donation, '', 'reconcile' );
			}
		}
	}

	/**
	 * Asks PesaPal about this donation's own order and records the answer.
	 *
	 * @param string $tracking_id What the doorbell claimed. It must equal the id
	 *                            stored at checkout; a different one is refused
	 *                            before any outbound call.
	 * @return bool False only when PesaPal could not be asked (answer 500 so it
	 *              retries); true when handled, including "nothing to change".
	 */
	public function sync( Donation $donation, string $tracking_id, string $source ): bool {
		if ( 'pesapal' !== $donation->get_gateway() ) {
			return true;
		}

		$stored = $donation->get_tracking_id();
		if ( '' !== $stored && '' !== $tracking_id && ! hash_equals( $stored, $tracking_id ) ) {
			Logger::error( 'PesaPal: refused a tracking id that is not this donation\'s', [ 'donation' => $donation->get_id(), 'source' => $source ] );
			return true;
		}
		$tracking = '' !== $stored ? $stored : $tracking_id;
		if ( '' === $tracking ) {
			return true;
		}

		// A test payment is only checked against the environment it was made in.
		if ( '' !== $donation->get_environment() && $donation->get_environment() !== Pesapal_Auth::environment() ) {
			return true;
		}

		// One lookup per order per 30 s, however often a doorbell rings.
		$lock = 'pd_pesapal_sync_' . md5( $tracking );
		if ( get_transient( $lock ) ) {
			return true;
		}
		set_transient( $lock, 1, 30 );

		$result = Pesapal_Gateway::get_transaction_status( $tracking, $donation->get_id() );
		if ( null === $result ) {
			delete_transient( $lock );
			return false; // PesaPal unreachable: change nothing.
		}
		if ( ! Pesapal_Gateway::matches( $donation, $result ) ) {
			Logger::error( 'PesaPal: status does not match this donation, ignored', [
				'donation' => $donation->get_id(),
				'source'   => $source,
				'got_ref'  => (string) ( $result['raw']['merchant_reference'] ?? '' ),
			] );
			return true;
		}

		if ( '' === $stored ) {
			$donation->update( [ 'order_tracking_id' => $tracking ] );
		}
		if ( null === $result['status'] ) {
			return true; // INVALID / not paid yet.
		}

		$donation->transition( $result['status'], array_filter( [
			'status_code'       => $result['status_code'],
			'payment_method'    => $result['payment_method'] ? substr( $result['payment_method'], 0, 50 ) : null,
			'confirmation_code' => $result['confirmation'] ? substr( $result['confirmation'], 0, 100 ) : null,
		], static fn( $v ): bool => null !== $v ), [ 'source' => $source ] );

		return true;
	}
}
