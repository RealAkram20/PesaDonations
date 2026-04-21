<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

class Sanitizer {

	public static function amount( mixed $value ): float {
		return (float) preg_replace( '/[^0-9.]/', '', (string) $value );
	}

	public static function currency( mixed $value ): string {
		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
	}

	public static function gateway( mixed $value ): string {
		$allowed = [ 'pesapal', 'paypal' ];
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
