<?php
/**
 * "Connect with Google": one sign-in shared by GA4, Search Console, YouTube and Google Play.
 *
 * The sign-in goes through Marsad Connect (server/connect in this repository), which holds
 * the Google OAuth client. The site keeps only its refresh token, encrypted, and asks the relay
 * for a short-lived access token when it needs one. See server/connect/lib.php for the flow.
 *
 * Saved shape (option marsad_google): array( 'refresh' => encrypted, 'email', 'scope', 'at' )
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Google_Account {

	const OPT      = 'marsad_google';
	const TOKEN    = 'marsad_gacct_token';
	const VERIFIER = 'marsad_gverifier_';
	const RELAY    = 'https://form.yementv.tv/connect/';

	public static function relay() {
		$url = defined( 'MARSAD_CONNECT_URL' ) ? MARSAD_CONNECT_URL : self::RELAY;
		return trailingslashit( apply_filters( 'marsad_connect_url', $url ) );
	}

	public static function connected() {
		$a = get_option( self::OPT, array() );
		return ! empty( $a['refresh'] );
	}

	public static function email() {
		$a = get_option( self::OPT, array() );
		return (string) ( $a['email'] ?? '' );
	}

	/**
	 * The address that starts the sign-in. Remembers a one-time verifier for this user.
	 *
	 * @param string $return admin-post.php URL the relay sends the user back to
	 */
	public static function connect_url( $return ) {
		$verifier = Marsad_Jwt::b64url( random_bytes( 32 ) );
		set_transient( self::VERIFIER . get_current_user_id(), $verifier, 20 * MINUTE_IN_SECONDS );
		return self::relay() . 'start.php?' . http_build_query(
			array(
				'return'    => $return,
				'challenge' => Marsad_Jwt::b64url( hash( 'sha256', $verifier, true ) ),
			)
		);
	}

	/**
	 * Swap the handover code from the relay for the tokens and keep the refresh token.
	 *
	 * @throws Marsad_Exception
	 */
	public static function finish( $code ) {
		$key      = self::VERIFIER . get_current_user_id();
		$verifier = get_transient( $key );
		delete_transient( $key );
		if ( ! $verifier ) {
			throw new Marsad_Exception( __( 'The sign-in took too long. Press "Connect with Google" again.', 'marsad' ) );
		}
		$res  = Marsad_Http::request( 'POST', self::relay() . 'claim.php', array( 'body' => array( 'code' => $code, 'verifier' => $verifier ) ) );
		$data = json_decode( $res['body'], true );
		if ( 200 !== $res['code'] || empty( $data['refresh_token'] ) ) {
			throw new Marsad_Exception( Marsad_Http::error_message( $res['code'], $data, $res['body'] ) );
		}
		update_option(
			self::OPT,
			array(
				'refresh' => Marsad_Crypto::encrypt( $data['refresh_token'] ),
				'email'   => sanitize_text_field( $data['email'] ?? '' ),
				'scope'   => sanitize_text_field( $data['scope'] ?? '' ),
				'at'      => time(),
			),
			false
		);
		if ( ! empty( $data['access_token'] ) ) {
			set_transient( self::TOKEN, $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 ) );
		}
		Marsad_Registry::reset_cache();
	}

	/**
	 * A fresh access token, cached until five minutes before it expires.
	 *
	 * @throws Marsad_Exception
	 */
	public static function token() {
		$cached = get_transient( self::TOKEN );
		if ( $cached ) {
			return $cached;
		}
		$a       = get_option( self::OPT, array() );
		$refresh = Marsad_Crypto::decrypt( $a['refresh'] ?? '' );
		if ( '' === $refresh ) {
			throw new Marsad_Exception( __( 'Google is not connected. Press "Connect with Google" on the Connections page.', 'marsad' ) );
		}
		$res  = Marsad_Http::request( 'POST', self::relay() . 'token.php', array( 'body' => array( 'refresh_token' => $refresh ) ) );
		$data = json_decode( $res['body'], true );
		if ( 200 !== $res['code'] || empty( $data['access_token'] ) ) {
			if ( 'invalid_grant' === ( $data['error'] ?? '' ) ) {
				throw new Marsad_Exception( __( 'Google access was removed or expired. Press "Connect with Google" again.', 'marsad' ) );
			}
			throw new Marsad_Exception( Marsad_Http::error_message( $res['code'], $data, $res['body'] ) );
		}
		set_transient( self::TOKEN, $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 ) );
		return $data['access_token'];
	}

	/** Revoke at Google (no secret needed) and forget everything. */
	public static function disconnect() {
		$a       = get_option( self::OPT, array() );
		$refresh = Marsad_Crypto::decrypt( $a['refresh'] ?? '' );
		if ( '' !== $refresh ) {
			try {
				Marsad_Http::request( 'POST', 'https://oauth2.googleapis.com/revoke', array( 'body' => array( 'token' => $refresh ) ) );
			} catch ( Marsad_Exception $e ) {
				unset( $e ); // Forgetting locally is what matters; Google drops unused tokens anyway.
			}
		}
		delete_option( self::OPT );
		delete_transient( self::TOKEN );
		Marsad_Registry::reset_cache();
	}

	/**
	 * Bearer header for a Google API: the connector's own service account when it has one
	 * (the advanced option), otherwise the connected Google account.
	 *
	 * @throws Marsad_Exception
	 */
	public static function bearer( $service_account_json, $scope ) {
		if ( '' !== trim( (string) $service_account_json ) ) {
			return array( 'Authorization' => 'Bearer ' . Marsad_Google_Auth::token( $service_account_json, $scope ) );
		}
		return array( 'Authorization' => 'Bearer ' . self::token() );
	}

	/**
	 * GET a Google API as the connected account and cache the answer briefly; used to fill
	 * the pick-lists, so a slow API never blocks the Connections page twice.
	 *
	 * @return array|null decoded JSON, or null when it cannot be read right now
	 */
	public static function list_cached( $url, $ttl = 600 ) {
		if ( ! self::connected() ) {
			return null;
		}
		$key = 'marsad_glist_' . md5( $url . '|' . self::email() );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}
		try {
			$data = Marsad_Http::json( 'GET', $url, null, array( 'Authorization' => 'Bearer ' . self::token() ) );
		} catch ( Marsad_Exception $e ) {
			return null;
		}
		set_transient( $key, $data, $ttl );
		return $data;
	}
}
