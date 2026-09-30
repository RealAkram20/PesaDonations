<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

use PesaDonations\Models\Campaign;
use PesaDonations\Models\Campaign_Totals;
use PesaDonations\Models\Donation;
use PesaDonations\Models\Open_Donation;
use PesaDonations\CPT\Campaign_CPT;
use PesaDonations\Utils\Sanitizer;
use WP_Query;

class Shortcodes {

	private const SPONSORSHIP_CATEGORIES = [ 'sponsorship', 'child' ];
	private const PROJECT_CATEGORIES     = [ 'project', 'school', 'hospital', 'medical', 'other' ];

	public function register(): void {
		$map = [
			'pd_donate_button'  => 'render_donate_button',
			'pd_sponsor_browse' => 'render_sponsor_browse',
			'pd_give_browse'    => 'render_give_browse',
			'pd_sponsor_slider' => 'render_sponsor_slider',
			'pd_give_slider'    => 'render_give_slider',
			'pd_checkout'       => 'render_checkout',
			'pd_thank_you'      => 'render_thank_you',
			'pd_donate'         => 'render_open_donation',
		];
		foreach ( $map as $tag => $method ) {
			// Every shortcode loads the plugin's CSS and JS as it renders, so it
			// is styled wherever it is placed, not only in a post's own content.
			add_shortcode( $tag, function ( $atts ) use ( $method, $tag ): string {
				PD_Public::enqueue( 'pd_donate_button' === $tag ); // a plain link needs only the CSS
				return $this->$method( is_array( $atts ) ? $atts : [] );
			} );
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

		if ( ! $campaign || ! $campaign->accepts_donations() ) {
			return '';
		}

		return sprintf(
			'<div class="pd-donate-btn-wrap"><a href="%s" class="pd-btn pd-btn--primary pd-donate-btn">%s</a></div>',
			esc_url( $campaign->get_checkout_url() ),
			esc_html( $atts['label'] )
		);
	}

	// -------------------------------------------------------------------------
	// Card (sliders) and details modal (sliders + browse pages)
	// -------------------------------------------------------------------------

	/** @param bool $eager On screen at load (the first cards): not lazy, so the browser fetches it at once. */
	private function render_card( Campaign $c, bool $eager = false ): void {
		$is_sp    = $c->is_sponsorship();
		$has_goal = $c->show_progress_bar() && $c->get_goal_amount() > 0;
		$title    = $is_sp ? ( $c->get_beneficiary_name() ?: $c->get_title() ) : $c->get_title();
		$thumb    = $c->get_thumbnail_url( 'medium_large' );
		$summary  = $c->get_summary( 11 );
		?>
		<article class="pd-card <?php echo $is_sp ? 'pd-card--sponsorship' : 'pd-card--project'; ?>">
			<div class="pd-card__media">
				<?php if ( $thumb ) : ?>
					<img src="<?php echo esc_url( $thumb ); ?>"
					     alt="<?php echo esc_attr( $title ); ?>"
					     class="pd-card__image"
					     loading="<?php echo $eager ? 'eager' : 'lazy'; ?>" />
				<?php else : ?>
					<div class="pd-card__image pd-card__image--placeholder"></div>
				<?php endif; ?>
				<?php if ( $is_sp ) : ?>
					<span class="pd-tag pd-tag--sponsorship"><?php esc_html_e( 'Sponsorship', 'pesa-donations' ); ?></span>
				<?php else : ?>
					<span class="pd-tag pd-tag--project"><?php esc_html_e( 'Project', 'pesa-donations' ); ?></span>
				<?php endif; ?>
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

				<?php $this->render_period_line( $c ); ?>

				<h3 class="pd-card__title"><?php echo esc_html( $title ); ?></h3>

				<?php if ( $is_sp && $c->get_beneficiary_location() ) : ?>
					<p class="pd-card__meta"><?php echo esc_html( $c->get_beneficiary_location() ); ?></p>
				<?php endif; ?>

				<?php if ( $summary ) : ?>
					<p class="pd-card__excerpt"><?php echo esc_html( $summary ); ?></p>
				<?php endif; ?>

				<div class="pd-card__actions">
					<button type="button" class="pd-btn pd-btn--outline"
					        @click="openDetails(<?php echo (int) $c->get_id(); ?>)">
						<?php esc_html_e( 'View Details', 'pesa-donations' ); ?>
					</button>
					<a href="<?php echo esc_url( $c->get_checkout_url() ); ?>" class="pd-btn pd-btn--primary">
						<?php $is_sp ? esc_html_e( 'Sponsor', 'pesa-donations' ) : esc_html_e( 'Donate Now', 'pesa-donations' ); ?>
					</a>
				</div>
			</div>
		</article>
		<?php
	}

	/** "Term 3 2026 · Ends 5 Dec 2026" under the progress of a repeating campaign. */
	private function render_period_line( Campaign $c ): void {
		$period = $c->get_current_period();
		if ( ! $period ) {
			return;
		}
		?>
		<p class="pd-card__period">
			<span class="pd-card__period-label"><?php echo esc_html( $period->get_label() ); ?></span>
			<span><?php echo esc_html( $c->get_period_note() ); ?></span>
		</p>
		<?php
	}

	private function render_stripe_progress( Campaign $c ): void {
		$pct = $c->get_progress_percent();
		?>
		<div class="pd-progress" role="progressbar"
		     aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>"
		     aria-valuemin="0" aria-valuemax="100">
			<div class="pd-progress__fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%">
				<span class="pd-progress__badge"><?php echo esc_html( round( $pct ) . '%' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * One modal for sponsorships and projects. The card's own data shows at
	 * once; the story and the gallery load when it opens (pd_campaign_details).
	 */
	private function render_details_modal(): void {
		?>
		<div class="pd-modal-overlay" x-show="modalOpen" x-cloak @click.self="closeModal()" style="display:none;"
		     @keydown.escape.window="onEscape()" x-transition.opacity>
			<div class="pd-modal" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $this->modal_title_id() ); ?>">
				<button type="button" class="pd-modal__close" @click="closeModal()"
				        aria-label="<?php esc_attr_e( 'Close', 'pesa-donations' ); ?>">&times;</button>

				<template x-if="active">
					<div class="pd-modal__inner">

						<div class="pd-modal__hero" x-show="active.thumbnail_lg">
							<img :src="active.thumbnail_lg" :alt="active.display_title" class="pd-modal__hero-img" />
							<span class="pd-tag pd-tag--sponsorship" x-show="active.is_sponsorship"><?php esc_html_e( 'Sponsorship', 'pesa-donations' ); ?></span>
							<span class="pd-tag pd-tag--project" x-show="!active.is_sponsorship"><?php esc_html_e( 'Project', 'pesa-donations' ); ?></span>
						</div>

						<div class="pd-modal__body">
							<h2 class="pd-modal__title" id="<?php echo esc_attr( $this->modal_title_id( false ) ); ?>" x-text="active.display_title"></h2>

							<div class="pd-modal__meta-row">
								<span x-show="active.location" x-text="active.location"></span>
								<template x-if="active.birthday">
									<span>&bull; <?php esc_html_e( 'Birthday:', 'pesa-donations' ); ?> <span x-text="active.birthday"></span></span>
								</template>
							</div>

							<template x-if="active.has_progress">
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

							<p class="pd-card__period" x-show="active.period_label">
								<span class="pd-card__period-label" x-text="active.period_label"></span>
								<span x-text="active.period_note"></span>
							</p>

							<div class="pd-modal__loading" x-show="detailsLoading" aria-hidden="true"><span></span><span></span><span></span></div>
							<p class="pd-modal__error" x-show="detailsFailed" role="alert">
								<?php esc_html_e( 'The story could not load.', 'pesa-donations' ); ?>
								<button type="button" @click="retryDetails()"><?php esc_html_e( 'Try again', 'pesa-donations' ); ?></button>
							</p>

							<div class="pd-modal__content" x-html="active.content"></div>

							<p class="pd-modal__code" x-show="active.code" x-text="active.code"></p>

							<template x-if="active.gallery && active.gallery.length">
								<div class="pd-gallery">
									<h4 class="pd-gallery__title"><?php esc_html_e( 'Gallery', 'pesa-donations' ); ?></h4>
									<div class="pd-gallery__grid">
										<template x-for="(img, i) in active.gallery" :key="i">
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
								<span x-show="active.is_sponsorship"><?php esc_html_e( 'Sponsor Now', 'pesa-donations' ); ?></span>
								<span x-show="!active.is_sponsorship"><?php esc_html_e( 'Donate Now', 'pesa-donations' ); ?></span>
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

			<img :src="lightboxImage" :alt="lightboxAlt" class="pd-lightbox__img" @click.stop />

			<button type="button" class="pd-lightbox__nav pd-lightbox__nav--next"
			        @click.stop="lightboxNext()"
			        aria-label="<?php esc_attr_e( 'Next', 'pesa-donations' ); ?>">&rsaquo;</button>

			<div class="pd-lightbox__strip" @click.stop x-ref="strip">
				<template x-for="(thumb, i) in lightboxImages" :key="i">
					<button type="button"
					        class="pd-lightbox__thumb"
					        :class="{ 'pd-lightbox__thumb--active': i === lightboxIndex }"
					        @click.stop="lightboxIndex = i"
					        :aria-label="'<?php echo esc_js( __( 'View image', 'pesa-donations' ) ); ?> ' + (i + 1)">
						<img :src="thumb.thumb" :alt="thumb.alt" loading="lazy" />
					</button>
				</template>
			</div>

			<div class="pd-lightbox__counter" @click.stop>
				<span x-text="lightboxIndex + 1"></span>
				<span class="pd-lightbox__counter-sep">/</span>
				<span x-text="lightboxTotal"></span>
			</div>
		</div>
		<?php
	}

	/** A heading id unique per component, so two on one page do not share it. */
	private function modal_title_id( bool $next = true ): string {
		static $n = 0;
		if ( $next ) {
			++$n;
		}
		return 'pd-modal-title-' . $n;
	}

	/**
	 * Component data travels in a JSON script block, not an attribute: no HTML
	 * escaping (which inflated it by a third), and nothing wptexturize or a page
	 * builder can rewrite. JSON_HEX_TAG keeps a "</script>" in a title inert.
	 */
	private function data_block( array $data ): string {
		return '<script type="application/json" class="pd-data">'
			. wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			. '</script>';
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
			'filters'  => 'true',
		], $atts, $tag );

		$show_filters = filter_var( $atts['filters'], FILTER_VALIDATE_BOOLEAN );
		$per_page     = max( 1, min( 100, (int) $atts['per_page'] ) );
		$columns      = max( 1, min( 4, (int) $atts['columns'] ) );

		$campaigns = $this->query_campaigns( $type, -1 );

		if ( empty( $campaigns ) ) {
			$empty_msg = 'sponsorship' === $type
				? __( 'No sponsorship opportunities available at this time.', 'pesa-donations' )
				: __( 'No projects found.', 'pesa-donations' );
			return '<p class="pd-empty">' . esc_html( $empty_msg ) . '</p>';
		}

		$today = new \DateTimeImmutable( 'now', wp_timezone() );
		$items = array_map( function ( Campaign $c ) use ( $today ) {
			$row      = $c->to_json_array();
			$birthday = $c->get_beneficiary_birthday();
			$row['age'] = '';
			if ( $birthday ) {
				try {
					$row['age'] = ( new \DateTimeImmutable( $birthday, wp_timezone() ) )->diff( $today )->y;
				} catch ( \Exception $e ) {
					$row['age'] = '';
				}
			}
			$row['vendor']       = $row['location'] ?: ( $row['is_sponsorship'] ? __( 'Sponsorship', 'pesa-donations' ) : __( 'Project', 'pesa-donations' ) );
			$row['show_age']     = $row['is_sponsorship'] && '' !== $row['age'];
			return $row;
		}, $campaigns );

		$sort_labels = [
			'default'   => __( 'Default', 'pesa-donations' ),
			'recent'    => __( 'Recently Added', 'pesa-donations' ),
			'name_asc'  => __( 'Name: A → Z', 'pesa-donations' ),
			'name_desc' => __( 'Name: Z → A', 'pesa-donations' ),
		];
		if ( 'sponsorship' === $type ) {
			$sort_labels['age_asc']  = __( 'Age: Young → Old', 'pesa-donations' );
			$sort_labels['age_desc'] = __( 'Age: Old → Young', 'pesa-donations' );
		}
		$sort_labels += [
			'progress_desc' => __( 'Progress: High → Low', 'pesa-donations' ),
			'progress_asc'  => __( 'Progress: Low → High', 'pesa-donations' ),
			'goal_desc'     => __( 'Goal: High → Low', 'pesa-donations' ),
			'goal_asc'      => __( 'Goal: Low → High', 'pesa-donations' ),
		];

		$per_page_options = array_unique( [ 12, 24, 48, $per_page ] );
		sort( $per_page_options );

		$config = [
			'type'       => $type,
			'perPage'    => $per_page,
			'columns'    => $columns,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'sortLabels' => $sort_labels,
			'i18n'       => [
				'cta'        => 'sponsorship' === $type ? __( 'Sponsor Now', 'pesa-donations' ) : __( 'Donate Now', 'pesa-donations' ),
				'empty'      => __( 'No results match your filters.', 'pesa-donations' ),
				'found'      => __( 'Results found', 'pesa-donations' ),
				'searchPh'   => 'sponsorship' === $type ? __( 'Search by name…', 'pesa-donations' ) : __( 'Search projects…', 'pesa-donations' ),
				'viewDetail' => __( 'View Details', 'pesa-donations' ),
			],
		];

		ob_start();
		?>
		<div class="pd-browse pd-browse--<?php echo esc_attr( $type ); ?><?php echo $show_filters ? '' : ' pd-browse--no-filters'; ?>"
		     x-data="pdBrowse()">
			<?php echo $this->data_block( [ 'config' => $config, 'items' => $items ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, hex-escaped. ?>

			<?php if ( $show_filters ) : ?>
			<div class="pd-browse__sidebar-overlay"
			     x-show="filtersOpen" x-cloak
			     @click="filtersOpen = false"
			     style="display:none;"></div>

			<aside class="pd-browse__sidebar"
			       :class="{ 'pd-browse__sidebar--open': filtersOpen }">

				<button type="button" class="pd-browse__sidebar-close"
				        @click="filtersOpen = false"
				        aria-label="<?php esc_attr_e( 'Close filters', 'pesa-donations' ); ?>">&times;</button>

				<div class="pd-browse__search-wrap">
					<input type="search"
					       class="pd-browse__search"
					       :placeholder="i18n.searchPh"
					       aria-label="<?php esc_attr_e( 'Search', 'pesa-donations' ); ?>"
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
								<input type="checkbox" value="<?php echo esc_attr( $key ); ?>" x-model="filters.ageRange" />
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
								<input type="checkbox" value="<?php echo esc_attr( $key ); ?>" x-model="filters.goalRange" />
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<button type="button" class="pd-btn pd-btn--ghost pd-btn--sm pd-browse__reset"
				        @click="resetFilters()"
				        x-show="hasActiveFilters" x-cloak style="display:none;">
					<?php esc_html_e( 'Reset Filters', 'pesa-donations' ); ?>
				</button>

			</aside>
			<?php endif; ?>

			<section class="pd-browse__main">

				<div class="pd-browse__toolbar">
					<?php if ( $show_filters ) : ?>
					<button type="button" class="pd-browse__filter-btn"
					        @click="filtersOpen = true"
					        aria-label="<?php esc_attr_e( 'Open filters', 'pesa-donations' ); ?>">
						<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
							<path fill="currentColor" d="M0 2h16L10 9v5L6 16V9z"/>
						</svg>
						<span><?php esc_html_e( 'Filters', 'pesa-donations' ); ?></span>
						<span class="pd-browse__filter-count" x-show="hasActiveFilters" x-cloak style="display:none;" x-text="activeFilterCount"></span>
					</button>
					<?php endif; ?>

					<div class="pd-browse__view-toggle" role="group" aria-label="<?php esc_attr_e( 'View', 'pesa-donations' ); ?>">
						<button type="button"
						        class="pd-view-btn"
						        :class="{ 'pd-view-btn--active': isGridView }"
						        :aria-pressed="isGridView ? 'true' : 'false'"
						        @click="setView(true)"
						        aria-label="<?php esc_attr_e( 'Grid view', 'pesa-donations' ); ?>">
							<span class="pd-icon pd-icon--grid" aria-hidden="true">
								<span></span><span></span><span></span><span></span>
							</span>
						</button>
						<button type="button"
						        class="pd-view-btn"
						        :class="{ 'pd-view-btn--active': isListView }"
						        :aria-pressed="isListView ? 'true' : 'false'"
						        @click="setView(false)"
						        aria-label="<?php esc_attr_e( 'List view', 'pesa-donations' ); ?>">
							<span class="pd-icon pd-icon--list" aria-hidden="true">
								<span></span><span></span><span></span>
							</span>
						</button>
					</div>

					<p class="pd-browse__count" aria-live="polite">
						<span x-text="filtered.length"></span>
						<span x-text="i18n.found"></span>
					</p>

					<div class="pd-browse__toolbar-right">

						<div class="pd-select-wrap pd-select-wrap--sort">
							<span class="pd-icon pd-icon--sort" aria-hidden="true">
								<span></span><span></span><span></span>
							</span>
							<span class="pd-select-label" x-text="sortLabel"></span>
							<span class="pd-select-chevron" aria-hidden="true"></span>
							<select class="pd-select" x-model="sort"
							        aria-label="<?php esc_attr_e( 'Sort', 'pesa-donations' ); ?>">
								<?php foreach ( $sort_labels as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="pd-select-wrap pd-select-wrap--perpage">
							<span class="pd-icon pd-icon--stack" aria-hidden="true">
								<span></span><span></span><span></span>
							</span>
							<span class="pd-select-label" x-text="perPage"></span>
							<span class="pd-select-chevron" aria-hidden="true"></span>
							<select class="pd-select" x-model.number="perPage"
							        aria-label="<?php esc_attr_e( 'Items per page', 'pesa-donations' ); ?>">
								<?php foreach ( $per_page_options as $n ) : ?>
									<option value="<?php echo (int) $n; ?>"><?php echo (int) $n; ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<p class="pd-empty" x-show="showEmpty" x-cloak style="display:none;" x-text="i18n.empty"></p>

				<div class="pd-grid pd-grid--<?php echo (int) $columns; ?>col"
				     x-show="showGrid" x-cloak>
					<template x-for="(c, i) in paginated" :key="c.id">
						<article :class="c.is_sponsorship ? 'pd-card pd-card--sponsorship' : 'pd-card pd-card--project'">
							<div class="pd-card__media">
								<?php /* :loading before :src — Alpine binds in order, and an image decides lazy or not when its src is set. */ ?>
								<img :loading="imageLoading(i)" :src="c.thumbnail" :alt="c.display_title"
								     class="pd-card__image"
								     x-show="c.thumbnail" />
								<div class="pd-card__image pd-card__image--placeholder" x-show="!c.thumbnail"></div>
								<span class="pd-tag pd-tag--sponsorship" x-show="c.is_sponsorship"><?php esc_html_e( 'Sponsorship', 'pesa-donations' ); ?></span>
								<span class="pd-tag pd-tag--project" x-show="!c.is_sponsorship"><?php esc_html_e( 'Project', 'pesa-donations' ); ?></span>
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

								<p class="pd-card__period" x-show="c.period_label">
									<span class="pd-card__period-label" x-text="c.period_label"></span>
									<span x-text="c.period_note"></span>
								</p>

								<h3 class="pd-card__title" x-text="c.display_title"></h3>

								<p class="pd-card__meta" x-show="c.location" x-text="c.location"></p>

								<p class="pd-card__meta pd-card__meta--muted"
								   x-show="c.show_age">
									<?php esc_html_e( 'Age', 'pesa-donations' ); ?>: <span x-text="c.age"></span>
								</p>

								<p class="pd-card__excerpt"
								   x-show="c.summary"
								   x-text="c.summary"></p>

								<div class="pd-card__actions">
									<button type="button" class="pd-btn pd-btn--outline"
									        @click="openDetails(c.id)" x-text="i18n.viewDetail"></button>
									<a :href="c.checkout_url" class="pd-btn pd-btn--primary" x-text="i18n.cta"></a>
								</div>
							</div>
						</article>
					</template>
				</div>

				<div class="pd-list"
				     x-show="showList" x-cloak style="display:none;">
					<template x-for="(c, i) in paginated" :key="c.id">
						<article class="pd-list-row">
							<div class="pd-list-row__media">
								<img :loading="imageLoading(i)" :src="c.thumbnail" :alt="c.display_title"
								     x-show="c.thumbnail" />
								<div class="pd-card__image--placeholder" x-show="!c.thumbnail" style="width:100%;height:100%;"></div>
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

								<p class="pd-list-row__price" x-show="c.has_progress">
									<span class="pd-raised" x-text="c.raised_fmt + ' ' + c.currency"></span>
									<span class="pd-list-row__of">
										<?php esc_html_e( 'raised of', 'pesa-donations' ); ?>
										<span class="pd-goal" x-text="c.goal_fmt + ' ' + c.currency"></span>
									</span>
								</p>

								<p class="pd-card__period" x-show="c.period_label">
									<span class="pd-card__period-label" x-text="c.period_label"></span>
									<span x-text="c.period_note"></span>
								</p>

								<p class="pd-list-row__excerpt" x-show="c.summary" x-text="c.summary"></p>

								<div class="pd-list-row__actions">
									<button type="button" class="pd-btn pd-btn--outline pd-btn--sm"
									        @click="openDetails(c.id)" x-text="i18n.viewDetail"></button>
									<a :href="c.checkout_url" class="pd-btn pd-btn--primary pd-btn--sm" x-text="i18n.cta"></a>
								</div>
							</div>
						</article>
					</template>
				</div>

				<nav class="pd-browse__pagination" x-show="showPaginator" x-cloak style="display:none;" aria-label="<?php esc_attr_e( 'Pagination', 'pesa-donations' ); ?>">
					<button type="button" class="pd-page-btn" :disabled="onFirstPage" @click="goToPage(currentPage - 1)"
					        aria-label="<?php esc_attr_e( 'Previous page', 'pesa-donations' ); ?>">&lsaquo;</button>
					<template x-for="p in totalPages" :key="p">
						<button type="button" class="pd-page-btn" :class="{ 'pd-page-btn--active': p === currentPage }"
						        :aria-current="p === currentPage ? 'page' : null" @click="goToPage(p)" x-text="p"></button>
					</template>
					<button type="button" class="pd-page-btn" :disabled="onLastPage" @click="goToPage(currentPage + 1)"
					        aria-label="<?php esc_attr_e( 'Next page', 'pesa-donations' ); ?>">&rsaquo;</button>
				</nav>

			</section>

			<?php $this->render_details_modal(); ?>

		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_sponsor_slider] / [pd_give_slider] — horizontal carousels
	// -------------------------------------------------------------------------

	public function render_sponsor_slider( array $atts ): string {
		return $this->render_slider( 'sponsorship', $atts, 'pd_sponsor_slider' );
	}

	public function render_give_slider( array $atts ): string {
		return $this->render_slider( 'project', $atts, 'pd_give_slider' );
	}

	private function render_slider( string $type, array $atts, string $tag ): string {
		$atts = shortcode_atts( [
			'limit'    => 10,
			'per_view' => 3,
			'autoplay' => 'false',
			'interval' => 4500,
		], $atts, $tag );

		$campaigns = $this->query_campaigns( $type, max( 1, min( 50, (int) $atts['limit'] ) ) );

		if ( empty( $campaigns ) ) {
			return '';
		}

		$per_view = max( 1, min( 5, (int) $atts['per_view'] ) );
		$config   = [
			'autoplay' => filter_var( $atts['autoplay'], FILTER_VALIDATE_BOOLEAN ),
			'interval' => max( 1500, (int) $atts['interval'] ),
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		];

		ob_start();
		?>
		<div class="pd-slider pd-slider--<?php echo esc_attr( $type ); ?>"
		     style="--pd-slider-cols: <?php echo (int) $per_view; ?>;"
		     x-data="pdSlider()"
		     @mouseenter="pauseAutoplay()"
		     @mouseleave="resumeAutoplay()"
		     @focusin="pauseAutoplay()"
		     @focusout="resumeAutoplay()">
			<?php echo $this->data_block( [ 'config' => $config, 'items' => array_map( static fn( Campaign $c ) => $c->to_json_array(), $campaigns ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, hex-escaped. ?>

			<button type="button" class="pd-slider__arrow pd-slider__arrow--prev"
			        @click="prev()" aria-label="<?php esc_attr_e( 'Previous', 'pesa-donations' ); ?>">&lsaquo;</button>

			<div class="pd-slider__viewport">
				<div class="pd-slider__track" x-ref="track">
					<?php foreach ( $campaigns as $i => $campaign ) : ?>
						<div class="pd-slider__slide">
							<?php $this->render_card( $campaign, $i < $per_view ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<button type="button" class="pd-slider__arrow pd-slider__arrow--next"
			        @click="next()" aria-label="<?php esc_attr_e( 'Next', 'pesa-donations' ); ?>">&rsaquo;</button>

			<?php $this->render_details_modal(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_checkout] — used on the Donation Checkout page
	// -------------------------------------------------------------------------

	public function render_checkout( array $atts ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$campaign_id = isset( $_GET['pd_cid'] ) ? absint( $_GET['pd_cid'] ) : 0;

		// No campaign in the URL: the open donation form, when it is switched on.
		if ( ! $campaign_id && Open_Donation::is_enabled() ) {
			return $this->render_open_donation( [] );
		}

		$campaign = $campaign_id ? Campaign::get( $campaign_id ) : null;

		if ( ! $campaign || ! $campaign->accepts_donations() ) {
			return '<p class="pd-error">' . esc_html__( 'Campaign not found or no longer active.', 'pesa-donations' ) . '</p>';
		}

		ob_start();
		$this->load_template( 'sponsorship-checkout', [ 'campaign' => $campaign ] );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_donate title="Support our work" amounts="20000,50000"] — open donation
	// -------------------------------------------------------------------------

	public function render_open_donation( array $atts ): string {
		$atts = shortcode_atts( [
			'title'   => '',
			'amounts' => '',
		], $atts, 'pd_donate' );

		if ( ! Open_Donation::is_enabled() ) {
			return current_user_can( 'manage_options' )
				? '<p class="pd-error">' . esc_html__( 'Open donations are switched off in Settings → Open Donations. Only admins see this message.', 'pesa-donations' ) . '</p>'
				: '';
		}

		ob_start();
		// Its own template name, so a theme that overrides the campaign checkout
		// never receives a null campaign. The default includes the checkout template.
		$this->load_template( 'donation-form', [
			'campaign'   => null,
			'open_title' => '' !== $atts['title'] ? (string) $atts['title'] : Open_Donation::title(),
			'open_intro' => Open_Donation::intro(),
			'suggested'  => Open_Donation::suggested_amounts( (string) $atts['amounts'] ),
		] );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [pd_thank_you]
	// -------------------------------------------------------------------------

	public function render_thank_you( array $atts ): string {
		// The status on this page changes within seconds of a payment: a page
		// cache holding "Processing payment" would show it to the donor for good.
		$this->do_not_cache();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uuid_raw = $_GET['pd_d'] ?? ( $_GET['d'] ?? '' );
		$uuid     = is_string( $uuid_raw ) ? sanitize_text_field( wp_unslash( $uuid_raw ) ) : '';
		$donation = $uuid ? Donation::get_by_uuid( $uuid ) : null;

		// Navigation URLs
		$home_url     = home_url( '/' );
		$campaign     = $donation ? Campaign::get( $donation->get_campaign_id() ) : null;
		$is_open      = $donation && Open_Donation::CAMPAIGN_ID === $donation->get_campaign_id();
		// An open donation is a gift to the organisation itself: "to {site name}".
		$campaign_name = $campaign
			? ( $campaign->get_beneficiary_name() ?: $campaign->get_title() )
			: ( $is_open ? get_bloginfo( 'name' ) : '' );
		$retry_url    = $campaign ? $campaign->get_checkout_url() : ( $is_open ? Open_Donation::form_url() : '' );

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
				/* translators: %s: campaign or beneficiary name */
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
			$subheading  = __( 'No funds were taken. You can try again or reach out if you need help.', 'pesa-donations' );
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

				<span class="<?php echo esc_attr( $icon_class ); ?>" aria-hidden="true"><?php echo $icon_glyph; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entity. ?></span>

				<p class="pd-thanks__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<h1 class="pd-thanks__heading"><?php echo esc_html( $heading ); ?></h1>

				<?php if ( $subheading ) : ?>
					<p class="pd-thanks__sub"><?php echo esc_html( $subheading ); ?></p>
				<?php endif; ?>

				<?php if ( $donation && $is_completed ) : ?>
					<div class="pd-thanks__amount">
						<span class="pd-thanks__amount-value">
							<?php echo esc_html( number_format( $donation->get_amount(), Sanitizer::is_zero_decimal( $donation->get_currency() ) ? 0 : 2 ) ); ?>
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
					<?php if ( $is_failed && $retry_url ) : ?>
						<a href="<?php echo esc_url( $retry_url ); ?>" class="pd-btn pd-btn--outline pd-btn--lg">
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

	/** Asks page caches (WP Super Cache, W3TC, WP Rocket, LiteSpeed) to skip this response. */
	private function do_not_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'pesa-donations: donation status page' );
	}

	/**
	 * Published campaigns of one type that can take a donation today, newest
	 * first, with everything the cards read loaded up front: post meta (the
	 * query's own cache), featured images, and every total in one query.
	 * Before, each card cost several queries of its own.
	 *
	 * @return Campaign[]
	 */
	private function query_campaigns( string $type, int $limit ): array {
		$query = new WP_Query( [
			'post_type'              => Campaign_CPT::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'     => '_pd_status',
					'value'   => [ 'active', 'reached' ],
					'compare' => 'IN',
				],
				[
					'key'     => '_pd_category',
					'value'   => 'sponsorship' === $type ? self::SPONSORSHIP_CATEGORIES : self::PROJECT_CATEGORIES,
					'compare' => 'IN',
				],
			],
		] );

		update_post_thumbnail_cache( $query );

		// Past its end date (before the daily job closes it) a card would lead to
		// a checkout that refuses the donation.
		$campaigns = array_values( array_filter(
			array_map( static fn( \WP_Post $p ): Campaign => new Campaign( $p ), $query->posts ),
			static fn( Campaign $c ): bool => $c->accepts_donations()
		) );

		Campaign_Totals::prime( $campaigns );

		return $campaigns;
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
