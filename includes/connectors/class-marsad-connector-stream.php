<?php
/**
 * Live stream viewers, pushed every few minutes by the collector that runs on the
 * streaming server (server/stream/marsad_stream.py). It reads the HLS server's access log
 * and sends only counts: viewers, minutes watched, peak concurrency, by platform.
 *
 * The collector POSTs to /wp-json/marsad/v1/push/stream with the push key as a Bearer token.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Stream extends Marsad_Connector {

	/** Unix time of the last accepted push. */
	const OPT_LAST = 'marsad_stream_last';
	/** The latest "now" block: viewers in the last two minutes. */
	const OPT_NOW = 'marsad_stream_now';
	/** Minutes without a push before the source counts as broken (the collector runs every 5). */
	const SILENT = 20;

	public function id() {
		return 'stream';
	}

	public function label() {
		return __( 'Live stream', 'marsad' );
	}

	public function group() {
		return 'live';
	}

	public function description() {
		return __( 'Who watches your live stream and on what: website, iPhone and Android apps, smart TVs, IPTV players and other sites, with minutes watched and peak viewers.', 'marsad' );
	}

	public function fields() {
		return array(
			'token' => array(
				'label'    => __( 'Push key', 'marsad' ),
				'type'     => 'text',
				'required' => true,
				'generate' => true,
				'help'     => __( 'A long random key, filled in for you. The same key goes into the collector on the streaming server.', 'marsad' ),
			),
		);
	}

	public function instructions() {
		return array(
			__( 'Save this card once so the push key is kept.', 'marsad' ),
			$this->step(
				/* translators: %s: address of the push endpoint */
				sprintf( __( 'On the streaming server, install marsad_stream.py from the plugin\'s GitHub repository (folder server/stream) and set MARSAD_URL to %s and MARSAD_TOKEN to the push key.', 'marsad' ), rest_url( 'marsad/v1/push/stream' ) ),
				'https://github.com/alahdal262/marsad/tree/main/server/stream',
				__( 'Collector and instructions', 'marsad' )
			),
			__( 'It runs every 5 minutes and sends counts only; viewer addresses never leave the server.', 'marsad' ),
		);
	}

	public function metrics() {
		return array(
			'viewers'     => array( 'label' => __( 'Viewers a day (avg)', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'chart' => true ),
			'watch_hours' => array( 'label' => __( 'Hours watched', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true, 'format' => 'decimal' ),
			'peak'        => array( 'label' => __( 'Peak viewers at once', 'marsad' ), 'agg' => 'max', 'kpi' => true ),
		);
	}

	public function tables() {
		return array(
			'viewers_by_platform' => array( 'label' => __( 'Viewers by platform (avg a day)', 'marsad' ), 'agg' => 'avg', 'limit' => 20 ),
			'viewers_by_site'     => array( 'label' => __( 'Other sites playing your stream', 'marsad' ), 'agg' => 'avg', 'limit' => 30 ),
			'restreamers'         => array( 'label' => __( 'Re-broadcasting tools and other apps', 'marsad' ), 'agg' => 'avg', 'limit' => 30 ),
		);
	}

	/** Readable names of the collector's platform keys. */
	public static function platforms() {
		return array(
			'website'     => __( 'Website', 'marsad' ),
			'live_page'   => __( 'Live player page', 'marsad' ),
			'app_ios'     => __( 'iPhone app', 'marsad' ),
			'app_android' => __( 'Android app', 'marsad' ),
			'app_unknown' => __( 'App (system not reported)', 'marsad' ),
			'tv_samsung'  => __( 'Samsung TV', 'marsad' ),
			'tv_lg'       => __( 'LG TV', 'marsad' ),
			'tv_fire'     => __( 'Fire TV', 'marsad' ),
			'tv_roku'     => __( 'Roku', 'marsad' ),
			'tv_apple'    => __( 'Apple TV', 'marsad' ),
			'tv_android'  => __( 'Android TV and smart TVs', 'marsad' ),
			'iptv'        => __( 'IPTV players', 'marsad' ),
			'other_sites' => __( 'Other sites', 'marsad' ),
			'tools'       => __( 'Re-broadcasting tools', 'marsad' ),
			'direct'      => __( 'Direct link', 'marsad' ),
		);
	}

	/**
	 * Data arrives by push, so a sync only checks that the collector is still sending.
	 *
	 * @throws Marsad_Exception
	 */
	public function fetch( $from, $to ) {
		$last = (int) get_option( self::OPT_LAST, 0 );
		if ( ! $last ) {
			throw new Marsad_Exception( __( 'No data received from the streaming server yet. Install the collector (steps on this card).', 'marsad' ) );
		}
		if ( $last < time() - self::SILENT * MINUTE_IN_SECONDS ) {
			/* translators: %s: e.g. "2 hours ago" */
			throw new Marsad_Exception( sprintf( __( 'The streaming server stopped sending %s. Check that its collector timer is running.', 'marsad' ), Marsad_Admin::ago( $last ) ) );
		}
		return array();
	}

	/** The latest "watching now" block, or null. */
	public static function now() {
		$now = get_option( self::OPT_NOW, null );
		return is_array( $now ) ? $now : null;
	}

	/**
	 * One push from the collector. Returns array( code, message ) for the REST answer.
	 *
	 * @param string $authorization the Authorization header
	 * @param mixed  $data          the decoded JSON body
	 */
	public static function receive( $authorization, $data ) {
		$c     = Marsad_Registry::get( 'stream' );
		$token = $c ? trim( (string) $c->get( 'token' ) ) : '';
		$given = trim( (string) preg_replace( '/^Bearer\s+/i', '', (string) $authorization ) );
		if ( ! $c || ! Marsad_Registry::is_enabled( $c ) || strlen( $token ) < 20 || ! hash_equals( $token, $given ) ) {
			return array( 'code' => 401, 'message' => 'Unknown push key.' );
		}
		if ( ! is_array( $data ) || ! is_array( $data['days'] ?? null ) ) {
			return array( 'code' => 400, 'message' => 'Expected {"days": {...}}.' );
		}
		$names = self::platforms();
		$rows  = array();
		$count = function ( $v ) {
			return is_numeric( $v ) && $v >= 0 && $v < 1e9 ? (float) $v : null;
		};
		foreach ( $data['days'] as $day => $d ) {
			// Only today and the last week: a push cannot rewrite old history.
			if ( ! is_string( $day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $day > gmdate( 'Y-m-d' ) || $day < gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) || ! is_array( $d ) ) {
				continue;
			}
			foreach ( array( 'viewers' => 1, 'peak' => 1, 'watch_minutes' => 60 ) as $key => $div ) {
				$v = $count( $d[ $key ] ?? null );
				if ( null !== $v ) {
					$rows[] = array( 'metric' => 'watch_minutes' === $key ? 'watch_hours' : $key, 'dim' => '', 'day' => $day, 'value' => round( $v / $div, 2 ) );
				}
			}
			foreach ( array( 'platforms' => 'viewers_by_platform', 'sites' => 'viewers_by_site', 'tools' => 'restreamers' ) as $key => $metric ) {
				foreach ( (array) ( $d[ $key ] ?? array() ) as $name => $v ) {
					$v = $count( $v );
					if ( null === $v || '' === (string) $name ) {
						continue;
					}
					$dim    = 'platforms' === $key ? ( $names[ $name ] ?? sanitize_text_field( (string) $name ) ) : sanitize_text_field( (string) $name );
					$rows[] = array( 'metric' => $metric, 'dim' => mb_substr( $dim, 0, 120 ), 'day' => $day, 'value' => $v );
				}
			}
		}
		if ( $rows ) {
			Marsad_Store::put( 'stream', $rows );
		}
		if ( is_array( $data['now'] ?? null ) ) {
			$platforms = array();
			foreach ( (array) ( $data['now']['platforms'] ?? array() ) as $name => $v ) {
				if ( null !== $count( $v ) ) {
					$platforms[ $names[ $name ] ?? sanitize_text_field( (string) $name ) ] = (int) $v;
				}
			}
			update_option( self::OPT_NOW, array( 'viewers' => (int) $count( $data['now']['viewers'] ?? 0 ), 'platforms' => $platforms, 'at' => time() ), false );
		}
		update_option( self::OPT_LAST, time(), false );
		return array( 'code' => 200, 'message' => count( $rows ) . ' values stored.' );
	}

	/** REST route registration (rest_api_init). */
	public static function register_route() {
		register_rest_route(
			'marsad/v1',
			'/push/stream',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // The push key is checked in receive().
				'callback'            => function ( $request ) {
					$r = self::receive( (string) $request->get_header( 'authorization' ), $request->get_json_params() );
					return new WP_REST_Response( array( 'message' => $r['message'] ), $r['code'] );
				},
			)
		);
	}
}
