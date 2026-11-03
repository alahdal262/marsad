<?php
/**
 * The Marsad Connect relay (server/connect): the whole sign-in round trip with a fake Google.
 */

require_once dirname( __DIR__ ) . '/server/connect/lib.php';

function marsad_relay_cfg() {
	$dir = sys_get_temp_dir() . '/marsad-relay-' . getmypid();
	foreach ( array_merge( (array) glob( $dir . '/*.json' ), (array) glob( $dir . '/*.cnt' ) ) as $f ) {
		unlink( $f );
	}
	return array( 'client_id' => 'cid', 'client_secret' => 'csecret', 'redirect_uri' => 'https://relay.test/connect/callback.php', 'data_dir' => $dir );
}

function marsad_relay_query( $location ) {
	parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $q );
	return $q;
}

function test_relay_round_trip_hands_tokens_only_to_the_site_that_started() {
	$cfg       = marsad_relay_cfg();
	$verifier  = mc_random() . mc_random();
	$challenge = mc_b64url( hash( 'sha256', $verifier, true ) );
	$return    = 'https://news.example/wp-admin/admin-post.php';

	list( $st, $h ) = mc_start( array( 'return' => $return, 'challenge' => $challenge ), $cfg );
	same( 302, $st );
	$g = marsad_relay_query( $h['Location'] );
	same( 'cid', $g['client_id'] );
	same( 'offline', $g['access_type'] );
	check( false !== strpos( $g['scope'], 'analytics.readonly' ) );

	$sent  = array();
	$email = mc_b64url( json_encode( array( 'email' => 'owner@example.com' ) ) );
	$post  = function ( $url, $fields ) use ( &$sent, $email ) {
		$sent[] = $fields;
		return array( 200, json_encode( array( 'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3599, 'scope' => 'x', 'id_token' => 'h.' . $email . '.s' ) ) );
	};
	list( $st, $h ) = mc_callback( array( 'code' => 'gcode', 'state' => $g['state'] ), $cfg, $post );
	same( 302, $st );
	same( 'csecret', $sent[0]['client_secret'] );
	check( 0 === strpos( $h['Location'], $return . '?' ), 'back to the site' );
	$back = marsad_relay_query( $h['Location'] );
	same( 'marsad_google_return', $back['action'] );
	check( false === strpos( $h['Location'], 'RT' ), 'refresh token never in a URL' );

	list( $st ) = mc_claim( array( 'code' => $back['marsad_code'], 'verifier' => 'wrong' ), $cfg );
	same( 403, $st, 'wrong verifier refused' );

	// A refused claim consumed the code too: an attacker gets one guess, the site starts over.
	list( $st ) = mc_claim( array( 'code' => $back['marsad_code'], 'verifier' => $verifier ), $cfg );
	same( 400, $st );
}

function test_relay_claim_with_right_verifier_returns_tokens_once() {
	$cfg       = marsad_relay_cfg();
	$verifier  = mc_random() . mc_random();
	$challenge = mc_b64url( hash( 'sha256', $verifier, true ) );
	list( , $h ) = mc_start( array( 'return' => 'https://a.example/wp-admin/admin-post.php', 'challenge' => $challenge ), $cfg );
	$state     = marsad_relay_query( $h['Location'] )['state'];
	$post      = function () { return array( 200, json_encode( array( 'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3599 ) ) ); };
	list( , $h ) = mc_callback( array( 'code' => 'c', 'state' => $state ), $cfg, $post );
	$code      = marsad_relay_query( $h['Location'] )['marsad_code'];

	list( $st, , $body ) = mc_claim( array( 'code' => $code, 'verifier' => $verifier ), $cfg );
	same( 200, $st );
	same( 'RT', json_decode( $body, true )['refresh_token'] );
	list( $st ) = mc_claim( array( 'code' => $code, 'verifier' => $verifier ), $cfg );
	same( 400, $st, 'second claim fails' );
	same( array(), glob( $cfg['data_dir'] . '/*.json' ), 'nothing left on disk' );
}

function test_relay_rejects_bad_return_addresses() {
	foreach ( array( 'http://evil.example/wp-admin/admin-post.php', 'https://evil.example/steal', 'https://u:p@a.example/wp-admin/admin-post.php', 'javascript:alert(1)' ) as $bad ) {
		check( ! mc_valid_return( $bad ), "refuses $bad" );
	}
	check( mc_valid_return( 'https://a.example/blog/wp-admin/admin-post.php' ) );
	check( mc_valid_return( 'http://localhost/wp-admin/admin-post.php' ) );
	list( $st ) = mc_start( array( 'return' => 'https://evil.example/x', 'challenge' => str_repeat( 'a', 43 ) ), marsad_relay_cfg() );
	same( 400, $st );
}

function test_relay_denied_consent_goes_back_with_the_reason() {
	$cfg = marsad_relay_cfg();
	list( , $h ) = mc_start( array( 'return' => 'https://a.example/wp-admin/admin-post.php', 'challenge' => str_repeat( 'b', 43 ) ), $cfg );
	$state = marsad_relay_query( $h['Location'] )['state'];
	list( $st, $h ) = mc_callback( array( 'error' => 'access_denied', 'state' => $state ), $cfg, function () { throw new Exception( 'no call' ); } );
	same( 302, $st );
	same( 'access_denied', marsad_relay_query( $h['Location'] )['marsad_error'] );
}

function test_relay_token_refresh_passes_googles_answer_through() {
	$cfg  = marsad_relay_cfg();
	$sent = array();
	list( $st, , $body ) = mc_token(
		array( 'refresh_token' => 'RT' ),
		$cfg,
		function ( $url, $f ) use ( &$sent ) {
			$sent = $f;
			return array( 200, json_encode( array( 'access_token' => 'NEW', 'expires_in' => 3599 ) ) );
		}
	);
	same( 200, $st );
	same( 'NEW', json_decode( $body, true )['access_token'] );
	same( 'refresh_token', $sent['grant_type'] );
	list( $st, , $body ) = mc_token( array( 'refresh_token' => 'RT' ), $cfg, function () { return array( 400, json_encode( array( 'error' => 'invalid_grant' ) ) ); } );
	same( 400, $st );
	same( 'invalid_grant', json_decode( $body, true )['error'] );
}
