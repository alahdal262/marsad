<?php
/**
 * Cloudflare: zone traffic from the GraphQL Analytics API.
 */

/** One day as Cloudflare's httpRequests1dGroups returns it. */
function marsad_cf_day( $date, $requests = 1000 ) {
	return array(
		'dimensions' => array( 'date' => $date ),
		'sum'        => array(
			'requests'             => $requests,
			'cachedRequests'       => 600,
			'bytes'                => 2.5e9,
			'cachedBytes'          => 2e9,
			'pageViews'            => 400,
			'threats'              => 7,
			'encryptedRequests'    => 990,
			'encryptedBytes'       => 2.4e9,
			'countryMap'           => array(
				array( 'clientCountryName' => 'YE', 'requests' => 500, 'bytes' => 1e9, 'threats' => 0 ),
				array( 'clientCountryName' => 'SA', 'requests' => 300, 'bytes' => 8e8, 'threats' => 5 ),
			),
			'responseStatusMap'    => array(
				array( 'edgeResponseStatus' => 200, 'requests' => 900 ),
				array( 'edgeResponseStatus' => 404, 'requests' => 60 ),
				array( 'edgeResponseStatus' => 403, 'requests' => 10 ),
				array( 'edgeResponseStatus' => 502, 'requests' => 30 ),
			),
			'contentTypeMap'       => array( array( 'edgeResponseContentTypeName' => 'html', 'requests' => 450, 'bytes' => 5e8 ) ),
			'browserMap'           => array( array( 'uaBrowserFamily' => 'ChromeMobile', 'pageViews' => 250 ) ),
			'clientHTTPVersionMap' => array( array( 'clientHTTPProtocol' => 'HTTP/2', 'requests' => 700 ) ),
			'clientSSLMap'         => array( array( 'clientSSLProtocol' => 'TLSv1.3', 'requests' => 950 ) ),
			'ipClassMap'           => array( array( 'ipType' => 'searchEngine', 'requests' => 120 ) ),
			'threatPathingMap'     => array( array( 'threatPathingName' => 'bic.ban.unknown', 'requests' => 7 ) ),
		),
		'uniq'       => array( 'uniques' => 321 ),
	);
}

function marsad_cf_enable( array $values = array() ) {
	delete_option( 'marsad_cloudflare_zones' );
	Marsad_Registry::save( Marsad_Registry::get( 'cloudflare' ), true, $values + array( 'token' => 'CFTOKEN' ) );
	return Marsad_Registry::get( 'cloudflare' );
}

/** Answers the zone lookup and the GraphQL query; records what was asked. */
function marsad_cf_mock( array $days, array &$seen ) {
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( $days, &$seen ) {
		$seen[] = array( $m, $url, $args['headers']['Authorization'] ?? '', $args['body'] ?? '' );
		if ( false !== strpos( $url, '/zones?' ) ) {
			return array( 'code' => 200, 'body' => '{"success":true,"result":[{"id":"Z123","name":"example.test"}]}', 'headers' => array() );
		}
		return array( 'code' => 200, 'body' => wp_json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'httpRequests1dGroups' => $days ) ) ) ), 'errors' => null ) ), 'headers' => array() );
	};
}

function test_cloudflare_is_a_builtin_source() {
	check( Marsad_Registry::get( 'cloudflare' ) instanceof Marsad_Connector_Cloudflare );
	same( 364, Marsad_Registry::get( 'cloudflare' )->max_backfill(), 'Cloudflare keeps a year; a query may span at most 52 weeks' );
}

function test_cloudflare_reads_every_daily_number() {
	$seen = array();
	$c    = marsad_cf_enable();
	marsad_cf_mock( array( marsad_cf_day( '2026-11-14' ) ), $seen );
	$rows = $c->fetch( '2026-11-14', '2026-11-14' );
	$by   = array();
	foreach ( $rows as $r ) {
		if ( '' === $r['dim'] ) {
			$by[ $r['metric'] ] = $r['value'];
		}
	}
	near( 1000, $by['requests'] );
	near( 400, $by['page_views'] );
	near( 321, $by['visitors'] );
	near( 2.5, $by['bandwidth_gb'] );
	near( 60, $by['cached_pct'], 'cached share of requests, 0–100' );
	near( 80, $by['cached_bytes_pct'] );
	near( 99, $by['encrypted_pct'] );
	near( 7, $by['threats'] );
	near( 70, $by['errors_4xx'] );
	near( 30, $by['errors_5xx'] );
	same( 'Bearer CFTOKEN', $seen[1][2] );
	check( false !== strpos( $seen[1][3], 'Z123' ) && false !== strpos( $seen[1][3], 'httpRequests1dGroups' ), 'the zone id from the lookup goes into the query' );
}

function test_cloudflare_breakdowns_become_tables() {
	$seen = array();
	$c    = marsad_cf_enable();
	marsad_cf_mock( array( marsad_cf_day( '2026-11-14' ) ), $seen );
	$t = array();
	foreach ( $c->fetch( '2026-11-14', '2026-11-14' ) as $r ) {
		if ( '' !== $r['dim'] ) {
			$t[ $r['metric'] ][ $r['dim'] ] = $r['value'];
		}
	}
	near( 500, $t['requests_by_country']['YE'] );
	near( 5, $t['threats_by_country']['SA'] );
	check( ! isset( $t['threats_by_country']['YE'] ), 'zero rows are not stored' );
	near( 0.8, $t['bandwidth_by_country']['SA'] );
	near( 60, $t['requests_by_status']['404'] );
	near( 450, $t['requests_by_content_type']['html'] );
	near( 250, $t['pageviews_by_browser']['ChromeMobile'] );
	near( 700, $t['requests_by_http_version']['HTTP/2'] );
	near( 950, $t['requests_by_tls']['TLSv1.3'] );
	near( 120, $t['requests_by_visitor_type']['searchEngine'] );
	near( 7, $t['threats_by_kind']['bic.ban.unknown'] );
}

function test_cloudflare_names_countries_and_visitor_types() {
	$c = Marsad_Registry::get( 'cloudflare' );
	same( 'Yemen', $c->dim_label( 'requests_by_country', 'YE' ) );
	same( 'Search engines', $c->dim_label( 'requests_by_visitor_type', 'searchEngine' ) );
	same( 'HTTP/2', $c->dim_label( 'requests_by_http_version', 'HTTP/2' ) );
}

function test_cloudflare_zone_defaults_to_this_site_and_is_remembered() {
	$seen = array();
	$c    = marsad_cf_enable();
	marsad_cf_mock( array(), $seen );
	$c->fetch( '2026-11-14', '2026-11-14' );
	check( false !== strpos( $seen[0][1], 'name=example.test' ), 'no zone typed: this site\'s own domain' );
	same( array( 'example.test' => 'Z123' ), get_option( Marsad_Connector_Cloudflare::OPT_ZONES ) );
	$seen = array();
	Marsad_Registry::get( 'cloudflare' )->fetch( '2026-11-14', '2026-11-14' );
	same( 1, count( $seen ), 'the zone is looked up once' );
}

function test_cloudflare_subdomain_site_finds_its_parent_zone() {
	$seen = array();
	$c    = marsad_cf_enable( array( 'zone' => 'https://en.example.test/' ) );
	Marsad_Http::$mock = function ( $m, $url ) use ( &$seen ) {
		$seen[] = $url;
		if ( false !== strpos( $url, 'name=en.example.test' ) ) {
			return array( 'code' => 200, 'body' => '{"success":true,"result":[]}', 'headers' => array() );
		}
		if ( false !== strpos( $url, 'name=example.test' ) ) {
			return array( 'code' => 200, 'body' => '{"success":true,"result":[{"id":"ZP","name":"example.test"}]}', 'headers' => array() );
		}
		return array( 'code' => 200, 'body' => '{"data":{"viewer":{"zones":[{"httpRequests1dGroups":[]}]}}}', 'headers' => array() );
	};
	$c->fetch( '2026-11-14', '2026-11-14' );
	same( 'ZP', get_option( Marsad_Connector_Cloudflare::OPT_ZONES )['en.example.test'] );
}

function test_cloudflare_errors_say_what_to_fix() {
	$c = marsad_cf_enable();
	Marsad_Http::$mock = function () { return array( 'code' => 200, 'body' => '{"success":true,"result":[]}', 'headers' => array() ); };
	throws( function () use ( $c ) { $c->fetch( '2026-11-14', '2026-11-14' ); }, 'example.test' );
	$c = marsad_cf_enable();
	update_option( Marsad_Connector_Cloudflare::OPT_ZONES, array( 'example.test' => 'Z1' ) );
	Marsad_Http::$mock = function () { return array( 'code' => 200, 'body' => '{"data":null,"errors":[{"message":"not authorized for that account"}]}', 'headers' => array() ); };
	throws( function () use ( $c ) { $c->fetch( '2026-11-14', '2026-11-14' ); }, 'Analytics' );
}

function test_cloudflare_long_ranges_are_split_under_52_weeks() {
	$seen = array();
	$c    = marsad_cf_enable();
	update_option( Marsad_Connector_Cloudflare::OPT_ZONES, array( 'example.test' => 'Z1' ) );
	marsad_cf_mock( array(), $seen );
	$c->fetch( '2025-11-15', '2026-11-14' );
	check( count( $seen ) >= 2, 'a year is asked in parts' );
	foreach ( $seen as $s ) {
		preg_match_all( '/\d{4}-\d{2}-\d{2}/', $s[3], $m );
		check( count( $m[0] ) >= 2 && ( strtotime( $m[0][1] ) - strtotime( $m[0][0] ) ) / DAY_IN_SECONDS <= 180, 'each part spans at most 180 days' );
	}
}

function test_hosting_tab_shows_cloudflare_traffic() {
	require_once __DIR__ . '/test-dashboard-deep.php';
	marsad_test_as_admin();
	$db = marsad_test_deep_site();
	marsad_test_registry( array( new Marsad_Connector_Wordpress(), new Marsad_Connector_Site(), new Marsad_Connector_Cloudflare() ) );
	Marsad_Registry::save( Marsad_Registry::get( 'cloudflare' ), true, array( 'token' => 'T' ) );
	for ( $i = 1; $i <= 28; $i++ ) {
		$d             = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		$db->fixture[] = array( 'cloudflare', 'requests', '', $d, 1000 );
		$db->fixture[] = array( 'cloudflare', 'threats', '', $d, 3 );
		$db->fixture[] = array( 'cloudflare', 'requests_by_country', 'YE', $d, 500 );
	}
	$h = marsad_test_deep_render( 'hosting' );
	marsad_test_has( $h, array( 'marsad-src-cloudflare', '28,000', 'Threats stopped', 'Requests by country', 'Yemen' ), 'hosting tab' );
	$o = marsad_test_deep_render( 'overview' );
	check( false !== strpos( $o, 'tab=hosting#marsad-src-cloudflare' ) || false !== strpos( $o, 'tab=hosting' ), 'the overview card leads to the Hosting tab' );
}
