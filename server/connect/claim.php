<?php
require __DIR__ . "/lib.php";
if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) { mc_send( mc_json( 405, array( 'error' => 'method' ) ) ); exit; }
mc_send( mc_claim( $_POST, mc_config() ) );
