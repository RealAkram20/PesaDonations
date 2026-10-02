<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

/**
 * The day's exchange rates, from ExchangeRate-API's open access feed (no key;
 * one request a day; its terms ask for the "Rates By Exchange Rate API" credit,
 * shown at checkout wherever a rate is used).
 *
 * Fetched by a daily job and stored in one option. A rate older than a week is
 * not used: a donor would be charged against a stale figure, so the checkout
 * offers only the campaign's own currency until fresh rates arrive.
 */
class Exchange_Rates {

	public const HOOK        = 'pd_refresh_exchange_rates';
	public const OPTION      = 'pd_exchange_rates';
	public const CREDIT_URL  = 'https://www.exchangerate-api.com';
	private const SOURCE     = 'https://open.er-api.com/v6/latest/USD';
	private const MAX_AGE    = 7 * DAY_IN_SECONDS;
	private const RETRY_LOCK = 'pd_fx_retry';

	/** @var array{rates: array<string, float>, updated: int, fetched: int}|null|false false = not read yet */
	private static $memo = false;

	/**
	 * Fetches today's rates. Keeps the previous table when the feed fails or
	 * answers nonsense. Never called while a donor waits.
	 */
	public static function refresh(): bool {
		$response = wp_remote_get( self::SOURCE, [ 'timeout' => 10 ] );
		$body     = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || 'success' !== ( $body['result'] ?? '' ) || ! is_array( $body['rates'] ?? null ) ) {
			Logger::error( 'Exchange rates not refreshed', [
				'error' => is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ),
			] );
			set_transient( self::RETRY_LOCK, 1, HOUR_IN_SECONDS );
			return false;
		}

		$rates = [ 'USD' => 1.0 ];
		foreach ( Currencies::codes() as $code ) {
			$value = $body['rates'][ $code ] ?? null;
			if ( is_numeric( $value ) && (float) $value > 0 ) {
				$rates[ $code ] = (float) $value;
			}
		}
		// Every local currency must be there, or nothing can be converted to it.
		if ( ! isset( $rates[ Currencies::local() ] ) ) {
			Logger::error( 'Exchange rates missing the local currency', [ 'currency' => Currencies::local() ] );
			return false;
		}

		$table = [
			'rates'   => $rates,
			'updated' => (int) ( $body['time_last_update_unix'] ?? time() ),
			'fetched' => time(),
		];
		update_option( self::OPTION, $table, false );
		self::$memo = $table;
		delete_transient( self::RETRY_LOCK );
		return true;
	}

	/** The stored table when it is fresh enough to charge against, else null. */
	public static function table(): ?array {
		if ( false === self::$memo ) {
			$stored     = get_option( self::OPTION );
			self::$memo = is_array( $stored ) && is_array( $stored['rates'] ?? null ) ? $stored : null;
		}
		if ( ! self::$memo || time() - (int) self::$memo['updated'] > self::MAX_AGE ) {
			self::schedule_catch_up();
			return null;
		}
		return self::$memo;
	}

	/** Missing or stale: ask the background job to try now (at most once an hour). */
	private static function schedule_catch_up(): void {
		if ( ! get_transient( self::RETRY_LOCK ) ) {
			set_transient( self::RETRY_LOCK, 1, HOUR_IN_SECONDS );
			// The same hook as the daily run, once, now. WordPress drops it if a run is due within ten minutes anyway.
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	public static function available(): bool {
		return null !== self::table();
	}

	/** When the source last updated its rates (Unix time), or 0. */
	public static function updated_at(): int {
		$table = self::table();
		return $table ? (int) $table['updated'] : 0;
	}

	/** Units of $to per one unit of $from, or null when either is not available. */
	public static function rate( string $from, string $to ): ?float {
		$from = strtoupper( $from );
		$to   = strtoupper( $to );
		if ( $from === $to ) {
			return 1.0;
		}
		$table = self::table();
		if ( ! $table || empty( $table['rates'][ $from ] ) || empty( $table['rates'][ $to ] ) ) {
			return null;
		}
		return (float) $table['rates'][ $to ] / (float) $table['rates'][ $from ];
	}

	public static function has( string $code ): bool {
		return null !== self::rate( $code, 'USD' );
	}

	/**
	 * Per-USD rates for these codes, for the checkout's live conversion.
	 *
	 * @param string[] $codes
	 * @return array<string, float>
	 */
	public static function for_codes( array $codes ): array {
		$table = self::table();
		if ( ! $table ) {
			return [];
		}
		return array_intersect_key( $table['rates'], array_flip( array_map( 'strtoupper', $codes ) ) );
	}
}
