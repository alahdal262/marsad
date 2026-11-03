<?php
/**
 * Knows every connector and its saved settings.
 *
 * Saved shape (option marsad_connections, not autoloaded):
 *   array( id => array( 'enabled' => bool, 'values' => array( field => stored ) ) )
 * Fields of type "secret" or "textarea_secret" are stored encrypted.
 *
 * Custom JSON sources exist only once the user adds them (option marsad_custom_sources:
 *   array( 'ids' => array( 'custom_1', … ), 'next' => int ) ). Ids only grow, so data
 *   left behind by a removed source never attaches to a new one.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Registry {

	const OPT        = 'marsad_connections';
	const OPT_CUSTOM = 'marsad_custom_sources';

	/** @var Marsad_Connector[]|null */
	private static $cache = null;

	public static function builtin() {
		return array(
			'Marsad_Connector_Wordpress',
			'Marsad_Connector_Site',
			'Marsad_Connector_Ga4',
			'Marsad_Connector_Search_Console',
			'Marsad_Connector_Youtube',
			'Marsad_Connector_Facebook',
			'Marsad_Connector_Instagram',
			'Marsad_Connector_Telegram',
			'Marsad_Connector_Google_Play',
			'Marsad_Connector_App_Store',
		);
	}

	/**
	 * @return Marsad_Connector[] keyed by id, in display order
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$list = array();
		foreach ( self::builtin() as $class ) {
			$list[] = new $class();
		}
		foreach ( self::custom_ids() as $id ) {
			$list[] = new Marsad_Connector_Json( $id );
		}
		/**
		 * Add your own data sources: return the array with extra Marsad_Connector instances.
		 */
		$list  = apply_filters( 'marsad_connectors', $list );
		$saved = self::saved();
		$out   = array();
		foreach ( $list as $c ) {
			if ( ! $c instanceof Marsad_Connector ) {
				continue;
			}
			$c->set_values( self::decrypt_values( $c, $saved[ $c->id() ]['values'] ?? array() ) );
			$out[ $c->id() ] = $c;
		}
		self::$cache = $out;
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	public static function is_enabled( Marsad_Connector $c ) {
		if ( $c->always_on() ) {
			return true;
		}
		$saved = self::saved();
		return ! empty( $saved[ $c->id() ]['enabled'] ) && $c->is_configured();
	}

	/**
	 * @return Marsad_Connector[]
	 */
	public static function enabled() {
		return array_filter( self::all(), array( __CLASS__, 'is_enabled' ) );
	}

	public static function saved() {
		$s = get_option( self::OPT, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Store submitted values. An empty secret field keeps the existing secret
	 * (the form never echoes secrets back).
	 */
	public static function save( Marsad_Connector $c, $enabled, array $submitted ) {
		$saved = self::saved();
		$prev  = $saved[ $c->id() ]['values'] ?? array();
		$vals  = array();
		foreach ( $c->fields() as $key => $f ) {
			$type = $f['type'] ?? 'text';
			$in   = isset( $submitted[ $key ] ) ? (string) $submitted[ $key ] : '';
			if ( self::is_secret( $type ) ) {
				$vals[ $key ] = ( '' === trim( $in ) ) ? ( $prev[ $key ] ?? '' ) : Marsad_Crypto::encrypt( trim( $in ) );
			} elseif ( 'textarea' === $type ) {
				$vals[ $key ] = sanitize_textarea_field( $in );
			} elseif ( 'url' === $type ) {
				$vals[ $key ] = esc_url_raw( trim( $in ) );
			} else {
				$vals[ $key ] = sanitize_text_field( $in );
			}
		}
		$saved[ $c->id() ] = array(
			'enabled' => (bool) $enabled,
			'values'  => $vals,
		);
		update_option( self::OPT, $saved, false );
		self::$cache = null;
	}

	/**
	 * Ids of the custom sources the user added. Before 0.2.0 there was one built-in
	 * JSON source with id "json"; a site that had set it up keeps it.
	 */
	public static function custom_ids() {
		return self::custom()['ids'];
	}

	public static function is_custom( $id ) {
		return in_array( $id, self::custom_ids(), true );
	}

	/** @return string the new source's id */
	public static function add_custom() {
		$c          = self::custom();
		$id         = 'custom_' . $c['next'];
		$c['ids'][] = $id;
		$c['next']++;
		update_option( self::OPT_CUSTOM, $c, false );
		self::$cache = null;
		return $id;
	}

	public static function remove_custom( $id ) {
		$c        = self::custom();
		$c['ids'] = array_values( array_diff( $c['ids'], array( $id ) ) );
		update_option( self::OPT_CUSTOM, $c, false );
		self::forget( $id );
	}

	private static function custom() {
		$c = get_option( self::OPT_CUSTOM, null );
		if ( ! is_array( $c ) ) {
			$saved = self::saved();
			$c     = array(
				'ids'  => empty( $saved['json']['values'] ) ? array() : array( 'json' ),
				'next' => 1,
			);
			update_option( self::OPT_CUSTOM, $c, false );
		}
		return $c;
	}

	public static function forget( $id ) {
		$saved = self::saved();
		unset( $saved[ $id ] );
		update_option( self::OPT, $saved, false );
		self::$cache = null;
	}

	public static function is_secret( $type ) {
		return in_array( $type, array( 'secret', 'textarea_secret' ), true );
	}

	public static function has_secret( $id, $key ) {
		$saved = self::saved();
		return ! empty( $saved[ $id ]['values'][ $key ] );
	}

	private static function decrypt_values( Marsad_Connector $c, array $stored ) {
		$out = array();
		foreach ( $c->fields() as $key => $f ) {
			$v           = $stored[ $key ] ?? '';
			$out[ $key ] = self::is_secret( $f['type'] ?? 'text' ) ? Marsad_Crypto::decrypt( $v ) : $v;
		}
		return $out;
	}

	public static function reset_cache() {
		self::$cache = null;
	}
}
