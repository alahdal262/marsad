<?php

function test_fresh_install_has_no_custom_sources() {
	same( array(), Marsad_Registry::custom_ids() );
	same( null, Marsad_Registry::get( 'json' ) );
	foreach ( Marsad_Registry::all() as $c ) {
		check( 'custom' !== $c->group(), 'no custom card until the user adds one' );
	}
}

function test_added_sources_are_independent() {
	$a = Marsad_Registry::add_custom();
	$b = Marsad_Registry::add_custom();
	same( array( 'custom_1', 'custom_2' ), array( $a, $b ) );
	Marsad_Registry::save( Marsad_Registry::get( $a ), true, array( 'name' => 'Shop', 'url' => 'https://a.test', 'map' => 'X = x', 'mode' => 'total' ) );
	Marsad_Registry::save( Marsad_Registry::get( $b ), true, array( 'name' => 'Forms', 'url' => 'https://b.test', 'map' => 'Y = y', 'mode' => 'daily' ) );
	same( 'Shop', Marsad_Registry::get( $a )->label() );
	same( 'Forms', Marsad_Registry::get( $b )->label() );
	same( 'https://b.test', Marsad_Registry::get( $b )->get( 'url' ) );
	same( $a, Marsad_Registry::get( $a )->id() );
}

function test_removed_source_is_gone_and_its_number_not_reused_while_others_exist() {
	$a = Marsad_Registry::add_custom();
	$b = Marsad_Registry::add_custom();
	Marsad_Registry::remove_custom( $a );
	same( array( $b ), Marsad_Registry::custom_ids() );
	same( null, Marsad_Registry::get( $a ) );
	same( 'custom_3', Marsad_Registry::add_custom(), 'ids only grow, so old data never attaches to a new source' );
}

function test_existing_json_source_is_kept_on_upgrade() {
	update_option( Marsad_Registry::OPT, array( 'json' => array( 'enabled' => true, 'values' => array( 'name' => 'Campaign', 'map' => 'V = visitors' ) ) ) );
	Marsad_Registry::reset_cache();
	same( array( 'json' ), Marsad_Registry::custom_ids() );
	same( 'Campaign', Marsad_Registry::get( 'json' )->label() );
}
