<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\Models\Campaign_Totals;
use PesaDonations\Models\Donation;
use PesaDonations\Models\Donor;
use PesaDonations\Models\Open_Donation;
use PesaDonations\Models\Campaign;
use PesaDonations\Payments\Charge_Quote;
use PesaDonations\Utils\Countries;
use PesaDonations\Utils\Currencies;
use PesaDonations\Utils\Sanitizer;
use WP_Error;

class Donation_Editor {


	/** A refused save: the message, and the values as typed, shown again by render(). */
	private static string $error  = '';
	private static array $posted = [];

	/**
	 * Runs on load-{page}, before WordPress prints anything, so the redirect
	 * after a save works. (It ran inside the page before: the donation was
	 * written, the redirect failed with "headers already sent", and a reload
	 * wrote it again.)
	 */
	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['pd_donation_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'pd_save_donation', 'pd_donation_nonce' );
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
			'page'   => 'pd-donation-edit',
			'id'     => $result,
			'pd_msg' => $id ? 'saved' : 'created',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$donation = $id ? Donation::get( $id ) : null;

		if ( $id && ! $donation ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Donation not found', 'pesa-donations' ) . '</h1></div>';
			return;
		}

		$data = $donation ? $this->donation_to_array( $donation ) : $this->defaults();
		if ( self::$posted ) {
			// Show what was typed, not what is stored, after a refused save.
			foreach ( [ 'campaign_id', 'amount', 'currency', 'gateway', 'merchant_reference', 'donor_name', 'donor_email', 'donor_phone', 'donor_country', 'message' ] as $k ) {
				if ( isset( self::$posted[ $k ] ) && is_string( self::$posted[ $k ] ) ) {
					$data[ $k ] = self::$posted[ $k ];
				}
			}
			$data['requested_status'] = (string) ( self::$posted['status'] ?? '' );
		}

		$this->render_form( ! $donation, $data );
	}

	private function defaults(): array {
		return [
			'id'                 => 0,
			'merchant_reference' => '',
			'campaign_id'        => '', // Nothing chosen yet; 0 means an open donation.
			'amount'             => '',
			'currency'           => get_option( 'pd_default_currency', 'UGX' ),
			'gateway'            => 'manual',
			'status'             => 'completed', // Most gifts entered by hand are cash already received.
			'donor_name'         => '',
			'donor_email'        => '',
			'donor_phone'        => '',
			'donor_country'      => '',
			'message'            => '',
			'created_at'         => current_time( 'mysql' ),
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

	/** A payment the gateway confirmed: its amount, currency and reference are the gateway's record. */
	private static function is_gateway_record( array $data ): bool {
		return ! empty( $data['id'] ) && 'manual' !== ( $data['gateway'] ?? 'manual' ) && ! empty( $data['order_tracking_id'] );
	}

	/** The status the donation has now, and the ones it may move to. */
	private static function status_choices( array $data, bool $is_new ): array {
		if ( $is_new ) {
			return [ 'pending', 'completed', 'failed', 'cancelled' ];
		}
		$current = (string) $data['status'];
		return array_merge( [ $current ], Donation::MOVES[ $current ] ?? [] );
	}

	private function render_form( bool $is_new, array $data ): void {
		// Any status: a donation to an ended (draft or private) campaign stays editable.
		$campaigns = get_posts( [
			'post_type'      => 'pd_campaign',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );
		$current_cid = (int) $data['campaign_id'];
		if ( $current_cid && ! in_array( $current_cid, wp_list_pluck( $campaigns, 'ID' ), true ) ) {
			$gone = get_post( $current_cid );
			$campaigns[] = (object) [
				'ID'         => $current_cid,
				/* translators: %d: campaign ID */
				'post_title' => $gone ? $gone->post_title . ' (' . $gone->post_status . ')' : sprintf( __( 'Deleted campaign #%d', 'pesa-donations' ), $current_cid ),
			];
		}

		$locked   = self::is_gateway_record( $data );
		$statuses = self::status_choices( $data, $is_new );
		$selected = $data['requested_status'] ?? (string) $data['status'];

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

			<?php if ( self::$error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( self::$error ); ?></p></div>
			<?php elseif ( isset( $_GET['pd_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php 'created' === $_GET['pd_msg'] ? esc_html_e( 'Donation created.', 'pesa-donations' ) : esc_html_e( 'Donation saved.', 'pesa-donations' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
				</div>
			<?php endif; ?>

			<?php if ( 'sandbox' === ( $data['environment'] ?? '' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Test payment: made in the PesaPal sandbox. It is not real money and is not counted once the site is live.', 'pesa-donations' ); ?></p></div>
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
												<option value="0" <?php selected( (string) $data['campaign_id'], '0' ); ?>>
													<?php echo esc_html( Open_Donation::label() . ' ' . __( '(no campaign)', 'pesa-donations' ) ); ?>
												</option>
												<?php foreach ( $campaigns as $c ) : ?>
													<option value="<?php echo (int) $c->ID; ?>" <?php selected( (string) $data['campaign_id'], (string) $c->ID ); ?>>
														<?php echo esc_html( $c->post_title . ( isset( $c->post_status ) && 'publish' !== $c->post_status ? ' (' . $c->post_status . ')' : '' ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>

									<tr>
										<th><label for="pd_amount"><?php esc_html_e( 'Amount', 'pesa-donations' ); ?> <span style="color:#c62828;">*</span></label></th>
										<td>
											<?php if ( $locked ) : ?>
												<strong><?php echo esc_html( \PesaDonations\Utils\Money::format( (float) $data['amount'], (string) $data['currency'] ) ); ?></strong>
												<p class="description"><?php esc_html_e( 'As confirmed by the payment gateway.', 'pesa-donations' ); ?></p>
											<?php else : ?>
												<input type="text" inputmode="decimal" name="amount" id="pd_amount" value="<?php echo esc_attr( (string) $data['amount'] ); ?>" class="regular-text" required />
												<select name="currency" aria-label="<?php esc_attr_e( 'Currency', 'pesa-donations' ); ?>" style="margin-left:8px;">
													<?php foreach ( array_unique( array_merge( Currencies::codes(), [ (string) $data['currency'] ] ) ) as $cur ) : ?>
														<option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $data['currency'], $cur ); ?>>
															<?php echo esc_html( $cur ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th><label for="pd_status"><?php esc_html_e( 'Status', 'pesa-donations' ); ?></label></th>
										<td>
											<select name="status" id="pd_status" class="regular-text">
												<?php foreach ( $statuses as $s ) : ?>
													<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $selected, $s ); ?>>
														<?php echo esc_html( ucfirst( $s ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p>
												<label>
													<input type="checkbox" name="notify" value="1" <?php checked( ! empty( self::$posted['notify'] ) ); ?> />
													<?php esc_html_e( 'Email the donor about a status change', 'pesa-donations' ); ?>
												</label>
											</p>
											<?php if ( ! $is_new && 1 === count( $statuses ) ) : ?>
												<p class="description"><?php esc_html_e( 'A reversed donation is final.', 'pesa-donations' ); ?></p>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th><label for="pd_gateway"><?php esc_html_e( 'Gateway', 'pesa-donations' ); ?></label></th>
										<td>
											<?php if ( $locked ) : ?>
												<?php echo esc_html( 'pesapal' === $data['gateway'] ? 'PesaPal' : ucfirst( (string) $data['gateway'] ) ); ?>
												<?php if ( ! empty( $data['payment_method'] ) ) : ?>
													· <?php echo esc_html( $data['payment_method'] ); ?>
												<?php endif; ?>
											<?php else : ?>
												<select name="gateway" id="pd_gateway" class="regular-text">
													<option value="manual"  <?php selected( $data['gateway'], 'manual' ); ?>><?php esc_html_e( 'Manual / Cash / Bank', 'pesa-donations' ); ?></option>
													<option value="pesapal" <?php selected( $data['gateway'], 'pesapal' ); ?>>PesaPal</option>
													<option value="paypal"  <?php selected( $data['gateway'], 'paypal' ); ?>>PayPal</option>
												</select>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th><label for="pd_ref"><?php esc_html_e( 'Reference', 'pesa-donations' ); ?></label></th>
										<td>
											<?php if ( $locked ) : ?>
												<code><?php echo esc_html( (string) $data['merchant_reference'] ); ?></code>
											<?php else : ?>
												<input type="text" name="merchant_reference" id="pd_ref" value="<?php echo esc_attr( (string) $data['merchant_reference'] ); ?>" class="regular-text" maxlength="100" />
												<p class="description"><?php esc_html_e( 'Leave blank to auto-generate.', 'pesa-donations' ); ?></p>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th><label for="pd_created_at"><?php esc_html_e( 'Date', 'pesa-donations' ); ?></label></th>
										<td>
											<input type="datetime-local" name="created_at" id="pd_created_at"
											       value="<?php echo esc_attr( $data['created_at'] ? mysql2date( 'Y-m-d\TH:i', (string) $data['created_at'], false ) : '' ); ?>"
											       class="regular-text" />
										</td>
									</tr>

									<tr>
										<th><label for="pd_message"><?php esc_html_e( 'Notes / Message', 'pesa-donations' ); ?></label></th>
										<td>
											<textarea name="message" id="pd_message" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $data['message'] ?? '' ) ); ?></textarea>
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
										<th><label for="pd_donor_name"><?php esc_html_e( 'Full Name', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="donor_name" id="pd_donor_name" value="<?php echo esc_attr( (string) $data['donor_name'] ); ?>" class="regular-text" /></td>
									</tr>

									<tr>
										<th><label for="pd_donor_email"><?php esc_html_e( 'Email', 'pesa-donations' ); ?></label></th>
										<td><input type="email" name="donor_email" id="pd_donor_email" value="<?php echo esc_attr( Donor::is_placeholder_email( (string) $data['donor_email'] ) ? '' : (string) $data['donor_email'] ); ?>" class="regular-text" /></td>
									</tr>

									<tr>
										<th><label for="pd_donor_phone"><?php esc_html_e( 'Phone', 'pesa-donations' ); ?></label></th>
										<td><input type="text" name="donor_phone" id="pd_donor_phone" value="<?php echo esc_attr( (string) $data['donor_phone'] ); ?>" class="regular-text" /></td>
									</tr>

									<tr>
										<th><label for="pd_donor_country"><?php esc_html_e( 'Country', 'pesa-donations' ); ?></label></th>
										<td>
											<select name="donor_country" id="pd_donor_country">
												<option value=""><?php esc_html_e( '—', 'pesa-donations' ); ?></option>
												<?php foreach ( Countries::all() as $code => $name ) : ?>
													<option value="<?php echo esc_attr( $code ); ?>" <?php selected( strtoupper( (string) $data['donor_country'] ), $code ); ?>><?php echo esc_html( $name ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>

								</tbody></table>
								<?php $this->render_checkout_answers( $data ); ?>
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
									<?php if ( ! empty( $data['completed_at'] ) ) : ?>
										<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Completed', 'pesa-donations' ); ?>:</strong> <?php echo esc_html( mysql2date( 'M j, Y · g:i a', (string) $data['completed_at'] ) ); ?></p>
									<?php endif; ?>
									<p style="margin:0;color:#646970;font-size:12px;"><?php
										echo esc_html( sprintf(
											/* translators: %s: date */
											__( 'Last updated: %s', 'pesa-donations' ),
											! empty( $data['updated_at'] ) ? mysql2date( 'M j, Y · g:i a', (string) $data['updated_at'] ) : '—'
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
			.pd-answers { margin: 4px 0 12px; }
			.pd-answers dt { font-weight: 600; margin-top: 8px; }
			.pd-answers dd { margin: 2px 0 0; }
		</style>
		<?php
	}

	/** What the donor told the checkout beyond name and contact (stored since 1.2.0). */
	private function render_checkout_answers( array $data ): void {
		$address = [];
		if ( ! empty( $data['donor_address'] ) ) {
			$decoded = json_decode( (string) $data['donor_address'], true );
			$address = is_array( $decoded ) ? array_filter( array_map( 'strval', $decoded ) ) : [];
		}
		$rows = array_filter( [
			__( 'Address', 'pesa-donations' )             => implode( ', ', $address ),
			__( 'Heard about us', 'pesa-donations' )      => (string) ( $data['referral_source'] ?? '' ),
			__( 'Organisation or group', 'pesa-donations' ) => ! empty( $data['is_organization'] ) ? __( 'Yes', 'pesa-donations' ) : '',
			__( 'Wants updates', 'pesa-donations' )       => ! empty( $data['wants_updates'] ) ? __( 'Yes', 'pesa-donations' ) : '',
			__( 'Anonymous', 'pesa-donations' )           => ! empty( $data['is_anonymous'] ) ? __( 'Yes', 'pesa-donations' ) : '',
		] );
		if ( ! $rows ) {
			return;
		}
		echo '<dl class="pd-answers">';
		foreach ( $rows as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
	}

	/** @return int|WP_Error The donation's id, or why it was refused (nothing is written then). */
	private function save( int $id ): int|WP_Error {
		$existing = $id ? Donation::get( $id ) : null;
		if ( $id && ! $existing ) {
			return new WP_Error( 'pd_missing', __( 'This donation no longer exists.', 'pesa-donations' ) );
		}
		$current = $existing ? $this->donation_to_array( $existing ) : [];
		$locked  = $existing && self::is_gateway_record( $current );

		// '' = nothing chosen (refused); '0' = an open donation with no campaign.
		$campaign_raw = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';
		$campaign_id  = (int) $campaign_raw;
		if ( '' === $campaign_raw || $campaign_id < 0 || ( $campaign_id && 'pd_campaign' !== get_post_type( $campaign_id ) ) ) {
			return new WP_Error( 'pd_campaign', __( 'Choose the campaign this donation is for.', 'pesa-donations' ) );
		}

		if ( $locked ) {
			$currency = (string) $current['currency'];
			$amount   = (float) $current['amount'];
		} else {
			$currency = Sanitizer::currency( wp_unslash( $_POST['currency'] ?? '' ) );
			if ( ! in_array( $currency, array_merge( Currencies::codes(), [ (string) ( $current['currency'] ?? '' ) ] ), true ) ) {
				return new WP_Error( 'pd_currency', __( 'Choose a currency.', 'pesa-donations' ) );
			}
			$amount = Sanitizer::amount( wp_unslash( $_POST['amount'] ?? '' ), $currency );
			if ( null === $amount ) {
				return new WP_Error( 'pd_amount', __( 'Enter the amount as a number, for example 50000.', 'pesa-donations' ) );
			}
		}

		$status   = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		$allowed  = self::status_choices( $current, ! $existing );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'pd_status', sprintf(
				/* translators: 1: current status, 2: requested status */
				__( 'A %1$s donation cannot be marked %2$s.', 'pesa-donations' ),
				$existing ? $existing->get_status() : 'new',
				$status
			) );
		}

		$created = sanitize_text_field( wp_unslash( $_POST['created_at'] ?? '' ) );
		if ( '' === $created ) {
			$created = $existing ? $existing->get_created_at() : current_time( 'mysql' );
		} else {
			$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $created );
			if ( ! $parsed ) {
				return new WP_Error( 'pd_date', __( 'Enter the date as day, month, year and time.', 'pesa-donations' ) );
			}
			$created = $parsed->format( 'Y-m-d H:i:s' );
		}

		$email   = sanitize_email( wp_unslash( $_POST['donor_email'] ?? '' ) );
		$phone   = mb_substr( Sanitizer::phone( wp_unslash( $_POST['donor_phone'] ?? '' ) ), 0, 30 );
		$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['donor_country'] ?? '' ) ) );
		$country = Countries::is_valid( $country ) ? $country : '';
		$name    = mb_substr( sanitize_text_field( wp_unslash( $_POST['donor_name'] ?? '' ) ), 0, 200 );

		$donor_id = null;
		if ( $email || $phone ) {
			$parts = preg_split( '/\s+/', $name, 2 );
			$donor = Donor::get_or_create( $email ?: $phone . '@phone.pd', [
				'first_name' => $parts[0] ?? '',
				'last_name'  => $parts[1] ?? '',
				'phone'      => $phone,
				'country'    => $country,
			] );
			$donor_id = $donor->get_id() ?: null;
		}

		$fields = [
			'campaign_id'   => $campaign_id,
			'donor_id'      => $donor_id,
			'donor_name'    => $name,
			'donor_email'   => $email,
			'donor_phone'   => $phone,
			'donor_country' => $country,
			'message'       => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
			'created_at'    => $created,
		];
		// What the gift counts for, in the campaign's currency. An edit that keeps
		// the amount's basis keeps the rate stored with it; history never moves
		// with today's rate. A new or re-based gift uses today's (else counts in
		// its own currency, as before 1.3).
		$base_currency = $campaign_id && Campaign::get( $campaign_id ) ? Campaign::get( $campaign_id )->get_base_currency() : Open_Donation::currency();
		$same_basis    = $existing
			&& (string) $current['currency'] === $currency
			&& (string) ( $current['base_currency'] ?? $current['currency'] ) === $base_currency;
		if ( $same_basis ) {
			$fields['amount_base']   = round( $amount * (float) $current['fx_rate'], 2 );
			$fields['base_currency'] = $base_currency;
		} else {
			$fields += Charge_Quote::counted( $amount, $currency, $base_currency );
		}

		if ( ! $locked ) {
			$fields['amount']      = $amount;
			$fields['currency']    = $currency;
			$gateway               = sanitize_key( wp_unslash( $_POST['gateway'] ?? 'manual' ) );
			$fields['gateway']     = in_array( $gateway, [ 'manual', 'pesapal', 'paypal' ], true ) ? $gateway : 'manual';
			$ref = mb_substr( sanitize_text_field( wp_unslash( $_POST['merchant_reference'] ?? '' ) ), 0, 100 );
			if ( '' !== $ref ) {
				$fields['merchant_reference'] = $ref;
			}
		}

		$context = [ 'source' => 'admin', 'notify' => ! empty( $_POST['notify'] ) ];

		if ( $existing ) {
			$old_donor = $existing->get_donor_id();
			$existing->update( $fields );
			if ( $status !== $existing->get_status() ) {
				$existing->transition( $status, [], $context );
			}
			// Amount, campaign, date or donor may have changed without a status move.
			foreach ( array_unique( array_filter( [ $old_donor, (int) $donor_id ] ) ) as $did ) {
				Donor::recalculate( (int) $did );
			}
			Campaign_Totals::flush();
			return $id;
		}

		$fields['merchant_reference'] = $fields['merchant_reference'] ?? 'PD-MANUAL-' . strtoupper( wp_generate_password( 8, false ) );
		$new_id = Donation::create( $fields + [ 'status' => 'pending' ] );
		if ( ! $new_id ) {
			return new WP_Error( 'pd_db', __( 'The donation could not be saved. Please try again.', 'pesa-donations' ) );
		}
		$donation = Donation::get( (int) $new_id );
		if ( $donation && 'pending' !== $status ) {
			$donation->transition( $status, [], $context );
		}
		if ( $donor_id ) {
			Donor::recalculate( (int) $donor_id );
		}
		Campaign_Totals::flush();
		return (int) $new_id;
	}
}
