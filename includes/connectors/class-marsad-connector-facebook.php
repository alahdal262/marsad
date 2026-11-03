<?php
/**
 * Facebook Page followers through the Graph API.
 *
 * The admin pastes any token that can see the Page (usually a 60-day user token from
 * Facebook's token tools). On the first sync the connector swaps it for the Page's own
 * token, which does not expire, so the admin pastes it once.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Facebook extends Marsad_Connector {

	const GRAPH = 'https://graph.facebook.com/v21.0/';

	public function id() {
		return 'facebook';
	}

	public function label() {
		return __( 'Facebook Page', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Page followers and likes, recorded every day so you can see growth.', 'marsad' );
	}

	public function fields() {
		return array(
			'token'   => array( 'label' => __( 'Access token', 'marsad' ), 'type' => 'secret', 'required' => true, 'help' => __( 'Paste it once: Marsad keeps the Page\'s own token, which does not expire.', 'marsad' ) ),
			'page_id' => array( 'label' => __( 'Page', 'marsad' ), 'type' => 'text', 'help' => __( 'Pick it after saving the token. Empty is fine when the token sees one Page.', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			$this->step( __( 'Open Graph API Explorer (create a free app the first time if asked).', 'marsad' ), 'https://developers.facebook.com/tools/explorer/', __( 'Graph API Explorer', 'marsad' ) ),
			__( 'Under Permissions add pages_show_list, pages_read_engagement and instagram_basic, then press "Generate Access Token" and allow your Page.', 'marsad' ),
			$this->step( __( 'Paste that token in the Access Token Debugger, press "Debug", then "Extend Access Token", and copy the long token it shows.', 'marsad' ), 'https://developers.facebook.com/tools/debug/accesstoken/', __( 'Access Token Debugger', 'marsad' ) ),
			__( 'Paste it here and save, then pick your Page from the list.', 'marsad' ),
		);
	}

	public function choices( $key ) {
		if ( 'page_id' !== $key ) {
			return null;
		}
		$out = array();
		foreach ( self::pages( (string) $this->get( 'token' ) ) as $p ) {
			$out[ $p['id'] ] = $p['name'] . ' (' . $p['id'] . ')';
		}
		return $out ? $out : null;
	}

	/**
	 * Pages the token can see, with their own tokens and linked Instagram accounts.
	 * A Page token sees only itself; a user token sees every Page it was allowed.
	 *
	 * @return array[] array( id, name, token, instagram )
	 */
	public static function pages( $token ) {
		if ( '' === $token ) {
			return array();
		}
		$key = 'marsad_fbpages_' . md5( $token );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}
		$fields = 'id,name,access_token,instagram_business_account{id,username}';
		try {
			$data = Marsad_Http::json( 'GET', self::GRAPH . 'me/accounts?' . http_build_query( array( 'fields' => $fields, 'limit' => 100, 'access_token' => $token ) ) );
			$list = $data['data'] ?? array();
		} catch ( Marsad_Exception $e ) {
			// A Page token has no "accounts": it is the Page itself.
			try {
				$list = array( Marsad_Http::json( 'GET', self::GRAPH . 'me?' . http_build_query( array( 'fields' => 'id,name,instagram_business_account{id,username}', 'access_token' => $token ) ) ) + array( 'access_token' => $token ) );
			} catch ( Marsad_Exception $e2 ) {
				return array();
			}
		}
		$out = array();
		foreach ( $list as $p ) {
			if ( empty( $p['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (string) $p['id'],
				'name'      => (string) ( $p['name'] ?? $p['id'] ),
				'token'     => (string) ( $p['access_token'] ?? $token ),
				'instagram' => isset( $p['instagram_business_account']['id'] ) ? array( 'id' => (string) $p['instagram_business_account']['id'], 'username' => (string) ( $p['instagram_business_account']['username'] ?? '' ) ) : null,
			);
		}
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** The chosen Page (or the only one) among those the token sees. */
	public function page() {
		$pages = self::pages( (string) $this->get( 'token' ) );
		$id    = trim( (string) $this->get( 'page_id' ) );
		foreach ( $pages as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return ( '' === $id && 1 === count( $pages ) ) ? $pages[0] : null;
	}

	public function metrics() {
		return array(
			'followers' => array( 'label' => __( 'Followers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'fans'      => array( 'label' => __( 'Page likes', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
			'talking'   => array( 'label' => __( 'People talking about this', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
		);
	}

	public function fetch( $from, $to ) {
		$p = $this->page();
		if ( ! $p && '' === trim( (string) $this->get( 'page_id' ) ) ) {
			throw new Marsad_Exception(
				self::pages( (string) $this->get( 'token' ) )
					? __( 'Pick your Page in the list, then save.', 'marsad' )
					: __( 'Facebook did not accept this token, or it cannot see any Page. Make a fresh one (steps 2–3) and paste it again.', 'marsad' )
			);
		}
		$id    = $p ? $p['id'] : trim( (string) $this->get( 'page_id' ) );
		$token = $p ? $p['token'] : (string) $this->get( 'token' );
		if ( $p && $p['token'] !== $this->get( 'token' ) ) {
			// Keep the Page's own token: it does not expire like the pasted one.
			Marsad_Registry::remember( $this, 'token', $p['token'] );
			Marsad_Registry::remember( $this, 'page_id', $p['id'] );
		}
		$page = Marsad_Http::json( 'GET', self::GRAPH . rawurlencode( $id ) . '?' . http_build_query( array( 'fields' => 'followers_count,fan_count,talking_about_count', 'access_token' => $token ) ) );
		$day  = $this->today();
		return array(
			$this->row( 'followers', $day, $page['followers_count'] ?? 0 ),
			$this->row( 'fans', $day, $page['fan_count'] ?? 0 ),
			$this->row( 'talking', $day, $page['talking_about_count'] ?? 0 ),
		);
	}
}
