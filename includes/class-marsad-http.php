<?php
/**
 * Thin wrapper over the WordPress HTTP API. Tests swap the transport with Marsad_Http::$mock.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Http {

	/** @var callable|null fn( $method, $url, $args ) => array( code, body, headers ) */
	public static $mock = null;

	/**
	 * @return array array( 'code' => int, 'body' => string, 'headers' => array )
	 * @throws Marsad_Exception on transport failure.
	 */
	public static function request( $method, $url, array $args = array() ) {
		if ( self::$mock ) {
			return call_user_func( self::$mock, $method, $url, $args );
		}
		$args = wp_parse_args(
			$args,
			array(
				'method'  => $method,
				'timeout' => 25,
				'headers' => array(),
			)
		);
		$args['method']               = $method;
		$args['headers']['User-Agent'] = 'Marsad/' . MARSAD_VERSION . '; ' . home_url( '/' );
		$res                          = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			throw new Marsad_Exception( $res->get_error_message() );
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'headers' => (array) wp_remote_retrieve_headers( $res ),
		);
	}

	/**
	 * JSON request that throws on non-2xx with the API's own error message.
	 */
	public static function json( $method, $url, $body = null, array $headers = array() ) {
		$args = array( 'headers' => $headers );
		if ( null !== $body ) {
			$args['body']                    = is_string( $body ) ? $body : wp_json_encode( $body );
			$args['headers']['Content-Type'] = $args['headers']['Content-Type'] ?? 'application/json';
		}
		$res  = self::request( $method, $url, $args );
		$data = json_decode( $res['body'], true );
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			throw new Marsad_Exception( self::error_message( $res['code'], $data, $res['body'] ), $res['code'] );
		}
		return is_array( $data ) ? $data : array();
	}

	public static function error_message( $code, $data, $raw ) {
		$msg = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$msg = $data['error']['message'];
			} elseif ( isset( $data['error_description'] ) ) {
				$msg = $data['error_description'];
			} elseif ( isset( $data['description'] ) ) {
				$msg = $data['description'];
			} elseif ( isset( $data['errors'][0]['detail'] ) ) {
				$msg = $data['errors'][0]['detail'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$msg = $data['error'];
			}
		}
		if ( '' === $msg ) {
			$msg = mb_substr( trim( wp_strip_all_tags( (string) $raw ) ), 0, 200 );
		}
		return sprintf( 'HTTP %d: %s', $code, $msg );
	}
}
