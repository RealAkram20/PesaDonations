<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

class Activator {

	public static function activate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		Installer::install();

		// Register CPT so rewrite flush picks it up.
		( new \PesaDonations\CPT\Campaign_CPT() )->register();
		flush_rewrite_rules();
	}
}
