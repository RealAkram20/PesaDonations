<?php
declare( strict_types=1 );

namespace PesaDonations\Modules\Dashboard;

use PesaDonations\Models\Campaign;
use PesaDonations\Models\Open_Donation;

/**
 * CSV of every donation made in a date range, all statuses, for the
 * dashboard's Export button. admin-post.php?action=pd_export_donations.
 *
 * Streams row by row, so a year of donations never sits in memory.
 */
class Donations_Export {

	public const ACTION = 'pd_export_donations';

	public static function url( string $from, string $to ): string {
		return wp_nonce_url(
			add_query_arg( [ 'action' => self::ACTION, 'from' => $from, 'to' => $to ], admin_url( 'admin-post.php' ) ),
			self::ACTION
		);
	}

	public function handle(): void {
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			wp_die( esc_html__( 'You do not have permission to export donations.', 'pesa-donations' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );

		$from = $this->day( $_GET['from'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$to   = $this->day( $_GET['to'] ?? '' );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $from || ! $to || $to < $from ) {
			wp_die( esc_html__( 'Choose a valid date range to export.', 'pesa-donations' ), '', [ 'response' => 400 ] );
		}

		// A long export must finish: past max_execution_time the download used to
		// stop mid-file and still look like a complete CSV.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- disabled on some hosts.
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="donations-' . $from . '-to-' . $to . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM, so Excel reads UTF-8 names correctly.
		fputcsv( $out, [
			__( 'Date', 'pesa-donations' ),
			__( 'Reference', 'pesa-donations' ),
			__( 'Campaign', 'pesa-donations' ),
			__( 'Period', 'pesa-donations' ),
			__( 'Donor', 'pesa-donations' ),
			__( 'Email', 'pesa-donations' ),
			__( 'Phone', 'pesa-donations' ),
			__( 'Amount', 'pesa-donations' ),
			__( 'Currency', 'pesa-donations' ),
			__( 'Given as', 'pesa-donations' ),
			__( 'Counted toward campaign', 'pesa-donations' ),
			__( 'Counted currency', 'pesa-donations' ),
			__( 'Exchange rate', 'pesa-donations' ),
			__( 'Status', 'pesa-donations' ),
			__( 'Gateway', 'pesa-donations' ),
			__( 'Payment method', 'pesa-donations' ),
			__( 'Anonymous', 'pesa-donations' ),
			__( 'Test payment', 'pesa-donations' ),
			__( 'Organisation', 'pesa-donations' ),
			__( 'Wants updates', 'pesa-donations' ),
			__( 'Heard about us', 'pesa-donations' ),
			__( 'Address', 'pesa-donations' ),
		] );

		global $wpdb;
		$campaigns = [];
		// Keyset paging (after the last row sent), not OFFSET: each page is an
		// index seek, and a donation made while the export runs cannot shift a
		// page and duplicate or drop a row.
		$after_at = $from . ' 00:00:00';
		$after_id = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, created_at, merchant_reference, campaign_id, donor_name, donor_email, donor_phone, amount, currency, original_amount, original_currency, amount_base, base_currency, fx_rate,
				        status, gateway, payment_method, is_anonymous, environment, is_organization, wants_updates,
				        referral_source, donor_address
				 FROM {$wpdb->prefix}pd_donations
				 WHERE created_at <= %s AND ( created_at > %s OR ( created_at = %s AND id > %d ) )
				 ORDER BY created_at, id LIMIT 500",
				$to . ' 23:59:59',
				$after_at,
				$after_at,
				$after_id
			), ARRAY_A );
			if ( $rows ) {
				$last     = end( $rows );
				$after_at = (string) $last['created_at'];
				$after_id = (int) $last['id'];
			}

			foreach ( $rows as $r ) {
				$cid = (int) $r['campaign_id'];
				if ( ! array_key_exists( $cid, $campaigns ) ) {
					$campaigns[ $cid ] = $cid ? Campaign::get( $cid ) : null;
				}
				$campaign = $campaigns[ $cid ];
				$period   = $campaign ? $campaign->get_period_for_donation( (string) $r['created_at'] ) : null;
				$email    = str_ends_with( (string) $r['donor_email'], '@phone.pd' ) ? '' : (string) $r['donor_email'];

				fputcsv( $out, array_map( [ $this, 'cell' ], [
					$r['created_at'],
					$r['merchant_reference'],
					$cid ? ( $campaign ? html_entity_decode( $campaign->get_title(), ENT_QUOTES, 'UTF-8' ) : '#' . $cid ) : Open_Donation::label(),
					$period ? $period->get_label() : '',
					$r['donor_name'],
					$email,
					$r['donor_phone'],
					number_format( (float) $r['amount'], 2, '.', '' ),
					$r['currency'],
					null !== $r['original_amount'] ? number_format( (float) $r['original_amount'], 2, '.', '' ) . ' ' . $r['original_currency'] : '',
					number_format( (float) $r['amount_base'], 2, '.', '' ),
					(string) $r['base_currency'],
					(string) $r['base_currency'] !== (string) $r['currency'] ? rtrim( rtrim( number_format( (float) $r['fx_rate'], 10, '.', '' ), '0' ), '.' ) : '',
					$r['status'],
					$r['gateway'],
					$r['payment_method'],
					$r['is_anonymous'] ? __( 'Yes', 'pesa-donations' ) : '',
					'sandbox' === $r['environment'] ? __( 'Yes', 'pesa-donations' ) : '',
					$r['is_organization'] ? __( 'Yes', 'pesa-donations' ) : '',
					$r['wants_updates'] ? __( 'Yes', 'pesa-donations' ) : '',
					(string) $r['referral_source'],
					$this->address( (string) $r['donor_address'] ),
				] ) );
			}
		} while ( count( $rows ) === 500 );

		fclose( $out );
		exit;
	}

	/**
	 * A cell a spreadsheet will not execute: text starting with = + - @ (or a
	 * tab/CR) is a formula to Excel, and donor names are typed by the public.
	 */
	public function cell( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) && ! preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private function address( string $json ): string {
		$parts = json_decode( $json, true );
		return is_array( $parts ) ? implode( ', ', array_filter( array_map( 'strval', $parts ) ) ) : '';
	}

	private function day( $raw ): string {
		$raw = sanitize_text_field( wp_unslash( (string) $raw ) );
		$d   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );
		return ( $d && $d->format( 'Y-m-d' ) === $raw ) ? $raw : '';
	}
}
