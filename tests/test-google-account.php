<?php

function marsad_test_connect_google( $scope = 'analytics.readonly' ) {
	$start = Marsad_Google_Account::connect_url( 'https://example.test/wp-admin/admin-post.php' );
	parse_str( parse_url( $start, PHP_URL_QUERY ), $q );
	$seen = array();
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$seen, $scope ) {
		$seen[] = array( $url, $args['body'] ?? null );
		return array( 'code' => 200, 'body' => json_encode( array( 'refresh_token' => 'RT', 'access_token' => 'AT1', 'expires_in' => 3599, 'email' => 'owner@example.com', 'scope' => $scope ) ), 'headers' => array() );
	};
	Marsad_Google_Account::finish( 'HANDOVER' );
	return array( $q, $seen );
}

function test_connect_url_carries_challenge_of_a_remembered_verifier() {
	$url = Marsad_Google_Account::connect_url( 'https://example.test/wp-admin/admin-post.php' );
	check( 0 === strpos( $url, 'https://form.yementv.tv/connect/start.php?' ) );
	parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
	$verifier = get_transient( Marsad_Google_Account::VERIFIER . '1' );
	same( Marsad_Jwt::b64url( hash( 'sha256', $verifier, true ) ), $q['challenge'] );
	check( false === strpos( $url, $verifier ), 'the verifier never leaves the server' );
}

function test_finish_claims_with_verifier_and_stores_refresh_token_encrypted() {
	list( , $seen ) = marsad_test_connect_google();
	same( 'https://form.yementv.tv/connect/claim.php', $seen[0][0] );
	same( 'HANDOVER', $seen[0][1]['code'] );
	check( Marsad_Google_Account::connected() );
	same( 'owner@example.com', Marsad_Google_Account::email() );
	check( 0 === strpos( get_option( Marsad_Google_Account::OPT )['refresh'], 'mz1:' ), 'refresh token encrypted at rest' );
	same( 'AT1', Marsad_Google_Account::token(), 'first access token reused' );
	throws( function () { Marsad_Google_Account::finish( 'AGAIN' ); }, 'took too long' );
}

function test_token_refreshes_through_relay_and_explains_revoked_access() {
	marsad_test_connect_google();
	delete_transient( Marsad_Google_Account::TOKEN );
	$calls = 0;
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$calls ) {
		$calls++;
		same( 'https://form.yementv.tv/connect/token.php', $url );
		same( 'RT', $args['body']['refresh_token'] );
		return array( 'code' => 200, 'body' => '{"access_token":"AT2","expires_in":3599}', 'headers' => array() );
	};
	same( 'AT2', Marsad_Google_Account::token() );
	same( 'AT2', Marsad_Google_Account::token() );
	same( 1, $calls, 'cached' );

	delete_transient( Marsad_Google_Account::TOKEN );
	Marsad_Http::$mock = function () { return array( 'code' => 400, 'body' => '{"error":"invalid_grant"}', 'headers' => array() ); };
	throws( function () { Marsad_Google_Account::token(); }, 'Connect with Google" again' );
}

function test_bearer_prefers_a_connectors_own_service_account() {
	marsad_test_connect_google();
	same( array( 'Authorization' => 'Bearer AT1' ), Marsad_Google_Account::bearer( '', 'scope' ) );
	throws( function () { Marsad_Google_Account::bearer( '{"not":"a key"}', 'scope' ); }, 'service account' );
}

function test_disconnect_revokes_and_forgets() {
	marsad_test_connect_google();
	$revoked = '';
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$revoked ) {
		$revoked = $url . '|' . $args['body']['token'];
		return array( 'code' => 200, 'body' => '', 'headers' => array() );
	};
	Marsad_Google_Account::disconnect();
	same( 'https://oauth2.googleapis.com/revoke|RT', $revoked );
	check( ! Marsad_Google_Account::connected() );
	throws( function () { Marsad_Google_Account::token(); }, 'not connected' );
}

function test_pick_lists_from_google_answers() {
	same(
		array( '256087272' => 'Yemen TV › yementv.tv (256087272)' ),
		Marsad_Connector_Ga4::properties( array( 'accountSummaries' => array( array( 'displayName' => 'Yemen TV', 'propertySummaries' => array( array( 'property' => 'properties/256087272', 'displayName' => 'yementv.tv' ) ) ) ) ) )
	);
	same( null, Marsad_Connector_Ga4::properties( array() ) );
	same(
		array( 'https://yementv.tv/' => 'https://yementv.tv/', 'sc-domain:yementv.tv' => 'sc-domain:yementv.tv' ),
		Marsad_Connector_Search_Console::sites( array( 'siteEntry' => array( array( 'siteUrl' => 'sc-domain:yementv.tv', 'permissionLevel' => 'siteOwner' ), array( 'siteUrl' => 'https://yementv.tv/', 'permissionLevel' => 'siteFullUser' ), array( 'siteUrl' => 'https://other.example/', 'permissionLevel' => 'siteUnverifiedUser' ) ) ) )
	);
}

function test_google_sources_count_as_configured_with_the_account_alone() {
	$ga = new Marsad_Connector_Ga4();
	$ga->set_values( array( 'property_id' => '123' ) );
	check( ! $ga->is_configured(), 'no account, no key' );
	$yt = new Marsad_Connector_Youtube();
	check( ! $yt->is_configured() );
	marsad_test_connect_google();
	check( $ga->is_configured() );
	check( $yt->is_configured(), 'own channel needs nothing else' );
}

function test_youtube_uses_own_channel_through_the_account() {
	marsad_test_connect_google();
	$yt   = new Marsad_Connector_Youtube();
	$seen = array();
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$seen ) {
		$seen[] = array( $url, $args['headers']['Authorization'] ?? '' );
		return array( 'code' => 200, 'body' => '{"items":[{"statistics":{"subscriberCount":"5"}}]}', 'headers' => array() );
	};
	$rows = $yt->fetch( 'a', 'b' );
	check( false !== strpos( $seen[0][0], 'mine=true' ) && false === strpos( $seen[0][0], 'key=' ) );
	same( 'Bearer AT1', $seen[0][1] );
	near( 5, $rows[0]['value'] );
}

function test_connect_button_only_when_the_relay_can_sign_in() {
	Marsad_Http::$mock = function ( $m, $url ) {
		same( 'https://form.yementv.tv/connect/status.php', $url );
		return array( 'code' => 200, 'body' => '{"google":false,"youtube":false}', 'headers' => array() );
	};
	check( ! Marsad_Google_Account::relay_ready( 'google' ) );
	$h = marsad_render_connections();
	check( false === strpos( $h, 'value="marsad_google_connect"' ), 'no dead-end button' );
	check( false !== strpos( $h, 'service account' ), 'offers the way that works today' );

	delete_transient( 'marsad_relay_status' );
	Marsad_Http::$mock = function () { return array( 'code' => 200, 'body' => '{"google":true,"youtube":true}', 'headers' => array() ); };
	check( Marsad_Google_Account::relay_ready( 'google' ) );
	check( false !== strpos( marsad_render_connections(), 'value="marsad_google_connect"' ) );
}

function test_relay_status_reports_what_is_configured() {
	$cfg = marsad_relay_cfg();
	list( $st, , $body ) = mc_status( $cfg );
	same( 200, $st );
	same( array( 'google' => true, 'youtube' => false ), json_decode( $body, true ) );
	list( , , $body ) = mc_status( array( 'client_id' => '', 'client_secret' => '' ) + $cfg );
	same( false, json_decode( $body, true )['google'] );
}
