<?php
declare( strict_types=1 );

namespace PesaDonations\Modules\Email_Notifications;

use PesaDonations\Models\Donation;
use PesaDonations\Models\Campaign;
use PesaDonations\Utils\Logger;

/**
 * Sends transactional emails when donations change state.
 * Listens for pd_donation_completed and pd_donation_failed action hooks.
 */
class Email_Notifications {

	public function register(): void {
		add_action( 'pd_donation_completed', [ $this, 'on_completed' ], 10, 1 );
		add_action( 'pd_donation_failed',    [ $this, 'on_failed' ],    10, 1 );
	}

	// -------------------------------------------------------------------------
	// Listeners
	// -------------------------------------------------------------------------

	public function on_completed( Donation $donation ): void {
		$campaign = Campaign::get( $donation->get_campaign_id() );
		if ( ! $campaign ) {
			return;
		}

		// Donor receipt — always sent if donor has an email.
		if ( $donation->get_donor_email() ) {
			$this->send_donor_receipt( $donation, $campaign );
		}

		// Admin alert — controlled by per-site setting (default on).
		if ( '1' !== (string) get_option( 'pd_admin_alert_disabled', '0' ) ) {
			$this->send_admin_alert( $donation, $campaign );
		}
	}

	public function on_failed( Donation $donation ): void {
		// Optional: notify admin of failed donations.
		if ( '1' === (string) get_option( 'pd_email_failed_alerts', '0' ) ) {
			$campaign = Campaign::get( $donation->get_campaign_id() );
			if ( $campaign ) {
				$this->send_admin_failure_alert( $donation, $campaign );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Senders
	// -------------------------------------------------------------------------

	private function send_donor_receipt( Donation $donation, Campaign $campaign ): void {
		$to      = $donation->get_donor_email();
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your donation receipt — %s', 'pesa-donations' ),
			get_bloginfo( 'name' )
		);

		$body = $this->render_template( 'donor-receipt', compact( 'donation', 'campaign' ) );
		$this->mail( $to, $subject, $body );
	}

	private function send_admin_alert( Donation $donation, Campaign $campaign ): void {
		$to      = $this->admin_email();
		$subject = sprintf(
			/* translators: 1: amount with currency, 2: campaign title */
			__( 'New donation: %1$s to %2$s', 'pesa-donations' ),
			number_format( $donation->get_amount(), 2 ) . ' ' . $donation->get_currency(),
			$campaign->get_beneficiary_name() ?: $campaign->get_title()
		);

		$body = $this->render_template( 'admin-alert', compact( 'donation', 'campaign' ) );
		$this->mail( $to, $subject, $body );
	}

	private function send_admin_failure_alert( Donation $donation, Campaign $campaign ): void {
		$to      = $this->admin_email();
		$subject = sprintf(
			__( 'Failed donation: %1$s to %2$s', 'pesa-donations' ),
			number_format( $donation->get_amount(), 2 ) . ' ' . $donation->get_currency(),
			$campaign->get_beneficiary_name() ?: $campaign->get_title()
		);

		$body = '<p>' . sprintf(
			__( 'A donation attempt failed. Reference: %s', 'pesa-donations' ),
			'<code>' . esc_html( $donation->get_merchant_reference() ) . '</code>'
		) . '</p>';

		$this->mail( $to, $subject, $body );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function mail( string $to, string $subject, string $body ): bool {
		if ( ! $to ) {
			return false;
		}

		$from_name  = (string) get_option( 'pd_email_from_name',    get_bloginfo( 'name' ) );
		$from_email = (string) get_option( 'pd_email_from_address', get_bloginfo( 'admin_email' ) );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		];

		$sent = wp_mail( $to, $subject, $this->wrap( $subject, $body ), $headers );

		if ( ! $sent ) {
			Logger::error( 'Email send failed', [ 'to' => $to, 'subject' => $subject ] );
		}

		return (bool) $sent;
	}

	private function admin_email(): string {
		return (string) ( get_option( 'pd_admin_alert_email' ) ?: get_bloginfo( 'admin_email' ) );
	}

	/**
	 * Loads a template file from theme override (preferred) or plugin default.
	 * Theme path: /wp-content/themes/{theme}/pesa-donations/emails/{name}.php
	 */
	private function render_template( string $name, array $vars ): string {
		$theme_file  = get_stylesheet_directory() . '/pesa-donations/emails/' . $name . '.php';
		$plugin_file = PD_PLUGIN_DIR . 'templates/emails/' . $name . '.php';
		$file        = file_exists( $theme_file ) ? $theme_file : $plugin_file;

		if ( ! file_exists( $file ) ) {
			return '';
		}

		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Wraps the email body content in a minimal branded HTML shell with
	 * a header (site name) + body + footer (site URL).
	 */
	private function wrap( string $title, string $body ): string {
		$site       = get_bloginfo( 'name' );
		$site_url   = home_url( '/' );
		$footer     = (string) get_option( 'pd_email_footer', '' );
		$brand_hex  = (string) get_option( 'pd_brand_color', '#e94e4e' );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $brand_hex ) ) {
			$brand_hex = '#e94e4e';
		}

		ob_start();
		?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?php echo esc_html( $title ); ?></title></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#222;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;background:#f5f5f5;">
  <tr><td align="center">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
	  <tr><td style="background:<?php echo esc_attr( $brand_hex ); ?>;padding:20px 28px;color:#fff;">
		<a href="<?php echo esc_url( $site_url ); ?>" style="color:#fff;text-decoration:none;font-size:18px;font-weight:700;letter-spacing:.4px;"><?php echo esc_html( $site ); ?></a>
	  </td></tr>
	  <tr><td style="padding:28px;color:#333;font-size:15px;line-height:1.6;">
		<?php echo wp_kses_post( $body ); ?>
	  </td></tr>
	  <tr><td style="background:#fafafa;padding:18px 28px;color:#777;font-size:12px;text-align:center;border-top:1px solid #eee;">
		<?php if ( $footer ) : ?>
			<?php echo wp_kses_post( wpautop( $footer ) ); ?>
		<?php else : ?>
			<a href="<?php echo esc_url( $site_url ); ?>" style="color:#777;"><?php echo esc_html( wp_parse_url( $site_url, PHP_URL_HOST ) ); ?></a>
		<?php endif; ?>
	  </td></tr>
	</table>
  </td></tr>
</table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
