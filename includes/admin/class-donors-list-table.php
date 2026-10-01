<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\Models\Donor;
use PesaDonations\Utils\Money;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Donors_List_Table extends \WP_List_Table {

	private const PER_PAGE = 20;

	/** @var array<int, array<string, float>> donor id => [currency => completed total] */
	private array $totals = [];

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
			's'    => Donor::is_placeholder_email( (string) $item['email'] ) ? $item['phone'] : $item['email'],
		], admin_url( 'admin.php' ) );

		$display = trim( ( $item['first_name'] ?? '' ) . ' ' . ( $item['last_name'] ?? '' ) ) ?: __( '(No name)', 'pesa-donations' );

		$actions = [
			'edit'      => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'pesa-donations' ) ),
			'donations' => sprintf( '<a href="%s">%s</a>', esc_url( $view_donations_url ), esc_html__( 'View Donations', 'pesa-donations' ) ),
			'delete'    => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\')" style="color:#c62828;">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this donor? Only donors without donations can be deleted.', 'pesa-donations' ) ),
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
		if ( empty( $item['email'] ) || Donor::is_placeholder_email( (string) $item['email'] ) ) {
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
		// Per currency: the stored total adds shillings and dollars together.
		return '<strong>' . esc_html( Money::format_totals( $this->totals[ (int) $item['id'] ] ?? [] ) ) . '</strong>';
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

		$sql = "SELECT id, email, phone, first_name, last_name, country, donation_count, total_donated_base, last_donation_at
				FROM {$table}
				WHERE {$where_sql}
				ORDER BY {$orderby} {$order}, id DESC
				LIMIT %d OFFSET %d";
		$query_args   = array_merge( $args, [ $per_page, $offset ] );
		$this->items  = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->totals = Donor::totals_by_currency( array_column( $this->items, 'id' ) );

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
	// Actions: run on load-{page}, before any output, so they can redirect.
	// -------------------------------------------------------------------------

	public static function handle_actions(): void {
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			return;
		}
		$base = admin_url( 'admin.php?page=pd-donors' );

		// Single-item delete via row action link.
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && 'delete' === $_GET['action'] ) {
			check_admin_referer( 'pd_delete_donor_' . (int) $_GET['id'] );
			$ids = [ (int) $_GET['id'] ];
		} else {
			$table = new self();
			if ( 'delete' !== $table->current_action() ) {
				return;
			}
			check_admin_referer( 'bulk-donors' );
			// The list form is a GET form, so the checked rows arrive in the query string.
			$ids = array_values( array_filter( array_map( 'absint', (array) ( $_REQUEST['donor'] ?? [] ) ) ) );
		}

		[ $deleted, $kept ] = self::delete_donors( $ids );
		wp_safe_redirect( add_query_arg( [ 'pd_msg' => 'deleted', 'pd_deleted' => $deleted, 'pd_kept' => $kept ], $base ) );
		exit;
	}

	/**
	 * Deletes donors who have no donations. A donor with donations is kept:
	 * deleting them only cut the link (their name, email and phone stay on each
	 * donation) and a returning gift re-created them, so it erased nothing.
	 *
	 * @return array{0: int, 1: int} deleted, kept
	 */
	private static function delete_donors( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [ 0, 0 ];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$with_gifts   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT donor_id FROM {$wpdb->prefix}pd_donations WHERE donor_id IN ({$placeholders})",
			$ids
		) ) );
		$free = array_values( array_diff( $ids, $with_gifts ) );
		if ( $free ) {
			$in = implode( ',', array_fill( 0, count( $free ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pd_donors WHERE id IN ({$in})", $free ) );
		}
		return [ count( $free ), count( $ids ) - count( $free ) ];
	}

	public function no_items(): void {
		esc_html_e( 'No donors found.', 'pesa-donations' );
	}
}
