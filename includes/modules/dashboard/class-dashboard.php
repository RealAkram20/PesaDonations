<?php
declare( strict_types=1 );

namespace PesaDonations\Modules\Dashboard;

/**
 * The staff dashboard at its own address (home_url('/dashboard/') by default,
 * set in Settings → Advanced), outside the WordPress admin chrome.
 *
 * Signed-out visitors are sent to the login screen and back; a signed-in user
 * without pd_view_dashboard gets a 403. Editing still happens in wp-admin: the
 * dashboard is an overview with shortcuts.
 */
class Dashboard {

	public const QUERY_VAR    = 'pd_dashboard';
	public const SLUG_OPTION  = 'pd_dashboard_slug';
	public const FLUSH_OPTION = 'pd_flush_rewrite';
	public const NONCE        = 'pd_dashboard';

	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite_rule' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite' ], 99 );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'render' ], 0 );
		add_action( 'wp_ajax_pd_dashboard_data', [ $this, 'ajax_data' ] );
		add_action( 'admin_post_' . Donations_Export::ACTION, [ new Donations_Export(), 'handle' ] );
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 80 );
	}

	// -------------------------------------------------------------------------
	// Address
	// -------------------------------------------------------------------------

	public static function slug(): string {
		$slug = sanitize_title( (string) get_option( self::SLUG_OPTION, 'dashboard' ) );
		return '' !== $slug ? $slug : 'dashboard';
	}

	public static function url(): string {
		return get_option( 'permalink_structure' )
			? home_url( '/' . self::slug() . '/' )
			: add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/** First install: /dashboard, unless a page already lives there. */
	public static function default_slug(): string {
		return get_page_by_path( 'dashboard' ) ? 'donations-dashboard' : 'dashboard';
	}

	/**
	 * Rewrite rules are rebuilt on the next init, after every rule is
	 * registered. The flag stays stored ('0'/'1', autoloaded): a deleted
	 * option costs a query on every request to find it is still missing.
	 */
	public static function request_flush(): void {
		update_option( self::FLUSH_OPTION, '1', true );
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule( '^' . self::slug() . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public function maybe_flush_rewrite(): void {
		if ( '1' === (string) get_option( self::FLUSH_OPTION, '0' ) ) {
			update_option( self::FLUSH_OPTION, '0', true );
			flush_rewrite_rules( false );
		}
	}

	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	// -------------------------------------------------------------------------
	// The page
	// -------------------------------------------------------------------------

	public function render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( ! is_user_logged_in() ) {
			auth_redirect(); // To the login screen, and back here after.
		}
		if ( ! current_user_can( 'pd_view_dashboard' ) ) {
			wp_die(
				esc_html__( 'Your account cannot open the donations dashboard. Ask an administrator for the Donations Manager role.', 'pesa-donations' ),
				esc_html__( 'Not allowed', 'pesa-donations' ),
				[ 'response' => 403 ]
			);
		}

		$metrics = new Dashboard_Metrics();
		$payload = $this->payload( $metrics );
		$accent  = self::accent();

		status_header( 200 );
		include PD_PLUGIN_DIR . 'templates/dashboard/dashboard.php';
		exit;
	}

	public function ajax_data(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'pd_view_dashboard' ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session has expired. Reload the page.', 'pesa-donations' ) ], 403 );
		}
		$range = sanitize_key( wp_unslash( $_GET['range'] ?? '30d' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		wp_send_json_success( ( new Dashboard_Metrics() )->build( $range ) );
	}

	private function payload( Dashboard_Metrics $metrics ): array {
		$user = wp_get_current_user();
		$name = $user->display_name ?: $user->user_login;

		return [
			'range'    => '30d',
			'data'     => $metrics->build( '30d' ),
			'now'      => $metrics->now(),
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'export'   => [
				'url'   => admin_url( 'admin-post.php' ),
				'nonce' => wp_create_nonce( Donations_Export::ACTION ),
			],
			'locale'   => str_replace( '_', '-', get_user_locale() ),
			'currency' => \PesaDonations\Models\Open_Donation::currency(),
			'can'      => [
				'campaigns' => current_user_can( 'edit_pd_campaigns' ),
				'donations' => current_user_can( 'pd_manage_donations' ),
				'settings'  => current_user_can( 'manage_options' ),
			],
			'links'    => [
				'newCampaign'    => admin_url( 'post-new.php?post_type=pd_campaign' ),
				'recordDonation' => admin_url( 'admin.php?page=pd-donation-new' ),
				'campaigns'      => admin_url( 'edit.php?post_type=pd_campaign' ),
				'donations'      => admin_url( 'admin.php?page=pd-donations' ),
				'donors'         => admin_url( 'admin.php?page=pd-donors' ),
				'settings'       => admin_url( 'admin.php?page=pd-settings' ),
				'wpAdmin'        => admin_url(),
				'site'           => home_url( '/' ),
				'logout'         => wp_logout_url( home_url( '/' ) ),
			],
			'user'     => [
				'name'     => $name,
				'first'    => $user->first_name ?: $name,
				'initials' => self::initials( $name ),
			],
		];
	}

	private static function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
		$out   = '';
		foreach ( array_slice( $parts, 0, 2 ) as $p ) {
			$out .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
		return '' !== $out ? $out : '?';
	}

	/**
	 * The brand colour for accents, and a darker step of it for filled buttons,
	 * darkened until white text on it reaches WCAG AA (4.5:1). The brand stays;
	 * only the pairing is fixed.
	 *
	 * @return array{accent: string, strong: string}
	 */
	public static function accent(): array {
		$hex = (string) get_option( 'pd_brand_color', '#e94e4e' );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = '#e94e4e';
		}
		$strong = strtolower( $hex );
		for ( $i = 0; $i < 20 && self::contrast( $strong, '#ffffff' ) < 4.5; $i++ ) {
			$strong = self::darken( $strong, 0.06 );
		}
		return [ 'accent' => strtolower( $hex ), 'strong' => $strong ];
	}

	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	private static function luminance( string $hex ): float {
		$c = array_map( static function ( string $pair ): float {
			$v = hexdec( $pair ) / 255;
			return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		}, str_split( ltrim( $hex, '#' ), 2 ) );
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	private static function darken( string $hex, float $by ): string {
		$c = array_map( static fn( string $p ): int => (int) round( hexdec( $p ) * ( 1 - $by ) ), str_split( ltrim( $hex, '#' ), 2 ) );
		return sprintf( '#%02x%02x%02x', $c[0], $c[1], $c[2] );
	}

	// -------------------------------------------------------------------------
	// Ways in
	// -------------------------------------------------------------------------

	/** A Donations Manager lands on the dashboard after signing in, not on their profile. */
	public function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof \WP_User
			&& $user->has_cap( 'pd_view_dashboard' ) && ! $user->has_cap( 'manage_options' )
			&& ( '' === (string) $requested || admin_url() === $requested ) ) {
			return self::url();
		}
		return $redirect_to;
	}

	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'pd_view_dashboard' ) ) {
			return;
		}
		$bar->add_node( [
			'id'     => 'pd-dashboard',
			'parent' => 'site-name',
			'title'  => esc_html__( 'Donations dashboard', 'pesa-donations' ),
			'href'   => self::url(),
		] );
	}
}
