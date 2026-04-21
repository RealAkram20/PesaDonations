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
		$screens = [
			'toplevel_page_pesa-donations',
			'pd-campaigns_page_pd-donations',
			'pd-campaigns_page_pd-settings',
			'pd-campaigns_page_pd-system-status',
		];

		if ( ! in_array( $hook, $screens, true ) && 'pd_campaign' !== get_post_type() ) {
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
