<?php
/**
 * Marsad Connect: the small relay that gives every Marsad site a "Connect with Google"
 * button without each site registering its own Google OAuth client.
 *
 * Flow (PKCE-style, so nothing useful ever travels in a browser URL):
 *   1. start.php    site sends the user here with ?return=<its admin-post URL>&challenge=<sha256(verifier)>.
 *                   We remember both under a random state and redirect to Google's consent screen.
 *   2. callback.php Google returns ?code&state. We swap the code for tokens, park them for five
 *                   minutes under a one-time handover code and send the user back to the site.
 *   3. claim.php    the site's server posts the handover code plus its verifier; we check
 *                   sha256(verifier) against the challenge, hand over the tokens once and forget them.
 *   4. token.php    the site posts its refresh token whenever it needs a fresh access token;
 *                   we add the client secret, ask Google, and pass the answer back. Nothing is stored.
 *
 * Tokens are never logged. Parked entries live in a directory outside the web root.
 */

const MC_GOOGLE_AUTH  = 'https://accounts.google.com/o/oauth2/v2/auth';
const MC_GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
const MC_SCOPES       = array(
	'openid',
	'email',
	'https://www.googleapis.com/auth/analytics.readonly',
	'https://www.googleapis.com/auth/webmasters.readonly',
	'https://www.googleapis.com/auth/youtube.readonly',
	'https://www.googleapis.com/auth/devstorage.read_only',
);
const MC_STATE_TTL    = 900;
const MC_HANDOVER_TTL = 300;

/**
 * Settings live outside the web root: client_id, client_secret, redirect_uri, data_dir.
 */
function mc_config() {
	static $cfg = null;
	if ( null === $cfg ) {
		$path = getenv( 'MARSAD_CONNECT_CONFIG' ) ?: dirname( __DIR__, 4 ) . '/marsad-connect/config.php';
		$cfg  = is_readable( $path ) ? require $path : array();
	}
	return $cfg;
}

function mc_b64url( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

function mc_random() {
	return mc_b64url( random_bytes( 24 ) );
}

/**
 * Only send people back to a WordPress admin-post endpoint over HTTPS (or a local test site).
 */
function mc_valid_return( $url ) {
	$p = parse_url( (string) $url );
	if ( ! $p || empty( $p['host'] ) || empty( $p['path'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
		return false;
	}
	$local = in_array( $p['host'], array( 'localhost', '127.0.0.1' ), true ) || preg_match( '/\.(test|localhost)$/', $p['host'] );
	if ( 'https' !== ( $p['scheme'] ?? '' ) && ! ( $local && 'http' === ( $p['scheme'] ?? '' ) ) ) {
		return false;
	}
	return (bool) preg_match( '#/wp-admin/admin-post\.php$#', $p['path'] );
}

function mc_valid_key( $k ) {
	return is_string( $k ) && preg_match( '/^[A-Za-z0-9_-]{20,64}$/', $k );
}

/* ── parked entries: one JSON file each, deleted on read or expiry ── */

function mc_put( $dir, $key, array $data, $ttl ) {
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	$data['_exp'] = time() + $ttl;
	file_put_contents( $dir . '/' . $key . '.json', json_encode( $data ), LOCK_EX );
	chmod( $dir . '/' . $key . '.json', 0600 );
}

function mc_take( $dir, $key ) {
	if ( ! mc_valid_key( $key ) ) {
		return null;
	}
	$file = $dir . '/' . $key . '.json';
	if ( ! is_file( $file ) ) {
		return null;
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	unlink( $file );
	if ( ! is_array( $data ) || ( $data['_exp'] ?? 0 ) < time() ) {
		return null;
	}
	unset( $data['_exp'] );
	return $data;
}

function mc_sweep( $dir ) {
	foreach ( array_merge( (array) glob( $dir . '/*.json' ), (array) glob( $dir . '/*.cnt' ) ) as $f ) {
		if ( filemtime( $f ) < time() - 3600 ) {
			@unlink( $f );
		}
	}
}

/* ── the four steps; each returns array( status, headers, body ) so tests can drive them ── */

function mc_start( array $q, array $cfg ) {
	if ( empty( $cfg['client_id'] ) ) {
		return mc_page( 503, 'Marsad Connect is not configured yet.' );
	}
	$return    = (string) ( $q['return'] ?? '' );
	$challenge = (string) ( $q['challenge'] ?? '' );
	if ( ! mc_valid_return( $return ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
		return mc_page( 400, 'This connection link is incomplete. Go back to Marsad and press the button again.' );
	}
	$state = mc_random();
	mc_put( $cfg['data_dir'], 's_' . $state, array( 'return' => $return, 'challenge' => $challenge ), MC_STATE_TTL );
	mc_sweep( $cfg['data_dir'] );
	$url = MC_GOOGLE_AUTH . '?' . http_build_query(
		array(
			'client_id'              => $cfg['client_id'],
			'redirect_uri'           => $cfg['redirect_uri'],
			'response_type'          => 'code',
			'scope'                  => implode( ' ', MC_SCOPES ),
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
		)
	);
	return array( 302, array( 'Location' => $url ), '' );
}

/**
 * @param callable $post function( $url, array $fields ): array( code, body )
 */
function mc_callback( array $q, array $cfg, callable $post ) {
	$state = (string) ( $q['state'] ?? '' );
	$entry = mc_take( $cfg['data_dir'], 's_' . $state );
	if ( ! $entry ) {
		return mc_page( 400, 'This sign-in expired. Go back to Marsad and press the button again.' );
	}
	$back = function ( array $args ) use ( $entry ) {
		$sep = false === strpos( $entry['return'], '?' ) ? '?' : '&';
		return array( 302, array( 'Location' => $entry['return'] . $sep . http_build_query( $args ) ), '' );
	};
	if ( ! empty( $q['error'] ) || empty( $q['code'] ) ) {
		return $back( array( 'action' => 'marsad_google_return', 'marsad_error' => substr( (string) ( $q['error'] ?? 'no_code' ), 0, 40 ) ) );
	}
	list( $code, $body ) = $post(
		MC_GOOGLE_TOKEN,
		array(
			'code'          => (string) $q['code'],
			'client_id'     => $cfg['client_id'],
			'client_secret' => $cfg['client_secret'],
			'redirect_uri'  => $cfg['redirect_uri'],
			'grant_type'    => 'authorization_code',
		)
	);
	$tok = json_decode( (string) $body, true );
	if ( 200 !== $code || empty( $tok['refresh_token'] ) ) {
		return $back( array( 'action' => 'marsad_google_return', 'marsad_error' => 'token_exchange' ) );
	}
	$handover = mc_random();
	mc_put(
		$cfg['data_dir'],
		'h_' . $handover,
		array(
			'challenge'     => $entry['challenge'],
			'refresh_token' => $tok['refresh_token'],
			'access_token'  => $tok['access_token'] ?? '',
			'expires_in'    => (int) ( $tok['expires_in'] ?? 0 ),
			'scope'         => $tok['scope'] ?? '',
			'email'         => mc_id_token_email( $tok['id_token'] ?? '' ),
		),
		MC_HANDOVER_TTL
	);
	return $back( array( 'action' => 'marsad_google_return', 'marsad_code' => $handover ) );
}

function mc_claim( array $p, array $cfg ) {
	$entry = mc_take( $cfg['data_dir'], 'h_' . (string) ( $p['code'] ?? '' ) );
	if ( ! $entry ) {
		return mc_json( 400, array( 'error' => 'expired', 'error_description' => 'The handover code expired or was already used.' ) );
	}
	$verifier = (string) ( $p['verifier'] ?? '' );
	if ( ! hash_equals( $entry['challenge'], mc_b64url( hash( 'sha256', $verifier, true ) ) ) ) {
		return mc_json( 403, array( 'error' => 'mismatch', 'error_description' => 'This site did not start the sign-in.' ) );
	}
	unset( $entry['challenge'] );
	return mc_json( 200, $entry );
}

/**
 * @param callable $post function( $url, array $fields ): array( code, body )
 */
function mc_token( array $p, array $cfg, callable $post ) {
	$refresh = (string) ( $p['refresh_token'] ?? '' );
	if ( '' === $refresh || strlen( $refresh ) > 512 ) {
		return mc_json( 400, array( 'error' => 'invalid_request' ) );
	}
	list( $code, $body ) = $post(
		MC_GOOGLE_TOKEN,
		array(
			'refresh_token' => $refresh,
			'client_id'     => $cfg['client_id'],
			'client_secret' => $cfg['client_secret'],
			'grant_type'    => 'refresh_token',
		)
	);
	$tok = json_decode( (string) $body, true );
	if ( 200 !== $code || empty( $tok['access_token'] ) ) {
		return mc_json( $code >= 400 && $code < 500 ? $code : 502, array( 'error' => $tok['error'] ?? 'google_error', 'error_description' => $tok['error_description'] ?? '' ) );
	}
	return mc_json( 200, array( 'access_token' => $tok['access_token'], 'expires_in' => (int) ( $tok['expires_in'] ?? 3600 ), 'scope' => $tok['scope'] ?? '' ) );
}

/**
 * Public YouTube numbers with Marsad's own API key, so a site can follow any channel
 * by pasting its link. Read-only endpoints and parameters only; answers are cached for
 * 30 minutes and each address is limited, to keep the shared daily quota fair.
 *
 * @param callable $get function( $url ): array( code, body )
 */
function mc_youtube( array $q, array $cfg, callable $get, $ip ) {
	if ( empty( $cfg['youtube_key'] ) ) {
		return mc_json( 503, array( 'error' => array( 'message' => 'Marsad Connect has no YouTube key yet. Add your own API key in Marsad, or try later.' ) ) );
	}
	$allowed = array(
		'channels'      => array( 'part', 'id', 'forHandle' ),
		'playlistItems' => array( 'part', 'playlistId', 'maxResults' ),
		'videos'        => array( 'part', 'id' ),
	);
	$endpoint = (string) ( $q['endpoint'] ?? '' );
	if ( ! isset( $allowed[ $endpoint ] ) ) {
		return mc_json( 400, array( 'error' => array( 'message' => 'Unsupported request.' ) ) );
	}
	$args = array_intersect_key( $q, array_flip( $allowed[ $endpoint ] ) );
	$args = array_map( function ( $v ) { return substr( (string) $v, 0, 600 ); }, $args );
	if ( empty( $args['id'] ) && empty( $args['forHandle'] ) && empty( $args['playlistId'] ) ) {
		return mc_json( 400, array( 'error' => array( 'message' => 'Say which channel: a link, @handle or id.' ) ) );
	}
	ksort( $args );
	$dir   = $cfg['data_dir'];
	$cache = $dir . '/yt_' . md5( $endpoint . '?' . http_build_query( $args ) ) . '.json';
	if ( is_file( $cache ) && filemtime( $cache ) > time() - 1800 ) {
		return mc_json( 200, (array) json_decode( (string) file_get_contents( $cache ), true ) );
	}
	if ( ! mc_allow( $dir, 'ytip_' . md5( (string) $ip ), 120, 3600 ) ) {
		return mc_json( 429, array( 'error' => array( 'message' => 'Too many YouTube requests from this server; try again within the hour.' ) ) );
	}
	list( $code, $body ) = $get( 'https://www.googleapis.com/youtube/v3/' . $endpoint . '?' . http_build_query( $args + array( 'key' => $cfg['youtube_key'] ) ) );
	$data = json_decode( (string) $body, true );
	if ( 200 !== $code || ! is_array( $data ) ) {
		return mc_json( $code >= 400 ? $code : 502, is_array( $data ) ? $data : array( 'error' => array( 'message' => 'YouTube did not answer.' ) ) );
	}
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	file_put_contents( $cache, json_encode( $data ), LOCK_EX );
	return mc_json( 200, $data );
}

/** A small per-key counter in a file: true while under $max hits in the current window. */
function mc_allow( $dir, $key, $max, $window ) {
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	$file = $dir . '/' . $key . '.cnt';
	$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $data ) || $data['start'] < time() - $window ) {
		$data = array( 'start' => time(), 'n' => 0 );
	}
	$data['n']++;
	file_put_contents( $file, json_encode( $data ), LOCK_EX );
	return $data['n'] <= $max;
}

function mc_curl_get( $url ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20 ) );
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $code, false === $body ? '' : $body );
}

/** The e-mail claim of an id_token straight from Google's token endpoint (TLS, no need to verify). */
function mc_id_token_email( $jwt ) {
	$parts = explode( '.', (string) $jwt );
	if ( 3 !== count( $parts ) ) {
		return '';
	}
	$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
	return is_array( $claims ) ? (string) ( $claims['email'] ?? '' ) : '';
}

/* ── output ── */

function mc_json( $status, array $data ) {
	return array( $status, array( 'Content-Type' => 'application/json', 'Cache-Control' => 'no-store' ), json_encode( $data ) );
}

function mc_page( $status, $message ) {
	$m = htmlspecialchars( $message, ENT_QUOTES );
	return array( $status, array( 'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store' ), '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Marsad Connect</title><body style="font:16px system-ui,sans-serif;max-width:32em;margin:15vh auto;padding:0 20px;color:#111827"><h1 style="font-size:20px">Marsad Connect</h1><p>' . $m . '</p></body>' );
}

function mc_send( array $res ) {
	list( $status, $headers, $body ) = $res;
	http_response_code( $status );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: no-referrer' );
	foreach ( $headers as $k => $v ) {
		header( $k . ': ' . $v );
	}
	echo $body; // phpcs:ignore
}

function mc_curl_post( $url, array $fields ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( $fields ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
		)
	);
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $code, false === $body ? '' : $body );
}
