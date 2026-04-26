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

		add_action( 'wp_ajax_pd_submit_lead',        [ $this, 'submit_lead' ] );
		add_action( 'wp_ajax_nopriv_pd_submit_lead', [ $this, 'submit_lead' ] );
	}

	/**
	 * Handle the "Raise Hand For Charity" lead form on the donation
	 * single page. Public endpoint — nonce + per-IP rate limit. Composes
	 * a simple HTML email and sends it via wp_mail() to the address
	 * configured in Settings → Emails → "Lead Form Email" (falling back
	 * to the Admin Alerts email, then the site admin email). Reply-To
	 * is set to the lead's own email so admins can respond directly.
	 */
	public function submit_lead(): void {
		$this->verify_nonce();

		// Per-IP rate limit: 3 submissions per 10 minutes.
		$ip_key = 'pd_lead_rl_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0' ) );
		$hits   = (int) get_transient( $ip_key );
		if ( $hits >= 3 ) {
			wp_send_json_error( [
				'message' => __( 'Too many submissions. Please try again in a few minutes.', 'pesa-donations' ),
			], 429 );
		}
		set_transient( $ip_key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		$name        = sanitize_text_field( wp_unslash( $_POST['lead_name']  ?? '' ) );
		$email       = sanitize_email(      wp_unslash( $_POST['lead_email'] ?? '' ) );
		$phone       = Sanitizer::phone(    $_POST['lead_phone'] ?? '' );
		$campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		$source_url  = isset( $_POST['source_url'] )
			? esc_url_raw( wp_unslash( $_POST['source_url'] ) )
			: '';

		if ( ! $name || ! $email || ! $phone ) {
			wp_send_json_error( [ 'message' => __( 'Name, email and phone are all required.', 'pesa-donations' ) ], 422 );
		}
		if ( strlen( $name ) > 100 || strlen( $phone ) > 30 ) {
			wp_send_json_error( [ 'message' => __( 'Submission too long.', 'pesa-donations' ) ], 422 );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'pesa-donations' ) ], 422 );
		}

		// Resolve the destination address.
		$to = (string) get_option( 'pd_lead_form_email', '' );
		if ( ! $to ) {
			$to = (string) get_option( 'pd_admin_alert_email', '' );
		}
		if ( ! $to ) {
			$to = (string) get_bloginfo( 'admin_email' );
		}
		if ( ! $to || ! is_email( $to ) ) {
			\PesaDonations\Utils\Logger::error( 'Lead form: no destination email configured' );
			wp_send_json_error( [ 'message' => __( 'Lead destination email is not configured. Please contact the site administrator.', 'pesa-donations' ) ], 500 );
		}

		// Campaign context for the alert body.
		$campaign_title = '';
		if ( $campaign_id ) {
			$post = get_post( $campaign_id );
			if ( $post && \PesaDonations\CPT\Campaign_CPT::POST_TYPE === $post->post_type ) {
				$campaign_title = (string) $post->post_title;
			}
		}

		$site_name = (string) get_bloginfo( 'name' );

		// Concatenate (rather than sprintf %s) so a site whose name
		// contains a literal "%" character doesn't break the subject.
		$subject = __( 'New "Raise Hand for Charity" enquiry — ', 'pesa-donations' ) . $site_name;

		$body  = '<p>' . esc_html__( 'A new lead has been submitted from the donation page:', 'pesa-donations' ) . '</p>';
		$body .= '<table cellpadding="6" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">';
		$body .= '<tr><td><strong>' . esc_html__( 'Name', 'pesa-donations' )  . ':</strong></td><td>' . esc_html( $name )  . '</td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Email', 'pesa-donations' ) . ':</strong></td><td><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></td></tr>';
		$body .= '<tr><td><strong>' . esc_html__( 'Phone', 'pesa-donations' ) . ':</strong></td><td>' . esc_html( $phone ) . '</td></tr>';
		if ( $campaign_title ) {
			$body .= '<tr><td><strong>' . esc_html__( 'Campaign', 'pesa-donations' ) . ':</strong></td><td>' . esc_html( $campaign_title ) . '</td></tr>';
		}
		if ( $source_url ) {
			$body .= '<tr><td><strong>' . esc_html__( 'Page', 'pesa-donations' ) . ':</strong></td><td><a href="' . esc_url( $source_url ) . '">' . esc_html( $source_url ) . '</a></td></tr>';
		}
		$body .= '<tr><td><strong>' . esc_html__( 'Submitted', 'pesa-donations' ) . ':</strong></td><td>' . esc_html( current_time( 'mysql' ) ) . '</td></tr>';
		$body .= '</table>';

		// Build clean From + Reply-To headers. Strip CRLF defensively even
		// though the option-save path also strips it.
		$from_name  = str_replace( [ "\r", "\n" ], '', (string) get_option( 'pd_email_from_name',    $site_name ) );
		$from_email = str_replace( [ "\r", "\n" ], '', (string) get_option( 'pd_email_from_address', get_bloginfo( 'admin_email' ) ) );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
			'Reply-To: ' . $email,
		];

		// Capture the underlying SMTP error (if any) just for this send,
		// so log entries include a meaningful reason instead of just
		// "wp_mail returned false". Removed immediately after.
		$mail_error = null;
		$capture    = static function ( \WP_Error $err ) use ( &$mail_error ): void {
			$mail_error = $err;
		};
		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'wp_mail_failed', $capture );

		if ( ! $sent ) {
			\PesaDonations\Utils\Logger::error( 'Lead form: wp_mail failed', [
				'to'      => $to,
				'subject' => $subject,
				'reason'  => $mail_error ? $mail_error->get_error_message() : 'unknown',
				'data'    => $mail_error ? $mail_error->get_error_data()    : null,
			] );
			wp_send_json_error( [ 'message' => __( 'Could not send your message. Please try again or contact us directly.', 'pesa-donations' ) ], 500 );
		}

		wp_send_json_success( [
			'message' => __( 'Thanks! We received your details and will reach out soon.', 'pesa-donations' ),
		] );
	}

	/**
	 * Look up a returning donor by email or phone. By default this only
	 * returns a boolean "yes/no, we know you" so the form can hint that
	 * autofill is available — it does NOT echo the stored PII back, which
	 * would let an unauthenticated attacker enumerate donors and harvest
	 * names/phones. Sites that want autofill can opt in with the
	 * `pd_lookup_donor_return_pii` filter (then make sure your checkout is
	 * gated, e.g. behind a logged-in user). Per-IP rate-limited regardless.
	 */
	public function lookup_donor(): void {
		$this->verify_nonce();

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = Sanitizer::phone( $_POST['phone'] ?? '' );

		if ( ! $email && ! $phone ) {
			wp_send_json_success( null );
		}

		// Per-IP rate limit (20 lookups / 5 minutes). Stops bulk enumeration.
		$ip_key = 'pd_lookup_rl_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0' ) );
		$hits   = (int) get_transient( $ip_key );
		if ( $hits > 20 ) {
			wp_send_json_error( [ 'message' => __( 'Too many lookups. Please wait a few minutes.', 'pesa-donations' ) ], 429 );
		}
		set_transient( $ip_key, $hits + 1, 5 * MINUTE_IN_SECONDS );

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

		if ( ! $donor ) {
			wp_send_json_success( null );
		}

		// Default: no PII echoed back. The client just learns "we know you".
		if ( ! apply_filters( 'pd_lookup_donor_return_pii', false ) ) {
			wp_send_json_success( [ 'known' => true ] );
		}

		wp_send_json_success( $donor );
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
		$base     = $campaign->get_base_currency();

		// FX conversion isn't implemented yet — accept only the campaign's
		// base currency to avoid storing a bogus `amount_base` that would
		// throw off dashboard totals and goal progress.
		if ( $currency !== $base ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: base currency */
					__( 'This campaign only accepts donations in %s.', 'pesa-donations' ),
					$base
				),
			], 422 );
		}

		if ( $amount < $min ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: minimum amount with currency */
					__( 'Minimum donation is %s.', 'pesa-donations' ),
					number_format( $min ) . ' ' . $base
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
		$updates    = ! empty( $_POST['updates'] ) ? 1 : 0;
		// Organization branch — only honour the org_name if the donor
		// actually toggled the org switch on. Empties otherwise.
		$is_org     = ! empty( $_POST['is_org'] );
		$org_name   = $is_org ? sanitize_text_field( wp_unslash( $_POST['org_name'] ?? '' ) ) : '';

		if ( ! $email && ! $phone ) {
			wp_send_json_error( [ 'message' => __( 'Email or phone number is required.', 'pesa-donations' ) ], 422 );
		}

		$donor = Donor::get_or_create( $email ?: $phone . '@phone.pd', [
			'first_name'    => $first_name,
			'last_name'     => $last_name,
			'phone'         => $phone,
			'country'       => $country,
			'organization'  => $org_name,
			'wants_updates' => $updates,
		] );

		// Donor::get_or_create() returns id=0 on a hard insert failure
		// (table missing, unique-key race that can't be recovered). Don't
		// silently write an orphan donation pointing to a non-existent donor.
		$donor_id = $donor->get_id();
		if ( $donor_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Could not create donor record.', 'pesa-donations' ) ], 500 );
		}

		// Create the donation row.
		$donation_id = Donation::create( [
			'campaign_id'        => $campaign_id,
			'donor_id'           => $donor_id,
			'amount'             => $amount,
			'currency'           => $currency,
			'amount_base'        => $amount,  // FX conversion applied in gateway init.
			'gateway'            => $gateway,
			'donor_name'         => $anonymous ? '' : trim( $first_name . ' ' . $last_name ),
			'donor_organization' => $anonymous ? '' : $org_name,
			'donor_email'        => $email,
			'donor_phone'        => $phone,
			'donor_country'      => $country,
			'donor_ip'           => $this->get_ip(),
			'is_anonymous'       => $anonymous ? 1 : 0,
			'message'            => $message,
		] );

		if ( ! $donation_id ) {
			wp_send_json_error( [ 'message' => __( 'Could not create donation record.', 'pesa-donations' ) ], 500 );
		}

		$donation = Donation::get( $donation_id );

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

	/**
	 * Returns REMOTE_ADDR only — proxy headers like X-Forwarded-For are
	 * client-controllable on direct hits and would let any donor spoof their
	 * stored IP, defeating any rate-limit or fraud check that relies on it.
	 * Sites behind a trusted reverse proxy (Cloudflare, nginx) can opt in
	 * via the `pd_trust_proxy_headers` filter.
	 */
	private function get_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( apply_filters( 'pd_trust_proxy_headers', false ) ) {
			$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
				: '';
			if ( $xff ) {
				// Take the left-most entry: the original client per RFC 7239.
				$first = trim( explode( ',', $xff )[0] );
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					return $first;
				}
			}
		}

		return $remote;
	}
}
