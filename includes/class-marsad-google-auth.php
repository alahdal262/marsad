<?php
/**
 * OAuth access tokens for a Google service account (JWT bearer grant), cached for 50 minutes.
 * One service account JSON can serve GA4, Search Console, YouTube Analytics and Play reports.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Google_Auth {

	/**
	 * @return array decoded key file
	 * @throws Marsad_Exception
	 */
	public static function parse_key( $json ) {
		$sa = json_decode( (string) $json, true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			throw new Marsad_Exception( __( 'This is not a service account key file. In Google Cloud open IAM → Service accounts → Keys → Add key → JSON, and paste the downloaded file.', 'marsad' ) );
		}
		return $sa;
	}

	public static function client_email( $json ) {
		$sa = json_decode( (string) $json, true );
		return is_array( $sa ) && ! empty( $sa['client_email'] ) ? $sa['client_email'] : '';
	}

	/**
	 * @throws Marsad_Exception
	 */
	public static function token( $json, $scope ) {
		$sa        = self::parse_key( $json );
		$cache_key = 'marsad_gtok_' . md5( $sa['client_email'] . '|' . $scope );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}
		$token_uri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
		$now       = time();
		$jwt       = Marsad_Jwt::sign(
			array(
				'alg' => 'RS256',
				'typ' => 'JWT',
			),
			array(
				'iss'   => $sa['client_email'],
				'scope' => $scope,
				'aud'   => $token_uri,
				'iat'   => $now,
				'exp'   => $now + 3600,
			),
			$sa['private_key']
		);
		$res  = Marsad_Http::request(
			'POST',
			$token_uri,
			array(
				'body' => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		$data = json_decode( $res['body'], true );
		if ( 200 !== $res['code'] || empty( $data['access_token'] ) ) {
			throw new Marsad_Exception( Marsad_Http::error_message( $res['code'], $data, $res['body'] ) );
		}
		set_transient( $cache_key, $data['access_token'], 50 * MINUTE_IN_SECONDS );
		return $data['access_token'];
	}

	/**
	 * Google APIs answer "API has not been used in project N" when the API is switched off;
	 * turn that into the exact step the admin has to take.
	 */
	public static function explain( Marsad_Exception $e, $api_label, $api_id ) {
		$m = $e->getMessage();
		if ( false !== stripos( $m, 'has not been used in project' ) || false !== stripos( $m, 'is disabled' ) ) {
			return sprintf(
				/* translators: 1: API name, 2: API id in Google Cloud */
				__( '%1$s is switched off for this service account\'s Google Cloud project. Open console.cloud.google.com → APIs & Services → Library, search for "%2$s" and press Enable, then try again in a few minutes.', 'marsad' ),
				$api_label,
				$api_id
			);
		}
		return $m;
	}
}
