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
		// Schema migration BEFORE anything else. Must run earlier than
		// AJAX, IPN, or cron handlers — otherwise a freshly-updated
		// plugin can hit Unknown column errors on any request that
		// arrives before the next admin pageview.
		add_action( 'plugins_loaded', [ Installer::class, 'maybe_upgrade' ], 1 );

		add_action( 'plugins_loaded', [ $this, 'check_wp_version' ] );
		add_action( 'plugins_loaded', [ $this, 'self_heal' ], 20 );
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'register_cpt' ] );

		// is_admin() is true for admin-ajax.php too, which would pull the
		// whole admin stack into every front-end donation submission. Only
		// load admin code on real admin screens.
		if ( is_admin() && ! wp_doing_ajax() ) {
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
		( new Privacy() )->register();
	}

	public function load_cron(): void {
		( new Cron() )->register();
	}

	/**
	 * If the checkout or thank-you pages are missing (plugin upgraded from
	 * an earlier build, DB reset, page trashed, etc.), re-create them.
	 * Throttled to once per hour and gated to admin requests so an admin
	 * trashing a page does not turn every front-end hit into a full
	 * Installer::install() run (page inserts, dbDelta, cron schedule, etc.).
	 */
	public function self_heal(): void {
		// Only run for admins on the admin side. Front-end requests must not
		// re-run install() on every hit.
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		// Throttle: at most once per hour per site.
		if ( get_transient( 'pd_self_heal_lock' ) ) {
			return;
		}

		$checkout_id  = (int) get_option( 'pd_checkout_page_id' );
		$thank_you_id = (int) get_option( 'pd_thank_you_page_id' );

		$missing = ! $checkout_id || ! get_post( $checkout_id ) || 'publish' !== get_post_status( $checkout_id )
			|| ! $thank_you_id || ! get_post( $thank_you_id ) || 'publish' !== get_post_status( $thank_you_id );

		if ( ! $missing ) {
			return;
		}

		set_transient( 'pd_self_heal_lock', 1, HOUR_IN_SECONDS );

		Installer::install();

		// Defer rewrite flush to shutdown so all post types/endpoints register first.
		add_action( 'shutdown', static function (): void {
			flush_rewrite_rules( false );
		} );
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
