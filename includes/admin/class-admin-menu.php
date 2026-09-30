<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\CPT\Campaign_CPT;
use PesaDonations\Core\Installer;
use PesaDonations\Models\Donation;
use PesaDonations\Models\Donor;
use PesaDonations\Payments\Pesapal\Pesapal_Gateway;
use PesaDonations\Payments\Pesapal\Pesapal_IPN;
use PesaDonations\Utils\Money;

class Admin_Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		// Donations → Dashboard opens the staff dashboard at its own address.
		add_action( 'load-toplevel_page_pesa-donations', [ $this, 'open_dashboard' ] );
	}

	public function open_dashboard(): void {
		if ( current_user_can( 'pd_view_dashboard' ) ) {
			wp_safe_redirect( \PesaDonations\Modules\Dashboard\Dashboard::url() );
			exit;
		}
	}

	/**
	 * Every form on these screens is handled on its page's load-{hook}: after
	 * the user and screen are known, before any output, so a save can redirect.
	 * Handling them inside the render callbacks (as before) wrote the data and
	 * then failed to redirect with "headers already sent".
	 */
	private function on_load( $hook, callable $handler ): void {
		if ( $hook ) {
			add_action( 'load-' . $hook, $handler );
		}
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Donations', 'pesa-donations' ),
			__( 'Donations', 'pesa-donations' ),
			'pd_manage_donations',
			'pesa-donations',
			[ $this, 'render_dashboard' ],
			'dashicons-heart',
			58
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Dashboard', 'pesa-donations' ),
			__( 'Dashboard', 'pesa-donations' ),
			'pd_view_dashboard',
			'pesa-donations',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Campaigns', 'pesa-donations' ),
			__( 'Campaigns', 'pesa-donations' ),
			'edit_pd_campaigns',
			'edit.php?post_type=' . Campaign_CPT::POST_TYPE
		);

		add_submenu_page(
			'pesa-donations',
			__( 'Add New Campaign', 'pesa-donations' ),
			__( 'Add New', 'pesa-donations' ),
			'edit_pd_campaigns',
			'post-new.php?post_type=' . Campaign_CPT::POST_TYPE
		);

		$this->on_load( add_submenu_page(
			'pesa-donations',
			__( 'All Donations', 'pesa-donations' ),
			__( 'All Donations', 'pesa-donations' ),
			'pd_manage_donations',
			'pd-donations',
			[ $this, 'render_donations' ]
		), [ Donations_List_Table::class, 'handle_actions' ] );

		$this->on_load( add_submenu_page(
			'pesa-donations',
			__( 'Add New Donation', 'pesa-donations' ),
			__( 'Add New', 'pesa-donations' ),
			'pd_manage_donations',
			'pd-donation-new',
			[ $this, 'render_donation_editor' ]
		), [ Donation_Editor::class, 'handle' ] );

		// Hidden (not in menu) — for edit donation screen.
		$this->on_load( add_submenu_page(
			'',
			__( 'Edit Donation', 'pesa-donations' ),
			__( 'Edit Donation', 'pesa-donations' ),
			'pd_manage_donations',
			'pd-donation-edit',
			[ $this, 'render_donation_editor' ]
		), [ Donation_Editor::class, 'handle' ] );

		$this->on_load( add_submenu_page(
			'pesa-donations',
			__( 'All Donors', 'pesa-donations' ),
			__( 'Donors', 'pesa-donations' ),
			'pd_manage_donations',
			'pd-donors',
			[ $this, 'render_donors' ]
		), [ Donors_List_Table::class, 'handle_actions' ] );

		$this->on_load( add_submenu_page(
			'pesa-donations',
			__( 'Add New Donor', 'pesa-donations' ),
			__( 'Add New Donor', 'pesa-donations' ),
			'pd_manage_donations',
			'pd-donor-new',
			[ $this, 'render_donor_editor' ]
		), [ Donor_Editor::class, 'handle' ] );

		// Hidden — edit donor screen.
		$this->on_load( add_submenu_page(
			'',
			__( 'Edit Donor', 'pesa-donations' ),
			__( 'Edit Donor', 'pesa-donations' ),
			'pd_manage_donations',
			'pd-donor-edit',
			[ $this, 'render_donor_editor' ]
		), [ Donor_Editor::class, 'handle' ] );

		$this->on_load( add_submenu_page(
			'pesa-donations',
			__( 'Settings', 'pesa-donations' ),
			__( 'Settings', 'pesa-donations' ),
			'manage_options',
			'pd-settings',
			[ $this, 'render_settings' ]
		), [ Settings::class, 'handle' ] );

		$this->on_load( add_submenu_page(
			'pesa-donations',
			__( 'System Status', 'pesa-donations' ),
			__( 'System Status', 'pesa-donations' ),
			'manage_options',
			'pd-system-status',
			[ $this, 'render_system_status' ]
		), [ $this, 'handle_system_status' ] );
	}

	public function render_donation_editor(): void {
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		( new Donation_Editor() )->render();
	}

	public function render_donors(): void {
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}

		$table = new Donors_List_Table();
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
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		( new Donor_Editor() )->render();
	}

	private function render_donor_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['pd_msg'] ) || 'deleted' !== $_GET['pd_msg'] ) {
			return;
		}
		$deleted = isset( $_GET['pd_deleted'] ) ? absint( $_GET['pd_deleted'] ) : 0;
		$kept    = isset( $_GET['pd_kept'] ) ? absint( $_GET['pd_kept'] ) : 0;
		// phpcs:enable
		if ( $deleted ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				/* translators: %d: number of donors */
				esc_html( sprintf( _n( '%d donor deleted.', '%d donors deleted.', $deleted, 'pesa-donations' ), $deleted ) )
			);
		}
		if ( $kept ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				/* translators: %d: number of donors */
				esc_html( sprintf( _n( '%d donor kept: they have donations on record.', '%d donors kept: they have donations on record.', $kept, 'pesa-donations' ), $kept ) )
			);
		}
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'pesa-donations' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'PesaDonations', 'pesa-donations' ) . '</h1>';
		$this->render_stats_cards();
		echo '</div>';
	}

	private function render_stats_cards(): void {
		global $wpdb;

		// Real money only (sandbox payments excluded once live), per currency.
		$counted = Donation::counted_sql();
		$by_cur  = $wpdb->get_results( "SELECT currency, COUNT(*) AS n, SUM(amount) AS total FROM {$wpdb->prefix}pd_donations WHERE {$counted} GROUP BY currency", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raised  = array_column( $by_cur, 'total', 'currency' );
		$count   = array_sum( array_map( 'intval', array_column( $by_cur, 'n' ) ) );

		$cards = [
			[ 'label' => __( 'Total Raised', 'pesa-donations' ),     'value' => Money::format_totals( array_map( 'floatval', $raised ) ) ],
			[ 'label' => __( 'Donations', 'pesa-donations' ),        'value' => number_format( $count ) ],
			[ 'label' => __( 'Active Campaigns', 'pesa-donations' ), 'value' => number_format( (int) wp_count_posts( Campaign_CPT::POST_TYPE )->publish ) ],
			[ 'label' => __( 'Donors', 'pesa-donations' ),           'value' => number_format( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pd_donors" ) ) ],
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
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}

		$table = new Donations_List_Table();
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['pd_msg'] ) ) {
			return;
		}
		$msg = sanitize_key( wp_unslash( $_GET['pd_msg'] ) );
		if ( 'deleted' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Donation deleted.', 'pesa-donations' ) . '</p></div>';
			return;
		}
		if ( 'status' !== $msg ) {
			return;
		}
		$moved   = isset( $_GET['pd_moved'] ) ? absint( $_GET['pd_moved'] ) : 0;
		$skipped = isset( $_GET['pd_skipped'] ) ? absint( $_GET['pd_skipped'] ) : 0;
		// phpcs:enable
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: %d: number of donations */
			esc_html( sprintf( _n( '%d donation updated.', '%d donations updated.', $moved, 'pesa-donations' ), $moved ) )
		);
		if ( $skipped ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				/* translators: %d: number of donations */
				esc_html( sprintf( _n( '%d donation left as it was: its status cannot make that change (a completed gift can only be reversed).', '%d donations left as they were: their status cannot make that change (a completed gift can only be reversed).', $skipped, 'pesa-donations' ), $skipped ) )
			);
		}
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}
		( new Settings() )->render();
	}

	/** The repair button, handled before output and redirected, so a reload does not run it again. */
	public function handle_system_status(): void {
		if ( ! isset( $_POST['pd_recalc_donors'] ) ) {
			return;
		}
		check_admin_referer( 'pd_recalc_donors', 'pd_recalc_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ), 403 );
		}
		$count = Donor::recalculate_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'pd-system-status', 'pd_recalculated' => $count ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_system_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'System Status', 'pesa-donations' ) . '</h1>';

		if ( isset( $_GET['pd_recalculated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: number of donors */
					__( 'Recalculated aggregates for %d donor(s).', 'pesa-donations' ),
					absint( $_GET['pd_recalculated'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				) )
			);
		}

		global $wpdb;
		$ok   = '&#9989; ';
		$bad  = '&#10060; ';
		$next = static function ( string $hook ) use ( $ok, $bad ): string {
			$ts = wp_next_scheduled( $hook );
			return $ts
				/* translators: %s: date and time */
				? $ok . esc_html( sprintf( __( 'Next run %s', 'pesa-donations' ), wp_date( 'M j, H:i', $ts ) ) )
				: $bad . esc_html__( 'Not scheduled', 'pesa-donations' );
		};

		$checks = [
			__( 'PHP Version', 'pesa-donations' )       => esc_html( PHP_VERSION . ' (min ' . PD_MIN_PHP . ')' ),
			__( 'WordPress Version', 'pesa-donations' ) => esc_html( get_bloginfo( 'version' ) ),
			__( 'Plugin Version', 'pesa-donations' )    => esc_html( PD_VERSION . ' · DB ' . get_option( 'pd_db_version', '—' ) ),
			__( 'Donations Table', 'pesa-donations' )   => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'pd_donations' ) ) ? $ok . 'OK' : $bad . esc_html__( 'Missing', 'pesa-donations' ),
			__( 'Donors Table', 'pesa-donations' )      => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'pd_donors' ) ) ? $ok . 'OK' : $bad . esc_html__( 'Missing', 'pesa-donations' ),
			__( 'Pages', 'pesa-donations' )             => Installer::pages_ok() ? $ok . esc_html__( 'Checkout and thank-you pages published', 'pesa-donations' ) : $bad . esc_html__( 'A plugin page is missing or unpublished', 'pesa-donations' ),
			__( 'PesaPal Environment', 'pesa-donations' ) => esc_html( (string) get_option( 'pd_pesapal_environment', 'sandbox' ) ),
			__( 'PesaPal IPN', 'pesa-donations' )       => Pesapal_Gateway::is_ipn_current() ? $ok . esc_html__( 'Registered for this site and keys', 'pesa-donations' ) : $bad . esc_html__( 'Registers with the next payment', 'pesa-donations' ),
			__( 'Payment check (hourly)', 'pesa-donations' ) => $next( Pesapal_IPN::RECONCILE_HOOK ),
		];

		echo '<table class="wp-list-table widefat fixed striped"><tbody>';
		foreach ( $checks as $label => $value ) {
			printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html( $label ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
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
			</p>
		</form>
		<?php

		echo '</div>';
	}
}
