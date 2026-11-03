<?php
/**
 * Who can see the dashboard, how often to sync, how long to keep data, and updates.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Settings {

	public static function render() {
		if ( ! current_user_can( Marsad_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to change Marsad settings.', 'marsad' ), 403 );
		}
		$s = Marsad_Plugin::settings();
		echo '<div class="wrap marsad"><h1>' . esc_html__( 'Marsad settings', 'marsad' ) . '</h1>';
		Marsad_Admin::print_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'marsad_save_settings' );
		echo '<input type="hidden" name="action" value="marsad_save_settings"><table class="form-table" role="presentation">';

		echo '<tr><th>' . esc_html__( 'Who can see the dashboard', 'marsad' ) . '</th><td><fieldset>';
		foreach ( wp_roles()->roles as $key => $role ) {
			$is_admin = 'administrator' === $key;
			echo '<label><input type="checkbox" name="view_roles[]" value="' . esc_attr( $key ) . '"' . checked( $is_admin || in_array( $key, $s['view_roles'], true ), true, false ) . disabled( $is_admin, true, false ) . '> ' . esc_html( translate_user_role( $role['name'] ) ) . '</label><br>';
		}
		echo '<p class="description">' . esc_html__( 'They can view the numbers only. Connecting accounts and these settings stay with administrators.', 'marsad' ) . '</p></fieldset></td></tr>';

		$intervals = array(
			'hourly'     => __( 'Every hour', 'marsad' ),
			'marsad_6h'  => __( 'Every 6 hours', 'marsad' ),
			'twicedaily' => __( 'Twice a day', 'marsad' ),
			'daily'      => __( 'Once a day', 'marsad' ),
		);
		echo '<tr><th><label for="marsad-interval">' . esc_html__( 'Sync', 'marsad' ) . '</label></th><td><select id="marsad-interval" name="interval">';
		foreach ( $intervals as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['interval'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="marsad-backfill">' . esc_html__( 'First import', 'marsad' ) . '</label></th><td><input id="marsad-backfill" type="number" min="7" max="480" name="backfill_days" value="' . esc_attr( $s['backfill_days'] ) . '" class="small-text"> ' . esc_html__( 'days of history when a source is connected', 'marsad' ) . '</td></tr>';
		echo '<tr><th><label for="marsad-retention">' . esc_html__( 'Keep data for', 'marsad' ) . '</label></th><td><input id="marsad-retention" type="number" min="30" max="3650" name="retention_days" value="' . esc_attr( $s['retention_days'] ) . '" class="small-text"> ' . esc_html__( 'days', 'marsad' ) . '</td></tr>';
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Save settings', 'marsad' ) . '</button></p></form>';

		$r = ( defined( 'MARSAD_DISABLE_GITHUB_UPDATES' ) && MARSAD_DISABLE_GITHUB_UPDATES ) ? null : Marsad_Updater::latest();
		echo '<h2>' . esc_html__( 'Updates', 'marsad' ) . '</h2><p>';
		/* translators: %s: version */
		echo esc_html( sprintf( __( 'Installed version: %s', 'marsad' ), MARSAD_VERSION ) );
		if ( $r ) {
			/* translators: %s: version */
			echo ' · ' . esc_html( sprintf( __( 'Latest release: %s', 'marsad' ), $r['version'] ) );
		}
		echo ' · <a href="https://github.com/' . esc_attr( MARSAD_UPDATE_REPO ) . '" target="_blank" rel="noopener">GitHub</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'marsad_check_update' );
		echo '<input type="hidden" name="action" value="marsad_check_update"><button class="button">' . esc_html__( 'Check for updates now', 'marsad' ) . '</button></form>';
		echo '</div>';
	}
}
