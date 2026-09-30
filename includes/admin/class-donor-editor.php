<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\Models\Donor;
use PesaDonations\Models\Open_Donation;
use PesaDonations\Utils\Countries;
use PesaDonations\Utils\Money;
use PesaDonations\Utils\Sanitizer;
use WP_Error;

class Donor_Editor {

	/** A refused save: the message, and the values as typed, shown again by render(). */
	private static string $error  = '';
	private static array $posted = [];

	/** Runs on load-{page}, before any output, so the redirect after a save works. */
	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['pd_donor_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'pd_save_donor', 'pd_donor_nonce' );
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'pesa-donations' ), 403 );
		}

		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$result = ( new self() )->save( $id );
		if ( is_wp_error( $result ) ) {
			self::$error  = $result->get_error_message();
			self::$posted = wp_unslash( $_POST );
			return;
		}
		wp_safe_redirect( add_query_arg( [
			'page'   => 'pd-donor-edit',
			'id'     => $result,
			'pd_msg' => $id ? 'saved' : 'created',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$is_new = ! $id;

		global $wpdb;
		$donor = $id
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donors WHERE id = %d", $id ), ARRAY_A )
			: null;

		if ( $id && ! $donor ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Donor not found', 'pesa-donations' ) . '</h1></div>';
			return;
		}

		$data = $donor ?: $this->defaults();
		if ( Donor::is_placeholder_email( (string) $data['email'] ) ) {
			$data['email'] = '';
		}
		foreach ( [ 'email', 'first_name', 'last_name', 'phone', 'country' ] as $k ) {
			if ( isset( self::$posted[ $k ] ) && is_string( self::$posted[ $k ] ) ) {
				$data[ $k ] = self::$posted[ $k ];
			}
		}

		$donations = $id ? $this->get_donations_for_donor( $id ) : [];
		$totals    = $id ? ( Donor::totals_by_currency( [ $id ] )[ $id ] ?? [] ) : [];

		$this->render_form( $is_new, $data, $donations, $totals );
	}

	private function defaults(): array {
		return [
			'id'                 => 0,
			'email'              => '',
			'phone'              => '',
			'first_name'         => '',
			'last_name'          => '',
			'country'            => '',
			'total_donated_base' => 0,
			'donation_count'     => 0,
			'first_donation_at'  => '',
			'last_donation_at'   => '',
			'created_at'         => '',
		];
	}

	private function get_donations_for_donor( int $donor_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id, d.merchant_reference, d.amount, d.currency, d.status, d.gateway, d.environment, d.created_at,
						d.campaign_id, p.post_title AS campaign_title
				 FROM {$wpdb->prefix}pd_donations d
				 LEFT JOIN {$wpdb->posts} p ON p.ID = d.campaign_id
				 WHERE d.donor_id = %d
				 ORDER BY d.created_at DESC
				 LIMIT 50",
				$donor_id
			),
			ARRAY_A
		) ?: [];
	}

	private function render_form( bool $is_new, array $data, array $donations, array $totals ): void {
		$display_name = trim( $data['first_name'] . ' ' . $data['last_name'] ) ?: __( '(No name)', 'pesa-donations' );
		$title = $is_new
			? __( 'Add New Donor', 'pesa-donations' )
			: sprintf(
				/* translators: %s: donor display name */
				__( 'Edit Donor — %s', 'pesa-donations' ),
				$display_name
			);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pd-donors' ) ); ?>" class="page-title-action">
				&larr; <?php esc_html_e( 'Back to list', 'pesa-donations' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php if ( self::$error ) : ?>
				<div class="notice notice-error"><p><?php echo wp_kses( self::$error, [ 'a' => [ 'href' => [] ] ] ); ?></p></div>
			<?php elseif ( isset( $_GET['pd_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php 'created' === $_GET['pd_msg'] ? esc_html_e( 'Donor created.', 'pesa-donations' ) : esc_html_e( 'Donor saved.', 'pesa-donations' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="" id="pd-donor-form">
				<?php wp_nonce_field( 'pd_save_donor', 'pd_donor_nonce' ); ?>

				<div class="pd-editor-grid">

					<div class="pd-editor-col">
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Donor Information', 'pesa-donations' ); ?></span></h2>
							<div class="inside">
								<table class="form-table"><tbody>
									<tr>
										<th><label for="pd_first_name"><?php esc_html_e( 'First Name', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="first_name" id="pd_first_name" value="<?php echo esc_attr( $data['first_name'] ); ?>" class="regular-text" /></td>
									</tr>
									<tr>
										<th><label for="pd_last_name"><?php esc_html_e( 'Last Name', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="last_name" id="pd_last_name" value="<?php echo esc_attr( $data['last_name'] ); ?>" class="regular-text" /></td>
									</tr>
									<tr>
										<th><label for="pd_email"><?php esc_html_e( 'Email', 'pesa-donations' ); ?></label></th>
										<td>
											<input type="email" name="email" id="pd_email" value="<?php echo esc_attr( $data['email'] ); ?>" class="regular-text" />
											<p class="description"><?php esc_html_e( 'Email or phone is required.', 'pesa-donations' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label for="pd_phone"><?php esc_html_e( 'Phone', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="phone" id="pd_phone" value="<?php echo esc_attr( $data['phone'] ); ?>" class="regular-text" /></td>
									</tr>
									<tr>
										<th><label for="pd_country"><?php esc_html_e( 'Country', 'pesa-donations' ); ?></label></th>
										<td>
											<select name="country" id="pd_country">
												<option value=""><?php esc_html_e( '—', 'pesa-donations' ); ?></option>
												<?php foreach ( Countries::all() as $code => $name ) : ?>
													<option value="<?php echo esc_attr( $code ); ?>" <?php selected( strtoupper( (string) $data['country'] ), $code ); ?>><?php echo esc_html( $name ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								</tbody></table>
							</div>
						</div>
					</div>

					<div class="pd-editor-col">

						<?php if ( ! $is_new ) : ?>
							<div class="postbox">
								<h2 class="hndle"><span><?php esc_html_e( 'Summary', 'pesa-donations' ); ?></span></h2>
								<div class="inside pd-donor-summary">
									<div class="pd-stat">
										<span class="pd-stat__value"><?php echo (int) $data['donation_count']; ?></span>
										<span class="pd-stat__label"><?php esc_html_e( 'Donations', 'pesa-donations' ); ?></span>
									</div>
									<div class="pd-stat">
										<span class="pd-stat__value pd-stat__value--small"><?php echo esc_html( Money::format_totals( $totals ) ); ?></span>
										<span class="pd-stat__label"><?php esc_html_e( 'Total Given', 'pesa-donations' ); ?></span>
									</div>
									<div class="pd-stat">
										<span class="pd-stat__value pd-stat__value--small">
											<?php echo esc_html( $data['first_donation_at'] ? mysql2date( 'M j, Y', $data['first_donation_at'] ) : '—' ); ?>
										</span>
										<span class="pd-stat__label"><?php esc_html_e( 'First Donation', 'pesa-donations' ); ?></span>
									</div>
									<div class="pd-stat">
										<span class="pd-stat__value pd-stat__value--small">
											<?php echo esc_html( $data['last_donation_at'] ? mysql2date( 'M j, Y', $data['last_donation_at'] ) : '—' ); ?>
										</span>
										<span class="pd-stat__label"><?php esc_html_e( 'Last Donation', 'pesa-donations' ); ?></span>
									</div>
								</div>
							</div>
						<?php endif; ?>

					</div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large">
						<?php echo $is_new ? esc_html__( 'Create Donor', 'pesa-donations' ) : esc_html__( 'Update Donor', 'pesa-donations' ); ?>
					</button>
				</p>

			</form>

			<?php if ( ! $is_new && ! empty( $donations ) ) : ?>
				<h2 style="margin-top:32px;"><?php esc_html_e( 'Donation History', 'pesa-donations' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'pesa-donations' ); ?></th>
							<th><?php esc_html_e( 'Reference', 'pesa-donations' ); ?></th>
							<th><?php esc_html_e( 'Campaign', 'pesa-donations' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'pesa-donations' ); ?></th>
							<th><?php esc_html_e( 'Gateway', 'pesa-donations' ); ?></th>
							<th><?php esc_html_e( 'Status', 'pesa-donations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $donations as $d ) :
							$edit_url = add_query_arg( [ 'page' => 'pd-donation-edit', 'id' => (int) $d['id'] ], admin_url( 'admin.php' ) );
						?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'M j, Y', $d['created_at'] ) ); ?></td>
								<td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $d['merchant_reference'] ); ?></a></td>
								<td><?php echo esc_html( Open_Donation::CAMPAIGN_ID === (int) $d['campaign_id'] ? Open_Donation::label() : ( $d['campaign_title'] ?: '—' ) ); ?></td>
								<td><strong><?php echo esc_html( Money::format( (float) $d['amount'], (string) $d['currency'] ) ); ?></strong></td>
								<td><?php echo esc_html( ucfirst( $d['gateway'] ) ); ?></td>
								<td>
									<span class="pd-status pd-status--<?php echo esc_attr( $d['status'] ); ?>"><?php echo esc_html( ucfirst( $d['status'] ) ); ?></span>
									<?php if ( 'sandbox' === $d['environment'] ) : ?>
										<span class="pd-status pd-status--test"><?php esc_html_e( 'Test', 'pesa-donations' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( ! $is_new ) : ?>
				<p style="margin-top:24px;color:#646970;font-style:italic;">
					<?php esc_html_e( 'No donations yet.', 'pesa-donations' ); ?>
				</p>
			<?php endif; ?>

		</div>

		<style>
			.pd-editor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
			@media (max-width: 960px) { .pd-editor-grid { grid-template-columns: 1fr; } }
			.pd-editor-col .postbox { margin-bottom: 16px; }
			.pd-editor-col .hndle { padding: 12px 16px; margin: 0; font-size: 14px; border-bottom: 1px solid #e0e0e0; }
			.pd-editor-col .inside { padding: 6px 16px; }
			.pd-donor-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px !important; }
			.pd-stat { background: #fafafa; border-radius: 6px; padding: 14px 16px; display: flex; flex-direction: column; gap: 2px; }
			.pd-stat__value { font-size: 26px; font-weight: 800; color: #c62828; line-height: 1; }
			.pd-stat__value--small { font-size: 14px; color: #333; }
			.pd-stat__label { font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: .8px; margin-top: 4px; }
			.pd-status { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
			.pd-status--completed { background: #e8f5e9; color: #2e7d32; }
			.pd-status--pending   { background: #fff8e1; color: #b34700; }
			.pd-status--failed    { background: #ffebee; color: #c62828; }
			.pd-status--reversed  { background: #f3e5f5; color: #6a1b9a; }
			.pd-status--cancelled { background: #f5f5f5; color: #555; }
			.pd-status--test      { background: #fff; color: #3c434a; border: 1px solid #8c8f94; }
		</style>
		<?php
	}

	/** @return int|WP_Error The donor's id, or why it was refused (nothing is written then). */
	private function save( int $id ): int|WP_Error {
		global $wpdb;
		$table = $wpdb->prefix . 'pd_donors';

		$current = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT id, email FROM {$table} WHERE id = %d", $id ), ARRAY_A ) : null;
		if ( $id && ! $current ) {
			return new WP_Error( 'pd_missing', __( 'This donor no longer exists.', 'pesa-donations' ) );
		}

		$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
		$phone = mb_substr( Sanitizer::phone( wp_unslash( $_POST['phone'] ?? '' ) ), 0, 30 );
		if ( '' !== trim( (string) wp_unslash( $_POST['email'] ?? '' ) ) && ! is_email( $email ) ) {
			return new WP_Error( 'pd_email', __( 'That email address is not valid.', 'pesa-donations' ) );
		}
		if ( ! $email && ! $phone ) {
			return new WP_Error( 'pd_contact', __( 'Enter an email address or a phone number.', 'pesa-donations' ) );
		}
		// A donor without an email is keyed by phone, as the checkout keys them.
		$key = $email ?: strtolower( sanitize_email( $phone . '@phone.pd' ) );

		$other = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND id <> %d", $key, $id ) );
		if ( $other ) {
			return new WP_Error( 'pd_duplicate', sprintf(
				/* translators: %s: link to the other donor */
				__( 'Another donor already has this email or phone: %s.', 'pesa-donations' ),
				'<a href="' . esc_url( add_query_arg( [ 'page' => 'pd-donor-edit', 'id' => $other ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'open that donor', 'pesa-donations' ) . '</a>'
			) );
		}

		$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
		$data = [
			'email'      => $key,
			'first_name' => mb_substr( sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), 0, 100 ),
			'last_name'  => mb_substr( sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ), 0, 100 ),
			'phone'      => $phone,
			'country'    => Countries::is_valid( $country ) ? $country : '',
			'updated_at' => current_time( 'mysql' ),
		];

		if ( $id ) {
			if ( false === $wpdb->update( $table, $data, [ 'id' => $id ] ) ) {
				return new WP_Error( 'pd_db', __( 'The donor could not be saved. Please try again.', 'pesa-donations' ) );
			}
			return $id;
		}

		$data['created_at'] = current_time( 'mysql' );
		if ( ! $wpdb->insert( $table, $data ) ) {
			return new WP_Error( 'pd_db', __( 'The donor could not be saved. Please try again.', 'pesa-donations' ) );
		}
		return (int) $wpdb->insert_id;
	}
}
