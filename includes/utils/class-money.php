<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

/**
 * Amounts as people read them: whole units where the currency has no cents
 * ("50,000 UGX", not "50,000.00 UGX"). Totals in different currencies are
 * listed side by side, never added together; a gift is converted once, at
 * checkout (Payments\Charge_Quote), and counted in its campaign's currency.
 */
class Money {

	public static function format( float $amount, string $currency ): string {
		return number_format( $amount, Currencies::decimals( $currency ) ) . ' ' . strtoupper( $currency );
	}

	/** Up to the currency's smallest unit: a converted minimum must never come out below the real one. */
	public static function round_up( float $amount, string $currency ): float {
		$factor = 10 ** Currencies::decimals( $currency );
		return ceil( round( $amount * $factor, 6 ) ) / $factor;
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

	/**
	 * A gift as given and, when it was converted, as charged:
	 * "50.00 EUR (charged 207,350 UGX)".
	 */
	public static function given( float $charged, string $charged_currency, ?float $original, string $original_currency ): string {
		if ( null === $original || '' === $original_currency || strtoupper( $original_currency ) === strtoupper( $charged_currency ) ) {
			return self::format( $charged, $charged_currency );
		}
		return sprintf(
			/* translators: 1: amount the donor chose, 2: amount charged */
			__( '%1$s (charged %2$s)', 'pesa-donations' ),
			self::format( $original, $original_currency ),
			self::format( $charged, $charged_currency )
		);
	}
}
