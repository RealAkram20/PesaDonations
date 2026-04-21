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
