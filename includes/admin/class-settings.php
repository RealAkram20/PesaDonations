<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

class Settings {

	private const OPTION_GROUP = 'pd_settings';

	public function render(): void {
		if ( isset( $_POST['pd_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pd_settings_nonce'] ) ), 'pd_save_settings' ) ) {
			$this->save();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'pesa-donations' ) . '</p></div>';
		}

		// Handle "Register IPN" button click.
		if (
			isset( $_POST['pd_register_ipn'] ) &&
			isset( $_POST['pd_settings_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pd_settings_nonce'] ) ), 'pd_save_settings' )
		) {
			delete_option( 'pd_pesapal_ipn_id' );
			delete_option( 'pd_pesapal_ipn_env' );
			$ipn_id = ( new \PesaDonations\Payments\Pesapal\Pesapal_Gateway() )->ensure_ipn_registered();
			if ( $ipn_id ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'PesaPal IPN registered successfully. IPN ID: ', 'pesa-donations' ) . esc_html( $ipn_id ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to register IPN. Check that your keys are correct and see the logs.', 'pesa-donations' ) . '</p></div>';
			}
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PesaDonations Settings', 'pesa-donations' ); ?></h1>
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
				<?php submit_button( __( 'Save Settings', 'pesa-donations' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function tabs(): array {
		return [
			'general'  => __( 'General', 'pesa-donations' ),
			'pesapal'  => __( 'PesaPal', 'pesa-donations' ),
			'paypal'   => __( 'PayPal', 'pesa-donations' ),
			'emails'   => __( 'Emails', 'pesa-donations' ),
			'advanced' => __( 'Advanced', 'pesa-donations' ),
		];
	}

	private function render_tab( string $tab ): void {
		echo '<table class="form-table"><tbody>';
		match ( $tab ) {
			'general'  => $this->render_general(),
			'pesapal'  => $this->render_pesapal(),
			'paypal'   => $this->render_paypal(),
			'emails'   => $this->render_emails(),
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
		$this->input( 'pd_pesapal_consumer_secret', __( 'Consumer Secret', 'pesa-donations' ), 'password' );

		$ipn_url     = \PesaDonations\Payments\Pesapal\Pesapal_Gateway::get_ipn_url();
		$ipn_id      = (string) get_option( 'pd_pesapal_ipn_id' );
		$ipn_env     = (string) get_option( 'pd_pesapal_ipn_env' );
		$current_env = (string) get_option( 'pd_pesapal_environment', 'sandbox' );

		$status = $ipn_id && $ipn_env === $current_env
			? '<span style="color:#28a745;">&#9989; ' . esc_html__( 'Registered', 'pesa-donations' ) . '</span> &nbsp; <code>' . esc_html( $ipn_id ) . '</code>'
			: '<span style="color:#c62828;">&#10060; ' . esc_html__( 'Not registered yet', 'pesa-donations' ) . '</span>';

		$ipn_field  = '<code style="display:block;padding:8px;background:#f5f5f5;margin-bottom:8px;user-select:all;">' . esc_html( $ipn_url ) . '</code>';
		$ipn_field .= '<p class="description">' . esc_html__( 'PesaPal will POST to this URL whenever a payment status changes. Save your keys first, then click the button below to register.', 'pesa-donations' ) . '</p>';
		$ipn_field .= '<p>' . $status . '</p>';
		$ipn_field .= '<p><button type="submit" name="pd_register_ipn" value="1" class="button button-secondary">' . esc_html__( 'Register / Re-register IPN with PesaPal', 'pesa-donations' ) . '</button></p>';

		$this->row( '<strong>' . esc_html__( 'IPN URL', 'pesa-donations' ) . '</strong>', $ipn_field );
	}

	private function render_paypal(): void {
		$this->select( 'pd_paypal_environment', __( 'Environment', 'pesa-donations' ), [
			'sandbox'    => __( 'Sandbox (Testing)', 'pesa-donations' ),
			'production' => __( 'Production (Live)', 'pesa-donations' ),
		] );
		$this->input( 'pd_paypal_client_id',     __( 'Client ID', 'pesa-donations' ) );
		$this->input( 'pd_paypal_client_secret', __( 'Client Secret', 'pesa-donations' ), 'password' );
		$this->select( 'pd_paypal_integration', __( 'Integration Style', 'pesa-donations' ), [
			'smart_buttons' => __( 'Smart Payment Buttons (Recommended)', 'pesa-donations' ),
			'redirect'      => __( 'Redirect Checkout', 'pesa-donations' ),
		] );
	}

	private function render_emails(): void {
		$this->input( 'pd_email_from_name',    __( 'From Name', 'pesa-donations' ) );
		$this->input( 'pd_email_from_address', __( 'From Email', 'pesa-donations' ), 'email' );
	}

	private function render_advanced(): void {
		$this->input( 'pd_log_retention_days', __( 'Log Retention (days)', 'pesa-donations' ), 'number', __( 'Gateway logs older than this are automatically deleted.', 'pesa-donations' ) );
	}

	private function save(): void {
		$fields = [
			'pd_default_currency', 'pd_pesapal_environment', 'pd_pesapal_consumer_key',
			'pd_pesapal_consumer_secret', 'pd_paypal_environment', 'pd_paypal_client_id',
			'pd_paypal_client_secret', 'pd_paypal_integration', 'pd_email_from_name',
			'pd_email_from_address', 'pd_log_retention_days',
		];
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
	}
}
