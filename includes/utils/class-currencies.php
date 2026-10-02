<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

/**
 * The currencies a donor can give in, and the ones PesaPal charges.
 *
 * PesaPal charges a merchant's local currency and USD (cards); its documents
 * confirm nothing else. A donor may still choose any currency offered here:
 * the checkout converts it to the local currency at the day's rate
 * (Exchange_Rates) and says so before they pay. See docs/adr/0003.
 */
class Currencies {

	/** East African Community members first (plus Ethiopia), then widely held international currencies. */
	private const EAST_AFRICA   = [ 'UGX', 'KES', 'TZS', 'RWF', 'BIF', 'SSP', 'CDF', 'SOS', 'ETB' ];
	private const INTERNATIONAL = [ 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'SEK', 'NOK', 'DKK', 'AED', 'ZAR', 'CNY', 'JPY', 'INR' ];

	/** No minor unit in practice: amounts are whole numbers. */
	private const ZERO_DECIMAL = [ 'UGX', 'TZS', 'RWF', 'BIF', 'JPY' ];

	/** @return array<string, string> code => name, in display order. */
	public static function all(): array {
		return [
			'UGX' => __( 'Ugandan Shilling', 'pesa-donations' ),
			'KES' => __( 'Kenyan Shilling', 'pesa-donations' ),
			'TZS' => __( 'Tanzanian Shilling', 'pesa-donations' ),
			'RWF' => __( 'Rwandan Franc', 'pesa-donations' ),
			'BIF' => __( 'Burundian Franc', 'pesa-donations' ),
			'SSP' => __( 'South Sudanese Pound', 'pesa-donations' ),
			'CDF' => __( 'Congolese Franc', 'pesa-donations' ),
			'SOS' => __( 'Somali Shilling', 'pesa-donations' ),
			'ETB' => __( 'Ethiopian Birr', 'pesa-donations' ),
			'USD' => __( 'US Dollar', 'pesa-donations' ),
			'EUR' => __( 'Euro', 'pesa-donations' ),
			'GBP' => __( 'British Pound', 'pesa-donations' ),
			'CAD' => __( 'Canadian Dollar', 'pesa-donations' ),
			'AUD' => __( 'Australian Dollar', 'pesa-donations' ),
			'CHF' => __( 'Swiss Franc', 'pesa-donations' ),
			'SEK' => __( 'Swedish Krona', 'pesa-donations' ),
			'NOK' => __( 'Norwegian Krone', 'pesa-donations' ),
			'DKK' => __( 'Danish Krone', 'pesa-donations' ),
			'AED' => __( 'UAE Dirham', 'pesa-donations' ),
			'ZAR' => __( 'South African Rand', 'pesa-donations' ),
			'CNY' => __( 'Chinese Yuan', 'pesa-donations' ),
			'JPY' => __( 'Japanese Yen', 'pesa-donations' ),
			'INR' => __( 'Indian Rupee', 'pesa-donations' ),
		];
	}

	/** @return string[] Every code the plugin knows, in display order. */
	public static function codes(): array {
		return array_merge( self::EAST_AFRICA, self::INTERNATIONAL );
	}

	/** @return array<string, string[]> group label => codes */
	public static function groups(): array {
		return [
			__( 'East Africa', 'pesa-donations' )   => self::EAST_AFRICA,
			__( 'International', 'pesa-donations' ) => self::INTERNATIONAL,
		];
	}

	public static function is_known( string $code ): bool {
		return in_array( strtoupper( $code ), self::codes(), true );
	}

	public static function name( string $code ): string {
		return self::all()[ strtoupper( $code ) ] ?? strtoupper( $code );
	}

	public static function is_zero_decimal( string $code ): bool {
		return in_array( strtoupper( $code ), self::ZERO_DECIMAL, true );
	}

	public static function decimals( string $code ): int {
		return self::is_zero_decimal( $code ) ? 0 : 2;
	}

	public static function round( float $amount, string $code ): float {
		return round( $amount, self::decimals( $code ) );
	}

	/** The site's own currency: what PesaPal settles in and what a converted gift is charged in. */
	public static function local(): string {
		$code = strtoupper( (string) get_option( 'pd_default_currency', 'UGX' ) );
		return self::is_known( $code ) ? $code : 'UGX';
	}

	/** Whether donors may pick a currency at all (Settings → General). */
	public static function choice_enabled(): bool {
		return '0' !== (string) get_option( 'pd_currency_choice', '1' );
	}

	/** @return string[] Currencies donors may choose from, in display order. */
	public static function offered(): array {
		$saved = array_map( 'strtoupper', (array) get_option( 'pd_enabled_currencies', self::codes() ) );
		return array_values( array_filter( self::codes(), static fn( string $c ): bool => in_array( $c, $saved, true ) ) );
	}

	/**
	 * Currencies sent to PesaPal as they are: the local currency, and USD unless
	 * switched off (PesaPal takes USD by card). Anything else is converted.
	 *
	 * @return string[]
	 */
	public static function chargeable(): array {
		$out = [ self::local() ];
		if ( '0' !== (string) get_option( 'pd_charge_usd', '1' ) && 'USD' !== self::local() ) {
			$out[] = 'USD';
		}
		return $out;
	}

	public static function is_chargeable( string $code ): bool {
		return in_array( strtoupper( $code ), self::chargeable(), true );
	}

	/** What a gift chosen in $code is charged in. */
	public static function charge_currency_for( string $code ): string {
		return self::is_chargeable( $code ) ? strtoupper( $code ) : self::local();
	}
}
