<?php
declare( strict_types=1 );

namespace PesaDonations\Modules\Updater;

/**
 * Self-hosted updates from a public GitHub repo using the Plugin Update
 * Checker library (Yahnis Elsts, MIT). Checks for new releases every
 * 12 hours; surfaces them through the native WP "Updates" UI.
 *
 * Release process:
 *   1. Bump PD_VERSION in pesa-donations.php
 *   2. git tag vX.Y.Z && git push --tags
 *   3. Create a GitHub release for that tag (PUC reads release zips first,
 *      falls back to the tag's source archive)
 *   4. Sites running the plugin pick it up within 12h, or immediately on
 *      a manual "Check Again" from the Updates screen
 */
class Updater {

	private const REPO_URL = 'https://github.com/RealAkram20/PesaDonations';

	public function register(): void {
		add_action( 'init', [ $this, 'boot' ] );
	}

	public function boot(): void {
		$loader = PD_PLUGIN_DIR . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $loader ) ) {
			return;
		}

		require_once $loader;

		// PUC v5 entry: factory builds the updater for a public GitHub repo.
		$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$updater = $factory::buildUpdateChecker(
			self::REPO_URL,
			PD_PLUGIN_FILE,
			'pesa-donations'   // plugin slug — must match folder name
		);

		// Prefer GitHub release ZIP assets when available; falls back to
		// the auto-generated tag source archive otherwise.
		$vcsApi = $updater->getVcsApi();
		if ( method_exists( $vcsApi, 'enableReleaseAssets' ) ) {
			$vcsApi->enableReleaseAssets();
		}
	}
}
