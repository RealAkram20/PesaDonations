<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

use PesaDonations\Models\Campaign;
use PesaDonations\CPT\Campaign_CPT;
use WP_Query;

class Shortcodes {

	public function register(): void {
		$map = [
			'pd_donate_button'  => 'render_donate_button',
			'pd_sponsorships'   => 'render_sponsorships',
			'pd_projects'       => 'render_projects',
			'pd_sponsor_browse' => 'render_sponsor_browse',
			'pd_give_browse'    => 'render_give_browse',
			'pd_campaign'       => 'render_single_campaign',
			'pd_campaign_list'  => 'render_campaign_list',
			'pd_progress'       => 'render_progress',
			'pd_checkout'       => 'render_checkout',
			'pd_thank_you'      => 'render_thank_you',
		];
		foreach ( $map as $tag => $method ) {
			add_shortcode( $tag, [ $this, $method ] );
		}
	}

	// -------------------------------------------------------------------------
	// [pd_donate_button id="123" label="Donate Now"]
	// -------------------------------------------------------------------------

	public function render_donate_button( array $atts ): string {
		$atts = shortcode_atts( [
			'id'    => 0,
			'label' => __( 'Donate Now', 'pesa-donations' ),
		], $atts, 'pd_donate_button' );

		$campaign_id = (int) $atts['id'];
		$campaign    = $campaign_id ? Campaign::get( $campaign_id ) : null;

		if ( ! $campaign || ! $campaign->is_active() ) {
			return '';
		}

		$checkout_url = esc_url( $campaign->get_checkout_url() );

		ob_start();
		?>
		<div class="pd-donate-btn-wrap" x-data="pdDonateButton()">
			<a href="<?php echo $checkout_url; ?>" class="pd-btn pd-btn--primary pd-donate-btn">
				<?php echo esc_html( $atts['label'] ); ?>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_sponsorships limit="12" columns="3" status="available"]
	// -------------------------------------------------------------------------

	public function render_sponsorships( array $atts ): string {
		$atts = shortcode_atts( [
			'limit'   => 12,
			'columns' => 3,
			'orderby' => 'date',
			'status'  => 'active',
		], $atts, 'pd_sponsorships' );

		$campaigns = $this->query_campaigns( [
			'posts_per_page' => (int) $atts['limit'],
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'meta_query'     => [
				[
					'key'     => '_pd_category',
					'value'   => [ 'sponsorship', 'child' ], // 'child' for legacy
					'compare' => 'IN',
				],
			],
		] );

		if ( empty( $campaigns ) ) {
			return '<p class="pd-empty">' . esc_html__( 'No sponsorship opportunities available at this time.', 'pesa-donations' ) . '</p>';
		}

		$json_data = wp_json_encode( array_map( fn( Campaign $c ) => $c->to_json_array(), $campaigns ) );

		ob_start();
		?>
		<div class="pd-listing pd-listing--sponsorships"
		     x-data="pdCampaignList(<?php echo esc_attr( $json_data ); ?>)"
		     x-init="init()">

			<div class="pd-grid pd-grid--<?php echo esc_attr( $atts['columns'] ); ?>col">
				<?php foreach ( $campaigns as $campaign ) : ?>
					<?php $this->render_sponsorship_card( $campaign ); ?>
				<?php endforeach; ?>
			</div>

			<?php $this->render_details_modal(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_sponsorship_card( Campaign $c ): void {
		$data       = wp_json_encode( $c->to_json_array() );
		$has_goal   = $c->show_progress_bar() && $c->get_goal_amount() > 0;
		$title      = $c->get_beneficiary_name() ?: $c->get_title();
		?>
		<article class="pd-card pd-card--sponsorship">
			<div class="pd-card__media">
				<?php if ( $c->get_thumbnail_url( 'medium_large' ) ) : ?>
					<img src="<?php echo esc_url( $c->get_thumbnail_url( 'medium_large' ) ); ?>"
					     alt="<?php echo esc_attr( $title ); ?>"
					     class="pd-card__image"
					     loading="lazy" />
				<?php else : ?>
					<div class="pd-card__image pd-card__image--placeholder"></div>
				<?php endif; ?>
				<span class="pd-tag pd-tag--sponsorship"><?php esc_html_e( 'Sponsorship', 'pesa-donations' ); ?></span>
			</div>

			<div class="pd-card__body">
				<?php if ( $has_goal ) : ?>
					<?php $this->render_stripe_progress( $c ); ?>
					<p class="pd-card__raised">
						<span class="pd-raised"><?php echo esc_html( number_format( $c->get_raised_amount() ) ); ?></span>
						<span class="pd-card__raised-label">
							<?php esc_html_e( 'Raised of', 'pesa-donations' ); ?>
							<span class="pd-goal"><?php echo esc_html( number_format( $c->get_goal_amount() ) ); ?></span>
						</span>
					</p>
				<?php endif; ?>

				<h3 class="pd-card__title"><?php echo esc_html( $title ); ?></h3>

				<?php if ( $c->get_beneficiary_location() ) : ?>
					<p class="pd-card__meta"><?php echo esc_html( $c->get_beneficiary_location() ); ?></p>
				<?php endif; ?>

				<?php if ( $c->get_excerpt() ) : ?>
					<p class="pd-card__excerpt"><?php echo esc_html( wp_trim_words( $c->get_excerpt(), 11 ) ); ?></p>
				<?php endif; ?>

				<div class="pd-card__actions">
					<button type="button" class="pd-btn pd-btn--outline"
					        @click="openDetails(<?php echo esc_attr( $data ); ?>)">
						<?php esc_html_e( 'View Details', 'pesa-donations' ); ?>
					</button>
					<a href="<?php echo esc_url( $c->get_checkout_url() ); ?>" class="pd-btn pd-btn--primary">
						<?php esc_html_e( 'Sponsor', 'pesa-donations' ); ?>
					</a>
				</div>
			</div>
		</article>
		<?php
	}

	private function render_stripe_progress( Campaign $c ): void {
		$pct = $c->get_progress_percent();
		?>
		<div class="pd-progress" role="progressbar"
		     aria-valuenow="<?php echo esc_attr( $pct ); ?>"
		     aria-valuemin="0" aria-valuemax="100">
			<div class="pd-progress__fill" style="width:<?php echo esc_attr( $pct ); ?>%">
				<span class="pd-progress__badge"><?php echo esc_html( round( $pct ) . '%' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Single modal used by both sponsorships and projects. Loads full story,
	 * gallery with lightbox, progress, and the Donate / Sponsor CTA.
	 */
	private function render_details_modal(): void {
		?>
		<div class="pd-modal-overlay" x-show="modalOpen" x-cloak @click.self="closeModal()" style="display:none;"
		     x-transition.opacity>
			<div class="pd-modal" role="dialog" aria-modal="true">
				<button type="button" class="pd-modal__close" @click="closeModal()"
				        aria-label="<?php esc_attr_e( 'Close', 'pesa-donations' ); ?>">&times;</button>

				<template x-if="active">
					<div class="pd-modal__inner">

						<div class="pd-modal__hero" x-show="active.thumbnail_lg">
							<img :src="active.thumbnail_lg" :alt="active.title" class="pd-modal__hero-img" />
							<span class="pd-tag" :class="active.is_sponsorship ? 'pd-tag--sponsorship' : 'pd-tag--project'"
							      x-text="active.is_sponsorship ? '<?php echo esc_js( __( 'Sponsorship', 'pesa-donations' ) ); ?>' : '<?php echo esc_js( __( 'Project', 'pesa-donations' ) ); ?>'"></span>
						</div>

						<div class="pd-modal__body">
							<h2 class="pd-modal__title"
							    x-text="active.is_sponsorship ? (active.beneficiary || active.title) : active.title"></h2>

							<div class="pd-modal__meta-row">
								<span x-show="active.location" x-text="active.location"></span>
								<template x-if="active.birthday">
									<span>&bull; <?php esc_html_e( 'Birthday:', 'pesa-donations' ); ?> <span x-text="active.birthday"></span></span>
								</template>
							</div>

							<template x-if="active.show_bar && active.goal > 0">
								<div>
									<div class="pd-progress">
										<div class="pd-progress__fill" :style="'width:' + active.progress + '%'">
											<span class="pd-progress__badge" x-text="Math.round(active.progress) + '%'"></span>
										</div>
									</div>
									<p class="pd-card__raised">
										<span class="pd-raised" x-text="active.raised_fmt + ' ' + active.currency"></span>
										<span class="pd-card__raised-label">
											<?php esc_html_e( 'Raised of', 'pesa-donations' ); ?>
											<span class="pd-goal" x-text="active.goal_fmt + ' ' + active.currency"></span>
										</span>
									</p>
								</div>
							</template>

							<div class="pd-modal__content" x-html="active.content"></div>

							<p class="pd-modal__code" x-show="active.code" x-text="active.code"></p>

							<template x-if="active.gallery && active.gallery.length">
								<div class="pd-gallery">
									<h4 class="pd-gallery__title"><?php esc_html_e( 'Gallery', 'pesa-donations' ); ?></h4>
									<div class="pd-gallery__grid">
										<template x-for="(img, i) in active.gallery" :key="img.id">
											<button type="button" class="pd-gallery__thumb"
											        @click="openLightbox(i)">
												<img :src="img.thumb" :alt="img.alt" loading="lazy" />
											</button>
										</template>
									</div>
								</div>
							</template>
						</div>

						<div class="pd-modal__footer">
							<a :href="active.checkout_url" class="pd-btn pd-btn--primary pd-btn--lg pd-btn--full">
								<span x-text="active.is_sponsorship ? '<?php echo esc_js( __( 'Sponsor Now', 'pesa-donations' ) ); ?>' : '<?php echo esc_js( __( 'Donate Now', 'pesa-donations' ) ); ?>'"></span>
							</a>
						</div>
					</div>
				</template>
			</div>
		</div>

		<?php /* Lightbox for gallery images */ ?>
		<div class="pd-lightbox" x-show="lightboxOpen" x-cloak @click="closeLightbox()" style="display:none;">
			<button type="button" class="pd-lightbox__close" @click.stop="closeLightbox()"
			        aria-label="<?php esc_attr_e( 'Close', 'pesa-donations' ); ?>">&times;</button>

			<button type="button" class="pd-lightbox__nav pd-lightbox__nav--prev"
			        @click.stop="lightboxPrev()"
			        aria-label="<?php esc_attr_e( 'Previous', 'pesa-donations' ); ?>">&lsaquo;</button>

			<img :src="lightboxImage" class="pd-lightbox__img" @click.stop />

			<button type="button" class="pd-lightbox__nav pd-lightbox__nav--next"
			        @click.stop="lightboxNext()"
			        aria-label="<?php esc_attr_e( 'Next', 'pesa-donations' ); ?>">&rsaquo;</button>

			<div class="pd-lightbox__controls" @click.stop>
				<button type="button" class="pd-lightbox__ctrl"
				        :class="{ 'pd-lightbox__ctrl--on': autoplayOn }"
				        @click.stop="toggleAutoplay()"
				        :aria-label="autoplayOn ? '<?php echo esc_js( __( 'Pause slideshow', 'pesa-donations' ) ); ?>' : '<?php echo esc_js( __( 'Play slideshow', 'pesa-donations' ) ); ?>'">
					<span x-show="!autoplayOn">&#9654;</span>
					<span x-show="autoplayOn" class="pd-lightbox__pause">
						<span></span><span></span>
					</span>
				</button>

				<button type="button" class="pd-lightbox__ctrl pd-lightbox__ctrl--speed"
				        @click.stop="cycleSpeed()"
				        :aria-label="'<?php echo esc_js( __( 'Change speed', 'pesa-donations' ) ); ?>: ' + autoplaySpeedLabel">
					<span class="pd-lightbox__speed-label" x-text="autoplaySpeedLabel"></span>
				</button>

				<span class="pd-lightbox__counter">
					<span x-text="lightboxIndex + 1"></span>
					/
					<span x-text="lightboxTotal"></span>
				</span>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// [pd_projects limit="6" columns="3"]
	// -------------------------------------------------------------------------

	public function render_projects( array $atts ): string {
		$atts = shortcode_atts( [
			'limit'    => 6,
			'columns'  => 3,
			'category' => '',
			'orderby'  => 'date',
		], $atts, 'pd_projects' );

		$allowed_categories = [ 'project', 'school', 'hospital', 'medical', 'other' ];
		$categories = $atts['category'] ? [ sanitize_key( $atts['category'] ) ] : $allowed_categories;

		$campaigns = $this->query_campaigns( [
			'posts_per_page' => (int) $atts['limit'],
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'meta_query'     => [
				[
					'key'     => '_pd_category',
					'value'   => $categories,
					'compare' => 'IN',
				],
			],
		] );

		if ( empty( $campaigns ) ) {
			// Try with just "project" (the new simplified category)
			$campaigns = $this->query_campaigns( [
				'posts_per_page' => (int) $atts['limit'],
				'orderby'        => sanitize_key( $atts['orderby'] ),
				'meta_query'     => [
					[ 'key' => '_pd_category', 'value' => 'project' ],
				],
			] );
		}

		if ( empty( $campaigns ) ) {
			return '<p class="pd-empty">' . esc_html__( 'No projects found.', 'pesa-donations' ) . '</p>';
		}

		$json_data = wp_json_encode( array_map( fn( Campaign $c ) => $c->to_json_array(), $campaigns ) );

		ob_start();
		?>
		<div class="pd-listing pd-listing--projects"
		     x-data="pdCampaignList(<?php echo esc_attr( $json_data ); ?>)"
		     x-init="init()">
			<div class="pd-grid pd-grid--<?php echo esc_attr( $atts['columns'] ); ?>col">
				<?php foreach ( $campaigns as $campaign ) : ?>
					<?php $this->render_project_card( $campaign ); ?>
				<?php endforeach; ?>
			</div>
			<?php $this->render_details_modal(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_project_card( Campaign $c ): void {
		$data     = wp_json_encode( $c->to_json_array() );
		$has_goal = $c->show_progress_bar() && $c->get_goal_amount() > 0;
		?>
		<article class="pd-card pd-card--project">
			<div class="pd-card__media">
				<?php if ( $c->get_thumbnail_url( 'medium_large' ) ) : ?>
					<img src="<?php echo esc_url( $c->get_thumbnail_url( 'medium_large' ) ); ?>"
					     alt="<?php echo esc_attr( $c->get_title() ); ?>"
					     class="pd-card__image"
					     loading="lazy" />
				<?php else : ?>
					<div class="pd-card__image pd-card__image--placeholder"></div>
				<?php endif; ?>
				<span class="pd-tag pd-tag--project"><?php esc_html_e( 'Project', 'pesa-donations' ); ?></span>
			</div>

			<div class="pd-card__body">
				<?php if ( $has_goal ) : ?>
					<?php $this->render_stripe_progress( $c ); ?>
					<p class="pd-card__raised">
						<span class="pd-raised"><?php echo esc_html( number_format( $c->get_raised_amount() ) ); ?></span>
						<span class="pd-card__raised-label">
							<?php esc_html_e( 'Raised of', 'pesa-donations' ); ?>
							<span class="pd-goal"><?php echo esc_html( number_format( $c->get_goal_amount() ) ); ?></span>
						</span>
					</p>
				<?php endif; ?>

				<h3 class="pd-card__title"><?php echo esc_html( $c->get_title() ); ?></h3>

				<?php if ( $c->get_excerpt() ) : ?>
					<p class="pd-card__excerpt"><?php echo esc_html( wp_trim_words( $c->get_excerpt(), 11 ) ); ?></p>
				<?php endif; ?>

				<div class="pd-card__actions">
					<button type="button" class="pd-btn pd-btn--outline"
					        @click="openDetails(<?php echo esc_attr( $data ); ?>)">
						<?php esc_html_e( 'View Details', 'pesa-donations' ); ?>
					</button>
					<a href="<?php echo esc_url( $c->get_checkout_url() ); ?>" class="pd-btn pd-btn--primary">
						<?php esc_html_e( 'Donate Now', 'pesa-donations' ); ?>
					</a>
				</div>
			</div>
		</article>
		<?php
	}

	// -------------------------------------------------------------------------
	// [pd_sponsor_browse] / [pd_give_browse] — full browse pages with
	// grid/list toggle, sidebar filters, search, sort, pagination.
	// -------------------------------------------------------------------------

	public function render_sponsor_browse( array $atts ): string {
		return $this->render_browse_page( 'sponsorship', $atts, 'pd_sponsor_browse' );
	}

	public function render_give_browse( array $atts ): string {
		return $this->render_browse_page( 'project', $atts, 'pd_give_browse' );
	}

	private function render_browse_page( string $type, array $atts, string $tag ): string {
		$atts = shortcode_atts( [
			'per_page' => 12,
			'columns'  => 3,
		], $atts, $tag );

		$meta_query = [];
		if ( 'sponsorship' === $type ) {
			$meta_query[] = [
				'key'     => '_pd_category',
				'value'   => [ 'sponsorship', 'child' ],
				'compare' => 'IN',
			];
		} else {
			$meta_query[] = [
				'key'     => '_pd_category',
				'value'   => [ 'project', 'school', 'hospital', 'medical', 'other' ],
				'compare' => 'IN',
			];
		}

		$campaigns = $this->query_campaigns( [
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'meta_query'     => $meta_query,
		] );

		if ( empty( $campaigns ) ) {
			$empty_msg = 'sponsorship' === $type
				? __( 'No sponsorship opportunities available at this time.', 'pesa-donations' )
				: __( 'No projects found.', 'pesa-donations' );
			return '<p class="pd-empty">' . esc_html( $empty_msg ) . '</p>';
		}

		// Enrich each campaign with computed fields (age, etc.) used for filtering
		// AND a set of pre-computed display flags/strings so our Alpine attributes
		// only need to read single identifiers. WP's wp_texturize filter mangles
		// any attribute value that contains "word+quote" patterns (e.g. `&& isX"`)
		// into curly quotes, breaking the Alpine expression. Pre-computing side-
		// steps the whole problem.
		$data = array_map( function ( Campaign $c ) {
			$row      = $c->to_json_array();
			$birthday = $c->get_beneficiary_birthday();
			$row['age'] = '';
			if ( $birthday ) {
				try {
					$dob  = new \DateTimeImmutable( $birthday );
					$diff = $dob->diff( new \DateTimeImmutable( 'now' ) );
					$row['age'] = (int) $diff->y;
				} catch ( \Exception $e ) {
					$row['age'] = '';
				}
			}

			$row['display_title'] = $row['is_sponsorship']
				? ( $row['beneficiary'] ?: $row['title'] )
				: $row['title'];
			$row['vendor']        = $row['location']
				?: ( $row['is_sponsorship'] ? __( 'Sponsorship', 'pesa-donations' ) : __( 'Project', 'pesa-donations' ) );
			$row['has_progress']  = ! empty( $row['show_bar'] ) && (float) $row['goal'] > 0;
			$row['show_age']      = ! empty( $row['is_sponsorship'] ) && '' !== $row['age'];
			$row['short_excerpt'] = $row['excerpt'] ? wp_trim_words( $row['excerpt'], 11, '…' ) : '';
			$row['list_excerpt']  = $row['excerpt'] ? wp_trim_words( $row['excerpt'], 11, '…' ) : '';
			$row['tag_label']     = $row['is_sponsorship'] ? __( 'Sponsorship', 'pesa-donations' ) : __( 'Project', 'pesa-donations' );
			$row['tag_class']     = $row['is_sponsorship'] ? 'pd-tag pd-tag--sponsorship' : 'pd-tag pd-tag--project';
			$row['card_class']    = $row['is_sponsorship'] ? 'pd-card pd-card--sponsorship' : 'pd-card pd-card--project';
			return $row;
		}, $campaigns );

		$json_data = wp_json_encode( $data );
		$config    = wp_json_encode( [
			'type'     => $type,
			'perPage'  => (int) $atts['per_page'],
			'columns'  => (int) $atts['columns'],
			'i18n'     => [
				'cta'        => 'sponsorship' === $type ? __( 'Sponsor Now', 'pesa-donations' ) : __( 'Donate Now', 'pesa-donations' ),
				'empty'      => __( 'No results match your filters.', 'pesa-donations' ),
				'found'      => __( 'Results found', 'pesa-donations' ),
				'searchPh'   => 'sponsorship' === $type ? __( 'Search by name…', 'pesa-donations' ) : __( 'Search projects…', 'pesa-donations' ),
				'viewDetail' => __( 'View Details', 'pesa-donations' ),
			],
		] );

		ob_start();
		?>
		<div class="pd-browse pd-browse--<?php echo esc_attr( $type ); ?>"
		     x-data="pdBrowse(<?php echo esc_attr( $json_data ); ?>, <?php echo esc_attr( $config ); ?>)"
		     x-init="init()">

			<aside class="pd-browse__sidebar">

				<div class="pd-browse__search-wrap">
					<input type="search"
					       class="pd-browse__search"
					       :placeholder="i18n.searchPh"
					       x-model.debounce.250ms="filters.search" />
					<span class="pd-browse__search-icon" aria-hidden="true">&#128269;</span>
				</div>

				<?php if ( 'sponsorship' === $type ) : ?>
					<div class="pd-browse__filter-group">
						<h3 class="pd-browse__filter-title"><?php esc_html_e( 'Age Range', 'pesa-donations' ); ?></h3>
						<?php
						$age_ranges = [
							'0-5'   => '0 – 5',
							'6-10'  => '6 – 10',
							'11-15' => '11 – 15',
							'16-18' => '16 – 18',
							'18+'   => '18+',
						];
						foreach ( $age_ranges as $key => $label ) : ?>
							<label class="pd-filter-check">
								<input type="checkbox" :value="'<?php echo esc_js( $key ); ?>'" x-model="filters.ageRange" />
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="pd-browse__filter-group">
					<h3 class="pd-browse__filter-title"><?php esc_html_e( 'Status', 'pesa-donations' ); ?></h3>
					<label class="pd-filter-check">
						<input type="checkbox" value="available" x-model="filters.status" />
						<span><?php esc_html_e( 'Available', 'pesa-donations' ); ?></span>
					</label>
					<label class="pd-filter-check">
						<input type="checkbox" value="funded" x-model="filters.status" />
						<span><?php esc_html_e( 'Fully Funded', 'pesa-donations' ); ?></span>
					</label>
				</div>

				<?php if ( 'project' === $type ) : ?>
					<div class="pd-browse__filter-group">
						<h3 class="pd-browse__filter-title"><?php esc_html_e( 'Goal Amount', 'pesa-donations' ); ?></h3>
						<?php
						$goal_ranges = [
							'0-100000'       => __( 'Under 100K', 'pesa-donations' ),
							'100000-500000'  => '100K – 500K',
							'500000-1000000' => '500K – 1M',
							'1000000+'       => __( 'Over 1M', 'pesa-donations' ),
						];
						foreach ( $goal_ranges as $key => $label ) : ?>
							<label class="pd-filter-check">
								<input type="checkbox" :value="'<?php echo esc_js( $key ); ?>'" x-model="filters.goalRange" />
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<button type="button" class="pd-btn pd-btn--ghost pd-btn--sm pd-browse__reset"
				        @click="resetFilters()"
				        x-show="hasActiveFilters">
					<?php esc_html_e( 'Reset Filters', 'pesa-donations' ); ?>
				</button>

			</aside>

			<section class="pd-browse__main">

				<div class="pd-browse__toolbar">
					<div class="pd-browse__view-toggle" role="tablist" aria-label="<?php esc_attr_e( 'View', 'pesa-donations' ); ?>">
						<button type="button"
						        class="pd-view-btn"
						        :class="{ 'pd-view-btn--active': isGridView }"
						        @click="setView(true)"
						        aria-label="<?php esc_attr_e( 'Grid view', 'pesa-donations' ); ?>">
							<svg viewBox="0 0 16 16" width="16" height="16"><rect x="0" y="0" width="7" height="7"/><rect x="9" y="0" width="7" height="7"/><rect x="0" y="9" width="7" height="7"/><rect x="9" y="9" width="7" height="7"/></svg>
						</button>
						<button type="button"
						        class="pd-view-btn"
						        :class="{ 'pd-view-btn--active': isListView }"
						        @click="setView(false)"
						        aria-label="<?php esc_attr_e( 'List view', 'pesa-donations' ); ?>">
							<svg viewBox="0 0 16 16" width="16" height="16"><rect x="0" y="1" width="16" height="4"/><rect x="0" y="11" width="16" height="4"/></svg>
						</button>
					</div>

					<p class="pd-browse__count">
						<span x-text="filtered.length"></span>
						<span x-text="i18n.found"></span>
					</p>

					<div class="pd-browse__toolbar-right">
						<select class="pd-input pd-input--select pd-browse__sort" x-model="sort">
							<option value="default"><?php esc_html_e( 'Default', 'pesa-donations' ); ?></option>
							<option value="recent"><?php esc_html_e( 'Recently Added', 'pesa-donations' ); ?></option>
							<option value="name_asc"><?php esc_html_e( 'Name: A → Z', 'pesa-donations' ); ?></option>
							<option value="name_desc"><?php esc_html_e( 'Name: Z → A', 'pesa-donations' ); ?></option>
							<?php if ( 'sponsorship' === $type ) : ?>
								<option value="age_asc"><?php esc_html_e( 'Age: Young → Old', 'pesa-donations' ); ?></option>
								<option value="age_desc"><?php esc_html_e( 'Age: Old → Young', 'pesa-donations' ); ?></option>
							<?php endif; ?>
							<option value="progress_desc"><?php esc_html_e( 'Progress: High → Low', 'pesa-donations' ); ?></option>
							<option value="progress_asc"><?php esc_html_e( 'Progress: Low → High', 'pesa-donations' ); ?></option>
							<option value="goal_desc"><?php esc_html_e( 'Goal: High → Low', 'pesa-donations' ); ?></option>
							<option value="goal_asc"><?php esc_html_e( 'Goal: Low → High', 'pesa-donations' ); ?></option>
						</select>

						<select class="pd-input pd-input--select pd-browse__per-page" x-model.number="perPage">
							<option value="12">12</option>
							<option value="24">24</option>
							<option value="48">48</option>
						</select>
					</div>
				</div>

				<p class="pd-empty" x-show="showEmpty" x-text="i18n.empty"></p>

				<div class="pd-grid"
				     :class="'pd-grid--' + columns + 'col'"
				     x-show="showGrid">
					<template x-for="c in paginated" :key="c.id">
						<article :class="c.card_class">
							<div class="pd-card__media">
								<img :src="c.thumbnail" :alt="c.display_title"
								     class="pd-card__image" loading="lazy"
								     x-show="c.thumbnail" />
								<div class="pd-card__image pd-card__image--placeholder" x-show="!c.thumbnail"></div>
								<span :class="c.tag_class" x-text="c.tag_label"></span>
							</div>
							<div class="pd-card__body">
								<div x-show="c.has_progress">
									<div class="pd-progress">
										<div class="pd-progress__fill" :style="'width:' + c.progress + '%'">
											<span class="pd-progress__badge" x-text="Math.round(c.progress) + '%'"></span>
										</div>
									</div>
									<p class="pd-card__raised">
										<span class="pd-raised" x-text="c.raised_fmt"></span>
										<span class="pd-card__raised-label">
											<?php esc_html_e( 'Raised of', 'pesa-donations' ); ?>
											<span class="pd-goal" x-text="c.goal_fmt"></span>
										</span>
									</p>
								</div>

								<h3 class="pd-card__title" x-text="c.display_title"></h3>

								<p class="pd-card__meta" x-show="c.location" x-text="c.location"></p>

								<p class="pd-card__meta pd-card__meta--muted"
								   x-show="c.show_age">
									<?php esc_html_e( 'Age', 'pesa-donations' ); ?>: <span x-text="c.age"></span>
								</p>

								<p class="pd-card__excerpt"
								   x-show="c.short_excerpt"
								   x-text="c.short_excerpt"></p>

								<div class="pd-card__actions">
									<button type="button" class="pd-btn pd-btn--outline"
									        @click="openDetails(c)" x-text="i18n.viewDetail"></button>
									<a :href="c.checkout_url" class="pd-btn pd-btn--primary" x-text="i18n.cta"></a>
								</div>
							</div>
						</article>
					</template>
				</div>

				<div class="pd-list"
				     x-show="showList">
					<template x-for="c in paginated" :key="c.id">
						<article class="pd-list-row">
							<div class="pd-list-row__media">
								<img :src="c.thumbnail" :alt="c.display_title" loading="lazy"
								     x-show="c.thumbnail" />
								<div x-show="!c.thumbnail" style="width:100%;height:100%;background:#f5f5f5;"></div>
							</div>
							<div class="pd-list-row__body">
								<span class="pd-list-row__vendor" x-text="c.vendor"></span>
								<h3 class="pd-list-row__title" x-text="c.display_title"></h3>

								<div class="pd-list-row__progress" x-show="c.has_progress">
									<div class="pd-progress">
										<div class="pd-progress__fill" :style="'width:' + c.progress + '%'">
											<span class="pd-progress__badge" x-text="Math.round(c.progress) + '%'"></span>
										</div>
									</div>
								</div>

								<p class="pd-list-row__price">
									<span class="pd-raised" x-text="c.raised_fmt + ' ' + c.currency"></span>
									<span class="pd-list-row__of">
										<?php esc_html_e( 'raised of', 'pesa-donations' ); ?>
										<span class="pd-goal" x-text="c.goal_fmt + ' ' + c.currency"></span>
									</span>
								</p>

								<p class="pd-list-row__excerpt" x-show="c.list_excerpt" x-text="c.list_excerpt"></p>

								<div class="pd-list-row__actions">
									<button type="button" class="pd-btn pd-btn--outline pd-btn--sm"
									        @click="openDetails(c)" x-text="i18n.viewDetail"></button>
									<a :href="c.checkout_url" class="pd-btn pd-btn--primary pd-btn--sm" x-text="i18n.cta"></a>
								</div>
							</div>
						</article>
					</template>
				</div>

				<nav class="pd-browse__pagination" x-show="showPaginator" aria-label="<?php esc_attr_e( 'Pagination', 'pesa-donations' ); ?>">
					<button type="button" class="pd-page-btn" :disabled="page === 1" @click="page = Math.max(1, page - 1)">&lsaquo;</button>
					<template x-for="p in totalPages" :key="p">
						<button type="button" class="pd-page-btn" :class="{ 'pd-page-btn--active': p === page }" @click="page = p" x-text="p"></button>
					</template>
					<button type="button" class="pd-page-btn" :disabled="page === totalPages" @click="page = Math.min(totalPages, page + 1)">&rsaquo;</button>
				</nav>

			</section>

			<?php $this->render_details_modal(); ?>

		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_progress id="123"]
	// -------------------------------------------------------------------------

	public function render_progress( array $atts ): string {
		$atts     = shortcode_atts( [ 'id' => 0 ], $atts, 'pd_progress' );
		$campaign = Campaign::get( (int) $atts['id'] );
		if ( ! $campaign || $campaign->get_goal_amount() <= 0 ) {
			return '';
		}
		$pct = $campaign->get_progress_percent();
		ob_start();
		?>
		<div class="pd-progress-widget">
			<div class="pd-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
				<div class="pd-progress-bar__fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
			</div>
			<div class="pd-progress-stats">
				<span><?php echo esc_html( number_format( $campaign->get_raised_amount() ) . ' ' . $campaign->get_base_currency() ); ?> <?php esc_html_e( 'raised', 'pesa-donations' ); ?></span>
				<span><?php echo esc_html( $pct . '%' ); ?></span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_checkout] — used on the Donation Checkout page
	// -------------------------------------------------------------------------

	public function render_checkout( array $atts ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$campaign_id = isset( $_GET['pd_cid'] ) ? (int) $_GET['pd_cid'] : 0;
		$campaign    = $campaign_id ? Campaign::get( $campaign_id ) : null;

		if ( ! $campaign || ! $campaign->is_active() ) {
			return '<p class="pd-error">' . esc_html__( 'Campaign not found or no longer active.', 'pesa-donations' ) . '</p>';
		}

		ob_start();
		$this->load_template( 'sponsorship-checkout', [ 'campaign' => $campaign ] );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_single_campaign id="123"] — full single display
	// -------------------------------------------------------------------------

	public function render_single_campaign( array $atts ): string {
		$atts     = shortcode_atts( [ 'id' => 0, 'layout' => 'full' ], $atts, 'pd_campaign' );
		$campaign = Campaign::get( (int) $atts['id'] );
		if ( ! $campaign ) {
			return '';
		}
		ob_start();
		$this->load_template( 'campaign-single', [ 'campaign' => $campaign, 'layout' => $atts['layout'] ] );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_campaign_list]
	// -------------------------------------------------------------------------

	public function render_campaign_list( array $atts ): string {
		$atts = shortcode_atts( [
			'category' => '',
			'limit'    => 6,
			'columns'  => 3,
			'orderby'  => 'recent',
			'status'   => 'active',
		], $atts, 'pd_campaign_list' );

		$meta_query = [];
		if ( $atts['category'] ) {
			$meta_query[] = [ 'key' => '_pd_category', 'value' => sanitize_key( $atts['category'] ) ];
		}

		$campaigns = $this->query_campaigns( [
			'posts_per_page' => (int) $atts['limit'],
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'meta_query'     => $meta_query,
		] );

		ob_start();
		echo '<div class="pd-grid pd-grid--' . esc_attr( $atts['columns'] ) . 'col">';
		foreach ( $campaigns as $c ) {
			$this->render_project_card( $c );
		}
		echo '</div>';
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_thank_you]
	// -------------------------------------------------------------------------

	public function render_thank_you( array $atts ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uuid     = isset( $_GET['d'] ) ? sanitize_text_field( wp_unslash( $_GET['d'] ) ) : '';
		$donation = $uuid ? \PesaDonations\Models\Donation::get_by_uuid( $uuid ) : null;

		// Navigation URLs
		$home_url     = home_url( '/' );
		$campaign     = $donation ? Campaign::get( $donation->get_campaign_id() ) : null;
		$campaign_name = $campaign ? ( $campaign->get_beneficiary_name() ?: $campaign->get_title() ) : '';

		// Status-aware messaging
		$status       = $donation ? $donation->get_status() : 'unknown';
		$is_completed = 'completed' === $status;
		$is_pending   = 'pending' === $status;
		$is_failed    = in_array( $status, [ 'failed', 'reversed', 'cancelled' ], true );

		if ( $is_completed ) {
			$icon_class  = 'pd-thanks__icon pd-thanks__icon--success';
			$icon_glyph  = '&#10003;'; // ✓
			$eyebrow     = __( 'Donation received', 'pesa-donations' );
			$heading     = __( 'Thank you for your generosity!', 'pesa-donations' );
			$subheading  = $campaign_name
				? sprintf( __( 'Your gift will make a real difference for %s.', 'pesa-donations' ), $campaign_name )
				: __( 'Your gift will make a real difference.', 'pesa-donations' );
		} elseif ( $is_pending ) {
			$icon_class  = 'pd-thanks__icon pd-thanks__icon--pending';
			$icon_glyph  = '&hellip;';
			$eyebrow     = __( 'Processing payment', 'pesa-donations' );
			$heading     = __( "We're confirming your donation", 'pesa-donations' );
			$subheading  = __( "This usually takes just a moment. We'll send you a confirmation email once it's complete.", 'pesa-donations' );
		} elseif ( $is_failed ) {
			$icon_class  = 'pd-thanks__icon pd-thanks__icon--failed';
			$icon_glyph  = '!';
			$eyebrow     = __( 'Payment unsuccessful', 'pesa-donations' );
			$heading     = __( "Your donation didn't go through", 'pesa-donations' );
			$subheading  = __( "No funds were taken. You can try again or reach out if you need help.", 'pesa-donations' );
		} else {
			$icon_class  = 'pd-thanks__icon pd-thanks__icon--success';
			$icon_glyph  = '&#10084;'; // ♥
			$eyebrow     = __( 'Thank you', 'pesa-donations' );
			$heading     = __( 'Thank you for your generosity!', 'pesa-donations' );
			$subheading  = '';
		}

		ob_start();
		?>
		<div class="pd-thanks">
			<div class="pd-thanks__card">

				<span class="<?php echo esc_attr( $icon_class ); ?>"><?php echo $icon_glyph; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>

				<p class="pd-thanks__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<h1 class="pd-thanks__heading"><?php echo esc_html( $heading ); ?></h1>

				<?php if ( $subheading ) : ?>
					<p class="pd-thanks__sub"><?php echo esc_html( $subheading ); ?></p>
				<?php endif; ?>

				<?php if ( $donation && $is_completed ) : ?>
					<div class="pd-thanks__amount">
						<span class="pd-thanks__amount-value">
							<?php echo esc_html( number_format( $donation->get_amount(), 2 ) ); ?>
							<span class="pd-thanks__amount-currency"><?php echo esc_html( $donation->get_currency() ); ?></span>
						</span>
						<?php if ( $campaign_name ) : ?>
							<span class="pd-thanks__amount-target">
								<?php printf(
									/* translators: %s: campaign/beneficiary name */
									esc_html__( 'to %s', 'pesa-donations' ),
									'<strong>' . esc_html( $campaign_name ) . '</strong>'
								); ?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="pd-thanks__actions">
					<a href="<?php echo esc_url( $home_url ); ?>" class="pd-btn pd-btn--primary pd-btn--lg">
						<?php esc_html_e( 'Back to Home', 'pesa-donations' ); ?>
					</a>
					<?php if ( $is_failed && $campaign ) : ?>
						<a href="<?php echo esc_url( $campaign->get_checkout_url() ); ?>" class="pd-btn pd-btn--outline pd-btn--lg">
							<?php esc_html_e( 'Try Again', 'pesa-donations' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php if ( $donation ) : ?>
					<p class="pd-thanks__reference">
						<?php esc_html_e( 'Reference', 'pesa-donations' ); ?>:
						<code><?php echo esc_html( $donation->get_merchant_reference() ); ?></code>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function query_campaigns( array $args ): array {
		$defaults = [
			'post_type'      => Campaign_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => '_pd_status',
					'value'   => [ 'active', 'reached' ],
					'compare' => 'IN',
				],
			],
		];

		// Merge meta_queries instead of overwriting.
		if ( ! empty( $args['meta_query'] ) ) {
			$defaults['meta_query'] = array_merge( $defaults['meta_query'], $args['meta_query'] );
			unset( $args['meta_query'] );
		}

		$query   = new WP_Query( array_merge( $defaults, $args ) );
		$results = [];

		foreach ( $query->posts as $post ) {
			$campaign = Campaign::get( $post->ID );
			if ( $campaign ) {
				$results[] = $campaign;
			}
		}

		return $results;
	}

	private function load_template( string $name, array $data = [] ): void {
		// Allow theme override (WooCommerce pattern).
		$theme_file  = get_stylesheet_directory() . '/pesa-donations/' . $name . '.php';
		$plugin_file = PD_PLUGIN_DIR . 'templates/' . $name . '.php';
		$file        = file_exists( $theme_file ) ? $theme_file : $plugin_file;

		if ( ! file_exists( $file ) ) {
			return;
		}

		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;
	}
}
