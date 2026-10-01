<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

class Deactivator {

	public static function deactivate(): void {
		// Rebuilt on the next request without this plugin's rules. flush_rewrite_rules()
		// here would store them again: the plugin is still loaded while it deactivates.
		delete_option( 'rewrite_rules' );

		// Every scheduled instance, including leftover reminder batches, not only the next one.
		foreach ( Installer::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
