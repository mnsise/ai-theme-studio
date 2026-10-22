<?php
/**
 * PSR-4 autoloader for the AIThemeStudio namespace.
 *
 * @package AIThemeStudio
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'AIThemeStudio\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
