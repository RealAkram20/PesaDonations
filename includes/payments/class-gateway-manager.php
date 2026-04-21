<?php
declare( strict_types=1 );

namespace PesaDonations\Payments;

use PesaDonations\Models\Campaign;

class Gateway_Manager {

	private static array $gateways = [];

	public static function register( Abstract_Gateway $gateway ): void {
		self::$gateways[ $gateway->get_id() ] = $gateway;
	}

	public static function get( string $id ): ?Abstract_Gateway {
		return self::$gateways[ $id ] ?? null;
	}

	public static function get_all(): array {
		return self::$gateways;
	}

	public static function get_available( Campaign $campaign, string $currency ): array {
		$available = [];

		foreach ( self::$gateways as $id => $gateway ) {
			if ( ! $gateway->is_enabled() ) {
				continue;
			}
			if ( $gateway->supports_currency( $currency ) ) {
				$available[ $id ] = [
					'gateway' => $gateway,
					'notice'  => '',
				];
			} else {
				$notice = $gateway->get_currency_notice( $currency, 0 );
				if ( $notice ) {
					$available[ $id ] = [
						'gateway' => $gateway,
						'notice'  => $notice,
					];
				}
			}
		}

		return $available;
	}
}
