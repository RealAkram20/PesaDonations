<?php
/**
 * Donor receipt email body.
 * Variables: $donation (Donation), $campaign (Campaign)
 */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** @var \PesaDonations\Models\Donation $donation */
/** @var \PesaDonations\Models\Campaign $campaign */

$donor_name    = $donation->get_donor_name() ?: __( 'Friend', 'pesa-donations' );
$campaign_name = $campaign->get_beneficiary_name() ?: $campaign->get_title();
$amount_text   = number_format( $donation->get_amount(), 2 ) . ' ' . esc_html( $donation->get_currency() );
$date_text     = mysql2date( get_option( 'date_format', 'F j, Y' ), $donation->get_completed_at() ?: current_time( 'mysql' ) );
$reference     = $donation->get_merchant_reference();
?>

<h2 style="margin:0 0 12px;font-size:22px;color:#222;">
	<?php
	echo esc_html( sprintf(
		/* translators: %s: donor name */
		__( 'Thank you, %s', 'pesa-donations' ),
		$donor_name
	) );
	?>
</h2>

<p style="margin:0 0 18px;color:#555;">
	<?php
	echo esc_html( sprintf(
		/* translators: %s: campaign / beneficiary */
		__( 'Your generous contribution to %s has been received. Below is a copy of your receipt for your records.', 'pesa-donations' ),
		$campaign_name
	) );
	?>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border-radius:6px;padding:18px;margin:18px 0;">
	<tr>
		<td style="font-size:13px;color:#888;text-transform:uppercase;letter-spacing:1px;padding-bottom:6px;">
			<?php esc_html_e( 'Donation Amount', 'pesa-donations' ); ?>
		</td>
	</tr>
	<tr>
		<td style="font-size:32px;font-weight:800;color:#222;letter-spacing:-.5px;padding-bottom:16px;">
			<?php echo esc_html( $amount_text ); ?>
		</td>
	</tr>
	<tr>
		<td>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;border-top:1px solid #eee;padding-top:12px;">
				<tr>
					<td style="padding:4px 0;color:#888;"><?php esc_html_e( 'Date', 'pesa-donations' ); ?></td>
					<td style="padding:4px 0;text-align:right;color:#222;"><?php echo esc_html( $date_text ); ?></td>
				</tr>
				<tr>
					<td style="padding:4px 0;color:#888;"><?php esc_html_e( 'Campaign', 'pesa-donations' ); ?></td>
					<td style="padding:4px 0;text-align:right;color:#222;"><?php echo esc_html( $campaign_name ); ?></td>
				</tr>
				<tr>
					<td style="padding:4px 0;color:#888;"><?php esc_html_e( 'Payment Method', 'pesa-donations' ); ?></td>
					<td style="padding:4px 0;text-align:right;color:#222;"><?php echo esc_html( ucfirst( $donation->get_gateway() ) ); ?></td>
				</tr>
				<tr>
					<td style="padding:4px 0;color:#888;"><?php esc_html_e( 'Reference', 'pesa-donations' ); ?></td>
					<td style="padding:4px 0;text-align:right;font-family:monospace;color:#222;"><?php echo esc_html( $reference ); ?></td>
				</tr>
			</table>
		</td>
	</tr>
</table>

<p style="margin:0 0 18px;color:#555;">
	<?php esc_html_e( 'Your support makes real change possible — thank you for being part of it.', 'pesa-donations' ); ?>
</p>

<p style="margin:18px 0 0;color:#888;font-size:13px;">
	<?php
	echo esc_html( sprintf(
		/* translators: %s: site name */
		__( 'Warmly,<br>The %s Team', 'pesa-donations' ),
		get_bloginfo( 'name' )
	) );
	?>
</p>
