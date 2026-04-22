<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Donors_List_Table extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct() {
		parent::__construct( [
			'singular' => 'donor',
			'plural'   => 'donors',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'cb'               => '<input type="checkbox" />',
			'name'             => __( 'Name', 'pesa-donations' ),
			'email'            => __( 'Email', 'pesa-donations' ),
			'phone'            => __( 'Phone', 'pesa-donations' ),
			'country'          => __( 'Country', 'pesa-donations' ),
			'donation_count'   => __( 'Donations', 'pesa-donations' ),
			'total_donated'    => __( 'Total Given', 'pesa-donations' ),
			'last_donation_at' => __( 'Last Donation', 'pesa-donations' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'name'             => [ 'last_name', false ],
			'total_donated'    => [ 'total_donated_base', true ],
			'donation_count'   => [ 'donation_count', false ],
			'last_donation_at' => [ 'last_donation_at', false ],
		];
	}

	protected function get_bulk_actions(): array {
		return [
			'delete' => __( 'Delete', 'pesa-donations' ),
		];
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="donor[]" value="%d" />', (int) $item['id'] );
	}

	protected function column_name( $item ): string {
		$edit_url = add_query_arg( [
			'page' => 'pd-donor-edit',
			'id'   => (int) $item['id'],
		], admin_url( 'admin.php' ) );

		$delete_url = wp_nonce_url(
			add_query_arg( [
				'page'   => 'pd-donors',
				'action' => 'delete',
				'id'     => (int) $item['id'],
			], admin_url( 'admin.php' ) ),
			'pd_delete_donor_' . $item['id']
		);

		$view_donations_url = add_query_arg( [
			'page' => 'pd-donations',
			's'    => $item['email'],
		], admin_url( 'admin.php' ) );

		$display = trim( ( $item['first_name'] ?? '' ) . ' ' . ( $item['last_name'] ?? '' ) ) ?: __( '(No name)', 'pesa-donations' );

		$actions = [
			'edit'      => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'pesa-donations' ) ),
			'donations' => sprintf( '<a href="%s">%s</a>', esc_url( $view_donations_url ), esc_html__( 'View Donations', 'pesa-donations' ) ),
			'delete'    => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\')" style="color:#c62828;">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this donor? Their donation records will remain but will no longer be linked.', 'pesa-donations' ) ),
				esc_html__( 'Delete', 'pesa-donations' )
			),
		];

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $display ),
			$this->row_actions( $actions )
		);
	}

	protected function column_email( $item ): string {
		if ( empty( $item['email'] ) ) {
			return '—';
		}
		return sprintf(
			'<a href="mailto:%s">%s</a>',
			esc_attr( $item['email'] ),
			esc_html( $item['email'] )
		);
	}

	protected function column_phone( $item ): string {
		return esc_html( $item['phone'] ?: '—' );
	}

	protected function column_country( $item ): string {
		return esc_html( $item['country'] ?: '—' );
	}

	protected function column_donation_count( $item ): string {
		return '<strong>' . (int) $item['donation_count'] . '</strong>';
	}

	protected function column_total_donated( $item ): string {
		$amount = (float) ( $item['total_donated_base'] ?? 0 );
		return sprintf(
			'<strong style="color:#c62828;">%s</strong>',
			esc_html( number_format( $amount, 2 ) )
		);
	}

	protected function column_last_donation_at( $item ): string {
		if ( empty( $item['last_donation_at'] ) ) {
			return '—';
		}
		return esc_html( mysql2date( 'M j, Y', $item['last_donation_at'] ) );
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		global $wpdb;
		$table = $wpdb->prefix . 'pd_donors';

		$where  = [ '1=1' ];
		$args   = [];

		if ( ! empty( $_REQUEST['s'] ) ) {
			$search  = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) . '%';
			$where[] = '(email LIKE %s OR phone LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			array_push( $args, $search, $search, $search, $search );
		}
		if ( ! empty( $_GET['pd_country'] ) ) {
			$where[] = 'country = %s';
			$args[]  = sanitize_text_field( wp_unslash( $_GET['pd_country'] ) );
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = 'last_donation_at';
		$order   = 'DESC';
		if ( ! empty( $_GET['orderby'] ) ) {
			$allowed = [ 'last_name', 'total_donated_base', 'donation_count', 'last_donation_at' ];
			$req     = sanitize_key( wp_unslash( $_GET['orderby'] ) );
			if ( in_array( $req, $allowed, true ) ) {
				$orderby = $req;
			}
		}
		if ( ! empty( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ) {
			$order = 'ASC';
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

		$per_page = self::PER_PAGE;
		$page     = $this->get_pagenum();
		$offset   = ( $page - 1 ) * $per_page;

		$sql = "SELECT * FROM {$table}
				WHERE {$where_sql}
				ORDER BY {$orderby} {$order}, id DESC
				LIMIT %d OFFSET %d";
		$query_args  = array_merge( $args, [ $per_page, $offset ] );
		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A );

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		global $wpdb;
		$countries = $wpdb->get_col(
			"SELECT DISTINCT country FROM {$wpdb->prefix}pd_donors WHERE country IS NOT NULL AND country != '' ORDER BY country"
		);

		$selected = isset( $_GET['pd_country'] ) ? sanitize_text_field( wp_unslash( $_GET['pd_country'] ) ) : '';

		echo '<div class="alignleft actions">';
		echo '<select name="pd_country"><option value="">' . esc_html__( 'All countries', 'pesa-donations' ) . '</option>';
		foreach ( $countries as $c ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $c ),
				selected( $selected, $c, false ),
				esc_html( $c )
			);
		}
		echo '</select>';
		submit_button( __( 'Filter', 'pesa-donations' ), 'secondary', 'pd_filter', false );
		if ( $selected ) {
			printf(
				' <a href="%s" class="button-link" style="margin-left:6px;">%s</a>',
				esc_url( admin_url( 'admin.php?page=pd-donors' ) ),
				esc_html__( 'Reset', 'pesa-donations' )
			);
		}
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Bulk actions
	// -------------------------------------------------------------------------

	public function process_bulk_action(): void {
		// Single-item delete via row action link.
		if (
			isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) &&
			'delete' === $_GET['action'] &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pd_delete_donor_' . (int) $_GET['id'] )
		) {
			$this->delete_donors( [ (int) $_GET['id'] ] );
			wp_safe_redirect( add_query_arg( 'pd_msg', 'deleted', admin_url( 'admin.php?page=pd-donors' ) ) );
			exit;
		}

		$action = $this->current_action();
		if ( 'delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$ids = array_map( 'absint', (array) ( $_POST['donor'] ?? [] ) );
		if ( empty( $ids ) ) {
			return;
		}

		$this->delete_donors( $ids );
		wp_safe_redirect( add_query_arg( 'pd_msg', 'deleted', admin_url( 'admin.php?page=pd-donors' ) ) );
		exit;
	}

	private function delete_donors( array $ids ): void {
		global $wpdb;
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Unlink donor_id from any donations they had (keep donation records).
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}pd_donations SET donor_id = NULL WHERE donor_id IN ({$placeholders})",
			$ids
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}pd_donors WHERE id IN ({$placeholders})",
			$ids
		) );
	}

	public function no_items(): void {
		esc_html_e( 'No donors found.', 'pesa-donations' );
	}
}
