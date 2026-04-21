<?php
declare( strict_types=1 );

namespace PesaDonations\Payments;

use PesaDonations\Models\Donation;

abstract class Abstract_Gateway {

	abstract public function get_id(): string;
	abstract public function get_name(): string;
	abstract public function supports_currency( string $currency ): bool;
	abstract public function init_donation( Donation $donation, array $donor_data ): array;

	public function is_enabled(): bool {
		return (bool) get_option( 'pd_gateway_' . $this->get_id() . '_enabled', true );
	}

	public function get_environment(): string {
		return (string) get_option( 'pd_' . $this->get_id() . '_environment', 'sandbox' );
	}

	public function is_sandbox(): bool {
		return 'sandbox' === $this->get_environment();
	}

	/**
	 * Returns a human-readable conversion notice if the currency needs converting.
	 */
	public function get_currency_notice( string $currency, float $amount ): string {
		return '';
	}
}
