<?php
/**
 * Runs every tests/test-*.php. A test is a function named test_*; it fails by throwing.
 * Usage: php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

// A warning or notice in plugin code is a bug: fail the test that triggered it.
set_error_handler(
	function ( $no, $msg, $file, $line ) {
		throw new ErrorException( $msg . ' at ' . basename( $file ) . ':' . $line, 0, $no, $file, $line );
	}
);

function check( $cond, $msg = 'assertion failed' ) {
	if ( ! $cond ) {
		throw new Exception( $msg );
	}
}

function same( $expected, $actual, $msg = '' ) {
	if ( $expected !== $actual ) {
		throw new Exception( ( $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function near( $expected, $actual, $msg = '', $eps = 1e-6 ) {
	if ( null === $actual || abs( $expected - $actual ) > $eps ) {
		throw new Exception( ( $msg ? $msg . ': ' : '' ) . "expected ~$expected, got " . var_export( $actual, true ) );
	}
}

function throws( callable $fn, $contains = '' ) {
	try {
		$fn();
	} catch ( Exception $e ) {
		if ( '' !== $contains && false === strpos( $e->getMessage(), $contains ) ) {
			throw new Exception( "threw, but message lacks '$contains': " . $e->getMessage() );
		}
		return;
	}
	throw new Exception( 'expected an exception' );
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

$pass = 0;
$fail = 0;
foreach ( get_defined_functions()['user'] as $fn ) {
	if ( 0 !== strpos( $fn, 'test_' ) ) {
		continue;
	}
	marsad_test_reset();
	try {
		$fn();
		$pass++;
	} catch ( Throwable $e ) {
		$fail++;
		fwrite( STDERR, "FAIL $fn: " . $e->getMessage() . "\n" );
	}
}
echo "$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
