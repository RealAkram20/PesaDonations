<?php
declare( strict_types=1 );

namespace PesaDonations\Utils;

/**
 * Daily log files in uploads/pesa-donations-logs/.
 *
 * The directory is guarded by an .htaccess (Apache 2.2 and 2.4) and an
 * index.php, recreated whenever missing, and every file name carries a
 * site-specific hash: nginx and OpenLiteSpeed ignore .htaccess, and a
 * guessable "pd-2026-09-30.log" would be downloadable there. Files older than
 * the retention setting are deleted by the daily purge.
 */
class Logger {

	private static ?self $instance = null;
	private string $log_dir;

	private function __construct() {
		$this->log_dir = WP_CONTENT_DIR . '/uploads/pesa-donations-logs/';
		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}
		if ( ! file_exists( $this->log_dir . 'index.php' ) ) {
			file_put_contents( $this->log_dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			file_put_contents( $this->log_dir . 'index.php', "<?php\n// Silence.\n" );
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

	/** Deletes log files older than $days. */
	public static function purge_older_than( int $days ): void {
		$dir = self::get_instance()->log_dir;
		foreach ( glob( $dir . 'pd-*.log' ) ?: [] as $file ) {
			if ( filemtime( $file ) < time() - $days * DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	private function write( string $level, string $message, array $context ): void {
		$file = $this->log_dir . 'pd-' . gmdate( 'Y-m-d' ) . '-' . substr( wp_hash( 'pd-log-' . gmdate( 'Y-m-d' ) ), 0, 12 ) . '.log';
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
