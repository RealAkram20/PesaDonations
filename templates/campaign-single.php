<?php
/**
 * Single campaign display template.
 *
 * Variables: $campaign (Campaign), $layout (string)
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \PesaDonations\Models\Campaign $campaign */
?>
<div class="pd-campaign pd-campaign--<?php echo esc_attr( $layout ?? 'full' ); ?>">

	<?php if ( $campaign->get_thumbnail_url( 'large' ) ) : ?>
		<div class="pd-campaign__image-wrap">
			<img src="<?php echo esc_url( $campaign->get_thumbnail_url( 'large' ) ); ?>"
			     alt="<?php echo esc_attr( $campaign->get_title() ); ?>"
			     class="pd-campaign__image" />
		</div>
	<?php endif; ?>

	<div class="pd-campaign__body">
		<h2 class="pd-campaign__title"><?php echo esc_html( $campaign->get_title() ); ?></h2>

		<?php if ( $campaign->show_progress_bar() && $campaign->get_goal_amount() > 0 ) : ?>
			<div class="pd-progress-bar">
				<div class="pd-progress-bar__fill" style="width:<?php echo esc_attr( $campaign->get_progress_percent() ); ?>%"></div>
			</div>
			<div class="pd-progress-stats">
				<span class="pd-raised"><?php echo esc_html( number_format( $campaign->get_raised_amount() ) . ' ' . $campaign->get_base_currency() ); ?></span>
				<span><?php esc_html_e( 'raised of', 'pesa-donations' ); ?></span>
				<span class="pd-goal"><?php echo esc_html( number_format( $campaign->get_goal_amount() ) . ' ' . $campaign->get_base_currency() ); ?></span>
				<span class="pd-progress-pct">(<?php echo esc_html( $campaign->get_progress_percent() . '%' ); ?>)</span>
			</div>
		<?php endif; ?>

		<?php if ( $campaign->show_donor_count() ) : ?>
			<p class="pd-donor-count">
				<?php echo esc_html( sprintf(
					_n( '%d donor', '%d donors', $campaign->get_donor_count(), 'pesa-donations' ),
					$campaign->get_donor_count()
				) ); ?>
			</p>
		<?php endif; ?>

		<div class="pd-campaign__content">
			<?php echo wp_kses_post( $campaign->get_content() ); ?>
		</div>

		<div class="pd-campaign__cta">
			<a href="<?php echo esc_url( $campaign->get_checkout_url() ); ?>" class="pd-btn pd-btn--primary pd-btn--lg">
				<?php esc_html_e( 'Donate Now', 'pesa-donations' ); ?>
			</a>
		</div>
	</div>
</div>
