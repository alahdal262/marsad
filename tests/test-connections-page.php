<?php

foreach (
	array(
		'checked'       => function ( $a, $b = true, $e = true ) { return (string) $a === (string) $b ? ' checked="checked"' : ''; },
		'selected'      => function ( $a, $b = true, $e = true ) { return (string) $a === (string) $b ? ' selected="selected"' : ''; },
		'esc_textarea'  => function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); },
	) as $name => $fn
) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS['marsad_stub_' . $name] = $fn;
		eval( "function $name( ...\$a ) { return call_user_func_array( \$GLOBALS['marsad_stub_$name'], \$a ); }" ); // phpcs:ignore
	}
}

function marsad_render_connections() {
	$_GET = array();
	ob_start();
	Marsad_Admin_Connections::render();
	return ob_get_clean();
}

function test_connections_page_offers_google_button_and_folds_service_accounts() {
	set_transient( 'marsad_relay_status', array( 'google' => true, 'youtube' => true ) );
	$h = marsad_render_connections();
	check( false !== strpos( $h, 'Connect with Google' ) );
	check( false !== strpos( $h, 'name="action" value="marsad_google_connect"' ) );
	check( substr_count( $h, 'class="marsad-adv"' ) >= 4, 'service accounts and API key folded under Advanced' );
	check( false !== strpos( $h, '+ Add a source' ) );
}

function test_connections_page_shows_pick_lists_once_google_is_connected() {
	marsad_test_connect_google();
	Marsad_Http::$mock = function ( $m, $url ) {
		if ( false !== strpos( $url, 'accountSummaries' ) ) {
			return array( 'code' => 200, 'body' => '{"accountSummaries":[{"displayName":"Yemen TV","propertySummaries":[{"property":"properties/256087272","displayName":"yementv.tv"}]}]}', 'headers' => array() );
		}
		if ( false !== strpos( $url, 'webmasters/v3/sites' ) ) {
			return array( 'code' => 200, 'body' => '{"siteEntry":[{"siteUrl":"sc-domain:yementv.tv","permissionLevel":"siteOwner"}]}', 'headers' => array() );
		}
		if ( false !== strpos( $url, 'mine=true' ) ) {
			return array( 'code' => 200, 'body' => '{"items":[{"id":"UC1","snippet":{"title":"Yemen TV"}}]}', 'headers' => array() );
		}
		return array( 'code' => 500, 'body' => '{}', 'headers' => array() );
	};
	$h = marsad_render_connections();
	check( false !== strpos( $h, 'Connected as owner@example.com' ) );
	check( false !== strpos( $h, '<option value="256087272">Yemen TV › yementv.tv (256087272)</option>' ), 'GA4 property list' );
	check( false !== strpos( $h, 'sc-domain:yementv.tv</option>' ), 'Search Console list' );
	check( false !== strpos( $h, 'Yemen TV (UC1)</option>' ), 'YouTube channel list' );
	check( false !== strpos( $h, 'marsad_google_disconnect' ) );
}
