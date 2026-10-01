<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

class Admin {

	public function init(): void {
		( new Admin_Menu() )->register();
		( new Meta_Boxes() )->register();

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( string $hook ): void {
		// By page slug: a submenu's hook name starts with the translated menu
		// title ("donations_page_…"), and the list here named hooks that never
		// existed, so these files loaded on none of the plugin's own screens.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ours    = 'pesa-donations' === $page || str_starts_with( $page, 'pd-' );
		$screen  = get_current_screen();
		$is_cpt  = $screen && 'pd_campaign' === $screen->post_type;

		if ( ! $ours && ! $is_cpt ) {
			return;
		}

		wp_enqueue_style(
			'pd-admin',
			PD_PLUGIN_URL . 'assets/css/pd-admin.css',
			[],
			PD_VERSION
		);

		wp_enqueue_script(
			'pd-admin',
			PD_PLUGIN_URL . 'assets/js/pd-admin.js',
			[ 'jquery' ],
			PD_VERSION,
			true
		);

		wp_localize_script( 'pd-admin', 'pdAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pd_admin_nonce' ),
		] );
	}
}
