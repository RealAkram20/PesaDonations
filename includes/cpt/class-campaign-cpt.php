<?php
declare( strict_types=1 );

namespace PesaDonations\CPT;

class Campaign_CPT {

	public const POST_TYPE = 'pd_campaign';

	public function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels'             => $this->labels(),
			'public'             => true,
			'show_in_rest'       => false,
			'menu_icon'          => 'dashicons-heart',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'revisions', 'excerpt' ],
			'rewrite'            => [ 'slug' => 'campaigns', 'with_front' => false ],
			'has_archive'        => false,
			'show_in_menu'       => false, // shown under PesaDonations top-level menu
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
		] );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'custom_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );

		// Yoast Duplicate Post (and the older non-Yoast fork) gates each
		// post type behind an opt-in option which defaults to ['post', 'page'].
		// Wire pd_campaign in via their public filter so the Clone / New
		// Draft / Rewrite & Republish row actions appear on Campaigns out
		// of the box, with no admin toggle required. Filter is a no-op if
		// the plugin is not active.
		add_filter( 'duplicate_post_enabled_post_types', [ $this, 'enable_duplicate_post' ] );

		// Admin: filter the campaigns list by ?pd_category=project|sponsorship
		// so the split "Donations" and "Sponsorships" submenu items show
		// only their respective campaigns.
		add_action( 'pre_get_posts', [ $this, 'filter_admin_list_by_category' ] );

		// Pre-fill the category meta box when an admin clicks "Add New
		// Donation" (?pd_category=project) or "Add New Sponsorship"
		// (?pd_category=sponsorship) so the editor opens with the right
		// type already selected.
		add_action( 'admin_head-post-new.php', [ $this, 'inject_default_category' ] );
	}

	public function filter_admin_list_by_category( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		if ( self::POST_TYPE !== ( $query->get( 'post_type' ) ?: '' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cat = isset( $_GET['pd_category'] ) ? sanitize_key( wp_unslash( $_GET['pd_category'] ) ) : '';
		if ( ! $cat ) {
			return;
		}
		$values = 'sponsorship' === $cat
			? [ 'sponsorship', 'child' ]
			: [ 'project', 'school', 'hospital', 'medical', 'other' ];

		// Merge with any existing meta_query so we don't blow away
		// filters that other plugins set on the same edit.php query.
		$existing = (array) $query->get( 'meta_query', [] );
		$existing[] = [
			'key'     => '_pd_category',
			'value'   => $values,
			'compare' => 'IN',
		];
		if ( ! isset( $existing['relation'] ) && count( $existing ) > 1 ) {
			$existing['relation'] = 'AND';
		}
		$query->set( 'meta_query', $existing );
	}

	public function inject_default_category(): void {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cat = isset( $_GET['pd_category'] ) ? sanitize_key( wp_unslash( $_GET['pd_category'] ) ) : '';
		if ( ! in_array( $cat, [ 'project', 'sponsorship' ], true ) ) {
			return;
		}
		// Inline JS that sets the category select on page load. Cleaner than
		// a server-side default because the meta box uses select_meta(...) and
		// reading get_post_meta on a brand-new post returns nothing anyway.
		?>
		<script>
		(function(){
			document.addEventListener('DOMContentLoaded', function(){
				var sel = document.getElementById('_pd_category');
				if (sel && !sel.value) { sel.value = <?php echo wp_json_encode( $cat ); ?>; }
			});
		})();
		</script>
		<?php
	}

	/**
	 * Add pd_campaign to Yoast Duplicate Post's allowlist.
	 *
	 * @param mixed $types Whatever the filter chain has so far — usually an
	 *                     array, but a defensive cast keeps us safe if a
	 *                     prior callback returned a string or null.
	 * @return array
	 */
	public function enable_duplicate_post( $types ): array {
		$types = is_array( $types ) ? $types : ( $types ? [ (string) $types ] : [] );
		if ( ! in_array( self::POST_TYPE, $types, true ) ) {
			$types[] = self::POST_TYPE;
		}
		return $types;
	}

	private function labels(): array {
		return [
			'name'               => __( 'Campaigns', 'pesa-donations' ),
			'singular_name'      => __( 'Campaign', 'pesa-donations' ),
			'add_new'            => __( 'Add New', 'pesa-donations' ),
			'add_new_item'       => __( 'Add New Campaign', 'pesa-donations' ),
			'edit_item'          => __( 'Edit Campaign', 'pesa-donations' ),
			'new_item'           => __( 'New Campaign', 'pesa-donations' ),
			'view_item'          => __( 'View Campaign', 'pesa-donations' ),
			'search_items'       => __( 'Search Campaigns', 'pesa-donations' ),
			'not_found'          => __( 'No campaigns found.', 'pesa-donations' ),
			'not_found_in_trash' => __( 'No campaigns found in Trash.', 'pesa-donations' ),
			'menu_name'          => __( 'Campaigns', 'pesa-donations' ),
		];
	}

	public function custom_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['pd_category']  = __( 'Type', 'pesa-donations' );
				$new['pd_goal']      = __( 'Goal', 'pesa-donations' );
				$new['pd_raised']    = __( 'Raised', 'pesa-donations' );
				$new['pd_status']    = __( 'Status', 'pesa-donations' );
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'pd_category':
				echo esc_html( ucfirst( (string) get_post_meta( $post_id, '_pd_category', true ) ) );
				break;
			case 'pd_goal':
				$goal     = (float) get_post_meta( $post_id, '_pd_goal_amount', true );
				$currency = (string) get_post_meta( $post_id, '_pd_base_currency', true ) ?: 'UGX';
				echo $goal ? esc_html( number_format( $goal ) . ' ' . $currency ) : '&mdash;';
				break;
			case 'pd_raised':
				global $wpdb;
				$raised = (float) $wpdb->get_var( $wpdb->prepare(
					"SELECT SUM(amount_base) FROM {$wpdb->prefix}pd_donations WHERE campaign_id = %d AND status = 'completed'",
					$post_id
				) );
				echo esc_html( number_format( $raised ) );
				break;
			case 'pd_status':
				$status = get_post_meta( $post_id, '_pd_status', true ) ?: 'active';
				$labels = [
					'active'  => '<span style="color:#28a745;">&#9679;</span> ' . __( 'Active', 'pesa-donations' ),
					'paused'  => '<span style="color:#ffc107;">&#9679;</span> ' . __( 'Paused', 'pesa-donations' ),
					'ended'   => '<span style="color:#6c757d;">&#9679;</span> ' . __( 'Ended', 'pesa-donations' ),
					'reached' => '<span style="color:#17a2b8;">&#9679;</span> ' . __( 'Goal Reached', 'pesa-donations' ),
				];
				echo wp_kses_post( $labels[ $status ] ?? esc_html( $status ) );
				break;
		}
	}
}
