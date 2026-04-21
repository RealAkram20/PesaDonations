<?php
declare( strict_types=1 );

namespace PesaDonations\Core;

class Autoloader {

	/**
	 * Maps top-level sub-namespaces to directories under includes/.
	 * Needed because 'Public' is a reserved PHP keyword.
	 */
	private static array $dir_map = [
		'Frontend' => 'public',
	];

	public static function register(): void {
		spl_autoload_register( [ self::class, 'autoload' ] );
	}

	public static function autoload( string $class ): void {
		if ( ! str_starts_with( $class, 'PesaDonations\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'PesaDonations\\' ) );
		$parts    = explode( '\\', $relative );
		$class_name = array_pop( $parts );

		// Map namespace segment to directory if a custom map exists.
		$dirs = array_map( static function ( string $segment ): string {
			return self::$dir_map[ $segment ] ?? strtolower( str_replace( '_', '-', $segment ) );
		}, $parts );

		$sub_dir   = implode( '/', $dirs );
		$file_name = strtolower( str_replace( '_', '-', $class_name ) );
		$prefix    = str_starts_with( $file_name, 'abstract-' ) ? '' : 'class-';

		$path = PD_PLUGIN_DIR . 'includes/'
			. ( $sub_dir ? $sub_dir . '/' : '' )
			. $prefix . $file_name . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
