<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

class PD_Public {

	public function init(): void {
		( new Shortcodes() )->register();
		( new Ajax_Handler() )->register();

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'template_redirect',  [ $this, 'handle_pesapal_callback' ] );

		// wp_texturize mangles the closing `"` of Alpine attributes into curly
		// quotes. Hook late on every filter that could emit our shortcode HTML.
		add_filter( 'the_content',  [ $this, 'fix_alpine_attribute_quotes' ], 999 );
		add_filter( 'render_block', [ $this, 'fix_alpine_attribute_quotes' ], 999 );
		add_filter( 'do_shortcode_tag', [ $this, 'fix_alpine_attribute_quotes' ], 999 );
	}

	/**
	 * Replace curly smart-quotes that WP's texturize filter accidentally
	 * inserted in place of straight ASCII double quotes at the end of
	 * Alpine.js attribute values.
	 */
	public function fix_alpine_attribute_quotes( string $content ): string {
		// &#8221; = ” right curly double
		// &#8243; = ″ double prime
		// &#8220; = “ left curly double
		// &#8216; / &#8217; = ‘ ’ curly singles
		// Only replace when immediately followed by a character that could
		// close an HTML attribute (> or whitespace-before-next-attr).
		return preg_replace(
			'/(&#8220;|&#8221;|&#8243;)(?=[>\s])/',
			'"',
			$content
		);
	}

	public function enqueue_assets(): void {
		if ( ! $this->page_has_shortcode() ) {
			return;
		}

		wp_enqueue_style(
			'pd-public',
			PD_PLUGIN_URL . 'assets/css/pd-public.css',
			[],
			PD_VERSION
		);

		// pd-public.js MUST load before alpine.min.js so the component
		// functions (pdCheckout, pdSponsorshipList, pdDonateButton) are on
		// window before Alpine scans the DOM.
		wp_enqueue_script(
			'pd-public',
			PD_PLUGIN_URL . 'assets/js/pd-public.js',
			[],
			PD_VERSION,
			true
		);

		wp_enqueue_script(
			'pd-alpine-js',
			PD_PLUGIN_URL . 'assets/js/alpine.min.js',
			[ 'pd-public' ],
			'3.14.1',
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);

		wp_localize_script( 'pd-public', 'pdPublic', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'pd_public_nonce' ),
			'checkoutUrl' => get_permalink( (int) get_option( 'pd_checkout_page_id' ) ) ?: '',
			'currency'    => get_option( 'pd_default_currency', 'UGX' ),
		] );
	}

	private function page_has_shortcode(): bool {
		global $post;
		if ( ! $post ) {
			return false;
		}
		$shortcodes = [ 'pd_donate_button', 'pd_sponsorships', 'pd_projects', 'pd_sponsor_browse', 'pd_give_browse', 'pd_campaign', 'pd_campaign_list', 'pd_checkout', 'pd_progress', 'pd_thank_you' ];
		foreach ( $shortcodes as $sc ) {
			if ( has_shortcode( $post->post_content, $sc ) ) {
				return true;
			}
		}
		return false;
	}

	public function handle_pesapal_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['pd_callback'] ) || ! isset( $_GET['d'] ) ) {
			return;
		}
		$uuid = sanitize_text_field( wp_unslash( $_GET['d'] ) );
		( new \PesaDonations\Frontend\Campaign_Display() )->render_callback( $uuid );
		exit;
	}
}
