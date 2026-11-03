<?php
/**
 * Range snapshots: storage, freshness, refresh order and deadline, per-range errors,
 * the sync phase and the install SQL.
 *
 * The shared fake $wpdb only records queries, so these tests swap in
 * Marsad_Test_Snapshot_Wpdb: the same recorder plus an in-memory marsad_snapshots table
 * fed by the exact queries Marsad_Snapshots sends. Other tests that need snapshot
 * fixtures can use it too: marsad_test_snapshots_db() then Marsad_Snapshots::put().
 */

foreach (
	array(
		'current_user_can'    => function ( $cap ) { return true; },
		'check_admin_referer' => function ( $a ) { return 1; },
		'wp_die'              => function ( $m ) { throw new Exception( 'wp_die: ' . $m ); },
		'sanitize_key'        => function ( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); },
		'wp_unslash'          => function ( $v ) { return $v; },
		'admin_url'           => function ( $p = '' ) { return 'https://example.test/wp-admin/' . $p; },
		'add_query_arg'       => function ( $args, $url ) { return $url . '?' . http_build_query( $args ); },
		// Throwing stands in for the redirect, so the handler never reaches its exit.
		'wp_safe_redirect'    => function ( $u ) { throw new Exception( 'redirect: ' . $u ); },
		'dbDelta'             => function ( $q ) { $GLOBALS['marsad_test_dbdelta'][] = $q; return array(); },
	) as $name => $fn
) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['marsad_stub_' . $name] = $fn;
		eval( "function $name( ...\$a ) { return call_user_func_array( \$GLOBALS['marsad_stub_$name'], \$a ); }" ); // phpcs:ignore
	}
}

/** The recording fake, plus a marsad_snapshots table kept in memory. */
class Marsad_Test_Snapshot_Wpdb extends Marsad_Test_Wpdb {
	/** @var array "source|range|report" => array( source, range_key, report, payload, fetched_at ) */
	public $snapshots = array();
	/** @var array prepared SQL => its arguments, so values are read back exactly (no SQL parsing). */
	private $args = array();

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}

	public function prepare( $sql, ...$args ) {
		$out = parent::prepare( $sql, ...$args );
		$this->args[ $out ] = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		return $out;
	}

	public function query( $sql ) {
		parent::query( $sql );
		if ( 0 === strpos( $sql, 'INSERT INTO wp_marsad_snapshots' ) ) {
			list( $source, $range, $report, $payload, $at ) = $this->args[ $sql ];
			$this->snapshots[ "$source|$range|$report" ] = array( $source, $range, $report, $payload, $at );
		}
		return 1;
	}

	public function get_row( $sql ) {
		parent::get_row( $sql );
		if ( false === strpos( $sql, 'FROM wp_marsad_snapshots' ) ) {
			return null;
		}
		list( $source, $range, $report ) = $this->args[ $sql ];
		$r = $this->snapshots[ "$source|$range|$report" ] ?? null;
		return $r ? (object) array( 'payload' => $r[3], 'fetched_at' => $r[4] ) : null;
	}

	public function get_results( $sql ) {
		if ( false === strpos( $sql, 'FROM wp_marsad_snapshots' ) ) {
			return parent::get_results( $sql );
		}
		$this->queries[] = $sql;
		list( $source, $range ) = $this->args[ $sql ];
		$out = array();
		foreach ( $this->snapshots as $r ) {
			if ( $r[0] === $source && $r[1] === $range ) {
				$out[] = (object) array( 'report' => $r[2], 'payload' => $r[3] );
			}
		}
		return $out;
	}

	public function delete( $t, $w ) {
		parent::delete( $t, $w );
		if ( 'wp_marsad_snapshots' === $t ) {
			foreach ( $this->snapshots as $k => $r ) {
				if ( $r[0] === $w['source'] ) {
					unset( $this->snapshots[ $k ] );
				}
			}
		}
		return 1;
	}

	/** Pretend a stored report was fetched $seconds ago. */
	public function age( $source, $range, $report, $seconds ) {
		$this->snapshots[ "$source|$range|$report" ][4] = gmdate( 'Y-m-d H:i:s', time() - $seconds );
	}
}

/** @return Marsad_Test_Snapshot_Wpdb installed as $wpdb for this test */
function marsad_test_snapshots_db() {
	$GLOBALS['wpdb'] = new Marsad_Test_Snapshot_Wpdb();
	return $GLOBALS['wpdb'];
}

/** Makes the registry hold exactly these connectors, all enabled. */
function marsad_test_registry( array $connectors ) {
	$list  = array();
	$saved = array();
	foreach ( $connectors as $c ) {
		$list[ $c->id() ]  = $c;
		$saved[ $c->id() ] = array( 'enabled' => true, 'values' => array() );
	}
	update_option( Marsad_Registry::OPT, $saved );
	$p = new ReflectionProperty( 'Marsad_Registry', 'cache' );
	if ( PHP_VERSION_ID < 80100 ) {
		$p->setAccessible( true );
	}
	$p->setValue( null, $list );
}

class Marsad_Test_Snapshot_Connector extends Marsad_Connector {
	public $calls    = array();
	public $fail     = array();
	public $partial  = array();
	public $snap     = true;
	public $id       = 'snap';
	/** @var callable|null runs inside snapshots(), e.g. to let the clock pass a deadline */
	public $during   = null;
	public function id() { return $this->id; }
	public function label() { return 'Snap'; }
	public function fetch( $from, $to ) { return array( $this->row( 'm', $to, 1 ) ); }
	public function history_start() { return '2020-01-01'; }
	public function supports_snapshots(): bool { return $this->snap; }
	public function snapshots( $range_key, array $range ): array {
		$this->calls[] = array( $range_key, $range );
		if ( $this->during ) {
			call_user_func( $this->during, $range_key );
		}
		if ( in_array( $range_key, $this->fail, true ) ) {
			throw new Marsad_Exception( "quota for $range_key" );
		}
		if ( in_array( $range_key, $this->partial, true ) ) {
			Marsad_Snapshots::record_error( $this->id, $range_key, 'age report rejected' );
		}
		return array(
			'totals' => array( 'metrics' => array( 'activeUsers' => array( 'value' => 5138, 'prev' => 'all' === $range_key ? null : 4801 ) ) ),
			'pages'  => array(
				'dims'       => array( 'pagePath', 'pageTitle' ),
				'metrics'    => array( 'screenPageViews' ),
				'rows'       => array( array( 'd' => array( '/news/a', 'خبر عاجل' ), 'm' => array( 900 ), 'p' => array( 810 ) ) ),
				'sort'       => 'screenPageViews',
				'total_rows' => 5206,
			),
			'broken' => 'not a payload',
		);
	}
	public function snapshot_formats(): array { return array( 'country' => 'country', 'pageTitle' => 'bogus' ); }
}

function test_install_creates_the_snapshot_table_and_upgrades_from_version_1() {
	marsad_test_snapshots_db();
	$GLOBALS['marsad_test_dbdelta'] = array();
	update_option( Marsad_Store::OPT_DB, 1 );
	Marsad_Store::maybe_upgrade();
	same( 2, Marsad_Store::DB_VERSION );
	same( 2, get_option( Marsad_Store::OPT_DB ), 'version 1 sites are upgraded' );
	same( 1, count( $GLOBALS['marsad_test_dbdelta'] ), 'one dbDelta call' );
	$sql = implode( "\n", (array) $GLOBALS['marsad_test_dbdelta'][0] );
	check( false !== strpos( $sql, 'CREATE TABLE wp_marsad_metrics' ), 'metrics table kept' );
	check( false !== strpos( $sql, 'CREATE TABLE wp_marsad_snapshots' ), 'snapshot table created' );
	foreach ( array( 'source varchar(40) NOT NULL', 'range_key varchar(10) NOT NULL', 'report varchar(80) NOT NULL', 'payload longtext NOT NULL', 'fetched_at datetime NOT NULL', 'PRIMARY KEY  (id)', 'UNIQUE KEY srr (source,range_key,report)', 'utf8mb4' ) as $part ) {
		check( false !== strpos( $sql, $part ), "snapshot SQL has: $part" );
	}

	$GLOBALS['marsad_test_dbdelta'] = array();
	Marsad_Store::maybe_upgrade();
	same( array(), $GLOBALS['marsad_test_dbdelta'], 'nothing to do at version 2' );
}

function test_uninstall_drops_the_snapshot_table_and_errors() {
	$src = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
	check( false !== strpos( $src, "'marsad_snapshots'" ), 'table dropped' );
	check( false !== strpos( $src, "'marsad_snapshot_errors'" ), 'errors option deleted' );
}

function test_snapshot_put_get_and_upsert() {
	$db = marsad_test_snapshots_db();
	same( null, Marsad_Snapshots::get( 'ga4', '28', 'totals' ), 'nothing stored yet' );

	$payload = array( 'dims' => array( 'query' ), 'metrics' => array( 'clicks' ), 'rows' => array( array( 'd' => array( 'أخبار اليمن' ), 'm' => array( 12 ) ) ), 'sort' => 'clicks', 'total_rows' => 1 );
	Marsad_Snapshots::put( 'search_console', '28', 'queries', $payload );
	$sql = end( $db->queries );
	check( 0 === strpos( $sql, "INSERT INTO wp_marsad_snapshots (source,range_key,report,payload,fetched_at) VALUES ('search_console','28','queries'," ), $sql );
	check( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE payload=VALUES(payload), fetched_at=VALUES(fetched_at)' ), 'upsert' );
	$got = Marsad_Snapshots::get( 'search_console', '28', 'queries' );
	same( $payload, $got['payload'], 'decoded back, Arabic intact' );
	check( abs( time() - $got['fetched_at'] ) < 5, 'fetched_at is a unix time of now' );
	check( false !== strpos( end( $db->queries ), "WHERE source='search_console' AND range_key='28' AND report='queries'" ) );

	$payload['total_rows'] = 2;
	Marsad_Snapshots::put( 'search_console', '28', 'queries', $payload );
	same( 1, count( $db->snapshots ), 'same key replaced, not added' );
	same( 2, Marsad_Snapshots::get( 'search_console', '28', 'queries' )['payload']['total_rows'] );

	Marsad_Snapshots::put( 'search_console', '28', 'totals', array( 'metrics' => array( 'clicks' => array( 'value' => 4090, 'prev' => null ) ) ) );
	Marsad_Snapshots::put( 'search_console', '7', 'totals', array( 'metrics' => array() ) );
	same( array( 'queries', 'totals' ), array_keys( Marsad_Snapshots::reports( 'search_console', '28' ) ), 'reports of one range only' );
	same( null, Marsad_Snapshots::reports( 'search_console', '28' )['totals']['metrics']['clicks']['prev'] );
	same( array(), Marsad_Snapshots::reports( 'ga4', '28' ) );

	$db->snapshots['search_console|28|queries'][3] = '{broken';
	same( null, Marsad_Snapshots::get( 'search_console', '28', 'queries' ), 'unreadable JSON is treated as missing' );
}

function test_snapshot_reads_are_empty_on_the_plain_recorder() {
	// Other tests run with the plain fake: reads must quietly return nothing.
	same( null, Marsad_Snapshots::get( 'ga4', '28', 'totals' ) );
	same( array(), Marsad_Snapshots::reports( 'ga4', '28' ) );
	check( Marsad_Snapshots::stale( 'ga4', '28', 3600 ) );
}

function test_snapshot_ranges() {
	same( array( 'from' => '2026-10-06', 'to' => '2026-11-02', 'prev_from' => '2026-09-08', 'prev_to' => '2026-10-05' ), Marsad_Snapshots::range( '28', '2026-11-03' ) );
	same( array( 'from' => '2026-10-27', 'to' => '2026-11-02', 'prev_from' => '2026-10-20', 'prev_to' => '2026-10-26' ), Marsad_Snapshots::range( '7', '2026-11-03' ) );
	same( Marsad_Aggregate::range( 365, '2026-11-03' ), Marsad_Snapshots::range( '365', '2026-11-03' ) );
	same( array( 'from' => '2015-08-14', 'to' => '2026-11-02', 'prev_from' => null, 'prev_to' => null ), Marsad_Snapshots::range( 'all', '2026-11-03', '2015-08-14' ) );
	same( '2025-11-03', Marsad_Snapshots::range( 'all', '2026-11-03' )['from'], 'all time without a start: a year' );
	same( Marsad_Snapshots::range( '28', '2026-11-03' ), Marsad_Snapshots::range( '13', '2026-11-03' ), 'unknown key reads as 28' );
	same( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), Marsad_Snapshots::range( '7' )['to'], 'ends yesterday by default' );
}

function test_snapshot_stale_by_ttl() {
	$db = marsad_test_snapshots_db();
	check( Marsad_Snapshots::stale( 'ga4', '7', 3600 ), 'missing totals is stale' );
	Marsad_Snapshots::put( 'ga4', '7', 'pages', array( 'rows' => array() ) );
	check( Marsad_Snapshots::stale( 'ga4', '7', 3600 ), 'other reports do not count' );
	Marsad_Snapshots::put( 'ga4', '7', 'totals', array( 'metrics' => array() ) );
	check( ! Marsad_Snapshots::stale( 'ga4', '7', 3600 ), 'fresh' );
	$db->age( 'ga4', '7', 'totals', 3500 );
	check( ! Marsad_Snapshots::stale( 'ga4', '7', 3600 ), 'within the TTL' );
	$db->age( 'ga4', '7', 'totals', 3700 );
	check( Marsad_Snapshots::stale( 'ga4', '7', 3600 ), 'older than the TTL' );
	same( array( '7' => 3600, '28' => 10800, '90' => 43200, '365' => 86400, 'all' => 86400 ), Marsad_Snapshots::TTL );
	same( array( '7', '28', '90', '365', 'all' ), Marsad_Snapshots::RANGES );
}

function test_snapshot_refresh_fetches_missing_ranges_shortest_first() {
	$db = marsad_test_snapshots_db();
	$c  = new Marsad_Test_Snapshot_Connector();
	same( 10, Marsad_Snapshots::refresh( $c, time() + 60 ), 'two reports per range; the non-array one is skipped' );
	same( array( '7', '28', '90', '365', 'all' ), array_column( $c->calls, 0 ) );
	same( Marsad_Snapshots::range( '28' ), $c->calls[1][1] );
	same( array( 'from' => '2020-01-01', 'to' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'prev_from' => null, 'prev_to' => null ), $c->calls[4][1], 'all time starts at the source history' );
	same( 'خبر عاجل', Marsad_Snapshots::get( 'snap', '90', 'pages' )['payload']['rows'][0]['d'][1] );
	same( 5206, Marsad_Snapshots::reports( 'snap', 'all' )['pages']['total_rows'] );
	check( ! isset( $db->snapshots['snap|7|broken'] ) );

	$c->calls = array();
	same( 0, Marsad_Snapshots::refresh( $c, time() + 60 ), 'everything fresh: nothing to do' );
	same( array(), $c->calls );
}

function test_snapshot_refresh_takes_the_most_overdue_first() {
	$db = marsad_test_snapshots_db();
	$c  = new Marsad_Test_Snapshot_Connector();
	Marsad_Snapshots::refresh( $c, time() + 60 );
	$db->age( 'snap', '7', 'totals', 600 );          // fresh (TTL 1 h)
	$db->age( 'snap', '28', 'totals', 4 * 3600 );    // 1.3 × its TTL
	$db->age( 'snap', '365', 'totals', 3 * 86400 );  // 3 × its TTL
	$db->age( 'snap', 'all', 'totals', 2 * 86400 );  // 2 × its TTL
	unset( $db->snapshots['snap|90|totals'] );       // missing comes first
	$c->calls = array();
	Marsad_Snapshots::refresh( $c, time() + 60 );
	same( array( '90', '365', 'all', '28' ), array_column( $c->calls, 0 ) );
}

function test_snapshot_refresh_stops_at_the_deadline() {
	marsad_test_snapshots_db();
	$c = new Marsad_Test_Snapshot_Connector();
	same( 0, Marsad_Snapshots::refresh( $c, time() ), 'deadline already reached' );
	same( array(), $c->calls );

	// The first range takes until the deadline; the rest wait for the next run.
	$deadline  = time() + 1;
	$c->during = function () use ( $deadline ) {
		while ( time() < $deadline ) {
			usleep( 20000 );
		}
	};
	same( 2, Marsad_Snapshots::refresh( $c, $deadline ) );
	same( array( '7' ), array_column( $c->calls, 0 ) );
	check( ! Marsad_Snapshots::stale( 'snap', '7', 3600 ) );
	check( Marsad_Snapshots::stale( 'snap', '28', 10800 ), 'left for the next run' );
}

function test_snapshot_errors_are_kept_per_range_and_cleared_on_success() {
	marsad_test_snapshots_db();
	$c       = new Marsad_Test_Snapshot_Connector();
	$c->fail = array( '28' );
	same( 8, Marsad_Snapshots::refresh( $c, time() + 60 ), 'one failing range does not stop the others' );
	$errors = get_option( Marsad_Snapshots::OPT_ERRORS );
	same( array( 'snap' ), array_keys( $errors ) );
	// PHP turns numeric string keys into integers; lookups by '28' still work.
	same( array( 28 ), array_keys( $errors['snap'] ) );
	same( 'quota for 28', $errors['snap']['28']['message'] );
	check( abs( time() - $errors['snap']['28']['at'] ) < 5 );
	same( null, Marsad_Snapshots::get( 'snap', '28', 'totals' ) );

	$c->fail  = array();
	$c->calls = array();
	Marsad_Snapshots::refresh( $c, time() + 60 );
	same( array( '28' ), array_column( $c->calls, 0 ) );
	same( array(), get_option( Marsad_Snapshots::OPT_ERRORS ), 'cleared on success' );
	same( array(), Marsad_Snapshots::errors( 'snap' ) );
}

function test_snapshot_failed_range_waits_behind_the_others() {
	$db = marsad_test_snapshots_db();
	$c  = new Marsad_Test_Snapshot_Connector();
	Marsad_Snapshots::refresh( $c, time() + 60 );
	foreach ( Marsad_Snapshots::RANGES as $key ) {
		unset( $db->snapshots[ "snap|$key|totals" ] );
	}
	Marsad_Snapshots::record_error( 'snap', '7', 'quota' );
	$c->calls = array();
	Marsad_Snapshots::refresh( $c, time() + 60 );
	same( array( '28', '90', '365', 'all', '7' ), array_column( $c->calls, 0 ) );
}

function test_snapshot_partial_problem_survives_a_successful_range() {
	marsad_test_snapshots_db();
	$c          = new Marsad_Test_Snapshot_Connector();
	$c->partial = array( '90' );
	Marsad_Snapshots::refresh( $c, time() + 60 );
	same( 'age report rejected', Marsad_Snapshots::errors( 'snap' )['90']['message'] );
	check( null !== Marsad_Snapshots::get( 'snap', '90', 'totals' ), 'the range itself was stored' );

	Marsad_Snapshots::record_error( 'snap', '90', 'gender report rejected' );
	same( 'age report rejected | gender report rejected', Marsad_Snapshots::errors( 'snap' )['90']['message'], 'problems of one refresh are kept together' );
}

function test_snapshot_delete_source() {
	$db = marsad_test_snapshots_db();
	$c  = new Marsad_Test_Snapshot_Connector();
	$c->fail = array( '7' );
	Marsad_Snapshots::refresh( $c, time() + 60 );
	Marsad_Snapshots::put( 'other', '28', 'totals', array( 'metrics' => array() ) );
	Marsad_Snapshots::record_error( 'other', '28', 'x' );
	Marsad_Snapshots::delete_source( 'snap' );
	check( in_array( 'DELETE wp_marsad_snapshots', $db->queries, true ) );
	same( array( 'other|28|totals' ), array_keys( $db->snapshots ) );
	same( array( 'other' ), array_keys( Marsad_Snapshots::errors() ), 'errors of the source removed, others kept' );
}

function test_snapshot_formats_merge_connector_formats() {
	same( 'percent', Marsad_Snapshots::FORMATS['engagementRate'] );
	same( 'seconds', Marsad_Snapshots::FORMATS['averageSessionDuration'] );
	same( 'decimal', Marsad_Snapshots::FORMATS['position'] );
	same( 'percent', Marsad_Snapshots::FORMATS['ctr'] );
	foreach ( Marsad_Snapshots::FORMATS as $key => $f ) {
		check( in_array( $f, Marsad_Snapshots::FORMAT_TYPES, true ), "known format for $key" );
	}
	$f = Marsad_Snapshots::formats( new Marsad_Test_Snapshot_Connector() );
	same( 'country', $f['country'], 'connector adds its own' );
	check( ! isset( $f['pageTitle'] ), 'unknown format names are ignored' );
	same( Marsad_Snapshots::FORMATS, Marsad_Snapshots::formats() );
}

function test_connector_defaults_have_no_snapshots() {
	foreach ( Marsad_Registry::all() as $c ) {
		if ( in_array( $c->id(), array( 'ga4', 'search_console' ), true ) ) {
			continue; // These two gain snapshots in their own step.
		}
		check( ! $c->supports_snapshots(), $c->id() . ' unchanged' );
		same( array(), $c->snapshots( '28', Marsad_Snapshots::range( '28' ) ) );
		same( array(), $c->snapshot_labels() );
		same( array(), $c->snapshot_formats() );
	}
}

function test_sync_snapshot_phase_shares_one_deadline_and_skips_others() {
	marsad_test_snapshots_db();
	$a        = new Marsad_Test_Snapshot_Connector();
	$b        = new Marsad_Test_Snapshot_Connector();
	$b->id    = 'snap_b';
	$off      = new Marsad_Test_Snapshot_Connector();
	$off->id  = 'plain';
	$off->snap = false;
	marsad_test_registry( array( $a, $off, $b ) );

	same( 0, Marsad_Sync::snapshot_phase( 0 ), 'no time, no work' );
	same( array(), $a->calls );

	same( 20, Marsad_Sync::snapshot_phase( 45 ) );
	same( 5, count( $a->calls ) );
	same( 5, count( $b->calls ), 'the next source runs too' );
	same( array(), $off->calls, 'sources without snapshots are skipped' );

	// A source that blows up with a PHP error is recorded and the next source still runs.
	foreach ( Marsad_Snapshots::RANGES as $key ) {
		$GLOBALS['wpdb']->age( 'snap', $key, 'totals', 10 * 86400 );
		$GLOBALS['wpdb']->age( 'snap_b', $key, 'totals', 10 * 86400 );
	}
	$a->during = function () {
		throw new TypeError( 'bad shape' );
	};
	same( 10, Marsad_Sync::snapshot_phase( 45 ) );
	same( 'bad shape', Marsad_Snapshots::errors( 'snap' )['7']['message'] );
}

function test_sync_run_all_ends_with_the_snapshot_phase() {
	$db = marsad_test_snapshots_db();
	$c  = new Marsad_Test_Snapshot_Connector();
	marsad_test_registry( array( $c ) );
	Marsad_Sync::run_all();
	check( Marsad_Sync::status()['snap']['ok'], 'daily run first' );
	same( 5, count( $c->calls ), 'then every range' );
	check( false !== strpos( implode( ' ', $db->queries ), 'INSERT INTO wp_marsad_metrics' ) );
	same( false, get_transient( Marsad_Sync::LOCK ), 'lock released' );

	$src = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-marsad-admin.php' );
	check( false !== strpos( $src, 'Marsad_Sync::run_all( 90 );' ), 'Sync now gives snapshots 90 s' );
}

function test_report_range_key() {
	foreach ( array( 7 => '7', 28 => '28', 90 => '90', 365 => '365', 0 => 'all', 13 => '28' ) as $n => $key ) {
		same( $key, ( new Marsad_Report( $n ) )->range_key(), "view $n" );
	}
}

function test_forget_connector_with_delete_data_removes_snapshots() {
	$db = marsad_test_snapshots_db();
	$c  = new Marsad_Test_Snapshot_Connector();
	marsad_test_registry( array( $c ) );
	Marsad_Snapshots::refresh( $c, time() + 60 );
	$c->fail = array( '7' );
	Marsad_Snapshots::record_error( 'snap', '7', 'x' );

	$_POST = array( 'connector' => 'snap' );
	try {
		Marsad_Admin::handle_forget_connector();
	} catch ( Exception $e ) {
		check( 0 === strpos( $e->getMessage(), 'redirect: ' ), $e->getMessage() );
	}
	check( count( $db->snapshots ) > 0, 'kept without delete_data' );

	marsad_test_registry( array( $c ) );
	$_POST = array( 'connector' => 'snap', 'delete_data' => '1' );
	try {
		Marsad_Admin::handle_forget_connector();
	} catch ( Exception $e ) {
		check( 0 === strpos( $e->getMessage(), 'redirect: ' ), $e->getMessage() );
	}
	$_POST = array();
	same( array(), $db->snapshots, 'snapshots deleted with the data' );
	same( array(), Marsad_Snapshots::errors( 'snap' ) );
	check( in_array( 'DELETE wp_marsad_metrics', $db->queries, true ), 'daily rows deleted too' );
}
