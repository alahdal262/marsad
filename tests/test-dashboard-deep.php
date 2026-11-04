<?php
/**
 * The deep dashboard: every tab with range snapshots and without, the report table, the CSV
 * export and the live visitors endpoint.
 *
 * Stubs dispatch through $GLOBALS['marsad_stub_<name>'] like the other test files, so a test
 * can swap one (deny a capability, fail a nonce) and put it back.
 */

foreach (
	array(
		'current_user_can'    => function ( $cap ) { return true; },
		'wp_die'              => function ( $m, $code = 500 ) { throw new Exception( 'wp_die: ' . $m ); },
		'check_admin_referer' => function ( $a ) { return 1; },
		'check_ajax_referer'  => function ( $a ) { return 1; },
		'wp_create_nonce'     => function ( $a ) { return 'nonce-' . $a; },
		'wp_send_json_success' => function ( $d = null ) { throw new Exception( 'json-success: ' . json_encode( $d ) ); },
		'wp_send_json_error'  => function ( $d = null, $code = null ) { throw new Exception( 'json-error ' . (int) $code . ': ' . json_encode( $d ) ); },
		'nocache_headers'     => function () {},
		'sanitize_key'        => function ( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); },
		'wp_unslash'          => function ( $v ) { return $v; },
		'esc_attr__'          => function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); },
		'esc_url'             => function ( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); },
		'get_bloginfo'        => function ( $k ) { return 'Test Site'; },
		'wp_parse_url'        => function ( $u, $c = -1 ) { return parse_url( $u, $c ); },
		'admin_url'           => function ( $p = '' ) { return 'https://example.test/wp-admin/' . $p; },
		'add_query_arg'       => function ( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); },
		'wp_nonce_field'      => function ( $a ) { echo '<input type="hidden" name="_wpnonce" value="x">'; },
		'number_format_i18n'  => function ( $n, $d = 0 ) { return number_format( (float) $n, $d ); },
		'human_time_diff'     => function ( $a, $b ) { return '5 mins'; },
		'is_rtl'              => function () { return true; },
	) as $name => $fn
) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['marsad_stub_' . $name] = $fn;
		eval( "function $name( ...\$a ) { return call_user_func_array( \$GLOBALS['marsad_stub_$name'], \$a ); }" ); // phpcs:ignore
	}
}

/** Runs $fn with one stub replaced, then puts the old one back whatever happens. */
function marsad_test_with_stub( $name, callable $stub, callable $fn ) {
	$old                                = $GLOBALS[ 'marsad_stub_' . $name ];
	$GLOBALS[ 'marsad_stub_' . $name ] = $stub;
	try {
		return $fn();
	} finally {
		$GLOBALS[ 'marsad_stub_' . $name ] = $old;
	}
}

/** Real connectors with their setup checks waived, so they count as connected. */
class Marsad_Test_Deep_Ga4 extends Marsad_Connector_Ga4 {
	public function missing() { return array(); }
}
class Marsad_Test_Deep_Sc extends Marsad_Connector_Search_Console {
	public function missing() { return array(); }
}
class Marsad_Test_Deep_Youtube extends Marsad_Connector_Youtube {
	public function missing() { return array(); }
}

/** Stands in for GA4 in the realtime tests; any connector with realtime() is asked. */
class Marsad_Test_Realtime_Source extends Marsad_Connector {
	public $answer = null;
	public $throw  = false;
	public function id() { return 'ga4'; }
	public function label() { return 'GA4'; }
	public function fetch( $from, $to ) { return array(); }
	public function realtime() {
		if ( $this->throw ) {
			throw new Marsad_Exception( 'quota' );
		}
		return $this->answer;
	}
}

/** A table payload; $rows is a list of array( dims, metrics, prev|null ). */
function marsad_test_table( array $dims, array $metrics, array $rows, $sort = null, $total = null ) {
	$out = array();
	foreach ( $rows as $r ) {
		$row = array( 'd' => $r[0], 'm' => $r[1] );
		if ( isset( $r[2] ) ) {
			$row['p'] = $r[2];
		}
		$out[] = $row;
	}
	return array( 'dims' => $dims, 'metrics' => $metrics, 'rows' => $out, 'sort' => $sort ? $sort : $metrics[0], 'total_rows' => null === $total ? count( $rows ) : $total );
}

/** The GA4 snapshot of one range, as the GA4 connector stores it. */
function marsad_test_ga4_payloads() {
	$pages = array();
	for ( $i = 1; $i <= 30; $i++ ) {
		$pages[] = array( array( '/news/' . $i, 'Story ' . $i ), array( 1000 - $i * 10, 700 - $i * 5, 52000 - $i * 100, 0.6 ), array( 900 - $i * 10, null, null, null ) );
	}
	$pages[0] = array( array( '/%D8%A7%D9%84%D9%8A%D9%85%D9%86', 'أخبار اليمن' ), array( 5000, 4000, 400000, 0.62 ), array( 4000, 3500, 300000, 0.6 ) );
	$pages[1] = array( array( '/x', '<script>alert(1)</script>' ), array( 3000, 2800, 9000, 0.12 ), array( 2900, null, null, null ) );
	return array(
		'totals'        => array(
			'metrics' => array(
				'activeUsers'     => array( 'value' => 5138, 'prev' => 4801 ),
				'sessions'        => array( 'value' => 9100, 'prev' => 8000 ),
				'screenPageViews' => array( 'value' => 21000, 'prev' => 20000 ),
				'engagementRate'  => array( 'value' => 0.53, 'prev' => 0.5 ),
				'bounceRate'      => array( 'value' => 0.47, 'prev' => 0.5 ),
			),
		),
		'pages'         => marsad_test_table( array( 'pagePath', 'pageTitle' ), array( 'screenPageViews', 'activeUsers', 'userEngagementDuration', 'engagementRate' ), $pages, 'screenPageViews', 5206 ),
		'channels'      => marsad_test_table(
			array( 'sessionDefaultChannelGroup' ),
			array( 'sessions', 'activeUsers', 'engagementRate', 'keyEvents' ),
			array(
				array( array( 'Organic Search' ), array( 3000, 2500, 0.6, 10 ), array( 2000, 1800, 0.55, 8 ) ),
				array( array( 'Direct' ), array( 1500, 1400, 0.5, 4 ), array( 1450, 1300, 0.5, 4 ) ),
				array( array( 'Organic Social' ), array( 600, 550, 0.4, 1 ), array( 700, 650, 0.4, 2 ) ),
			)
		),
		'countries'     => marsad_test_table(
			array( 'country' ),
			array( 'activeUsers', 'sessions', 'screenPageViews' ),
			array(
				array( array( 'Yemen' ), array( 2000, 3000, 9000 ), array( 1800, 2700, 8000 ) ),
				array( array( 'Saudi Arabia' ), array( 900, 1200, 3000 ), array( 500, 700, 1800 ) ),
				array( array( 'United States' ), array( 300, 350, 600 ), array( 290, 300, 500 ) ),
			)
		),
		'devices'       => marsad_test_table( array( 'deviceCategory' ), array( 'activeUsers', 'sessions', 'engagementRate' ), array( array( array( 'mobile' ), array( 3500, 6000, 0.5 ) ), array( array( 'desktop' ), array( 1400, 2800, 0.6 ) ), array( array( 'tablet' ), array( 238, 300, 0.5 ) ) ) ),
		'new_returning' => marsad_test_table( array( 'newVsReturning' ), array( 'activeUsers', 'sessions', 'engagementRate' ), array( array( array( 'new' ), array( 4000, 5000, 0.4 ) ), array( array( 'returning' ), array( 1138, 4100, 0.7 ) ) ) ),
		'heatmap'       => marsad_test_table( array( 'dayOfWeek', 'hour' ), array( 'activeUsers' ), array( array( array( '1', '14' ), array( 420 ) ), array( array( '5', '09' ), array( 80 ) ), array( array( '0', '23' ), array( 12 ) ) ) ),
		'age'           => marsad_test_table( array( 'userAgeBracket' ), array( 'activeUsers' ), array() ),
		'landing'       => marsad_test_table( array( 'landingPage' ), array( 'sessions', 'newUsers', 'engagementRate', 'keyEvents' ), array( array( array( '/' ), array( 2000, 900, 0.5, 3 ) ) ) ),
		'post_dates'    => marsad_test_table( array( 'customEvent:googlesitekit_post_date' ), array( 'screenPageViews' ), array( array( array( '20240115' ), array( 100 ) ), array( array( '20240203' ), array( 50 ) ), array( array( '20231201' ), array( 30 ) ) ) ),
	);
}

function marsad_test_sc_payloads() {
	$totals = function ( $clicks, $impr ) {
		return array( 'metrics' => array( 'clicks' => array( 'value' => $clicks, 'prev' => $clicks - 100 ), 'impressions' => array( 'value' => $impr, 'prev' => $impr ), 'ctr' => array( 'value' => $clicks / $impr, 'prev' => null ), 'position' => array( 'value' => 7.4, 'prev' => 8.1 ) ) );
	};
	return array(
		'totals'        => $totals( 4090, 120000 ),
		'totals_web'    => $totals( 4090, 120000 ),
		'totals_image'  => $totals( 310, 35423 ),
		'queries'       => marsad_test_table( array( 'query' ), array( 'clicks', 'impressions', 'ctr', 'position' ), array( array( array( 'اليمن اليوم' ), array( 900, 9000, 0.1, 2.1 ), array( 800, 8000, 0.1, 2.4 ) ), array( array( '=HYPERLINK("x")' ), array( 3, 50, 0.06, 9 ), array( 0, 0, 0, 0 ) ) ), 'clicks', 5206 ),
		'rising'        => marsad_test_table( array( 'query' ), array( 'clicks', 'impressions', 'ctr', 'position' ), array( array( array( 'أخبار عدن' ), array( 120, 900, 0.13, 3.2 ), array( 20, 500, 0.04, 6 ) ) ) ),
		'opportunities' => marsad_test_table( array( 'query' ), array( 'clicks', 'impressions', 'ctr', 'position', 'potential_clicks' ), array( array( array( 'سعر الصرف' ), array( 5, 2000, 0.0025, 6.4, 85 ) ), array( array( 'عدن' ), array( 2, 900, 0.002, 8, 20 ) ) ) ),
		'countries'     => marsad_test_table( array( 'country' ), array( 'clicks', 'impressions', 'ctr', 'position' ), array( array( array( 'yem' ), array( 3000, 50000, 0.06, 5 ) ), array( array( 'sau' ), array( 600, 30000, 0.02, 8 ) ) ) ),
	);
}

/**
 * A site with WordPress, Site, GA4, Search Console and YouTube connected, snapshots for 28 days
 * (unless $snapshots is false) and daily rows for charts.
 *
 * @return Marsad_Test_Snapshot_Wpdb
 */
function marsad_test_deep_site( $snapshots = true ) {
	$db = marsad_test_snapshots_db();
	marsad_test_registry( array( new Marsad_Connector_Wordpress(), new Marsad_Connector_Site(), new Marsad_Test_Deep_Ga4(), new Marsad_Test_Deep_Sc(), new Marsad_Test_Deep_Youtube() ) );
	for ( $i = 1; $i <= 56; $i++ ) {
		$d             = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		$db->fixture[] = array( 'wordpress', 'posts', '', $d, $i <= 28 ? 20 : 15 );
		$db->fixture[] = array( 'ga4', 'users', '', $d, 200 );
		$db->fixture[] = array( 'search_console', 'clicks', '', $d, 140 );
		$db->fixture[] = array( 'youtube', 'subscribers', '', $d, 10000 + ( 56 - $i ) * 10 );
	}
	$db->fixture[] = array( 'site', 'posts_total', '', gmdate( 'Y-m-d' ), 52006 );
	update_option( Marsad_Sync::OPT_STATUS, array( 'wordpress' => array( 'at' => time(), 'ok' => true ), 'ga4' => array( 'at' => time(), 'ok' => true ), 'search_console' => array( 'at' => time(), 'ok' => true ), 'youtube' => array( 'at' => time(), 'ok' => false, 'message' => 'The API key is <b>not valid</b>.' ) ) );
	if ( $snapshots ) {
		foreach ( marsad_test_ga4_payloads() as $k => $p ) {
			Marsad_Snapshots::put( 'ga4', '28', $k, $p );
		}
		foreach ( marsad_test_sc_payloads() as $k => $p ) {
			Marsad_Snapshots::put( 'search_console', '28', $k, $p );
		}
	}
	return $db;
}

function marsad_test_deep_render( $tab, $range = 28 ) {
	$_GET = array( 'tab' => $tab, 'range' => $range );
	ob_start();
	try {
		Marsad_Admin_Dashboard::render();
	} finally {
		$html = ob_get_clean();
	}
	return $html;
}

function marsad_test_has( $html, array $needles, $where ) {
	foreach ( $needles as $n ) {
		check( false !== strpos( $html, $n ), "$where shows: $n" );
	}
}

/* ── every tab, fresh site ───────────────────────────────────────── */

function test_every_tab_renders_on_a_fresh_site_without_snapshots() {
	foreach ( array_keys( Marsad_Admin_Dashboard::tabs() ) as $tab ) {
		$h = marsad_test_deep_render( $tab );
		check( false !== strpos( $h, 'aria-current="page"' ), "$tab renders" );
	}
	$h = marsad_test_deep_render( 'audience' );
	check( false !== strpos( $h, 'once Google Analytics 4 is connected' ), 'audience explains what connecting brings' );
	check( false === strpos( $h, 'data-marsad-realtime' ), 'no live card without GA4' );
	$h = marsad_test_deep_render( 'search' );
	check( false !== strpos( $h, 'once Search Console is connected' ), 'search explains what connecting brings' );
	$h = marsad_test_deep_render( 'overview' );
	check( false !== strpos( $h, 'Findings appear here once' ), 'findings empty state' );
	check( false !== strpos( $h, 'Every platform at a glance' ), 'platform grid even on a fresh site' );
	check( false === strpos( $h, 'The audience in pictures' ), 'no infographics without Google sources' );
}

function test_connected_sources_without_snapshots_say_the_reports_are_coming() {
	marsad_test_deep_site( false );
	Marsad_Snapshots::record_error( 'ga4', '28', 'Quota exceeded for <property>' );
	foreach ( array( 'overview', 'audience', 'content', 'search' ) as $tab ) {
		marsad_test_deep_render( $tab );
	}
	$h = marsad_test_deep_render( 'audience' );
	marsad_test_has( $h, array( 'The reports for this period are on their way', 'data-marsad-realtime', 'nonce-marsad_realtime', 'Quota exceeded for &lt;property&gt;' ), 'audience without snapshots' );
	$h = marsad_test_deep_render( 'search' );
	check( false !== strpos( $h, 'The reports for this period are on their way' ), 'search without snapshots' );
	$h = marsad_test_deep_render( 'overview' );
	marsad_test_has( $h, array( '>Visitors<', 'daily average', '>200<', 'Recorded on the next sync.' ), 'overview falls back to the daily average' );
}

/* ── overview ────────────────────────────────────────────────────── */

function test_overview_is_an_infographic_of_every_platform() {
	marsad_test_deep_site();
	$h = marsad_test_deep_render( 'overview' );

	// Headline: GA4's own deduplicated users, not the daily average.
	marsad_test_has( $h, array( '>Visitors<', '5,138', '21,000', '4,090', 'Posts published', '▲ 7.0%' ), 'headline' );
	check( false === strpos( $h, 'daily average' ), 'snapshot users are not labelled as a daily average' );

	// One card per enabled source, with numbers, and the failing one in its error state.
	foreach ( array( 'Google Analytics 4', 'Google Search Console', 'YouTube', 'WordPress', 'Site &amp; hosting' ) as $label ) {
		check( preg_match( '#<article class="marsad-pcard[^"]*"[^>]*><header><span class="marsad-badge"[^>]*>.</span><h4>' . preg_quote( $label, '#' ) . '</h4>#u', $h ), "platform card for $label" );
	}
	marsad_test_has( $h, array( 'Engagement rate', '53.0%', 'Posts per day', '52,006', 'Last sync failed', 'The API key is &lt;b&gt;not valid&lt;/b&gt;.', 'tab=audience', 'tab=search', '#marsad-src-youtube' ), 'platform cards' );
	check( 1 === substr_count( $h, 'marsad-pcard-bad' ), 'only the failing source shows the error state' );

	// Infographic row.
	check( 1 === preg_match( '#<svg class="marsad-donut" viewBox="0 0 42 42" role="img" aria-label="Traffic channels: Organic Search 58.8%، Direct 29.4%#', $h ), 'channels donut, accessible' );
	marsad_test_has( $h, array( 'aria-label="Devices: Mobile 68.1%', 'aria-label="New and returning visitors: New 77.9%', '>YE<', '>SA<', '>US<', 'aria-label="Search clicks: Web search 93.0%، Image search 7.0%"' ), 'infographics' );

	// Findings, linked to the tab that explains them.
	marsad_test_has( $h, array( 'Key findings', 'Sessions from Organic Search rose 50.0%', 'href="https://example.test/wp-admin/admin.php?page=marsad&amp;tab=audience&amp;range=28#marsad-ga4-channels"', 'aria-label="Details in Audience"' ), 'findings' );

	// The posts chart stays.
	check( substr_count( $h, '<i style="height:' ) >= 28, 'daily posts chart' );
}

function test_overview_platform_card_lists_unconnected_platforms_for_managers() {
	marsad_test_deep_site();
	// Facebook is known but switched off.
	marsad_test_registry( array_merge( array_values( Marsad_Registry::all() ), array( new Marsad_Connector_Facebook() ) ) );
	$saved                        = Marsad_Registry::saved();
	$saved['facebook']['enabled'] = false;
	update_option( Marsad_Registry::OPT, $saved );
	$h = marsad_test_deep_render( 'overview' );
	marsad_test_has( $h, array( 'Not connected yet', 'Facebook', 'open=facebook', '+ Add a source' ), 'not connected card' );
}

/* ── audience ────────────────────────────────────────────────────── */

function test_audience_tab_shows_totals_live_card_heatmap_and_tables() {
	marsad_test_deep_site();
	$h = marsad_test_deep_render( 'audience' );
	marsad_test_has( $h, array( 'fetched 5 mins ago', '5,138', '53.0%', 'data-marsad-realtime', 'data-url="https://example.test/wp-admin/admin-ajax.php"', 'Right now' ), 'audience top' );
	// A lower bounce rate is good news.
	check( false !== strpos( $h, '<span class="marsad-change marsad-good">▼ 6.0%</span>' ), 'bounce rate fall is green' );
	// Heatmap: a drawing with words, and the numbers as a table.
	check( 1 === preg_match( '#<div class="marsad-heat" role="img" aria-label="When visitors come\. Busiest: Mon at 14:00, with 420 active users\."#', $h ), 'heatmap summary' );
	check( 7 * 24 === substr_count( $h, '<i style="--a:' ), 'a cell per weekday and hour' );
	check( 1 === preg_match( '#<table class="marsad-sr"><caption>When visitors come</caption>#', $h ), 'heatmap as a hidden table' );
	marsad_test_has( $h, array( 'id="marsad-ga4-channels"', 'id="marsad-ga4-countries"', 'id="marsad-ga4-devices"', 'Not available for this property' ), 'audience tables' );
}

/* ── content ─────────────────────────────────────────────────────── */

function test_content_tab_adds_google_analytics_tables_and_content_age() {
	marsad_test_deep_site();
	$h = marsad_test_deep_render( 'content' );
	marsad_test_has( $h, array( 'id="marsad-ga4-pages"', 'Avg. engagement time', 'Top 30 of 5,206', 'Landing pages', 'Content age', 'Highest: Jan 2024 with 100' ), 'content tab' );
	// Average engagement time per user = userEngagementDuration / activeUsers: 400000 / 4000 = 100 s.
	check( false !== strpos( $h, '>1:40<' ), 'derived engagement time' );
	// The daily "Most read" fallback is replaced by the snapshot table.
	check( false === strpos( $h, 'appear here once Google Analytics 4 is connected' ), 'no connect callout' );
}

/* ── search ──────────────────────────────────────────────────────── */

function test_search_tab_shows_types_queries_opportunities_and_movers() {
	marsad_test_deep_site();
	$h = marsad_test_deep_render( 'search' );
	marsad_test_has( $h, array( 'Clicks from Web search', 'Clicks from Image search', '35,423', '7.4', 'اليمن اليوم', 'id="marsad-search_console-opportunities"', 'سعر الصرف', 'id="marsad-search_console-rising"', 'Top 2 of 5,206' ), 'search tab' );
	check( false === strpos( $h, 'Clicks from Video search' ), 'only types that were fetched' );
	// Position going down (7.4 from 8.1) is good news.
	check( false !== strpos( $h, '<span class="marsad-mini"><span>Search impressions <b>120,000</b></span>' ), 'type card details' );
}

/* ── the table component ─────────────────────────────────────────── */

function marsad_test_render_table( array $payload, array $opt = array(), $c = null ) {
	ob_start();
	Marsad_Admin_Table::render( $c ? $c : new Marsad_Test_Deep_Ga4(), '28', 'pages', $payload, $opt );
	return ob_get_clean();
}

function test_table_sorts_filters_shows_25_and_exports() {
	$p = marsad_test_ga4_payloads()['pages'];
	$h = marsad_test_render_table( $p, array( 'title' => 'Most read', 'derive' => array( 'avgEngagement' ), 'columns' => array( 'screenPageViews', 'activeUsers', 'avgEngagement', 'engagementRate' ) ) );
	$views = esc_html( Marsad_Admin_Table::label( new Marsad_Test_Deep_Ga4(), 'screenPageViews' ) );
	check( false !== strpos( $h, '<th scope="col" aria-sort="descending" class="marsad-th-num"><button type="button" class="marsad-sort" data-type="num">' . $views . '</button></th>' ), 'sorted column marked' );
	check( 1 === substr_count( $h, 'aria-sort=' ), 'one sorted column' );
	check( false !== strpos( $h, 'class="marsad-filter" hidden' ), 'filter box waits for JavaScript' );
	check( false !== strpos( $h, '<caption class="marsad-sr">Most read</caption>' ), 'table has a name' );
	list( $first, $rest ) = explode( '<details class="marsad-showall">', $h );
	same( 25, substr_count( $first, '<tr><td' ), 'first 25 rows in the table' );
	same( 5, substr_count( $rest, '<tr><td' ), 'the rest behind Show all' );
	check( false !== strpos( $h, '<summary>Show all 30</summary>' ), 'show all, readable without JS' );
	check( false !== strpos( $h, 'Top 30 of 5,206' ), 'total rows note' );
	// Untrusted titles are escaped; Arabic paths are shown decoded and linked on this site.
	check( false === strpos( $h, '<script>' ) && false !== strpos( $h, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'escaped title' );
	check( false !== strpos( $h, 'href="https://example.test/%D8%A7%D9%84%D9%8A%D9%85%D9%86"' ) && false !== strpos( $h, '>أخبار اليمن</a><small class="marsad-cell-path" dir="ltr">/اليمن</small>' ), 'title links to the path' );
	// Change column: +25% on views; userEngagementDuration hidden, engagement time derived.
	check( false !== strpos( $h, '▲ 25.0%' ), 'change badge' );
	check( false === strpos( $h, '>' . esc_html( Marsad_Admin_Table::label( new Marsad_Test_Deep_Ga4(), 'userEngagementDuration' ) ) . '<' ), 'only chosen columns' );
	check( false !== strpos( $h, '62.0%' ), 'ratio shown as percent' );
	check( false !== strpos( $h, 'admin-post.php?action=marsad_export&amp;source=ga4&amp;range=28&amp;report=pages&amp;_wpnonce=nonce-marsad_export' ), 'export link with nonce' );
}

function test_table_without_rows_or_previous_period() {
	$h = marsad_test_render_table( array( 'dims' => array( 'userAgeBracket' ), 'metrics' => array( 'activeUsers' ), 'rows' => array() ), array( 'empty' => 'Not available here.' ) );
	same( '<p class="marsad-sub marsad-empty">Not available here.</p>', $h );
	$h = marsad_test_render_table( marsad_test_table( array( 'browser' ), array( 'activeUsers' ), array( array( array( 'Chrome' ), array( 10 ) ) ) ) );
	check( false === strpos( $h, '>Change<' ), 'no change column without a previous period' );
	check( false !== strpos( $h, '1 row' ) && false === strpos( $h, 'marsad-showall' ), 'small table' );
	// A malformed payload reads as missing data, not as a crash.
	$h = marsad_test_render_table( array( 'dims' => 'x', 'metrics' => array( 'a', 5 ), 'rows' => array( 'junk', array( 'd' => array( array( 1 ) ), 'm' => array( 'NaN' ) ) ) ) );
	check( false !== strpos( $h, '—' ), 'non-numeric value shown as a dash' );
	// Links only for web addresses and this site's paths.
	same( '', Marsad_Admin_Table::href( 'javascript:alert(1)' ) );
	same( '', Marsad_Admin_Table::href( '//evil.test/x' ) );
	same( 'https://example.test/a', Marsad_Admin_Table::href( '/a' ) );
}

function test_value_formats() {
	same( '53.0%', Marsad_Admin_Table::fmt( 0.53, 'percent' ) );
	same( '7.4', Marsad_Admin_Table::fmt( 7.43, 'decimal' ) );
	same( '1:40', Marsad_Admin_Table::fmt( 100, 'seconds' ) );
	same( '14.4 h', Marsad_Admin_Table::fmt( 52000, 'duration' ) );
	same( '5,138', Marsad_Admin_Table::fmt( 5138, 'number' ) );
	same( '—', Marsad_Admin_Table::fmt( null, 'number' ) );
	same( 'YE', Marsad_Admin_Infographics::country_code( 'Yemen' ) );
	same( 'YEM', Marsad_Admin_Infographics::country_code( 'yem' ) );
	same( '', Marsad_Admin_Infographics::country_code( 'Atlantis' ) );
}

/* ── CSV export ──────────────────────────────────────────────────── */

/** CSV text (BOM optional) as rows of cells. */
function marsad_test_csv( $body ) {
	$body = 0 === strpos( $body, "\xEF\xBB\xBF" ) ? substr( $body, 3 ) : $body;
	$h    = fopen( 'php://temp', 'r+' );
	fwrite( $h, $body );
	rewind( $h );
	$rows = array();
	while ( false !== ( $row = fgetcsv( $h, 0, ',', '"', '' ) ) ) {
		$rows[] = $row;
	}
	fclose( $h );
	return $rows;
}

/** Labels as the export writes them, whether or not the connector translates its keys yet. */
function marsad_test_labels( Marsad_Connector $c, array $keys ) {
	return array_map(
		function ( $k ) use ( $c ) {
			return Marsad_Admin_Table::label( $c, $k );
		},
		$keys
	);
}

function test_csv_export_has_bom_translated_header_and_every_row() {
	marsad_test_deep_site();
	$file = Marsad_Admin_Table::export( 'ga4', '28', 'pages' );
	check( 0 === strpos( $file['body'], "\xEF\xBB\xBF" ), 'UTF-8 BOM' );
	same( 'marsad-ga4-pages-28-' . gmdate( 'Y-m-d' ) . '.csv', $file['name'] );
	$rows = marsad_test_csv( $file['body'] );
	same( 31, count( $rows ), 'header + all 30 stored rows' );
	$l    = marsad_test_labels( new Marsad_Test_Deep_Ga4(), array( 'pagePath', 'pageTitle', 'screenPageViews', 'activeUsers', 'userEngagementDuration', 'engagementRate' ) );
	$head = array( $l[0], $l[1], $l[2], $l[3], $l[4], $l[5] . ' (%)' );
	foreach ( array( $l[2], $l[3], $l[4], $l[5] . ' (%)' ) as $m ) {
		$head[] = $m . ', previous period';
	}
	same( $head, $rows[0], 'translated header, previous period columns' );
	same( array( '/%D8%A7%D9%84%D9%8A%D9%85%D9%86', 'أخبار اليمن', '5000', '4000', '400000', '62', '4000', '3500', '300000', '60' ), $rows[1], 'Arabic intact, ratios as percentages' );

	$file = Marsad_Admin_Table::export( 'search_console', '28', 'queries' );
	same( "'=HYPERLINK(\"x\")", marsad_test_csv( $file['body'] )[2][0], 'formula-like query kept as text' );

	$file = Marsad_Admin_Table::export( 'ga4', '28', 'totals' );
	$rows = marsad_test_csv( $file['body'] );
	same( array( 'Metric', 'Value', 'Previous period' ), $rows[0] );
	same( array( marsad_test_labels( new Marsad_Test_Deep_Ga4(), array( 'activeUsers' ) )[0], '5138', '4801' ), $rows[1], 'totals as metric rows' );

	same( null, Marsad_Admin_Table::export( 'ga4', '28', 'nope' ), 'unknown report' );
	same( null, Marsad_Admin_Table::export( 'ga4', '29', 'pages' ), 'unknown range' );
	same( null, Marsad_Admin_Table::export( 'nobody', '28', 'pages' ), 'unknown source' );
}

function test_csv_export_refuses_without_capability_or_nonce() {
	marsad_test_deep_site();
	$_GET = $_REQUEST = array( 'action' => 'marsad_export', 'source' => 'ga4', 'range' => '28', 'report' => 'pages', '_wpnonce' => 'x' );
	$asked = array();
	marsad_test_with_stub(
		'current_user_can',
		function ( $cap ) use ( &$asked ) {
			$asked[] = $cap;
			return false;
		},
		function () {
			throws( array( 'Marsad_Admin', 'handle_export' ), 'wp_die: You are not allowed' );
		}
	);
	same( array( 'marsad_view' ), $asked, 'the view capability is what counts' );
	marsad_test_with_stub(
		'check_admin_referer',
		function ( $a ) {
			throw new Exception( 'bad nonce for ' . $a );
		},
		function () {
			throws( array( 'Marsad_Admin', 'handle_export' ), 'bad nonce for marsad_export' );
		}
	);
	$_GET['report'] = 'nope';
	throws( array( 'Marsad_Admin', 'handle_export' ), 'wp_die: This report is not available' );
	$_GET = $_REQUEST = array();
}

/** The real handler, in a PHP process of its own, because it ends with exit after streaming. */
function test_csv_export_streams_the_file() {
	$script = '<?php
		require ' . var_export( __DIR__ . '/bootstrap.php', true ) . ';
		set_error_handler( function ( $no, $msg ) { fwrite( STDERR, "PHP: $msg\n" ); exit( 3 ); } );
		foreach ( glob( ' . var_export( __DIR__, true ) . ' . "/test-*.php" ) as $f ) { require_once $f; }
		marsad_test_reset();
		marsad_test_deep_site();
		$_GET = $_REQUEST = array( "source" => "search_console", "range" => "28", "report" => "rising", "_wpnonce" => "x" );
		Marsad_Admin::handle_export();
		echo "NOT REACHED";';
	$file = tempnam( sys_get_temp_dir(), 'marsad' );
	file_put_contents( $file, $script );
	$out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	unlink( $file );
	check( 0 === strpos( $out, "\xEF\xBB\xBF" ), 'starts with the BOM: ' . substr( $out, 0, 200 ) );
	$l = marsad_test_labels( new Marsad_Test_Deep_Sc(), array( 'query', 'clicks', 'impressions', 'ctr', 'position' ) );
	same(
		array(
			array( $l[0], $l[1], $l[2], $l[3] . ' (%)', $l[4], $l[1] . ', previous period', $l[2] . ', previous period', $l[3] . ' (%), previous period', $l[4] . ', previous period' ),
			array( 'أخبار عدن', '120', '900', '13', '3.2', '20', '500', '4', '6' ),
		),
		marsad_test_csv( $out ),
		'the streamed file, and nothing after it'
	);
}

/* ── live visitors ───────────────────────────────────────────────── */

function marsad_test_realtime_call() {
	try {
		Marsad_Admin::ajax_realtime();
	} catch ( Exception $e ) {
		return $e->getMessage();
	}
	return 'no answer';
}

function test_realtime_endpoint_checks_capability_and_nonce() {
	$src         = new Marsad_Test_Realtime_Source();
	$src->answer = array( 'active' => 3 );
	marsad_test_registry( array( $src ) );
	$asked = array();
	$out   = marsad_test_with_stub(
		'current_user_can',
		function ( $cap ) use ( &$asked ) {
			$asked[] = $cap;
			return false;
		},
		'marsad_test_realtime_call'
	);
	check( 0 === strpos( $out, 'json-error 403:' ), $out );
	same( array( 'marsad_view' ), $asked );
	$out = marsad_test_with_stub(
		'check_ajax_referer',
		function ( $a ) {
			throw new Exception( 'bad nonce for ' . $a );
		},
		'marsad_test_realtime_call'
	);
	same( 'bad nonce for marsad_realtime', $out );
}

function test_realtime_endpoint_answers_in_one_shape() {
	$src         = new Marsad_Test_Realtime_Source();
	$src->answer = array(
		'active'    => 42.4,
		'pages'     => array( 'dims' => array( 'unifiedScreenName' ), 'metrics' => array( 'activeUsers' ), 'rows' => array( array( 'd' => array( 'الرئيسية' ), 'm' => array( 30 ) ) ) ),
		'countries' => array( 'Yemen' => 20, 'Egypt' => 2 ),
		'devices'   => array( array( 'deviceCategory' => 'mobile', 'activeUsers' => 40 ), array( 'desktop', 2 ), array( 'junk' ) ),
	);
	marsad_test_registry( array( $src ) );
	$out = marsad_test_realtime_call();
	same( 'json-success: ' . json_encode( array( 'active' => 42, 'pages' => array( array( 'name' => 'الرئيسية', 'value' => 30 ) ), 'countries' => array( array( 'name' => 'Yemen', 'value' => 20 ), array( 'name' => 'Egypt', 'value' => 2 ) ), 'devices' => array( array( 'name' => 'mobile', 'value' => 40 ), array( 'name' => 'desktop', 'value' => 2 ) ) ) ), $out );

	$src->throw = true;
	check( 0 === strpos( marsad_test_realtime_call(), 'json-error 0:' ), 'a failing call is an error answer, not a crash' );

	// A source without realtime() (or GA4 not connected) answers "not available".
	marsad_test_registry( array( new Marsad_Test_Snapshot_Connector() ) );
	check( false !== strpos( marsad_test_realtime_call(), 'Live numbers are not available right now.' ) );
}
