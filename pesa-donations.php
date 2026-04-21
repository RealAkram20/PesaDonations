<?php
/**
 * Plugin Name:       PesaDonations
 * Plugin URI:        https://github.com/RealAkram20/PesaDonations
 * Description:       Modular donation plugin for East African NGOs. PesaPal + PayPal, one-time and recurring.
 * Version:           1.0.0
 * Author:            ArmGenius (Rio Akram Miiro)
 * Author URI:        https://armgenius.com
 * Text Domain:       pesa-donations
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PD_VERSION',     '1.0.0' );
define( 'PD_PLUGIN_FILE', __FILE__ );
define( 'PD_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PD_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'PD_MIN_PHP',     '8.0' );
define( 'PD_MIN_WP',      '5.8' );

if ( version_compare( PHP_VERSION, PD_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', static function (): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required version, 2: current version */
					__( 'PesaDonations requires PHP %1$s or higher. You are running PHP %2$s.', 'pesa-donations' ),
					PD_MIN_PHP,
					PHP_VERSION
				)
			)
		);
	} );
	return;
}

require_once PD_PLUGIN_DIR . 'includes/core/class-autoloader.php';

use PesaDonations\Core\Autoloader;
use PesaDonations\Core\Plugin;
use PesaDonations\Core\Activator;
use PesaDonations\Core\Deactivator;

Autoloader::register();

register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Deactivator::class, 'deactivate' ] );

Plugin::get_instance()->run();
