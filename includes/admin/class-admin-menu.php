<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\CPT\Campaign_CPT;

class Admin_Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'PesaDonations', 'pesa-donations' ),
			__( 'PesaDonations', 'pesa-donations' ),
			'manage_options',
			'pesa-donations',
			[ $this, 'render_dashboard' ],
			'dashicons-heart',
			58
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Dashboard', 'pesa-donations' ),
			__( 'Dashboard', 'pesa-donations' ),
			'manage_options',
			'pesa-donations',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Campaigns', 'pesa-donations' ),
			__( 'Campaigns', 'pesa-donations' ),
			'manage_options',
			'edit.php?post_type=' . Campaign_CPT::POST_TYPE
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Add New Campaign', 'pesa-donations' ),
			__( 'Add New', 'pesa-donations' ),
			'manage_options',
			'post-new.php?post_type=' . Campaign_CPT::POST_TYPE
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Donations', 'pesa-donations' ),
			__( 'Donations', 'pesa-donations' ),
			'manage_options',
			'pd-donations',
			[ $this, 'render_donations' ]
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Settings', 'pesa-donations' ),
			__( 'Settings', 'pesa-donations' ),
			'manage_options',
			'pd-settings',
			[ $this, 'render_settings' ]
		);

		add_submenu_page(
			'pesa-donations',
			__( 'System Status', 'pesa-donations' ),
			__( 'System Status', 'pesa-donations' ),
			'manage_options',
			'pd-system-status',
			[ $this, 'render_system_status' ]
		);
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'pesa-donations' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'PesaDonations Dashboard', 'pesa-donations' ) . '</h1>';
		echo '<p>' . esc_html__( 'Welcome to PesaDonations. Use the menu to manage campaigns, donations and settings.', 'pesa-donations' ) . '</p>';

		$this->render_stats_cards();
		echo '</div>';
	}

	private function render_stats_cards(): void {
		global $wpdb;

		$total_donations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pd_donations WHERE status = 'completed'" );
		$total_raised    = (float) $wpdb->get_var( "SELECT SUM(amount_base) FROM {$wpdb->prefix}pd_donations WHERE status = 'completed'" );
		$total_campaigns = (int) wp_count_posts( 'pd_campaign' )->publish;
		$total_donors    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pd_donors" );

		$cards = [
			[ 'label' => __( 'Total Raised', 'pesa-donations' ),   'value' => number_format( $total_raised ) ],
			[ 'label' => __( 'Donations', 'pesa-donations' ),       'value' => number_format( $total_donations ) ],
			[ 'label' => __( 'Active Campaigns', 'pesa-donations' ), 'value' => number_format( $total_campaigns ) ],
			[ 'label' => __( 'Donors', 'pesa-donations' ),          'value' => number_format( $total_donors ) ],
		];

		echo '<div class="pd-admin-cards">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="pd-admin-card"><span class="pd-admin-card__value">%s</span><span class="pd-admin-card__label">%s</span></div>',
				esc_html( $card['value'] ),
				esc_html( $card['label'] )
			);
		}
		echo '</div>';
	}

	public function render_donations(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Donations', 'pesa-donations' ) . '</h1>';

		global $wpdb;
		$items = $wpdb->get_results(
			"SELECT d.*, c.post_title as campaign_title
			 FROM {$wpdb->prefix}pd_donations d
			 LEFT JOIN {$wpdb->posts} c ON c.ID = d.campaign_id
			 ORDER BY d.created_at DESC
			 LIMIT 100",
			ARRAY_A
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( [ 'Date', 'Campaign', 'Donor', 'Amount', 'Gateway', 'Status' ] as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $items as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s %s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['created_at'] ),
				esc_html( $row['campaign_title'] ?? '—' ),
				esc_html( $row['donor_name'] ?? $row['donor_email'] ?? '—' ),
				esc_html( number_format( (float) $row['amount'], 2 ) ),
				esc_html( $row['currency'] ),
				esc_html( ucfirst( $row['gateway'] ) ),
				'<span class="pd-status pd-status--' . esc_attr( $row['status'] ) . '">' . esc_html( ucfirst( $row['status'] ) ) . '</span>'
			);
		}

		echo '</tbody></table></div>';
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		( new Settings() )->render();
	}

	public function render_system_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'System Status', 'pesa-donations' ) . '</h1>';

		global $wpdb;
		$checks = [
			'PHP Version'              => PHP_VERSION . ' (min ' . PD_MIN_PHP . ')',
			'WordPress Version'        => get_bloginfo( 'version' ),
			'Plugin Version'           => PD_VERSION,
			'Donations Table'          => $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}pd_donations'" ) ? '&#9989; OK' : '&#10060; Missing',
			'Donors Table'             => $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}pd_donors'" ) ? '&#9989; OK' : '&#10060; Missing',
			'Checkout Page'            => get_option( 'pd_checkout_page_id' ) ? '&#9989; Created' : '&#10060; Missing',
			'PesaPal Environment'      => get_option( 'pd_pesapal_environment', 'sandbox' ),
			'PayPal Environment'       => get_option( 'pd_paypal_environment', 'sandbox' ),
			'WP Cron (FX Rates)'       => wp_next_scheduled( 'pd_daily_fx_rates' ) ? '&#9989; Scheduled' : '&#10060; Not scheduled',
		];

		echo '<table class="wp-list-table widefat fixed striped"><tbody>';
		foreach ( $checks as $label => $value ) {
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html( $label ), wp_kses_post( $value ) );
		}
		echo '</tbody></table></div>';
	}
}
