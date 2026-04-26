<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\CPT\Campaign_CPT;
use PesaDonations\Admin\Donations_List_Table;
use PesaDonations\Admin\Donation_Editor;
use PesaDonations\Admin\Donors_List_Table;
use PesaDonations\Admin\Donor_Editor;

class Admin_Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Donations', 'pesa-donations' ),
			__( 'Donations', 'pesa-donations' ),
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
			__( 'All Donations', 'pesa-donations' ),
			__( 'All Donations', 'pesa-donations' ),
			'manage_options',
			'pd-donations',
			[ $this, 'render_donations' ]
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Add New Donation', 'pesa-donations' ),
			__( 'Add New', 'pesa-donations' ),
			'manage_options',
			'pd-donation-new',
			[ $this, 'render_donation_editor' ]
		);

		// Hidden (not in menu) — for edit donation screen.
		// Empty parent slug instead of null: passing null is deprecated in
		// PHP 8.1+ (add_submenu_page expects string) and emits notices that
		// can break header output on hosts with display_errors=on.
		add_submenu_page(
			'',
			__( 'Edit Donation', 'pesa-donations' ),
			__( 'Edit Donation', 'pesa-donations' ),
			'manage_options',
			'pd-donation-edit',
			[ $this, 'render_donation_editor' ]
		);

		add_submenu_page(
			'pesa-donations',
			__( 'All Donors', 'pesa-donations' ),
			__( 'Donors', 'pesa-donations' ),
			'manage_options',
			'pd-donors',
			[ $this, 'render_donors' ]
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Add New Donor', 'pesa-donations' ),
			__( 'Add New Donor', 'pesa-donations' ),
			'manage_options',
			'pd-donor-new',
			[ $this, 'render_donor_editor' ]
		);

		// Hidden — edit donor screen.
		add_submenu_page(
			'',
			__( 'Edit Donor', 'pesa-donations' ),
			__( 'Edit Donor', 'pesa-donations' ),
			'manage_options',
			'pd-donor-edit',
			[ $this, 'render_donor_editor' ]
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

	public function render_donation_editor(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		( new Donation_Editor() )->render();
	}

	public function render_donors(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}

		$table = new Donors_List_Table();
		$table->process_bulk_action();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Donors', 'pesa-donations' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pd-donor-new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'pesa-donations' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php $this->render_donor_notices(); ?>

			<form method="get">
				<input type="hidden" name="page" value="pd-donors" />
				<?php
				$table->search_box( __( 'Search donors', 'pesa-donations' ), 'pd-donors' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_donor_editor(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		( new Donor_Editor() )->render();
	}

	private function render_donor_notices(): void {
		if ( isset( $_GET['pd_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg    = sanitize_key( wp_unslash( $_GET['pd_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$labels = [
				'saved'   => __( 'Donor saved.', 'pesa-donations' ),
				'created' => __( 'Donor created.', 'pesa-donations' ),
				'deleted' => __( 'Donor(s) deleted.', 'pesa-donations' ),
			];
			if ( isset( $labels[ $msg ] ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( $labels[ $msg ] )
				);
			}
		}
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

		$table = new Donations_List_Table();
		$table->process_bulk_action();
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Donations', 'pesa-donations' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pd-donation-new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'pesa-donations' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php $this->render_notices(); ?>

			<form method="get">
				<input type="hidden" name="page" value="pd-donations" />
				<?php
				$table->views();
				$table->search_box( __( 'Search donations', 'pesa-donations' ), 'pd-donations' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	private function render_notices(): void {
		if ( isset( $_GET['pd_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg    = sanitize_key( wp_unslash( $_GET['pd_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$labels = [
				'saved'    => __( 'Donation saved.', 'pesa-donations' ),
				'created'  => __( 'Donation created.', 'pesa-donations' ),
				'deleted'  => __( 'Donation deleted.', 'pesa-donations' ),
				'status'   => __( 'Status updated.', 'pesa-donations' ),
			];
			if ( isset( $labels[ $msg ] ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( $labels[ $msg ] )
				);
			}
		}
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

		// Handle the recalc-donors button.
		if (
			isset( $_POST['pd_recalc_donors'], $_POST['pd_recalc_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pd_recalc_nonce'] ) ), 'pd_recalc_donors' )
		) {
			$count = \PesaDonations\Models\Donor::recalculate_all();
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of donors */
					__( 'Recalculated aggregates for %d donor(s).', 'pesa-donations' ),
					$count
				) )
			);
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
		echo '</tbody></table>';

		// Maintenance tools.
		?>
		<h2 style="margin-top:32px;"><?php esc_html_e( 'Maintenance', 'pesa-donations' ); ?></h2>
		<form method="post" style="margin-top:12px;">
			<?php wp_nonce_field( 'pd_recalc_donors', 'pd_recalc_nonce' ); ?>
			<p>
				<button type="submit" name="pd_recalc_donors" value="1" class="button button-secondary">
					<?php esc_html_e( 'Recalculate Donor Totals', 'pesa-donations' ); ?>
				</button>
				<span class="description" style="margin-left:8px;">
					<?php esc_html_e( 'Rebuilds every donor\'s donation count and total given from the donations table. Safe to run anytime.', 'pesa-donations' ); ?>
				</span>
			</p>
		</form>
		<?php

		echo '</div>';
	}
}
