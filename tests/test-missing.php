<?php

function test_each_source_names_what_it_still_needs() {
	$ga = new Marsad_Connector_Ga4();
	$ga->set_values( array( 'property_id' => '256087272' ) );
	same( array( 'Connect with Google (or a service account key)' ), $ga->missing() );

	$as = new Marsad_Connector_App_Store();
	$as->set_values( array( 'issuer_id' => 'i', 'key_id' => 'k', 'p8' => 'p', 'apple_id' => '1' ) );
	same( array( 'Vendor number' ), $as->missing() );

	$tg = new Marsad_Connector_Telegram();
	$tg->set_values( array( 'chat' => '@x' ) );
	same( array( 'Bot token' ), $tg->missing() );

	$yt = new Marsad_Connector_Youtube();
	same( array( 'Connect with Google (or an API key and channel ID)' ), $yt->missing() );
	marsad_test_connect_google();
	same( array(), $yt->missing() );
	same( array(), $ga->missing() );
}

function test_connections_card_says_what_is_missing() {
	Marsad_Registry::save( Marsad_Registry::get( 'telegram' ), true, array( 'chat' => '@x', 'token' => '' ) );
	$h = marsad_render_connections();
	check( false !== strpos( $h, 'Missing: Bot token' ), 'card pill names the gap' );
}
