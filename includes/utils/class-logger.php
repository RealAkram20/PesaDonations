<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

class Logger {

	private static ?self $instance = null;
	private string $log_dir;

	private function __construct() {
		// Use wp_upload_dir() so multisite + custom UPLOADS constants are
		// respected. WP_CONTENT_DIR . '/uploads/' is wrong on those setups.
		$uploads = wp_upload_dir( null, false );
		$base    = ! empty( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		$this->log_dir = trailingslashit( $base ) . 'pesa-donations-logs/';

		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
			file_put_contents( $this->log_dir . '.htaccess', 'deny from all' );
			file_put_contents( $this->log_dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function info( string $message, array $context = [] ): void {
		self::get_instance()->write( 'INFO', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::get_instance()->write( 'ERROR', $message, $context );
	}

	public static function debug( string $message, array $context = [] ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::get_instance()->write( 'DEBUG', $message, $context );
		}
	}

	private function write( string $level, string $message, array $context ): void {
		$file = $this->log_dir . 'pd-' . gmdate( 'Y-m-d' ) . '.log';
		$line = sprintf(
			'[%s] [%s] %s %s' . PHP_EOL,
			gmdate( 'Y-m-d H:i:s' ),
			$level,
			$message,
			$context ? wp_json_encode( $context ) : ''
		);
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}
}
