<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

use PesaDonations\Admin\Admin;
use PesaDonations\CPT\Campaign_CPT;
use PesaDonations\Frontend\PD_Public;
use PesaDonations\Payments\Gateway_Manager;
use PesaDonations\Payments\Pesapal\Pesapal_Gateway;
use PesaDonations\Payments\Pesapal\Pesapal_IPN;
use PesaDonations\Modules\Email_Notifications\Email_Notifications;
use PesaDonations\Modules\Updater\Updater;
use PesaDonations\Modules\Campaign_Cycles\Campaign_Cycles;
use PesaDonations\Modules\Dashboard\Dashboard;

class Plugin {

	private static ?self $instance = null;

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function run(): void {
		// Before anything checks a capability: administrators hold every PesaDonations one.
		( new Roles() )->register();

		add_action( 'plugins_loaded', [ $this, 'check_wp_version' ] );
		// Activation does not run on an update, so schema and cron changes ride on the DB version.
		// On init, not earlier: install() translates page titles and defaults.
		add_action( 'init', [ Installer::class, 'maybe_upgrade' ], 1 );
		// On init, not plugins_loaded: recreating a page calls get_permalink(), which
		// needs $wp_rewrite. On plugins_loaded it is still null, and WordPress 7.1
		// fatals on every request while the page it just inserted is left behind.
		add_action( 'init', [ $this, 'self_heal' ], 2 );
		// Before the installer, which translates page titles and defaults.
		add_action( 'init', [ $this, 'load_textdomain' ], 0 );
		add_action( 'init', [ $this, 'register_cpt' ] );
		// A plugin page leaving "publish" is repaired on the next request, not a day later.
		add_action( 'transition_post_status', [ $this, 'watch_pages' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'watch_pages_deleted' ] );

		if ( is_admin() ) {
			add_action( 'plugins_loaded', [ $this, 'load_admin' ] );
		}

		add_action( 'plugins_loaded', [ $this, 'load_public' ] );
		add_action( 'plugins_loaded', [ $this, 'load_gateways' ] );
		add_action( 'plugins_loaded', [ $this, 'load_modules' ] );
		add_action( 'plugins_loaded', [ $this, 'load_cron' ] );
	}

	public function load_gateways(): void {
		Gateway_Manager::register( new Pesapal_Gateway() );
		( new Pesapal_IPN() )->register();
	}

	public function load_modules(): void {
		( new Email_Notifications() )->register();
		( new Updater() )->register();
		( new Campaign_Cycles() )->register();
		( new Dashboard() )->register();
	}

	public function load_cron(): void {
		( new Cron() )->register();
	}

	/**
	 * If the checkout or thank-you page is missing or no longer published,
	 * create a new one. Checked at most once a day (the check costs two queries,
	 * and it ran on every request before), or at once after watch_pages() saw
	 * one of the pages change.
	 */
	public function self_heal(): void {
		if ( time() - (int) get_option( 'pd_pages_checked_at', 0 ) < DAY_IN_SECONDS ) {
			return;
		}
		if ( ! Installer::pages_ok() ) {
			Installer::repair_pages();
		}
		// The jobs and the role can be lost too (a cron array reset, a role
		// editor, an activation that did not run the install): put them back.
		Installer::ensure_runtime();
		update_option( 'pd_pages_checked_at', time(), true );
	}

	/** @param \WP_Post $post */
	public function watch_pages( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && $this->is_plugin_page( (int) $post->ID ) ) {
			update_option( 'pd_pages_checked_at', 0, true );
		}
	}

	public function watch_pages_deleted( $post_id ): void {
		if ( $this->is_plugin_page( (int) $post_id ) ) {
			update_option( 'pd_pages_checked_at', 0, true );
		}
	}

	private function is_plugin_page( int $id ): bool {
		return $id > 0 && in_array( $id, [ (int) get_option( 'pd_checkout_page_id' ), (int) get_option( 'pd_thank_you_page_id' ) ], true );
	}

	public function check_wp_version(): void {
		global $wp_version;
		if ( version_compare( $wp_version, PD_MIN_WP, '<' ) ) {
			add_action( 'admin_notices', static function () use ( $wp_version ): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: required WP version, 2: current WP version */
							__( 'PesaDonations requires WordPress %1$s or higher. You are running %2$s.', 'pesa-donations' ),
							PD_MIN_WP,
							$wp_version
						)
					)
				);
			} );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'pesa-donations',
			false,
			dirname( plugin_basename( PD_PLUGIN_FILE ) ) . '/languages'
		);
	}

	public function register_cpt(): void {
		( new Campaign_CPT() )->register();
	}

	public function load_admin(): void {
		( new Admin() )->init();
	}

	public function load_public(): void {
		( new PD_Public() )->init();
	}
}
