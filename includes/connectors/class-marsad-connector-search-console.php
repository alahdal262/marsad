<?php
/**
 * Google Search Console: how the site appears in Google Search.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Search_Console extends Marsad_Connector {

	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	public function id() {
		return 'search_console';
	}

	public function label() {
		return __( 'Google Search Console', 'marsad' );
	}

	public function group() {
		return 'search';
	}

	public function description() {
		return __( 'Clicks and impressions from Google Search, average position and the queries people used.', 'marsad' );
	}

	public function fields() {
		return array(
			'site'            => array( 'label' => __( 'Search Console property', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'Either sc-domain:example.com (domain property) or https://example.com/ (URL-prefix property).', 'marsad' ) ),
			'service_account' => array( 'label' => __( 'Service account key (JSON)', 'marsad' ), 'type' => 'textarea_secret', 'required' => true, 'help' => __( 'The same key as Google Analytics works.', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			__( 'Enable "Google Search Console API" in the service account\'s Cloud project.', 'marsad' ),
			__( 'In Search Console → Settings → Users and permissions, add the service account e-mail with Restricted permission.', 'marsad' ),
		);
	}

	// Search Console publishes data with a 2–3 day delay; keep refreshing a week.
	public function window_days() {
		return 7;
	}

	public function max_backfill() {
		return 480;
	}

	public function metrics() {
		return array(
			'clicks'      => array( 'label' => __( 'Search clicks', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'impressions' => array( 'label' => __( 'Search impressions', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'ctr'         => array( 'label' => __( 'Click-through rate', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'format' => 'percent' ),
			'position'    => array( 'label' => __( 'Average position', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'format' => 'decimal', 'better' => 'down' ),
		);
	}

	public function tables() {
		return array(
			'clicks_by_query' => array( 'label' => __( 'Top search queries', 'marsad' ), 'dim_label' => __( 'Query', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'clicks_by_page'  => array( 'label' => __( 'Pages clicked from Google', 'marsad' ), 'dim_label' => __( 'Page', 'marsad' ), 'agg' => 'sum', 'limit' => 15, 'link' => true ),
		);
	}

	public function fetch( $from, $to ) {
		$site = trim( (string) $this->get( 'site' ) );
		try {
			$token = Marsad_Google_Auth::token( $this->get( 'service_account' ), self::SCOPE );
			$url   = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
			$auth  = array( 'Authorization' => 'Bearer ' . $token );
			$total = Marsad_Http::json( 'POST', $url, array( 'startDate' => $from, 'endDate' => $to, 'dimensions' => array( 'date' ), 'rowLimit' => 25000 ), $auth );
			$query = Marsad_Http::json( 'POST', $url, array( 'startDate' => $from, 'endDate' => $to, 'dimensions' => array( 'date', 'query' ), 'rowLimit' => 25000 ), $auth );
			$page  = Marsad_Http::json( 'POST', $url, array( 'startDate' => $from, 'endDate' => $to, 'dimensions' => array( 'date', 'page' ), 'rowLimit' => 25000 ), $auth );
		} catch ( Marsad_Exception $e ) {
			throw new Marsad_Exception( Marsad_Google_Auth::explain( $e, 'Google Search Console API', 'searchconsole.googleapis.com' ), (int) $e->getCode() );
		}
		return self::parse( $total, $query, $page, 50 );
	}

	/**
	 * Public for tests. Breakdown rows are trimmed to the top $keep per day by clicks.
	 */
	public static function parse( array $total, array $query, array $page, $keep ) {
		$rows = array();
		foreach ( $total['rows'] ?? array() as $r ) {
			$d      = $r['keys'][0];
			$rows[] = array( 'metric' => 'clicks', 'dim' => '', 'day' => $d, 'value' => (float) $r['clicks'] );
			$rows[] = array( 'metric' => 'impressions', 'dim' => '', 'day' => $d, 'value' => (float) $r['impressions'] );
			$rows[] = array( 'metric' => 'ctr', 'dim' => '', 'day' => $d, 'value' => (float) $r['ctr'] * 100 );
			$rows[] = array( 'metric' => 'position', 'dim' => '', 'day' => $d, 'value' => (float) $r['position'] );
		}
		foreach ( array( 'clicks_by_query' => $query, 'clicks_by_page' => $page ) as $metric => $data ) {
			$by_day = array();
			foreach ( $data['rows'] ?? array() as $r ) {
				$by_day[ $r['keys'][0] ][ $r['keys'][1] ] = (float) $r['clicks'];
			}
			foreach ( $by_day as $d => $items ) {
				arsort( $items );
				foreach ( array_slice( $items, 0, $keep, true ) as $dim => $v ) {
					if ( $v > 0 ) {
						$rows[] = array( 'metric' => $metric, 'dim' => (string) $dim, 'day' => $d, 'value' => $v );
					}
				}
			}
		}
		return $rows;
	}
}
