<?php
/**
 * Class map autoloader. Marsad_Foo_Bar → class-marsad-foo-bar.php, looked up in
 * includes/, includes/connectors/ and includes/admin/.
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'Marsad_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		foreach ( array( '', 'connectors/', 'admin/' ) as $dir ) {
			$path = __DIR__ . '/' . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);
