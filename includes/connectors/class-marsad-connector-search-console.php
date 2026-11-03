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
			'site'            => array( 'label' => __( 'Search Console property', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'Pick it from the list after connecting Google, or type sc-domain:example.com / https://example.com/.', 'marsad' ) ),
			'service_account' => array( 'label' => __( 'Service account key (JSON)', 'marsad' ), 'type' => 'textarea_secret', 'advanced' => true, 'help' => __( 'Only if you prefer a service account to "Connect with Google": create one, add a JSON key, and give its e-mail read access in the product.', 'marsad' ), 'url' => 'https://console.cloud.google.com/iam-admin/serviceaccounts', 'link' => __( 'Service accounts', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			__( 'Press "Connect with Google" at the top of this page with an account that has access to the site in Search Console.', 'marsad' ),
			$this->step( __( 'Pick the property below and save. Not in the list? Add and verify your site in Search Console first.', 'marsad' ), 'https://search.google.com/search-console', __( 'Search Console', 'marsad' ) ),
		);
	}

	public function missing() {
		return $this->missing_google();
	}

	public function choices( $key ) {
		if ( 'site' !== $key ) {
			return null;
		}
		$data = Marsad_Google_Account::list_cached( 'https://www.googleapis.com/webmasters/v3/sites' );
		return $data ? self::sites( $data ) : null;
	}

	/** Verified properties from the sites.list answer. Public for tests. */
	public static function sites( array $data ) {
		$out = array();
		foreach ( $data['siteEntry'] ?? array() as $s ) {
			if ( 'siteUnverifiedUser' !== ( $s['permissionLevel'] ?? '' ) && ! empty( $s['siteUrl'] ) ) {
				$out[ $s['siteUrl'] ] = $s['siteUrl'];
			}
		}
		ksort( $out );
		return $out ? $out : null;
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
			$auth  = Marsad_Google_Account::bearer( $this->get( 'service_account' ), self::SCOPE );
			$url   = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
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
