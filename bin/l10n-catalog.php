<?php
// Every translatable string (usage: php bin/l10n-catalog.php . > catalog.json) in the plugin: singles and _n() plural pairs, with where they appear.
$root = $argv[1];
$singles = array(); $plurals = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) );
$files = array( $root . '/marsad.php' );
foreach ( $it as $f ) { if ( substr( $f, -4 ) === '.php' ) $files[] = (string) $f; }
foreach ( $files as $file ) {
	$t = token_get_all( file_get_contents( $file ) );
	for ( $i = 0; $i < count( $t ); $i++ ) {
		if ( ! is_array( $t[$i] ) || T_STRING !== $t[$i][0] ) continue;
		$fn = $t[$i][1];
		if ( ! in_array( $fn, array( '__', 'esc_html__', 'esc_attr__', '_e', 'esc_html_e', '_n' ), true ) ) continue;
		$j = $i + 1; while ( is_array( $t[$j] ) && T_WHITESPACE === $t[$j][0] ) $j++;
		if ( '(' !== $t[$j] ) continue;
		$args = array(); $j++;
		while ( count( $args ) < 2 ) {
			while ( is_array( $t[$j] ) && T_WHITESPACE === $t[$j][0] ) $j++;
			if ( ! is_array( $t[$j] ) || T_CONSTANT_ENCAPSED_STRING !== $t[$j][0] ) break;
			$args[] = eval( 'return ' . $t[$j][1] . ';' ); $j++;
			while ( is_array( $t[$j] ) && T_WHITESPACE === $t[$j][0] ) $j++;
			if ( ',' !== $t[$j] ) break; $j++;
		}
		if ( '_n' === $fn && 2 === count( $args ) ) $plurals[ $args[0] . "\0" . $args[1] ] = $args;
		elseif ( '_n' !== $fn && $args ) $singles[ $args[0] ] = 1;
	}
}
echo json_encode( array( 'singles' => array_keys( $singles ), 'plurals' => array_values( $plurals ) ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
