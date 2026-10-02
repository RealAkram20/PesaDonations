<?php
declare( strict_types=1 );

namespace PesaDonations\Payments;

use PesaDonations\Utils\Currencies;
use PesaDonations\Utils\Exchange_Rates;
use WP_Error;

/**
 * What a gift costs and what it counts for, worked out once at checkout and
 * stored with the donation (docs/adr/0003):
 *
 *   given    the amount and currency the donor chose (original_amount/currency
 *            when it was converted);
 *   charged  what PesaPal is asked for (amount/currency): the donor's currency
 *            if PesaPal charges it, else the site's local currency;
 *   counted  the charged amount in the campaign's currency (amount_base,
 *            base_currency, fx_rate = charged → counted), which goal, progress
 *            and every total add up.
 *
 * Rates are the day's rates at checkout and never change afterwards.
 */
final class Charge_Quote {

	private function __construct(
		private float $given_amount,
		private string $given_currency,
		private float $charge_amount,
		private string $charge_currency,
		private float $base_amount,
		private string $base_currency,
		private float $fx_rate
	) {}

	public function charge_amount(): float    { return $this->charge_amount; }
	public function charge_currency(): string { return $this->charge_currency; }
	public function base_amount(): float      { return $this->base_amount; }

	/**
	 * @param float  $amount        As the donor typed it.
	 * @param string $currency      The donor's choice.
	 * @param string $base_currency The campaign's currency (the open donation's for no campaign).
	 */
	public static function make( float $amount, string $currency, string $base_currency ): self|WP_Error {
		$currency      = strtoupper( $currency );
		$base_currency = strtoupper( $base_currency );
		$charge        = Currencies::charge_currency_for( $currency );

		$charge_amount = $amount;
		if ( $charge !== $currency ) {
			$rate = Exchange_Rates::rate( $currency, $charge );
			if ( null === $rate ) {
				return self::no_rate( $base_currency );
			}
			$charge_amount = Currencies::round( $amount * $rate, $charge );
		}

		$fx = Exchange_Rates::rate( $charge, $base_currency );
		if ( null === $fx ) {
			return self::no_rate( $base_currency );
		}

		return new self( $amount, $currency, $charge_amount, $charge, round( $charge_amount * $fx, 2 ), $base_currency, $fx );
	}

	/**
	 * The counted part only, for a gift recorded by hand in the currency it was
	 * received in. Without a rate, it counts in its own currency (as before 1.3):
	 * listed, but not added to a campaign kept in another one.
	 *
	 * @return array{amount_base: float, base_currency: string, fx_rate: float}
	 */
	public static function counted( float $amount, string $currency, string $base_currency ): array {
		$fx = Exchange_Rates::rate( $currency, $base_currency );
		return null === $fx
			? [ 'amount_base' => $amount, 'base_currency' => strtoupper( $currency ), 'fx_rate' => 1.0 ]
			: [ 'amount_base' => round( $amount * $fx, 2 ), 'base_currency' => strtoupper( $base_currency ), 'fx_rate' => $fx ];
	}

	/**
	 * Currencies a donor may choose at this checkout, in display order: the
	 * campaign's own, then (if choosing is allowed) the offered list, then any
	 * a sponsorship plan is priced in. Only those that can be charged and
	 * counted today: without fresh rates that is the campaign's own currency.
	 *
	 * @param string[] $extra
	 * @return string[]
	 */
	public static function choices( string $base_currency, bool $switchable, array $extra = [] ): array {
		$codes = array_unique( array_merge(
			[ strtoupper( $base_currency ) ],
			$switchable ? Currencies::offered() : [],
			array_map( 'strtoupper', $extra )
		) );
		return array_values( array_filter( $codes, static fn( string $c ): bool => ! is_wp_error( self::make( 1.0, $c, $base_currency ) ) ) );
	}

	public function is_converted(): bool {
		return $this->charge_currency !== $this->given_currency;
	}

	/** Donation columns. */
	public function fields(): array {
		return [
			'amount'            => $this->charge_amount,
			'currency'          => $this->charge_currency,
			'amount_base'       => $this->base_amount,
			'base_currency'     => $this->base_currency,
			'fx_rate'           => $this->fx_rate,
			'original_amount'   => $this->is_converted() ? $this->given_amount : null,
			'original_currency' => $this->is_converted() ? $this->given_currency : null,
		];
	}

	private static function no_rate( string $base_currency ): WP_Error {
		// The campaign's own currency needs a rate only when PesaPal cannot charge it.
		if ( ! Currencies::is_chargeable( $base_currency ) ) {
			return new WP_Error( 'pd_no_rate', __( 'Donations here need today\'s exchange rate, which is not available right now. Please try again later.', 'pesa-donations' ) );
		}
		return new WP_Error( 'pd_no_rate', sprintf(
			/* translators: %s: currency code */
			__( 'Today\'s exchange rate is not available. Please give in %s.', 'pesa-donations' ),
			$base_currency
		) );
	}
}
