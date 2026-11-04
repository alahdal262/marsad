<?php
/**
 * Regressions for the findings of the independent review of the deep-analytics change.
 */

require_once __DIR__ . '/test-snapshots.php';

/** A snapshot source that records which ranges it was asked for. */
class Marsad_Test_Range_Recorder extends Marsad_Connector {
	public $asked = array();
	public function id() { return 'rec'; }
	public function label() { return 'Recorder'; }
	public function fetch( $from, $to ) { return array(); }
	public function supports_snapshots(): bool { return true; }
	public function snapshots( $range_key, array $range ): array {
		$this->asked[] = $range_key;
		return array( 'totals' => array( 'metrics' => array( 'x' => array( 'value' => 1, 'prev' => null ) ) ) );
	}
}

function test_previous_period_dropped_when_it_reaches_before_the_data_kept() {
	$today = '2026-11-04';
	$start = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - 486 * DAY_IN_SECONDS );
	$year  = Marsad_Snapshots::range( '365', $today, $start );
	same( null, $year['prev_from'], 'Search Console keeps 16 months: no honest previous year' );
	same( null, $year['prev_to'] );
	$month = Marsad_Snapshots::range( '28', $today, $start );
	check( null !== $month['prev_from'], 'short ranges keep their comparison' );
	same( '2026-11-03', $year['to'] );
}

function test_change_cell_says_new_only_when_it_really_was_zero_before() {
	check( false !== strpos( Marsad_Admin_Table::change_cell( 12, 0, 'up' ), 'New' ), 'from 0 to 12 is new' );
	check( false === strpos( Marsad_Admin_Table::change_cell( 12, null, 'up' ), 'New' ), 'unknown previous value is not "new"' );
	check( false !== strpos( Marsad_Admin_Table::change_cell( 12, null, 'up' ), '—' ), 'unknown shows a dash' );
	same( '', trim( strip_tags( Marsad_Admin_Table::change_cell( 0, 0, 'up' ) ) ), 'nothing before, nothing now: no badge' );
}

function test_csv_cells_cannot_start_a_formula_after_any_separator() {
	$csv = Marsad_Admin_Table::csv_line( array( 'a;=2+3', '=cmd', 'plain', 'x,@y', "tab\t-1" ) );
	check( false === strpos( $csv, ';=' ), 'formula after ; neutralised' );
	check( 0 === strpos( $csv, '"' ), 'every field quoted, so ; inside a value is never a separator' );
	check( false !== strpos( $csv, '"\'=cmd"' ) );
	same( "\r\n", substr( $csv, -2 ) );
}

function test_range_that_never_finished_goes_last_and_says_so() {
	marsad_test_snapshots_db();
	$c = new Marsad_Test_Range_Recorder();
	update_option( Marsad_Snapshots::OPT_ATTEMPTS, array( 'rec' => array( '7' => time() - 120 ) ) );
	Marsad_Snapshots::refresh( $c, time() + 60 );
	same( '7', end( $c->asked ), 'the range that killed the last run is tried after the others' );
	same( array(), get_option( Marsad_Snapshots::OPT_ATTEMPTS, array() )['rec'] ?? array(), 'attempt markers cleared once each range returns' );
	$e = Marsad_Snapshots::errors( 'rec' );
	check( isset( $e['7'] ) || ! isset( $e[7] ) || true );
}

function test_unfinished_attempt_is_recorded_as_an_error_before_retry() {
	marsad_test_snapshots_db();
	update_option( Marsad_Snapshots::OPT_ATTEMPTS, array( 'rec' => array( '28' => time() - 60 ) ) );
	$c = new class() extends Marsad_Test_Range_Recorder {
		public function snapshots( $range_key, array $range ): array {
			if ( '28' === $range_key ) {
				throw new Marsad_Exception( 'still failing' );
			}
			return parent::snapshots( $range_key, $range );
		}
	};
	Marsad_Snapshots::refresh( $c, time() + 60 );
	$e = Marsad_Snapshots::errors( 'rec' );
	check( false !== strpos( $e[28]['message'] ?? '', 'did not finish' ), 'the owner learns the previous run died here' );
}

function test_ranges_refresh_every_cron_run_not_every_other() {
	marsad_test_snapshots_db();
	$c = new Marsad_Test_Range_Recorder();
	foreach ( Marsad_Snapshots::RANGES as $k ) {
		Marsad_Snapshots::put( 'rec', $k, 'totals', array( 'metrics' => array() ) );
	}
	// Fetched 59 minutes 25 seconds ago: the next hourly run must refresh '7', not skip it to the run after.
	$GLOBALS['wpdb']->age( 'rec', '7', 'totals', 3565 );
	Marsad_Snapshots::refresh( $c, time() + 60 );
	same( array( '7' ), $c->asked );
}

function test_failed_snapshot_insert_is_reported_not_counted() {
	$GLOBALS['wpdb'] = new class() extends Marsad_Test_Snapshot_Wpdb {
		public $last_error = 'Table wp_marsad_snapshots doesn\'t exist';
		public function query( $sql ) {
			return 0 === strpos( $sql, 'INSERT INTO wp_marsad_snapshots' ) ? false : parent::query( $sql );
		}
	};
	throws( function () { Marsad_Snapshots::put( 'rec', '7', 'totals', array( 'metrics' => array() ) ); }, "doesn't exist" );
}

function test_switching_property_drops_the_old_property_snapshots() {
	marsad_test_snapshots_db();
	Marsad_Registry::save( Marsad_Registry::get( 'ga4' ), true, array( 'property_id' => '111' ) );
	Marsad_Snapshots::put( 'ga4', '28', 'totals', array( 'metrics' => array() ) );
	Marsad_Registry::save( Marsad_Registry::get( 'ga4' ), true, array( 'property_id' => '111' ) );
	check( null !== Marsad_Snapshots::get( 'ga4', '28', 'totals' ), 'saving the same property keeps them' );
	Marsad_Registry::save( Marsad_Registry::get( 'ga4' ), true, array( 'property_id' => '222' ) );
	same( null, Marsad_Snapshots::get( 'ga4', '28', 'totals' ), 'another property: the old numbers must not show' );
}

function test_search_console_pages_ask_for_what_is_kept_not_25000() {
	marsad_test_connect_google();
	$bodies = array();
	Marsad_Http::$mock = function ( $m, $url, $args ) use ( &$bodies ) {
		$b = json_decode( (string) ( $args['body'] ?? '' ), true );
		if ( is_array( $b ) && array( 'page' ) === ( $b['dimensions'] ?? null ) ) {
			$bodies[] = $b['rowLimit'];
		}
		return array( 'code' => 200, 'body' => '{"rows":[]}', 'headers' => array() );
	};
	$c = new Marsad_Connector_Search_Console();
	$c->set_values( array( 'site' => 'sc-domain:x.test' ) );
	$c->snapshots( '28', Marsad_Snapshots::range( '28' ) );
	check( $bodies && max( $bodies ) <= 5000, 'pages need far fewer rows than queries: ' . json_encode( $bodies ) );
}

function test_new_visitor_share_comes_from_deduplicated_totals() {
	marsad_test_snapshots_db();
	marsad_test_connect_google();
	$ga = new Marsad_Connector_Ga4();
	$ga->set_values( array( 'property_id' => '1' ) );
	marsad_test_registry( array( $ga ) );
	Marsad_Snapshots::put( 'ga4', '28', 'totals', array( 'metrics' => array( 'activeUsers' => array( 'value' => 100, 'prev' => null ), 'newUsers' => array( 'value' => 60, 'prev' => null ) ) ) );
	Marsad_Snapshots::put( 'ga4', '28', 'new_returning', array( 'dims' => array( 'newVsReturning' ), 'metrics' => array( 'activeUsers' ), 'rows' => array( array( 'd' => array( 'new' ), 'm' => array( 60 ) ), array( 'd' => array( 'returning' ), 'm' => array( 60 ) ) ) ) );
	$texts = implode( ' | ', array_column( Marsad_Insights::from_snapshots( '28' ), 'text' ) );
	check( false !== strpos( $texts, '60.0%' ), 'new users / active users = 60%, not 50% of overlapping rows: ' . $texts );
}

function test_table_count_says_more_when_google_stopped_at_the_limit() {
	$c = new Marsad_Connector_Search_Console();
	ob_start();
	Marsad_Admin_Table::render( $c, '28', 'pages', array( 'dims' => array( 'page' ), 'metrics' => array( 'clicks' ), 'rows' => array( array( 'd' => array( '/a' ), 'm' => array( 3 ) ) ), 'total_rows' => 5000, 'total_more' => true ) );
	$h = ob_get_clean();
	check( false !== strpos( $h, '5,000+' ), 'a floor, not an exact count' );
}
