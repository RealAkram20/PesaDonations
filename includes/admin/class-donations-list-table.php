<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Donations_List_Table extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct() {
		parent::__construct( [
			'singular' => 'donation',
			'plural'   => 'donations',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'cb'          => '<input type="checkbox" />',
			'reference'   => __( 'Reference', 'pesa-donations' ),
			'donor'       => __( 'Donor', 'pesa-donations' ),
			'campaign'    => __( 'Campaign', 'pesa-donations' ),
			'amount'      => __( 'Amount', 'pesa-donations' ),
			'gateway'     => __( 'Gateway', 'pesa-donations' ),
			'status'      => __( 'Status', 'pesa-donations' ),
			'created_at'  => __( 'Date', 'pesa-donations' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'created_at' => [ 'created_at', true ],
			'amount'     => [ 'amount', false ],
			'status'     => [ 'status', false ],
		];
	}

	protected function get_bulk_actions(): array {
		return [
			'mark_completed' => __( 'Mark as Completed', 'pesa-donations' ),
			'mark_failed'    => __( 'Mark as Failed', 'pesa-donations' ),
			'mark_pending'   => __( 'Mark as Pending', 'pesa-donations' ),
			'delete'         => __( 'Delete', 'pesa-donations' ),
		];
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="donation[]" value="%d" />', (int) $item['id'] );
	}

	protected function column_reference( $item ): string {
		$edit_url = add_query_arg( [
			'page' => 'pd-donation-edit',
			'id'   => (int) $item['id'],
		], admin_url( 'admin.php' ) );

		$delete_url = wp_nonce_url(
			add_query_arg( [
				'page'   => 'pd-donations',
				'action' => 'delete',
				'id'     => (int) $item['id'],
			], admin_url( 'admin.php' ) ),
			'pd_delete_' . $item['id']
		);

		$actions = [
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'pesa-donations' ) ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\')" style="color:#c62828;">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this donation permanently?', 'pesa-donations' ) ),
				esc_html__( 'Delete', 'pesa-donations' )
			),
		];

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['merchant_reference'] ?: '#' . $item['id'] ),
			$this->row_actions( $actions )
		);
	}

	protected function column_donor( $item ): string {
		$name  = $item['donor_name'] ?: '—';
		$email = $item['donor_email'];
		$phone = $item['donor_phone'];

		$out = '<strong>' . esc_html( $name ) . '</strong>';
		if ( $email ) {
			$out .= '<br><a href="mailto:' . esc_attr( $email ) . '" style="color:#555;font-size:12px;">' . esc_html( $email ) . '</a>';
		}
		if ( $phone ) {
			$out .= '<br><span style="color:#888;font-size:12px;">' . esc_html( $phone ) . '</span>';
		}
		return $out;
	}

	protected function column_campaign( $item ): string {
		if ( ! $item['campaign_title'] ) {
			return '—';
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_post_link( (int) $item['campaign_id'] ) ),
			esc_html( $item['campaign_title'] )
		);
	}

	protected function column_amount( $item ): string {
		return sprintf(
			'<strong>%s</strong> %s',
			number_format( (float) $item['amount'], 2 ),
			esc_html( $item['currency'] )
		);
	}

	protected function column_gateway( $item ): string {
		$labels = [ 'pesapal' => 'PesaPal', 'paypal' => 'PayPal', 'manual' => __( 'Manual', 'pesa-donations' ) ];
		$g      = $item['gateway'] ?: 'manual';
		return esc_html( $labels[ $g ] ?? ucfirst( $g ) );
	}

	protected function column_status( $item ): string {
		$colors = [
			'completed' => [ '#e8f5e9', '#2e7d32' ],
			'pending'   => [ '#fff8e1', '#e65100' ],
			'failed'    => [ '#ffebee', '#c62828' ],
			'reversed'  => [ '#f3e5f5', '#6a1b9a' ],
			'cancelled' => [ '#f5f5f5', '#555' ],
		];
		$status = $item['status'] ?: 'pending';
		$c      = $colors[ $status ] ?? [ '#f5f5f5', '#555' ];

		return sprintf(
			'<span style="background:%s;color:%s;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;">%s</span>',
			esc_attr( $c[0] ),
			esc_attr( $c[1] ),
			esc_html( ucfirst( $status ) )
		);
	}

	protected function column_created_at( $item ): string {
		return esc_html( mysql2date( 'M j, Y · g:i a', $item['created_at'] ) );
	}

	/**
	 * Top tablenav: status filter, date range, campaign filter.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		global $wpdb;

		// Campaign filter options. Capped at 500 to keep the page render
		// snappy on sites with thousands of campaigns; the search box still
		// finds the rest.
		$campaigns = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_type = 'pd_campaign' AND post_status = 'publish'
			 ORDER BY post_title
			 LIMIT 500",
			ARRAY_A
		);

		$selected_status   = isset( $_GET['pd_status'] ) ? sanitize_key( wp_unslash( $_GET['pd_status'] ) ) : '';
		$selected_campaign = isset( $_GET['pd_campaign_filter'] ) ? (int) $_GET['pd_campaign_filter'] : 0;
		$selected_gateway  = isset( $_GET['pd_gateway'] ) ? sanitize_key( wp_unslash( $_GET['pd_gateway'] ) ) : '';
		$date_from         = isset( $_GET['pd_from'] ) ? sanitize_text_field( wp_unslash( $_GET['pd_from'] ) ) : '';
		$date_to           = isset( $_GET['pd_to'] ) ? sanitize_text_field( wp_unslash( $_GET['pd_to'] ) ) : '';

		echo '<div class="alignleft actions">';

		// Status
		echo '<select name="pd_status"><option value="">' . esc_html__( 'All statuses', 'pesa-donations' ) . '</option>';
		foreach ( [ 'completed', 'pending', 'failed', 'reversed', 'cancelled' ] as $s ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $s ),
				selected( $selected_status, $s, false ),
				esc_html( ucfirst( $s ) )
			);
		}
		echo '</select>';

		// Campaign
		echo '<select name="pd_campaign_filter"><option value="0">' . esc_html__( 'All campaigns', 'pesa-donations' ) . '</option>';
		foreach ( $campaigns as $c ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $c['ID'],
				selected( $selected_campaign, (int) $c['ID'], false ),
				esc_html( $c['post_title'] )
			);
		}
		echo '</select>';

		// Gateway
		echo '<select name="pd_gateway"><option value="">' . esc_html__( 'All gateways', 'pesa-donations' ) . '</option>';
		foreach ( [ 'pesapal' => 'PesaPal', 'paypal' => 'PayPal', 'manual' => __( 'Manual', 'pesa-donations' ) ] as $v => $l ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $v ),
				selected( $selected_gateway, $v, false ),
				esc_html( $l )
			);
		}
		echo '</select>';

		// Date range
		echo '<input type="date" name="pd_from" value="' . esc_attr( $date_from ) . '" placeholder="' . esc_attr__( 'From', 'pesa-donations' ) . '" />';
		echo '<input type="date" name="pd_to" value="' . esc_attr( $date_to ) . '" placeholder="' . esc_attr__( 'To', 'pesa-donations' ) . '" />';

		submit_button( __( 'Filter', 'pesa-donations' ), 'secondary', 'pd_filter', false );

		if ( $selected_status || $selected_campaign || $selected_gateway || $date_from || $date_to ) {
			printf(
				' <a href="%s" class="button-link" style="margin-left:6px;">%s</a>',
				esc_url( admin_url( 'admin.php?page=pd-donations' ) ),
				esc_html__( 'Reset', 'pesa-donations' )
			);
		}

		echo '</div>';
	}

	/**
	 * Quick status tabs (All / Completed / Pending / Failed).
	 */
	protected function get_views(): array {
		global $wpdb;

		$base     = admin_url( 'admin.php?page=pd-donations' );
		$current  = isset( $_GET['pd_status'] ) ? sanitize_key( wp_unslash( $_GET['pd_status'] ) ) : '';

		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) as c FROM {$wpdb->prefix}pd_donations GROUP BY status",
			OBJECT_K
		);
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pd_donations" );
		$get = fn( string $k ) => isset( $counts[ $k ] ) ? (int) $counts[ $k ]->c : 0;

		$views = [
			'all'       => [ __( 'All', 'pesa-donations' ),       $total,            '' ],
			'completed' => [ __( 'Completed', 'pesa-donations' ), $get( 'completed' ), 'completed' ],
			'pending'   => [ __( 'Pending', 'pesa-donations' ),   $get( 'pending' ),   'pending' ],
			'failed'    => [ __( 'Failed', 'pesa-donations' ),    $get( 'failed' ),    'failed' ],
			'reversed'  => [ __( 'Reversed', 'pesa-donations' ),  $get( 'reversed' ),  'reversed' ],
		];

		$out = [];
		foreach ( $views as $k => [ $label, $count, $s ] ) {
			$url = $s ? add_query_arg( 'pd_status', $s, $base ) : $base;
			$class = ( $current === $s ) ? 'current' : '';
			$out[ $k ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
				(int) $count
			);
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		global $wpdb;
		$table = $wpdb->prefix . 'pd_donations';

		$where = [ '1=1' ];
		$args  = [];

		if ( ! empty( $_GET['pd_status'] ) ) {
			$where[]  = 'd.status = %s';
			$args[]   = sanitize_key( wp_unslash( $_GET['pd_status'] ) );
		}
		if ( ! empty( $_GET['pd_campaign_filter'] ) ) {
			$where[] = 'd.campaign_id = %d';
			$args[]  = (int) $_GET['pd_campaign_filter'];
		}
		if ( ! empty( $_GET['pd_gateway'] ) ) {
			$where[] = 'd.gateway = %s';
			$args[]  = sanitize_key( wp_unslash( $_GET['pd_gateway'] ) );
		}
		if ( ! empty( $_GET['pd_from'] ) ) {
			$where[] = 'd.created_at >= %s';
			$args[]  = sanitize_text_field( wp_unslash( $_GET['pd_from'] ) ) . ' 00:00:00';
		}
		if ( ! empty( $_GET['pd_to'] ) ) {
			$where[] = 'd.created_at <= %s';
			$args[]  = sanitize_text_field( wp_unslash( $_GET['pd_to'] ) ) . ' 23:59:59';
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$search    = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) . '%';
			$where[]   = '(d.donor_name LIKE %s OR d.donor_email LIKE %s OR d.donor_phone LIKE %s OR d.merchant_reference LIKE %s)';
			array_push( $args, $search, $search, $search, $search );
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = 'd.created_at';
		$order   = 'DESC';
		if ( ! empty( $_GET['orderby'] ) ) {
			$allowed = [ 'created_at', 'amount', 'status' ];
			$orderby = in_array( $_GET['orderby'], $allowed, true ) ? 'd.' . sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'd.created_at';
		}
		if ( ! empty( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ) {
			$order = 'ASC';
		}

		// Count total.
		$count_sql = "SELECT COUNT(*) FROM {$table} d WHERE {$where_sql}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

		$per_page = self::PER_PAGE;
		$page     = $this->get_pagenum();
		$offset   = ( $page - 1 ) * $per_page;

		// Fetch rows.
		$sql = "SELECT d.*, p.post_title AS campaign_title
				FROM {$table} d
				LEFT JOIN {$wpdb->posts} p ON p.ID = d.campaign_id
				WHERE {$where_sql}
				ORDER BY {$orderby} {$order}
				LIMIT %d OFFSET %d";
		$query_args   = array_merge( $args, [ $per_page, $offset ] );
		$this->items  = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A );

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );
	}

	// -------------------------------------------------------------------------
	// Bulk actions
	// -------------------------------------------------------------------------

	public function process_bulk_action(): void {
		// Single-item delete via row action link.
		if (
			isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) &&
			'delete' === $_GET['action'] &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pd_delete_' . (int) $_GET['id'] )
		) {
			$this->delete_donations( [ (int) $_GET['id'] ] );
			wp_safe_redirect( add_query_arg( 'pd_msg', 'deleted', admin_url( 'admin.php?page=pd-donations' ) ) );
			exit;
		}

		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$ids = array_map( 'absint', (array) ( $_POST['donation'] ?? [] ) );
		if ( empty( $ids ) ) {
			return;
		}

		$status_map = [
			'mark_completed' => 'completed',
			'mark_failed'    => 'failed',
			'mark_pending'   => 'pending',
		];

		if ( 'delete' === $action ) {
			$this->delete_donations( $ids );
			$msg = 'deleted';
		} elseif ( isset( $status_map[ $action ] ) ) {
			$this->update_status( $ids, $status_map[ $action ] );
			$msg = 'status';
		} else {
			return;
		}

		wp_safe_redirect( add_query_arg( 'pd_msg', $msg, admin_url( 'admin.php?page=pd-donations' ) ) );
		exit;
	}

	private function delete_donations( array $ids ): void {
		global $wpdb;
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// Capture affected donor & campaign IDs BEFORE deleting, so we can
		// refresh their aggregates afterward.
		$affected = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT donor_id, campaign_id FROM {$wpdb->prefix}pd_donations WHERE id IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}pd_donations WHERE id IN ({$placeholders})",
			$ids
		) );

		$this->refresh_affected( $affected );
	}

	private function update_status( array $ids, string $status ): void {
		global $wpdb;
		$affected = [];
		foreach ( $ids as $id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT donor_id, campaign_id FROM {$wpdb->prefix}pd_donations WHERE id = %d", $id ),
				ARRAY_A
			);
			if ( $row ) {
				$affected[] = $row;
			}
			$wpdb->update(
				$wpdb->prefix . 'pd_donations',
				[
					'status'       => $status,
					'updated_at'   => current_time( 'mysql' ),
					'completed_at' => 'completed' === $status ? current_time( 'mysql' ) : null,
				],
				[ 'id' => $id ]
			);
		}
		$this->refresh_affected( $affected );
	}

	private function refresh_affected( array $affected ): void {
		$donor_ids    = array_unique( array_filter( array_column( $affected, 'donor_id' ) ) );
		$campaign_ids = array_unique( array_filter( array_column( $affected, 'campaign_id' ) ) );

		foreach ( $donor_ids as $did ) {
			\PesaDonations\Models\Donor::recalculate( (int) $did );
		}
		foreach ( $campaign_ids as $cid ) {
			delete_transient( 'pd_raised_' . (int) $cid );
			delete_transient( 'pd_donors_' . (int) $cid );
		}
	}

	public function no_items(): void {
		esc_html_e( 'No donations found.', 'pesa-donations' );
	}
}
