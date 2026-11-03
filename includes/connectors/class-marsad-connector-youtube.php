<?php
/**
 * YouTube channel totals and the views of the latest uploads, with an API key (no OAuth).
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Youtube extends Marsad_Connector {

	const API = 'https://www.googleapis.com/youtube/v3/';

	public function id() {
		return 'youtube';
	}

	public function label() {
		return __( 'YouTube', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Subscribers, total channel views, video count, and how the latest uploads are doing.', 'marsad' );
	}

	public function fields() {
		return array(
			'channel_id' => array( 'label' => __( 'Channel link', 'marsad' ), 'type' => 'text', 'help' => __( 'Paste the channel address or @handle, for example youtube.com/@yourchannel. Any public channel works, even one you do not manage.', 'marsad' ) ),
			'api_key'    => array( 'label' => __( 'API key', 'marsad' ), 'type' => 'secret', 'advanced' => true, 'help' => __( 'Optional: your own YouTube Data API v3 key, so you do not share Marsad\'s daily quota.', 'marsad' ), 'url' => 'https://console.cloud.google.com/apis/credentials', 'link' => __( 'Create an API key', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			__( 'Paste the channel link below and save: subscribers, views and videos are public, so nothing else is needed.', 'marsad' ),
			__( 'Manage the channel? Press "Connect with Google" instead and pick it from the list.', 'marsad' ),
		);
	}

	public function missing() {
		// With the Google account an empty channel means "my own"; otherwise a link is needed.
		$have = self::channel_query( (string) $this->get( 'channel_id' ) ) || ( Marsad_Google_Account::connected() && '' === trim( (string) $this->get( 'api_key' ) ) );
		return $have ? array() : array( __( 'Channel link', 'marsad' ) );
	}

	/**
	 * What to ask YouTube for, from whatever the admin pasted: a channel URL, an @handle
	 * or a UC… id. Public for tests.
	 */
	public static function channel_query( $input ) {
		$s = trim( (string) $input );
		if ( preg_match( '~(?:^|/channel/)(UC[A-Za-z0-9_-]{22})(?:[/?#]|$)~', $s, $m ) ) {
			return array( 'id' => $m[1] );
		}
		if ( preg_match( '~(?:^|/)(@[A-Za-z0-9._-]{3,100})(?:[/?#]|$)~', $s, $m ) ) {
			return array( 'forHandle' => $m[1] );
		}
		return array();
	}

	public function choices( $key ) {
		if ( 'channel_id' !== $key ) {
			return null;
		}
		$data = Marsad_Google_Account::list_cached( self::API . 'channels?part=snippet&mine=true&maxResults=50' );
		$out  = array();
		foreach ( $data['items'] ?? array() as $c ) {
			$out[ $c['id'] ] = ( $c['snippet']['title'] ?? $c['id'] ) . ' (' . $c['id'] . ')';
		}
		return $out ? $out : null;
	}

	/**
	 * One read: with the site's own API key, as the connected Google account, or through
	 * Marsad Connect's shared key (public numbers only, nothing to set up).
	 *
	 * @throws Marsad_Exception
	 */
	private function call( $endpoint, array $args ) {
		$key = trim( (string) $this->get( 'api_key' ) );
		if ( '' !== $key ) {
			return Marsad_Http::json( 'GET', self::API . $endpoint . '?' . http_build_query( $args + array( 'key' => $key ) ) );
		}
		if ( Marsad_Google_Account::connected() ) {
			return Marsad_Http::json( 'GET', self::API . $endpoint . '?' . http_build_query( $args ), null, array( 'Authorization' => 'Bearer ' . Marsad_Google_Account::token() ) );
		}
		return Marsad_Http::json( 'GET', Marsad_Google_Account::relay() . 'youtube.php?' . http_build_query( array( 'endpoint' => $endpoint ) + $args ) );
	}

	public function metrics() {
		return array(
			'subscribers' => array( 'label' => __( 'Subscribers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'views'       => array( 'label' => __( 'Total channel views', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'videos'      => array( 'label' => __( 'Videos', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
		);
	}

	public function tables() {
		return array(
			'video_views' => array( 'label' => __( 'Latest uploads (views)', 'marsad' ), 'dim_label' => __( 'Video', 'marsad' ), 'agg' => 'last', 'limit' => 15 ),
		);
	}

	public function fetch( $from, $to ) {
		$which = self::channel_query( (string) $this->get( 'channel_id' ) );
		$ch    = $this->call( 'channels', array( 'part' => 'statistics,contentDetails' ) + ( $which ? $which : array( 'mine' => 'true' ) ) );
		if ( empty( $ch['items'][0] ) ) {
			throw new Marsad_Exception( __( 'YouTube found no channel at this link. Copy the address from the channel page and paste it again.', 'marsad' ) );
		}
		$day  = $this->today();
		$st   = $ch['items'][0]['statistics'];
		$rows = array(
			$this->row( 'subscribers', $day, $st['subscriberCount'] ?? 0 ),
			$this->row( 'views', $day, $st['viewCount'] ?? 0 ),
			$this->row( 'videos', $day, $st['videoCount'] ?? 0 ),
		);
		$uploads = $ch['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';
		if ( $uploads ) {
			$list = $this->call( 'playlistItems', array( 'part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 25 ) );
			$ids  = array_filter( array_map( function ( $i ) { return $i['contentDetails']['videoId'] ?? ''; }, $list['items'] ?? array() ) );
			if ( $ids ) {
				$vids = $this->call( 'videos', array( 'part' => 'snippet,statistics', 'id' => implode( ',', $ids ) ) );
				foreach ( $vids['items'] ?? array() as $v ) {
					$rows[] = $this->row( 'video_views', $day, $v['statistics']['viewCount'] ?? 0, $v['snippet']['title'] ?? $v['id'] );
				}
			}
		}
		return $rows;
	}
}
