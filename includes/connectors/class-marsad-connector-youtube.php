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
			'channel_id' => array( 'label' => __( 'Channel', 'marsad' ), 'type' => 'text', 'help' => __( 'Pick it after connecting Google (empty = your own channel), or type an ID starting with UC….', 'marsad' ) ),
			'api_key'    => array( 'label' => __( 'API key', 'marsad' ), 'type' => 'secret', 'advanced' => true, 'help' => __( 'Only without "Connect with Google": a YouTube Data API v3 key; then the channel ID is required.', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			__( 'Press "Connect with Google" at the top of this page with the account that owns or manages the channel.', 'marsad' ),
			__( 'Pick the channel below (or leave it empty for your own) and save.', 'marsad' ),
		);
	}

	public function missing() {
		if ( '' !== trim( (string) $this->get( 'api_key' ) ) ) {
			return '' === trim( (string) $this->get( 'channel_id' ) ) ? array( __( 'Channel', 'marsad' ) ) : array();
		}
		return Marsad_Google_Account::connected() ? array() : array( __( 'Connect with Google (or an API key and channel ID)', 'marsad' ) );
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
	 * Query args and headers for one call: the API key when one is set, otherwise the Google account.
	 *
	 * @throws Marsad_Exception
	 */
	private function auth( array $args ) {
		$key = trim( (string) $this->get( 'api_key' ) );
		if ( '' !== $key ) {
			return array( $args + array( 'key' => $key ), array() );
		}
		return array( $args, array( 'Authorization' => 'Bearer ' . Marsad_Google_Account::token() ) );
	}

	private function call( $endpoint, array $args ) {
		list( $q, $headers ) = $this->auth( $args );
		return Marsad_Http::json( 'GET', self::API . $endpoint . '?' . http_build_query( $q ), null, $headers );
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
		$channel = trim( (string) $this->get( 'channel_id' ) );
		$ch      = $this->call( 'channels', array( 'part' => 'statistics,contentDetails' ) + ( '' !== $channel ? array( 'id' => $channel ) : array( 'mine' => 'true' ) ) );
		if ( empty( $ch['items'][0] ) ) {
			throw new Marsad_Exception( __( 'No channel with this ID.', 'marsad' ) );
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
