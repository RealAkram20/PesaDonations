<?php
declare( strict_types=1 );

namespace PesaDonations\Models;

use PesaDonations\Utils\Sanitizer;

/**
 * A donation to the organisation itself, with no campaign: the donor just
 * enters an amount. Stored like any donation with campaign_id = 0, so
 * receipts, the donor record and the admin lists all keep working.
 *
 * Settings live under Settings → Open Donations.
 */
class Open_Donation {

	public const CAMPAIGN_ID = 0;

	public static function is_enabled(): bool {
		return '1' === (string) get_option( 'pd_open_enabled', '1' );
	}

	/** What the donation is called on receipts, payment pages and in admin. */
	public static function label(): string {
		return (string) ( get_option( 'pd_open_label' ) ?: __( 'General donation', 'pesa-donations' ) );
	}

	public static function title(): string {
		return (string) ( get_option( 'pd_open_title' ) ?: __( 'Make a donation', 'pesa-donations' ) );
	}

	public static function intro(): string {
		return (string) get_option( 'pd_open_intro', '' );
	}

	public static function currency(): string {
		return (string) get_option( 'pd_default_currency', 'UGX' );
	}

	/**
	 * The saved minimum, or the site's UGX default when the form takes UGX.
	 * That default used to apply to every currency: 5,000 USD.
	 */
	public static function min_amount(): float {
		$saved = get_option( 'pd_open_min_amount' );
		if ( is_numeric( $saved ) && (float) $saved > 0 ) {
			return (float) $saved;
		}
		return 'UGX' === self::currency() ? (float) get_option( 'pd_minimum_amount_ugx', 5000 ) : 0.0;
	}

	/**
	 * Quick-pick amounts in the shape the checkout already renders.
	 *
	 * @param string $csv Overrides the saved list, e.g. from a shortcode attribute.
	 * @return array<int, array{amount: float, currency: string}>
	 */
	public static function suggested_amounts( string $csv = '' ): array {
		$csv      = '' !== $csv ? $csv : (string) get_option( 'pd_open_amounts', '' );
		$currency = self::currency();
		// "20,000, 50,000" in a currency without cents is two amounts, not
		// 20 / 0 / 50 / 0: there a comma before exactly three digits groups thousands.
		if ( Sanitizer::is_zero_decimal( $currency ) ) {
			$csv = (string) preg_replace( '/(?<=\d),(?=\d{3}(?!\d))/', '', $csv );
		}
		$min = self::min_amount();
		$out = [];
		foreach ( preg_split( '/[,;]/', $csv ) as $part ) {
			$amount = Sanitizer::amount( $part, $currency );
			// Below the minimum, the button could only lead to an error.
			if ( null !== $amount && $amount >= $min ) {
				$out[] = [ 'amount' => $amount, 'currency' => $currency ];
			}
		}
		return $out;
	}

	/** The checkout page with no campaign shows the open donation form. */
	public static function form_url(): string {
		$page_id = (int) get_option( 'pd_checkout_page_id' );
		return $page_id ? (string) get_permalink( $page_id ) : home_url( '/' );
	}
}
