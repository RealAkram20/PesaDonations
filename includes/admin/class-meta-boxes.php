<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\CPT\Campaign_CPT;
use PesaDonations\Models\Campaign;
use PesaDonations\Models\Campaign_Period;
use PesaDonations\Models\Campaign_Schedule;
use PesaDonations\Models\Campaign_Totals;
use PesaDonations\Utils\Currencies;
use PesaDonations\Utils\Sanitizer;

class Meta_Boxes {

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ] );
		add_action( 'save_post_' . Campaign_CPT::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'print_save_notice' ] );
		add_action( 'admin_footer-post.php',     [ $this, 'print_type_toggle_js' ] );
		add_action( 'admin_footer-post-new.php', [ $this, 'print_type_toggle_js' ] );
	}

	/**
	 * Hide meta boxes that aren't relevant for the chosen campaign type.
	 * Runs on the campaign editor screen only.
	 */
	public function print_type_toggle_js(): void {
		$screen = get_current_screen();
		if ( ! $screen || Campaign_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}
		?>
		<script>
		(function($){
			$(function(){
				var $cat = $('#_pd_category');
				if (!$cat.length) return;

				// Meta box IDs grouped by the type they belong to.
				var sponsorshipBoxes = ['#pd_beneficiary', '#pd_sponsorship_settings'];
				var projectBoxes     = ['#pd_gallery'];

				function applyVisibility() {
					var type = $cat.val();
					var showSponsorship = (type === 'sponsorship');
					var showProject     = (type === 'project');

					$.each(sponsorshipBoxes, function(_, sel) {
						$(sel).toggle(showSponsorship);
					});
					$.each(projectBoxes, function(_, sel) {
						$(sel).toggle(showProject);
					});
				}

				$cat.on('change', applyVisibility);
				applyVisibility();
			});
		})(jQuery);
		</script>
		<?php
	}

	public function add(): void {
		$boxes = [
			[ 'pd_campaign_details',      __( 'Campaign Details', 'pesa-donations' ),      'render_details',      'normal', 'high' ],
			[ 'pd_duration',              __( 'Duration & Repeat', 'pesa-donations' ),     'render_duration',     'normal', 'high' ],
			[ 'pd_period_history',        __( 'Period History', 'pesa-donations' ),        'render_period_history', 'normal', 'default' ],
			[ 'pd_beneficiary',           __( 'Beneficiary', 'pesa-donations' ),            'render_beneficiary',  'normal', 'high' ],
			[ 'pd_donation_settings',     __( 'Donation Settings', 'pesa-donations' ),      'render_donation_settings', 'normal', 'default' ],
			[ 'pd_sponsorship_settings',  __( 'Sponsorship Plans', 'pesa-donations' ),      'render_sponsorship',  'normal', 'default' ],
			[ 'pd_gallery',               __( 'Project Gallery', 'pesa-donations' ),        'render_gallery',      'normal', 'default' ],
			[ 'pd_display_options',       __( 'Display Options', 'pesa-donations' ),        'render_display',      'side',   'default' ],
			[ 'pd_shortcodes_box',        __( 'Shortcodes', 'pesa-donations' ),             'render_shortcodes',   'side',   'default' ],
		];

		foreach ( $boxes as [ $id, $title, $callback, $context, $priority ] ) {
			add_meta_box( $id, $title, [ $this, $callback ], Campaign_CPT::POST_TYPE, $context, $priority );
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_gallery_assets' ] );
	}

	public function enqueue_gallery_assets( string $hook ): void {
		global $post_type;
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) && Campaign_CPT::POST_TYPE === $post_type ) {
			wp_enqueue_media();
		}
	}

	// -------------------------------------------------------------------------
	// Renderers
	// -------------------------------------------------------------------------

	public function render_details( \WP_Post $post ): void {
		wp_nonce_field( 'pd_meta_save_' . $post->ID, 'pd_meta_nonce' );

		$this->field_select( $post, '_pd_category', __( 'Type', 'pesa-donations' ), [
			'sponsorship' => __( 'Sponsorship (individual beneficiary)', 'pesa-donations' ),
			'project'     => __( 'Project (school, hospital, community cause, etc.)', 'pesa-donations' ),
		] );

		$this->field_select( $post, '_pd_status', __( 'Status', 'pesa-donations' ), [
			'active'  => __( 'Active', 'pesa-donations' ),
			'paused'  => __( 'Paused', 'pesa-donations' ),
			'ended'   => __( 'Ended', 'pesa-donations' ),
			'reached' => __( 'Goal Reached', 'pesa-donations' ),
		] );

		$this->field_text( $post, '_pd_goal_amount',   __( 'Goal Amount', 'pesa-donations' ), 'number' );

		// Goal, minimum and progress are kept in this currency; gifts in others are converted into it.
		$currencies = [];
		foreach ( Currencies::all() as $code => $name ) {
			$currencies[ $code ] = $code . ' — ' . $name;
		}
		$this->field_select( $post, '_pd_base_currency', __( 'Base Currency', 'pesa-donations' ), $currencies );
	}

	/**
	 * How long the campaign runs and whether it starts again: once, every
	 * week / month / 3 months / 6 months / year, or typed-in periods such as
	 * school terms. The end date moved here from Campaign Details (same meta key).
	 */
	public function render_duration( \WP_Post $post ): void {
		$type     = (string) get_post_meta( $post->ID, '_pd_cycle_type', true ) ?: Campaign_Schedule::ONCE;
		// Unset: the 1st of next month, so "every month" means calendar months
		// unless the admin picks another day (it used to default to today).
		$start    = (string) get_post_meta( $post->ID, '_pd_cycle_start', true )
			?: ( new \DateTimeImmutable( 'first day of next month', wp_timezone() ) )->format( 'Y-m-d' );
		$end_date = (string) get_post_meta( $post->ID, '_pd_end_date', true );
		$campaign = Campaign::get( $post->ID );
		$periods  = $campaign ? $campaign->get_custom_periods() : [];
		$remind   = $campaign ? $campaign->sends_cycle_reminders() : true;
		?>
		<div class="pd-duration">
			<p>
				<label for="_pd_cycle_type"><strong><?php esc_html_e( 'Repeat', 'pesa-donations' ); ?></strong></label><br>
				<select id="_pd_cycle_type" name="_pd_cycle_type" class="widefat">
					<?php foreach ( Campaign_Schedule::types() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="description" data-pd-show="repeating">
					<?php esc_html_e( 'When a new period starts, the amount raised, the donor count and the progress bar start again from zero. Every donation is kept, and each period\'s total is listed under Period History.', 'pesa-donations' ); ?>
				</span>
			</p>

			<p data-pd-show="fixed">
				<label for="_pd_cycle_start"><strong><?php esc_html_e( 'First period starts', 'pesa-donations' ); ?></strong></label><br>
				<input type="date" id="_pd_cycle_start" name="_pd_cycle_start" value="<?php echo esc_attr( $start ); ?>" class="widefat" />
				<span class="description"><?php esc_html_e( 'Later periods start on the same weekday or day of the month. Pick the 1st for calendar months.', 'pesa-donations' ); ?></span>
			</p>

			<div data-pd-show="custom">
				<p>
					<strong><?php esc_html_e( 'Periods', 'pesa-donations' ); ?></strong><br>
					<span class="description"><?php esc_html_e( 'One row per term or period. A donation made between two periods, e.g. in the school holidays, counts toward the next one.', 'pesa-donations' ); ?></span>
				</p>
				<table class="widefat striped pd-periods">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'pesa-donations' ); ?></th>
							<th><?php esc_html_e( 'Starts', 'pesa-donations' ); ?></th>
							<th><?php esc_html_e( 'Ends', 'pesa-donations' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'pesa-donations' ); ?></span></th>
						</tr>
					</thead>
					<tbody id="pd-period-rows">
						<?php
						foreach ( $periods as $i => $row ) {
							$this->render_period_row( (string) $i, $row );
						}
						?>
					</tbody>
				</table>
				<template id="pd-period-row-template"><?php $this->render_period_row( '__i__', [] ); ?></template>
				<p>
					<button type="button" class="button" id="pd-add-period"><?php esc_html_e( 'Add period', 'pesa-donations' ); ?></button>
				</p>
			</div>

			<p data-pd-show="repeating">
				<input type="hidden" name="_pd_cycle_reminders" value="0" />
				<label>
					<input type="checkbox" name="_pd_cycle_reminders" value="1" <?php checked( $remind ); ?> />
					<?php esc_html_e( 'Email last period\'s donors when a new period starts', 'pesa-donations' ); ?>
				</label>
			</p>

			<p>
				<label for="_pd_end_date"><strong><?php esc_html_e( 'End date (optional)', 'pesa-donations' ); ?></strong></label><br>
				<input type="date" id="_pd_end_date" name="_pd_end_date" value="<?php echo esc_attr( $end_date ); ?>" class="widefat" />
				<span class="description"><?php esc_html_e( 'Donations close after this day. A repeating campaign stops repeating. Leave blank to keep going.', 'pesa-donations' ); ?></span>
			</p>

			<?php $this->render_period_summary( $campaign ); ?>
		</div>

		<style>
			.pd-duration .description { display: block; margin-top: 4px; }
			.pd-periods { margin-bottom: 8px; }
			.pd-periods td { vertical-align: middle; }
			.pd-periods input[type="text"], .pd-periods input[type="date"] { width: 100%; }
			.pd-period-summary { background: #f6f7f7; border-left: 4px solid #2271b1; padding: 10px 14px; margin: 14px 0 4px; }
			.pd-period-summary p { margin: 4px 0; }
			.pd-period-summary--warn { border-left-color: #dba617; }
		</style>

		<script>
		(function($){
			$(function(){
				var $type = $('#_pd_cycle_type');
				var $rows = $('#pd-period-rows');
				var next  = $rows.children('tr').length;

				function applyVisibility() {
					var t = $type.val();
					$('[data-pd-show="repeating"]').toggle(t !== 'once');
					$('[data-pd-show="fixed"]').toggle(t !== 'once' && t !== 'custom');
					$('[data-pd-show="custom"]').toggle(t === 'custom');
					if (t === 'custom' && !$rows.children('tr').length) { addRow(); }
				}
				function addRow() {
					var html = $('#pd-period-row-template').html().replace(/__i__/g, 'n' + (next++));
					$rows.append(html);
				}

				$type.on('change', applyVisibility);
				$('#pd-add-period').on('click', function(e){ e.preventDefault(); addRow(); });
				$rows.on('click', '.pd-remove-period', function(e){ e.preventDefault(); $(this).closest('tr').remove(); });
				applyVisibility();
			});
		})(jQuery);
		</script>
		<?php
	}

	private function render_period_row( string $index, array $row ): void {
		$name = '_pd_cycle_periods[' . $index . ']';
		?>
		<tr>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name . '[id]' ); ?>" value="<?php echo esc_attr( (string) ( $row['id'] ?? '' ) ); ?>" />
				<input type="text" name="<?php echo esc_attr( $name . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>"
				       placeholder="<?php esc_attr_e( 'e.g. Term 1 2027', 'pesa-donations' ); ?>" aria-label="<?php esc_attr_e( 'Period name', 'pesa-donations' ); ?>" />
			</td>
			<td><input type="date" name="<?php echo esc_attr( $name . '[start]' ); ?>" value="<?php echo esc_attr( (string) ( $row['start'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'Starts', 'pesa-donations' ); ?>" /></td>
			<td><input type="date" name="<?php echo esc_attr( $name . '[end]' ); ?>" value="<?php echo esc_attr( (string) ( $row['end'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'Ends', 'pesa-donations' ); ?>" /></td>
			<td><button type="button" class="button-link pd-remove-period" aria-label="<?php esc_attr_e( 'Remove period', 'pesa-donations' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/** What the saved settings mean right now: the live period, its total, the next reset. */
	private function render_period_summary( ?Campaign $campaign ): void {
		if ( ! $campaign || ! $campaign->repeats() ) {
			return;
		}

		$schedule = $campaign->get_schedule();
		$current  = $campaign->get_current_period();

		if ( ! $current ) {
			printf(
				'<div class="pd-period-summary pd-period-summary--warn"><p>%s</p></div>',
				esc_html__( 'Every period has ended. Add the next period\'s dates and set Status to Active to reopen this campaign.', 'pesa-donations' )
			);
			return;
		}

		$started  = $current->has_started( $schedule->today() );
		$goal     = $campaign->get_goal_amount();
		$currency = $campaign->get_base_currency();
		$raised   = number_format( $campaign->get_raised_amount() );
		$stats    = get_post_meta( $campaign->get_id(), '_pd_cycle_reminder_stats', true );
		$rows     = $campaign->get_custom_periods();
		$last_end = $rows ? max( array_column( $rows, 'end' ) ) : '';
		$is_last  = Campaign_Schedule::CUSTOM === $schedule->get_type()
			&& $last_end === $current->get_end()->format( 'Y-m-d' );
		?>
		<div class="pd-period-summary<?php echo $is_last ? ' pd-period-summary--warn' : ''; ?>">
			<p>
				<?php echo esc_html( $started ? __( 'This period:', 'pesa-donations' ) : __( 'Next period:', 'pesa-donations' ) ); ?>
				<strong><?php echo esc_html( $current->get_label() ); ?></strong>
				(<?php echo esc_html( $current->get_date_range() ); ?>)
			</p>
			<p>
				<?php
				echo esc_html( $goal > 0
					/* translators: 1: raised, 2: goal, 3: currency, 4: donor count */
					? sprintf( __( 'Raised so far: %1$s of %2$s %3$s from %4$d donor(s).', 'pesa-donations' ), $raised, number_format( $goal ), $currency, $campaign->get_donor_count() )
					/* translators: 1: raised, 2: currency, 3: donor count */
					: sprintf( __( 'Raised so far: %1$s %2$s from %3$d donor(s).', 'pesa-donations' ), $raised, $currency, $campaign->get_donor_count() )
				);
				?>
			</p>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: %s: date */
					__( 'Starts again from zero on %s.', 'pesa-donations' ),
					Campaign_Period::format_day( $current->get_reset_date() )
				) );
				if ( $is_last ) {
					echo ' <strong>' . esc_html__( 'This is the last period entered: add the next one, or the campaign ends after it.', 'pesa-donations' ) . '</strong>';
				}
				?>
			</p>
			<?php if ( is_array( $stats ) && isset( $stats['sent'] ) ) : ?>
				<p>
					<?php
					echo esc_html( sprintf(
						/* translators: 1: period label, 2: emails sent, 3: emails failed, 4: date and time */
						__( 'Reminders for %1$s: %2$d sent, %3$d failed (last batch %4$s).', 'pesa-donations' ),
						(string) ( $stats['label'] ?? '' ),
						(int) $stats['sent'],
						(int) $stats['failed'],
						mysql2date( 'j M Y, H:i', (string) ( $stats['updated'] ?? '' ) )
					) );
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Each period's total, newest first, with a link to its donations. Totals are
	 * computed from the donations' own dates, so editing a period's dates re-files
	 * the donations under the new dates.
	 */
	public function render_period_history( \WP_Post $post ): void {
		$campaign = Campaign::get( $post->ID );
		if ( ! $campaign || ! $campaign->repeats() ) {
			echo '<p class="description">' . esc_html__( 'This campaign runs once, so every donation counts toward one total. Choose a repeat under Duration & Repeat to track periods here.', 'pesa-donations' ) . '</p>';
			return;
		}

		$schedule = $campaign->get_schedule();
		$periods  = array_reverse( $schedule->recent( 12 ) );
		$first    = $schedule->first();
		$goal     = $campaign->get_goal_amount();
		$currency = $campaign->get_base_currency();
		$today    = $schedule->today();
		$current  = $campaign->get_current_period();

		$rows = [];
		foreach ( $periods as $period ) {
			$dates = $period->get_date_range();
			if ( $period->get_counts_from() < $period->get_start() ) {
				// Gifts made in the gap before it count toward it: say from when.
				$dates .= ' — ' . sprintf(
					/* translators: %s: date the period's counting starts */
					__( 'gifts from %s', 'pesa-donations' ),
					Campaign_Period::format_day( $period->get_counts_from(), $period->get_counts_from()->format( 'Y' ) !== $period->get_end()->format( 'Y' ) )
				);
			}
			$rows[] = [
				'label'  => $period->get_label(),
				'dates'  => $dates,
				'note'   => ( $current && $current->get_key() === $period->get_key() )
					? ( $period->has_started( $today ) ? __( 'current', 'pesa-donations' ) : __( 'upcoming', 'pesa-donations' ) )
					: '',
				'totals' => $campaign->get_totals_between( $period->window_start_sql(), $period->window_end_sql() ),
				'from'   => $period->get_counts_from()->format( 'Y-m-d' ),
				'to'     => $period->get_end()->format( 'Y-m-d' ),
			];
		}

		// Donations from before the repeat was switched on are kept, in their own row.
		$before_to = $first->get_counts_from()->modify( '-1 day' );
		$earlier   = $campaign->get_totals_between( '', $before_to->format( 'Y-m-d 23:59:59' ) );
		if ( $earlier['count'] > 0 ) {
			$rows[] = [
				'label'  => __( 'Before repeating began', 'pesa-donations' ),
				'dates'  => sprintf(
					/* translators: %s: date */
					__( 'up to %s', 'pesa-donations' ),
					Campaign_Period::format_day( $before_to )
				),
				'note'   => '',
				'totals' => $earlier,
				'from'   => '',
				'to'     => $before_to->format( 'Y-m-d' ),
			];
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Period', 'pesa-donations' ); ?></th>
					<th><?php esc_html_e( 'Dates', 'pesa-donations' ); ?></th>
					<th><?php esc_html_e( 'Raised', 'pesa-donations' ); ?></th>
					<th><?php esc_html_e( 'Donors', 'pesa-donations' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Donations', 'pesa-donations' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) :
					$raised = $row['totals']['raised'];
					$url    = add_query_arg( array_filter( [
						'page'               => 'pd-donations',
						'pd_campaign_filter' => $campaign->get_id(),
						'pd_status'          => 'completed',
						'pd_from'            => $row['from'],
						'pd_to'              => $row['to'],
					] ), admin_url( 'admin.php' ) );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $row['label'] ); ?></strong>
							<?php if ( $row['note'] ) : ?>
								<span class="description">(<?php echo esc_html( $row['note'] ); ?>)</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['dates'] ); ?></td>
						<td>
							<?php echo esc_html( number_format( $raised ) . ' ' . $currency ); ?>
							<?php if ( $goal > 0 && '' !== $row['from'] ) : ?>
								<span class="description">(<?php echo esc_html( round( ( $raised / $goal ) * 100 ) . '%' ); ?>)</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $row['totals']['donors'] ); ?></td>
						<td><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View donations', 'pesa-donations' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $first->get_key() !== end( $periods )->get_key() ) : ?>
			<p class="description"><?php esc_html_e( 'Showing the latest 12 periods. Use All Donations with a date range for older ones.', 'pesa-donations' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_beneficiary( \WP_Post $post ): void {
		$this->field_text( $post, '_pd_beneficiary_name',     __( 'Beneficiary Name', 'pesa-donations' ) );
		$this->field_text( $post, '_pd_beneficiary_location', __( 'Location (City, Country)', 'pesa-donations' ) );
		$this->field_text( $post, '_pd_beneficiary_birthday', __( 'Birthday', 'pesa-donations' ), 'date' );
		$this->field_text( $post, '_pd_beneficiary_code',     __( 'Beneficiary Code (e.g. CHI-04104)', 'pesa-donations' ) );
	}

	public function render_donation_settings( \WP_Post $post ): void {
		$this->field_text( $post, '_pd_minimum_amount', __( 'Minimum Donation Amount', 'pesa-donations' ), 'number' );
		$this->field_checkbox( $post, '_pd_allow_recurring',      __( 'Allow Recurring Donations', 'pesa-donations' ) );
		$this->field_checkbox( $post, '_pd_allow_anonymous',      __( 'Allow Anonymous Donations', 'pesa-donations' ) );
		$this->field_checkbox( $post, '_pd_single_currency',      __( 'Accept the base currency only (no currency choice)', 'pesa-donations' ) );
		$this->field_checkbox( $post, '_pd_checkout_require_address', __( 'Require Mailing Address at Checkout', 'pesa-donations' ) );

		echo '<p><strong>' . esc_html__( 'Suggested Amounts (JSON)', 'pesa-donations' ) . '</strong></p>';
		$amounts = get_post_meta( $post->ID, '_pd_suggested_amounts', true ) ?: '[{"amount":10000,"currency":"UGX"},{"amount":50000,"currency":"UGX"},{"amount":100000,"currency":"UGX"}]';
		echo '<textarea name="_pd_suggested_amounts" rows="4" class="widefat">' . esc_textarea( $amounts ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'JSON array of {amount, currency} objects.', 'pesa-donations' ) . '</p>';
	}

	public function render_sponsorship( \WP_Post $post ): void {
		$base_currency = get_post_meta( $post->ID, '_pd_base_currency', true ) ?: get_option( 'pd_default_currency', 'UGX' );

		$defaults_by_currency = [
			'UGX' => '[{"name":"Standard","amount":150000,"currency":"UGX"},{"name":"Plus","amount":200000,"currency":"UGX"}]',
			'KES' => '[{"name":"Standard","amount":4500,"currency":"KES"},{"name":"Plus","amount":6000,"currency":"KES"}]',
			'TZS' => '[{"name":"Standard","amount":90000,"currency":"TZS"},{"name":"Plus","amount":120000,"currency":"TZS"}]',
			'USD' => '[{"name":"Standard","amount":40,"currency":"USD"},{"name":"Plus","amount":50,"currency":"USD"}]',
		];
		$default = $defaults_by_currency[ $base_currency ] ?? $defaults_by_currency['USD'];
		$plans   = get_post_meta( $post->ID, '_pd_sponsorship_plans', true ) ?: $default;

		echo '<p>' . esc_html__( 'Define sponsorship plan tiers for this campaign (shown as buttons on the checkout).', 'pesa-donations' ) . '</p>';
		echo '<textarea name="_pd_sponsorship_plans" rows="5" class="widefat">' . esc_textarea( $plans ) . '</textarea>';
		echo '<p class="description">' . sprintf(
			/* translators: %s: base currency code */
			esc_html__( 'JSON array of {name, amount, currency} objects. Campaign base currency is %s — omit the "currency" field to inherit it.', 'pesa-donations' ),
			'<code>' . esc_html( $base_currency ) . '</code>'
		) . '</p>';
	}

	public function render_gallery( \WP_Post $post ): void {
		$ids_raw = get_post_meta( $post->ID, '_pd_gallery_ids', true );
		$ids     = is_array( $ids_raw ) ? $ids_raw : ( $ids_raw ? json_decode( $ids_raw, true ) : [] );
		$ids     = array_map( 'absint', (array) $ids );
		?>
		<div class="pd-gallery-picker">
			<p class="description">
				<?php esc_html_e( 'Upload multiple images for this project. Donors will see a gallery with lightbox on the public page. Best for project-type campaigns.', 'pesa-donations' ); ?>
			</p>

			<input type="hidden" name="_pd_gallery_ids" id="pd_gallery_ids" value="<?php echo esc_attr( wp_json_encode( $ids ) ); ?>" />

			<div class="pd-gallery-thumbs" id="pd_gallery_thumbs">
				<?php foreach ( $ids as $id ) :
					$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
					if ( ! $thumb ) {
						continue;
					}
					?>
					<div class="pd-gallery-thumb" data-id="<?php echo esc_attr( (string) $id ); ?>">
						<img src="<?php echo esc_url( $thumb ); ?>" alt="" />
						<button type="button" class="pd-gallery-remove" aria-label="<?php esc_attr_e( 'Remove', 'pesa-donations' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>

			<p>
				<button type="button" class="button button-secondary" id="pd_gallery_add">
					<?php esc_html_e( 'Add / Select Images', 'pesa-donations' ); ?>
				</button>
			</p>
		</div>

		<style>
			.pd-gallery-thumbs { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; min-height: 20px; }
			.pd-gallery-thumb { position: relative; width: 90px; height: 90px; border-radius: 6px; overflow: hidden; border: 2px solid #e0e0e0; }
			.pd-gallery-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
			.pd-gallery-remove { position: absolute; top: 2px; right: 2px; width: 22px; height: 22px; border: none; border-radius: 50%; background: rgba(0,0,0,.7); color: #fff; cursor: pointer; font-size: 16px; line-height: 1; display: flex; align-items: center; justify-content: center; padding: 0; }
			.pd-gallery-remove:hover { background: #c62828; }
		</style>

		<script>
		(function($){
			$(function(){
				var $hidden  = $('#pd_gallery_ids');
				var $thumbs  = $('#pd_gallery_thumbs');
				var frame;

				function getIds() {
					try { var v = JSON.parse($hidden.val() || '[]'); return Array.isArray(v) ? v : []; }
					catch(e) { return []; }
				}
				function setIds(ids) { $hidden.val(JSON.stringify(ids.map(function(i){return parseInt(i,10);}).filter(Boolean))); }

				$('#pd_gallery_add').on('click', function(e){
					e.preventDefault();
					if (frame) { frame.open(); return; }
					frame = wp.media({
						title:    'Select Gallery Images',
						button:   { text: 'Add to gallery' },
						multiple: true,
						library:  { type: 'image' }
					});
					frame.on('select', function(){
						var selection = frame.state().get('selection');
						var ids = getIds();
						selection.each(function(att){
							var id = att.id;
							if (ids.indexOf(id) === -1) {
								ids.push(id);
								var url = att.attributes.sizes && att.attributes.sizes.thumbnail
									? att.attributes.sizes.thumbnail.url
									: att.attributes.url;
								$thumbs.append(
									'<div class="pd-gallery-thumb" data-id="'+id+'">'+
									'<img src="'+url+'" alt=""/>'+
									'<button type="button" class="pd-gallery-remove">&times;</button>'+
									'</div>'
								);
							}
						});
						setIds(ids);
					});
					frame.open();
				});

				$thumbs.on('click', '.pd-gallery-remove', function(e){
					e.preventDefault();
					var $thumb = $(this).closest('.pd-gallery-thumb');
					var id = parseInt($thumb.data('id'), 10);
					var ids = getIds().filter(function(i){ return i !== id; });
					setIds(ids);
					$thumb.remove();
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	public function render_display( \WP_Post $post ): void {
		$this->field_checkbox( $post, '_pd_show_progress_bar', __( 'Show Progress Bar', 'pesa-donations' ) );
		$this->field_checkbox( $post, '_pd_show_donor_count',  __( 'Show Donor Count', 'pesa-donations' ) );
	}

	public function render_shortcodes( \WP_Post $post ): void {
		$id = $post->ID;
		$category = get_post_meta( $id, '_pd_category', true ) ?: 'project';

		// Context-aware: show the most relevant shortcodes for this campaign type.
		$codes = [
			"[pd_donate_button id=\"{$id}\"]",
		];
		if ( 'sponsorship' === $category ) {
			$codes[] = '[pd_sponsor_browse]';
			$codes[] = '[pd_sponsor_slider]';
		} else {
			$codes[] = '[pd_give_browse]';
			$codes[] = '[pd_give_slider]';
		}

		echo '<p>' . esc_html__( 'Copy and paste these into any page or post:', 'pesa-donations' ) . '</p>';
		foreach ( $codes as $code ) {
			echo '<p><code style="user-select:all;display:block;padding:6px 8px;font-size:12px;">' . esc_html( $code ) . '</code></p>';
		}
		echo '<p class="description">' . esc_html__( 'See the Shortcodes tab in Settings for the full reference.', 'pesa-donations' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field Helpers
	// -------------------------------------------------------------------------

	private function field_text( \WP_Post $post, string $key, string $label, string $type = 'text' ): void {
		$value = get_post_meta( $post->ID, $key, true );
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="widefat" /></p>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) $value )
		);
	}

	private function field_select( \WP_Post $post, string $key, string $label, array $options ): void {
		$value = get_post_meta( $post->ID, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="widefat">';
		foreach ( $options as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $value, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p>';
	}

	private function field_checkbox( \WP_Post $post, string $key, string $label ): void {
		$value = get_post_meta( $post->ID, $key, true );
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
			esc_attr( $key ),
			checked( $value, '1', false ),
			esc_html( $label )
		);
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	public function save( int $post_id, \WP_Post $post ): void {
		if (
			! isset( $_POST['pd_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pd_meta_nonce'] ) ), 'pd_meta_save_' . $post_id ) ||
			defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		// update_post_meta() unslashes what it is given, so every value is
		// slashed on the way in. Without it a JSON label such as "Term 1 – 2026"
		// (stored as \u2013) or a name with a backslash was saved corrupted.
		$put = static fn( string $key, $value ) => update_post_meta( $post_id, $key, wp_slash( $value ) );

		// Values with a fixed set: anything else keeps what is stored.
		$choices = [
			'_pd_category' => [ 'sponsorship', 'project' ],
			'_pd_status'   => [ 'active', 'paused', 'ended', 'reached' ],
		];
		foreach ( $choices as $key => $allowed ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( in_array( $value, $allowed, true ) ) {
				$put( $key, $value );
			}
		}
		if ( isset( $_POST['_pd_base_currency'] ) ) {
			$currency = Sanitizer::currency( wp_unslash( $_POST['_pd_base_currency'] ) );
			if ( Currencies::is_known( $currency ) ) {
				$put( '_pd_base_currency', $currency );
			}
		}
		foreach ( [ '_pd_end_date', '_pd_beneficiary_birthday' ] as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$put( $key, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '' );
		}

		$text_fields = [ '_pd_beneficiary_name', '_pd_beneficiary_location', '_pd_beneficiary_code' ];
		foreach ( $text_fields as $key ) {
			$put( $key, isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '' );
		}

		// "5,000,000" is five million: (float) stopped at the first comma and saved 5.
		$number = static function ( string $key ): ?float {
			$raw = isset( $_POST[ $key ] ) ? str_replace( [ ',', ' ' ], '', (string) wp_unslash( $_POST[ $key ] ) ) : '';
			return is_numeric( $raw ) && (float) $raw >= 0 ? (float) $raw : null;
		};
		$goal = $number( '_pd_goal_amount' );
		$put( '_pd_goal_amount', $goal ?? 0 );
		// Blank means "the site default", which is not the same as zero.
		$min = $number( '_pd_minimum_amount' );
		$put( '_pd_minimum_amount', null !== $min && $min > 0 ? $min : '' );

		$checkbox_fields = [
			'_pd_allow_recurring', '_pd_allow_anonymous', '_pd_single_currency',
			'_pd_show_progress_bar', '_pd_show_donor_count', '_pd_checkout_require_address',
		];
		foreach ( $checkbox_fields as $key ) {
			$put( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
		}

		foreach ( [ '_pd_suggested_amounts', '_pd_sponsorship_plans' ] as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw     = trim( (string) wp_unslash( $_POST[ $key ] ) );
			$decoded = json_decode( $raw, true );
			if ( '' === $raw ) {
				$put( $key, '' );
			} elseif ( is_array( $decoded ) ) {
				$put( $key, wp_json_encode( array_values( $decoded ) ) );
			} else {
				// Refused, not blanked: a typo must not silently wipe the tiers.
				set_transient(
					'pd_campaign_notice_' . get_current_user_id(),
					__( 'Amounts and plans must be valid JSON; the previous value was kept.', 'pesa-donations' ),
					MINUTE_IN_SECONDS
				);
			}
		}

		if ( isset( $_POST['_pd_gallery_ids'] ) ) {
			$decoded = json_decode( (string) wp_unslash( $_POST['_pd_gallery_ids'] ), true );
			update_post_meta( $post_id, '_pd_gallery_ids', array_values( array_unique( array_filter( array_map( 'absint', (array) $decoded ) ) ) ) );
		}

		$this->save_duration( $post_id );

		// Goal, currency and the period rules all change what the totals mean.
		Campaign_Totals::flush();
	}

	/**
	 * Saves the repeat settings. Invalid custom periods are refused as a whole
	 * (the previous ones stay) with a notice, never half-saved.
	 */
	private function save_duration( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save().
		if ( ! isset( $_POST['_pd_cycle_type'] ) ) {
			return;
		}

		$old_type = (string) get_post_meta( $post_id, '_pd_cycle_type', true ) ?: Campaign_Schedule::ONCE;
		$type     = sanitize_key( wp_unslash( $_POST['_pd_cycle_type'] ) );
		if ( ! array_key_exists( $type, Campaign_Schedule::types() ) ) {
			$type = Campaign_Schedule::ONCE;
		}

		update_post_meta( $post_id, '_pd_cycle_reminders', empty( $_POST['_pd_cycle_reminders'] ) ? '0' : '1' );

		// Validate everything before writing anything. Before, an invalid custom
		// period list was refused but the type was already saved as "custom", so
		// a campaign with no periods closed to donations.
		$errors = [];
		$rows   = [];
		if ( Campaign_Schedule::CUSTOM === $type ) {
			$raw  = isset( $_POST['_pd_cycle_periods'] ) && is_array( $_POST['_pd_cycle_periods'] ) ? wp_unslash( $_POST['_pd_cycle_periods'] ) : [];
			$rows = $this->clean_periods( $raw, $errors );
			if ( ! $errors && ! $rows ) {
				$errors[] = __( 'Add at least one period with a start and an end date.', 'pesa-donations' );
			}
		}
		if ( $errors ) {
			set_transient(
				'pd_campaign_notice_' . get_current_user_id(),
				__( 'The repeat settings were not saved:', 'pesa-donations' ) . ' ' . implode( ' ', $errors ),
				MINUTE_IN_SECONDS
			);
			return;
		}

		update_post_meta( $post_id, '_pd_cycle_type', $type );
		if ( Campaign_Schedule::CUSTOM === $type ) {
			update_post_meta( $post_id, '_pd_cycle_periods', wp_slash( wp_json_encode( $rows ) ) );
		} elseif ( Campaign_Schedule::ONCE !== $type ) {
			// The first-period date means something only to the fixed repeats.
			$start = sanitize_text_field( wp_unslash( $_POST['_pd_cycle_start'] ?? '' ) );
			update_post_meta( $post_id, '_pd_cycle_start', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ? $start : '' );
		}
		// phpcs:enable

		if ( Campaign_Schedule::ONCE !== $type
			&& ( Campaign_Schedule::ONCE === $old_type || ! get_post_meta( $post_id, '_pd_cycle_since', true ) ) ) {
			// Only donations from now on (or from the first period's start, if earlier) count toward period one.
			update_post_meta( $post_id, '_pd_cycle_since', current_time( 'mysql' ) );
		}

		// Record the period in force now, so a change made here never looks like
		// a new period to the hourly job and sends no reminder emails. Except
		// when the stored period is the one just before it: the period rolled
		// over and the job has not run yet. Writing the new key then would
		// swallow the rollover, and its reminders would never go out.
		$campaign = Campaign::get( $post_id );
		$current  = $campaign ? $campaign->get_current_period() : null;
		$stored   = (string) get_post_meta( $post_id, '_pd_cycle_current', true );
		$previous = $current && $campaign ? $campaign->get_schedule()->previous( $current ) : null;
		if ( ! ( $previous && '' !== $stored && $stored === $previous->get_key() && $type === $old_type ) ) {
			update_post_meta( $post_id, '_pd_cycle_current', $current ? $current->get_key() : '' );
		}
	}

	/**
	 * @param array    $raw    Rows as posted: {id, label, start, end}.
	 * @param string[] $errors Filled with a sentence per problem.
	 * @return array<int, array{id: string, label: string, start: string, end: string}> Sorted by start.
	 */
	private function clean_periods( array $raw, array &$errors ): array {
		$rows = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$start = sanitize_text_field( (string) ( $row['start'] ?? '' ) );
			$end   = sanitize_text_field( (string) ( $row['end'] ?? '' ) );
			if ( '' === $label && '' === $start && '' === $end ) {
				continue; // An untouched blank row.
			}
			$name = '' !== $label ? $label : __( 'A period', 'pesa-donations' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
				/* translators: %s: period name */
				$errors[] = sprintf( __( '%s needs both a start and an end date.', 'pesa-donations' ), $name );
				continue;
			}
			if ( $end < $start ) {
				/* translators: %s: period name */
				$errors[] = sprintf( __( '%s ends before it starts.', 'pesa-donations' ), $name );
				continue;
			}
			$id     = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$rows[] = [
				// A stable id: renaming or re-dating a period must not look like a new one.
				'id'    => '' !== $id ? $id : 'p' . strtolower( wp_generate_password( 10, false, false ) ),
				'label' => $label,
				'start' => $start,
				'end'   => $end,
			];
		}

		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['start'], $b['start'] ) );
		for ( $i = 1, $n = count( $rows ); $i < $n; $i++ ) {
			if ( $rows[ $i ]['start'] <= $rows[ $i - 1 ]['end'] ) {
				$errors[] = sprintf(
					/* translators: 1: period name, 2: period name */
					__( '%1$s overlaps %2$s.', 'pesa-donations' ),
					$rows[ $i ]['label'] ?: $rows[ $i ]['start'],
					$rows[ $i - 1 ]['label'] ?: $rows[ $i - 1 ]['start']
				);
			}
		}
		return $rows;
	}

	public function print_save_notice(): void {
		$key     = 'pd_campaign_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! $message ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( (string) $message ) );
	}
}
