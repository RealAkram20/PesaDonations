<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

/**
 * Hooks the plugin's donor data into WordPress's built-in personal-data
 * export and erasure tools. EU GDPR / UK DPA compliant subject-access and
 * right-to-be-forgotten requests can then be served from
 * Tools → Export Personal Data / Erase Personal Data.
 *
 * Eraser policy: donor identity fields are anonymized in place rather than
 * deleted, so financial accounting for completed donations stays intact
 * (amount, currency, campaign). The donor's `pd_donors` row is removed.
 */
class Privacy {

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers',   [ $this, 'register_eraser' ] );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['pesa-donations'] = [
			'exporter_friendly_name' => __( 'Donations', 'pesa-donations' ),
			'callback'               => [ $this, 'export' ],
		];
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['pesa-donations'] = [
			'eraser_friendly_name' => __( 'Donations', 'pesa-donations' ),
			'callback'             => [ $this, 'erase' ],
		];
		return $erasers;
	}

	public function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$page  = max( 1, $page );
		$per   = 50;
		$email = strtolower( sanitize_email( $email ) );

		$donor = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}pd_donors WHERE email = %s",
			$email
		), ARRAY_A );

		$exports = [];
		$done    = true;

		if ( $donor ) {
			// Donor profile entry: emit only on the first page so
			// multi-page exports don't duplicate it in the WP exporter ZIP.
			if ( 1 === $page ) {
			$exports[] = [
				'group_id'    => 'pd-donor',
				'group_label' => __( 'Donor profile', 'pesa-donations' ),
				'item_id'     => 'pd-donor-' . (int) $donor['id'],
				'data'        => [
					[ 'name' => __( 'First name', 'pesa-donations' ),     'value' => (string) $donor['first_name'] ],
					[ 'name' => __( 'Last name', 'pesa-donations' ),      'value' => (string) $donor['last_name'] ],
					[ 'name' => __( 'Email', 'pesa-donations' ),          'value' => (string) $donor['email'] ],
					[ 'name' => __( 'Phone', 'pesa-donations' ),          'value' => (string) $donor['phone'] ],
					[ 'name' => __( 'Country', 'pesa-donations' ),        'value' => (string) $donor['country'] ],
					[ 'name' => __( 'Donation count', 'pesa-donations' ), 'value' => (string) (int) $donor['donation_count'] ],
					[ 'name' => __( 'Total donated', 'pesa-donations' ),  'value' => (string) (float) $donor['total_donated_base'] ],
					[ 'name' => __( 'First donation', 'pesa-donations' ), 'value' => (string) $donor['first_donation_at'] ],
					[ 'name' => __( 'Last donation', 'pesa-donations' ),  'value' => (string) $donor['last_donation_at'] ],
				],
			];
			}

			$offset = ( $page - 1 ) * $per;
			$rows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, merchant_reference, campaign_id, amount, currency, status, gateway, created_at, donor_ip, message
				 FROM {$wpdb->prefix}pd_donations
				 WHERE donor_id = %d OR donor_email = %s
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				(int) $donor['id'], $email, $per, $offset
			), ARRAY_A );

			foreach ( $rows as $row ) {
				$exports[] = [
					'group_id'    => 'pd-donations',
					'group_label' => __( 'Donations', 'pesa-donations' ),
					'item_id'     => 'pd-donation-' . (int) $row['id'],
					'data'        => [
						[ 'name' => __( 'Reference', 'pesa-donations' ), 'value' => (string) $row['merchant_reference'] ],
						[ 'name' => __( 'Date', 'pesa-donations' ),      'value' => (string) $row['created_at'] ],
						[ 'name' => __( 'Amount', 'pesa-donations' ),    'value' => $row['amount'] . ' ' . $row['currency'] ],
						[ 'name' => __( 'Status', 'pesa-donations' ),    'value' => (string) $row['status'] ],
						[ 'name' => __( 'Gateway', 'pesa-donations' ),   'value' => (string) $row['gateway'] ],
						[ 'name' => __( 'IP address', 'pesa-donations' ),'value' => (string) $row['donor_ip'] ],
						[ 'name' => __( 'Message', 'pesa-donations' ),   'value' => (string) $row['message'] ],
					],
				];
			}

			$done = count( $rows ) < $per;
		}

		return [ 'data' => $exports, 'done' => $done ];
	}

	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		$page  = max( 1, $page );
		$batch = 500;  // donations to anonymize per pass

		$messages = [];
		$removed  = false;
		$retained = false;
		$done     = true;

		$donor_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}pd_donors WHERE email = %s",
			$email
		) );

		if ( ! $donor_id ) {
			return [
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			];
		}

		// Anonymize donations in batches so we don't lock the donations
		// table for minutes on a donor with thousands of records. Each
		// pass clears `donor_id` so the same rows aren't re-found on the
		// next call — the WP eraser will keep invoking us until done.
		$donations = $wpdb->prefix . 'pd_donations';
		$ids       = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$donations} WHERE donor_id = %d ORDER BY id LIMIT %d",
			$donor_id,
			$batch
		) );

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$donations} SET
					donor_id = NULL,
					donor_name = '',
					donor_organization = '',
					donor_email = '',
					donor_phone = '',
					donor_country = '',
					donor_ip = '',
					message = '',
					is_anonymous = 1,
					updated_at = %s
				WHERE id IN ({$placeholders})",
				array_merge( [ current_time( 'mysql' ) ], $ids )
			) );

			$removed  = true;
			$retained = true;

			// More to do if we filled the batch.
			$done = count( $ids ) < $batch;
		}

		// Only delete the donor profile row on the FINAL pass so the
		// SELECT above can keep finding the remaining donations.
		if ( $done ) {
			$wpdb->delete( $wpdb->prefix . 'pd_donors', [ 'id' => $donor_id ] );
			$removed    = true;
			$retained   = true;
			$messages[] = __( 'Donor profile removed; completed donations anonymized for accounting.', 'pesa-donations' );
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		];
	}
}
