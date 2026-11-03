<?php
/**
 * Minimal JWT signing for the two schemes Marsad needs:
 *  RS256 — Google service accounts
 *  ES256 — App Store Connect API keys (.p8)
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Jwt {

	public static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function b64url_decode( $s ) {
		$s = strtr( $s, '-_', '+/' );
		return base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * @throws Marsad_Exception when the key cannot be read or signing fails.
	 */
	public static function sign( array $header, array $claims, $private_key_pem ) {
		$alg   = $header['alg'] ?? 'RS256';
		$input = self::b64url( wp_json_encode( $header ) ) . '.' . self::b64url( wp_json_encode( $claims ) );
		$key   = openssl_pkey_get_private( $private_key_pem );
		if ( ! $key ) {
			throw new Marsad_Exception( __( 'The private key could not be read. Paste the whole key including the BEGIN/END lines.', 'marsad' ) );
		}
		$sig = '';
		if ( ! openssl_sign( $input, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new Marsad_Exception( __( 'Signing with the private key failed.', 'marsad' ) );
		}
		if ( 'ES256' === $alg ) {
			$sig = self::der_to_jose( $sig, 32 );
		}
		return $input . '.' . self::b64url( $sig );
	}

	/**
	 * OpenSSL returns ECDSA signatures as DER SEQUENCE { INTEGER r, INTEGER s };
	 * JWS wants r || s, each left-padded to the curve size.
	 */
	public static function der_to_jose( $der, $part_len ) {
		$pos = 0;
		if ( "\x30" !== $der[ $pos++ ] ) {
			throw new Marsad_Exception( 'Bad ECDSA signature' );
		}
		$len = ord( $der[ $pos++ ] );
		if ( $len & 0x80 ) {
			$pos += $len & 0x7f;
		}
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			if ( "\x02" !== $der[ $pos++ ] ) {
				throw new Marsad_Exception( 'Bad ECDSA signature' );
			}
			$l    = ord( $der[ $pos++ ] );
			$int  = substr( $der, $pos, $l );
			$pos += $l;
			$int  = ltrim( $int, "\x00" );
			$out .= str_pad( $int, $part_len, "\x00", STR_PAD_LEFT );
		}
		return $out;
	}
}
