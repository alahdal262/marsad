<?php
/**
 * Who can see the dashboard, how often to sync, how long to keep data, and updates.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Settings {

	/**
	 * Per role: the dashboard tabs it sees and the sources it may connect. A role nobody
	 * restricted sees every tab; connecting is never given until ticked here.
	 */
	private static function roles_matrix( array $s ) {
		$tabs  = Marsad_Admin_Dashboard::tabs();
		$names = array(
			'google' => __( 'Google account (Analytics, Search Console, YouTube, Google Play)', 'marsad' ),
			'custom' => __( 'Your own sources', 'marsad' ),
		);
		foreach ( Marsad_Registry::all() as $c ) {
			$names[ $c->id() ] = $c->label();
		}
		echo '<h2>' . esc_html__( 'What each role sees and may connect', 'marsad' ) . '</h2><div class="marsad-roles">';
		foreach ( wp_roles()->roles as $key => $role ) {
			$home = $s['role_widgets'][ $key ] ?? array( 'overview' );
			echo '<fieldset class="marsad-role"><legend>' . esc_html( translate_user_role( $role['name'] ) ) . '</legend>';
			echo '<input type="hidden" name="roles_shown[]" value="' . esc_attr( $key ) . '">';
			if ( 'administrator' === $key ) {
				echo '<p class="marsad-sub">' . esc_html__( 'Sees every section and may connect every platform.', 'marsad' ) . '</p>';
				self::widget_choices( $key, $tabs, $home );
				echo '</fieldset>';
				continue;
			}
			$seen = $s['role_tabs'][ $key ] ?? array_keys( $tabs );
			$may  = $s['role_connect'][ $key ] ?? array();
			// One place decides whether the role sees statistics at all; the sections below refine it.
			echo '<label class="marsad-role-view"><input type="checkbox" name="view_roles[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, (array) $s['view_roles'], true ), true, false ) . '> <b>' . esc_html__( 'Can see the statistics', 'marsad' ) . '</b></label>';
			echo '<div class="marsad-role-tabs"><b>' . esc_html__( 'Sections it sees', 'marsad' ) . '</b>';
			foreach ( $tabs as $t => $label ) {
				echo '<label><input type="checkbox" name="role_tabs[' . esc_attr( $key ) . '][]" value="' . esc_attr( $t ) . '"' . checked( in_array( $t, $seen, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
			}
			echo '</div>';
			self::widget_choices( $key, $tabs, $home );
			echo '<div><b>' . esc_html__( 'Platforms it may connect', 'marsad' ) . '</b>';
			foreach ( Marsad_Plugin::connectable() as $id ) {
				echo '<label><input type="checkbox" name="role_connect[' . esc_attr( $key ) . '][]" value="' . esc_attr( $id ) . '"' . checked( in_array( $id, $may, true ), true, false ) . '> ' . esc_html( $names[ $id ] ?? $id ) . '</label>';
			}
			echo '</div></fieldset>';
		}
		echo '</div><p class="description">' . esc_html__( 'Administrators always see and connect everything. The sections count only when "Can see the statistics" is ticked; a role may connect platforms without seeing the statistics.', 'marsad' ) . ' ' . esc_html__( 'Sections ticked under "On the WordPress Dashboard" appear as boxes on the home screen after login; each user can still hide or move them under Screen Options.', 'marsad' ) . '</p>';
	}

	/** Which sections a role gets as boxes on the WordPress Dashboard. */
	private static function widget_choices( $role, array $tabs, array $home ) {
		echo '<div class="marsad-role-tabs"><b>' . esc_html__( 'On the WordPress Dashboard', 'marsad' ) . '</b>';
		foreach ( $tabs as $t => $label ) {
			echo '<label><input type="checkbox" name="role_widgets[' . esc_attr( $role ) . '][]" value="' . esc_attr( $t ) . '"' . checked( in_array( $t, $home, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
	}

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

		echo '</table>';
		self::roles_matrix( $s );
		echo '<table class="form-table" role="presentation">';

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
