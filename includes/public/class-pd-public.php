<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

class PD_Public {

	public function init(): void {
		( new Shortcodes() )->register();
		( new Ajax_Handler() )->register();

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// PesaPal callback is handled by Pesapal_IPN::handle_callback_redirect.

		// wp_texturize mangles the closing `"` of Alpine attributes into curly
		// quotes. Hook late on every filter that could emit our shortcode HTML.
		add_filter( 'the_content',  [ $this, 'fix_alpine_attribute_quotes' ], 999 );
		add_filter( 'render_block', [ $this, 'fix_alpine_attribute_quotes' ], 999 );
		add_filter( 'do_shortcode_tag', [ $this, 'fix_alpine_attribute_quotes' ], 999 );

		// Strip <p> wrappers + <br> that wpautop sometimes injects around our
		// shortcode output when multiple shortcodes share a page. Our output is
		// already valid HTML — auto-paragraphing breaks the layout.
		add_filter( 'the_content', [ $this, 'unwrap_shortcode_paragraphs' ], 12 );

		// Load our dedicated single-donation template for project-type
		// campaigns. Sponsorship campaigns continue to use the theme's
		// standard single template.
		add_filter( 'single_template', [ $this, 'load_donation_single_template' ] );
	}

	/**
	 * Swap in templates/single-donation.php when WordPress is about to
	 * render a single pd_campaign post whose category is project-like.
	 * Theme override: place a copy at
	 *   /wp-content/themes/{theme}/pesa-donations/single-donation.php
	 *
	 * Returns the FILE PATH for the WP template loader to include — does
	 * not render directly. Including-and-exiting here would skip every
	 * other `template_include` filter registered after ours and break
	 * caching plugins / block-theme rendering.
	 *
	 * The template needs `$campaign` in scope; we stash it on a global
	 * the template reads back. Avoids a class property leak across
	 * requests in long-running PHP processes.
	 */
	public function load_donation_single_template( string $template ): string {
		if ( ! is_singular( \PesaDonations\CPT\Campaign_CPT::POST_TYPE ) ) {
			return $template;
		}
		$post_id  = (int) get_queried_object_id();
		$category = (string) get_post_meta( $post_id, '_pd_category', true );
		$donation_categories = [ 'project', 'school', 'hospital', 'medical', 'other' ];
		if ( ! in_array( $category, $donation_categories, true ) ) {
			return $template;
		}

		$campaign = \PesaDonations\Models\Campaign::get( $post_id );
		if ( ! $campaign ) {
			return $template;
		}

		$theme_file  = get_stylesheet_directory() . '/pesa-donations/single-donation.php';
		$plugin_file = PD_PLUGIN_DIR . 'templates/single-donation.php';
		$file        = file_exists( $theme_file ) ? $theme_file : $plugin_file;

		if ( ! file_exists( $file ) ) {
			return $template;
		}

		// Stash campaign so the template can pull it back without a
		// global — keeps template authors from depending on the loader's
		// scope semantics.
		$GLOBALS['pd_donation_campaign'] = $campaign;

		return $file;
	}

	/**
	 * Remove stray <p>...</p> wrappers and <br> tags that wpautop inserts
	 * around (or between) our shortcodes. Runs AFTER shortcodes have been
	 * expanded (priority 12 — do_shortcode is 11).
	 */
	public function unwrap_shortcode_paragraphs( string $content ): string {
		$containers = [
			'pd-slider', 'pd-browse', 'pd-checkout', 'pd-thanks',
			'pd-donate-btn-wrap', 'pd-donation-card', 'pd-donation-single',
		];

		foreach ( $containers as $class ) {
			// Remove <p> or <br> immediately BEFORE our container's opening tag.
			$content = preg_replace(
				'#(<p[^>]*>\s*|<br\s*/?>\s*)+(<div class="' . preg_quote( $class, '#' ) . ')#',
				'$2',
				$content
			);
			// Remove </p> / <br> immediately AFTER a closing </div> that ends our container
			// (heuristic: an orphan </p> or <br> right after our component).
			$content = preg_replace(
				'#(</div>)\s*(</p>|<br\s*/?>)#',
				'$1',
				$content
			);
		}
		return $content;
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

		// Use filemtime() as the asset version so browsers always fetch the
		// latest file after we push updates (no stale caches between releases).
		$css_path = PD_PLUGIN_DIR . 'assets/css/pd-public.css';
		$js_path  = PD_PLUGIN_DIR . 'assets/js/pd-public.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : PD_VERSION;
		$js_ver   = file_exists( $js_path )  ? (string) filemtime( $js_path )  : PD_VERSION;

		wp_enqueue_style(
			'pd-public',
			PD_PLUGIN_URL . 'assets/css/pd-public.css',
			[],
			$css_ver
		);

		// Admin brand color → live CSS variables. Attached AFTER pd-public.css
		// so it naturally wins in the cascade (same specificity + !important).
		$custom_vars = $this->build_custom_vars();
		if ( $custom_vars ) {
			wp_add_inline_style( 'pd-public', $custom_vars );
		}

		// pd-public.js MUST load before alpine.min.js so the component
		// functions are on window before Alpine scans the DOM.
		wp_enqueue_script(
			'pd-public',
			PD_PLUGIN_URL . 'assets/js/pd-public.js',
			[],
			$js_ver,
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

	/**
	 * Generates inline CSS overriding --pd-coral family from saved admin
	 * settings. Returns empty string if no valid brand color is set.
	 */
	private function build_custom_vars(): string {
		$hex = (string) get_option( 'pd_brand_color', '' );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
			return '';
		}

		$alpha = max( 0, min( 100, (int) get_option( 'pd_brand_color_alpha', 100 ) ) );

		// Derived variants:
		//   --pd-coral       = admin choice
		//   --pd-coral-dark  = 18% darker (for hover / active states)
		//   --pd-coral-soft  = 10%-alpha tint of the color (for soft backgrounds)
		$dark = $this->darken_hex( $hex, 18 );
		$soft = $this->hex_to_rgba( $hex, 10 );

		// If admin chose alpha < 100, apply it to the primary color too.
		$primary = 100 === $alpha ? $hex : $this->hex_to_rgba( $hex, $alpha );

		return ":root {
			--pd-coral:      {$primary} !important;
			--pd-coral-dark: {$dark} !important;
			--pd-coral-soft: {$soft} !important;
		}";
	}

	/**
	 * Returns a darker version of a hex color by multiplying each channel
	 * by (1 - percent/100). Simple and predictable for UI purposes.
	 */
	private function darken_hex( string $hex, int $percent ): string {
		$hex    = ltrim( $hex, '#' );
		$factor = ( 100 - max( 0, min( 100, $percent ) ) ) / 100;
		$r      = (int) max( 0, hexdec( substr( $hex, 0, 2 ) ) * $factor );
		$g      = (int) max( 0, hexdec( substr( $hex, 2, 2 ) ) * $factor );
		$b      = (int) max( 0, hexdec( substr( $hex, 4, 2 ) ) * $factor );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	private function hex_to_rgba( string $hex, int $alpha_percent ): string {
		$hex = ltrim( $hex, '#' );
		$r   = hexdec( substr( $hex, 0, 2 ) );
		$g   = hexdec( substr( $hex, 2, 2 ) );
		$b   = hexdec( substr( $hex, 4, 2 ) );
		$a   = max( 0, min( 100, $alpha_percent ) ) / 100;
		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim( rtrim( number_format( $a, 2, '.', '' ), '0' ), '.' ) ?: '0' );
	}

	private function page_has_shortcode(): bool {
		// Single donation/sponsorship post: always load our assets so the
		// custom template's styles + Alpine bits work.
		if ( is_singular( \PesaDonations\CPT\Campaign_CPT::POST_TYPE ) ) {
			return true;
		}

		global $post;
		if ( ! $post ) {
			return false;
		}
		$shortcodes = [
			'pd_donate_button',
			'pd_sponsor_browse', 'pd_give_browse',
			'pd_sponsor_slider', 'pd_give_slider',
			'pd_checkout', 'pd_thank_you',
		];
		foreach ( $shortcodes as $sc ) {
			if ( has_shortcode( $post->post_content, $sc ) ) {
				return true;
			}
		}
		return false;
	}

}
