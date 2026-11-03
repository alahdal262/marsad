<?php

function marsad_meta_mock( &$seen ) {
	return function ( $m, $url, $args ) use ( &$seen ) {
		$seen[] = $url;
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$path = parse_url( $url, PHP_URL_PATH );
		if ( false !== strpos( $path, '/me/accounts' ) ) {
			if ( 'USER' !== $q['access_token'] ) {
				return array( 'code' => 400, 'body' => '{"error":{"message":"(#100) Tried accessing nonexisting field (accounts) on node type (Page)"}}', 'headers' => array() );
			}
			return array( 'code' => 200, 'body' => json_encode( array( 'data' => array( array( 'id' => '11', 'name' => 'Yemen TV', 'access_token' => 'PAGE11', 'instagram_business_account' => array( 'id' => '99', 'username' => 'yementv' ) ) ) ) ), 'headers' => array() );
		}
		if ( preg_match( '#/me$#', $path ) ) {
			return array( 'code' => 200, 'body' => '{"id":"11","name":"Yemen TV"}', 'headers' => array() );
		}
		if ( preg_match( '#/11$#', $path ) ) {
			same( 'PAGE11', $q['access_token'], 'page read with its own token' );
			return array( 'code' => 200, 'body' => '{"followers_count":1000,"fan_count":900,"talking_about_count":50}', 'headers' => array() );
		}
		if ( preg_match( '#/99$#', $path ) ) {
			same( 'PAGE11', $q['access_token'] );
			return array( 'code' => 200, 'body' => '{"followers_count":300,"media_count":40}', 'headers' => array() );
		}
		return array( 'code' => 404, 'body' => '{}', 'headers' => array() );
	};
}

function test_facebook_lists_pages_and_trades_the_pasted_token_for_the_page_token() {
	$seen = array();
	Marsad_Http::$mock = marsad_meta_mock( $seen );
	Marsad_Registry::save( Marsad_Registry::get( 'facebook' ), true, array( 'token' => 'USER', 'page_id' => '' ) );
	$fb = Marsad_Registry::get( 'facebook' );
	same( array( '11' => 'Yemen TV (11)' ), $fb->choices( 'page_id' ) );
	$rows = $fb->fetch( 'a', 'b' );
	near( 1000, $rows[0]['value'] );
	$fb = Marsad_Registry::get( 'facebook' );
	same( 'PAGE11', $fb->get( 'token' ), 'the never-expiring page token is kept' );
	same( '11', $fb->get( 'page_id' ) );
	check( 0 === strpos( Marsad_Registry::saved()['facebook']['values']['token'], 'mz1:' ), 'still encrypted' );

	// Later syncs work with the page token alone.
	$rows = $fb->fetch( 'a', 'b' );
	near( 900, $rows[1]['value'] );
}

function test_instagram_finds_the_linked_account_through_facebook() {
	$seen = array();
	Marsad_Http::$mock = marsad_meta_mock( $seen );
	Marsad_Registry::save( Marsad_Registry::get( 'facebook' ), true, array( 'token' => 'USER', 'page_id' => '' ) );
	$ig = Marsad_Registry::get( 'instagram' );
	check( $ig->is_configured(), 'Facebook token is enough' );
	same( array( '99' => '@yementv · Yemen TV' ), $ig->choices( 'ig_user_id' ) );
	$rows = $ig->fetch( 'a', 'b' );
	near( 300, $rows[0]['value'] );
}

function test_facebook_with_a_rejected_token_says_so() {
	Marsad_Http::$mock = function () { return array( 'code' => 400, 'body' => '{"error":{"message":"Invalid OAuth access token"}}', 'headers' => array() ); };
	Marsad_Registry::save( Marsad_Registry::get( 'facebook' ), true, array( 'token' => 'BAD', 'page_id' => '' ) );
	throws( function () { Marsad_Registry::get( 'facebook' )->fetch( 'a', 'b' ); }, 'did not accept this token' );
}
