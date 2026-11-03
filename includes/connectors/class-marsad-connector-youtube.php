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
			'channel_id' => array( 'label' => __( 'Channel ID', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'Starts with UC… (YouTube Studio → Settings → Channel → Advanced).', 'marsad' ) ),
			'api_key'    => array( 'label' => __( 'API key', 'marsad' ), 'type' => 'secret', 'required' => true ),
		);
	}

	public function instructions() {
		return array(
			__( 'In Google Cloud → APIs & Services enable "YouTube Data API v3".', 'marsad' ),
			__( 'Credentials → Create credentials → API key. Restrict it to YouTube Data API v3.', 'marsad' ),
		);
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
		$key     = $this->get( 'api_key' );
		$channel = trim( (string) $this->get( 'channel_id' ) );
		$ch      = Marsad_Http::json( 'GET', self::API . 'channels?' . http_build_query( array( 'part' => 'statistics,contentDetails', 'id' => $channel, 'key' => $key ) ) );
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
			$list = Marsad_Http::json( 'GET', self::API . 'playlistItems?' . http_build_query( array( 'part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 25, 'key' => $key ) ) );
			$ids  = array_filter( array_map( function ( $i ) { return $i['contentDetails']['videoId'] ?? ''; }, $list['items'] ?? array() ) );
			if ( $ids ) {
				$vids = Marsad_Http::json( 'GET', self::API . 'videos?' . http_build_query( array( 'part' => 'snippet,statistics', 'id' => implode( ',', $ids ), 'key' => $key ) ) );
				foreach ( $vids['items'] ?? array() as $v ) {
					$rows[] = $this->row( 'video_views', $day, $v['statistics']['viewCount'] ?? 0, $v['snippet']['title'] ?? $v['id'] );
				}
			}
		}
		return $rows;
	}
}
