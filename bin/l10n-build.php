<?php
/**
 * Builds languages/marsad-<code>.l10n.php from a translation JSON
 * ({"singles": {english: text}, "plurals": [[form, …], …]}) and refuses anything that
 * would show the wrong number, a broken placeholder or English to the admin.
 *
 * Usage: php bin/l10n-build.php <catalog.json> <tr-xx.json> <code>
 */

$plural_forms = array(
	'fr' => 'nplurals=2; plural=(n > 1);',
	'pt' => 'nplurals=2; plural=(n > 1);',
	'fa' => 'nplurals=2; plural=(n > 1);',
	'es' => 'nplurals=2; plural=(n != 1);',
	'it' => 'nplurals=2; plural=(n != 1);',
	'de' => 'nplurals=2; plural=(n != 1);',
	'tr' => 'nplurals=2; plural=(n != 1);',
	'ur' => 'nplurals=2; plural=(n != 1);',
	'hi' => 'nplurals=2; plural=(n != 1);',
	'ru' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	'zh' => 'nplurals=1; plural=0;',
	'ja' => 'nplurals=1; plural=0;',
	'id' => 'nplurals=1; plural=0;',
);

list( , $catalog_file, $tr_file, $code ) = $argv + array( '', '', '', '' );
$catalog = json_decode( file_get_contents( $catalog_file ), true );
$tr      = json_decode( file_get_contents( $tr_file ), true );
if ( ! isset( $plural_forms[ $code ] ) || ! is_array( $tr ) ) {
	fwrite( STDERR, "unknown language or unreadable JSON\n" );
	exit( 1 );
}
preg_match( '/nplurals=(\d)/', $plural_forms[ $code ], $m );
$nplurals = (int) $m[1];

$holders = function ( $s ) {
	preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
	$h = $m[0];
	sort( $h );
	return $h;
};
$errors   = array();
$messages = array();
foreach ( $catalog['singles'] as $en ) {
	$t = $tr['singles'][ $en ] ?? '';
	if ( '' === trim( $t ) ) {
		$errors[] = "missing: $en";
		continue;
	}
	// Unnumbered %s/%d may not move; numbered ones may. Compare as sets either way.
	if ( $holders( $en ) !== $holders( $t ) ) {
		$errors[] = "placeholders differ: $en";
		continue;
	}
	$messages[ $en ] = $t;
}
foreach ( $catalog['plurals'] as $i => $pair ) {
	$forms = $tr['plurals'][ $i ] ?? array();
	if ( count( $forms ) !== $nplurals ) {
		$errors[] = 'plural ' . $i . ': need ' . $nplurals . ' forms';
		continue;
	}
	foreach ( $forms as $f ) {
		if ( $holders( $f ) !== $holders( $pair[0] ) ) {
			$errors[] = 'plural ' . $i . ': placeholders differ';
			continue 2;
		}
	}
	$messages[ $pair[0] . "\0" . $pair[1] ] = implode( "\0", $forms );
}
if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

$out = array(
	'domain'       => 'marsad',
	'plural-forms' => $plural_forms[ $code ],
	'language'     => $code,
	'messages'     => $messages,
);
$file = dirname( __DIR__ ) . '/languages/marsad-' . $code . '.l10n.php';
file_put_contents( $file, "<?php\n// Marsad translation (" . $code . "). Built by bin/l10n-build.php.\nreturn " . var_export( $out, true ) . ";\n" );
echo $code . ': ' . count( $messages ) . " strings\n";
