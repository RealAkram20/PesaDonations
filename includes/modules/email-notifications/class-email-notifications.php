<?php
declare( strict_types=1 );

namespace PesaDonations\Modules\Email_Notifications;

use PesaDonations\Models\Donation;
use PesaDonations\Models\Campaign;
use PesaDonations\Models\Campaign_Period;
use PesaDonations\Models\Open_Donation;
use PesaDonations\Modules\Campaign_Cycles\Campaign_Cycles;
use PesaDonations\Utils\Logger;
use PesaDonations\Utils\Money;

/**
 * Sends transactional emails when donations change state.
 * Listens for pd_donation_completed and pd_donation_failed action hooks.
 */
class Email_Notifications {

	public function register(): void {
		// Donation::transition() fires these once per real change, with a context.
		add_action( 'pd_donation_completed', [ $this, 'on_completed' ], 10, 2 );
		add_action( 'pd_donation_failed',    [ $this, 'on_failed' ],    10, 2 );
		add_action( 'pd_donation_reversed',  [ $this, 'on_reversed' ],  10, 2 );
	}

	// -------------------------------------------------------------------------
	// Listeners
	// -------------------------------------------------------------------------

	/** @param array $context source (ipn, callback, reconcile, admin), notify (email the donor) */
	public function on_completed( Donation $donation, $context = [] ): void {
		$context = is_array( $context ) ? $context : [];
		// Null for an open donation (campaign 0); the templates name it Open_Donation::label().
		$campaign = Campaign::get( $donation->get_campaign_id() );
		if ( ! $campaign && Open_Donation::CAMPAIGN_ID !== $donation->get_campaign_id() ) {
			return;
		}

		// Donor receipt, unless whoever completed it said not to.
		if ( $donation->get_donor_email() && false !== ( $context['notify'] ?? true ) ) {
			$this->send_donor_receipt( $donation, $campaign );
		}

		// Admin alert (per-site setting, default on); not for an admin's own edit.
		if ( '1' !== (string) get_option( 'pd_admin_alert_disabled', '0' ) && 'admin' !== ( $context['source'] ?? '' ) ) {
			$this->send_admin_alert( $donation, $campaign );
		}
	}

	public function on_failed( Donation $donation, $context = [] ): void {
		$context = is_array( $context ) ? $context : [];
		// Optional: notify admin of failed donations.
		if ( '1' === (string) get_option( 'pd_email_failed_alerts', '0' ) && 'admin' !== ( $context['source'] ?? '' ) ) {
			$campaign = Campaign::get( $donation->get_campaign_id() );
			if ( $campaign || Open_Donation::CAMPAIGN_ID === $donation->get_campaign_id() ) {
				$this->send_admin_failure_alert( $donation, $campaign );
			}
		}
	}

	/** Money that arrived and left again: always worth an admin's attention. */
	public function on_reversed( Donation $donation, $context = [] ): void {
		$context = is_array( $context ) ? $context : [];
		if ( 'admin' === ( $context['source'] ?? '' ) ) {
			return;
		}
		$campaign = Campaign::get( $donation->get_campaign_id() );
		$subject  = $this->test_prefix( $donation ) . sprintf(
			/* translators: 1: amount with currency, 2: campaign title */
			__( 'Donation reversed: %1$s to %2$s', 'pesa-donations' ),
			Money::format( $donation->get_amount(), $donation->get_currency() ),
			$this->target_name( $campaign )
		);
		$body = '<p>' . sprintf(
			/* translators: %s: reference */
			esc_html__( 'PesaPal reports that this payment was reversed. It no longer counts toward any total. Reference: %s', 'pesa-donations' ),
			'<code>' . esc_html( $donation->get_merchant_reference() ) . '</code>'
		) . '</p>';
		$this->mail( $this->admin_email(), $subject, $body );
	}

	/** Sandbox payments are test money; their emails say so. */
	private function test_prefix( Donation $donation ): string {
		return $donation->is_test() ? '[TEST] ' : '';
	}

	/**
	 * "A new period has started": sent by Campaign_Cycles to each donor of the
	 * period that just closed.
	 */
	public function send_cycle_reminder( Campaign $campaign, Campaign_Period $period, Campaign_Period $previous, string $to, string $donor_name ): bool {
		$campaign_name = $this->target_name( $campaign );
		$subject       = $period->has_started( $campaign->get_schedule()->today() )
			? sprintf(
				/* translators: 1: campaign name, 2: period label, e.g. Term 2 2026 */
				__( '%1$s: %2$s has begun', 'pesa-donations' ),
				$campaign_name,
				$period->get_label()
			)
			: sprintf(
				/* translators: 1: campaign name, 2: period label, 3: its first day */
				__( '%1$s: %2$s starts on %3$s', 'pesa-donations' ),
				$campaign_name,
				$period->get_label(),
				Campaign_Period::format_day( $period->get_start() )
			);
		$opt_out_url = Campaign_Cycles::opt_out_url( $to );

		$body = $this->render_template( 'cycle-reminder', compact( 'campaign', 'campaign_name', 'period', 'previous', 'donor_name', 'opt_out_url' ) );
		// One-click unsubscribe (RFC 8058), which Gmail and Yahoo expect of bulk
		// mail: the mail client POSTs to the same signed link the email shows.
		return $this->mail( $to, $subject, $body, [
			'List-Unsubscribe: <' . esc_url_raw( $opt_out_url ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		] );
	}

	// -------------------------------------------------------------------------
	// Senders
	// -------------------------------------------------------------------------

	private function send_donor_receipt( Donation $donation, ?Campaign $campaign ): void {
		$to      = $donation->get_donor_email();
		$subject = $this->test_prefix( $donation ) . sprintf(
			/* translators: %s: site name */
			__( 'Your donation receipt — %s', 'pesa-donations' ),
			get_bloginfo( 'name' )
		);

		$body = $this->render_template( 'donor-receipt', compact( 'donation', 'campaign' ) );
		$this->mail( $to, $subject, $body );
	}

	private function send_admin_alert( Donation $donation, ?Campaign $campaign ): void {
		$to      = $this->admin_email();
		$subject = $this->test_prefix( $donation ) . sprintf(
			/* translators: 1: amount with currency, 2: campaign title */
			__( 'New donation: %1$s to %2$s', 'pesa-donations' ),
			Money::format( $donation->get_amount(), $donation->get_currency() ),
			$this->target_name( $campaign )
		);

		$body = $this->render_template( 'admin-alert', compact( 'donation', 'campaign' ) );
		$this->mail( $to, $subject, $body );
	}

	private function send_admin_failure_alert( Donation $donation, ?Campaign $campaign ): void {
		$to      = $this->admin_email();
		$subject = $this->test_prefix( $donation ) . sprintf(
			__( 'Failed donation: %1$s to %2$s', 'pesa-donations' ),
			Money::format( $donation->get_amount(), $donation->get_currency() ),
			$this->target_name( $campaign )
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

	private function mail( string $to, string $subject, string $body, array $extra_headers = [] ): bool {
		if ( ! $to ) {
			return false;
		}

		$from_name  = (string) get_option( 'pd_email_from_name',    get_bloginfo( 'name' ) );
		$from_email = (string) get_option( 'pd_email_from_address', get_bloginfo( 'admin_email' ) );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		// A blank or broken From made PHPMailer refuse every email. Without a
		// valid one, WordPress's own From is used. The name is quoted so a comma
		// or a quote in it cannot break the header.
		if ( is_email( $from_email ) ) {
			$name      = trim( str_replace( [ '"', "\r", "\n" ], '', wp_specialchars_decode( $from_name, ENT_QUOTES ) ) );
			$headers[] = '' !== $name ? sprintf( 'From: "%s" <%s>', $name, $from_email ) : 'From: ' . $from_email;
		}
		$headers = array_merge( $headers, $extra_headers );

		$sent = wp_mail( $to, $subject, $this->wrap( $subject, $body ), $headers );

		if ( ! $sent ) {
			// The address is masked: log files are not a place for donors' emails.
			Logger::error( 'Email send failed', [ 'to' => substr( $to, 0, 2 ) . '***' . strstr( $to, '@' ), 'subject' => $subject ] );
		}

		return (bool) $sent;
	}

	/** Beneficiary or campaign title; the open donation label when there is no campaign. */
	private function target_name( ?Campaign $campaign ): string {
		if ( ! $campaign ) {
			return Open_Donation::label();
		}
		return $campaign->get_beneficiary_name() ?: $campaign->get_title();
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
		<?php echo $body; // already escaped by template ?>
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
