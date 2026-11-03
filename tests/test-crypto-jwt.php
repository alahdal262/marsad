<?php

function marsad_test_key( $type ) {
	$cfg = 'ec' === $type
		? array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' )
		: array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 );
	$k   = openssl_pkey_new( $cfg );
	openssl_pkey_export( $k, $pem );
	return array( $pem, openssl_pkey_get_details( $k )['key'] );
}

function test_crypto_roundtrip_and_tamper() {
	$enc = Marsad_Crypto::encrypt( 'secret-token' );
	check( 0 === strpos( $enc, 'mz1:' ), 'prefixed' );
	check( false === strpos( $enc, 'secret-token' ), 'not plain' );
	same( 'secret-token', Marsad_Crypto::decrypt( $enc ) );
	same( 'legacy-plain', Marsad_Crypto::decrypt( 'legacy-plain' ), 'unencrypted values pass through' );
	$raw    = base64_decode( substr( $enc, 4 ) );
	$raw[30] = chr( ord( $raw[30] ) ^ 1 );
	same( '', Marsad_Crypto::decrypt( 'mz1:' . base64_encode( $raw ) ), 'tampered ciphertext is rejected' );
	same( '', Marsad_Crypto::encrypt( '' ) );
}

function test_rs256_signature_verifies() {
	list( $pem, $pub ) = marsad_test_key( 'rsa' );
	$jwt               = Marsad_Jwt::sign( array( 'alg' => 'RS256', 'typ' => 'JWT' ), array( 'a' => 1 ), $pem );
	list( $h, $c, $s ) = explode( '.', $jwt );
	same( array( 'a' => 1 ), json_decode( Marsad_Jwt::b64url_decode( $c ), true ) );
	same( 1, openssl_verify( "$h.$c", Marsad_Jwt::b64url_decode( $s ), $pub, OPENSSL_ALGO_SHA256 ) );
}

function marsad_jose_to_der( $sig ) {
	$enc = function ( $i ) {
		$i = ltrim( $i, "\x00" );
		if ( ord( $i[0] ) & 0x80 ) {
			$i = "\x00" . $i;
		}
		return "\x02" . chr( strlen( $i ) ) . $i;
	};
	$body = $enc( substr( $sig, 0, 32 ) ) . $enc( substr( $sig, 32 ) );
	return "\x30" . chr( strlen( $body ) ) . $body;
}

function test_es256_signature_is_64_bytes_and_verifies() {
	list( $pem, $pub ) = marsad_test_key( 'ec' );
	for ( $i = 0; $i < 5; $i++ ) { // several runs hit r/s values with leading 0x00 / high bit
		$jwt               = Marsad_Jwt::sign( array( 'alg' => 'ES256', 'kid' => 'K' ), array( 'n' => $i ), $pem );
		list( $h, $c, $s ) = explode( '.', $jwt );
		$sig               = Marsad_Jwt::b64url_decode( $s );
		same( 64, strlen( $sig ) );
		same( 1, openssl_verify( "$h.$c", marsad_jose_to_der( $sig ), $pub, OPENSSL_ALGO_SHA256 ) );
	}
}

function test_bad_key_gives_readable_error() {
	throws(
		function () {
			Marsad_Jwt::sign( array( 'alg' => 'RS256' ), array(), 'not a key' );
		},
		'BEGIN/END'
	);
}

function test_google_token_is_requested_once_then_cached() {
	list( $pem ) = marsad_test_key( 'rsa' );
	$sa          = json_encode( array( 'client_email' => 'sa@p.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ) );
	$calls       = 0;
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$calls ) {
		$calls++;
		same( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $args['body']['grant_type'] );
		same( 3, count( explode( '.', $args['body']['assertion'] ) ) );
		return array( 'code' => 200, 'body' => '{"access_token":"tok123"}', 'headers' => array() );
	};
	same( 'tok123', Marsad_Google_Auth::token( $sa, 'scope-a' ) );
	same( 'tok123', Marsad_Google_Auth::token( $sa, 'scope-a' ) );
	same( 1, $calls );
	same( 'sa@p.iam.gserviceaccount.com', Marsad_Google_Auth::client_email( $sa ) );
}

function test_google_errors_explain_disabled_api() {
	throws(
		function () {
			Marsad_Google_Auth::parse_key( '{"type":"other"}' );
		},
		'service account'
	);
	$e = new Marsad_Exception( 'HTTP 403: Google Analytics Data API has not been used in project 1 before or it is disabled.' );
	check( false !== strpos( Marsad_Google_Auth::explain( $e, 'GA', 'analyticsdata.googleapis.com' ), 'analyticsdata.googleapis.com' ) );
	same( 'other', Marsad_Google_Auth::explain( new Marsad_Exception( 'other' ), 'GA', 'x' ) );
}
