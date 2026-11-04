<?php
/**
 * Live stream source: counts pushed by server/stream/marsad_stream.py.
 */

function marsad_stream_payload() {
	return array(
		'version'   => 1,
		'generated' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'days'      => array(
			gmdate( 'Y-m-d' ) => array(
				'viewers'       => 25,
				'watch_minutes' => 600,
				'peak'          => 13,
				'peak_at'       => '16:23',
				'platforms'     => array( 'live_page' => 8, 'tools' => 7, 'app_ios' => 4, 'iptv' => 5, 'tv_android' => 1 ),
				'sites'         => array( 'other.example' => 2 ),
				'tools'         => array( 'Lavf' => 2, 'curl' => 3 ),
				'bots'          => 2,
			),
		),
		'now'       => array( 'viewers' => 13, 'platforms' => array( 'live_page' => 2, 'iptv' => 3 ), 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ),
	);
}

function marsad_stream_enable( $token = 'k3y-for-tests-only-0123456789abcdef' ) {
	Marsad_Registry::save( Marsad_Registry::get( 'stream' ), true, array( 'token' => $token ) );
	return $token;
}

function test_push_needs_the_right_key() {
	marsad_stream_enable();
	same( 401, Marsad_Connector_Stream::receive( 'Bearer wrong', marsad_stream_payload() )['code'] );
	same( 401, Marsad_Connector_Stream::receive( '', marsad_stream_payload() )['code'] );
	Marsad_Registry::save( Marsad_Registry::get( 'stream' ), false, array( 'token' => '' ) );
	same( 401, Marsad_Connector_Stream::receive( 'Bearer ', marsad_stream_payload() )['code'], 'no key configured: nothing accepted' );
}

function test_push_stores_daily_counts_and_now() {
	$token = marsad_stream_enable();
	$r     = Marsad_Connector_Stream::receive( 'Bearer ' . $token, marsad_stream_payload() );
	same( 200, $r['code'] );
	$sql = implode( "\n", $GLOBALS['wpdb']->queries );
	check( false !== strpos( $sql, "'stream','viewers'" ) && false !== strpos( $sql, "'stream','watch_hours'" ) && false !== strpos( $sql, "'stream','peak'" ), 'daily totals stored' );
	check( false !== strpos( $sql, "'stream','viewers_by_platform','iPhone app'" ), 'platforms stored with readable names' );
	check( false !== strpos( $sql, "'stream','restreamers','Lavf'" ) );
	check( false !== strpos( $sql, "'stream','viewers_by_site','other.example'" ) );
	$now = Marsad_Connector_Stream::now();
	same( 13, $now['viewers'] );
}

function test_push_rejects_malformed_days_and_absurd_values() {
	$token = marsad_stream_enable();
	$p     = marsad_stream_payload();
	$p['days']['not-a-date'] = $p['days'][ gmdate( 'Y-m-d' ) ];
	$p['days'][ gmdate( 'Y-m-d' ) ]['viewers'] = -5;
	$r = Marsad_Connector_Stream::receive( 'Bearer ' . $token, $p );
	same( 200, $r['code'] );
	$sql = implode( "\n", $GLOBALS['wpdb']->queries );
	check( false === strpos( $sql, 'not-a-date' ), 'bad day keys ignored' );
	check( false === strpos( $sql, '-5' ), 'negative counts ignored' );
	same( 400, Marsad_Connector_Stream::receive( 'Bearer ' . $token, array( 'days' => 'x' ) )['code'] );
}

function test_stream_sync_reports_a_silent_collector() {
	marsad_stream_enable();
	$c = Marsad_Registry::get( 'stream' );
	throws( function () use ( $c ) { $c->fetch( 'a', 'b' ); }, 'No data received' );
	update_option( Marsad_Connector_Stream::OPT_LAST, time() - 3600 );
	throws( function () use ( $c ) { $c->fetch( 'a', 'b' ); }, 'stopped sending' );
	update_option( Marsad_Connector_Stream::OPT_LAST, time() - 60 );
	same( array(), $c->fetch( 'a', 'b' ) );
}
