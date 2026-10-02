<?php
declare( strict_types=1 );

namespace PesaDonations\Admin;

use PesaDonations\Models\Campaign;
use PesaDonations\Models\Campaign_Totals;
use PesaDonations\Models\Donation;
use PesaDonations\Models\Donor;
use PesaDonations\Models\Open_Donation;
use PesaDonations\Utils\Money;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Donations_List_Table extends \WP_List_Table {

	private const PER_PAGE = 20;
	private const STATUSES = [ 'completed', 'pending', 'failed', 'reversed', 'cancelled' ];

	/** @var array<int, Campaign|null> */
	private array $campaigns = [];

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

	/**
	 * Only moves the status rules allow in bulk (no "Mark as Pending": nothing
	 * moves back to pending). Rows whose status cannot make the move are left
	 * as they are and counted in the notice.
	 */
	protected function get_bulk_actions(): array {
		return [
			'mark_completed' => __( 'Mark as Completed', 'pesa-donations' ),
			'mark_failed'    => __( 'Mark as Failed', 'pesa-donations' ),
			'mark_cancelled' => __( 'Mark as Cancelled', 'pesa-donations' ),
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
		$name  = $item['donor_name'] ?: ( $item['is_anonymous'] ? __( 'Anonymous', 'pesa-donations' ) : '—' );
		$email = Donor::is_placeholder_email( (string) $item['donor_email'] ) ? '' : (string) $item['donor_email'];
		$phone = (string) $item['donor_phone'];

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
		$cid = (int) $item['campaign_id'];
		if ( Open_Donation::CAMPAIGN_ID === $cid ) {
			return '<em>' . esc_html( Open_Donation::label() ) . '</em>';
		}
		$campaign = $this->campaigns[ $cid ] ?? null;
		if ( ! $campaign ) {
			return '—';
		}
		$out = sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_post_link( $cid ) ),
			esc_html( $campaign->get_title() )
		);

		// Which period of a repeating campaign the gift counted toward.
		$period = $campaign->get_period_for_donation( (string) $item['created_at'] );
		if ( $period ) {
			$out .= '<br><span style="color:#888;font-size:12px;">' . esc_html( $period->get_label() ) . '</span>';
		}
		return $out;
	}

	protected function column_amount( $item ): string {
		$out = '<strong>' . esc_html( Money::format( (float) $item['amount'], (string) $item['currency'] ) ) . '</strong>';
		// Given in another currency and converted before charging: show what the donor chose.
		if ( null !== $item['original_amount'] && '' !== (string) $item['original_currency'] ) {
			/* translators: %s: amount with currency */
			$out .= '<br><span style="color:#646970;font-size:12px;">' . esc_html( sprintf( __( 'given as %s', 'pesa-donations' ), Money::format( (float) $item['original_amount'], (string) $item['original_currency'] ) ) ) . '</span>';
		}
		return $out;
	}

	protected function column_gateway( $item ): string {
		$labels = [ 'pesapal' => 'PesaPal', 'paypal' => 'PayPal', 'manual' => __( 'Manual', 'pesa-donations' ) ];
		$g      = $item['gateway'] ?: 'manual';
		$out    = esc_html( $labels[ $g ] ?? ucfirst( $g ) );
		if ( ! empty( $item['payment_method'] ) ) {
			$out .= '<br><span style="color:#888;font-size:12px;">' . esc_html( $item['payment_method'] ) . '</span>';
		}
		return $out;
	}

	protected function column_status( $item ): string {
		$colors = [
			'completed' => [ '#e8f5e9', '#2e7d32' ],
			'pending'   => [ '#fff8e1', '#b34700' ],
			'failed'    => [ '#ffebee', '#c62828' ],
			'reversed'  => [ '#f3e5f5', '#6a1b9a' ],
			'cancelled' => [ '#f5f5f5', '#555' ],
		];
		$status = $item['status'] ?: 'pending';
		$c      = $colors[ $status ] ?? [ '#f5f5f5', '#555' ];

		$out = sprintf(
			'<span style="background:%s;color:%s;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;">%s</span>',
			esc_attr( $c[0] ),
			esc_attr( $c[1] ),
			esc_html( ucfirst( $status ) )
		);
		// Sandbox payments never count toward totals on a live site: say so where they are listed.
		if ( 'sandbox' === ( $item['environment'] ?? '' ) ) {
			$out .= ' <span style="border:1px solid #8c8f94;color:#3c434a;padding:1px 6px;border-radius:10px;font-size:11px;" title="'
				. esc_attr__( 'Paid in the PesaPal sandbox: not real money, not counted once the site is live.', 'pesa-donations' ) . '">'
				. esc_html__( 'Test', 'pesa-donations' ) . '</span>';
		}
		return $out;
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

		// Every campaign that exists, not only published ones: donations to an
		// ended (draft or private) campaign must stay findable.
		$campaigns = $wpdb->get_results(
			"SELECT ID, post_title, post_status FROM {$wpdb->posts}
			 WHERE post_type = 'pd_campaign' AND post_status NOT IN ('auto-draft', 'trash')
			 ORDER BY post_title",
			ARRAY_A
		);

		$selected_status   = isset( $_GET['pd_status'] ) ? sanitize_key( wp_unslash( $_GET['pd_status'] ) ) : '';
		$selected_campaign = isset( $_GET['pd_campaign_filter'] ) ? sanitize_key( wp_unslash( $_GET['pd_campaign_filter'] ) ) : '';
		$selected_gateway  = isset( $_GET['pd_gateway'] ) ? sanitize_key( wp_unslash( $_GET['pd_gateway'] ) ) : '';
		$date_from         = isset( $_GET['pd_from'] ) ? sanitize_text_field( wp_unslash( $_GET['pd_from'] ) ) : '';
		$date_to           = isset( $_GET['pd_to'] ) ? sanitize_text_field( wp_unslash( $_GET['pd_to'] ) ) : '';

		echo '<div class="alignleft actions">';

		// Status
		echo '<select name="pd_status" aria-label="' . esc_attr__( 'Status', 'pesa-donations' ) . '"><option value="">' . esc_html__( 'All statuses', 'pesa-donations' ) . '</option>';
		foreach ( self::STATUSES as $s ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $s ),
				selected( $selected_status, $s, false ),
				esc_html( ucfirst( $s ) )
			);
		}
		echo '</select>';

		// Campaign
		echo '<select name="pd_campaign_filter" aria-label="' . esc_attr__( 'Campaign', 'pesa-donations' ) . '"><option value="0">' . esc_html__( 'All campaigns', 'pesa-donations' ) . '</option>';
		printf(
			'<option value="general" %s>%s</option>',
			selected( $selected_campaign, 'general', false ),
			esc_html( Open_Donation::label() )
		);
		foreach ( $campaigns as $c ) {
			printf(
				'<option value="%d" %s>%s%s</option>',
				(int) $c['ID'],
				selected( $selected_campaign, (string) $c['ID'], false ),
				esc_html( $c['post_title'] ),
				'publish' === $c['post_status'] ? '' : ' (' . esc_html( $c['post_status'] ) . ')'
			);
		}
		echo '</select>';

		// Gateway
		echo '<select name="pd_gateway" aria-label="' . esc_attr__( 'Gateway', 'pesa-donations' ) . '"><option value="">' . esc_html__( 'All gateways', 'pesa-donations' ) . '</option>';
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
		echo '<input type="date" name="pd_from" value="' . esc_attr( $date_from ) . '" aria-label="' . esc_attr__( 'From', 'pesa-donations' ) . '" />';
		echo '<input type="date" name="pd_to" value="' . esc_attr( $date_to ) . '" aria-label="' . esc_attr__( 'To', 'pesa-donations' ) . '" />';

		submit_button( __( 'Filter', 'pesa-donations' ), 'secondary', 'pd_filter', false );

		if ( $selected_status || ( $selected_campaign && '0' !== $selected_campaign ) || $selected_gateway || $date_from || $date_to ) {
			printf(
				' <a href="%s" class="button-link" style="margin-left:6px;">%s</a>',
				esc_url( admin_url( 'admin.php?page=pd-donations' ) ),
				esc_html__( 'Reset', 'pesa-donations' )
			);
		}

		echo '</div>';
	}

	/**
	 * Quick status tabs (All / Completed / Pending / ...).
	 */
	protected function get_views(): array {
		global $wpdb;

		$base    = admin_url( 'admin.php?page=pd-donations' );
		$current = isset( $_GET['pd_status'] ) ? sanitize_key( wp_unslash( $_GET['pd_status'] ) ) : '';

		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) as c FROM {$wpdb->prefix}pd_donations GROUP BY status",
			OBJECT_K
		);
		$get   = fn( string $k ) => isset( $counts[ $k ] ) ? (int) $counts[ $k ]->c : 0;
		$total = array_sum( array_map( static fn( $row ): int => (int) $row->c, $counts ) );

		$views = [ 'all' => [ __( 'All', 'pesa-donations' ), $total, '' ] ];
		foreach ( self::STATUSES as $s ) {
			$views[ $s ] = [ ucfirst( $s ), $get( $s ), $s ];
		}

		$out = [];
		foreach ( $views as $k => [ $label, $count, $s ] ) {
			if ( $s && ! $count && $current !== $s ) {
				continue;
			}
			$url   = $s ? add_query_arg( 'pd_status', $s, $base ) : $base;
			$out[ $k ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current === $s ? ' class="current" aria-current="page"' : '',
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
		if ( isset( $_GET['pd_campaign_filter'] ) && 'general' === $_GET['pd_campaign_filter'] ) {
			$where[] = 'd.campaign_id = 0';
		} elseif ( ! empty( $_GET['pd_campaign_filter'] ) ) {
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
		$req     = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( in_array( $req, [ 'created_at', 'amount', 'status' ], true ) ) {
			$orderby = 'd.' . $req;
		}
		if ( ! empty( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) {
			$order = 'ASC';
		}

		// Count total.
		$count_sql = "SELECT COUNT(*) FROM {$table} d WHERE {$where_sql}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

		$per_page = self::PER_PAGE;
		$page     = $this->get_pagenum();
		$offset   = ( $page - 1 ) * $per_page;

		// The columns the list shows, not d.* (message and address are text columns).
		$sql = "SELECT d.id, d.merchant_reference, d.campaign_id, d.donor_name, d.donor_email, d.donor_phone, d.is_anonymous,
				       d.amount, d.currency, d.original_amount, d.original_currency, d.gateway, d.payment_method, d.status, d.environment, d.created_at
				FROM {$table} d
				WHERE {$where_sql}
				ORDER BY {$orderby} {$order}, d.id {$order}
				LIMIT %d OFFSET %d";
		$query_args  = array_merge( $args, [ $per_page, $offset ] );
		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Every campaign on the page, with its meta, in two queries (was several per row).
		$ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $this->items, 'campaign_id' ) ) ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
			foreach ( $ids as $cid ) {
				$this->campaigns[ $cid ] = Campaign::get( $cid );
			}
		}

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );
	}

	// -------------------------------------------------------------------------
	// Actions: run on load-{page}, before any output, so they can redirect.
	// -------------------------------------------------------------------------

	public static function handle_actions(): void {
		if ( ! current_user_can( 'pd_manage_donations' ) ) {
			return;
		}
		$base = admin_url( 'admin.php?page=pd-donations' );

		// Single-item delete via row action link.
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && 'delete' === $_GET['action'] ) {
			check_admin_referer( 'pd_delete_' . (int) $_GET['id'] );
			self::delete_donations( [ (int) $_GET['id'] ] );
			wp_safe_redirect( add_query_arg( 'pd_msg', 'deleted', $base ) );
			exit;
		}

		$table  = new self();
		$action = $table->current_action();
		if ( ! $action || ! array_key_exists( $action, $table->get_bulk_actions() ) ) {
			return;
		}

		check_admin_referer( 'bulk-donations' );

		// The list form is a GET form, so the checked rows arrive in the query string.
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $_REQUEST['donation'] ?? [] ) ) ) );
		if ( ! $ids ) {
			wp_safe_redirect( $base );
			exit;
		}

		if ( 'delete' === $action ) {
			self::delete_donations( $ids );
			wp_safe_redirect( add_query_arg( 'pd_msg', 'deleted', $base ) );
			exit;
		}

		$to    = substr( $action, strlen( 'mark_' ) );
		$moved = 0;
		foreach ( $ids as $id ) {
			$donation = Donation::get( $id );
			// A bulk change sends no emails: marking fifty cash gifts is not fifty receipts.
			if ( $donation && $donation->transition( $to, [], [ 'source' => 'admin', 'notify' => false ] ) ) {
				++$moved;
			}
		}
		wp_safe_redirect( add_query_arg( [ 'pd_msg' => 'status', 'pd_moved' => $moved, 'pd_skipped' => count( $ids ) - $moved ], $base ) );
		exit;
	}

	private static function delete_donations( array $ids ): void {
		global $wpdb;
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// Capture affected donors BEFORE deleting, so their totals can be rebuilt.
		$donor_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT donor_id FROM {$wpdb->prefix}pd_donations WHERE id IN ({$placeholders}) AND donor_id IS NOT NULL",
				$ids
			)
		);

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}pd_donations WHERE id IN ({$placeholders})",
			$ids
		) );

		foreach ( $donor_ids as $did ) {
			Donor::recalculate( (int) $did );
		}
		Campaign_Totals::flush();
	}

	public function no_items(): void {
		esc_html_e( 'No donations found.', 'pesa-donations' );
	}
}
