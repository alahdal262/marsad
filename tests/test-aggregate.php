<?php

function test_days_inclusive_and_across_months() {
	same( array( '2026-01-30', '2026-01-31', '2026-02-01' ), Marsad_Aggregate::days( '2026-01-30', '2026-02-01' ) );
	same( array( '2026-03-01' ), Marsad_Aggregate::days( '2026-03-01', '2026-03-01' ) );
}

function test_range_ends_yesterday_and_previous_window_touches_it() {
	$r = Marsad_Aggregate::range( 7, '2026-11-03' );
	same( '2026-11-02', $r['to'] );
	same( '2026-10-27', $r['from'] );
	same( '2026-10-26', $r['prev_to'] );
	same( '2026-10-20', $r['prev_from'] );
	same( 7, count( Marsad_Aggregate::days( $r['prev_from'], $r['prev_to'] ) ) );
}

function test_collapse_modes() {
	$d = array( '2026-01-02' => 10.0, '2026-01-01' => 4.0, '2026-01-03' => 7.0 );
	near( 21, Marsad_Aggregate::collapse( $d, 'sum' ) );
	near( 7, Marsad_Aggregate::collapse( $d, 'avg' ) );
	near( 7, Marsad_Aggregate::collapse( $d, 'last' ), 'last is by date, not insertion order' );
	near( 10, Marsad_Aggregate::collapse( $d, 'max' ) );
	same( null, Marsad_Aggregate::collapse( array(), 'sum' ) );
}

function test_change_handles_zero_and_missing() {
	near( 50, Marsad_Aggregate::change( 150, 100 ) );
	near( -25, Marsad_Aggregate::change( 75, 100 ) );
	same( null, Marsad_Aggregate::change( 5, 0 ) );
	same( null, Marsad_Aggregate::change( null, 3 ) );
	same( null, Marsad_Aggregate::change( 3, null ) );
}

function test_series_counters_fill_zero_snapshots_carry_forward() {
	$days = array( '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04' );
	$data = array( '2026-01-02' => 5.0, '2026-01-04' => 9.0 );
	same( array( 0.0, 5.0, 0.0, 9.0 ), Marsad_Aggregate::series( $data, $days, 'sum' ) );
	same( array( null, 5.0, 5.0, 9.0 ), Marsad_Aggregate::series( $data, $days, 'last' ) );
}

function test_top_ranks_limits_and_shares() {
	$b   = array(
		'/a' => array( 'd1' => 5, 'd2' => 5 ),
		'/b' => array( 'd1' => 30 ),
		'/c' => array( 'd1' => 1 ),
	);
	$top = Marsad_Aggregate::top( $b, 'sum', 2 );
	same( 2, count( $top ) );
	same( '/b', $top[0]['dim'] );
	near( 30, $top[0]['value'] );
	near( 0.75, $top[0]['share'] );
	same( '/a', $top[1]['dim'] );
}

function test_svg_points_and_short_numbers() {
	same( '', Marsad_Svg::points( array( null, null ), 100, 10 ) );
	same( '0,10 100,0', Marsad_Svg::points( array( 1, 2 ), 100, 10, 0 ) );
	same( '1234', Marsad_Svg::short( 1234 ), 'under 10k stays exact' );
	same( '12.3K', Marsad_Svg::short( 12345 ) );
	same( '2.5M', Marsad_Svg::short( 2500000 ) );
	same( '999', Marsad_Svg::short( 999 ) );
}
