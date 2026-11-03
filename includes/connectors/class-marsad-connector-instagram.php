<?php
/**
 * Instagram professional account (linked to a Facebook Page) through the Graph API.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Instagram extends Marsad_Connector {

	public function id() {
		return 'instagram';
	}

	public function label() {
		return __( 'Instagram', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Followers and number of posts of an Instagram business or creator account.', 'marsad' );
	}

	public function fields() {
		return array(
			'ig_user_id' => array( 'label' => __( 'Instagram account', 'marsad' ), 'type' => 'text', 'help' => __( 'Found automatically from the Facebook Page; pick another one if you have several.', 'marsad' ) ),
			'token'      => array( 'label' => __( 'Access token', 'marsad' ), 'type' => 'secret', 'advanced' => true, 'help' => __( 'Leave empty to use the Facebook connection\'s token.', 'marsad' ) ),
		);
	}

	public function metrics() {
		return array(
			'followers' => array( 'label' => __( 'Followers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'posts'     => array( 'label' => __( 'Posts', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
		);
	}

	public function instructions() {
		return array(
			__( 'Link the Instagram professional account to your Facebook Page (Instagram → Settings → Account Center).', 'marsad' ),
			__( 'Connect Facebook first with the instagram_basic permission; the account then appears here by itself.', 'marsad' ),
		);
	}

	/** Linked Instagram accounts of the Pages the token sees: array( ig id => array( label, token ) ). */
	private function accounts() {
		$own   = trim( (string) $this->get( 'token' ) );
		$fb    = Marsad_Registry::get( 'facebook' );
		$token = '' !== $own ? $own : ( $fb ? (string) $fb->get( 'token' ) : '' );
		$out   = array();
		foreach ( Marsad_Connector_Facebook::pages( $token ) as $p ) {
			if ( $p['instagram'] ) {
				$out[ $p['instagram']['id'] ] = array( '@' . $p['instagram']['username'] . ' · ' . $p['name'], $p['token'] );
			}
		}
		return array( $out, $token );
	}

	// Runs on every page view, so it must not call Facebook: a token is enough, fetch() finds the account.
	public function missing() {
		$fb = Marsad_Registry::get( 'facebook' );
		$ok = '' !== trim( (string) $this->get( 'token' ) ) || ( $fb && '' !== trim( (string) $fb->get( 'token' ) ) );
		return $ok ? array() : array( __( 'Connect Facebook first (or an access token)', 'marsad' ) );
	}

	public function choices( $key ) {
		if ( 'ig_user_id' !== $key ) {
			return null;
		}
		list( $accounts ) = $this->accounts();
		return $accounts ? array_map( function ( $a ) { return $a[0]; }, $accounts ) : null;
	}

	public function fetch( $from, $to ) {
		list( $accounts, $token ) = $this->accounts();
		$id = trim( (string) $this->get( 'ig_user_id' ) );
		if ( '' === $id && 1 === count( $accounts ) ) {
			$id = (string) key( $accounts );
		}
		if ( '' === $id ) {
			throw new Marsad_Exception( __( 'Pick the Instagram account in the list, then save.', 'marsad' ) );
		}
		$token = $accounts[ $id ][1] ?? $token;
		$u     = Marsad_Http::json( 'GET', Marsad_Connector_Facebook::GRAPH . rawurlencode( $id ) . '?' . http_build_query( array( 'fields' => 'followers_count,media_count', 'access_token' => $token ) ) );
		$day = $this->today();
		return array(
			$this->row( 'followers', $day, $u['followers_count'] ?? 0 ),
			$this->row( 'posts', $day, $u['media_count'] ?? 0 ),
		);
	}
}
