<?php
/**
 * Search Console range snapshots: totals per search type, tables with the previous period,
 * the computed opportunities and movers, sitemaps, and a report failing on its own.
 *
 * The API is faked through Marsad_Http::$mock; every request is kept in
 * $GLOBALS['marsad_test_sc_calls'] as array( method, url, decoded body, headers ).
 */

/** One Search Analytics row. */
function marsad_test_sc_row( array $keys, $clicks, $impressions, $ctr, $position ) {
	return array(
		'keys'        => $keys,
		'clicks'      => $clicks,
		'impressions' => $impressions,
		'ctr'         => $ctr,
		'position'    => $position,
	);
}

/** A connector with the connected Google account's token already cached. */
function marsad_test_sc_connector() {
	set_transient( Marsad_Google_Account::TOKEN, 'AT' );
	$c = new Marsad_Connector_Search_Console();
	$c->set_values( array( 'site' => 'sc-domain:yementv.tv' ) );
	return $c;
}

/**
 * Fake Search Console for one range. $fail lists search types or "dims" (e.g. 'page') that
 * answer HTTP 400.
 */
function marsad_test_sc_mock( array $range, array $fail = array() ) {
	$GLOBALS['marsad_test_sc_calls'] = array();
	Marsad_Http::$mock               = function ( $method, $url, $args ) use ( $range, $fail ) {
		$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		$GLOBALS['marsad_test_sc_calls'][] = array( $method, $url, $body, $args['headers'] ?? array() );
		$json = function ( $data, $code = 200 ) {
			return array( 'code' => $code, 'body' => json_encode( $data ), 'headers' => array() );
		};
		if ( 'GET' === $method && '/sitemaps' === substr( $url, -9 ) ) {
			return $json(
				array(
					'sitemap' => array(
						array( 'path' => 'https://yementv.tv/sitemap_index.xml', 'lastDownloaded' => '2026-11-02T10:11:12.000Z', 'errors' => '0', 'warnings' => '1', 'contents' => array( array( 'type' => 'web', 'submitted' => '1200', 'indexed' => '0' ) ) ),
					),
				)
			);
		}
		$cur  = $body['startDate'] === $range['from'];
		$dims = $body['dimensions'] ?? array();
		$type = $body['type'] ?? 'web';
		if ( in_array( $type, $fail, true ) || ( $dims && in_array( $dims[0], $fail, true ) ) ) {
			return $json( array( 'error' => array( 'message' => 'Bad request for ' . $type ) ), 400 );
		}
		if ( ! $dims ) {
			$totals = array(
				'web'        => array( array( 4090, 120000, 0.0341, 9.2 ), array( 3800, 110000, 0.0345, 9.6 ) ),
				'image'      => array( array( 120, 35423, 0.0034, 22.5 ), array( 100, 30000, 0.0033, 24.0 ) ),
				'video'      => array( array( 15, 900, 0.0167, 6.1 ), array( 12, 800, 0.015, 6.4 ) ),
				'news'       => null,
				'discover'   => array( array( 700, 20000, 0.035, 0 ), array( 650, 19000, 0.0342, 0 ) ),
				'googleNews' => array( array( 50, 4000, 0.0125, 0 ), array( 40, 3000, 0.0133, 0 ) ),
			);
			$t = $totals[ $type ];
			if ( null === $t ) {
				return $json( array( 'responseAggregationType' => 'byProperty' ) );
			}
			$v = $t[ $cur ? 0 : 1 ];
			return $json( array( 'rows' => array( array( 'clicks' => $v[0], 'impressions' => $v[1], 'ctr' => $v[2], 'position' => $v[3] ) ) ) );
		}
		$rows = array();
		if ( array( 'query' ) === $dims ) {
			$rows = $cur
				? array(
					marsad_test_sc_row( array( 'news' ), 300, 3000, 0.1, 2.5 ),
					marsad_test_sc_row( array( 'yemen tv' ), 500, 5000, 0.1, 1.4 ),
					marsad_test_sc_row( array( 'live' ), 40, 800, 0.05, 3.0 ),
				)
				: array(
					marsad_test_sc_row( array( 'yemen tv' ), 400, 4500, 0.0889, 1.6 ),
					marsad_test_sc_row( array( 'news' ), 320, 3100, 0.1032, 2.4 ),
					marsad_test_sc_row( array( 'old query' ), 50, 900, 0.0556, 4.0 ),
				);
		} elseif ( array( 'page' ) === $dims ) {
			$rows = array( marsad_test_sc_row( array( 'https://yementv.tv/' ), $cur ? 900 : 800, 9000, 0.1, 3.0 ) );
		} elseif ( array( 'country' ) === $dims ) {
			$rows = array( marsad_test_sc_row( array( 'yem' ), $cur ? 2000 : 1500, 40000, 0.05, 5.0 ), marsad_test_sc_row( array( 'sau' ), 800, 20000, 0.04, 7.0 ) );
		} elseif ( array( 'device' ) === $dims ) {
			$rows = array( marsad_test_sc_row( array( 'MOBILE' ), 3000, 90000, 0.033, 9.0 ), marsad_test_sc_row( array( 'DESKTOP' ), 1090, 30000, 0.036, 9.5 ) );
		} elseif ( array( 'searchAppearance' ) === $dims ) {
			$rows = array( marsad_test_sc_row( array( 'VIDEO' ), 15, 900, 0.0167, 6.1 ) );
		} elseif ( array( 'query', 'page' ) === $dims ) {
			$rows = array( marsad_test_sc_row( array( 'yemen tv', 'https://yementv.tv/' ), 450, 4000, 0.1125, 1.3 ) );
		}
		return $json( array( 'rows' => $rows ) );
	};
}

function test_sc_snapshots_totals_per_search_type_with_previous() {
	$c     = marsad_test_sc_connector();
	$range = Marsad_Snapshots::range( '28', '2026-11-03' );
	marsad_test_sc_mock( $range );
	$out = $c->snapshots( '28', $range );

	check( $c->supports_snapshots() );
	foreach ( array( 'totals', 'totals_web', 'totals_image', 'totals_video', 'totals_news', 'totals_discover', 'totals_googleNews', 'queries', 'pages', 'countries', 'devices', 'appearance', 'query_pages', 'opportunities', 'rising', 'falling', 'sitemaps' ) as $key ) {
		check( isset( $out[ $key ] ), "report $key" );
	}
	same( $out['totals_web'], $out['totals'], 'totals = web totals' );
	same( array( 'value' => 4090, 'prev' => 3800 ), $out['totals']['metrics']['clicks'] );
	same( array( 'value' => 35423, 'prev' => 30000 ), $out['totals_image']['metrics']['impressions'] );
	same( array( 'value' => 0.0341, 'prev' => 0.0345 ), $out['totals']['metrics']['ctr'], 'ratio stored as Google gives it' );
	same( array( 'value' => 9.2, 'prev' => 9.6 ), $out['totals']['metrics']['position'] );
	same( array( 'value' => 700, 'prev' => 650 ), $out['totals_discover']['metrics']['clicks'] );
	same( array( 'value' => 0, 'prev' => 0 ), $out['totals_news']['metrics']['clicks'], 'no rows: nothing clicked' );
	same( array( 'value' => null, 'prev' => null ), $out['totals_news']['metrics']['position'], 'no rows: position unknown, not zero' );
	same( array( 'clicks', 'impressions', 'ctr', 'position' ), array_keys( $out['totals_video']['metrics'] ) );

	$calls = $GLOBALS['marsad_test_sc_calls'];
	foreach ( $calls as $call ) {
		same( array( 'Authorization' => 'Bearer AT' ), array_intersect_key( $call[3], array( 'Authorization' => 1 ) ) );
		check( 0 === strpos( $call[1], 'https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Ayementv.tv/' ), $call[1] );
		if ( 'POST' !== $call[0] ) {
			continue;
		}
		same( 'all', $call[2]['dataState'], 'fresh data, as the Search Console UI' );
		same( 'auto', $call[2]['aggregationType'] );
		check( $call[2]['rowLimit'] >= 1 && $call[2]['rowLimit'] <= 25000, 'row limit within the API maximum' );
		if ( in_array( $call[2]['type'], array( 'discover', 'googleNews' ), true ) ) {
			same( false, isset( $call[2]['dimensions'] ), $call[2]['type'] . ' gets totals only' );
		}
	}
	$types = array();
	foreach ( $calls as $call ) {
		if ( 'POST' === $call[0] && ! isset( $call[2]['dimensions'] ) ) {
			$types[ $call[2]['type'] ][] = $call[2]['startDate'] . '..' . $call[2]['endDate'];
		}
	}
	same( array( 'web', 'image', 'video', 'news', 'discover', 'googleNews' ), array_keys( $types ), 'web first, so a broken account fails fast' );
	foreach ( $types as $type => $periods ) {
		same( array( $range['from'] . '..' . $range['to'], $range['prev_from'] . '..' . $range['prev_to'] ), $periods, "$type: current and previous" );
	}
	same( array(), Marsad_Snapshots::errors( 'search_console' ), 'nothing to report' );
}

function test_sc_snapshots_tables_carry_the_previous_period() {
	$c     = marsad_test_sc_connector();
	$range = Marsad_Snapshots::range( '28', '2026-11-03' );
	marsad_test_sc_mock( $range );
	$out = $c->snapshots( '28', $range );

	$q = $out['queries'];
	same( array( 'query' ), $q['dims'] );
	same( array( 'clicks', 'impressions', 'ctr', 'position' ), $q['metrics'] );
	same( 'clicks', $q['sort'] );
	same( 3, $q['total_rows'] );
	same( array( 'yemen tv', 'news', 'live' ), array_column( array_column( $q['rows'], 'd' ), 0 ), 'most clicks first' );
	same( array( 500, 5000, 0.1, 1.4 ), $q['rows'][0]['m'] );
	same( array( 400, 4500, 0.0889, 1.6 ), $q['rows'][0]['p'], 'previous mapped by query, not by position' );
	same( array( 320, 3100, 0.1032, 2.4 ), $q['rows'][1]['p'] );
	same( array( 0, 0, null, null ), $q['rows'][2]['p'], 'absent from a complete previous answer: nothing then' );

	$query_calls = array_values(
		array_filter(
			$GLOBALS['marsad_test_sc_calls'],
			function ( $call ) {
				return array( 'query' ) === ( $call[2]['dimensions'] ?? null );
			}
		)
	);
	same( 2, count( $query_calls ), 'one request per period' );
	same( 'web', $query_calls[0][2]['type'] );
	same( 25000, $query_calls[0][2]['rowLimit'], 'every query asked for, so total_rows is the real count' );

	same( array( 900, 9000, 0.1, 3.0 ), $out['pages']['rows'][0]['m'] );
	same( array( 800, 9000, 0.1, 3.0 ), $out['pages']['rows'][0]['p'] );
	same( array( 'yem' ), $out['countries']['rows'][0]['d'], 'ISO-3 code stored as is' );
	same( 1500, $out['countries']['rows'][0]['p'][0] );
	same( array( 'MOBILE' ), $out['devices']['rows'][0]['d'] );
	same( array( 'VIDEO' ), $out['appearance']['rows'][0]['d'] );
	same( array( 'query', 'page' ), $out['query_pages']['dims'] );
	same( array( 'yemen tv', 'https://yementv.tv/' ), $out['query_pages']['rows'][0]['d'] );
	same( false, isset( $out['query_pages']['rows'][0]['p'] ), 'query→page has no previous period' );

	same( array( 'old query' ), $out['falling']['rows'][0]['d'], 'a query that lost every click falls' );
	same( array( 0, 0, null, null, -50 ), $out['falling']['rows'][0]['m'] );
	same( array( 'yemen tv', 'live' ), array_column( array_column( $out['rising']['rows'], 'd' ), 0 ), 'new query rises too' );

	same(
		array(
			'dims'       => array( 'path', 'lastDownloaded' ),
			'metrics'    => array( 'submitted', 'indexed', 'errors', 'warnings' ),
			'rows'       => array( array( 'd' => array( 'https://yementv.tv/sitemap_index.xml', '2026-11-02 10:11' ), 'm' => array( 1200, 0, 0, 1 ) ) ),
			'sort'       => 'submitted',
			'total_rows' => 1,
		),
		$out['sitemaps']
	);
}

function test_sc_all_time_starts_sixteen_months_back_and_has_no_previous() {
	$c = marsad_test_sc_connector();
	same( gmdate( 'Y-m-d', time() - 486 * DAY_IN_SECONDS ), $c->history_start() );
	$range = Marsad_Snapshots::range( 'all', '2026-11-03', '2025-07-05' );
	marsad_test_sc_mock( $range );
	$out = $c->snapshots( 'all', $range );
	same( array( 'value' => 4090, 'prev' => null ), $out['totals']['metrics']['clicks'] );
	same( false, isset( $out['queries']['rows'][0]['p'] ), 'nothing to compare with' );
	same( false, isset( $out['rising'] ) || isset( $out['falling'] ), 'no movers without a previous period' );
	check( isset( $out['opportunities'], $out['sitemaps'] ) );
	foreach ( $GLOBALS['marsad_test_sc_calls'] as $call ) {
		if ( 'POST' === $call[0] ) {
			same( '2025-07-05', $call[2]['startDate'], 'only the range itself is asked for' );
		}
	}
}

function test_sc_a_failing_report_is_dropped_and_noted() {
	$c     = marsad_test_sc_connector();
	$range = Marsad_Snapshots::range( '7', '2026-11-03' );
	marsad_test_sc_mock( $range, array( 'discover', 'page' ) );
	$out = $c->snapshots( '7', $range );
	same( false, isset( $out['totals_discover'] ), 'rejected type dropped' );
	same( false, isset( $out['pages'] ), 'rejected table dropped' );
	check( isset( $out['totals_googleNews'], $out['queries'], $out['countries'] ), 'the others still arrive' );
	$msg = Marsad_Snapshots::errors( 'search_console' )['7']['message'];
	check( false !== strpos( $msg, 'Discover: HTTP 400: Bad request for discover' ), $msg );
	check( false !== strpos( $msg, 'Pages clicked from Google: HTTP 400' ), $msg );

	// Without the web totals nothing else can work: the whole range fails, explained.
	marsad_test_sc_mock( $range, array( 'web' ) );
	throws(
		function () use ( $c, $range ) {
			$c->snapshots( '7', $range );
		},
		'HTTP 400'
	);
	same( 1, count( $GLOBALS['marsad_test_sc_calls'] ), 'stopped at the first request' );
}

function test_sc_refresh_stores_every_range() {
	$db = marsad_test_snapshots_db();
	$c  = marsad_test_sc_connector();
	marsad_test_sc_mock( Marsad_Snapshots::range( '28' ) );
	$written = Marsad_Snapshots::refresh( $c, time() + 60 );
	check( $written > 5 * 15, "reports written: $written" );
	foreach ( Marsad_Snapshots::RANGES as $key ) {
		check( null !== Marsad_Snapshots::get( 'search_console', $key, 'totals' ), "range $key stored" );
		check( null !== Marsad_Snapshots::get( 'search_console', $key, 'sitemaps' ), "sitemaps under $key" );
	}
	same( 4090, Marsad_Snapshots::get( 'search_console', '28', 'totals' )['payload']['metrics']['clicks']['value'] );
	check( count( $db->snapshots ) > 0 );
}

function test_sc_labels_and_formats() {
	$c      = new Marsad_Connector_Search_Console();
	$labels = $c->snapshot_labels();
	foreach ( array( 'clicks', 'impressions', 'ctr', 'position', 'potential_clicks', 'clicks_change', 'query', 'page', 'country', 'device', 'searchAppearance', 'path', 'lastDownloaded', 'submitted', 'indexed', 'errors', 'warnings', 'totals_web', 'totals_discover', 'opportunities', 'rising', 'falling', 'sitemaps' ) as $key ) {
		check( isset( $labels[ $key ] ) && '' !== $labels[ $key ], "label for $key" );
	}
	$f = Marsad_Snapshots::formats( $c );
	same( 'country', $f['country'], 'ISO-3 codes, not names' );
	same( 'link', $f['path'] );
	same( 'link', $f['page'] );
	same( 'percent', $f['ctr'] );
	same( 'decimal', $f['position'] );
}

function test_sc_daily_fetch_asks_for_fresh_data() {
	$c = marsad_test_sc_connector();
	marsad_test_sc_mock( Marsad_Snapshots::range( '7', '2026-11-03' ) );
	$c->fetch( '2026-10-27', '2026-11-02' );
	same( 3, count( $GLOBALS['marsad_test_sc_calls'] ) );
	foreach ( $GLOBALS['marsad_test_sc_calls'] as $call ) {
		same( 'all', $call[2]['dataState'] );
	}
}

function test_sc_parse_rows_and_previous_mapping() {
	$t = Marsad_Connector_Search_Console::parse_rows(
		array(
			'rows' => array(
				marsad_test_sc_row( array( 'b' ), 2, 20, 0.1, 3 ),
				marsad_test_sc_row( array( 'a' ), 5.0, 50, 0.1, 2 ),
				marsad_test_sc_row( array( 'c' ), 2, 10, 0.2, 1 ),
				array( 'keys' => array( 'x', 'y' ), 'clicks' => 9 ),
			),
		),
		array( 'query' )
	);
	same( array( array( 'a' ), array( 'b' ), array( 'c' ) ), array_column( $t['rows'], 'd' ), 'most clicks first; equals keep Google\'s order; wrong key count skipped' );
	same( array( 5, 50, 0.1, 2.0 ), $t['rows'][0]['m'], 'whole counts as integers, ratio and position as floats' );
	same( 3, $t['total_rows'] );
	same( 1, count( Marsad_Connector_Search_Console::top( $t, 1 )['rows'] ) );
	same( 3, Marsad_Connector_Search_Console::top( $t, 1 )['total_rows'], 'cut rows still counted' );

	$prev = Marsad_Connector_Search_Console::parse_rows( array( 'rows' => array( marsad_test_sc_row( array( 'a' ), 1, 10, 0.1, 4 ) ) ), array( 'query' ) );
	$cut  = Marsad_Connector_Search_Console::with_previous( $t, $prev, false );
	same( array( 1, 10, 0.1, 4.0 ), $cut['rows'][0]['p'] );
	same( array( null, null, null, null ), $cut['rows'][1]['p'], 'absent from a cut-off answer: unknown' );
	same( array(), Marsad_Connector_Search_Console::parse_rows( array(), array( 'query' ) )['rows'] );
}

/** A queries table from compact rows: query => array( clicks, impressions, ctr, position ). */
function marsad_test_sc_table( array $rows ) {
	$out = array();
	foreach ( $rows as $q => $m ) {
		$out[] = array( 'd' => array( (string) $q ), 'm' => $m );
	}
	return array(
		'dims'       => array( 'query' ),
		'metrics'    => array( 'clicks', 'impressions', 'ctr', 'position' ),
		'rows'       => $out,
		'sort'       => 'clicks',
		'total_rows' => count( $out ),
	);
}

function test_sc_opportunities_follow_the_rule_exactly() {
	$t = marsad_test_sc_table(
		array(
			// Positions 1–3 set the reachable CTR: median of .30, .20, .10, .0005 = .15.
			'a1'     => array( 300, 1000, 0.30, 1.2 ),
			'a2'     => array( 200, 1000, 0.20, 2.0 ),
			'a3'     => array( 100, 1000, 0.10, 3.4 ),
			'p3low'  => array( 1, 2000, 0.0005, 3.2 ),   // low CTR but above position 4
			// Position 5: median .02 — strictly below qualifies.
			'big'    => array( 50, 10000, 0.005, 5.2 ),  // top impressions: floor = max( 50, 100 )
			'p5good' => array( 40, 400, 0.10, 4.8 ),
			'p5low'  => array( 4, 200, 0.02, 5.0 ),      // equal to the median: not below
			// Position 4 exactly, and 20 exactly, are inside.
			'p4'     => array( 3, 600, 0.005, 4.0 ),
			'p4b'    => array( 30, 600, 0.05, 4.3 ),
			'p20'    => array( 2, 300, 2 / 300, 20.0 ),
			'p20b'   => array( 6, 300, 0.02, 19.8 ),
			// Already above the reachable CTR: potential floored at 0.
			'p6hi'   => array( 50, 200, 0.25, 6.0 ),
			'p6top'  => array( 90, 300, 0.30, 6.1 ),
			'p12a'   => array( 1, 160, 1 / 160, 12.0 ),
			'p12b'   => array( 5, 250, 0.02, 11.6 ),
			// Too few impressions.
			'p8tiny' => array( 0, 99, 0.0, 8.0 ),
			'p8b'    => array( 10, 1000, 0.01, 8.2 ),
			// Beyond position 20.
			'p25'    => array( 0, 5000, 0.0, 25.0 ),
			'p25b'   => array( 10, 500, 0.02, 24.6 ),
		)
	);
	$o = Marsad_Connector_Search_Console::opportunities( $t );
	same( array( 'query' ), $o['dims'] );
	same( array( 'clicks', 'impressions', 'ctr', 'position', 'potential_clicks' ), $o['metrics'] );
	same( 'impressions', $o['sort'] );
	same( array( 'big', 'p4', 'p20', 'p6hi', 'p12a' ), array_column( array_column( $o['rows'], 'd' ), 0 ), 'most impressions first' );
	same( 5, $o['total_rows'] );
	same( array( 50, 10000, 0.005, 5.2, 1450 ), $o['rows'][0]['m'], '10000 × .15 − 50' );
	same( 87, $o['rows'][1]['m'][4], '600 × .15 − 3' );
	same( 43, $o['rows'][2]['m'][4], '300 × .15 − 2' );
	same( 0, $o['rows'][3]['m'][4], 'floored at 0' );
	same( 23, $o['rows'][4]['m'][4], '160 × .15 − 1' );
	same( false, isset( $o['rows'][0]['p'] ) );

	// Small site: the floor is 50 impressions; nobody in the top 3, so no potential is invented.
	$small = Marsad_Connector_Search_Console::opportunities(
		marsad_test_sc_table(
			array(
				'x1' => array( 0, 60, 0.0, 5.0 ),
				'x2' => array( 10, 1000, 0.01, 5.0 ),
				'x3' => array( 0, 49, 0.0, 7.0 ),
				'x4' => array( 5, 500, 0.01, 7.0 ),
			)
		)
	);
	same( array( array( 'x1' ) ), array_column( $small['rows'], 'd' ) );
	same( null, $small['rows'][0]['m'][4] );

	// At most 100 rows, total_rows tells how many qualified.
	$many = array( 'top' => array( 900, 1000, 0.9, 1.0 ) );
	for ( $i = 0; $i < 130; $i++ ) {
		$many[ "q$i" ] = array( 0, 100 + $i, 0.0, 9.0 );
		$many[ "r$i" ] = array( 10, 100, 0.1, 9.0 );
	}
	$o = Marsad_Connector_Search_Console::opportunities( marsad_test_sc_table( $many ) );
	same( 100, count( $o['rows'] ) );
	same( 130, $o['total_rows'] );
	same( array( 'q129' ), $o['rows'][0]['d'] );

	same( array(), Marsad_Connector_Search_Console::opportunities( marsad_test_sc_table( array() ) )['rows'] );
	same( array(), Marsad_Connector_Search_Console::opportunities( array( 'rows' => array( array( 'd' => array( 'a' ), 'm' => array( 1 ) ) ) ) )['rows'], 'unknown table shape: nothing' );
}

function test_sc_movers_follow_the_rule_exactly() {
	$now  = Marsad_Connector_Search_Console::parse_rows(
		array(
			'rows' => array(
				marsad_test_sc_row( array( 'q_up' ), 100, 1000, 0.1, 3 ),
				marsad_test_sc_row( array( 'q_new' ), 30, 300, 0.1, 5 ),
				marsad_test_sc_row( array( 'q_tie_a' ), 20, 200, 0.1, 5 ),
				marsad_test_sc_row( array( 'q_tie_b' ), 15, 150, 0.1, 5 ),
				marsad_test_sc_row( array( 'q_down' ), 10, 900, 0.011, 9 ),
				marsad_test_sc_row( array( 'q_same' ), 7, 70, 0.1, 6 ),
				marsad_test_sc_row( array( 'q_small_down' ), 5, 50, 0.1, 7 ),
			),
		),
		array( 'query' )
	);
	$then = Marsad_Connector_Search_Console::parse_rows(
		array(
			'rows' => array(
				marsad_test_sc_row( array( 'q_down' ), 80, 1000, 0.08, 4 ),
				marsad_test_sc_row( array( 'q_up' ), 40, 800, 0.05, 6 ),
				marsad_test_sc_row( array( 'q_gone' ), 25, 300, 0.0833, 5 ),
				marsad_test_sc_row( array( 'q_tie_a' ), 10, 100, 0.1, 5 ),
				marsad_test_sc_row( array( 'q_small_down' ), 8, 60, 0.1333, 7 ),
				marsad_test_sc_row( array( 'q_same' ), 7, 70, 0.1, 6 ),
				marsad_test_sc_row( array( 'q_tie_b' ), 5, 60, 0.0833, 5 ),
				marsad_test_sc_row( array( 'q_zero' ), 0, 40, 0.0, 30 ),
			),
		),
		array( 'query' )
	);
	$t         = Marsad_Connector_Search_Console::with_previous( $now, $then, true );
	$lost      = Marsad_Connector_Search_Console::lost( $t, $then );
	same( array( array( 'q_gone' ) ), array_column( $lost, 'd' ), 'only lost queries that had clicks' );
	$t['rows'] = array_merge( $t['rows'], $lost );
	// Unknown before (cut-off previous answer): left out of both lists.
	$t['rows'][] = array( 'd' => array( 'q_unknown' ), 'm' => array( 99, 990, 0.1, 2.0 ), 'p' => array( null, null, null, null ) );

	$m = Marsad_Connector_Search_Console::movers( $t );
	$names = function ( $table ) {
		return array_column( array_column( $table['rows'], 'd' ), 0 );
	};
	same( array( 'q_up', 'q_new', 'q_tie_a', 'q_tie_b' ), $names( $m['rising'] ), 'largest gain first, new included, ties in order' );
	same( array( 'q_down', 'q_gone', 'q_small_down' ), $names( $m['falling'] ), 'largest drop first' );
	same( array( 'clicks', 'impressions', 'ctr', 'position', 'clicks_change' ), $m['rising']['metrics'] );
	same( 'clicks_change', $m['falling']['sort'] );
	same( array( 100, 1000, 0.1, 3.0, 60 ), $m['rising']['rows'][0]['m'] );
	same( array( 40, 800, 0.05, 6.0, null ), $m['rising']['rows'][0]['p'] );
	same( 30, end( $m['rising']['rows'][1]['m'] ), 'new query: all its clicks are gain' );
	same( -70, end( $m['falling']['rows'][0]['m'] ) );
	same( array( 0, 0, null, null, -25 ), $m['falling']['rows'][1]['m'] );
	same( 4, $m['rising']['total_rows'] );
	same( 3, $m['falling']['total_rows'] );

	// 50 each at most.
	$rows = array();
	for ( $i = 1; $i <= 60; $i++ ) {
		$rows[] = array( 'd' => array( "u$i" ), 'm' => array( $i, 10, 0.1, 5.0 ), 'p' => array( 0, 0, null, null ) );
	}
	$m = Marsad_Connector_Search_Console::movers( array( 'dims' => array( 'query' ), 'metrics' => array( 'clicks', 'impressions', 'ctr', 'position' ), 'rows' => $rows ) );
	same( 50, count( $m['rising']['rows'] ) );
	same( 60, $m['rising']['total_rows'] );
	same( array( 'u60' ), $m['rising']['rows'][0]['d'] );
	same( array(), $m['falling']['rows'] );

	$none = Marsad_Connector_Search_Console::movers( marsad_test_sc_table( array( 'a' => array( 1, 2, 0.5, 1.0 ) ) ) );
	same( array(), $none['rising']['rows'], 'no previous period, no movers' );
}

function test_sc_sitemaps_prefer_web_contents() {
	$t = Marsad_Connector_Search_Console::sitemaps(
		array(
			'sitemap' => array(
				array(
					'path'     => 'https://yementv.tv/post-sitemap.xml',
					'errors'   => '2',
					'warnings' => '0',
					'contents' => array( array( 'type' => 'web', 'submitted' => '300', 'indexed' => '280' ), array( 'type' => 'image', 'submitted' => '900' ) ),
				),
				array(
					'path'           => 'https://yementv.tv/video-sitemap.xml',
					'lastDownloaded' => '2026-10-30T23:59:00Z',
					'contents'       => array( array( 'type' => 'video', 'submitted' => '40' ), array( 'type' => 'image', 'submitted' => '500' ) ),
				),
				array( 'path' => '' ),
			),
		)
	);
	same( 2, $t['total_rows'] );
	same( array( 'https://yementv.tv/video-sitemap.xml', '2026-10-30 23:59' ), $t['rows'][0]['d'], 'most submitted first' );
	same( array( 540, null, 0, 0 ), $t['rows'][0]['m'], 'no web entry: sum; indexed not reported: unknown' );
	same( array( 'https://yementv.tv/post-sitemap.xml', '' ), $t['rows'][1]['d'] );
	same( array( 300, 280, 2, 0 ), $t['rows'][1]['m'], 'web entry, images do not inflate the page count' );
	same( array(), Marsad_Connector_Search_Console::sitemaps( array() )['rows'] );
}
