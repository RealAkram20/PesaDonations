<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\CPT\Campaign_CPT;

class Meta_Boxes {

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ] );
		add_action( 'save_post_' . Campaign_CPT::POST_TYPE, [ $this, 'save' ], 10, 2 );
	}

	public function add(): void {
		$boxes = [
			[ 'pd_campaign_details',      __( 'Campaign Details', 'pesa-donations' ),      'render_details',      'normal', 'high' ],
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

		$this->field_select( $post, '_pd_base_currency', __( 'Base Currency', 'pesa-donations' ), [
			'UGX' => 'UGX',
			'KES' => 'KES',
			'TZS' => 'TZS',
			'USD' => 'USD',
		] );

		$this->field_text( $post, '_pd_end_date', __( 'End Date', 'pesa-donations' ), 'date' );
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
		$this->field_checkbox( $post, '_pd_allow_currency_switch', __( 'Allow Donor to Switch Currency', 'pesa-donations' ) );
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
		$codes = [
			"[pd_donate_button id=\"{$id}\"]",
			"[pd_sponsorships]",
			"[pd_projects]",
			"[pd_progress id=\"{$id}\"]",
		];
		echo '<p>' . esc_html__( 'Copy and paste these into any page or post:', 'pesa-donations' ) . '</p>';
		foreach ( $codes as $code ) {
			echo '<p><code style="user-select:all;display:block;padding:4px 8px;">' . esc_html( $code ) . '</code></p>';
		}
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

		$text_fields = [
			'_pd_category', '_pd_status', '_pd_base_currency', '_pd_end_date',
			'_pd_beneficiary_name', '_pd_beneficiary_location', '_pd_beneficiary_birthday', '_pd_beneficiary_code',
		];

		$number_fields = [ '_pd_goal_amount', '_pd_minimum_amount' ];

		$checkbox_fields = [
			'_pd_allow_recurring', '_pd_allow_anonymous', '_pd_allow_currency_switch',
			'_pd_show_progress_bar', '_pd_show_donor_count', '_pd_checkout_require_address',
		];

		$json_fields = [ '_pd_suggested_amounts', '_pd_sponsorship_plans' ];
		$id_list_fields = [ '_pd_gallery_ids' ];

		foreach ( $text_fields as $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '' );
		}
		foreach ( $number_fields as $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? (float) $_POST[ $key ] : 0 );
		}
		foreach ( $checkbox_fields as $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? '1' : '0' );
		}
		foreach ( $json_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw = wp_unslash( $_POST[ $key ] );
				$decoded = json_decode( $raw, true );
				update_post_meta( $post_id, $key, $decoded ? wp_json_encode( $decoded ) : '' );
			}
		}

		foreach ( $id_list_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw     = wp_unslash( $_POST[ $key ] );
				$decoded = json_decode( $raw, true );
				$clean   = array_values( array_filter( array_map( 'absint', (array) $decoded ) ) );
				update_post_meta( $post_id, $key, $clean );
			}
		}

		// Bust progress cache.
		delete_transient( 'pd_raised_' . $post_id );
	}
}
