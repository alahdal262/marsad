<?php
/**
 * Removes everything Marsad stored when the plugin is deleted from the Plugins screen.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'marsad_metrics' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'marsad_snapshots' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

foreach ( array( 'marsad_settings', 'marsad_connections', 'marsad_status', 'marsad_db_version', 'marsad_snapshot_errors' ) as $opt ) {
	delete_option( $opt );
}
delete_site_transient( 'marsad_release' );
wp_clear_scheduled_hook( 'marsad_sync' );

foreach ( wp_roles()->role_objects as $role ) {
	$role->remove_cap( 'marsad_view' );
	$role->remove_cap( 'marsad_manage' );
}
