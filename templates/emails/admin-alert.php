<?php
/**
 * Admin alert email body — sent when a donation is completed.
 * Variables: $donation (Donation), $campaign (Campaign)
 */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** @var \PesaDonations\Models\Donation $donation */
/** @var \PesaDonations\Models\Campaign $campaign */

$campaign_name = $campaign->get_beneficiary_name() ?: $campaign->get_title();
$amount_text   = number_format( $donation->get_amount(), 2 ) . ' ' . esc_html( $donation->get_currency() );
$donor_name    = $donation->get_donor_name() ?: __( '(no name)', 'pesa-donations' );
$donor_email   = $donation->get_donor_email() ?: __( '(no email)', 'pesa-donations' );
$edit_url      = admin_url( 'admin.php?page=pd-donation-edit&id=' . $donation->get_id() );
?>

<h2 style="margin:0 0 8px;font-size:20px;color:#222;">
	<?php esc_html_e( 'New donation received', 'pesa-donations' ); ?>
</h2>

<p style="margin:0 0 18px;color:#555;font-size:14px;">
	<?php
	echo esc_html( sprintf(
		__( 'A donation just came in for %s.', 'pesa-donations' ),
		$campaign_name
	) );
	?>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border-radius:6px;padding:16px;margin:0 0 20px;font-size:14px;color:#222;">
	<tr><td style="padding:6px 0;color:#888;width:130px;"><?php esc_html_e( 'Amount', 'pesa-donations' ); ?></td>
		<td style="padding:6px 0;font-weight:700;font-size:18px;"><?php echo esc_html( $amount_text ); ?></td></tr>
	<tr><td style="padding:6px 0;color:#888;"><?php esc_html_e( 'Donor', 'pesa-donations' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( $donor_name ); ?></td></tr>
	<tr><td style="padding:6px 0;color:#888;"><?php esc_html_e( 'Email', 'pesa-donations' ); ?></td>
		<td style="padding:6px 0;"><a href="mailto:<?php echo esc_attr( $donor_email ); ?>" style="color:#0073aa;"><?php echo esc_html( $donor_email ); ?></a></td></tr>
	<tr><td style="padding:6px 0;color:#888;"><?php esc_html_e( 'Campaign', 'pesa-donations' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( $campaign_name ); ?></td></tr>
	<tr><td style="padding:6px 0;color:#888;"><?php esc_html_e( 'Gateway', 'pesa-donations' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( ucfirst( $donation->get_gateway() ) ); ?></td></tr>
	<tr><td style="padding:6px 0;color:#888;"><?php esc_html_e( 'Reference', 'pesa-donations' ); ?></td>
		<td style="padding:6px 0;font-family:monospace;font-size:12px;"><?php echo esc_html( $donation->get_merchant_reference() ); ?></td></tr>
</table>

<p style="margin:0;text-align:center;">
	<a href="<?php echo esc_url( $edit_url ); ?>"
	   style="display:inline-block;background:#222;color:#fff;text-decoration:none;padding:10px 22px;border-radius:6px;font-size:14px;font-weight:600;">
		<?php esc_html_e( 'View in Admin', 'pesa-donations' ); ?>
	</a>
</p>
