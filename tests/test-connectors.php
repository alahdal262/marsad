<?php

function test_ga4_parse_maps_all_reports() {
	$row     = function ( $dims, $vals ) {
		return array(
			'dimensionValues' => array_map( function ( $d ) { return array( 'value' => $d ); }, $dims ),
			'metricValues'    => array_map( function ( $v ) { return array( 'value' => (string) $v ); }, $vals ),
		);
	};
	$reports = array(
		array( 'rows' => array( $row( array( '20261101' ), array( 100, 20, 150, 400, 0.55, 6000 ) ) ) ),
		array( 'rows' => array( $row( array( '20261101', '/news/a' ), array( 90 ) ), $row( array( '20261101', '(not set)' ), array( 3 ) ) ) ),
		array( 'rows' => array( $row( array( '20261101', 'Android' ), array( 40 ) ), $row( array( '20261101', 'web' ), array( 60 ) ) ) ),
		array( 'rows' => array( $row( array( '20261101', 'Organic Search' ), array( 80 ) ) ) ),
		array( 'rows' => array( $row( array( '20261101', 'Yemen' ), array( 70 ) ) ) ),
		array( 'rows' => array( $row( array( '20261101', 'facebook.com' ), array( 12 ) ) ) ),
		array( 'rows' => array( $row( array( '20261101', 'mobile' ), array( 77 ) ) ) ),
	);
	$rows = Marsad_Connector_Ga4::parse( $reports );
	$get  = function ( $metric, $dim = '' ) use ( $rows ) {
		foreach ( $rows as $r ) {
			if ( $r['metric'] === $metric && $r['dim'] === $dim ) {
				return $r;
			}
		}
		return null;
	};
	same( '2026-11-01', $get( 'users' )['day'] );
	near( 100, $get( 'users' )['value'] );
	near( 400, $get( 'views' )['value'] );
	near( 55, $get( 'engagement_rate' )['value'], 'stored as percent' );
	near( 60, $get( 'avg_engagement' )['value'], 'seconds per user' );
	near( 90, $get( 'views_by_page', '/news/a' )['value'] );
	same( null, $get( 'views_by_page', '(not set)' ), '(not set) is dropped' );
	near( 40, $get( 'users_by_platform', 'Android' )['value'] );
	near( 80, $get( 'sessions_by_channel', 'Organic Search' )['value'] );
	near( 70, $get( 'users_by_country', 'Yemen' )['value'] );
	near( 12, $get( 'sessions_by_source', 'facebook.com' )['value'] );
	near( 77, $get( 'users_by_device', 'mobile' )['value'] );
}

function test_search_console_parse_keeps_top_per_day() {
	$total = array( 'rows' => array( array( 'keys' => array( '2026-11-01' ), 'clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 7.5 ) ) );
	$query = array(
		'rows' => array(
			array( 'keys' => array( '2026-11-01', 'yemen' ), 'clicks' => 5 ),
			array( 'keys' => array( '2026-11-01', 'news' ), 'clicks' => 3 ),
			array( 'keys' => array( '2026-11-01', 'tv' ), 'clicks' => 1 ),
			array( 'keys' => array( '2026-11-01', 'zero' ), 'clicks' => 0 ),
		),
	);
	$rows  = Marsad_Connector_Search_Console::parse( $total, $query, array(), 2 );
	$q     = array_values( array_filter( $rows, function ( $r ) { return 'clicks_by_query' === $r['metric']; } ) );
	same( array( 'yemen', 'news' ), array_column( $q, 'dim' ) );
	$ctr   = array_values( array_filter( $rows, function ( $r ) { return 'ctr' === $r['metric']; } ) );
	near( 5, $ctr[0]['value'] );
}

function test_play_csv_utf16_with_named_columns() {
	$csv = "Date,Package Name,Daily Device Installs,Daily Device Uninstalls,Daily User Installs,Total User Installs,Active Device Installs\n"
		. "2026-10-01,com.x,120,30,100,5000,4200\n2026-10-02,com.x,\"1,050\",10,900,5900,4300\n";
	$raw  = "\xFF\xFE" . mb_convert_encoding( $csv, 'UTF-16LE', 'UTF-8' );
	$rows = Marsad_Connector_Google_Play::parse_csv( $raw );
	$find = function ( $m, $d ) use ( $rows ) {
		foreach ( $rows as $r ) {
			if ( $r['metric'] === $m && $r['day'] === $d ) {
				return $r['value'];
			}
		}
		return null;
	};
	near( 120, $find( 'installs', '2026-10-01' ) );
	near( 1050, $find( 'installs', '2026-10-02' ), 'thousands separator' );
	near( 30, $find( 'uninstalls', '2026-10-01' ) );
	near( 4300, $find( 'active_devices', '2026-10-02' ) );
	near( 5900, $find( 'total_users', '2026-10-02' ) );
	same( array( '202611', '202612', '202701' ), Marsad_Connector_Google_Play::months( '2026-11-30', '2027-01-02' ) );
}

function test_app_store_parse_types_country_and_app_filter() {
	$tsv  = "Provider\tSKU\tUnits\tProduct Type Identifier\tCountry Code\tApple Identifier\n"
		. "APPLE\ta\t5\t1F\tYE\t111\n"
		. "APPLE\ta\t2\t1F\tSA\t111\n"
		. "APPLE\ta\t3\t3F\tYE\t111\n"
		. "APPLE\ta\t9\t7F\tYE\t111\n"
		. "APPLE\tb\t50\t1F\tYE\t222\n";
	$rows = Marsad_Connector_App_Store::parse( $tsv, '2026-11-01', '111' );
	$by   = array();
	foreach ( $rows as $r ) {
		$by[ $r['metric'] . '|' . $r['dim'] ] = $r['value'];
	}
	near( 7, $by['downloads|'] );
	near( 3, $by['redownloads|'] );
	near( 9, $by['updates|'] );
	near( 5, $by['downloads_by_country|YE'] );
	near( 2, $by['downloads_by_country|SA'] );
	$all = Marsad_Connector_App_Store::parse( $tsv, '2026-11-01' );
	near( 57, $all[0]['value'], 'no filter counts every app' );
}

function test_json_connector_paths_and_modes() {
	$c = new Marsad_Connector_Json();
	$c->set_values( array( 'url' => 'https://x.test/s.json', 'map' => "Visitors = visitors\nShares = totals.shares\nbroken line\nMissing = nope", 'mode' => 'daily' ) );
	same( 3, count( $c->map() ) );
	$m = $c->metrics();
	same( 'sum', reset( $m )['agg'], 'daily mode sums days' );
	Marsad_Http::$mock = function () {
		return array( 'code' => 200, 'body' => '{"visitors":42,"totals":{"shares":"7"}}', 'headers' => array() );
	};
	$rows = $c->fetch( '2026-11-01', '2026-11-01' );
	same( 2, count( $rows ) );
	same( null, Marsad_Connector_Json::path( array( 'a' => 1 ), 'a.b' ) );
	Marsad_Http::$mock = function () {
		return array( 'code' => 200, 'body' => '{"other":1}', 'headers' => array() );
	};
	throws( function () use ( $c ) { $c->fetch( '2026-11-01', '2026-11-01' ); }, 'none of the paths' );
}

function test_telegram_rejects_malformed_token_before_any_request() {
	$c = new Marsad_Connector_Telegram();
	$c->set_values( array( 'chat' => '@x', 'token' => 'nope/../' ) );
	Marsad_Http::$mock = function () {
		throw new Exception( 'must not be called' );
	};
	throws( function () use ( $c ) { $c->fetch( 'a', 'b' ); }, 'token looks wrong' );
}

function test_youtube_fetch_channel_and_latest_videos() {
	$c = new Marsad_Connector_Youtube();
	$c->set_values( array( 'channel_id' => 'UC1', 'api_key' => 'k' ) );
	Marsad_Http::$mock = function ( $m, $url ) {
		if ( false !== strpos( $url, 'channels?' ) ) {
			return array( 'code' => 200, 'body' => '{"items":[{"statistics":{"subscriberCount":"1000","viewCount":"50000","videoCount":"12"},"contentDetails":{"relatedPlaylists":{"uploads":"UU1"}}}]}', 'headers' => array() );
		}
		if ( false !== strpos( $url, 'playlistItems?' ) ) {
			return array( 'code' => 200, 'body' => '{"items":[{"contentDetails":{"videoId":"v1"}}]}', 'headers' => array() );
		}
		return array( 'code' => 200, 'body' => '{"items":[{"id":"v1","snippet":{"title":"Clip"},"statistics":{"viewCount":"77"}}]}', 'headers' => array() );
	};
	$rows = $c->fetch( 'x', 'y' );
	same( 4, count( $rows ) );
	same( 'Clip', $rows[3]['dim'] );
	near( 1000, $rows[0]['value'] );
}

function test_http_json_raises_api_message() {
	Marsad_Http::$mock = function () {
		return array( 'code' => 403, 'body' => '{"error":{"message":"Permission denied"}}', 'headers' => array() );
	};
	throws( function () { Marsad_Http::json( 'GET', 'https://x' ); }, 'HTTP 403: Permission denied' );
}

function test_play_permission_error_names_the_exact_fix() {
	marsad_test_connect_google();
	$c = new Marsad_Connector_Google_Play();
	$c->set_values( array( 'package' => 'com.x', 'bucket' => 'pubsite_prod_1' ) );
	Marsad_Http::$mock = function () {
		return array( 'code' => 403, 'body' => '{"error":{"message":"owner@example.com does not have storage.objects.get access"}}', 'headers' => array() );
	};
	throws( function () use ( $c ) { $c->fetch( '2026-10-01', '2026-10-02' ); }, 'owner@example.com' );
	throws( function () use ( $c ) { $c->fetch( '2026-10-01', '2026-10-02' ); }, 'download bulk reports' );
}
