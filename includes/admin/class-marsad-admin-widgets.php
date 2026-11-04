<?php
/**
 * Marsad sections as boxes on the WordPress Dashboard (the screen after login). Which
 * sections each role gets is chosen in Marsad → Settings; each user can still hide or move
 * them under Screen Options, like any other Dashboard box.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Widgets {

	/** wp_dashboard_setup: one box per section the current user should get. */
	public static function register() {
		$tabs = Marsad_Admin_Dashboard::tabs();
		foreach ( Marsad_Plugin::dashboard_tabs() as $tab ) {
			wp_add_dashboard_widget(
				'marsad-' . $tab,
				/* translators: %s: dashboard section name, e.g. "Audience" */
				sprintf( __( 'Marsad: %s', 'marsad' ), $tabs[ $tab ] ),
				function () use ( $tab ) {
					Marsad_Admin_Dashboard::widget( $tab );
				}
			);
		}
	}
}
