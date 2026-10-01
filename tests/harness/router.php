<?php
if ( 'cli-server' !== PHP_SAPI ) { exit; } // php -S 127.0.0.1:8099 -t D:\pdwp\site router.php
// php -S router: real files are served as-is, everything else goes to WordPress.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . '/site' . $path;
if ( $path !== '/' && is_file( $file ) && substr( $file, -4 ) !== '.php' ) {
	return false;
}
if ( is_file( $file ) && substr( $file, -4 ) === '.php' ) {
	chdir( dirname( $file ) );
	require $file;
	return;
}
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) {
	chdir( rtrim( $file, '/' ) );
	require rtrim( $file, '/' ) . '/index.php';
	return;
}
chdir( __DIR__ . '/site' );
require __DIR__ . '/site/index.php';
