<?php
/**
 * X, Threads and TikTok sources.
 */

function test_x_reads_public_metrics_and_explains_the_paid_plan() {
	Marsad_Registry::save( Marsad_Registry::get( 'x' ), true, array( 'username' => '@YemenTV', 'bearer' => 'BEARER' ) );
	$c    = Marsad_Registry::get( 'x' );
	$seen = array();
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$seen ) {
		$seen = array( $url, $args['headers']['Authorization'] ?? '' );
		return array( 'code' => 200, 'body' => '{"data":{"id":"1","username":"YemenTV","public_metrics":{"followers_count":120000,"following_count":40,"tweet_count":56000,"listed_count":300}}}', 'headers' => array() );
	};
	$rows = $c->fetch( 'a', 'b' );
	check( false !== strpos( $seen[0], '/2/users/by/username/YemenTV?' ), 'the @ is dropped' );
	same( 'Bearer BEARER', $seen[1] );
	$by = array_column( $rows, 'value', 'metric' );
	near( 120000, $by['followers'] );
	near( 56000, $by['posts'] );
	Marsad_Http::$mock = function () { return array( 'code' => 402, 'body' => '{"title":"Payment Required"}', 'headers' => array() ); };
	throws( function () use ( $c ) { $c->fetch( 'a', 'b' ); }, 'paid' );
}

function test_threads_daily_views_and_followers_and_token_refresh() {
	Marsad_Registry::save( Marsad_Registry::get( 'threads' ), true, array( 'token' => 'OLD' ) );
	$c     = Marsad_Registry::get( 'threads' );
	$urls  = array();
	Marsad_Http::$mock = function ( $m, $url ) use ( &$urls ) {
		$urls[] = $url;
		if ( false !== strpos( $url, 'refresh_access_token' ) ) {
			return array( 'code' => 200, 'body' => '{"access_token":"NEW","expires_in":5183944}', 'headers' => array() );
		}
		if ( false !== strpos( $url, '/me?' ) ) {
			return array( 'code' => 200, 'body' => '{"id":"77","username":"yementv"}', 'headers' => array() );
		}
		if ( false !== strpos( $url, 'followers_count' ) ) {
			return array( 'code' => 200, 'body' => '{"data":[{"name":"followers_count","total_value":{"value":9100}}]}', 'headers' => array() );
		}
		return array( 'code' => 200, 'body' => '{"data":[{"name":"views","values":[{"value":300,"end_time":"2026-11-03T08:00:00+0000"}]},{"name":"likes","total_value":{"value":40}},{"name":"replies","total_value":{"value":5}},{"name":"reposts","total_value":{"value":3}}]}', 'headers' => array() );
	};
	$rows = $c->fetch( '2026-11-03', '2026-11-03' );
	$by   = array();
	foreach ( $rows as $r ) {
		$by[ $r['metric'] ] = $r['value'];
	}
	near( 9100, $by['followers'] );
	near( 300, $by['views'] );
	near( 40, $by['likes'] );
	check( false !== strpos( implode( ' ', $urls ), 'refresh_access_token' ), 'a long-lived token is renewed before it ages out' );
	same( 'NEW', Marsad_Registry::get( 'threads' )->get( 'token' ), 'the renewed token is kept, encrypted' );
}

function test_tiktok_rotates_its_refresh_token_and_reads_stats() {
	Marsad_Registry::save( Marsad_Registry::get( 'tiktok' ), true, array( 'client_key' => 'ck', 'client_secret' => 'cs', 'refresh_token' => 'R1' ) );
	$c    = Marsad_Registry::get( 'tiktok' );
	$sent = array();
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$sent ) {
		if ( false !== strpos( $url, '/oauth/token/' ) ) {
			$sent = $args['body'];
			return array( 'code' => 200, 'body' => '{"access_token":"AT","refresh_token":"R2","expires_in":86400}', 'headers' => array() );
		}
		same( 'Bearer AT', $args['headers']['Authorization'] );
		return array( 'code' => 200, 'body' => '{"data":{"user":{"follower_count":5000,"following_count":10,"likes_count":90000,"video_count":320}},"error":{"code":"ok"}}', 'headers' => array() );
	};
	$rows = $c->fetch( 'a', 'b' );
	same( 'refresh_token', $sent['grant_type'] );
	same( 'R1', $sent['refresh_token'] );
	same( 'R2', Marsad_Registry::get( 'tiktok' )->get( 'refresh_token' ), 'TikTok issues a new refresh token each time: keep it' );
	$by = array_column( $rows, 'value', 'metric' );
	near( 5000, $by['followers'] );
	near( 90000, $by['likes'] );
}

function test_new_platforms_are_social_sources_with_linked_steps() {
	foreach ( array( 'x', 'threads', 'tiktok' ) as $id ) {
		$c = Marsad_Registry::get( $id );
		check( null !== $c, $id );
		same( 'social', $c->group() );
		$links = array_filter( $c->instructions(), function ( $s ) { return is_array( $s ) && '' !== $s['url']; } );
		check( count( $links ) >= 1, "$id links to where its key is made" );
	}
}
