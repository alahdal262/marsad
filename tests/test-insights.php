<?php
/**
 * Key findings: each rule speaks on crafted snapshots, and every rule stays silent when its
 * data is missing or too thin. Uses the deep dashboard fixture (tests/test-dashboard-deep.php).
 */

foreach (
	array(
		'number_format_i18n' => function ( $n, $d = 0 ) { return number_format( (float) $n, $d ); },
	) as $name => $fn
) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['marsad_stub_' . $name] = $fn;
		eval( "function $name( ...\$a ) { return call_user_func_array( \$GLOBALS['marsad_stub_$name'], \$a ); }" ); // phpcs:ignore
	}
}

/** Findings keyed by the anchor of the report they explain ('' for posts). */
function marsad_test_findings( $range = '28' ) {
	$out = array();
	foreach ( Marsad_Insights::from_snapshots( $range ) as $f ) {
		check( in_array( $f['tone'], array( 'good', 'bad', 'neutral' ), true ), 'tone' );
		check( isset( Marsad_Admin_Dashboard::tabs()[ $f['tab'] ] ), 'links to a real tab: ' . $f['tab'] );
		// Queries and titles arrive wrapped in Unicode isolates; compared here without them.
		$f['text'] = str_replace( array( "\u{2068}", "\u{2069}" ), '', $f['text'] );
		$out[]     = $f;
	}
	return $out;
}

function marsad_test_finding_with( array $findings, $needle ) {
	foreach ( $findings as $f ) {
		if ( false !== strpos( $f['text'], $needle ) ) {
			return $f;
		}
	}
	throw new Exception( "no finding mentions: $needle — got: " . implode( ' / ', array_column( $findings, 'text' ) ) );
}

function test_insights_are_silent_without_data() {
	same( array(), Marsad_Insights::from_snapshots( '28' ), 'fresh site' );
	marsad_test_deep_site( false );
	// Daily rows exist for posts and YouTube, but the snapshot rules have nothing to read.
	$texts = array_column( marsad_test_findings(), 'text' );
	same( 2, count( $texts ), 'only the two daily rules: ' . implode( ' / ', $texts ) );
	// All time has no previous period: nothing to compare, so nothing to say about change.
	same( array(), Marsad_Insights::from_snapshots( 'all' ), 'all time' );
	// Unknown ranges read as 28 days instead of failing.
	same( count( $texts ), count( Marsad_Insights::from_snapshots( 'bogus' ) ) );
}

function test_insights_from_a_full_period() {
	marsad_test_deep_site();
	$all = marsad_test_findings();
	check( count( $all ) <= Marsad_Insights::MAX, 'eight at most' );
	same( Marsad_Insights::MAX, count( $all ), 'every rule has data here, so the list is full' );

	$f = marsad_test_finding_with( $all, 'Sessions from Organic Search rose 50.0%, from 2,000 to 3,000.' );
	same( array( 'good', 'audience', 'ga4-channels' ), array( $f['tone'], $f['tab'], $f['anchor'] ) );

	$f = marsad_test_finding_with( $all, 'Rising in search: “أخبار عدن” brought 100 more clicks than in the previous period.' );
	same( array( 'good', 'search' ), array( $f['tone'], $f['tab'] ) );

	// Saudi Arabia grew 80%; Yemen only 11%.
	$f = marsad_test_finding_with( $all, 'Visitors from Saudi Arabia grew 80.0%, to 900.' );
	same( 'good', $f['tone'] );

	$f = marsad_test_finding_with( $all, 'The most viewed page was “أخبار اليمن”, with 5,000 views.' );
	same( array( 'neutral', 'content' ), array( $f['tone'], $f['tab'] ) );

	// 12% engaged against 53% for the site.
	$f = marsad_test_finding_with( $all, '“<script>alert(1)</script>” gets many views (3,000) but only 12.0% are engaged, against 53.0% across the site.' );
	same( 'bad', $f['tone'] );

	$f = marsad_test_finding_with( $all, 'Opportunity: “سعر الصرف” was shown 2,000 times at position 6.4; ranking higher could bring about 85 more clicks.' );
	same( 'search', $f['tab'] );

	marsad_test_finding_with( $all, '68.1% of visitors used a mobile phone.' );
	marsad_test_finding_with( $all, '77.9% of visitors were new to the site.' );
}

function test_insights_from_daily_rows_for_youtube_and_posts() {
	marsad_test_deep_site( false );
	$all = marsad_test_findings();
	// Subscribers rose by 10 a day: 28 days later there are 280 more.
	$f = marsad_test_finding_with( $all, 'YouTube gained 280 subscribers in this period, to 10,550.' );
	same( array( 'good', 'platforms', 'src-youtube' ), array( $f['tone'], $f['tab'], $f['anchor'] ) );
	// 20 a day now, 15 a day before.
	$f = marsad_test_finding_with( $all, '560 posts were published, 140 more than in the previous period.' );
	same( array( 'good', 'content' ), array( $f['tone'], $f['tab'] ) );
}

function test_insights_report_falls_as_bad_news() {
	$db = marsad_test_deep_site( false );
	foreach ( $db->fixture as &$row ) {
		if ( 'wordpress' === $row[0] ) {
			$row[4] = $row[3] >= gmdate( 'Y-m-d', time() - 28 * DAY_IN_SECONDS ) ? 10 : 15;
		}
		if ( 'youtube' === $row[0] ) {
			$row[4] = 20000 - $row[4];
		}
	}
	unset( $row );
	Marsad_Snapshots::put(
		'ga4',
		'28',
		'channels',
		marsad_test_table( array( 'sessionDefaultChannelGroup' ), array( 'sessions' ), array( array( array( 'Referral' ), array( 100 ), array( 400 ) ), array( array( 'Direct' ), array( 990 ), array( 1000 ) ) ) )
	);
	$all = marsad_test_findings();
	same( 'bad', marsad_test_finding_with( $all, 'Sessions from Referral fell 75.0%, from 400 to 100.' )['tone'] );
	same( 'bad', marsad_test_finding_with( $all, 'YouTube lost 280 subscribers in this period, down to 9,450.' )['tone'] );
	same( 'bad', marsad_test_finding_with( $all, '280 posts were published, 140 fewer than in the previous period.' )['tone'] );
}

function test_insights_stay_silent_on_thin_data() {
	marsad_test_deep_site( false );
	$put = function ( $source, $report, array $payload ) {
		Marsad_Snapshots::put( $source, '28', $report, $payload );
	};
	// Channels: 3 → 9 sessions is no story; nor is a 2% move.
	$put( 'ga4', 'channels', marsad_test_table( array( 'sessionDefaultChannelGroup' ), array( 'sessions' ), array( array( array( 'Email' ), array( 9 ), array( 3 ) ), array( array( 'Direct' ), array( 1020 ), array( 1000 ) ) ) ) );
	// Countries: no previous period at all.
	$put( 'ga4', 'countries', marsad_test_table( array( 'country' ), array( 'activeUsers' ), array( array( array( 'Yemen' ), array( 2000 ) ) ) ) );
	// Pages: engagement close to the site's, no weak page.
	$put( 'ga4', 'totals', array( 'metrics' => array( 'engagementRate' => array( 'value' => 0.5, 'prev' => null ) ) ) );
	$put( 'ga4', 'pages', marsad_test_table( array( 'pagePath', 'pageTitle' ), array( 'screenPageViews', 'engagementRate' ), array( array( array( '/a', 'A' ), array( 10, 0.45 ) ) ) ) );
	// Search: a falling query in "rising" is not rising; no opportunity worth a click.
	$put( 'search_console', 'rising', marsad_test_table( array( 'query' ), array( 'clicks' ), array( array( array( 'q' ), array( 5 ), array( 9 ) ) ) ) );
	$put( 'search_console', 'opportunities', marsad_test_table( array( 'query' ), array( 'impressions', 'position', 'potential_clicks' ), array( array( array( 'q' ), array( 60, 9, 0.2 ) ) ) ) );
	// Devices without a mobile row; visitors without a "new" row.
	$put( 'ga4', 'devices', marsad_test_table( array( 'deviceCategory' ), array( 'activeUsers' ), array( array( array( 'desktop' ), array( 10 ) ) ) ) );
	$put( 'ga4', 'new_returning', marsad_test_table( array( 'newVsReturning' ), array( 'activeUsers' ), array( array( array( 'returning' ), array( 10 ) ) ) ) );

	$texts = array_column( marsad_test_findings(), 'text' );
	same( 3, count( $texts ), 'only the top page and the two daily rules: ' . implode( ' / ', $texts ) );
	check( false !== strpos( $texts[0], 'The most viewed page was “A”' ) );
}

function test_insights_ignore_sources_that_are_switched_off() {
	marsad_test_deep_site();
	$saved = Marsad_Registry::saved();
	foreach ( array( 'ga4', 'search_console', 'youtube' ) as $id ) {
		$saved[ $id ]['enabled'] = false;
	}
	update_option( Marsad_Registry::OPT, $saved );
	$texts = array_column( marsad_test_findings(), 'text' );
	same( 1, count( $texts ), 'only posts: ' . implode( ' / ', $texts ) );
}

function test_insights_isolate_untrusted_text_in_sentences() {
	marsad_test_deep_site();
	$raw = Marsad_Insights::from_snapshots( '28' );
	$top = array_values(
		array_filter(
			$raw,
			function ( $f ) {
				return false !== strpos( $f['text'], 'most viewed' );
			}
		)
	)[0];
	check( false !== strpos( $top['text'], "“\u{2068}أخبار اليمن\u{2069}”" ), 'title isolated' );
}

function test_insights_rows_read_malformed_payloads_as_missing() {
	same( array(), Marsad_Insights::rows_of( array( 'rows' => 'x' ) ) );
	$rows = Marsad_Insights::rows_of( array( 'dims' => array( 'a' ), 'metrics' => array( 'm', 7 ), 'rows' => array( 'junk', array( 'd' => array( array( 'x' ), 'ok' ), 'm' => array( 'NaN' ), 'p' => array( '3' ) ) ) ) );
	same( array( array( 'd' => array( '', 'ok' ), 'm' => array( 'm' => null ), 'p' => array( 'm' => 3.0 ) ) ), $rows );
}
