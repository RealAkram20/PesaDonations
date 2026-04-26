<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\Models\Donation;
use PesaDonations\Models\Donor;

class Donation_Editor {

	public function render(): void {
		$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$donation = $id ? Donation::get( $id ) : null;
		$is_new   = ! $donation;

		// Handle save.
		if (
			isset( $_POST['pd_donation_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pd_donation_nonce'] ) ), 'pd_save_donation' )
		) {
			$saved_id = $this->save( $id );
			if ( $saved_id ) {
				$redirect = add_query_arg( [
					'page'   => 'pd-donation-edit',
					'id'     => $saved_id,
					'pd_msg' => $is_new ? 'created' : 'saved',
				], admin_url( 'admin.php' ) );
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// Re-fetch if just saved.
		if ( $id && ! $donation ) {
			$donation = Donation::get( $id );
		}

		$data = $donation ? $this->donation_to_array( $donation ) : $this->defaults();

		$this->render_form( $is_new, $data );
	}

	private function defaults(): array {
		return [
			'id'                => 0,
			'merchant_reference' => '',
			'campaign_id'       => 0,
			'amount'            => 0,
			'currency'          => get_option( 'pd_default_currency', 'UGX' ),
			'gateway'           => 'manual',
			'status'            => 'completed',
			'donor_name'        => '',
			'donor_email'       => '',
			'donor_phone'       => '',
			'donor_country'     => '',
			'message'           => '',
			'created_at'        => current_time( 'mysql' ),
		];
	}

	private function donation_to_array( Donation $d ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pd_donations WHERE id = %d", $d->get_id() ),
			ARRAY_A
		);
		return $row ?: $this->defaults();
	}

	private function render_form( bool $is_new, array $data ): void {
		$campaigns = get_posts( [
			'post_type'      => 'pd_campaign',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$title = $is_new
			? __( 'Add New Donation', 'pesa-donations' )
			: sprintf(
				/* translators: %s: reference code */
				__( 'Edit Donation — %s', 'pesa-donations' ),
				$data['merchant_reference'] ?: ( '#' . (int) $data['id'] )
			);

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pd-donations' ) ); ?>" class="page-title-action">
				&larr; <?php esc_html_e( 'Back to list', 'pesa-donations' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['pd_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Donation saved.', 'pesa-donations' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="" id="pd-donation-form">
				<?php wp_nonce_field( 'pd_save_donation', 'pd_donation_nonce' ); ?>

				<div class="pd-editor-grid">

					<div class="pd-editor-col">
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Donation Details', 'pesa-donations' ); ?></span></h2>
							<div class="inside">
								<table class="form-table"><tbody>

									<tr>
										<th><label for="pd_campaign_id"><?php esc_html_e( 'Campaign', 'pesa-donations' ); ?> <span style="color:#c62828;">*</span></label></th>
										<td>
											<select name="campaign_id" id="pd_campaign_id" class="regular-text" required>
												<option value=""><?php esc_html_e( '— Select campaign —', 'pesa-donations' ); ?></option>
												<?php foreach ( $campaigns as $c ) : ?>
													<option value="<?php echo (int) $c->ID; ?>" <?php selected( (int) $data['campaign_id'], (int) $c->ID ); ?>>
														<?php echo esc_html( $c->post_title ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>

									<tr>
										<th><label for="pd_amount"><?php esc_html_e( 'Amount', 'pesa-donations' ); ?> <span style="color:#c62828;">*</span></label></th>
										<td>
											<input type="number" step="0.01" name="amount" id="pd_amount" value="<?php echo esc_attr( (string) $data['amount'] ); ?>" class="regular-text" required />
											<select name="currency" style="margin-left:8px;">
												<?php foreach ( [ 'UGX', 'KES', 'TZS', 'USD', 'EUR', 'GBP' ] as $cur ) : ?>
													<option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $data['currency'], $cur ); ?>>
														<?php echo esc_html( $cur ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>

									<tr>
										<th><label for="pd_status"><?php esc_html_e( 'Status', 'pesa-donations' ); ?></label></th>
										<td>
											<select name="status" id="pd_status" class="regular-text">
												<?php foreach ( [ 'completed', 'pending', 'failed', 'reversed', 'cancelled' ] as $s ) : ?>
													<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $data['status'], $s ); ?>>
														<?php echo esc_html( ucfirst( $s ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>

									<tr>
										<th><label for="pd_gateway"><?php esc_html_e( 'Gateway', 'pesa-donations' ); ?></label></th>
										<td>
											<select name="gateway" id="pd_gateway" class="regular-text">
												<option value="manual"  <?php selected( $data['gateway'], 'manual' ); ?>><?php esc_html_e( 'Manual / Cash / Bank', 'pesa-donations' ); ?></option>
												<option value="pesapal" <?php selected( $data['gateway'], 'pesapal' ); ?>>PesaPal</option>
											</select>
										</td>
									</tr>

									<tr>
										<th><label for="pd_ref"><?php esc_html_e( 'Reference', 'pesa-donations' ); ?></label></th>
										<td>
											<input type="text" name="merchant_reference" id="pd_ref" value="<?php echo esc_attr( $data['merchant_reference'] ); ?>" class="regular-text" />
											<p class="description"><?php esc_html_e( 'Leave blank to auto-generate.', 'pesa-donations' ); ?></p>
										</td>
									</tr>

									<tr>
										<th><label for="pd_created_at"><?php esc_html_e( 'Date', 'pesa-donations' ); ?></label></th>
										<td>
											<input type="datetime-local" name="created_at" id="pd_created_at"
											       value="<?php echo esc_attr( $data['created_at'] ? gmdate( 'Y-m-d\TH:i', strtotime( $data['created_at'] ) ) : '' ); ?>"
											       class="regular-text" />
										</td>
									</tr>

									<tr>
										<th><label for="pd_message"><?php esc_html_e( 'Notes / Message', 'pesa-donations' ); ?></label></th>
										<td>
											<textarea name="message" id="pd_message" rows="3" class="large-text"><?php echo esc_textarea( $data['message'] ?? '' ); ?></textarea>
										</td>
									</tr>

								</tbody></table>
							</div>
						</div>
					</div>

					<div class="pd-editor-col">
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Donor Information', 'pesa-donations' ); ?></span></h2>
							<div class="inside">
								<table class="form-table"><tbody>

									<tr>
										<th><label><?php esc_html_e( 'Full Name', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="donor_name" value="<?php echo esc_attr( $data['donor_name'] ); ?>" class="regular-text" /></td>
									</tr>

									<tr>
										<th><label><?php esc_html_e( 'Email', 'pesa-donations' ); ?></label></th>
										<td><input type="email" name="donor_email" value="<?php echo esc_attr( $data['donor_email'] ); ?>" class="regular-text" /></td>
									</tr>

									<tr>
										<th><label><?php esc_html_e( 'Phone', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="donor_phone" value="<?php echo esc_attr( $data['donor_phone'] ); ?>" class="regular-text" /></td>
									</tr>

									<tr>
										<th><label><?php esc_html_e( 'Country', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="donor_country" value="<?php echo esc_attr( $data['donor_country'] ); ?>" maxlength="2" style="width:80px;" placeholder="UG" /></td>
									</tr>

								</tbody></table>
							</div>
						</div>

						<?php if ( ! $is_new ) : ?>
							<div class="postbox">
								<h2 class="hndle"><span><?php esc_html_e( 'System Info', 'pesa-donations' ); ?></span></h2>
								<div class="inside" style="padding:12px;">
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'UUID', 'pesa-donations' ); ?>:</strong> <code style="font-size:11px;"><?php echo esc_html( $data['uuid'] ?? '' ); ?></code></p>
									<?php if ( ! empty( $data['order_tracking_id'] ) ) : ?>
										<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Order Tracking ID', 'pesa-donations' ); ?>:</strong> <code style="font-size:11px;"><?php echo esc_html( $data['order_tracking_id'] ); ?></code></p>
									<?php endif; ?>
									<?php if ( ! empty( $data['confirmation_code'] ) ) : ?>
										<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Confirmation Code', 'pesa-donations' ); ?>:</strong> <code><?php echo esc_html( $data['confirmation_code'] ); ?></code></p>
									<?php endif; ?>
									<p style="margin:0;color:#888;font-size:12px;"><?php
										echo esc_html( sprintf(
											/* translators: %s: date */
											__( 'Last updated: %s', 'pesa-donations' ),
											isset( $data['updated_at'] ) ? mysql2date( 'M j, Y · g:i a', $data['updated_at'] ) : '—'
										) );
									?></p>
								</div>
							</div>
						<?php endif; ?>

					</div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large">
						<?php echo $is_new ? esc_html__( 'Create Donation', 'pesa-donations' ) : esc_html__( 'Update Donation', 'pesa-donations' ); ?>
					</button>
				</p>

			</form>
		</div>

		<style>
			.pd-editor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
			@media (max-width: 960px) { .pd-editor-grid { grid-template-columns: 1fr; } }
			.pd-editor-col .postbox { margin-bottom: 16px; }
			.pd-editor-col .hndle { padding: 12px 16px; margin: 0; font-size: 14px; border-bottom: 1px solid #e0e0e0; }
			.pd-editor-col .inside { padding: 6px 16px; }
		</style>
		<?php
	}

	private function save( int $id ): ?int {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		$amount      = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0.0;

		if ( ! $campaign_id || $amount <= 0 ) {
			return null;
		}

		$currency = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'UGX' ) ) );
		$email    = sanitize_email( wp_unslash( $_POST['donor_email'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['donor_phone'] ?? '' ) );

		$donor_id = null;
		if ( $email || $phone ) {
			$donor = Donor::get_or_create( $email ?: $phone . '@phone.pd', [
				'phone'   => $phone,
				'country' => sanitize_text_field( wp_unslash( $_POST['donor_country'] ?? '' ) ),
			] );
			$donor_id = $donor->get_id();
		}

		$status      = sanitize_key( wp_unslash( $_POST['status'] ?? 'pending' ) );
		$created_raw = sanitize_text_field( wp_unslash( $_POST['created_at'] ?? '' ) );
		// Reject anything strtotime() can't parse so a typo doesn't write
		// "abc:00" into the row, which on non-strict MySQL would become
		// 0000-00-00 and break list-table sort.
		$ts          = $created_raw ? strtotime( str_replace( 'T', ' ', $created_raw ) ) : false;
		$created     = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );

		$data = [
			'campaign_id'       => $campaign_id,
			'donor_id'          => $donor_id,
			'amount'            => $amount,
			'currency'          => $currency,
			'amount_base'       => $amount,
			'gateway'           => sanitize_key( wp_unslash( $_POST['gateway'] ?? 'manual' ) ),
			'status'            => $status,
			'donor_name'        => sanitize_text_field( wp_unslash( $_POST['donor_name'] ?? '' ) ),
			'donor_email'       => $email,
			'donor_phone'       => $phone,
			'donor_country'     => sanitize_text_field( wp_unslash( $_POST['donor_country'] ?? '' ) ),
			'message'           => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
			'created_at'        => $created,
		];

		if ( 'completed' === $status ) {
			$data['completed_at'] = current_time( 'mysql' );
		}

		$ref = sanitize_text_field( wp_unslash( $_POST['merchant_reference'] ?? '' ) );
		if ( $ref ) {
			$data['merchant_reference'] = $ref;
		}

		global $wpdb;

		if ( $id ) {
			$data['updated_at'] = current_time( 'mysql' );
			$wpdb->update( $wpdb->prefix . 'pd_donations', $data, [ 'id' => $id ] );
			$this->post_save( $campaign_id, $donor_id );
			return $id;
		}

		$data['uuid'] = wp_generate_uuid4();
		if ( empty( $data['merchant_reference'] ) ) {
			$data['merchant_reference'] = 'PD-MANUAL-' . strtoupper( wp_generate_password( 8, false ) );
		}
		$data['updated_at'] = current_time( 'mysql' );

		$wpdb->insert( $wpdb->prefix . 'pd_donations', $data );
		$new_id = (int) $wpdb->insert_id;

		$this->post_save( $campaign_id, $donor_id );

		return $new_id ?: null;
	}

	private function post_save( int $campaign_id, ?int $donor_id ): void {
		delete_transient( 'pd_raised_' . $campaign_id );
		delete_transient( 'pd_donors_' . $campaign_id );
		if ( $donor_id ) {
			\PesaDonations\Models\Donor::recalculate( $donor_id );
		}
	}
}
