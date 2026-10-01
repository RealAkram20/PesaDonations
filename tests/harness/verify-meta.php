<?php
defined( 'ABSPATH' ) || exit; // Run with: wp eval-file
// Campaign editor save, as a real request makes it (WordPress slashes $_POST). Run with wp eval-file.
use PesaDonations\Admin\Meta_Boxes;

wp_set_current_user( 1 );
$ok = static function ( $got, $want, string $msg ): void {
	echo ( $got === $want ? "ok   $msg" : "FAIL $msg: got " . var_export( $got, true ) . ' want ' . var_export( $want, true ) ) . "\n";
};
$save = static function ( int $id, array $post ): void {
	$_POST = wp_slash( $post );
	( new Meta_Boxes() )->save( $id, get_post( $id ) );
	clean_post_cache( $id );
	wp_cache_delete( $id, 'post_meta' );
};

$id    = (int) wp_insert_post( [ 'post_type' => 'pd_campaign', 'post_title' => '[VERIFY] Meta save', 'post_status' => 'draft' ] );
$plans = [ [ 'name' => 'Term – "Plus"', 'amount' => 150000, 'currency' => 'UGX' ] ];
$base  = [
	'pd_meta_nonce'         => wp_create_nonce( 'pd_meta_save_' . $id ),
	'_pd_category'          => 'sponsorship',
	'_pd_status'            => 'active',
	'_pd_base_currency'     => 'UGX',
	'_pd_goal_amount'       => '5,000,000',
	'_pd_minimum_amount'    => '',
	'_pd_beneficiary_name'  => 'O\'Brien \\ Nakato',
	'_pd_sponsorship_plans' => wp_json_encode( $plans ),
	'_pd_cycle_type'        => 'custom',
	'_pd_cycle_periods'     => [ [ 'id' => 't1', 'label' => 'Term 1 – 2027', 'start' => '2027-02-01', 'end' => '2027-05-01' ] ],
];

$save( $id, $base );
$ok( json_decode( (string) get_post_meta( $id, '_pd_sponsorship_plans', true ), true ), $plans, 'A3: plan JSON with an en dash and quotes round-trips' );
$periods = json_decode( (string) get_post_meta( $id, '_pd_cycle_periods', true ), true );
$ok( $periods[0]['label'] ?? null, 'Term 1 – 2027', 'A3: period label with an en dash round-trips' );
$ok( (float) get_post_meta( $id, '_pd_goal_amount', true ), 5000000.0, '"5,000,000" saved as five million' );
$ok( get_post_meta( $id, '_pd_minimum_amount', true ), '', 'blank minimum stays blank (the site default)' );
$ok( get_post_meta( $id, '_pd_beneficiary_name', true ), "O'Brien \\ Nakato", 'a backslash in a name survives' );

// M1: rejected custom periods leave the repeat type as it was.
update_post_meta( $id, '_pd_cycle_type', 'monthly' );
$bad                      = $base;
$bad['_pd_cycle_periods'] = [ [ 'id' => 't9', 'label' => 'Bad', 'start' => '2027-05-01', 'end' => '2027-02-01' ] ];
$save( $id, $bad );
$ok( get_post_meta( $id, '_pd_cycle_type', true ), 'monthly', 'M1: rejected periods do not switch the campaign to custom' );

// Broken JSON keeps the previous tiers.
$bad2                          = $base;
$bad2['_pd_cycle_type']        = 'monthly';
$bad2['_pd_sponsorship_plans'] = '{oops';
$save( $id, $bad2 );
$ok( json_decode( (string) get_post_meta( $id, '_pd_sponsorship_plans', true ), true ), $plans, 'broken plan JSON keeps the previous tiers' );

// An unknown status is not stored.
$bad3               = $base;
$bad3['_pd_status'] = 'hacked';
$bad3['_pd_cycle_type'] = 'monthly';
$save( $id, $bad3 );
$ok( get_post_meta( $id, '_pd_status', true ), 'active', 'unknown campaign status refused' );

wp_delete_post( $id, true );
delete_transient( 'pd_campaign_notice_1' );
