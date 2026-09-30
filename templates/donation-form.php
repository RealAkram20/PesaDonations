<?php
/**
 * Open donation form: no campaign, the donor enters an amount. Rendered by
 * [pd_donate] and by the checkout page when the URL names no campaign.
 *
 * Variables available:
 *   $campaign    null
 *   $open_title  string  Heading
 *   $open_intro  string  Optional paragraph under the heading
 *   $suggested   array   Quick-pick amounts: [ { amount, currency } ]
 *
 * Theme override: /wp-content/themes/{theme}/pesa-donations/donation-form.php
 * It reuses the plugin's checkout markup, so the two forms stay identical.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

include PD_PLUGIN_DIR . 'templates/sponsorship-checkout.php';
