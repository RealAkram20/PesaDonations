<?php
/**
 * Single donation campaign template.
 *
 * Loaded automatically (via the `single_template` filter in PD_Public)
 * for pd_campaign posts whose _pd_category is project-like. Sponsorship
 * campaigns continue to use the theme's standard single template.
 *
 * Theme override: copy this file to
 *   /wp-content/themes/{theme}/pesa-donations/single-donation.php
 *
 * Variables available:
 *   $campaign  PesaDonations\Models\Campaign
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PesaDonations\Models\Campaign;
use PesaDonations\CPT\Campaign_CPT;

/** @var \PesaDonations\Models\Campaign|null $campaign */
$campaign = $GLOBALS['pd_donation_campaign'] ?? null;
if ( ! $campaign instanceof Campaign ) {
	// Falling back to constructing from the queried object so the
	// template still works if someone overrides single_template upstream.
	$campaign = Campaign::get( (int) get_queried_object_id() );
}
if ( ! $campaign ) {
	get_header();
	echo '<div class="pd-donation-single"><div class="pd-donation-single__container"><main class="pd-donation-single__main">';
	echo '<h1>' . esc_html__( 'Campaign not found.', 'pesa-donations' ) . '</h1>';
	echo '</main></div></div>';
	get_footer();
	return;
}

$goal       = $campaign->get_goal_amount();
$raised     = $campaign->get_raised_amount();
$progress   = $goal > 0 ? min( 100, (int) round( ( $raised / $goal ) * 100 ) ) : 0;
$currency   = $campaign->get_base_currency();
$gallery    = $campaign->get_gallery_images( 'large' );
$goals_list = $campaign->get_main_goals();

// "Visible" gallery thumbs in the inline grid: cap at 4. The lightbox
// reveals every image. We pass the FULL gallery into Alpine so prev/next
// can cycle through them all.
$gallery_visible = array_slice( $gallery, 0, 4 );
$gallery_extra   = max( 0, count( $gallery ) - count( $gallery_visible ) );

// JSON config for the gallery lightbox component. Pre-encode here so
// esc_attr is the only escaping the template needs.
$gallery_json = wp_json_encode( array_map(
	static fn ( array $img ): array => [
		'id'    => $img['id'],
		'thumb' => $img['thumb'],
		'full'  => $img['full'],
		'alt'   => $img['alt'],
	],
	$gallery
) );

$campaign_type_label = (string) get_post_meta( $campaign->get_id(), '_pd_category', true );
$campaign_type_label = ucwords( str_replace( [ '_', '-' ], ' ', $campaign_type_label ) ) ?: __( 'Campaign', 'pesa-donations' );

// Pull a few related project campaigns for the "More campaign" sidebar.
$related = get_posts( [
	'post_type'      => Campaign_CPT::POST_TYPE,
	'posts_per_page' => 3,
	'post__not_in'   => [ $campaign->get_id() ],
	'orderby'        => 'rand',
	'meta_query'     => [
		[
			'key'     => '_pd_category',
			'value'   => [ 'project', 'school', 'hospital', 'medical', 'other' ],
			'compare' => 'IN',
		],
	],
] );

get_header();
?>

<div class="pd-donation-single"
     x-data="pdDonationGallery(<?php echo esc_attr( $gallery_json ); ?>)"
     x-init="init()">
	<div class="pd-donation-single__container">

		<main class="pd-donation-single__main">

			<?php
			// Find the back-link target. Priority:
			//   1. Page already configured for [pd_give_browse]
			//   2. CPT archive (if has_archive ever flipped to true)
			//   3. None — hide the link rather than dump donors at "/".
			$back_link  = '';
			$back_pages = get_posts( [
				'post_type'      => 'page',
				'posts_per_page' => 1,
				's'              => '[pd_give_browse',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );
			if ( ! empty( $back_pages ) ) {
				$back_link = (string) get_permalink( (int) $back_pages[0] );
			} elseif ( $arch = get_post_type_archive_link( Campaign_CPT::POST_TYPE ) ) {
				$back_link = (string) $arch;
			}
			?>
			<?php if ( $back_link ) : ?>
				<a href="<?php echo esc_url( $back_link ); ?>" class="pd-donation-single__back">
					<span aria-hidden="true">&larr;</span>
					<?php esc_html_e( 'All campaigns', 'pesa-donations' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $campaign->get_thumbnail_url( 'large' ) ) : ?>
				<div class="pd-donation-single__hero">
					<img src="<?php echo esc_url( $campaign->get_thumbnail_url( 'large' ) ); ?>"
					     alt="<?php echo esc_attr( $campaign->get_title() ); ?>" />
				</div>
			<?php endif; ?>

			<div class="pd-donation-single__type-tag">
				<?php echo esc_html( $campaign_type_label ); ?>
			</div>

			<h1 class="pd-donation-single__title"><?php echo esc_html( $campaign->get_title() ); ?></h1>

			<?php if ( $goal > 0 ) : ?>
				<div class="pd-donation-single__progress">
					<span class="pd-donation-single__progress-pill">
						<?php esc_html_e( 'Raised Funds', 'pesa-donations' ); ?>
						<strong><?php echo esc_html( $progress . '%' ); ?></strong>
					</span>
					<div class="pd-donation-single__progress-bar"
					     role="progressbar"
					     aria-valuemin="0"
					     aria-valuemax="100"
					     aria-valuenow="<?php echo esc_attr( (string) $progress ); ?>">
						<div class="pd-donation-single__progress-fill" style="--pd-progress: <?php echo esc_attr( (string) $progress ); ?>%"></div>
					</div>
					<p class="pd-donation-single__progress-meta">
						<span class="pd-donation-single__progress-pct"><?php echo esc_html( $progress . '%' ); ?>
							<span class="pd-donation-single__progress-suffix"><?php esc_html_e( 'Raised', 'pesa-donations' ); ?></span>
						</span>
						<span class="pd-donation-single__progress-sep">|</span>
						<span class="pd-donation-single__progress-goal"><?php echo esc_html( number_format( $goal ) . ' ' . $currency ); ?>
							<span class="pd-donation-single__progress-goal-label">(<?php esc_html_e( 'Goal', 'pesa-donations' ); ?>)</span>
						</span>
					</p>
				</div>
			<?php endif; ?>

			<div class="pd-donation-single__content">
				<?php
				// Campaign::get_content() already applies the_content
				// filter — applying it again here ran every shortcode,
				// embed, and wpautop pass twice.
				echo $campaign->get_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>

			<?php if ( ! empty( $gallery_visible ) ) : ?>
				<div class="pd-donation-single__gallery"
				     data-count="<?php echo esc_attr( (string) count( $gallery_visible ) ); ?>">
					<?php foreach ( $gallery_visible as $i => $img ) :
						$is_last_with_overflow = ( $i === count( $gallery_visible ) - 1 ) && $gallery_extra > 0;
					?>
						<button type="button"
						        class="pd-donation-single__gallery-item<?php echo $is_last_with_overflow ? ' pd-donation-single__gallery-item--overflow' : ''; ?>"
						        @click="openLightbox(<?php echo (int) $i; ?>)"
						        aria-label="<?php
									/* translators: %d: image index, starting at 1 */
									echo esc_attr( sprintf( __( 'View image %d', 'pesa-donations' ), $i + 1 ) );
								?>">
							<img src="<?php echo esc_url( $img['thumb'] ); ?>"
							     alt="<?php echo esc_attr( $img['alt'] ); ?>"
							     loading="lazy" />
							<?php if ( $is_last_with_overflow ) : ?>
								<span class="pd-donation-single__gallery-overflow">
									<?php
									/* translators: %d: number of additional images */
									echo esc_html( sprintf( '+%d more', $gallery_extra ) );
									?>
								</span>
							<?php endif; ?>
							<span class="pd-donation-single__gallery-zoom" aria-hidden="true">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
									<circle cx="11" cy="11" r="7" />
									<line x1="21" y1="21" x2="16.65" y2="16.65" />
									<line x1="11" y1="8" x2="11" y2="14" />
									<line x1="8" y1="11" x2="14" y2="11" />
								</svg>
							</span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $goals_list ) ) : ?>
				<div class="pd-donation-single__goals">
					<h2 class="pd-donation-single__goals-title"><?php esc_html_e( 'Our main goals', 'pesa-donations' ); ?></h2>
					<?php if ( $campaign->get_excerpt() ) : ?>
						<p class="pd-donation-single__goals-intro"><?php echo esc_html( $campaign->get_excerpt() ); ?></p>
					<?php endif; ?>
					<ul class="pd-donation-single__goals-list">
						<?php foreach ( $goals_list as $g ) : ?>
							<li>
								<span class="pd-donation-single__goals-check" aria-hidden="true">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
										<polyline points="20 6 9 17 4 12" />
									</svg>
								</span>
								<?php echo esc_html( $g ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

		</main>

		<aside class="pd-donation-single__sidebar">

			<!-- Sidebar card 1: Donate CTA -->
			<div class="pd-side-card pd-side-card--cta">
				<h3 class="pd-side-card__title"><?php esc_html_e( 'Join together for charity', 'pesa-donations' ); ?></h3>
				<p class="pd-side-card__text">
					<?php
					echo esc_html(
						$campaign->get_excerpt()
							? wp_trim_words( $campaign->get_excerpt(), 24 )
							: __( 'Every contribution moves us closer to our goal. Donate today and help make a real difference.', 'pesa-donations' )
					);
					?>
				</p>
				<a href="<?php echo esc_url( $campaign->get_checkout_url() ); ?>" class="pd-btn pd-btn--primary pd-side-card__cta">
					<?php esc_html_e( 'Donate Now', 'pesa-donations' ); ?>
				</a>
			</div>

			<!-- Sidebar card 2: More campaigns -->
			<?php if ( ! empty( $related ) ) : ?>
				<div class="pd-side-card pd-side-card--more">
					<div class="pd-side-card__header">
						<span class="pd-side-card__pill"><?php esc_html_e( 'More campaign', 'pesa-donations' ); ?></span>
					</div>
					<ul class="pd-side-card__list">
						<?php foreach ( $related as $r_post ) :
							$r_camp = Campaign::get( $r_post->ID );
							if ( ! $r_camp ) {
								continue;
							}
							$r_thumb = $r_camp->get_thumbnail_url( 'thumbnail' );
							$r_link  = get_permalink( $r_post->ID );
							?>
							<li class="pd-side-card__list-item">
								<?php if ( $r_thumb ) : ?>
									<a href="<?php echo esc_url( $r_link ); ?>" class="pd-side-card__list-thumb">
										<img src="<?php echo esc_url( $r_thumb ); ?>" alt="<?php echo esc_attr( $r_camp->get_title() ); ?>" loading="lazy" />
									</a>
								<?php endif; ?>
								<div class="pd-side-card__list-body">
									<a href="<?php echo esc_url( $r_link ); ?>" class="pd-side-card__list-title">
										<?php echo esc_html( $r_camp->get_title() ); ?>
									</a>
									<p class="pd-side-card__list-excerpt">
										<?php echo esc_html( wp_trim_words( $r_camp->get_excerpt(), 8 ) ); ?>
									</p>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<!-- Sidebar card 3: Lead-capture form. Posts via AJAX → wp_mail
			     to the address configured in Settings → Emails → "Lead
			     Form Email". Nonce + per-IP rate-limited on the server. -->
			<div class="pd-side-card pd-side-card--lead">
				<h3 class="pd-side-card__title pd-side-card__title--light"><?php esc_html_e( 'Raise Hand For Charity', 'pesa-donations' ); ?></h3>
				<p class="pd-side-card__text pd-side-card__text--light">
					<?php esc_html_e( 'Want to help differently? Leave your details and our team will reach out.', 'pesa-donations' ); ?>
				</p>
				<form class="pd-side-card__form" data-pd-lead-form>
					<input type="hidden" name="action"      value="pd_submit_lead" />
					<input type="hidden" name="nonce"       value="<?php echo esc_attr( wp_create_nonce( 'pd_public_nonce' ) ); ?>" />
					<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign->get_id() ); ?>" />
					<?php
					// Build source_url from home_url() (site-configured),
					// not HTTP_HOST (client-controllable on misconfigured
					// hosts — would let an attacker phish the admin alert
					// email by spoofing the Host header).
					$source_url = home_url( $_SERVER['REQUEST_URI'] ?? '/' );
					?>
					<input type="hidden" name="source_url"  value="<?php echo esc_attr( esc_url_raw( $source_url ) ); ?>" />

					<input type="text"  name="lead_name"  placeholder="<?php esc_attr_e( 'Enter Name *', 'pesa-donations' ); ?>" required maxlength="100" />
					<input type="email" name="lead_email" placeholder="<?php esc_attr_e( 'Enter Email *', 'pesa-donations' ); ?>" required maxlength="150" />
					<input type="tel"   name="lead_phone" placeholder="<?php esc_attr_e( 'Enter Phone No. *', 'pesa-donations' ); ?>" required maxlength="30" />

					<button type="submit" class="pd-btn pd-btn--primary pd-side-card__form-submit">
						<?php esc_html_e( 'Involve Now', 'pesa-donations' ); ?>
					</button>

					<p class="pd-side-card__form-msg pd-side-card__form-msg--success" hidden></p>
					<p class="pd-side-card__form-msg pd-side-card__form-msg--error"   hidden></p>
				</form>
			</div>

		</aside>

	</div>

	<?php /* ---- Lightbox (same look as the sponsor card lightbox) ---- */ ?>
	<div class="pd-lightbox" x-show="lightboxOpen" x-cloak @click="closeLightbox()" style="display:none;">
		<button type="button" class="pd-lightbox__close" @click.stop="closeLightbox()"
		        aria-label="<?php esc_attr_e( 'Close', 'pesa-donations' ); ?>">&times;</button>

		<button type="button" class="pd-lightbox__nav pd-lightbox__nav--prev"
		        @click.stop="lightboxPrev()"
		        aria-label="<?php esc_attr_e( 'Previous', 'pesa-donations' ); ?>">&lsaquo;</button>

		<img :src="lightboxImage" class="pd-lightbox__img" @click.stop alt="" />

		<button type="button" class="pd-lightbox__nav pd-lightbox__nav--next"
		        @click.stop="lightboxNext()"
		        aria-label="<?php esc_attr_e( 'Next', 'pesa-donations' ); ?>">&rsaquo;</button>

		<div class="pd-lightbox__strip" @click.stop x-ref="strip">
			<template x-for="(thumb, i) in gallery" :key="thumb.id">
				<button type="button"
				        class="pd-lightbox__thumb"
				        :class="{ 'pd-lightbox__thumb--active': i === lightboxIndex }"
				        @click.stop="lightboxIndex = i; scrollActiveThumbIntoView()"
				        :aria-label="'<?php echo esc_js( __( 'View image', 'pesa-donations' ) ); ?> ' + (i + 1)">
					<img :src="thumb.thumb" :alt="thumb.alt" loading="lazy" />
				</button>
			</template>
		</div>

		<div class="pd-lightbox__counter" @click.stop x-show="lightboxTotal > 1">
			<span x-text="lightboxIndex + 1"></span>
			<span class="pd-lightbox__counter-sep">/</span>
			<span x-text="lightboxTotal"></span>
		</div>
	</div>

	<?php /* ---- Sticky mobile donate bar (visible only below 720px) ---- */ ?>
	<div class="pd-donation-single__mobile-cta">
		<div class="pd-donation-single__mobile-cta-meta">
			<span class="pd-donation-single__mobile-cta-progress"><?php echo esc_html( $progress . '%' ); ?></span>
			<span class="pd-donation-single__mobile-cta-label"><?php esc_html_e( 'Raised', 'pesa-donations' ); ?></span>
		</div>
		<a href="<?php echo esc_url( $campaign->get_checkout_url() ); ?>" class="pd-btn pd-btn--primary pd-donation-single__mobile-cta-btn">
			<?php esc_html_e( 'Donate Now', 'pesa-donations' ); ?>
		</a>
	</div>

</div>

<script>
/* "Raise Hand For Charity" — vanilla AJAX submitter. Wires every form
   on the page that has [data-pd-lead-form]. Disables submit while in
   flight, swaps in success/error messages from the server response. */
(function () {
	var ajaxUrl = (window.pdPublic && window.pdPublic.ajaxUrl) || <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var forms   = document.querySelectorAll('[data-pd-lead-form]');

	Array.prototype.forEach.call(forms, function (form) {
		form.addEventListener('submit', async function (e) {
			e.preventDefault();

			var btn   = form.querySelector('button[type=submit]');
			var ok    = form.querySelector('.pd-side-card__form-msg--success');
			var err   = form.querySelector('.pd-side-card__form-msg--error');
			ok.hidden  = true;
			err.hidden = true;

			if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = '…'; }

			try {
				var res  = await fetch(ajaxUrl, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
				var data = await res.json();

				if (data && data.success) {
					ok.textContent = (data.data && data.data.message) || <?php echo wp_json_encode( __( 'Thanks! We received your details and will reach out soon.', 'pesa-donations' ) ); ?>;
					ok.hidden = false;
					form.reset();
				} else {
					err.textContent = (data && data.data && data.data.message) || <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'pesa-donations' ) ); ?>;
					err.hidden = false;
				}
			} catch (ex) {
				err.textContent = <?php echo wp_json_encode( __( 'Network error. Please check your connection and try again.', 'pesa-donations' ) ); ?>;
				err.hidden = false;
			} finally {
				if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || <?php echo wp_json_encode( __( 'Involve Now', 'pesa-donations' ) ); ?>; }
			}
		});
	});
})();
</script>

<?php
get_footer();
