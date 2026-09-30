<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

class Activator {

	public static function activate(): void {
		// No capability check here: WordPress checks it before activating from
		// the Plugins screen, and a WP-CLI or scripted activation has no user,
		// so the check made those skip the install (no role, no capabilities,
		// no scheduled jobs) without a word.
		Installer::install();

		// Register CPT so rewrite flush picks it up.
		( new \PesaDonations\CPT\Campaign_CPT() )->register();
		flush_rewrite_rules();
	}
}
