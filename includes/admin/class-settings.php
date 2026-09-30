<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\Payments\Pesapal\Pesapal_Auth;
use PesaDonations\Payments\Pesapal\Pesapal_Gateway;

class Settings {

	/** Stored secrets: never printed into the page; a blank box keeps the saved value. */
	private const SECRETS = [ 'pd_pesapal_consumer_secret', 'pd_paypal_client_secret' ];

	/**
	 * Runs on load-{page}, before any output: saves, registers the IPN when
	 * asked, then redirects so a reload does not post the form again.
	 */
	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['pd_settings_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'pd_save_settings', 'pd_settings_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ), 403 );
		}

		$errors = ( new self() )->save();
		$args   = [
			'page'     => 'pd-settings',
			'tab'      => isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general',
			'pd_saved' => 1,
		];

		// Saved first, so the registration uses the keys just entered.
		if ( isset( $_POST['pd_register_ipn'] ) ) {
			Pesapal_Gateway::forget_ipn();
			$args['pd_ipn'] = ( new Pesapal_Gateway() )->ensure_ipn_registered() ? 'ok' : 'failed';
		}

		if ( $errors ) {
			set_transient( 'pd_settings_errors_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$errors = get_transient( 'pd_settings_errors_' . get_current_user_id() );
		if ( is_array( $errors ) ) {
			delete_transient( 'pd_settings_errors_' . get_current_user_id() );
			foreach ( $errors as $error ) {
				echo '<div class="notice notice-error"><p>' . esc_html( (string) $error ) . '</p></div>';
			}
		}
		if ( isset( $_GET['pd_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'pesa-donations' ) . '</p></div>';
		}
		if ( isset( $_GET['pd_ipn'] ) ) {
			echo 'ok' === $_GET['pd_ipn']
				? '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'PesaPal IPN registered.', 'pesa-donations' ) . '</p></div>'
				: '<div class="notice notice-error"><p>' . esc_html__( 'PesaPal did not register the IPN. Check the environment and keys, then try again. The gateway log has PesaPal\'s answer.', 'pesa-donations' ) . '</p></div>';
		}
		// phpcs:enable
	}

	public function render(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PesaDonations Settings', 'pesa-donations' ); ?></h1>
			<?php $this->notices(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $this->tabs() as $id => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'pd-settings', 'tab' => $id ], admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $tab === $id ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="">
				<?php wp_nonce_field( 'pd_save_settings', 'pd_settings_nonce' ); ?>
				<?php $this->render_tab( $tab ); ?>
				<?php if ( 'shortcodes' !== $tab ) : ?>
					<?php submit_button( __( 'Save Settings', 'pesa-donations' ) ); ?>
				<?php endif; ?>
			</form>
		</div>
		<script>
		// Show / hide on secret fields. It reveals what is being typed; the stored secret never reaches the page.
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.pd-secret__eye' );
			if ( ! btn ) { return; }
			var input = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			if ( ! input ) { return; }
			var show = 'password' === input.type;
			input.type = show ? 'text' : 'password';
			btn.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			btn.setAttribute( 'aria-label', show ? btn.dataset.hide : btn.dataset.show );
			btn.querySelector( '.dashicons' ).className = 'dashicons ' + ( show ? 'dashicons-hidden' : 'dashicons-visibility' );
		} );
		</script>
		<style>
			.pd-secret { display: inline-flex; align-items: stretch; }
			.pd-secret input { border-top-right-radius: 0; border-bottom-right-radius: 0; }
			.pd-secret__eye.button { border-top-left-radius: 0; border-bottom-left-radius: 0; margin-left: -1px; display: inline-flex; align-items: center; }
		</style>
		<?php
	}

	/** A password field with the show/hide eye. Empty on screen; a placeholder says whether one is saved. */
	private function secret( string $name, string $label ): void {
		$saved = '' !== (string) get_option( $name, '' );
		$field = sprintf(
			'<span class="pd-secret"><input type="password" name="%1$s" id="%1$s" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="%2$s" />'
			. '<button type="button" class="button pd-secret__eye" aria-controls="%1$s" aria-pressed="false" aria-label="%3$s" data-show="%3$s" data-hide="%4$s"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button></span>',
			esc_attr( $name ),
			esc_attr( $saved ? __( 'Saved. Type a new one to replace it.', 'pesa-donations' ) : '' ),
			esc_attr__( 'Show secret', 'pesa-donations' ),
			esc_attr__( 'Hide secret', 'pesa-donations' )
		);
		$this->row( '<label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>', $field );
	}

	private function tabs(): array {
		return [
			'general'    => __( 'General', 'pesa-donations' ),
			'pesapal'    => __( 'PesaPal', 'pesa-donations' ),
			'paypal'     => __( 'PayPal', 'pesa-donations' ),
			'emails'     => __( 'Emails', 'pesa-donations' ),
			'open'       => __( 'Open Donations', 'pesa-donations' ),
			'advanced'   => __( 'Advanced', 'pesa-donations' ),
			'shortcodes' => __( 'Shortcodes', 'pesa-donations' ),
		];
	}

	private function render_tab( string $tab ): void {
		if ( 'shortcodes' === $tab ) {
			$this->render_shortcodes();
			return;
		}
		echo '<table class="form-table"><tbody>';
		match ( $tab ) {
			'general'  => $this->render_general(),
			'pesapal'  => $this->render_pesapal(),
			'paypal'   => $this->render_paypal(),
			'emails'   => $this->render_emails(),
			'open'     => $this->render_open(),
			'advanced' => $this->render_advanced(),
			default    => null,
		};
		echo '</tbody></table>';
	}

	private function row( string $label, string $field ): void {
		echo "<tr><th>{$label}</th><td>{$field}</td></tr>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function input( string $name, string $label, string $type = 'text', string $description = '' ): void {
		$value = esc_attr( (string) get_option( $name ) );
		$field = "<input type='{$type}' name='{$name}' id='{$name}' value='{$value}' class='regular-text' />";
		if ( $description ) {
			$field .= "<p class='description'>" . esc_html( $description ) . '</p>';
		}
		$this->row( "<label for='{$name}'>" . esc_html( $label ) . '</label>', $field );
	}

	private function select( string $name, string $label, array $options ): void {
		$value = get_option( $name );
		$field = "<select name='{$name}' id='{$name}'>";
		foreach ( $options as $v => $l ) {
			$field .= "<option value='" . esc_attr( $v ) . "'" . selected( $value, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		$field .= '</select>';
		$this->row( "<label for='{$name}'>" . esc_html( $label ) . '</label>', $field );
	}

	private function render_general(): void {
		$this->select( 'pd_default_currency', __( 'Default Currency', 'pesa-donations' ), [
			'UGX' => 'UGX – Ugandan Shilling',
			'KES' => 'KES – Kenyan Shilling',
			'TZS' => 'TZS – Tanzanian Shilling',
			'USD' => 'USD – US Dollar',
		] );
	}

	private function render_pesapal(): void {
		$this->select( 'pd_pesapal_environment', __( 'Environment', 'pesa-donations' ), [
			'sandbox'    => __( 'Sandbox (Testing)', 'pesa-donations' ),
			'production' => __( 'Production (Live)', 'pesa-donations' ),
		] );
		$this->input( 'pd_pesapal_consumer_key',    __( 'Consumer Key', 'pesa-donations' ) );
		$this->secret( 'pd_pesapal_consumer_secret', __( 'Consumer Secret', 'pesa-donations' ) );

		$ipn_url = Pesapal_Gateway::get_ipn_url();
		$ipn_id  = (string) get_option( 'pd_pesapal_ipn_id' );

		// Current means: registered for this environment, these keys and this URL.
		$status = Pesapal_Gateway::is_ipn_current()
			? '<span style="color:#1e7e34;">&#9989; ' . esc_html__( 'Registered', 'pesa-donations' ) . '</span> &nbsp; <code>' . esc_html( $ipn_id ) . '</code>'
			: '<span style="color:#b32d2e;">&#10060; ' . esc_html__( 'Not registered for these keys yet. It registers with the next payment, or now with the button.', 'pesa-donations' ) . '</span>';

		$ipn_field  = '<code style="display:block;padding:8px;background:#f5f5f5;margin-bottom:8px;user-select:all;">' . esc_html( $ipn_url ) . '</code>';
		$ipn_field .= '<p>' . $status . '</p>';
		$ipn_field .= '<p><button type="submit" name="pd_register_ipn" value="1" class="button button-secondary">' . esc_html__( 'Save and register IPN with PesaPal', 'pesa-donations' ) . '</button></p>';

		$this->row( '<strong>' . esc_html__( 'IPN URL', 'pesa-donations' ) . '</strong>', $ipn_field );
	}

	private function render_paypal(): void {
		$this->select( 'pd_paypal_environment', __( 'Environment', 'pesa-donations' ), [
			'sandbox'    => __( 'Sandbox (Testing)', 'pesa-donations' ),
			'production' => __( 'Production (Live)', 'pesa-donations' ),
		] );
		$this->input( 'pd_paypal_client_id',     __( 'Client ID', 'pesa-donations' ) );
		$this->secret( 'pd_paypal_client_secret', __( 'Client Secret', 'pesa-donations' ) );
		$this->select( 'pd_paypal_integration', __( 'Integration Style', 'pesa-donations' ), [
			'smart_buttons' => __( 'Smart Payment Buttons (Recommended)', 'pesa-donations' ),
			'redirect'      => __( 'Redirect Checkout', 'pesa-donations' ),
		] );
	}

	private function render_emails(): void {
		$this->input( 'pd_email_from_name',    __( 'From Name', 'pesa-donations' ) );
		$this->input( 'pd_email_from_address', __( 'From Email', 'pesa-donations' ), 'email' );
		$this->input( 'pd_admin_alert_email',  __( 'Admin Alerts To', 'pesa-donations' ), 'email', __( 'Where to send "new donation" notifications. Leave blank to use the site admin email.', 'pesa-donations' ) );

		$footer = (string) get_option( 'pd_email_footer', '' );
		$this->row(
			'<label for="pd_email_footer">' . esc_html__( 'Email Footer', 'pesa-donations' ) . '</label>',
			'<textarea name="pd_email_footer" id="pd_email_footer" rows="3" class="large-text">' . esc_textarea( $footer ) . '</textarea>'
			. '<p class="description">' . esc_html__( 'Shown at the bottom of every email (e.g. registered office address, charity number).', 'pesa-donations' ) . '</p>'
		);
	}

	/** A donation with no campaign: the donor just enters an amount. */
	private function render_open(): void {
		$enabled = \PesaDonations\Models\Open_Donation::is_enabled();
		$this->row(
			esc_html__( 'Open donations', 'pesa-donations' ),
			'<input type="hidden" name="pd_open_enabled" value="0" />'
			. '<label><input type="checkbox" name="pd_open_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> '
			. esc_html__( 'Accept donations that are not for a specific campaign', 'pesa-donations' ) . '</label>'
			. '<p class="description">' . sprintf(
				/* translators: %s: shortcode */
				esc_html__( 'Place %s on any page. The Donation Checkout page also shows this form when it is opened without a campaign.', 'pesa-donations' ),
				'<code>[pd_donate]</code>'
			) . '</p>'
		);

		$this->input( 'pd_open_title', __( 'Form heading', 'pesa-donations' ), 'text', __( 'Shown above the form, e.g. "Support our work".', 'pesa-donations' ) );

		$intro = (string) get_option( 'pd_open_intro', '' );
		$this->row(
			'<label for="pd_open_intro">' . esc_html__( 'Intro text', 'pesa-donations' ) . '</label>',
			'<textarea name="pd_open_intro" id="pd_open_intro" rows="3" class="large-text">' . esc_textarea( $intro ) . '</textarea>'
			. '<p class="description">' . esc_html__( 'Optional. One or two sentences under the heading.', 'pesa-donations' ) . '</p>'
		);

		$this->input(
			'pd_open_amounts',
			__( 'Quick-pick amounts', 'pesa-donations' ),
			'text',
			sprintf(
				/* translators: %s: currency code */
				__( 'Separated by commas, in %s (the default currency on the General tab). The donor can still type any amount.', 'pesa-donations' ),
				\PesaDonations\Models\Open_Donation::currency()
			)
		);
		$this->input( 'pd_open_min_amount', __( 'Minimum amount', 'pesa-donations' ), 'number' );
		$this->input( 'pd_open_label', __( 'Name on receipts and reports', 'pesa-donations' ), 'text', __( 'How these donations are named in admin lists, receipts and filters, e.g. "General donation".', 'pesa-donations' ) );
	}

	private function render_advanced(): void {
		$slug  = \PesaDonations\Modules\Dashboard\Dashboard::slug();
		$field = '<code style="margin-right:4px;">' . esc_html( trailingslashit( home_url() ) ) . '</code>'
			. '<input type="text" name="pd_dashboard_slug" id="pd_dashboard_slug" value="' . esc_attr( $slug ) . '" class="regular-text" style="width:14em;" />'
			. ' <a href="' . esc_url( \PesaDonations\Modules\Dashboard\Dashboard::url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open', 'pesa-donations' ) . '</a>';
		if ( get_page_by_path( $slug ) ) {
			$field .= '<p class="description" style="color:#b32d2e;">' . esc_html__( 'A page already uses this address, and the dashboard now hides it. Choose another address.', 'pesa-donations' ) . '</p>';
		}
		$this->row( '<label for="pd_dashboard_slug">' . esc_html__( 'Dashboard address', 'pesa-donations' ) . '</label>', $field );

		$this->input( 'pd_log_retention_days', __( 'Log Retention (days)', 'pesa-donations' ), 'number', __( 'Gateway logs older than this are automatically deleted.', 'pesa-donations' ) );

		$this->input(
			'pd_terms_url',
			__( 'Terms & Conditions URL', 'pesa-donations' ),
			'url',
			__( 'Link shown on the checkout next to the agreement checkbox. Leave blank to fall back to the WordPress Privacy Policy page.', 'pesa-donations' )
		);

		$this->color_picker(
			'pd_brand_color',
			__( 'Brand Color', 'pesa-donations' ),
			__( 'Accent color used for buttons, progress bars, and highlights. Supports HEX entry and transparency.', 'pesa-donations' )
		);

		$remove = '1' === (string) get_option( 'pd_remove_data_on_uninstall', '0' );
		$this->row(
			esc_html__( 'On delete', 'pesa-donations' ),
			'<input type="hidden" name="pd_remove_data_on_uninstall" value="0" />'
			. '<label><input type="checkbox" name="pd_remove_data_on_uninstall" value="1" ' . checked( $remove, true, false ) . ' /> '
			. esc_html__( 'Delete all donations, donors, campaigns and settings when the plugin is deleted', 'pesa-donations' ) . '</label>'
			. '<p class="description">' . esc_html__( 'Off: deleting the plugin keeps every record, so reinstalling restores them. Deactivating never deletes anything.', 'pesa-donations' ) . '</p>'
		);
	}

	/**
	 * Embedded color picker with 2D saturation area, hue + alpha sliders,
	 * and HEX entry. Matches Chrome's native color picker aesthetic.
	 * Saves: {name} (#RRGGBB) and {name}_alpha (0-100).
	 */
	private function color_picker( string $name, string $label, string $description = '' ): void {
		$hex   = get_option( $name, '#e94e4e' );
		$alpha = (int) get_option( $name . '_alpha', 100 );

		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $hex ) ) {
			$hex = '#e94e4e';
		}
		$alpha = max( 0, min( 100, $alpha ) );

		$field  = '<div class="pd-cp" data-default="' . esc_attr( $hex ) . '" data-default-alpha="' . esc_attr( (string) $alpha ) . '">';

		// 2D saturation area
		$field .= '  <div class="pd-cp__sat" data-role="sat">';
		$field .= '    <div class="pd-cp__sat-white"></div>';
		$field .= '    <div class="pd-cp__sat-black"></div>';
		$field .= '    <div class="pd-cp__sat-handle" data-role="sat-handle"></div>';
		$field .= '  </div>';

		// Hue slider
		$field .= '  <div class="pd-cp__slider pd-cp__slider--hue">';
		$field .= '    <input type="range" min="0" max="360" step="1" value="0" data-role="hue" />';
		$field .= '  </div>';

		// Alpha slider (checkered background visible through the gradient)
		$field .= '  <div class="pd-cp__slider pd-cp__slider--alpha" data-role="alpha-track">';
		$field .= '    <input type="range" min="0" max="100" step="1" value="' . esc_attr( (string) $alpha ) . '" data-role="alpha" />';
		$field .= '  </div>';

		// Footer: HEX input + alpha %
		$field .= '  <div class="pd-cp__footer">';
		$field .= '    <span class="pd-cp__mode">HEX</span>';
		$field .= '    <input type="text" class="pd-cp__hex" name="' . esc_attr( $name ) . '" value="' . esc_attr( $hex ) . '" maxlength="7" data-role="hex" />';
		$field .= '    <span class="pd-cp__alpha-val"><span data-role="alpha-val">' . esc_html( (string) $alpha ) . '</span><span class="pd-cp__percent">%</span></span>';
		$field .= '    <input type="hidden" name="' . esc_attr( $name . '_alpha' ) . '" value="' . esc_attr( (string) $alpha ) . '" data-role="alpha-hidden" />';
		$field .= '  </div>';

		$field .= '</div>';

		if ( $description ) {
			$field .= '<p class="description" style="max-width:260px;">' . esc_html( $description ) . '</p>';
		}

		add_action( 'admin_print_footer_scripts', [ $this, 'print_color_picker_assets' ], 99 );

		$this->row( '<label>' . esc_html( $label ) . '</label>', $field );
	}

	public function print_color_picker_assets(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			.pd-cp {
				display: inline-block;
				width: 240px;
				padding: 14px;
				background: #fff;
				border: 1px solid #dcdcdc;
				border-radius: 10px;
				box-shadow: 0 4px 14px rgba(0,0,0,.08);
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
				user-select: none;
				-webkit-user-select: none;
			}

			/* 2D saturation area */
			.pd-cp__sat {
				position: relative;
				width: 100%;
				aspect-ratio: 1 / 0.72;
				border-radius: 6px;
				overflow: hidden;
				cursor: crosshair;
				background: #f00;  /* will be overridden by JS with current hue */
				touch-action: none;
			}
			.pd-cp__sat-white,
			.pd-cp__sat-black {
				position: absolute;
				inset: 0;
				pointer-events: none;
			}
			.pd-cp__sat-white { background: linear-gradient(to right, #fff, transparent); }
			.pd-cp__sat-black { background: linear-gradient(to top, #000, transparent); }
			.pd-cp__sat-handle {
				position: absolute;
				width: 14px;
				height: 14px;
				border: 2px solid #fff;
				border-radius: 50%;
				box-shadow: 0 0 0 1px rgba(0,0,0,.4), 0 2px 4px rgba(0,0,0,.3);
				transform: translate(-50%, -50%);
				pointer-events: none;
				top: 0; left: 100%;
			}

			/* Sliders */
			.pd-cp__slider {
				margin-top: 12px;
				height: 14px;
				border-radius: 7px;
				position: relative;
				overflow: hidden;
			}
			.pd-cp__slider--hue {
				background: linear-gradient(to right,
					#ff0000 0%, #ffff00 17%, #00ff00 33%, #00ffff 50%,
					#0000ff 67%, #ff00ff 83%, #ff0000 100%);
			}
			.pd-cp__slider--alpha {
				background-color: #fff;
				background-image:
					linear-gradient(45deg, #ccc 25%, transparent 25%),
					linear-gradient(-45deg, #ccc 25%, transparent 25%),
					linear-gradient(45deg, transparent 75%, #ccc 75%),
					linear-gradient(-45deg, transparent 75%, #ccc 75%);
				background-size: 10px 10px;
				background-position: 0 0, 0 5px, 5px -5px, -5px 0;
			}
			.pd-cp__slider--alpha::before {
				content: '';
				position: absolute; inset: 0;
				background: linear-gradient(to right, transparent, var(--pd-cp-base, #e94e4e));
				pointer-events: none;
			}

			/* Native range input reset + thumb styling */
			.pd-cp__slider input[type=range] {
				-webkit-appearance: none;
				appearance: none;
				width: 100%;
				height: 14px;
				background: transparent;
				position: relative;
				z-index: 2;
				margin: 0;
				padding: 0;
				cursor: pointer;
				outline: none;
			}
			.pd-cp__slider input[type=range]::-webkit-slider-runnable-track {
				height: 14px; background: transparent; border: 0;
			}
			.pd-cp__slider input[type=range]::-moz-range-track {
				height: 14px; background: transparent; border: 0;
			}
			.pd-cp__slider input[type=range]::-webkit-slider-thumb {
				-webkit-appearance: none;
				appearance: none;
				width: 16px; height: 16px;
				border-radius: 50%;
				background: #fff;
				border: 2px solid #fff;
				box-shadow: 0 0 0 1px rgba(0,0,0,.4), 0 2px 4px rgba(0,0,0,.3);
				cursor: grab;
				margin-top: -1px;
			}
			.pd-cp__slider input[type=range]::-moz-range-thumb {
				width: 16px; height: 16px;
				border-radius: 50%;
				background: #fff;
				border: 2px solid #fff;
				box-shadow: 0 0 0 1px rgba(0,0,0,.4), 0 2px 4px rgba(0,0,0,.3);
				cursor: grab;
			}

			/* Footer */
			.pd-cp__footer {
				display: flex;
				align-items: center;
				gap: 6px;
				margin-top: 14px;
				font-size: 12px;
			}
			.pd-cp__mode {
				padding: 6px 10px;
				border: 1px solid #dcdcdc;
				border-radius: 4px;
				background: #f8f8f8;
				font-weight: 600;
				color: #555;
				font-size: 11px;
				letter-spacing: .5px;
			}
			.pd-cp__hex {
				flex: 1;
				min-width: 0;
				padding: 6px 8px;
				border: 1px solid #dcdcdc;
				border-radius: 4px;
				font-family: ui-monospace, 'SF Mono', Consolas, monospace;
				font-size: 12px;
				color: #222;
				background: #fff;
			}
			.pd-cp__hex:focus { outline: none; border-color: #2e7d32; }
			.pd-cp__alpha-val {
				min-width: 42px;
				display: inline-flex;
				align-items: baseline;
				gap: 2px;
				color: #555;
				font-family: ui-monospace, monospace;
				font-size: 12px;
				justify-content: flex-end;
			}
			.pd-cp__percent { color: #888; }
		</style>
		<script>
		(function(){
			function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
			function hsv2rgb(h, s, v) {
				h = h % 360; s /= 100; v /= 100;
				var c = v * s;
				var x = c * (1 - Math.abs(((h/60) % 2) - 1));
				var m = v - c;
				var r, g, b;
				if (h < 60)       [r,g,b] = [c,x,0];
				else if (h < 120) [r,g,b] = [x,c,0];
				else if (h < 180) [r,g,b] = [0,c,x];
				else if (h < 240) [r,g,b] = [0,x,c];
				else if (h < 300) [r,g,b] = [x,0,c];
				else              [r,g,b] = [c,0,x];
				return [Math.round((r+m)*255), Math.round((g+m)*255), Math.round((b+m)*255)];
			}
			function rgb2hex(r, g, b) {
				var to = function(n){ var s = n.toString(16); return s.length<2 ? '0'+s : s; };
				return '#' + to(r) + to(g) + to(b);
			}
			function hex2rgb(hex) {
				var m = hex.replace('#','');
				return [parseInt(m.substr(0,2),16), parseInt(m.substr(2,2),16), parseInt(m.substr(4,2),16)];
			}
			function rgb2hsv(r, g, b) {
				r/=255; g/=255; b/=255;
				var max = Math.max(r,g,b), min = Math.min(r,g,b);
				var d = max - min, h = 0, s = max===0 ? 0 : d/max, v = max;
				if (d !== 0) {
					switch (max) {
						case r: h = ((g - b) / d + (g < b ? 6 : 0)); break;
						case g: h = ((b - r) / d + 2); break;
						case b: h = ((r - g) / d + 4); break;
					}
					h *= 60;
				}
				return [h, s*100, v*100];
			}
			function isHex(v) { return /^#?[0-9a-fA-F]{6}$/.test(v); }

			document.querySelectorAll('.pd-cp').forEach(function(root){
				var sat      = root.querySelector('[data-role=sat]');
				var satH     = root.querySelector('[data-role=sat-handle]');
				var hueEl    = root.querySelector('[data-role=hue]');
				var alphaEl  = root.querySelector('[data-role=alpha]');
				var alphaTr  = root.querySelector('[data-role=alpha-track]');
				var hexEl    = root.querySelector('[data-role=hex]');
				var alphaV   = root.querySelector('[data-role=alpha-val]');
				var alphaHid = root.querySelector('[data-role=alpha-hidden]');

				// State
				var startHex   = root.dataset.default || '#e94e4e';
				var startAlpha = parseInt(root.dataset.defaultAlpha || '100', 10);
				var rgb0 = hex2rgb(startHex);
				var hsv0 = rgb2hsv(rgb0[0], rgb0[1], rgb0[2]);
				var H = hsv0[0], S = hsv0[1], V = hsv0[2], A = startAlpha;

				function render() {
					var rgb = hsv2rgb(H, S, V);
					var hex = rgb2hex(rgb[0], rgb[1], rgb[2]);
					var pureHueRgb = hsv2rgb(H, 100, 100);
					var pureHueHex = rgb2hex(pureHueRgb[0], pureHueRgb[1], pureHueRgb[2]);

					// 2D area: base color = pure hue
					sat.style.background = pureHueHex;

					// 2D handle position
					satH.style.left = S + '%';
					satH.style.top  = (100 - V) + '%';
					satH.style.background = hex;

					// Alpha track base color
					alphaTr.style.setProperty('--pd-cp-base', hex);

					// HEX field, alpha value, hidden alpha
					if (document.activeElement !== hexEl) {
						hexEl.value = hex;
					}
					alphaV.textContent = Math.round(A);
					alphaHid.value = Math.round(A);

					// Sliders
					if (parseInt(hueEl.value, 10) !== Math.round(H)) hueEl.value = Math.round(H);
					if (parseInt(alphaEl.value, 10) !== Math.round(A)) alphaEl.value = Math.round(A);
				}

				// --- Saturation drag ---
				function moveSat(e) {
					var rect = sat.getBoundingClientRect();
					var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
					var y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
					S = clamp((x / rect.width) * 100, 0, 100);
					V = clamp(100 - (y / rect.height) * 100, 0, 100);
					render();
				}
				function startSat(e) {
					moveSat(e);
					var move = function(ev){ ev.preventDefault(); moveSat(ev); };
					var up   = function(){
						document.removeEventListener('mousemove', move);
						document.removeEventListener('mouseup', up);
						document.removeEventListener('touchmove', move);
						document.removeEventListener('touchend', up);
					};
					document.addEventListener('mousemove', move);
					document.addEventListener('mouseup', up);
					document.addEventListener('touchmove', move, { passive: false });
					document.addEventListener('touchend', up);
				}
				sat.addEventListener('mousedown', startSat);
				sat.addEventListener('touchstart', startSat, { passive: true });

				// --- Hue slider ---
				hueEl.addEventListener('input', function(){ H = parseInt(hueEl.value, 10); render(); });

				// --- Alpha slider ---
				alphaEl.addEventListener('input', function(){ A = parseInt(alphaEl.value, 10); render(); });

				// --- HEX input ---
				hexEl.addEventListener('input', function(){
					var v = hexEl.value.trim();
					if (v && v[0] !== '#') v = '#' + v;
					if (isHex(v)) {
						var rgb = hex2rgb(v);
						var hsv = rgb2hsv(rgb[0], rgb[1], rgb[2]);
						H = hsv[0]; S = hsv[1]; V = hsv[2];
						render();
					}
				});
				hexEl.addEventListener('blur', function(){
					if (!isHex(hexEl.value.trim())) render();
				});

				render();
			});
		})();
		</script>
		<?php
	}

	private function render_shortcodes(): void {
		$shortcodes = [
			[
				'tag'         => 'pd_sponsor_browse',
				'purpose'     => __( 'Full sponsorship browsing page — sidebar filters (age, status), search, grid/list toggle, sort, pagination.', 'pesa-donations' ),
				'params'      => [
					'per_page' => __( 'Items per page (default 12)', 'pesa-donations' ),
					'columns'  => __( 'Grid columns: 2, 3, or 4 (default 3)', 'pesa-donations' ),
					'filters'  => __( 'true / false — show the sidebar filters (default true). Set to false for a clean grid-only layout. On mobile the sidebar is always a slide-out drawer.', 'pesa-donations' ),
				],
				'example'     => '[pd_sponsor_browse per_page="24" columns="3" filters="true"]',
				'recommended' => __( 'Put this on your "Sponsor a Child" page.', 'pesa-donations' ),
			],
			[
				'tag'         => 'pd_give_browse',
				'purpose'     => __( 'Full project browsing page — sidebar filters (goal amount, status), search, grid/list toggle, sort, pagination.', 'pesa-donations' ),
				'params'      => [
					'per_page' => __( 'Items per page (default 12)', 'pesa-donations' ),
					'columns'  => __( 'Grid columns: 2, 3, or 4 (default 3)', 'pesa-donations' ),
					'filters'  => __( 'true / false — show the sidebar filters (default true). Set to false for a clean grid-only layout. On mobile the sidebar is always a slide-out drawer.', 'pesa-donations' ),
				],
				'example'     => '[pd_give_browse per_page="12" columns="3" filters="true"]',
				'recommended' => __( 'Put this on your "Give" or "Projects" page.', 'pesa-donations' ),
			],
			[
				'tag'         => 'pd_sponsor_slider',
				'purpose'     => __( 'Horizontal carousel of sponsorship cards with prev/next arrows. Great for homepage sections.', 'pesa-donations' ),
				'params'      => [
					'limit'    => __( 'Max campaigns to include (default 10)', 'pesa-donations' ),
					'per_view' => __( 'Cards visible at once: 1-5 (default 3)', 'pesa-donations' ),
					'autoplay' => __( 'true / false (default false)', 'pesa-donations' ),
					'interval' => __( 'Autoplay delay in ms (default 4500)', 'pesa-donations' ),
				],
				'example'     => '[pd_sponsor_slider per_view="3" autoplay="true"]',
				'recommended' => __( 'Drop into a homepage section under the hero.', 'pesa-donations' ),
			],
			[
				'tag'         => 'pd_give_slider',
				'purpose'     => __( 'Horizontal carousel of project cards with prev/next arrows. Great for homepage sections.', 'pesa-donations' ),
				'params'      => [
					'limit'    => __( 'Max campaigns to include (default 10)', 'pesa-donations' ),
					'per_view' => __( 'Cards visible at once: 1-5 (default 3)', 'pesa-donations' ),
					'autoplay' => __( 'true / false (default false)', 'pesa-donations' ),
					'interval' => __( 'Autoplay delay in ms (default 4500)', 'pesa-donations' ),
				],
				'example'     => '[pd_give_slider per_view="3" autoplay="false"]',
				'recommended' => __( 'Drop into a homepage section under the hero.', 'pesa-donations' ),
			],
			[
				'tag'         => 'pd_donate_button',
				'purpose'     => __( 'A single pill button that takes donors to the checkout for one specific campaign.', 'pesa-donations' ),
				'params'      => [
					'id'    => __( 'Campaign ID (required)', 'pesa-donations' ),
					'label' => __( 'Button label (default "Donate Now")', 'pesa-donations' ),
				],
				'example'     => '[pd_donate_button id="10" label="Sponsor Now"]',
				'recommended' => __( 'Useful inside article content, sidebars, or widgets.', 'pesa-donations' ),
			],
			[
				'tag'         => 'pd_donate',
				'purpose'     => __( 'Open donation form: no campaign, the donor just enters an amount. Recorded as a general donation. Settings → Open Donations sets the heading, quick-pick amounts and minimum.', 'pesa-donations' ),
				'params'      => [
					'title'   => __( 'Heading (default from Settings → Open Donations)', 'pesa-donations' ),
					'amounts' => __( 'Quick-pick amounts, comma-separated, e.g. "20000,50000,100000"', 'pesa-donations' ),
				],
				'example'     => '[pd_donate title="Support our work"]',
				'recommended' => __( 'Put this on your "Donate" page or in a footer section.', 'pesa-donations' ),
			],
			[
				'tag'         => 'pd_checkout',
				'purpose'     => __( 'Donation checkout form. Auto-loads the campaign from ?pd_cid=ID in the URL; with no campaign it shows the open donation form.', 'pesa-donations' ),
				'params'      => [],
				'example'     => '[pd_checkout]',
				'recommended' => sprintf(
					/* translators: %s: checkout page name */
					__( 'Already placed on your "%s" page by the plugin — no manual setup needed.', 'pesa-donations' ),
					esc_html__( 'Donation Checkout', 'pesa-donations' )
				),
			],
			[
				'tag'         => 'pd_thank_you',
				'purpose'     => __( 'Post-payment confirmation page. Status-aware — shows success, pending, or failed message based on the donation state.', 'pesa-donations' ),
				'params'      => [],
				'example'     => '[pd_thank_you]',
				'recommended' => sprintf(
					/* translators: %s: thank-you page name */
					__( 'Already placed on your "%s" page by the plugin — no manual setup needed.', 'pesa-donations' ),
					esc_html__( 'Thank You', 'pesa-donations' )
				),
			],
		];
		?>
		<style>
			.pd-shortcodes-wrap { max-width: 900px; margin: 20px 0; }
			.pd-shortcodes-wrap > p.description { font-size: 14px; margin-bottom: 20px; }
			.pd-sc-card {
				background: #fff;
				border: 1px solid #dcdcdc;
				border-left: 4px solid #2e7d32;
				border-radius: 6px;
				padding: 18px 22px;
				margin-bottom: 14px;
				box-shadow: 0 1px 3px rgba(0,0,0,.04);
			}
			.pd-sc-card__header { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; flex-wrap: wrap; }
			.pd-sc-card__tag {
				font-family: ui-monospace, 'SF Mono', Consolas, monospace;
				font-size: 14px;
				font-weight: 700;
				background: #f1f8f3;
				color: #1b5e20;
				padding: 4px 10px;
				border-radius: 4px;
				border: 1px solid #c7e0c9;
			}
			.pd-sc-card__purpose { font-size: 13px; color: #555; margin: 4px 0 10px; line-height: 1.55; }
			.pd-sc-card__params { margin: 8px 0 10px; }
			.pd-sc-card__params h4 {
				font-size: 11px;
				text-transform: uppercase;
				letter-spacing: 1px;
				color: #777;
				margin: 0 0 6px;
				font-weight: 600;
			}
			.pd-sc-card__params ul { margin: 0; padding-left: 0; list-style: none; }
			.pd-sc-card__params li { font-size: 13px; color: #444; padding: 3px 0; }
			.pd-sc-card__params code {
				font-family: ui-monospace, monospace;
				font-size: 12px;
				background: #f5f5f5;
				padding: 1px 6px;
				border-radius: 3px;
				color: #c62828;
			}
			.pd-sc-card__example {
				position: relative;
				background: #1e1e1e;
				color: #e0e0e0;
				padding: 10px 44px 10px 14px;
				border-radius: 4px;
				font-family: ui-monospace, monospace;
				font-size: 13px;
				margin: 0;
				user-select: all;
				overflow-x: auto;
			}
			.pd-sc-card__copy {
				position: absolute;
				top: 6px;
				right: 6px;
				background: rgba(255,255,255,.1);
				color: #e0e0e0;
				border: 1px solid rgba(255,255,255,.2);
				border-radius: 3px;
				padding: 3px 10px;
				font-size: 11px;
				cursor: pointer;
				font-weight: 600;
				letter-spacing: .5px;
				text-transform: uppercase;
				transition: background .2s;
			}
			.pd-sc-card__copy:hover { background: rgba(255,255,255,.2); }
			.pd-sc-card__copy--copied { background: #2e7d32; border-color: #2e7d32; color: #fff; }
			.pd-sc-card__recommended {
				font-size: 12px;
				color: #777;
				font-style: italic;
				margin: 8px 0 0;
			}
		</style>

		<div class="pd-shortcodes-wrap">
			<p class="description">
				<?php esc_html_e( 'Copy any of these shortcodes into a page or post to display donation content. Click "Copy" to copy the example to your clipboard.', 'pesa-donations' ); ?>
			</p>

			<?php foreach ( $shortcodes as $sc ) : ?>
				<div class="pd-sc-card">
					<div class="pd-sc-card__header">
						<span class="pd-sc-card__tag">[<?php echo esc_html( $sc['tag'] ); ?>]</span>
					</div>

					<p class="pd-sc-card__purpose"><?php echo esc_html( $sc['purpose'] ); ?></p>

					<?php if ( ! empty( $sc['params'] ) ) : ?>
						<div class="pd-sc-card__params">
							<h4><?php esc_html_e( 'Parameters', 'pesa-donations' ); ?></h4>
							<ul>
								<?php foreach ( $sc['params'] as $param_name => $param_desc ) : ?>
									<li><code><?php echo esc_html( $param_name ); ?></code> — <?php echo esc_html( $param_desc ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<pre class="pd-sc-card__example"><?php echo esc_html( $sc['example'] ); ?><button type="button" class="pd-sc-card__copy" data-copy="<?php echo esc_attr( $sc['example'] ); ?>"><?php esc_html_e( 'Copy', 'pesa-donations' ); ?></button></pre>

					<?php if ( ! empty( $sc['recommended'] ) ) : ?>
						<p class="pd-sc-card__recommended">&#128161; <?php echo esc_html( $sc['recommended'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<script>
		(function(){
			document.addEventListener('click', function (e) {
				if (!e.target.matches('.pd-sc-card__copy')) return;
				const btn = e.target;
				const text = btn.getAttribute('data-copy');
				if (!text || !navigator.clipboard) return;
				navigator.clipboard.writeText(text).then(function () {
					const original = btn.textContent;
					btn.textContent = '<?php echo esc_js( __( 'Copied!', 'pesa-donations' ) ); ?>';
					btn.classList.add('pd-sc-card__copy--copied');
					setTimeout(function () {
						btn.textContent = original;
						btn.classList.remove('pd-sc-card__copy--copied');
					}, 1400);
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Saves the fields the submitted tab carries. A value that fails its check
	 * is not saved (the previous one stays) and is reported.
	 *
	 * @return string[] What was refused, for the notice.
	 */
	private function save(): array {
		$errors = [];
		$choice = [
			'pd_default_currency'    => [ 'UGX', 'KES', 'TZS', 'USD' ],
			'pd_pesapal_environment' => [ 'sandbox', 'production' ],
			'pd_paypal_environment'  => [ 'sandbox', 'production' ],
			'pd_paypal_integration'  => [ 'smart_buttons', 'redirect' ],
			'pd_open_enabled'        => [ '0', '1' ],
			'pd_remove_data_on_uninstall' => [ '0', '1' ],
		];
		$text = [
			'pd_pesapal_consumer_key', 'pd_paypal_client_id', 'pd_email_from_name',
			'pd_open_title', 'pd_open_label', 'pd_open_amounts',
		];
		$emails = [
			'pd_email_from_address' => __( 'From Email', 'pesa-donations' ),
			'pd_admin_alert_email'  => __( 'Admin Alerts To', 'pesa-donations' ),
		];
		$credentials_before = [ Pesapal_Auth::environment(), get_option( 'pd_pesapal_consumer_key' ), get_option( 'pd_pesapal_consumer_secret' ) ];

		foreach ( $choice as $field => $allowed ) {
			if ( isset( $_POST[ $field ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
				if ( in_array( $value, $allowed, true ) ) {
					update_option( $field, $value );
				}
			}
		}
		foreach ( $text as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_option( $field, trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) );
			}
		}
		foreach ( self::SECRETS as $field ) {
			$value = isset( $_POST[ $field ] ) ? trim( (string) wp_unslash( $_POST[ $field ] ) ) : '';
			if ( '' !== $value ) {
				update_option( $field, sanitize_text_field( $value ) );
			}
		}
		foreach ( $emails as $field => $label ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			if ( '' === $value && 'pd_admin_alert_email' === $field ) {
				update_option( $field, '' ); // Blank: alerts go to the site admin email.
			} elseif ( is_email( $value ) ) {
				update_option( $field, sanitize_email( $value ) );
			} else {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s: that is not an email address, so the previous one was kept.', 'pesa-donations' ), $label );
			}
		}
		if ( isset( $_POST['pd_terms_url'] ) ) {
			update_option( 'pd_terms_url', esc_url_raw( trim( (string) wp_unslash( $_POST['pd_terms_url'] ) ) ) );
		}
		if ( isset( $_POST['pd_log_retention_days'] ) ) {
			update_option( 'pd_log_retention_days', max( 1, min( 3650, absint( $_POST['pd_log_retention_days'] ) ) ) );
		}
		if ( isset( $_POST['pd_open_min_amount'] ) ) {
			$min = trim( (string) wp_unslash( $_POST['pd_open_min_amount'] ) );
			update_option( 'pd_open_min_amount', is_numeric( $min ) && (float) $min > 0 ? (string) (float) $min : '' );
		}

		// A cached PesaPal token belongs to the old environment and keys.
		$credentials_after = [ Pesapal_Auth::environment(), get_option( 'pd_pesapal_consumer_key' ), get_option( 'pd_pesapal_consumer_secret' ) ];
		if ( $credentials_before !== $credentials_after ) {
			Pesapal_Auth::clear_token();
		}

		// Multi-line footer field.
		if ( isset( $_POST['pd_email_footer'] ) ) {
			update_option( 'pd_email_footer', wp_kses_post( wp_unslash( $_POST['pd_email_footer'] ) ) );
		}
		if ( isset( $_POST['pd_open_intro'] ) ) {
			update_option( 'pd_open_intro', sanitize_textarea_field( wp_unslash( $_POST['pd_open_intro'] ) ) );
		}

		// Dashboard address: a slug; a change needs the rewrite rules rebuilt.
		if ( isset( $_POST['pd_dashboard_slug'] ) ) {
			$slug     = sanitize_title( wp_unslash( $_POST['pd_dashboard_slug'] ) );
			$reserved = [ 'wp-admin', 'wp-login', 'wp-json', 'wp-content', 'wp-includes', 'feed', 'page', 'comments', 'search', 'author', 'category', 'tag', 'embed' ];
			if ( '' !== $slug && \PesaDonations\Modules\Dashboard\Dashboard::slug() !== $slug ) {
				if ( in_array( $slug, $reserved, true ) || get_page_by_path( $slug ) ) {
					/* translators: %s: address */
					$errors[] = sprintf( __( 'Dashboard address: "%s" is already used on this site, so the previous address was kept.', 'pesa-donations' ), $slug );
				} else {
					update_option( \PesaDonations\Modules\Dashboard\Dashboard::SLUG_OPTION, $slug );
					\PesaDonations\Modules\Dashboard\Dashboard::request_flush();
				}
			}
		}

		// Color picker — validate + save hex and alpha.
		if ( isset( $_POST['pd_brand_color'] ) ) {
			$hex = sanitize_text_field( wp_unslash( $_POST['pd_brand_color'] ) );
			if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
				update_option( 'pd_brand_color', strtolower( $hex ) );
			}
		}
		if ( isset( $_POST['pd_brand_color_alpha'] ) ) {
			$alpha = max( 0, min( 100, (int) $_POST['pd_brand_color_alpha'] ) );
			update_option( 'pd_brand_color_alpha', $alpha );
		}

		return $errors;
	}
}
