<?php
declare( strict_types=1 );

namespace PesaDonations\Frontend;

class PD_Public {

	/** Shortcodes that only need the stylesheet (a plain link, no Alpine component). */
	private const STYLE_ONLY = [ 'pd_donate_button' ];

	private const ALL = [
		'pd_donate_button',
		'pd_sponsor_browse', 'pd_give_browse',
		'pd_sponsor_slider', 'pd_give_slider',
		'pd_checkout', 'pd_thank_you',
		'pd_donate',
	];

	public function init(): void {
		( new Shortcodes() )->register();
		( new Ajax_Handler() )->register();

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'wp_resource_hints', [ $this, 'resource_hints' ], 10, 2 );
		// PesaPal callback is handled by Pesapal_IPN::handle_callback_redirect.

		// wptexturize can curl the closing quote of an Alpine attribute when a
		// builder texturizes after shortcodes run. Repaired inside our own
		// component tags only; the rest of the page is never touched.
		// Block themes texturize the whole finished template after every filter,
		// where no repair can reach, so the markup itself must not need one:
		// no "<" or ">" inside an Alpine attribute (texturize takes it for the
		// end of the tag and curls the quote after it). Precompute a flag instead.
		add_filter( 'the_content',  [ $this, 'fix_alpine_attribute_quotes' ], 999 );
		add_filter( 'render_block', [ $this, 'fix_alpine_attribute_quotes' ], 999 );

		// Strip the <p>/<br> that wpautop sometimes puts before our components.
		add_filter( 'the_content', [ $this, 'unwrap_shortcode_paragraphs' ], 12 );
	}

	/**
	 * Removes a <p> or <br> directly before one of our containers. Only on
	 * content that holds one: the old version also removed any </p> or <br>
	 * after any </div>, on every post of the site.
	 *
	 * @param mixed $content Another plugin's filter may hand over null.
	 * @return mixed
	 */
	public function unwrap_shortcode_paragraphs( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, 'class="pd-' ) ) {
			return $content;
		}
		return preg_replace(
			'#(<p[^>]*>\s*|<br\s*/?>\s*)+(<div class="(?:pd-slider|pd-browse|pd-checkout|pd-thanks|pd-donate-btn-wrap))#',
			'$2',
			$content
		);
	}

	/**
	 * Turns a curly quote that ends an attribute back into a straight one,
	 * inside tags that carry Alpine attributes (our components) and nowhere
	 * else. The old version rewrote every “ ” in every post's text.
	 *
	 * @param mixed $content
	 * @return mixed
	 */
	public function fix_alpine_attribute_quotes( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, 'x-data="pd' ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/<[a-z][^<>]*?(?:\sx-[a-z]+|\s@[a-z]|\s:[a-z])[^<>]*>/i',
			static fn( array $tag ): string => preg_replace( '/(&#8220;|&#8221;|&#8243;)(?=[>\s])/', '"', $tag[0] ),
			$content
		);
	}

	/**
	 * Loads the stylesheet and scripts in the <head> when the page's own content
	 * holds one of our shortcodes. Shortcodes placed anywhere else (a synced
	 * pattern, a theme template part, a widget, a page builder's stored layout)
	 * are covered by enqueue(), which every shortcode calls as it renders.
	 */
	public function enqueue_assets(): void {
		$found = $this->shortcodes_on_page();
		if ( ! $found ) {
			return;
		}
		self::enqueue( ! array_diff( $found, self::STYLE_ONLY ) );
	}

	/**
	 * Safe to call from a shortcode at any point after init: a script queued
	 * mid-page prints in the footer, and a late stylesheet prints there too.
	 * Registration happens here, lazily: pages without our shortcodes pay nothing.
	 */
	public static function enqueue( bool $style_only = false ): void {
		if ( ! wp_style_is( 'pd-public', 'registered' ) ) {
			( new self() )->register_assets();
		}
		wp_enqueue_style( 'pd-public' );
		if ( ! $style_only ) {
			wp_enqueue_script( 'pd-public' );
			wp_enqueue_script( 'pd-alpine-js' );
		}
	}

	/** Connect to the font host early, in parallel with our stylesheet. */
	public function resource_hints( $urls, $relation ) {
		if ( is_array( $urls ) && 'preconnect' === $relation && wp_style_is( 'pd-fonts', 'enqueued' ) ) {
			$urls[] = [ 'href' => 'https://fonts.gstatic.com', 'crossorigin' ];
		}
		return $urls;
	}

	public function register_assets(): void {
		if ( wp_style_is( 'pd-public', 'registered' ) ) {
			return;
		}

		// Use filemtime() as the asset version so browsers always fetch the
		// latest file after we push updates (no stale caches between releases).
		$css_path = PD_PLUGIN_DIR . 'assets/css/pd-public.css';
		$js_path  = PD_PLUGIN_DIR . 'assets/js/pd-public.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : PD_VERSION;
		$js_ver   = file_exists( $js_path )  ? (string) filemtime( $js_path )  : PD_VERSION;

		// Fonts as their own stylesheet, requested in parallel. An @import inside
		// pd-public.css made the browser fetch our CSS first and only then
		// discover the fonts: two extra round trips before text could render.
		wp_register_style(
			'pd-fonts',
			'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Outfit:wght@600;700;800;900&display=swap',
			[],
			null
		);

		wp_register_style(
			'pd-public',
			PD_PLUGIN_URL . 'assets/css/pd-public.css',
			[ 'pd-fonts' ],
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
		wp_register_script(
			'pd-public',
			PD_PLUGIN_URL . 'assets/js/pd-public.js',
			[],
			$js_ver,
			true
		);

		wp_register_script(
			'pd-alpine-js',
			PD_PLUGIN_URL . 'assets/js/alpine.min.js',
			[ 'pd-public' ],
			'3.14.1',
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);
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

	/** @return string[] Our shortcodes found in the current post's own content. */
	private function shortcodes_on_page(): array {
		global $post;
		if ( ! $post instanceof \WP_Post || false === strpos( (string) $post->post_content, '[pd_' ) ) {
			return [];
		}
		return array_values( array_filter( self::ALL, static fn( string $sc ): bool => has_shortcode( $post->post_content, $sc ) ) );
	}
}
