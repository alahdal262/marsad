<?php

function test_media_counts_group_by_mime_and_skip_trash() {
	$c = Marsad_Connector_Site::media_counts(
		array( 'image/jpeg' => 10, 'image/webp' => 5, 'video/mp4' => 2, 'application/pdf' => 1, 'trash' => 9 )
	);
	same( array( 'images' => 15, 'videos' => 2, 'other' => 1 ), $c );
}

function test_dir_size_counts_nested_files_and_gives_up_past_the_limit() {
	$root = sys_get_temp_dir() . '/marsad-size-' . getmypid();
	@mkdir( $root . '/a/b', 0777, true );
	file_put_contents( $root . '/one.txt', str_repeat( 'x', 100 ) );
	file_put_contents( $root . '/a/b/two.txt', str_repeat( 'x', 50 ) );
	same( 150, Marsad_Connector_Site::dir_size( $root ) );
	same( null, Marsad_Connector_Site::dir_size( $root, 1 ), 'too many files: unknown, not a wrong number' );
	same( null, Marsad_Connector_Site::dir_size( $root . '/missing' ) );
	unlink( $root . '/a/b/two.txt' );
	unlink( $root . '/one.txt' );
	rmdir( $root . '/a/b' );
	rmdir( $root . '/a' );
	rmdir( $root );
}

function test_site_source_is_always_on_and_records_totals() {
	$c = Marsad_Registry::get( 'site' );
	check( null !== $c, 'site source is built in' );
	check( $c->always_on() && $c->is_snapshot() );
	$m = $c->metrics();
	foreach ( array( 'posts_total', 'pages', 'images', 'videos', 'comments_approved', 'comments_pending', 'users', 'db_mb', 'uploads_mb', 'response_ms' ) as $k ) {
		check( isset( $m[ $k ] ), "metric $k" );
	}
	same( 'last', $m['posts_total']['agg'] );
}
