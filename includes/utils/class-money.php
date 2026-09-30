<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

/**
 * Amounts as people read them: whole units where the currency has no cents
 * ("50,000 UGX", not "50,000.00 UGX"). There is no currency conversion in
 * this plugin, so totals in different currencies are listed side by side,
 * never added together.
 */
class Money {

	public static function format( float $amount, string $currency ): string {
		return number_format( $amount, Sanitizer::is_zero_decimal( $currency ) ? 0 : 2 ) . ' ' . strtoupper( $currency );
	}

	/**
	 * "150,000 UGX + 40.00 USD", largest first; an em dash when there is nothing.
	 *
	 * @param array<string, float> $by_currency currency => amount
	 */
	public static function format_totals( array $by_currency ): string {
		$by_currency = array_filter( $by_currency, static fn( $v ): bool => (float) $v > 0 );
		if ( ! $by_currency ) {
			return '—';
		}
		arsort( $by_currency );
		$parts = [];
		foreach ( $by_currency as $currency => $amount ) {
			$parts[] = self::format( (float) $amount, (string) $currency );
		}
		return implode( ' + ', $parts );
	}
}
