<?php
/**
 * Threads profile numbers through the Threads API. A long-lived token lasts 60 days; Marsad
 * renews it every week, so it is pasted once.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Threads extends Marsad_Connector {

	const API = 'https://graph.threads.net/v1.0/';
	/** When the token was last renewed. */
	const OPT_RENEWED = 'marsad_threads_renewed';

	public function id() {
		return 'threads';
	}

	public function label() {
		return __( 'Threads', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function description() {
		return __( 'Followers, and the views, likes, replies and reposts of your Threads posts each day.', 'marsad' );
	}

	public function window_days() {
		return 3;
	}

	public function max_backfill() {
		return 30;
	}

	public function fields() {
		return array(
			'token' => array( 'label' => __( 'Access token', 'marsad' ), 'type' => 'secret', 'required' => true, 'help' => __( 'A long-lived Threads token. Marsad renews it every week, so you paste it once.', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			$this->step( __( 'Create a Meta app with the "Access the Threads API" use case, with the threads_basic and threads_manage_insights permissions.', 'marsad' ), 'https://developers.facebook.com/apps/', __( 'Meta apps', 'marsad' ) ),
			__( 'In the app, open Use cases → Threads API → Settings, add your Threads account as a tester, then generate a long-lived access token and paste it here.', 'marsad' ),
		);
	}

	public function metrics() {
		return array(
			'followers' => array( 'label' => __( 'Followers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'views'     => array( 'label' => __( 'Views', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'likes'     => array( 'label' => __( 'Likes', 'marsad' ), 'agg' => 'sum', 'kpi' => true ),
			'replies'   => array( 'label' => __( 'Replies', 'marsad' ), 'agg' => 'sum', 'kpi' => true ),
			'reposts'   => array( 'label' => __( 'Reposts', 'marsad' ), 'agg' => 'sum' ),
		);
	}

	/** A fresh 60-day token once a week; the old one keeps working until then. */
	private function token() {
		$token = (string) $this->get( 'token' );
		if ( (int) get_option( self::OPT_RENEWED, 0 ) < time() - 7 * DAY_IN_SECONDS ) {
			try {
				$r = Marsad_Http::json( 'GET', 'https://graph.threads.net/refresh_access_token?' . http_build_query( array( 'grant_type' => 'th_refresh_token', 'access_token' => $token ) ) );
				if ( ! empty( $r['access_token'] ) ) {
					$token = (string) $r['access_token'];
					Marsad_Registry::remember( $this, 'token', $token );
					update_option( self::OPT_RENEWED, time(), false );
				}
			} catch ( Marsad_Exception $e ) {
				unset( $e ); // Not renewable yet (a token must be a day old): the current one still works.
			}
		}
		return $token;
	}

	public function fetch( $from, $to ) {
		$token = $this->token();
		$me    = Marsad_Http::json( 'GET', self::API . 'me?' . http_build_query( array( 'fields' => 'id,username', 'access_token' => $token ) ) );
		$id    = rawurlencode( (string) ( $me['id'] ?? '' ) );
		$rows  = array();
		$f     = Marsad_Http::json( 'GET', self::API . $id . '/threads_insights?' . http_build_query( array( 'metric' => 'followers_count', 'access_token' => $token ) ) );
		foreach ( $f['data'] ?? array() as $d ) {
			if ( 'followers_count' === ( $d['name'] ?? '' ) ) {
				$rows[] = $this->row( 'followers', $this->today(), $d['total_value']['value'] ?? 0 );
			}
		}
		foreach ( Marsad_Aggregate::days( $from, $to ) as $day ) {
			$since = strtotime( $day . ' 00:00:00 UTC' );
			$i     = Marsad_Http::json( 'GET', self::API . $id . '/threads_insights?' . http_build_query( array( 'metric' => 'views,likes,replies,reposts', 'since' => $since, 'until' => $since + DAY_IN_SECONDS - 1, 'access_token' => $token ) ) );
			foreach ( $i['data'] ?? array() as $d ) {
				$name = (string) ( $d['name'] ?? '' );
				if ( isset( $d['values'] ) ) {
					$v = array_sum( array_map( function ( $x ) { return (float) ( $x['value'] ?? 0 ); }, (array) $d['values'] ) );
				} else {
					$v = (float) ( $d['total_value']['value'] ?? 0 );
				}
				if ( in_array( $name, array( 'views', 'likes', 'replies', 'reposts' ), true ) ) {
					$rows[] = $this->row( $name, $day, $v );
				}
			}
		}
		return $rows;
	}
}
