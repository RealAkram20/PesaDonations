<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

use PesaDonations\Modules\Dashboard\Dashboard;
use PesaDonations\Payments\Pesapal\Pesapal_IPN;
use PesaDonations\Utils\Currencies;
use PesaDonations\Utils\Exchange_Rates;

class Installer {

	private const DB_VERSION_OPTION = 'pd_db_version';
	private const DB_VERSION        = '1.3.0';
	private const LOCK_OPTION       = 'pd_install_lock';

	/** Page options the plugin needs, and what each page holds. */
	private const PAGES = [
		'pd_checkout_page_id'  => [ 'content' => '[pd_checkout]', 'slug' => 'donation-checkout' ],
		'pd_thank_you_page_id' => [ 'content' => '[pd_thank_you]', 'slug' => 'donation-thank-you' ],
	];

	public static function install(): void {
		// One run at a time. The first requests after an update or a WP-CLI
		// activation arrive together (a page view and the wp-cron it spawns);
		// two concurrent runs created every page twice.
		if ( ! self::acquire_lock() ) {
			return;
		}
		// A request that began before the previous run finished still holds the
		// options it loaded at startup (no page ids yet): read them again.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( array_merge( array_keys( self::PAGES ), [ self::DB_VERSION_OPTION ] ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		$previous = (string) get_option( self::DB_VERSION_OPTION, '0' );
		try {
			self::create_tables();
			self::migrate( $previous );
			self::create_pages();
			self::schedule_crons();
			self::set_defaults();
			Roles::install();
			if ( false === get_option( Dashboard::SLUG_OPTION ) ) {
				update_option( Dashboard::SLUG_OPTION, Dashboard::default_slug() );
			}
			Dashboard::request_flush();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		} finally {
			global $wpdb;
			$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_OPTION ] );
		}
	}

	/**
	 * INSERT IGNORE on the unique option_name: exactly one caller gets a row.
	 * (add_option() will not do: it upserts.) A lock left by a run that died is
	 * taken over after ten minutes.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;
		$insert = $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::LOCK_OPTION,
			(string) time()
		);
		if ( 1 === (int) $wpdb->query( $insert ) ) {
			return true;
		}
		$since = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			self::LOCK_OPTION
		) );
		if ( $since && time() - $since > 10 * MINUTE_IN_SECONDS ) {
			$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_OPTION ] );
			return 1 === (int) $wpdb->query( $insert );
		}
		return false;
	}

	public static function maybe_upgrade(): void {
		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Whether both plugin pages exist and are published. The one test shared
	 * by the self-check and create_pages(), which used to disagree: a trashed
	 * page counted as missing to one and present to the other, so the install
	 * ran on every request and never repaired anything.
	 */
	public static function pages_ok(): bool {
		foreach ( array_keys( self::PAGES ) as $option ) {
			$id = (int) get_option( $option );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				return false;
			}
		}
		return true;
	}

	/** Recreates only the pages that are missing or unpublished. */
	public static function repair_pages(): bool {
		return self::create_pages();
	}

	// -------------------------------------------------------------------------
	// DB Tables
	// -------------------------------------------------------------------------

	/**
	 * Integer columns carry their display width (INT(10) UNSIGNED, TINYINT(4),
	 * INT(11)): MariaDB and MySQL before 8.0.17 report widths, and dbDelta
	 * otherwise re-issues ALTER TABLE for every such column on every install.
	 */
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
			donation_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			reminders_opt_out TINYINT(1) NOT NULL DEFAULT 0,
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
			base_currency CHAR(3) NULL,
			fx_rate DECIMAL(20,10) NOT NULL DEFAULT 1,
			original_amount DECIMAL(15,2) NULL,
			original_currency CHAR(3) NULL,
			gateway VARCHAR(30) NOT NULL DEFAULT 'pesapal',
			environment VARCHAR(10) NULL,
			payment_method VARCHAR(50) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			status_code TINYINT(4) NULL,
			is_recurring TINYINT(1) NOT NULL DEFAULT 0,
			recurring_schedule_id BIGINT(20) UNSIGNED NULL,
			is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
			donor_name VARCHAR(150) NULL,
			donor_email VARCHAR(150) NULL,
			donor_phone VARCHAR(30) NULL,
			donor_country CHAR(2) NULL,
			donor_ip VARCHAR(45) NULL,
			donor_address TEXT NULL,
			referral_source VARCHAR(100) NULL,
			wants_updates TINYINT(1) NOT NULL DEFAULT 0,
			is_organization TINYINT(1) NOT NULL DEFAULT 0,
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
			INDEX idx_campaign_created (campaign_id, created_at),
			INDEX idx_uuid (uuid),
			INDEX idx_status_created (status, created_at)
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
			total_charges INT(10) UNSIGNED DEFAULT 0,
			successful_charges INT(10) UNSIGNED DEFAULT 0,
			failed_charges INT(10) UNSIGNED DEFAULT 0,
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
			http_status INT(11) NULL,
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

	/**
	 * A page that is missing, trashed, draft or private is replaced by a new
	 * published one (the old one stays where the admin put it).
	 *
	 * @return bool Whether a page was created.
	 */
	private static function create_pages(): bool {
		$titles  = [
			'pd_checkout_page_id'  => __( 'Donation Checkout', 'pesa-donations' ),
			'pd_thank_you_page_id' => __( 'Thank You', 'pesa-donations' ),
		];
		$created = false;

		foreach ( self::PAGES as $option => $data ) {
			$existing = (int) get_option( $option );
			if ( $existing && 'publish' === get_post_status( $existing ) ) {
				continue;
			}

			$id = wp_insert_post( [
				'post_title'   => $titles[ $option ],
				'post_content' => $data['content'],
				'post_name'    => $data['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
			] );

			if ( $id && ! is_wp_error( $id ) ) {
				update_option( $option, $id );
				$created = true;
			}
		}
		return $created;
	}

	// -------------------------------------------------------------------------
	// Cron Jobs
	// -------------------------------------------------------------------------

	/** Scheduled jobs and the role with its capabilities, restored if missing. Cheap: both read autoloaded options. */
	public static function ensure_runtime(): void {
		// Every job, not a sample: 1.1.0's deactivation clears the two daily ones
		// and leaves the hourly ones, so checking only those missed it.
		self::schedule_crons(); // Schedules only what is missing; reads the autoloaded cron option.
		$admin = get_role( 'administrator' );
		if ( ! get_role( 'pd_donations_manager' ) || ( $admin && ! $admin->has_cap( 'pd_manage_donations' ) ) ) {
			Roles::install();
		}
	}

	private static function schedule_crons(): void {
		// Removed in 1.2.0: its result was never read and exchangerate.host now needs a key.
		wp_clear_scheduled_hook( 'pd_daily_fx_rates' );

		$events = [
			'pd_purge_gateway_logs'      => 'daily',
			'pd_daily_campaign_status'   => 'daily',
			'pd_hourly_campaign_cycles'  => 'hourly',
			Pesapal_IPN::RECONCILE_HOOK  => 'hourly',
			Exchange_Rates::HOOK         => 'daily',
		];
		foreach ( $events as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	/** Every hook the plugin schedules, for deactivation and uninstall. */
	public static function cron_hooks(): array {
		return [ 'pd_daily_fx_rates', 'pd_purge_gateway_logs', 'pd_daily_campaign_status', 'pd_hourly_campaign_cycles', 'pd_send_cycle_reminders', Pesapal_IPN::RECONCILE_HOOK, Exchange_Rates::HOOK ];
	}

	// -------------------------------------------------------------------------
	// Default Options
	// -------------------------------------------------------------------------

	/**
	 * Data changes a schema update cannot make. Each step runs once, when the
	 * stored version is older than the one that introduced it.
	 */
	private static function migrate( string $previous ): void {
		global $wpdb;
		if ( '0' === $previous ) {
			return; // A first install has nothing to move.
		}

		if ( version_compare( $previous, '1.3.0', '<' ) ) {
			// Before 1.3 amount_base was the charged amount, in the charged currency.
			// Saying so keeps every existing total exactly as it was.
			$wpdb->query( "UPDATE {$wpdb->prefix}pd_donations SET base_currency = currency WHERE base_currency IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// The 1.0 default offered four currencies; the full list is the new default.
			$enabled = array_map( 'strtoupper', (array) get_option( 'pd_enabled_currencies', [] ) );
			sort( $enabled );
			if ( [] === $enabled || [ 'KES', 'TZS', 'UGX', 'USD' ] === $enabled ) {
				update_option( 'pd_enabled_currencies', Currencies::codes() );
			}
		}
	}

	private static function set_defaults(): void {
		$defaults = [
			'pd_default_currency'       => 'UGX',
			'pd_enabled_currencies'     => Currencies::codes(),
			'pd_currency_choice'        => '1',
			'pd_charge_usd'             => '1',
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
			'pd_open_enabled'           => '1',
			'pd_open_title'             => __( 'Make a donation', 'pesa-donations' ),
			'pd_open_label'             => __( 'General donation', 'pesa-donations' ),
			'pd_open_min_amount'        => 5000,
			'pd_open_amounts'           => '10000, 50000, 100000',
			// Stored (autoloaded) so reading them costs no query on every page.
			'pd_brand_color'            => '#e94e4e',
			'pd_brand_color_alpha'      => 100,
			Dashboard::FLUSH_OPTION     => '0',
			'pd_pages_checked_at'       => 0,
			'pd_remove_data_on_uninstall' => '0',
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
				update_option( $key, $value, true );
			}
		}
	}
}
