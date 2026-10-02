<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

use PesaDonations\Utils\Logger;

/**
 * A second installed copy of PesaDonations, in another folder.
 *
 * WordPress knows a plugin by its folder, so a zip whose folder differs from
 * the installed one (a GitHub "Download ZIP" unpacks as PesaDonations-main/)
 * installs beside it. Both copies share the same tables. Before 1.2, the
 * uninstall script dropped those tables unconditionally, so deleting the old
 * copy from the Plugins screen would erase every donation the new copy uses.
 *
 * While this copy is active: it names the other copy on the Plugins screen and
 * the Dashboard, and when the other copy is deleted it switches off that copy's
 * uninstall script first (or stops the deletion if it cannot).
 */
class Duplicate_Guard {

	public function register(): void {
		// Not admin-only: WP-CLI's "plugin uninstall" goes through the same hook.
		add_action( 'pre_uninstall_plugin', [ $this, 'disarm_other_uninstall' ], 10, 1 );
		add_action( 'load-plugins.php', [ $this, 'watch' ] );
		add_action( 'load-index.php', [ $this, 'watch' ] );
	}

	/**
	 * Other installed copies: a pesa-donations.php in another folder whose
	 * header names this plugin.
	 *
	 * @return array<string, array> plugin basename => header data
	 */
	public static function other_copies(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$mine  = plugin_basename( PD_PLUGIN_FILE );
		$found = [];
		foreach ( get_plugins() as $file => $data ) {
			if ( $file !== $mine && self::is_pesadonations( (string) $file, (array) $data ) ) {
				$found[ $file ] = $data;
			}
		}
		return $found;
	}

	private static function is_pesadonations( string $file, array $data ): bool {
		return 'pesa-donations.php' === basename( $file ) && 'PesaDonations' === ( $data['Name'] ?? '' );
	}

	public function watch(): void {
		$others = self::other_copies();
		if ( ! $others || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		add_action( 'admin_notices', static function () use ( $others ): void {
			$list = [];
			foreach ( $others as $file => $data ) {
				$list[] = sprintf(
					'%s (%s%s)',
					$file,
					$data['Version'] ?? '?',
					is_plugin_active( $file ) ? ', ' . __( 'active', 'pesa-donations' ) : ''
				);
			}
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Another copy of PesaDonations is installed:', 'pesa-donations' ),
				esc_html( implode( '; ', $list ) . '. ' . __( 'Deactivate it, then delete it. Its uninstall script is switched off first, so the donation records stay.', 'pesa-donations' ) )
			);
		} );
	}

	/**
	 * Runs just before WordPress includes a plugin's uninstall.php. For another
	 * copy of this plugin, rename that file so WordPress finds none to run.
	 *
	 * @param string $plugin Basename of the plugin being uninstalled.
	 */
	public function disarm_other_uninstall( $plugin ): void {
		$plugin = (string) $plugin;
		if ( ! isset( self::other_copies()[ $plugin ] ) ) {
			return;
		}
		$script = WP_PLUGIN_DIR . '/' . dirname( $plugin ) . '/uninstall.php';
		if ( ! file_exists( $script ) ) {
			return;
		}
		if ( @rename( $script, $script . '.disabled-by-pesadonations' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
			Logger::info( 'Uninstall script of another PesaDonations copy switched off before deletion', [ 'plugin' => $plugin ] );
			return;
		}
		// Could not rename: stop here rather than let it erase the shared tables.
		wp_die(
			esc_html( sprintf(
				/* translators: %s: plugin folder */
				__( 'This copy of PesaDonations (%s) was not deleted: its uninstall script would erase every donation, and it could not be switched off. Remove the folder with your host\'s file manager instead.', 'pesa-donations' ),
				dirname( $plugin )
			) ),
			esc_html__( 'PesaDonations', 'pesa-donations' ),
			[ 'response' => 409, 'back_link' => true ]
		);
	}
}
