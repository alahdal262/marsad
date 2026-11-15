<?php
/**
 * Marsad sections on the WordPress Dashboard (wp-admin home), chosen per role.
 */

require_once __DIR__ . '/test-roles.php';
require_once __DIR__ . '/test-dashboard-deep.php';

if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
	function wp_add_dashboard_widget( $id, $title, $callback ) {
		$GLOBALS['marsad_test_widgets'][ $id ] = array( $title, $callback );
	}
}

function marsad_test_widgets() {
	$GLOBALS['marsad_test_widgets'] = array();
	Marsad_Admin_Widgets::register();
	return array_keys( $GLOBALS['marsad_test_widgets'] );
}

function test_a_role_gets_the_sections_chosen_for_its_dashboard() {
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'overview', 'content', 'search' ) ), 'role_widgets' => array( 'editor' => array( 'content', 'overview' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	same( array( 'marsad-overview', 'marsad-content' ), marsad_test_widgets(), 'in dashboard order' );
}

function test_without_a_choice_a_role_gets_the_overview_only() {
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	same( array( 'marsad-overview' ), marsad_test_widgets() );
}

function test_no_widget_for_a_section_the_role_may_not_see() {
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'content' ) ), 'role_widgets' => array( 'editor' => array( 'hosting', 'content' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	same( array( 'marsad-content' ), marsad_test_widgets() );
}

function test_no_widgets_without_the_statistics() {
	Marsad_Plugin::save_settings( array( 'role_widgets' => array( 'author' => array( 'overview' ) ) ) );
	marsad_test_as( array( 'author' ), array() );
	same( array(), marsad_test_widgets() );
}

function test_administrators_choose_their_own_widgets() {
	Marsad_Plugin::save_settings( array( 'role_widgets' => array( 'administrator' => array( 'platforms', 'overview', 'nonsense' ) ) ) );
	same( array( 'platforms', 'overview' ), Marsad_Plugin::settings()['role_widgets']['administrator'], 'kept for administrators, unknown sections dropped' );
	marsad_test_as_admin();
	same( array( 'marsad-overview', 'marsad-platforms' ), marsad_test_widgets() );
}

function test_a_widget_shows_its_section_and_links_to_it() {
	marsad_test_deep_site();
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'overview', 'content' ) ), 'role_widgets' => array( 'editor' => array( 'content', 'overview' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	marsad_test_widgets();
	ob_start();
	call_user_func( $GLOBALS['marsad_test_widgets']['marsad-content'][1] );
	$h = ob_get_clean();
	check( false !== strpos( $h, 'Posts published' ), 'the section numbers' );
	check( false !== strpos( $h, 'tab=content' ), 'a link to the full section' );
	ob_start();
	call_user_func( $GLOBALS['marsad_test_widgets']['marsad-overview'][1] );
	$h = ob_get_clean();
	check( false !== strpos( $h, 'marsad-card' ), 'the key numbers' );
	check( false === strpos( $h, 'tab=hosting' ), 'nothing from a hidden section' );
}

function test_settings_let_each_role_pick_its_dashboard_sections() {
	Marsad_Plugin::save_settings( array( 'role_widgets' => array( 'editor' => array( 'content' ) ) ) );
	marsad_test_as_admin();
	set_site_transient( 'marsad_release', array( 'version' => '9', 'zip' => '', 'body' => '', 'url' => '', 'published' => '' ) );
	ob_start();
	Marsad_Admin_Settings::render();
	$h = ob_get_clean();
	check( false !== strpos( $h, 'name="role_widgets[editor][]" value="content" checked' ) );
	check( false === strpos( $h, 'name="role_widgets[editor][]" value="overview" checked' ) );
	check( false !== strpos( $h, 'name="role_widgets[administrator][]" value="overview" checked' ), 'administrators pick too; overview by default' );
	check( false === strpos( $h, 'role_tabs[administrator]' ), 'administrators still see every section' );
}

function test_users_is_a_dashboard_box_of_its_own() {
	$db = marsad_test_deep_site();
	$db->fixture[] = array( 'site', 'users', '', gmdate( 'Y-m-d' ), 42 );
	$db->fixture[] = array( 'site', 'users_by_role', 'Editor', gmdate( 'Y-m-d' ), 7 );
	$db->fixture[] = array( 'wordpress', 'posts_by_author', 'Qahtany', gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 12 );
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'content' ) ), 'role_widgets' => array( 'editor' => array( 'users' ) ) ) );
	same( array( 'users' ), Marsad_Plugin::settings()['role_widgets']['editor'], 'users is a valid choice' );
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	same( array( 'marsad-users' ), marsad_test_widgets(), 'users without content' );
	same( 'Marsad: Users', $GLOBALS['marsad_test_widgets']['marsad-users'][0] );
	ob_start();
	call_user_func( $GLOBALS['marsad_test_widgets']['marsad-users'][1] );
	$h = ob_get_clean();
	check( false !== strpos( $h, '42' ) && false !== strpos( $h, 'Editor' ), 'total and roles' );
	check( false !== strpos( $h, 'Most active authors' ), 'authors' );
	check( false === strpos( $h, 'Posts published' ), 'no posts in the users box' );
	check( false !== strpos( $h, 'tab=content' ), 'links to the section it comes from' );
}

function test_content_box_is_called_content_and_users_needs_the_content_section() {
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'content' ) ), 'role_widgets' => array( 'editor' => array( 'content' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	marsad_test_widgets();
	same( 'Marsad: Content', $GLOBALS['marsad_test_widgets']['marsad-content'][0] );
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'search' ) ), 'role_widgets' => array( 'editor' => array( 'users', 'search' ) ) ) );
	same( array( 'marsad-search' ), marsad_test_widgets(), 'no users box for a role that cannot see Content & users' );
}

function test_settings_offer_users_as_a_separate_box() {
	Marsad_Plugin::save_settings( array( 'role_widgets' => array( 'editor' => array( 'users' ) ) ) );
	marsad_test_as_admin();
	set_site_transient( 'marsad_release', array( 'version' => '9', 'zip' => '', 'body' => '', 'url' => '', 'published' => '' ) );
	ob_start();
	Marsad_Admin_Settings::render();
	$h = ob_get_clean();
	check( false !== strpos( $h, 'name="role_widgets[editor][]" value="users" checked' ) );
	check( false !== strpos( $h, 'name="role_widgets[editor][]" value="content"' ) && false === strpos( $h, 'name="role_widgets[editor][]" value="content" checked' ) );
}
