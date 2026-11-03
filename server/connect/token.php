<?php
require __DIR__ . "/lib.php";
if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) { mc_send( mc_json( 405, array( 'error' => 'method' ) ) ); exit; }
mc_send( mc_token( $_POST, mc_config(), 'mc_curl_post' ) );
