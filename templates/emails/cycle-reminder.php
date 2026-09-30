<?php
/**
 * "A new period has started" email, sent to each donor of the period that just closed.
 * Variables: $campaign (Campaign), $campaign_name (string), $period (Campaign_Period, the new one),
 *            $previous (Campaign_Period), $donor_name (string), $opt_out_url (string)
 *
 * Theme override: /wp-content/themes/{theme}/pesa-donations/emails/cycle-reminder.php
 */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** @var \PesaDonations\Models\Campaign $campaign */
/** @var \PesaDonations\Models\Campaign_Period $period */
/** @var \PesaDonations\Models\Campaign_Period $previous */

$greeting_name = $donor_name ?: __( 'Friend', 'pesa-donations' );
$started       = $period->has_started( $campaign->get_schedule()->today() );
$goal          = $campaign->get_goal_amount();
$brand_hex     = (string) get_option( 'pd_brand_color', '#e94e4e' );
if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $brand_hex ) ) {
	$brand_hex = '#e94e4e';
}
?>

<h2 style="margin:0 0 12px;font-size:22px;color:#222;">
	<?php
	echo esc_html( sprintf(
		/* translators: %s: donor name */
		__( 'Hello %s,', 'pesa-donations' ),
		$greeting_name
	) );
	?>
</h2>

<p style="margin:0 0 14px;color:#555;">
	<?php
	echo esc_html( sprintf(
		/* translators: 1: campaign name, 2: previous period, e.g. Term 1 2026 */
		__( 'Thank you for supporting %1$s during %2$s. Your gift made a real difference.', 'pesa-donations' ),
		$campaign_name,
		$previous->get_label()
	) );
	?>
</p>

<p style="margin:0 0 14px;color:#555;">
	<?php
	if ( $started ) {
		echo esc_html( sprintf(
			/* translators: 1: new period, 2: its last day */
			__( '%1$s has now begun and runs until %2$s.', 'pesa-donations' ),
			$period->get_label(),
			\PesaDonations\Models\Campaign_Period::format_day( $period->get_end() )
		) );
	} else {
		echo esc_html( sprintf(
			/* translators: 1: next period, 2: its first day */
			__( '%1$s starts on %2$s, and gifts made now count toward it.', 'pesa-donations' ),
			$period->get_label(),
			\PesaDonations\Models\Campaign_Period::format_day( $period->get_start() )
		) );
	}
	if ( $goal > 0 ) {
		echo ' ' . esc_html( sprintf(
			/* translators: %s: goal amount with currency */
			__( 'This period we are raising %s.', 'pesa-donations' ),
			number_format( $goal ) . ' ' . $campaign->get_base_currency()
		) );
	}
	?>
</p>

<p style="margin:24px 0;text-align:center;">
	<a href="<?php echo esc_url( $campaign->get_checkout_url() ); ?>"
	   style="display:inline-block;background:<?php echo esc_attr( $brand_hex ); ?>;color:#fff;text-decoration:none;padding:12px 28px;border-radius:999px;font-size:15px;font-weight:700;">
		<?php esc_html_e( 'Give again', 'pesa-donations' ); ?>
	</a>
</p>

<p style="margin:24px 0 0;color:#999;font-size:12px;line-height:1.5;">
	<?php
	echo esc_html( sprintf(
		/* translators: 1: campaign name, 2: previous period */
		__( 'You are receiving this because you gave to %1$s during %2$s.', 'pesa-donations' ),
		$campaign_name,
		$previous->get_label()
	) );
	?>
	<a href="<?php echo esc_url( $opt_out_url ); ?>" style="color:#999;"><?php esc_html_e( 'Stop these reminders', 'pesa-donations' ); ?></a>
</p>
