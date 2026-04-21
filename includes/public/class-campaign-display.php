<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

use PesaDonations\Models\Donation;

class Campaign_Display {

	public function render_callback( string $uuid ): void {
		$donation = Donation::get_by_uuid( $uuid );
		if ( ! $donation ) {
			wp_die( esc_html__( 'Invalid donation reference.', 'pesa-donations' ) );
		}

		$thank_you_page_id = (int) get_option( 'pd_thank_you_page_id' );
		if ( $thank_you_page_id ) {
			wp_safe_redirect( add_query_arg( 'd', $uuid, get_permalink( $thank_you_page_id ) ) );
		} else {
			wp_safe_redirect( add_query_arg( [ 'pd_thankyou' => '1', 'd' => $uuid ], home_url() ) );
		}
	}
}
