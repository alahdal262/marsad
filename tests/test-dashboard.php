<?php
/**
 * Renders every dashboard tab against seeded data: catches PHP errors and
 * checks that each tab shows what the design promises.
 */

foreach (
	array(
		'current_user_can'    => function ( $cap ) { return true; },
		'wp_die'              => function ( $m ) { throw new Exception( 'wp_die: ' . $m ); },
		'sanitize_key'        => function ( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); },
		'wp_unslash'          => function ( $v ) { return $v; },
		'esc_attr__'          => function ( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); },
		'esc_url'             => function ( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); },
		'get_bloginfo'        => function ( $k ) { return 'Test Site'; },
		'wp_parse_url'        => function ( $u, $c = -1 ) { return parse_url( $u, $c ); },
		'admin_url'           => function ( $p = '' ) { return 'https://example.test/wp-admin/' . $p; },
		'add_query_arg'       => function ( $args, $url ) { return $url . '?' . http_build_query( $args ); },
		'wp_nonce_field'      => function ( $a ) { echo '<input type="hidden" name="_wpnonce" value="x">'; },
		'get_current_user_id' => function () { return 1; },
		'number_format_i18n'  => function ( $n, $d = 0 ) { return number_format( (float) $n, $d ); },
		'human_time_diff'     => function ( $a, $b ) { return '5 mins'; },
		'is_rtl'              => function () { return true; },
		'_get_cron_array'     => function () { return array( time() + 60 => array( 'marsad_sync' => array( 'k' => array() ) ) ); },
		'get_plugins'         => function () { return array( 'a/a.php' => array(), 'b/b.php' => array() ); },
		'wp_get_theme'        => function () { return new class() { public function get( $k ) { return 'Test Theme'; } }; },
		'size_format'         => function ( $b ) { return round( $b / 1048576 ) . ' MB'; },
		'wp_max_upload_size'  => function () { return 64 * 1048576; },
	) as $name => $fn
) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['marsad_stub_' . $name] = $fn;
		eval( "function $name( ...\$a ) { return call_user_func_array( \$GLOBALS['marsad_stub_$name'], \$a ); }" ); // phpcs:ignore
	}
}

function marsad_test_seed_dashboard() {
	$today = gmdate( 'Y-m-d' );
	$f     = &$GLOBALS['wpdb']->fixture;
	for ( $i = 1; $i <= 28; $i++ ) {
		$d   = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		$f[] = array( 'wordpress', 'posts', '', $d, 20 + $i % 7 );
		$f[] = array( 'wordpress', 'posts_by_category', 'Local', $d, 10 );
		$f[] = array( 'wordpress', 'posts_by_author', 'Newsroom', $d, 18 );
		$f[] = array( 'site', 'response_ms', '', $d, 300 + $i );
	}
	foreach ( array( 'posts_total' => 52006, 'pages' => 13, 'images' => 22500, 'videos' => 11, 'comments_approved' => 1081, 'comments_pending' => 28, 'comments_spam' => 97, 'comments_trash' => 140, 'users' => 9, 'categories' => 57, 'tags' => 27, 'db_mb' => 1589.5, 'db_tables' => 144, 'uploads_mb' => 4403.2, 'response_ms' => 420 ) as $k => $v ) {
		$f[] = array( 'site', $k, '', $today, $v );
	}
	$f[] = array( 'site', 'users_by_role', 'Editor', $today, 4 );
	$f[] = array( 'site', 'users_by_role', 'Administrator', $today, 1 );

	$id = Marsad_Registry::add_custom();
	Marsad_Registry::save( Marsad_Registry::get( $id ), true, array( 'name' => 'Frame campaign', 'url' => 'https://x.test', 'map' => "Visitors = visitors\nShares = shares", 'mode' => 'total' ) );
	$c = Marsad_Registry::get( $id );
	foreach ( array_keys( $c->metrics() ) as $n => $key ) {
		$f[] = array( $id, $key, '', $today, 0 === $n ? 315 : 7 );
	}
	update_option( Marsad_Sync::OPT_STATUS, array( 'wordpress' => array( 'at' => time(), 'ok' => true ), $id => array( 'at' => time(), 'ok' => true ) ) );
}

function marsad_test_render_tab( $tab ) {
	$_GET = array( 'tab' => $tab, 'range' => 28 );
	ob_start();
	Marsad_Admin_Dashboard::render();
	return ob_get_clean();
}

function test_overview_tab_shows_headline_chart_categories_and_custom_source() {
	marsad_test_seed_dashboard();
	$h = marsad_test_render_tab( 'overview' );
	check( false !== strpos( $h, 'Posts per day' ), 'posts-per-day card' );
	check( false !== strpos( $h, '52,006' ), 'total posts card' );
	check( substr_count( $h, '<i style="height:' ) >= 28, 'a bar per day' );
	check( false !== strpos( $h, 'Local' ), 'busiest category' );
	check( false !== strpos( $h, 'Frame campaign' ) && false !== strpos( $h, '315' ), 'custom source funnel' );
	check( false !== strpos( $h, 'Connect Google Analytics' ), 'GA connect card' );
	check( false !== strpos( $h, 'aria-current="page"' ), 'active tab marked' );
}

function test_content_tab_shows_totals_authors_roles_and_comments() {
	marsad_test_seed_dashboard();
	$h = marsad_test_render_tab( 'content' );
	foreach ( array( '22,500', '4.3 GB', '28 awaiting moderation', 'Newsroom', 'Editor', '1,081', 'Review 28 pending comments', 'Posts by status', 'Deleted permanently', 'Moved to the trash' ) as $needle ) {
		check( false !== strpos( $h, $needle ), "content tab shows $needle" );
	}
}

function test_platforms_tab_lists_custom_and_unconnected_platforms() {
	marsad_test_seed_dashboard();
	$h = marsad_test_render_tab( 'platforms' );
	check( false !== strpos( $h, 'Frame campaign' ), 'custom source card' );
	check( false !== strpos( $h, 'Google Analytics 4' ) && false !== strpos( $h, 'Not connected yet' ), 'unconnected platforms' );
	check( false === strpos( $h, '>Site &amp; hosting<' ), 'core sources stay on their own tabs' );
}

function test_hosting_tab_shows_stack_storage_and_health() {
	marsad_test_seed_dashboard();
	$h = marsad_test_render_tab( 'hosting' );
	foreach ( array( 'Everything is running normally', PHP_VERSION, 'Test Theme', '5.9 GB', '144', 'Served over HTTPS' ) as $needle ) {
		check( false !== strpos( $h, $needle ), "hosting tab shows $needle" );
	}
}

function test_hosting_shows_todays_response_time_before_any_full_day() {
	$GLOBALS['wpdb']->fixture[] = array( 'site', 'response_ms', '', gmdate( 'Y-m-d' ), 12 );
	$h = marsad_test_render_tab( 'hosting' );
	check( false !== strpos( $h, '<b class="marsad-big">12</b>' ), 'latest measurement in the header' );
	check( false !== strpos( $h, 'The daily chart starts tomorrow' ), 'explains the empty chart' );
}

function test_unknown_tab_falls_back_to_overview() {
	$h = marsad_test_render_tab( 'nope' );
	check( false !== strpos( $h, 'Overview' ) );
}

function test_nice_axis_maximum() {
	same( 40.0, (float) Marsad_Admin_Dashboard::nice_max( 37 ) );
	same( 60.0, (float) Marsad_Admin_Dashboard::nice_max( 41 ) );
	same( 1000.0, (float) Marsad_Admin_Dashboard::nice_max( 999 ) );
	same( 0, Marsad_Admin_Dashboard::nice_max( 0 ) );
}
