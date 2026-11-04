<?php
/**
 * What each role sees on the dashboard and which sources it may connect.
 */

require_once __DIR__ . '/test-dashboard.php';
require_once __DIR__ . '/test-connections-page.php';

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) array( 'ID' => 1, 'roles' => $GLOBALS['marsad_test_roles'] ?? array( 'administrator' ) );
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	class Marsad_Test_Role {
		public $caps = array();
		public function add_cap( $c ) { $this->caps[ $c ] = true; }
		public function remove_cap( $c ) { unset( $this->caps[ $c ] ); }
	}
	function wp_roles() {
		static $r = null;
		if ( null === $r ) {
			$r = (object) array( 'role_objects' => array(), 'roles' => array() );
			foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $n ) {
				$r->role_objects[ $n ] = new Marsad_Test_Role();
				$r->roles[ $n ]        = array( 'name' => ucfirst( $n ) );
			}
		}
		return $r;
	}
}
foreach ( array( 'wp_clear_scheduled_hook' => function () {}, 'wp_next_scheduled' => function () { return time(); }, 'wp_schedule_event' => function () {} ) as $name => $fn ) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS[ 'marsad_stub_' . $name ] = $fn;
		eval( "function $name( ...\$a ) { return call_user_func_array( \$GLOBALS['marsad_stub_$name'], \$a ); }" ); // phpcs:ignore
	}
}

/** Acts as a user with these roles and capabilities for the rest of the test. */
function marsad_test_as( array $roles, array $caps ) {
	$GLOBALS['marsad_test_roles']                = $roles;
	$GLOBALS['marsad_stub_current_user_can'] = function ( $cap ) use ( $caps ) {
		return in_array( $cap, $caps, true );
	};
}

function marsad_test_as_admin() {
	$GLOBALS['marsad_test_roles']                = array( 'administrator' );
	$GLOBALS['marsad_stub_current_user_can'] = function () { return true; };
}

function test_admin_sees_every_tab_and_may_connect_anything() {
	marsad_test_as_admin();
	same( array_keys( Marsad_Admin_Dashboard::tabs() ), Marsad_Plugin::allowed_tabs() );
	check( Marsad_Plugin::can_connect( 'telegram' ) && Marsad_Plugin::can_connect( 'google' ) && Marsad_Plugin::can_connect( 'custom' ) );
}

function test_role_without_settings_keeps_every_tab() {
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	same( array_keys( Marsad_Admin_Dashboard::tabs() ), Marsad_Plugin::allowed_tabs(), 'nothing chosen yet: nothing taken away' );
	check( ! Marsad_Plugin::can_connect( 'telegram' ), 'connecting is never given by default' );
}

function test_chosen_tabs_and_sources_for_a_role() {
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'overview', 'content' ) ), 'role_connect' => array( 'editor' => array( 'telegram', 'youtube' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view', 'marsad_connect' ) );
	same( array( 'overview', 'content' ), Marsad_Plugin::allowed_tabs() );
	check( Marsad_Plugin::can_connect( 'telegram' ) && Marsad_Plugin::can_connect( 'youtube' ) );
	check( ! Marsad_Plugin::can_connect( 'ga4' ) && ! Marsad_Plugin::can_connect( 'google' ) );
}

function test_a_user_with_two_roles_gets_both_sets() {
	Marsad_Plugin::save_settings( array( 'role_tabs' => array( 'editor' => array( 'content' ), 'author' => array( 'search' ) ) ) );
	marsad_test_as( array( 'editor', 'author' ), array( 'marsad_view' ) );
	same( array( 'content', 'search' ), Marsad_Plugin::allowed_tabs() );
}

function test_dashboard_hides_tabs_the_role_may_not_see() {
	marsad_test_seed_dashboard();
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'editor' ), 'role_tabs' => array( 'editor' => array( 'content' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view' ) );
	$h = marsad_test_render_tab( 'hosting' );
	check( false === strpos( $h, 'tab=hosting' ), 'no link to a hidden tab' );
	check( false !== strpos( $h, 'aria-current="page"' ) && false !== strpos( $h, 'Published posts' ), 'an asked-for hidden tab falls back to an allowed one' );
}

function test_connections_page_shows_only_allowed_cards() {
	Marsad_Plugin::save_settings( array( 'role_connect' => array( 'editor' => array( 'telegram' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view', 'marsad_connect' ) );
	$h = marsad_render_connections();
	check( false !== strpos( $h, 'id="conn-telegram"' ) );
	check( false === strpos( $h, 'id="conn-ga4"' ) && false === strpos( $h, 'marsad_google_connect' ), 'no Google for this role' );
	check( false === strpos( $h, 'id="conn-new"' ), 'no custom sources for this role' );
}

function test_saving_a_source_the_role_may_not_connect_is_refused() {
	Marsad_Plugin::save_settings( array( 'role_connect' => array( 'editor' => array( 'telegram' ) ) ) );
	marsad_test_as( array( 'editor' ), array( 'marsad_view', 'marsad_connect' ) );
	check( Marsad_Admin::may_change( 'telegram' ) );
	check( ! Marsad_Admin::may_change( 'facebook' ) );
	check( ! Marsad_Admin::may_change( Marsad_Admin_Connections::NEW_ID ), 'adding a custom source needs the custom permission' );
}

function test_settings_keep_only_real_tabs_and_sources() {
	Marsad_Plugin::save_settings( array( 'role_tabs' => array( 'editor' => array( 'content', 'evil<script>' ) ), 'role_connect' => array( 'editor' => array( 'telegram', 'nope' ) ) ) );
	$s = Marsad_Plugin::settings();
	same( array( 'content' ), $s['role_tabs']['editor'] );
	same( array( 'telegram' ), $s['role_connect']['editor'] );
}

function test_settings_page_lists_each_role_with_its_choices() {
	foreach ( array( 'translate_user_role' => function ( $n ) { return $n; }, 'disabled' => function ( $a, $b = true ) { return $a === $b ? ' disabled="disabled"' : ''; } ) as $name => $fn ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS[ 'marsad_stub_' . $name ] = $fn;
			eval( "function $name( ...\$a ) { return call_user_func_array( \$GLOBALS['marsad_stub_$name'], \$a ); }" ); // phpcs:ignore
		}
	}
	Marsad_Plugin::save_settings( array( 'role_tabs' => array( 'editor' => array( 'content' ) ), 'role_connect' => array( 'editor' => array( 'telegram' ) ) ) );
	marsad_test_as_admin();
	set_site_transient( 'marsad_release', array( 'version' => '9', 'zip' => '', 'body' => '', 'url' => '', 'published' => '' ) );
	ob_start();
	Marsad_Admin_Settings::render();
	$h = ob_get_clean();
	check( false !== strpos( $h, 'name="role_tabs[editor][]" value="content" checked' ) );
	check( false === strpos( $h, 'name="role_tabs[editor][]" value="hosting" checked' ) );
	check( false !== strpos( $h, 'name="role_connect[editor][]" value="telegram" checked' ) );
	check( false === strpos( $h, 'role_tabs[administrator]' ), 'administrators are not restricted' );
}

function test_each_role_card_decides_whether_it_sees_statistics() {
	Marsad_Plugin::save_settings( array( 'view_roles' => array( 'administrator', 'editor' ), 'role_tabs' => array( 'editor' => array( 'content' ) ) ) );
	marsad_test_as_admin();
	set_site_transient( 'marsad_release', array( 'version' => '9', 'zip' => '', 'body' => '', 'url' => '', 'published' => '' ) );
	ob_start();
	Marsad_Admin_Settings::render();
	$h = ob_get_clean();
	check( false !== strpos( $h, 'name="view_roles[]" value="editor" checked' ), 'the switch sits in the editor card, ticked' );
	check( false !== strpos( $h, 'name="view_roles[]" value="author"' ) && false === strpos( $h, 'name="view_roles[]" value="author" checked' ) );
	same( 1, substr_count( $h, 'name="view_roles[]" value="editor"' ), 'one switch per role, not two lists that can disagree' );
}
