<?php
declare( strict_types=1 );

namespace PesaDonations\CPT;

use PesaDonations\Models\Campaign;
use PesaDonations\Models\Campaign_Schedule;
use PesaDonations\Models\Campaign_Totals;
use PesaDonations\Utils\Money;

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
			// Its own capabilities, so a Donations Manager can run campaigns without
			// editing blog posts. Administrators get them via Roles::grant_to_administrators().
			'capability_type'    => [ 'pd_campaign', 'pd_campaigns' ],
			'map_meta_cap'       => true,
		] );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'custom_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_filter( 'the_posts', [ $this, 'prime_list_totals' ], 10, 2 );
	}

	/**
	 * The admin list shows every campaign's raised amount: read all of them for
	 * the page in one query, not one (plus a cache write) per row.
	 *
	 * @param mixed     $posts
	 * @param \WP_Query $query
	 * @return mixed
	 */
	public function prime_list_totals( $posts, $query ) {
		if ( is_admin() && is_array( $posts ) && $posts && $query instanceof \WP_Query && $query->is_main_query()
			&& self::POST_TYPE === $query->get( 'post_type' ) ) {
			Campaign_Totals::prime( array_map(
				static fn( \WP_Post $p ): Campaign => new Campaign( $p ),
				array_filter( $posts, static fn( $p ): bool => $p instanceof \WP_Post )
			) );
		}
		return $posts;
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
				$campaign = Campaign::get( $post_id );
				// get_category() maps 1.0's "child", "school"… onto the two types.
				echo esc_html( $campaign && $campaign->is_sponsorship() ? __( 'Sponsorship', 'pesa-donations' ) : __( 'Project', 'pesa-donations' ) );
				$repeat = (string) get_post_meta( $post_id, '_pd_cycle_type', true );
				$types  = Campaign_Schedule::types();
				if ( $repeat && Campaign_Schedule::ONCE !== $repeat && isset( $types[ $repeat ] ) ) {
					echo '<br><span style="color:#888;font-size:12px;">' . esc_html( $types[ $repeat ] ) . '</span>';
				}
				break;
			case 'pd_goal':
				$goal     = (float) get_post_meta( $post_id, '_pd_goal_amount', true );
				$currency = (string) get_post_meta( $post_id, '_pd_base_currency', true ) ?: 'UGX';
				echo $goal ? esc_html( Money::format( $goal, $currency ) ) : '&mdash;';
				break;
			case 'pd_raised':
				// The current period's total for a repeating campaign, all time otherwise.
				$campaign = Campaign::get( $post_id );
				if ( ! $campaign ) {
					echo '&mdash;';
					break;
				}
				echo esc_html( Money::format( $campaign->get_raised_amount(), $campaign->get_base_currency() ) );
				$period = $campaign->get_current_period();
				if ( $period ) {
					echo '<br><span style="color:#888;font-size:12px;">' . esc_html( $period->get_label() ) . '</span>';
				}
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
