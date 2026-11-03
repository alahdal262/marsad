<?php
require_once __DIR__ . '/test-registry-sync-updater.php';

class Marsad_Test_History_Connector extends Marsad_Test_Connector {
	public function id() { return 'hist'; }
	public function full_history() { return true; }
	public function history_start() { return '2021-08-01'; }
}

function test_full_history_source_imports_from_its_first_day_then_refreshes_window() {
	$c  = new Marsad_Test_History_Connector();
	$st = Marsad_Sync::run( $c );
	check( $st['ok'] );
	same( '2021-08-01', $c->calls[0][0], 'everything since the first post' );
	same( '2021-08-01', $st['backfilled_from'] );
	Marsad_Sync::run( $c );
	same( gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), $c->calls[1][0] );
}

function test_full_history_reimports_once_after_an_earlier_partial_backfill() {
	update_option( Marsad_Sync::OPT_STATUS, array( 'hist' => array( 'at' => time(), 'ok' => true, 'backfilled' => true ) ) );
	$c = new Marsad_Test_History_Connector();
	Marsad_Sync::run( $c );
	same( '2021-08-01', $c->calls[0][0], 'a site upgraded from 90-day history gets the rest' );
}

function test_retention_never_purges_full_history_sources() {
	Marsad_Store::purge_older_than( 730, array( 'wordpress' ) );
	$q = end( $GLOBALS['wpdb']->queries );
	check( false !== strpos( $q, "source NOT IN ('wordpress')" ), $q );
}

function test_permanent_deletion_of_a_published_post_is_counted() {
	Marsad_Connector_Wordpress::on_delete( 5, (object) array( 'post_type' => 'post', 'post_status' => 'publish' ) );
	$q = end( $GLOBALS['wpdb']->queries );
	check( false !== strpos( $q, "'wordpress','posts_deleted'" ) && false !== strpos( $q, 'value=value+1' ), $q );
	$n = count( $GLOBALS['wpdb']->queries );
	Marsad_Connector_Wordpress::on_delete( 6, (object) array( 'post_type' => 'post', 'post_status' => 'draft' ) );
	Marsad_Connector_Wordpress::on_delete( 7, (object) array( 'post_type' => 'page', 'post_status' => 'publish' ) );
	same( $n, count( $GLOBALS['wpdb']->queries ), 'drafts and other types are not posts that were deleted' );
}

function test_all_time_range_starts_at_the_first_recorded_day_without_comparison() {
	$GLOBALS['wpdb']->first_day = '2021-08-01';
	$r = new Marsad_Report( 0 );
	same( '2021-08-01', $r->range['from'] );
	same( null, $r->range['prev_from'] );
	$GLOBALS['wpdb']->fixture[] = array( 'wordpress', 'posts', '', '2022-01-05', 30 );
	$m = $r->metric( 'wordpress', 'posts', array( 'agg' => 'sum' ) );
	near( 30, $m['value'] );
	same( null, $m['change'] );
}

function test_long_ranges_draw_monthly_bars() {
	$GLOBALS['wpdb']->first_day = gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS );
	for ( $i = 1; $i <= 400; $i++ ) {
		$GLOBALS['wpdb']->fixture[] = array( 'wordpress', 'posts', '', gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ), 10 );
	}
	$_GET = array( 'tab' => 'overview', 'range' => 0 );
	ob_start();
	Marsad_Admin_Dashboard::render();
	$h = ob_get_clean();
	$bars = substr_count( $h, '<i style="height:' );
	check( $bars >= 13 && $bars <= 15, "one bar per month, got $bars" );
	check( false !== strpos( $h, 'All time' ) );
}
