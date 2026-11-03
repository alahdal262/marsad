<?php
/**
 * GA4 range snapshots: the requests, the parser on recorded-style answers (two named date
 * ranges, unnamed ones, rowCount, "(not set)"), custom reports gated by metadata, isolation of
 * a rejected report, and realtime with its one-minute cache.
 */

/** A connector reading as the connected Google account, whose access token is already cached. */
function marsad_test_ga4() {
	set_transient( 'marsad_gacct_token', 'AT' );
	$c = new Marsad_Connector_Ga4();
	$c->set_values( array( 'property_id' => 'properties/123' ) );
	return $c;
}

function marsad_test_ga4_range( $prev = true ) {
	return array(
		'from'      => '2026-10-06',
		'to'        => '2026-11-02',
		'prev_from' => $prev ? '2026-09-08' : null,
		'prev_to'   => $prev ? '2026-10-05' : null,
	);
}

/** One GA4 answer row: dimension values then metric values, as strings like the API sends. */
function marsad_test_ga4_row( array $dims, array $vals ) {
	return array(
		'dimensionValues' => array_map( function ( $d ) { return array( 'value' => (string) $d ); }, $dims ),
		'metricValues'    => array_map( function ( $v ) { return array( 'value' => (string) $v ); }, $vals ),
	);
}

/** A RunReportResponse shaped like Google's: headers, rows and rowCount. */
function marsad_test_ga4_report( array $dims, array $metrics, array $rows, $row_count = null ) {
	$types = array( 'engagementRate' => 'TYPE_FLOAT', 'bounceRate' => 'TYPE_FLOAT', 'screenPageViewsPerSession' => 'TYPE_FLOAT', 'sessionsPerUser' => 'TYPE_FLOAT', 'averageSessionDuration' => 'TYPE_SECONDS', 'userEngagementDuration' => 'TYPE_SECONDS' );
	$out   = array(
		'dimensionHeaders' => array_map( function ( $d ) { return array( 'name' => $d ); }, $dims ),
		'metricHeaders'    => array_map( function ( $m ) use ( $types ) { return array( 'name' => $m, 'type' => $types[ $m ] ?? 'TYPE_INTEGER' ); }, $metrics ),
		'rows'             => $rows,
		'metadata'         => array( 'currencyCode' => 'USD', 'timeZone' => 'Asia/Aden' ),
		'kind'             => 'analyticsData#runReport',
	);
	if ( $rows ) {
		$out['rowCount'] = null === $row_count ? count( $rows ) : $row_count;
	}
	return $out;
}

/** The wrapped request with this key from requests(). */
function marsad_test_ga4_find( array $requests, $key ) {
	foreach ( $requests as $r ) {
		if ( $key === $r['key'] ) {
			return $r;
		}
	}
	return null;
}

/**
 * A fake Data API: metadata lists $custom dimensions, each batch request gets one row per date
 * range, and any request whose JSON contains $reject is refused with 400 like Google does.
 */
function marsad_test_ga4_api( array &$log, array $custom = array(), $reject = '' ) {
	return function ( $method, $url, $args ) use ( &$log, $custom, $reject ) {
		$log[] = array( $method, $url, $args['body'] ?? null );
		if ( 'GET' === $method && '/metadata' === substr( $url, -9 ) ) {
			$dims = array( array( 'apiName' => 'pagePath' ) );
			foreach ( $custom as $c ) {
				$dims[] = array( 'apiName' => $c, 'customDefinition' => true );
			}
			return array( 'code' => 200, 'body' => json_encode( array( 'name' => 'properties/123/metadata', 'dimensions' => $dims, 'metrics' => array( array( 'apiName' => 'activeUsers' ) ) ) ), 'headers' => array() );
		}
		$body = json_decode( $args['body'], true );
		if ( '' !== $reject && false !== strpos( $args['body'], $reject ) ) {
			return array( 'code' => 400, 'body' => '{"error":{"code":400,"message":"Please remove ' . $reject . ' to make the request compatible.","status":"INVALID_ARGUMENT"}}', 'headers' => array() );
		}
		$reports = array();
		foreach ( $body['requests'] as $req ) {
			$dims    = array_column( $req['dimensions'] ?? array(), 'name' );
			$metrics = array_column( $req['metrics'], 'name' );
			$ranges  = $req['dateRanges'];
			$rows    = array();
			foreach ( $ranges as $n => $range ) {
				$d = array_map( function ( $x ) { return 'v-' . $x; }, $dims );
				if ( count( $ranges ) > 1 ) {
					$d[] = $range['name'];
				}
				$rows[] = marsad_test_ga4_row( $d, array_fill( 0, count( $metrics ), 0 === $n ? 10 : 7 ) );
			}
			$heads     = count( $ranges ) > 1 ? array_merge( $dims, array( 'dateRange' ) ) : $dims;
			$reports[] = marsad_test_ga4_report( $heads, $metrics, $rows );
		}
		return array( 'code' => 200, 'body' => json_encode( array( 'reports' => $reports, 'kind' => 'analyticsData#batchRunReports' ) ), 'headers' => array() );
	};
}

function test_ga4_requests_match_the_report_table() {
	$req = Marsad_Connector_Ga4::requests( marsad_test_ga4_range(), array( 'dims' => array(), 'metrics' => array() ) );
	$keys = array_values( array_unique( array_column( $req, 'key' ) ) );
	same( array( 'totals', 'pages', 'landing', 'channels', 'source_medium', 'campaigns', 'social', 'referrals', 'countries', 'cities', 'languages', 'devices', 'os', 'browsers', 'models', 'resolutions', 'new_returning', 'age', 'gender', 'heatmap', 'events', 'key_events', 'search_terms', 'outbound' ), $keys, 'no custom report without metadata' );
	foreach ( $req as $r ) {
		check( count( $r['body']['metrics'] ) <= 10, $r['key'] . ': at most ten metrics per request' );
		same( false, $r['body']['keepEmptyRows'] );
		same( array( 'cur', 'prev' ), array_column( $r['body']['dateRanges'], 'name' ) );
	}

	$totals = array_values( array_filter( $req, function ( $r ) { return 'totals' === $r['key']; } ) );
	same( 2, count( $totals ), 'fourteen totals metrics need two requests' );
	same( 14, count( $totals[0]['body']['metrics'] ) + count( $totals[1]['body']['metrics'] ) );
	check( ! isset( $totals[0]['body']['dimensions'] ) && ! isset( $totals[0]['body']['limit'] ), 'totals have no dimensions or limit' );

	$pages = marsad_test_ga4_find( $req, 'pages' );
	same( array( 'pagePath', 'pageTitle' ), array_column( $pages['body']['dimensions'], 'name' ) );
	same( 500, $pages['limit'] );
	same( 1000, $pages['body']['limit'], 'both periods share the limit' );
	same( array( array( 'metric' => array( 'metricName' => 'screenPageViews' ), 'desc' => true ) ), $pages['body']['orderBys'] );
	same( 'screenPageViews', $pages['sort'] );

	$social = marsad_test_ga4_find( $req, 'social' );
	same( array( 'filter' => array( 'fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => array( 'value' => 'Organic Social', 'matchType' => 'EXACT' ) ) ), $social['body']['dimensionFilter'] );
	same( 'Referral', marsad_test_ga4_find( $req, 'referrals' )['body']['dimensionFilter']['filter']['stringFilter']['value'] );
	same( 'click', marsad_test_ga4_find( $req, 'outbound' )['body']['dimensionFilter']['filter']['stringFilter']['value'] );

	$key_events = marsad_test_ga4_find( $req, 'key_events' );
	same( 'keyEvents', $key_events['body']['metricFilter']['filter']['fieldName'] );
	same( 'GREATER_THAN', $key_events['body']['metricFilter']['filter']['numericFilter']['operation'] );

	$heat = marsad_test_ga4_find( $req, 'heatmap' );
	same( array( 'dayOfWeek', 'hour' ), array_map( function ( $o ) { return $o['dimension']['dimensionName']; }, $heat['body']['orderBys'] ) );
	same( 'dayOfWeek', $heat['sort'] );

	$all = Marsad_Connector_Ga4::requests( marsad_test_ga4_range( false ), array() );
	same( array( 'cur' ), array_column( marsad_test_ga4_find( $all, 'pages' )['body']['dateRanges'], 'name' ), 'all time has no previous period' );
	same( 500, marsad_test_ga4_find( $all, 'pages' )['body']['limit'] );
}

function test_ga4_custom_reports_follow_the_property_metadata() {
	$meta = array(
		'dims'    => array( 'customEvent:googlesitekit_post_author', 'customEvent:percentage' ),
		'metrics' => array( 'customEvent:video_percent' => 'video_percent', 'averageCustomEvent:video_percent' => 'Average video_percent' ),
	);
	$req  = Marsad_Connector_Ga4::requests( marsad_test_ga4_range(), $meta );
	check( null !== marsad_test_ga4_find( $req, 'authors' ), 'authors runs when registered' );
	check( null !== marsad_test_ga4_find( $req, 'scroll' ), 'scroll runs when registered' );
	same( null, marsad_test_ga4_find( $req, 'categories' ), 'categories skipped: not registered' );
	same( null, marsad_test_ga4_find( $req, 'post_dates' ), 'post dates skipped: not registered' );
	same( 'scroll', marsad_test_ga4_find( $req, 'scroll' )['body']['dimensionFilter']['filter']['stringFilter']['value'] );
	$video = marsad_test_ga4_find( $req, 'video' );
	same( array( 'customEvent:video_percent', 'averageCustomEvent:video_percent' ), array_column( $video['body']['metrics'], 'name' ) );
	check( ! isset( $video['body']['dimensions'] ), 'video is a totals report' );
}

function test_ga4_parse_snapshot_maps_named_ranges_drops_not_set_and_keeps_row_count() {
	$req = Marsad_Connector_Ga4::requests( marsad_test_ga4_range(), array() );
	$idx = array_search( 'pages', array_column( $req, 'key' ), true );
	$res = array(
		$idx => marsad_test_ga4_report(
			array( 'pagePath', 'pageTitle', 'dateRange' ),
			array( 'screenPageViews', 'activeUsers', 'userEngagementDuration', 'engagementRate' ),
			array(
				marsad_test_ga4_row( array( '/news/a', 'عنوان أ', 'cur' ), array( 900, 700, 52000, 0.61 ) ),
				marsad_test_ga4_row( array( '/news/a', 'عنوان أ', 'prev' ), array( 810, 650, 48000, 0.58 ) ),
				marsad_test_ga4_row( array( '(not set)', '(not set)', 'cur' ), array( 400, 300, 100, 0.1 ) ),
				marsad_test_ga4_row( array( '/old', 'Old', 'prev' ), array( 500, 450, 9000, 0.4 ) ),
				marsad_test_ga4_row( array( '/news/b', '(not set)', 'cur' ), array( 950, 600, 30000, 0.5 ) ),
			),
			5
		),
	);
	$out   = Marsad_Connector_Ga4::parse_snapshot( $req, $res );
	$pages = $out['pages'];
	same( array( 'pagePath', 'pageTitle' ), $pages['dims'] );
	same( array( 'screenPageViews', 'activeUsers', 'userEngagementDuration', 'engagementRate' ), $pages['metrics'] );
	same( 'screenPageViews', $pages['sort'] );
	same( 2, count( $pages['rows'] ), '(not set) and previous-only rows are left out' );
	same( array( '/news/b', '' ), $pages['rows'][0]['d'], 'sorted by views; a missing title is blank' );
	same( array( 0, 0, 0, 0 ), $pages['rows'][0]['p'], 'absent from a complete answer: zero last period' );
	same( array( '/news/a', 'عنوان أ' ), $pages['rows'][1]['d'] );
	same( 900, $pages['rows'][1]['m'][0] );
	same( 52000.0, $pages['rows'][1]['m'][2] );
	near( 0.61, $pages['rows'][1]['m'][3] );
	same( 810, $pages['rows'][1]['p'][0] );
	same( 3, $pages['total_rows'], 'complete answer: the current rows Google had' );
	same( array( 'pages' ), array_keys( $out ), 'dropped requests leave no payload' );
}

function test_ga4_parse_snapshot_unnamed_ranges_totals_and_cut_answers() {
	$req = Marsad_Connector_Ga4::requests( marsad_test_ga4_range(), array() );
	$res = array();
	foreach ( $req as $i => $r ) {
		if ( 'totals' === $r['key'] ) {
			$metrics   = array_column( $r['body']['metrics'], 'name' );
			$res[ $i ] = marsad_test_ga4_report(
				array( 'dateRange' ),
				$metrics,
				array(
					marsad_test_ga4_row( array( 'date_range_0' ), array_fill( 0, count( $metrics ), 5138 ) ),
					marsad_test_ga4_row( array( 'date_range_1' ), array_fill( 0, count( $metrics ), 4801 ) ),
				)
			);
		}
		if ( 'countries' === $r['key'] ) {
			// Cut at the limit: 4 of 10 rows, half of them from the previous period.
			$res[ $i ] = marsad_test_ga4_report(
				array( 'dateRange', 'country' ),
				array( 'activeUsers', 'sessions', 'screenPageViews' ),
				array(
					marsad_test_ga4_row( array( 'date_range_0', 'Yemen' ), array( 3000, 4000, 9000 ) ),
					marsad_test_ga4_row( array( 'date_range_1', 'Yemen' ), array( 2800, 3700, 8000 ) ),
					marsad_test_ga4_row( array( 'date_range_0', 'Saudi Arabia' ), array( 900, 1000, 2000 ) ),
					marsad_test_ga4_row( array( 'date_range_1', 'Egypt' ), array( 500, 600, 700 ) ),
				),
				10
			);
		}
	}
	$out = Marsad_Connector_Ga4::parse_snapshot( $req, $res );

	same( 14, count( $out['totals']['metrics'] ), 'both totals requests joined' );
	same( array( 'value' => 5138, 'prev' => 4801 ), $out['totals']['metrics']['activeUsers'] );
	near( 5138, $out['totals']['metrics']['engagementRate']['value'] );
	check( is_float( $out['totals']['metrics']['engagementRate']['value'] ), 'ratios stay floats' );
	check( isset( $out['totals']['metrics']['sessionsPerUser'] ), 'second part present' );

	$c = $out['countries'];
	same( array( 'Yemen', 'Saudi Arabia' ), array_map( function ( $r ) { return $r['d'][0]; }, $c['rows'] ) );
	same( array( 2800, 3700, 8000 ), $c['rows'][0]['p'] );
	same( array( null, null, null ), $c['rows'][1]['p'], 'cut answer: last period unknown, not zero' );
	same( 5, $c['total_rows'], 'estimated from the share of current rows' );
}

function test_ga4_parse_snapshot_all_time_keeps_google_row_count_and_empty_tables() {
	$req = Marsad_Connector_Ga4::requests( marsad_test_ga4_range( false ), array() );
	$res = array();
	foreach ( $req as $i => $r ) {
		if ( 'search_terms' === $r['key'] ) {
			$res[ $i ] = marsad_test_ga4_report(
				array( 'searchTerm' ),
				array( 'eventCount', 'totalUsers' ),
				array( marsad_test_ga4_row( array( 'اليمن' ), array( 40, 31 ) ), marsad_test_ga4_row( array( 'tv' ), array( 12, 9 ) ) ),
				5206
			);
		}
		if ( 'gender' === $r['key'] ) {
			// Google signals off: everything is "(not set)".
			$res[ $i ] = marsad_test_ga4_report( array( 'userGender' ), array( 'activeUsers' ), array( marsad_test_ga4_row( array( '(not set)' ), array( 5138 ) ) ) );
		}
		if ( 'totals' === $r['key'] ) {
			$res[ $i ] = marsad_test_ga4_report( array(), array_column( $r['body']['metrics'], 'name' ), array() );
		}
	}
	$out = Marsad_Connector_Ga4::parse_snapshot( $req, $res );
	same( 5206, $out['search_terms']['total_rows'], "Google's rowCount" );
	same( array( 'd' => array( 'اليمن' ), 'm' => array( 40, 31 ) ), $out['search_terms']['rows'][0], 'no p without a previous period' );
	same( array(), $out['gender']['rows'], 'all (not set) is an empty table' );
	same( array( 'value' => 0, 'prev' => null ), $out['totals']['metrics']['activeUsers'], 'no row back is zero, nothing to compare' );
	check( false !== json_encode( $out ), 'payloads encode' );
}

function test_ga4_snapshots_batches_requests_and_skips_unregistered_custom_reports() {
	$log               = array();
	Marsad_Http::$mock = marsad_test_ga4_api( $log, array( 'customEvent:category' ) );
	$c                 = marsad_test_ga4();
	$out               = $c->snapshots( '28', marsad_test_ga4_range() );

	same( 'GET', $log[0][0] );
	same( 'https://analyticsdata.googleapis.com/v1beta/properties/123/metadata', $log[0][1] );
	$posts = array_slice( $log, 1 );
	foreach ( $posts as $p ) {
		same( 'https://analyticsdata.googleapis.com/v1beta/properties/123:batchRunReports', $p[1] );
		check( count( json_decode( $p[2], true )['requests'] ) <= 5, 'at most five requests per batch' );
	}
	check( isset( $out['categories'] ), 'registered custom dimension runs' );
	check( ! isset( $out['authors'] ) && ! isset( $out['post_dates'] ) && ! isset( $out['scroll'] ), 'unregistered ones are skipped silently' );
	same( array(), Marsad_Snapshots::errors( 'ga4' ), 'skipping is not an error' );
	same( array( 'value' => 10, 'prev' => 7 ), $out['totals']['metrics']['activeUsers'] );
	same( array( 10, 10, 10.0, 10.0 ), $out['pages']['rows'][0]['m'], 'counts are integers, durations and ratios floats' );
	same( array( 7, 7, 7.0, 7.0 ), $out['pages']['rows'][0]['p'] );

	// The metadata is cached a day: the next range asks only for reports.
	$log = array();
	$c->snapshots( '7', marsad_test_ga4_range() );
	same( 0, count( array_filter( $log, function ( $l ) { return 'GET' === $l[0]; } ) ) );
	check( is_array( get_transient( 'marsad_ga4_meta_123' ) ), 'metadata transient keyed by property' );
}

function test_ga4_an_incompatible_report_is_isolated_and_noted() {
	$log               = array();
	// Authors and categories share the last batch; only categories is refused.
	Marsad_Http::$mock = marsad_test_ga4_api( $log, array( 'customEvent:googlesitekit_post_author', 'customEvent:category' ), 'customEvent:category' );
	$out               = marsad_test_ga4()->snapshots( '90', marsad_test_ga4_range() );

	check( ! isset( $out['categories'] ), 'rejected report dropped' );
	foreach ( array( 'totals', 'pages', 'channels', 'heatmap', 'outbound', 'authors' ) as $key ) {
		check( isset( $out[ $key ] ), "$key kept" );
	}
	$batch = 0;
	foreach ( $log as $l ) {
		if ( 'POST' === $l[0] && false !== strpos( $l[2], 'customEvent:category' ) ) {
			$batch++;
		}
	}
	same( 2, $batch, 'sent once in its batch, then once alone' );
	$err = Marsad_Snapshots::errors( 'ga4' );
	check( isset( $err[90] ), 'noted on the range' );
	check( false !== strpos( $err[90]['message'], '"categories"' ), 'names the report' );
	check( false !== strpos( $err[90]['message'], 'Please remove customEvent:category' ), "keeps Google's reason" );
}

function test_ga4_snapshots_fail_whole_on_auth_errors_or_when_everything_is_refused() {
	Marsad_Http::$mock = function ( $method, $url ) {
		if ( 'GET' === $method ) {
			return array( 'code' => 200, 'body' => '{"dimensions":[]}', 'headers' => array() );
		}
		return array( 'code' => 403, 'body' => '{"error":{"code":403,"message":"User does not have sufficient permissions for this property."}}', 'headers' => array() );
	};
	throws( function () { marsad_test_ga4()->snapshots( '7', marsad_test_ga4_range() ); }, 'sufficient permissions' );

	$log               = array();
	Marsad_Http::$mock = marsad_test_ga4_api( $log, array(), 'dateRanges' );
	throws( function () { marsad_test_ga4()->snapshots( '7', marsad_test_ga4_range() ); }, 'Please remove dateRanges' );
	same( array(), Marsad_Snapshots::errors( 'ga4' ), 'one failure for the range, not one per report' );

	$c = new Marsad_Connector_Ga4();
	throws( function () use ( $c ) { $c->snapshots( '7', marsad_test_ga4_range() ); }, 'Property ID is missing' );
}

function test_ga4_refresh_stores_every_range_through_the_snapshot_store() {
	$db                = marsad_test_snapshots_db();
	$log               = array();
	Marsad_Http::$mock = marsad_test_ga4_api( $log );
	$c                 = marsad_test_ga4();
	check( $c->supports_snapshots() );
	same( '2015-08-14', $c->history_start() );
	$written = Marsad_Snapshots::refresh( $c, time() + 60 );
	same( 5 * 24, $written, 'twenty-four reports for each of the five ranges' );
	check( ! Marsad_Snapshots::stale( 'ga4', 'all', 3600 ) );
	$all = Marsad_Snapshots::get( 'ga4', 'all', 'countries' )['payload'];
	check( ! isset( $all['rows'][0]['p'] ), 'all time has no comparison' );
	$first = null;
	foreach ( $log as $l ) {
		if ( 'POST' === $l[0] ) {
			$dates = json_decode( $l[2], true )['requests'][0]['dateRanges'];
			if ( 1 === count( $dates ) ) {
				$first = $dates[0]['startDate'];
			}
		}
	}
	same( '2015-08-14', $first, 'all time starts at the first day GA4 answers for' );
	check( count( $db->snapshots ) === 120 );
}

function test_ga4_labels_and_formats_cover_every_key() {
	set_transient( 'marsad_ga4_meta_123', array( 'dims' => array(), 'metrics' => array( 'customEvent:video_percent' => 'video_percent' ) ) );
	$c      = marsad_test_ga4();
	$labels = $c->snapshot_labels();
	foreach ( Marsad_Connector_Ga4::REPORTS as $key => $def ) {
		foreach ( array_merge( $def['dims'], $def['metrics'] ) as $k ) {
			check( isset( $labels[ $k ] ) && '' !== $labels[ $k ], "label for $k" );
		}
	}
	same( 'video_percent', $labels['customEvent:video_percent'] );
	check( isset( $labels['unifiedScreenName'] ), 'realtime dimension labelled' );
	foreach ( $c->snapshot_formats() as $k => $f ) {
		check( in_array( $f, Marsad_Snapshots::FORMAT_TYPES, true ), "format of $k" );
	}
	$formats = Marsad_Snapshots::formats( $c );
	same( 'text', $formats['country'], 'GA4 countries are names, not ISO codes' );
	same( 'link', $formats['pagePath'] );
	same( 'percent', $formats['engagementRate'] );
}

function test_ga4_realtime_reads_four_reports_and_caches_a_minute() {
	$calls             = array();
	Marsad_Http::$mock = function ( $method, $url, $args ) use ( &$calls ) {
		$body    = json_decode( $args['body'], true );
		$calls[] = array( $url, $body );
		$dim     = $body['dimensions'][0]['name'] ?? '';
		$rows    = array(
			''                  => array( array( '', 42 ) ),
			'unifiedScreenName' => array( array( 'الرئيسية', 20 ), array( '/live', 9 ) ),
			'country'           => array( array( 'Yemen', 30 ) ),
			'deviceCategory'    => array( array( 'mobile', 35 ), array( 'desktop', 7 ) ),
		)[ $dim ];
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = '' === $dim ? array( 'metricValues' => array( array( 'value' => (string) $r[1] ) ) ) : marsad_test_ga4_row( array( $r[0] ), array( $r[1] ) );
		}
		return array( 'code' => 200, 'body' => json_encode( array( 'rows' => $out, 'rowCount' => count( $out ), 'kind' => 'analyticsData#runRealtimeReport' ) ), 'headers' => array() );
	};
	$c  = marsad_test_ga4();
	$rt = $c->realtime();
	same( 42, $rt['active'] );
	same( array( array( 'name' => 'الرئيسية', 'users' => 20 ), array( 'name' => '/live', 'users' => 9 ) ), $rt['pages'] );
	same( array( array( 'name' => 'Yemen', 'users' => 30 ) ), $rt['countries'] );
	same( 'mobile', $rt['devices'][0]['name'] );
	same( 4, count( $calls ) );
	same( 'https://analyticsdata.googleapis.com/v1beta/properties/123:runRealtimeReport', $calls[0][0] );
	same( 10, $calls[1][1]['limit'] );
	same( 'activeUsers', $calls[1][1]['orderBys'][0]['metric']['metricName'] );

	$again = $c->realtime();
	same( $rt, $again );
	same( 4, count( $calls ), 'second poll within a minute is served from the transient' );
}

function test_ga4_realtime_is_null_on_error_and_does_not_retry_every_poll() {
	$calls             = 0;
	Marsad_Http::$mock = function () use ( &$calls ) {
		$calls++;
		return array( 'code' => 429, 'body' => '{"error":{"message":"Exhausted property tokens"}}', 'headers' => array() );
	};
	$c = marsad_test_ga4();
	same( null, $c->realtime() );
	same( null, $c->realtime() );
	same( 1, $calls );
	same( null, ( new Marsad_Connector_Ga4() )->realtime(), 'no property, no request' );
}
