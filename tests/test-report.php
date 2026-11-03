<?php

function test_running_total_recorded_today_is_shown() {
	$today                       = gmdate( 'Y-m-d' );
	$GLOBALS['wpdb']->fixture[]  = array( 'json', 'm_a', '', $today, 42 );
	$GLOBALS['wpdb']->fixture[]  = array( 'youtube', 'video_views', 'Clip', $today, 7 );
	$r                           = new Marsad_Report( 7 );
	near( 42, $r->metric( 'json', 'm_a', array( 'agg' => 'last' ) )['value'], 'snapshot taken today' );
	$top = $r->table( 'youtube', 'video_views', array( 'agg' => 'last' ) );
	same( 'Clip', $top[0]['dim'] ?? null, 'snapshot table taken today' );
}

function test_counters_still_exclude_the_unfinished_day() {
	$today                       = gmdate( 'Y-m-d' );
	$yesterday                   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	$GLOBALS['wpdb']->fixture[]  = array( 'ga4', 'views', '', $today, 500 );
	$GLOBALS['wpdb']->fixture[]  = array( 'ga4', 'views', '', $yesterday, 30 );
	$r                           = new Marsad_Report( 7 );
	near( 30, $r->metric( 'ga4', 'views', array( 'agg' => 'sum' ) )['value'] );
}
