<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

class Sanitizer {

	public static function amount( mixed $value ): float {
		$v = preg_replace( '/[^0-9.]/', '', (string) $value );
		// Strip all but the first dot so "12.34.56" doesn't silently truncate
		// to 12.34 — the float cast accepts it but the input is malformed.
		$v = preg_replace( '/\.(?=.*\.)/', '', (string) $v );
		return (float) $v;
	}

	public static function currency( mixed $value ): string {
		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
	}

	public static function gateway( mixed $value ): string {
		// PayPal is not yet implemented (see Settings → PayPal tab notice).
		// Only PesaPal is exposed to public-facing code paths.
		$allowed = apply_filters( 'pd_allowed_public_gateways', [ 'pesapal' ] );
		$val     = strtolower( sanitize_key( (string) $value ) );
		return in_array( $val, $allowed, true ) ? $val : '';
	}

	public static function phone( mixed $value ): string {
		return preg_replace( '/[^0-9+\-\s]/', '', sanitize_text_field( (string) $value ) );
	}

	public static function merchant_reference( string $campaign_id, string $donation_id ): string {
		$rand = strtoupper( wp_generate_password( 6, false ) );
		return "PD-{$campaign_id}-{$donation_id}-{$rand}";
	}
}
