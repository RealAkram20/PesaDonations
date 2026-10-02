<?php
/**
 * Fired when the plugin is deleted from WordPress.
 *
 * Donations are financial records, so they are KEPT unless an administrator
 * ticked "Delete all donation data when the plugin is deleted" (Settings →
 * Advanced). Deleting and reinstalling a plugin is a common fix for a failed
 * update; it must never erase an organisation's history by accident.
 *
 * Always removed: the scheduled jobs, the Donations Manager role, the
 * capabilities given to administrators and the dashboard's rewrite rule.
 * On a network, every site is handled.
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Another copy of PesaDonations is still installed in another folder (a zip
// uploaded under a different folder name installs beside the old one). It
// uses the same tables, jobs and role: removing this copy leaves them all.
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
foreach ( get_plugins() as $pd_file => $pd_data ) {
	if ( WP_UNINSTALL_PLUGIN !== $pd_file && 'pesa-donations.php' === basename( (string) $pd_file ) && 'PesaDonations' === ( $pd_data['Name'] ?? '' ) ) {
		return;
	}
}
unset( $pd_file, $pd_data );

function pd_uninstall_site(): void {
	global $wpdb;

	foreach ( [ 'pd_daily_fx_rates', 'pd_purge_gateway_logs', 'pd_daily_campaign_status', 'pd_hourly_campaign_cycles', 'pd_send_cycle_reminders', 'pd_reconcile_payments', 'pd_refresh_exchange_rates' ] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	remove_role( 'pd_donations_manager' );
	$caps = [
		'pd_view_dashboard', 'pd_manage_donations',
		'edit_pd_campaigns', 'edit_others_pd_campaigns', 'edit_published_pd_campaigns', 'edit_private_pd_campaigns',
		'publish_pd_campaigns', 'read_private_pd_campaigns', 'delete_pd_campaigns', 'delete_others_pd_campaigns',
		'delete_published_pd_campaigns', 'delete_private_pd_campaigns',
	];
	foreach ( [ 'administrator', 'editor' ] as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
	delete_option( 'rewrite_rules' );

	if ( '1' !== (string) get_option( 'pd_remove_data_on_uninstall', '0' ) ) {
		return;
	}

	// Opted in: everything the plugin created.
	// Every status: 'any' leaves out the trash and auto-drafts (an unsaved
	// Add New screen), and those rows outlived the uninstall.
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'pd_campaign' ) ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( [ 'pd_checkout_page_id', 'pd_thank_you_page_id' ] as $option ) {
		$page = (int) get_option( $option );
		if ( $page ) {
			wp_delete_post( $page, true );
		}
	}

	foreach ( [ 'pd_gateway_logs', 'pd_recurring_schedules', 'pd_donations', 'pd_donors' ] as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// LIKE patterns escaped: an unescaped '_' matches any character, and
	// 'pd_%' would also have deleted other plugins' 'pdf…' options.
	foreach ( [ 'pd_', '_transient_pd_', '_transient_timeout_pd_' ] as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
	delete_option( 'external_updates-pesa-donations' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_pd_' ) . '%' ) );

	$logs = WP_CONTENT_DIR . '/uploads/pesa-donations-logs/';
	if ( is_dir( $logs ) ) {
		// scandir, not glob(GLOB_BRACE): that flag does not exist on musl-based hosts.
		foreach ( scandir( $logs ) ?: [] as $name ) {
			if ( is_file( $logs . $name ) ) {
				wp_delete_file( $logs . $name );
			}
		}
		@rmdir( $logs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		pd_uninstall_site();
		restore_current_blog();
	}
} else {
	pd_uninstall_site();
}
