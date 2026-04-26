<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

class Installer {

	private const DB_VERSION_OPTION = 'pd_db_version';
	private const DB_VERSION        = '1.1.0';

	public static function install(): void {
		self::create_tables();
		self::create_pages();
		self::schedule_crons();
		self::set_defaults();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	// -------------------------------------------------------------------------
	// DB Tables
	// -------------------------------------------------------------------------

	private static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = [];

		$sql[] = "CREATE TABLE {$wpdb->prefix}pd_donors (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT(20) UNSIGNED NULL,
			email VARCHAR(150) NOT NULL,
			phone VARCHAR(30) NULL,
			first_name VARCHAR(100) NULL,
			last_name VARCHAR(100) NULL,
			country CHAR(2) NULL,
			total_donated_base DECIMAL(15,2) NOT NULL DEFAULT 0,
			donation_count INT UNSIGNED NOT NULL DEFAULT 0,
			first_donation_at DATETIME NULL,
			last_donation_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			UNIQUE KEY uniq_email (email),
			INDEX idx_user (user_id),
			INDEX idx_phone (phone)
		) {$charset};";

		$sql[] = "CREATE TABLE {$wpdb->prefix}pd_donations (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			uuid CHAR(36) NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			donor_id BIGINT(20) UNSIGNED NULL,
			merchant_reference VARCHAR(50) NOT NULL,
			order_tracking_id VARCHAR(100) NULL,
			amount DECIMAL(15,2) NOT NULL,
			currency CHAR(3) NOT NULL,
			amount_base DECIMAL(15,2) NOT NULL,
			fx_rate DECIMAL(15,8) NOT NULL DEFAULT 1,
			original_amount DECIMAL(15,2) NULL,
			original_currency CHAR(3) NULL,
			gateway VARCHAR(30) NOT NULL DEFAULT 'pesapal',
			payment_method VARCHAR(50) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			status_code TINYINT NULL,
			is_recurring TINYINT(1) NOT NULL DEFAULT 0,
			recurring_schedule_id BIGINT(20) UNSIGNED NULL,
			is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
			donor_name VARCHAR(150) NULL,
			donor_email VARCHAR(150) NULL,
			donor_phone VARCHAR(30) NULL,
			donor_country CHAR(2) NULL,
			donor_ip VARCHAR(45) NULL,
			confirmation_code VARCHAR(100) NULL,
			message TEXT NULL,
			gateway_response LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			INDEX idx_campaign (campaign_id),
			INDEX idx_donor (donor_id),
			INDEX idx_status (status),
			INDEX idx_merchant_ref (merchant_reference),
			INDEX idx_tracking (order_tracking_id),
			INDEX idx_created (created_at),
			INDEX idx_campaign_status (campaign_id, status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$wpdb->prefix}pd_recurring_schedules (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			uuid CHAR(36) NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			donor_id BIGINT(20) UNSIGNED NOT NULL,
			gateway VARCHAR(30) NOT NULL DEFAULT 'pesapal',
			gateway_subscription_id VARCHAR(100) NULL,
			amount DECIMAL(15,2) NOT NULL,
			currency CHAR(3) NOT NULL,
			frequency VARCHAR(20) NOT NULL,
			start_date DATE NOT NULL,
			end_date DATE NULL,
			next_charge_at DATETIME NULL,
			total_charges INT UNSIGNED DEFAULT 0,
			successful_charges INT UNSIGNED DEFAULT 0,
			failed_charges INT UNSIGNED DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			cancelled_at DATETIME NULL,
			cancelled_reason VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			INDEX idx_campaign (campaign_id),
			INDEX idx_donor (donor_id),
			INDEX idx_status (status),
			INDEX idx_next_charge (next_charge_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$wpdb->prefix}pd_gateway_logs (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			gateway VARCHAR(30) NOT NULL,
			direction VARCHAR(10) NOT NULL,
			endpoint VARCHAR(255) NULL,
			request_body LONGTEXT NULL,
			response_body LONGTEXT NULL,
			http_status INT NULL,
			related_donation_id BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			INDEX idx_gateway (gateway),
			INDEX idx_donation (related_donation_id),
			INDEX idx_created (created_at)
		) {$charset};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	// -------------------------------------------------------------------------
	// Required Pages
	// -------------------------------------------------------------------------

	private static function create_pages(): void {
		$pages = [
			'pd_checkout_page_id'  => [
				'title'   => __( 'Donation Checkout', 'pesa-donations' ),
				'content' => '[pd_checkout]',
				'slug'    => 'donation-checkout',
			],
			'pd_thank_you_page_id' => [
				'title'   => __( 'Thank You', 'pesa-donations' ),
				'content' => '[pd_thank_you]',
				'slug'    => 'donation-thank-you',
			],
		];

		foreach ( $pages as $option => $data ) {
			$existing = get_option( $option );
			if ( $existing && get_post( $existing ) ) {
				continue;
			}

			$id = wp_insert_post( [
				'post_title'   => $data['title'],
				'post_content' => $data['content'],
				'post_name'    => $data['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
			] );

			if ( $id && ! is_wp_error( $id ) ) {
				update_option( $option, $id );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Cron Jobs
	// -------------------------------------------------------------------------

	private static function schedule_crons(): void {
		if ( ! wp_next_scheduled( 'pd_purge_gateway_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'pd_purge_gateway_logs' );
		}
		if ( ! wp_next_scheduled( 'pd_daily_campaign_status' ) ) {
			wp_schedule_event( time(), 'daily', 'pd_daily_campaign_status' );
		}
		// FX cron removed — endpoint sunset, multi-currency gated to
		// campaign base currency until a working source is wired up.
		if ( wp_next_scheduled( 'pd_daily_fx_rates' ) ) {
			wp_clear_scheduled_hook( 'pd_daily_fx_rates' );
		}
	}

	// -------------------------------------------------------------------------
	// Default Options
	// -------------------------------------------------------------------------

	private static function set_defaults(): void {
		$defaults = [
			'pd_default_currency'       => 'UGX',
			'pd_enabled_currencies'     => [ 'UGX', 'KES', 'TZS', 'USD' ],
			'pd_minimum_amount_ugx'     => 5000,
			'pd_country_currency_map'   => [
				'UG' => 'UGX',
				'KE' => 'KES',
				'TZ' => 'TZS',
			],
			'pd_pesapal_environment'    => 'sandbox',
			'pd_paypal_environment'     => 'sandbox',
			'pd_paypal_integration'     => 'smart_buttons',
			'pd_paypal_fallback_currency' => 'USD',
			'pd_email_from_name'        => get_bloginfo( 'name' ),
			'pd_email_from_address'     => get_bloginfo( 'admin_email' ),
			'pd_log_retention_days'     => 90,
			'pd_referral_sources'       => [
				__( 'Social Media', 'pesa-donations' ),
				__( 'Friend or Family', 'pesa-donations' ),
				__( 'Church / Faith Community', 'pesa-donations' ),
				__( 'Website / Search', 'pesa-donations' ),
				__( 'Email Newsletter', 'pesa-donations' ),
				__( 'Other', 'pesa-donations' ),
			],
		];

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value );
			}
		}
	}
}
