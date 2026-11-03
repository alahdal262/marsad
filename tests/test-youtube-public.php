<?php

function test_youtube_understands_links_handles_and_ids() {
	same( array( 'forHandle' => '@yementvyem' ), Marsad_Connector_Youtube::channel_query( 'https://www.youtube.com/@yementvyem' ) );
	same( array( 'forHandle' => '@yementvyem' ), Marsad_Connector_Youtube::channel_query( '@yementvyem' ) );
	same( array( 'forHandle' => '@yementvyem' ), Marsad_Connector_Youtube::channel_query( 'youtube.com/@yementvyem/videos' ) );
	same( array( 'id' => 'UCmdgAcBW7sMCOFUoRDpclmQ' ), Marsad_Connector_Youtube::channel_query( 'https://youtube.com/channel/UCmdgAcBW7sMCOFUoRDpclmQ' ) );
	same( array( 'id' => 'UCmdgAcBW7sMCOFUoRDpclmQ' ), Marsad_Connector_Youtube::channel_query( 'UCmdgAcBW7sMCOFUoRDpclmQ' ) );
	same( array(), Marsad_Connector_Youtube::channel_query( '' ) );
}

function test_youtube_public_numbers_need_only_the_channel_link() {
	$yt = new Marsad_Connector_Youtube();
	same( array( 'Channel link' ), $yt->missing() );
	$yt->set_values( array( 'channel_id' => '@yementvyem' ) );
	same( array(), $yt->missing(), 'no key, no account: the shared Marsad key is used' );
	$seen = array();
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$seen ) {
		$seen[] = $url;
		return array( 'code' => 200, 'body' => '{"items":[{"statistics":{"subscriberCount":"52000","viewCount":"9","videoCount":"3"}}]}', 'headers' => array() );
	};
	$rows = $yt->fetch( 'a', 'b' );
	check( 0 === strpos( $seen[0], 'https://form.yementv.tv/connect/youtube.php?' ), 'through Marsad Connect' );
	check( false !== strpos( $seen[0], 'forHandle=%40yementvyem' ) && false === strpos( $seen[0], 'key=' ) );
	near( 52000, $rows[0]['value'] );
}

function test_relay_youtube_proxy_allows_only_public_reads_and_caches() {
	$cfg            = marsad_relay_cfg();
	$cfg['youtube_key'] = 'YTKEY';
	$calls          = array();
	$get            = function ( $url ) use ( &$calls ) {
		$calls[] = $url;
		return array( 200, '{"items":[{"id":"UC1"}]}' );
	};
	list( $st, , $body ) = mc_youtube( array( 'endpoint' => 'channels', 'part' => 'statistics', 'forHandle' => '@a' ), $cfg, $get, '1.2.3.4' );
	same( 200, $st );
	check( false !== strpos( $calls[0], 'key=YTKEY' ) && false !== strpos( $calls[0], 'forHandle=%40a' ) );
	mc_youtube( array( 'endpoint' => 'channels', 'part' => 'statistics', 'forHandle' => '@a' ), $cfg, $get, '1.2.3.4' );
	same( 1, count( $calls ), 'second identical request served from cache' );

	list( $st ) = mc_youtube( array( 'endpoint' => 'search', 'q' => 'x' ), $cfg, $get, '1.2.3.4' );
	same( 400, $st, 'only channels, playlistItems and videos' );
	list( $st ) = mc_youtube( array( 'endpoint' => 'channels', 'part' => 'statistics', 'mine' => 'true' ), $cfg, $get, '1.2.3.4' );
	same( 400, $st, 'needs an id, handle or playlist' );
	unset( $cfg['youtube_key'] );
	list( $st ) = mc_youtube( array( 'endpoint' => 'channels', 'part' => 'statistics', 'id' => 'UC2' ), $cfg, $get, '1.2.3.4' );
	same( 503, $st, 'not configured' );
}
