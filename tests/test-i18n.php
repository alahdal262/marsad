<?php

function marsad_test_catalog() {
	static $c = null;
	if ( null === $c ) {
		$c = json_decode( shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/l10n-catalog.php' ) . ' ' . escapeshellarg( dirname( __DIR__ ) ) ), true );
	}
	return $c;
}

function test_every_language_translates_every_string_with_the_same_placeholders() {
	$catalog = marsad_test_catalog();
	check( count( $catalog['singles'] ) > 300, 'catalog read' );
	$files = glob( dirname( __DIR__ ) . '/languages/marsad-*.l10n.php' );
	check( count( $files ) >= 14, 'fourteen languages besides English, got ' . count( $files ) );
	$holders = function ( $s ) {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
		sort( $m[0] );
		return $m[0];
	};
	foreach ( $files as $f ) {
		$code = basename( $f, '.l10n.php' );
		$t    = ( require $f )['messages'];
		foreach ( $catalog['singles'] as $en ) {
			check( isset( $t[ $en ] ) && '' !== trim( $t[ $en ] ), "$code lacks: $en" );
			check( $holders( $en ) === $holders( $t[ $en ] ), "$code placeholders: $en" );
		}
		foreach ( $catalog['plurals'] as $p ) {
			// WordPress looks up "singular\0plural" first, then the singular alone.
			check( isset( $t[ $p[0] . "\0" . $p[1] ] ) || isset( $t[ $p[0] ] ), "$code lacks plural: {$p[0]}" );
		}
	}
}
