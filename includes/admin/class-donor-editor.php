<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

class Donor_Editor {

	public function render(): void {
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$is_new = ! $id;

		// Handle save.
		if (
			isset( $_POST['pd_donor_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pd_donor_nonce'] ) ), 'pd_save_donor' )
		) {
			$saved_id = $this->save( $id );
			if ( $saved_id ) {
				$redirect = add_query_arg( [
					'page'   => 'pd-donor-edit',
					'id'     => $saved_id,
					'pd_msg' => $is_new ? 'created' : 'saved',
				], admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		global $wpdb;
		$donor = $id
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donors WHERE id = %d", $id ), ARRAY_A )
			: null;

		if ( $id && ! $donor ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Donor not found', 'pesa-donations' ) . '</h1></div>';
			return;
		}

		$data = $donor ?: $this->defaults();

		$donations = $id ? $this->get_donations_for_donor( $id ) : [];

		$this->render_form( $is_new, $data, $donations );
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
				"SELECT d.id, d.merchant_reference, d.amount, d.currency, d.status, d.gateway, d.created_at,
						p.post_title AS campaign_title
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

	private function render_form( bool $is_new, array $data, array $donations ): void {
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

			<?php if ( isset( $_GET['pd_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php
						echo 'created' === $_GET['pd_msg']
							? esc_html__( 'Donor created.', 'pesa-donations' )
							: esc_html__( 'Donor saved.', 'pesa-donations' );
					?></p>
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
										<th><label for="pd_email"><?php esc_html_e( 'Email', 'pesa-donations' ); ?> <span style="color:#c62828;">*</span></label></th>
										<td>
											<input type="email" name="email" id="pd_email" value="<?php echo esc_attr( $data['email'] ); ?>" class="regular-text" required />
											<p class="description"><?php esc_html_e( 'Used to recognize the donor on return visits.', 'pesa-donations' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label for="pd_phone"><?php esc_html_e( 'Phone', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="phone" id="pd_phone" value="<?php echo esc_attr( $data['phone'] ); ?>" class="regular-text" /></td>
									</tr>
									<tr>
										<th><label for="pd_country"><?php esc_html_e( 'Country', 'pesa-donations' ); ?></label></th>
										<td>
											<input type="text" name="country" id="pd_country" value="<?php echo esc_attr( $data['country'] ); ?>" maxlength="2" style="width:80px;" placeholder="UG" />
											<span class="description"><?php esc_html_e( '2-letter code (e.g. UG, KE, US)', 'pesa-donations' ); ?></span>
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
										<span class="pd-stat__value"><?php echo esc_html( number_format( (float) $data['total_donated_base'], 0 ) ); ?></span>
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
								<td><?php echo esc_html( $d['campaign_title'] ?: '—' ); ?></td>
								<td><strong><?php echo esc_html( number_format( (float) $d['amount'], 2 ) ); ?></strong> <?php echo esc_html( $d['currency'] ); ?></td>
								<td><?php echo esc_html( ucfirst( $d['gateway'] ) ); ?></td>
								<td><span class="pd-status pd-status--<?php echo esc_attr( $d['status'] ); ?>"><?php echo esc_html( ucfirst( $d['status'] ) ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( ! $is_new ) : ?>
				<p style="margin-top:24px;color:#888;font-style:italic;">
					<?php esc_html_e( 'This donor has no donation history yet.', 'pesa-donations' ); ?>
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
			.pd-stat__label { font-size: 11px; color: #777; text-transform: uppercase; letter-spacing: .8px; margin-top: 4px; }
			.pd-status { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
			.pd-status--completed { background: #e8f5e9; color: #2e7d32; }
			.pd-status--pending   { background: #fff8e1; color: #e65100; }
			.pd-status--failed    { background: #ffebee; color: #c62828; }
			.pd-status--reversed  { background: #f3e5f5; color: #6a1b9a; }
		</style>
		<?php
	}

	private function save( int $id ): ?int {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$email = strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
		if ( ! $email ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Email is required.', 'pesa-donations' ) . '</p></div>';
			} );
			return null;
		}

		$data = [
			'email'      => $email,
			'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
			'phone'      => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'country'    => strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) ),
			'updated_at' => current_time( 'mysql' ),
		];

		global $wpdb;
		$table = $wpdb->prefix . 'pd_donors';

		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
			return $id;
		}

		// Check for duplicate email first.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) );
		if ( $existing ) {
			return (int) $existing;
		}

		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id ?: null;
	}
}
