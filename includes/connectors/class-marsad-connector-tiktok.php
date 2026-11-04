<?php
/**
 * TikTok account numbers through TikTok's Display API (user.info.stats). Access tokens last
 * a day; each refresh returns a new refresh token, which Marsad keeps.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Tiktok extends Marsad_Connector {

	public function id() {
		return 'tiktok';
	}

	public function label() {
		return __( 'TikTok', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Followers, total likes and videos of a TikTok account.', 'marsad' );
	}

	public function fields() {
		return array(
			'client_key'    => array( 'label' => __( 'Client key', 'marsad' ), 'type' => 'text', 'required' => true ),
			'client_secret' => array( 'label' => __( 'Client secret', 'marsad' ), 'type' => 'secret', 'required' => true ),
			'refresh_token' => array( 'label' => __( 'Refresh token', 'marsad' ), 'type' => 'secret', 'required' => true, 'help' => __( 'From signing in once with your TikTok account to your app (scope user.info.stats). Marsad keeps the new one TikTok issues each day.', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			$this->step( __( 'Create an app on TikTok for Developers, add Login Kit and the user.info.basic and user.info.stats scopes, and submit it for review.', 'marsad' ), 'https://developers.tiktok.com/apps/', __( 'TikTok developer apps', 'marsad' ) ),
			$this->step( __( 'Sign in once with your TikTok account through the app (authorization code flow) to receive a refresh token.', 'marsad' ), 'https://developers.tiktok.com/doc/oauth-user-access-token-management', __( 'How to get the token', 'marsad' ) ),
			__( 'Paste the client key, client secret and refresh token here.', 'marsad' ),
		);
	}

	public function metrics() {
		return array(
			'followers' => array( 'label' => __( 'Followers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'likes'     => array( 'label' => __( 'Likes', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'videos'    => array( 'label' => __( 'Videos', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
			'following' => array( 'label' => __( 'Following', 'marsad' ), 'agg' => 'last' ),
		);
	}

	public function fetch( $from, $to ) {
		$res  = Marsad_Http::request(
			'POST',
			'https://open.tiktokapis.com/v2/oauth/token/',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'client_key'    => (string) $this->get( 'client_key' ),
					'client_secret' => (string) $this->get( 'client_secret' ),
					'grant_type'    => 'refresh_token',
					'refresh_token' => (string) $this->get( 'refresh_token' ),
				),
			)
		);
		$tok = json_decode( $res['body'], true );
		if ( 200 !== $res['code'] || empty( $tok['access_token'] ) ) {
			throw new Marsad_Exception( __( 'TikTok did not renew the access. Sign in again to get a new refresh token and paste it here.', 'marsad' ) . ' ' . Marsad_Http::error_message( $res['code'], $tok, $res['body'] ) );
		}
		if ( ! empty( $tok['refresh_token'] ) && $tok['refresh_token'] !== $this->get( 'refresh_token' ) ) {
			Marsad_Registry::remember( $this, 'refresh_token', $tok['refresh_token'] );
		}
		$info = Marsad_Http::json( 'GET', 'https://open.tiktokapis.com/v2/user/info/?fields=follower_count,following_count,likes_count,video_count', null, array( 'Authorization' => 'Bearer ' . $tok['access_token'] ) );
		$u    = $info['data']['user'] ?? array();
		$day  = $this->today();
		return array(
			$this->row( 'followers', $day, $u['follower_count'] ?? 0 ),
			$this->row( 'likes', $day, $u['likes_count'] ?? 0 ),
			$this->row( 'videos', $day, $u['video_count'] ?? 0 ),
			$this->row( 'following', $day, $u['following_count'] ?? 0 ),
		);
	}
}
