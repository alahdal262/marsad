<?php
require __DIR__ . "/lib.php";
mc_send( mc_youtube( $_GET, mc_config(), 'mc_curl_get', $_SERVER['REMOTE_ADDR'] ?? '' ) );
