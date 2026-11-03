<?php
require __DIR__ . "/lib.php";
mc_send( mc_callback( $_GET, mc_config(), 'mc_curl_post' ) );
