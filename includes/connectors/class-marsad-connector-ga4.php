<?php
/**
 * Google Analytics 4 through the Data API with a service account.
 * When the property also has the Android/iOS app streams (Firebase), the platform
 * breakdown separates website, Android and iOS users.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Ga4 extends Marsad_Connector {

	const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

	public function id() {
		return 'ga4';
	}

	public function label() {
		return __( 'Google Analytics 4', 'marsad' );
	}

	public function group() {
		return 'web';
	}

	public function description() {
		return __( 'Visitors, page views, sessions, top pages, traffic sources, countries — and app users when your Android/iOS apps report to the same property.', 'marsad' );
	}

	public function fields() {
		return array(
			'property_id'     => array( 'label' => __( 'Analytics property', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'Pick it from the list after connecting Google, or type the property ID (digits only, Admin → Property settings).', 'marsad' ) ),
			'service_account' => array( 'label' => __( 'Service account key (JSON)', 'marsad' ), 'type' => 'textarea_secret', 'advanced' => true, 'help' => __( 'Only if you prefer a service account to "Connect with Google".', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			__( 'Press "Connect with Google" at the top of this page and sign in with an account that can see your Analytics.', 'marsad' ),
			__( 'Pick the property below and save.', 'marsad' ),
		);
	}

	public function is_configured() {
		return parent::is_configured() && $this->has_google_auth();
	}

	public function choices( $key ) {
		if ( 'property_id' !== $key ) {
			return null;
		}
		$data = Marsad_Google_Account::list_cached( 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200' );
		return $data ? self::properties( $data ) : null;
	}

	/** Property pick-list from the Admin API accountSummaries answer. Public for tests. */
	public static function properties( array $data ) {
		$out = array();
		foreach ( $data['accountSummaries'] ?? array() as $acc ) {
			foreach ( $acc['propertySummaries'] ?? array() as $p ) {
				$id         = preg_replace( '/\D/', '', (string) ( $p['property'] ?? '' ) );
				$out[ $id ] = ( $acc['displayName'] ?? '' ) . ' › ' . ( $p['displayName'] ?? $id ) . ' (' . $id . ')';
			}
		}
		return $out ? $out : null;
	}

	public function max_backfill() {
		return 365;
	}

	public function metrics() {
		return array(
			'users'            => array( 'label' => __( 'Daily users (avg)', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'chart' => true ),
			'new_users'        => array( 'label' => __( 'New users', 'marsad' ), 'agg' => 'sum', 'kpi' => true ),
			'sessions'         => array( 'label' => __( 'Sessions', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'views'            => array( 'label' => __( 'Page & screen views', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'engagement_rate'  => array( 'label' => __( 'Engagement rate', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'format' => 'percent' ),
			'avg_engagement'   => array( 'label' => __( 'Avg. engagement time per user', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'format' => 'seconds' ),
		);
	}

	public function tables() {
		return array(
			'views_by_page'     => array( 'label' => __( 'Top pages', 'marsad' ), 'dim_label' => __( 'Page', 'marsad' ), 'agg' => 'sum', 'limit' => 15, 'link' => true ),
			'users_by_platform' => array( 'label' => __( 'Web vs apps (avg daily users)', 'marsad' ), 'dim_label' => __( 'Platform', 'marsad' ), 'agg' => 'avg', 'limit' => 5 ),
			'sessions_by_channel' => array( 'label' => __( 'Traffic sources', 'marsad' ), 'dim_label' => __( 'Channel', 'marsad' ), 'agg' => 'sum', 'limit' => 10 ),
			'sessions_by_source'  => array( 'label' => __( 'Referring sites & social', 'marsad' ), 'dim_label' => __( 'Source', 'marsad' ), 'agg' => 'sum', 'limit' => 15 ),
			'users_by_country'  => array( 'label' => __( 'Countries (avg daily users)', 'marsad' ), 'dim_label' => __( 'Country', 'marsad' ), 'agg' => 'avg', 'limit' => 15 ),
			'users_by_device'   => array( 'label' => __( 'Devices (avg daily users)', 'marsad' ), 'dim_label' => __( 'Device', 'marsad' ), 'agg' => 'avg', 'limit' => 5 ),
		);
	}

	public function fetch( $from, $to ) {
		$property = preg_replace( '/\D/', '', (string) $this->get( 'property_id' ) );
		if ( '' === $property ) {
			throw new Marsad_Exception( __( 'Property ID is missing.', 'marsad' ) );
		}
		try {
			$auth  = Marsad_Google_Account::bearer( $this->get( 'service_account' ), self::SCOPE );
			$range = array( array( 'startDate' => $from, 'endDate' => $to ) );
			$data  = Marsad_Http::json(
				'POST',
				'https://analyticsdata.googleapis.com/v1beta/properties/' . $property . ':batchRunReports',
				array(
					'requests' => array(
						$this->report( $range, array( 'date' ), array( 'activeUsers', 'newUsers', 'sessions', 'screenPageViews', 'engagementRate', 'userEngagementDuration' ) ),
						$this->report( $range, array( 'date', 'pagePath' ), array( 'screenPageViews' ), 'screenPageViews', 25 * $this->span( $from, $to ) ),
						$this->report( $range, array( 'date', 'platform' ), array( 'activeUsers' ) ),
						$this->report( $range, array( 'date', 'sessionDefaultChannelGroup' ), array( 'sessions' ) ),
						$this->report( $range, array( 'date', 'country' ), array( 'activeUsers' ), 'activeUsers', 30 * $this->span( $from, $to ) ),
					),
				),
				$auth
			);
			$extra = Marsad_Http::json(
				'POST',
				'https://analyticsdata.googleapis.com/v1beta/properties/' . $property . ':batchRunReports',
				array(
					'requests' => array(
						$this->report( $range, array( 'date', 'sessionSource' ), array( 'sessions' ), 'sessions', 30 * $this->span( $from, $to ) ),
						$this->report( $range, array( 'date', 'deviceCategory' ), array( 'activeUsers' ) ),
					),
				),
				$auth
			);
		} catch ( Marsad_Exception $e ) {
			throw new Marsad_Exception( Marsad_Google_Auth::explain( $e, 'Google Analytics Data API', 'analyticsdata.googleapis.com' ), (int) $e->getCode() );
		}
		return self::parse( array_merge( $data['reports'] ?? array(), $extra['reports'] ?? array() ) );
	}

	private function span( $from, $to ) {
		return max( 1, (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1 );
	}

	private function report( $range, $dims, $metrics, $order_by = '', $limit = 100000 ) {
		$r = array(
			'dateRanges' => $range,
			'dimensions' => array_map(
				function ( $d ) {
					return array( 'name' => $d );
				},
				$dims
			),
			'metrics'    => array_map(
				function ( $m ) {
					return array( 'name' => $m );
				},
				$metrics
			),
			'limit'      => $limit,
		);
		if ( $order_by ) {
			$r['orderBys'] = array( array( 'metric' => array( 'metricName' => $order_by ), 'desc' => true ) );
		}
		return $r;
	}

	/**
	 * Turn the seven batch reports (in request order) into rows. Public for tests.
	 */
	public static function parse( array $reports ) {
		$rows = array();
		$day  = function ( $yyyymmdd ) {
			return substr( $yyyymmdd, 0, 4 ) . '-' . substr( $yyyymmdd, 4, 2 ) . '-' . substr( $yyyymmdd, 6, 2 );
		};
		$each = function ( $report, $fn ) {
			foreach ( $report['rows'] ?? array() as $r ) {
				$dims = array_column( $r['dimensionValues'] ?? array(), 'value' );
				$vals = array_map( 'floatval', array_column( $r['metricValues'] ?? array(), 'value' ) );
				$fn( $dims, $vals );
			}
		};
		$push = function ( $metric, $d, $v, $dim = '' ) use ( &$rows, $day ) {
			$rows[] = array( 'metric' => $metric, 'dim' => (string) $dim, 'day' => $day( $d ), 'value' => (float) $v );
		};

		if ( isset( $reports[0] ) ) {
			$each(
				$reports[0],
				function ( $d, $v ) use ( $push ) {
					$push( 'users', $d[0], $v[0] );
					$push( 'new_users', $d[0], $v[1] );
					$push( 'sessions', $d[0], $v[2] );
					$push( 'views', $d[0], $v[3] );
					$push( 'engagement_rate', $d[0], $v[4] * 100 );
					$push( 'avg_engagement', $d[0], $v[0] > 0 ? $v[5] / $v[0] : 0 );
				}
			);
		}
		$map = array(
			1 => 'views_by_page',
			2 => 'users_by_platform',
			3 => 'sessions_by_channel',
			4 => 'users_by_country',
			5 => 'sessions_by_source',
			6 => 'users_by_device',
		);
		foreach ( $map as $i => $metric ) {
			if ( ! isset( $reports[ $i ] ) ) {
				continue;
			}
			$each(
				$reports[ $i ],
				function ( $d, $v ) use ( $push, $metric ) {
					if ( isset( $d[1] ) && '' !== $d[1] && '(not set)' !== $d[1] ) {
						$push( $metric, $d[0], $v[0], $d[1] );
					}
				}
			);
		}
		return $rows;
	}
}
