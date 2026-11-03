<?php
/**
 * Encrypts connector secrets at rest with a key derived from the site's own salts,
 * so a database dump alone does not reveal tokens or private keys.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Crypto {

	const PREFIX = 'mz1:';

	private static function key() {
		$material = ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' ) . ( defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : '' ) . 'marsad';
		return hash( 'sha256', $material, true );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ct ) {
			return $plain;
		}
		return self::PREFIX . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
