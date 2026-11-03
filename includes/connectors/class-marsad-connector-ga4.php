<?php
/**
 * Google Analytics 4 through the Data API with a service account.
 * When the property also has the Android/iOS app streams (Firebase), the platform
 * breakdown separates website, Android and iOS users.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Ga4 extends Marsad_Connector {

	const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
	const API   = 'https://analyticsdata.googleapis.com/v1beta/properties/';

	/** batchRunReports takes at most five requests; one request at most ten metrics. */
	const BATCH       = 5;
	const MAX_METRICS = 10;

	/** Transient prefixes, keyed by property. */
	const META     = 'marsad_ga4_meta_';
	const REALTIME = 'marsad_ga4_rt_';

	/**
	 * The range reports. Sorted by the first metric (desc) unless by_dims; "filter" keeps one
	 * value of a dimension that is not shown; "positive" drops rows whose first metric is 0.
	 * Reports on a customEvent: dimension run only when the property has registered it.
	 */
	const REPORTS = array(
		'totals'        => array( 'dims' => array(), 'metrics' => array( 'activeUsers', 'totalUsers', 'newUsers', 'sessions', 'engagedSessions', 'engagementRate', 'bounceRate', 'screenPageViews', 'screenPageViewsPerSession', 'averageSessionDuration', 'userEngagementDuration', 'eventCount', 'keyEvents', 'sessionsPerUser' ) ),
		'pages'         => array( 'dims' => array( 'pagePath', 'pageTitle' ), 'metrics' => array( 'screenPageViews', 'activeUsers', 'userEngagementDuration', 'engagementRate' ), 'limit' => 500 ),
		'landing'       => array( 'dims' => array( 'landingPage' ), 'metrics' => array( 'sessions', 'newUsers', 'engagementRate', 'keyEvents' ), 'limit' => 200 ),
		'channels'      => array( 'dims' => array( 'sessionDefaultChannelGroup' ), 'metrics' => array( 'sessions', 'activeUsers', 'engagementRate', 'keyEvents' ), 'limit' => 30 ),
		'source_medium' => array( 'dims' => array( 'sessionSourceMedium' ), 'metrics' => array( 'sessions', 'activeUsers', 'engagementRate' ), 'limit' => 200 ),
		'campaigns'     => array( 'dims' => array( 'sessionCampaignName' ), 'metrics' => array( 'sessions', 'activeUsers', 'keyEvents' ), 'limit' => 100 ),
		'social'        => array( 'dims' => array( 'sessionSource' ), 'metrics' => array( 'sessions', 'activeUsers' ), 'limit' => 50, 'filter' => array( 'sessionDefaultChannelGroup', 'Organic Social' ) ),
		'referrals'     => array( 'dims' => array( 'sessionSource' ), 'metrics' => array( 'sessions', 'activeUsers' ), 'limit' => 100, 'filter' => array( 'sessionDefaultChannelGroup', 'Referral' ) ),
		'countries'     => array( 'dims' => array( 'country' ), 'metrics' => array( 'activeUsers', 'sessions', 'screenPageViews' ), 'limit' => 250 ),
		'cities'        => array( 'dims' => array( 'city', 'country' ), 'metrics' => array( 'activeUsers', 'sessions' ), 'limit' => 250 ),
		'languages'     => array( 'dims' => array( 'language' ), 'metrics' => array( 'activeUsers' ), 'limit' => 60 ),
		'devices'       => array( 'dims' => array( 'deviceCategory' ), 'metrics' => array( 'activeUsers', 'sessions', 'engagementRate' ), 'limit' => 10 ),
		'os'            => array( 'dims' => array( 'operatingSystem' ), 'metrics' => array( 'activeUsers' ), 'limit' => 30 ),
		'browsers'      => array( 'dims' => array( 'browser' ), 'metrics' => array( 'activeUsers' ), 'limit' => 30 ),
		'models'        => array( 'dims' => array( 'mobileDeviceModel' ), 'metrics' => array( 'activeUsers' ), 'limit' => 50 ),
		'resolutions'   => array( 'dims' => array( 'screenResolution' ), 'metrics' => array( 'activeUsers' ), 'limit' => 30 ),
		'new_returning' => array( 'dims' => array( 'newVsReturning' ), 'metrics' => array( 'activeUsers', 'sessions', 'engagementRate' ), 'limit' => 5 ),
		'age'           => array( 'dims' => array( 'userAgeBracket' ), 'metrics' => array( 'activeUsers' ), 'limit' => 10 ),
		'gender'        => array( 'dims' => array( 'userGender' ), 'metrics' => array( 'activeUsers' ), 'limit' => 5 ),
		'heatmap'       => array( 'dims' => array( 'dayOfWeek', 'hour' ), 'metrics' => array( 'activeUsers' ), 'limit' => 200, 'by_dims' => true ),
		'events'        => array( 'dims' => array( 'eventName' ), 'metrics' => array( 'eventCount', 'totalUsers' ), 'limit' => 200 ),
		'key_events'    => array( 'dims' => array( 'eventName' ), 'metrics' => array( 'keyEvents' ), 'limit' => 50, 'positive' => true ),
		'search_terms'  => array( 'dims' => array( 'searchTerm' ), 'metrics' => array( 'eventCount', 'totalUsers' ), 'limit' => 200 ),
		'outbound'      => array( 'dims' => array( 'linkUrl' ), 'metrics' => array( 'eventCount' ), 'limit' => 100, 'filter' => array( 'eventName', 'click' ) ),
		'authors'       => array( 'dims' => array( 'customEvent:googlesitekit_post_author' ), 'metrics' => array( 'screenPageViews', 'activeUsers', 'userEngagementDuration' ), 'limit' => 100 ),
		'categories'    => array( 'dims' => array( 'customEvent:category' ), 'metrics' => array( 'screenPageViews', 'activeUsers' ), 'limit' => 100 ),
		'post_dates'    => array( 'dims' => array( 'customEvent:googlesitekit_post_date' ), 'metrics' => array( 'screenPageViews' ), 'limit' => 400 ),
		'scroll'        => array( 'dims' => array( 'customEvent:percentage' ), 'metrics' => array( 'eventCount' ), 'limit' => 20, 'filter' => array( 'eventName', 'scroll' ) ),
	);

	/** Custom metrics of video events (video_percent and friends) count as one "video" report. */
	const VIDEO_METRIC = '/^(customEvent|averageCustomEvent|countCustomEvent):video_/';

	/** @var array property => metadata read during this run, so five ranges ask once */
	private $meta = array();

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
			'service_account' => array( 'label' => __( 'Service account key (JSON)', 'marsad' ), 'type' => 'textarea_secret', 'advanced' => true, 'help' => __( 'Only if you prefer a service account to "Connect with Google": create one, add a JSON key, and give its e-mail read access in the product.', 'marsad' ), 'url' => 'https://console.cloud.google.com/iam-admin/serviceaccounts', 'link' => __( 'Service accounts', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			__( 'Press "Connect with Google" at the top of this page and sign in with an account that can see your Analytics.', 'marsad' ),
			$this->step( __( 'Pick the property below and save. No property yet? Create one in Google Analytics.', 'marsad' ), 'https://analytics.google.com/analytics/web/', __( 'Google Analytics', 'marsad' ) ),
		);
	}

	public function missing() {
		return $this->missing_google();
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

	/** First day the Data API answers for; "all time" starts here (Sync reads it only for full-history sources). */
	public function history_start() {
		return '2015-08-14';
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

	public function supports_snapshots(): bool {
		return true;
	}

	/**
	 * Every range report, asked of Google for the range as a whole, so users are deduplicated
	 * and tables are complete. A report Google rejects is dropped and noted; the rest is kept.
	 *
	 * @throws Marsad_Exception
	 */
	public function snapshots( $range_key, array $range ): array {
		$property = $this->property();
		if ( '' === $property ) {
			throw new Marsad_Exception( __( 'Property ID is missing.', 'marsad' ) );
		}
		try {
			$auth      = Marsad_Google_Account::bearer( $this->get( 'service_account' ), self::SCOPE );
			$requests  = self::requests( $range, $this->metadata( $property, $auth ) );
			$responses = $this->run( $property, $requests, $auth, $range_key );
		} catch ( Marsad_Exception $e ) {
			throw new Marsad_Exception( Marsad_Google_Auth::explain( $e, 'Google Analytics Data API', 'analyticsdata.googleapis.com' ), (int) $e->getCode() );
		}
		$out = self::parse_snapshot( $requests, $responses );
		// Always a totals report, so a rejected one is retried at the range's pace, not on every run.
		if ( ! isset( $out['totals'] ) ) {
			$out['totals'] = array( 'metrics' => array() );
		}
		return $out;
	}

	/**
	 * The API requests for one range, each wrapped with what the parser needs to know.
	 * Public for tests.
	 *
	 * @param array $range from Marsad_Snapshots::range()
	 * @param array $meta  array( 'dims' => customEvent apiNames, 'metrics' => video apiName => name )
	 * @return array list of array( 'key', 'limit', 'sort', 'body' )
	 */
	public static function requests( array $range, array $meta ) {
		$dates = array( array( 'startDate' => $range['from'], 'endDate' => $range['to'], 'name' => 'cur' ) );
		if ( ! empty( $range['prev_from'] ) && ! empty( $range['prev_to'] ) ) {
			$dates[] = array( 'startDate' => $range['prev_from'], 'endDate' => $range['prev_to'], 'name' => 'prev' );
		}
		$defs   = self::REPORTS;
		$videos = array_keys( (array) ( $meta['metrics'] ?? array() ) );
		if ( $videos ) {
			$defs['video'] = array( 'dims' => array(), 'metrics' => array_slice( $videos, 0, self::MAX_METRICS ) );
		}
		$custom = (array) ( $meta['dims'] ?? array() );
		$out    = array();
		foreach ( $defs as $key => $def ) {
			foreach ( $def['dims'] as $dim ) {
				if ( 0 === strpos( $dim, 'customEvent:' ) && ! in_array( $dim, $custom, true ) ) {
					continue 2; // Not registered in this property: nothing to ask for.
				}
			}
			// Totals have more metrics than one request may carry; the parser joins the parts.
			foreach ( array_chunk( $def['metrics'], self::MAX_METRICS ) as $metrics ) {
				$out[] = self::request( $key, $def, $metrics, $dates );
			}
		}
		return $out;
	}

	private static function request( $key, array $def, array $metrics, array $dates ) {
		$name  = function ( $n ) {
			return array( 'name' => $n );
		};
		$limit = (int) ( $def['limit'] ?? 0 );
		$sort  = $def['dims'] ? $metrics[0] : '';
		$body  = array(
			'dateRanges'    => $dates,
			'metrics'       => array_map( $name, $metrics ),
			'keepEmptyRows' => false,
		);
		if ( $def['dims'] ) {
			$body['dimensions'] = array_map( $name, $def['dims'] );
			// Both periods share the limit, so ask for twice as many rows when comparing.
			$body['limit'] = $limit * count( $dates );
		}
		if ( ! empty( $def['by_dims'] ) ) {
			$sort             = $def['dims'][0];
			$body['orderBys'] = array_map(
				function ( $d ) {
					return array( 'dimension' => array( 'dimensionName' => $d, 'orderType' => 'NUMERIC' ) );
				},
				$def['dims']
			);
		} elseif ( '' !== $sort ) {
			$body['orderBys'] = array( array( 'metric' => array( 'metricName' => $sort ), 'desc' => true ) );
		}
		if ( ! empty( $def['filter'] ) ) {
			$body['dimensionFilter'] = array(
				'filter' => array(
					'fieldName'    => $def['filter'][0],
					'stringFilter' => array( 'value' => $def['filter'][1], 'matchType' => 'EXACT' ),
				),
			);
		}
		if ( ! empty( $def['positive'] ) ) {
			$body['metricFilter'] = array(
				'filter' => array(
					'fieldName'     => $metrics[0],
					'numericFilter' => array( 'operation' => 'GREATER_THAN', 'value' => array( 'int64Value' => '0' ) ),
				),
			);
		}
		return array(
			'key'   => $key,
			'limit' => $limit,
			'sort'  => $sort,
			'body'  => $body,
		);
	}

	/**
	 * Send the requests five at a time. When Google rejects a batch as invalid, each request
	 * is sent alone, so one incompatible report is dropped (and noted) without the others.
	 *
	 * @return array request index => report answer; dropped indexes are missing
	 * @throws Marsad_Exception for anything that is not one bad request (auth, quota, outage)
	 */
	private function run( $property, array $requests, array $auth, $range_key ) {
		$url  = self::API . $property . ':batchRunReports';
		$send = function ( array $part ) use ( $url, $auth ) {
			$data = Marsad_Http::json( 'POST', $url, array( 'requests' => array_column( $part, 'body' ) ), $auth );
			return array_values( (array) ( $data['reports'] ?? array() ) );
		};
		$out     = array();
		$dropped = array();
		foreach ( array_chunk( $requests, self::BATCH, true ) as $chunk ) {
			try {
				$reports = $send( $chunk );
				foreach ( array_keys( $chunk ) as $n => $i ) {
					$out[ $i ] = $reports[ $n ] ?? null;
				}
				continue;
			} catch ( Marsad_Exception $e ) {
				if ( 400 !== $e->getCode() ) {
					throw $e;
				}
				$batch_error = $e;
			}
			foreach ( $chunk as $i => $req ) {
				$fail = $batch_error;
				if ( count( $chunk ) > 1 ) {
					try {
						$reports   = $send( array( $req ) );
						$out[ $i ] = $reports[0] ?? null;
						continue;
					} catch ( Marsad_Exception $e ) {
						if ( 400 !== $e->getCode() ) {
							throw $e;
						}
						$fail = $e;
					}
				}
				$dropped[] = array( $req['key'], $fail );
			}
		}
		// Every request refused is not one bad report but a bad range or property: fail it whole.
		if ( $dropped && ! $out ) {
			throw $dropped[0][1];
		}
		foreach ( $dropped as $d ) {
			Marsad_Snapshots::record_error(
				$this->id(),
				$range_key,
				/* translators: 1: report name, 2: Google's error message */
				sprintf( __( 'Google Analytics rejected the "%1$s" report, so it was left out: %2$s', 'marsad' ), $d[0], $d[1]->getMessage() )
			);
		}
		return $out;
	}

	/**
	 * Which custom dimensions and video metrics the property has, cached a day. Unreadable
	 * metadata only means the custom reports wait for the next run.
	 *
	 * @return array array( 'dims' => apiName[], 'metrics' => apiName => name )
	 */
	private function metadata( $property, array $auth ) {
		if ( isset( $this->meta[ $property ] ) ) {
			return $this->meta[ $property ];
		}
		$hit = get_transient( self::META . $property );
		if ( is_array( $hit ) ) {
			$this->meta[ $property ] = $hit;
			return $hit;
		}
		try {
			$data = Marsad_Http::json( 'GET', self::API . $property . '/metadata', null, $auth );
		} catch ( Marsad_Exception $e ) {
			$this->meta[ $property ] = array( 'dims' => array(), 'metrics' => array() );
			return $this->meta[ $property ];
		}
		$meta = array( 'dims' => array(), 'metrics' => array() );
		foreach ( (array) ( $data['dimensions'] ?? array() ) as $d ) {
			$api = (string) ( $d['apiName'] ?? '' );
			if ( 0 === strpos( $api, 'customEvent:' ) ) {
				$meta['dims'][] = $api;
			}
		}
		foreach ( (array) ( $data['metrics'] ?? array() ) as $m ) {
			$api = (string) ( $m['apiName'] ?? '' );
			if ( preg_match( self::VIDEO_METRIC, $api ) ) {
				$meta['metrics'][ $api ] = (string) ( $m['uiName'] ?? $api );
			}
		}
		set_transient( self::META . $property, $meta, DAY_IN_SECONDS );
		$this->meta[ $property ] = $meta;
		return $meta;
	}

	/**
	 * Turn Google's answers into snapshot payloads: requests without dimensions become the
	 * totals shape (parts of one key joined), the others tables. The "cur" and "prev" date
	 * ranges (date_range_0/1 when unnamed) become m and p; rows whose main dimension is
	 * "(not set)" are left out. Pure, public for tests.
	 *
	 * @param array $requests from requests()
	 * @param array $responses request index => RunReportResponse; a missing index was dropped
	 * @return array report key => payload
	 */
	public static function parse_snapshot( array $requests, array $responses ) {
		$out = array();
		foreach ( $requests as $i => $req ) {
			if ( empty( $responses[ $i ] ) || ! is_array( $responses[ $i ] ) ) {
				continue;
			}
			$res     = $responses[ $i ];
			$dims    = array_column( (array) ( $req['body']['dimensions'] ?? array() ), 'name' );
			$metrics = array_column( (array) ( $req['body']['metrics'] ?? array() ), 'name' );
			$compare = count( (array) ( $req['body']['dateRanges'] ?? array() ) ) > 1;
			$rows    = self::response_rows( $res, $dims, $metrics );
			if ( ! $dims ) {
				$prior              = $out[ $req['key'] ]['metrics'] ?? array();
				$out[ $req['key'] ] = array( 'metrics' => array_merge( $prior, self::totals( $rows, $metrics, $compare ) ) );
			} elseif ( ! isset( $out[ $req['key'] ] ) ) {
				$out[ $req['key'] ] = self::table( $req, $res, $rows, $dims, $metrics, $compare );
			}
		}
		return $out;
	}

	/**
	 * Read a report answer by its headers rather than by position.
	 *
	 * @return array list of array( 'd' => requested dimension values, 'm' => metric values, 'prev' => bool )
	 */
	private static function response_rows( array $res, array $dims, array $metrics ) {
		$dh   = array_flip( array_column( (array) ( $res['dimensionHeaders'] ?? array() ), 'name' ) );
		$mh   = array_column( (array) ( $res['metricHeaders'] ?? array() ), null, 'name' );
		$mpos = array_flip( array_column( (array) ( $res['metricHeaders'] ?? array() ), 'name' ) );
		$out  = array();
		foreach ( (array) ( $res['rows'] ?? array() ) as $r ) {
			$dv = array_column( (array) ( $r['dimensionValues'] ?? array() ), 'value' );
			$mv = array_column( (array) ( $r['metricValues'] ?? array() ), 'value' );
			$d  = array();
			foreach ( $dims as $n => $dim ) {
				$d[] = (string) ( $dv[ $dh[ $dim ] ?? $n ] ?? '' );
			}
			$m = array();
			foreach ( $metrics as $n => $metric ) {
				$m[] = self::number( $mv[ $mpos[ $metric ] ?? $n ] ?? 0, $mh[ $metric ]['type'] ?? '' );
			}
			$range = isset( $dh['dateRange'] ) ? (string) ( $dv[ $dh['dateRange'] ] ?? '' ) : 'cur';
			$out[] = array(
				'd'    => $d,
				'm'    => $m,
				'prev' => in_array( $range, array( 'prev', 'date_range_1' ), true ),
			);
		}
		return $out;
	}

	/** Whole numbers stay integers in the JSON; ratios and averages stay floats. */
	private static function number( $v, $type ) {
		if ( 'TYPE_INTEGER' === $type || ( '' === $type && is_numeric( $v ) && false === strpbrk( (string) $v, '.eE' ) ) ) {
			return (int) round( (float) $v );
		}
		return (float) $v;
	}

	private static function totals( array $rows, array $metrics, $compare ) {
		$out = array();
		foreach ( $metrics as $metric ) {
			// No row back means nothing happened: Google leaves empty rows out.
			$out[ $metric ] = array( 'value' => 0, 'prev' => $compare ? 0 : null );
		}
		foreach ( $rows as $r ) {
			foreach ( $metrics as $n => $metric ) {
				if ( $r['prev'] ) {
					if ( $compare ) {
						$out[ $metric ]['prev'] = $r['m'][ $n ];
					}
				} else {
					$out[ $metric ]['value'] = $r['m'][ $n ];
				}
			}
		}
		return $out;
	}

	private static function table( array $req, array $res, array $rows, array $dims, array $metrics, $compare ) {
		$found    = array();
		$cur_rows = 0;
		foreach ( $rows as $order => $r ) {
			$cur_rows += $r['prev'] ? 0 : 1;
			if ( '' === $r['d'][0] || '(not set)' === $r['d'][0] ) {
				continue;
			}
			$d = array_map(
				function ( $v ) {
					return '(not set)' === $v ? '' : $v;
				},
				$r['d']
			);
			$k = implode( "\x1f", $d );
			if ( ! isset( $found[ $k ] ) ) {
				$found[ $k ] = array( 'd' => $d, 'm' => null, 'p' => null, 'order' => $order );
			}
			$found[ $k ][ $r['prev'] ? 'p' : 'm' ] = $r['m'];
		}
		$count    = count( $rows );
		$google   = isset( $res['rowCount'] ) ? (int) $res['rowCount'] : $count;
		$complete = $count >= $google;
		// Rows of the previous period alone are not in the current top list.
		$found = array_values(
			array_filter(
				$found,
				function ( $f ) {
					return null !== $f['m'];
				}
			)
		);
		$sort = array_search( $req['sort'], $metrics, true );
		if ( false !== $sort ) {
			usort(
				$found,
				function ( $a, $b ) use ( $sort ) {
					return array( $b['m'][ $sort ], $a['order'] ) <=> array( $a['m'][ $sort ], $b['order'] );
				}
			);
		}
		if ( $req['limit'] > 0 ) {
			$found = array_slice( $found, 0, $req['limit'] );
		}
		$table = array();
		foreach ( $found as $f ) {
			$row = array( 'd' => $f['d'], 'm' => $f['m'] );
			if ( $compare ) {
				// Absent from a complete answer means zero; from a cut one, unknown.
				$row['p'] = $f['p'] ?? array_fill( 0, count( $metrics ), $complete ? 0 : null );
			}
			$table[] = $row;
		}
		if ( ! $compare ) {
			$total = $google;
		} elseif ( $complete ) {
			$total = $cur_rows;
		} else {
			// Google counts both periods together; estimate the current period's share.
			$total = max( count( $table ), (int) round( $google * $cur_rows / max( 1, $count ) ) );
		}
		return array(
			'dims'       => $dims,
			'metrics'    => $metrics,
			'rows'       => $table,
			'sort'       => $req['sort'],
			'total_rows' => $total,
		);
	}

	public function snapshot_labels(): array {
		$labels = array(
			'activeUsers'                           => __( 'Active users', 'marsad' ),
			'totalUsers'                            => __( 'Total users', 'marsad' ),
			'newUsers'                              => __( 'New users', 'marsad' ),
			'sessions'                              => __( 'Sessions', 'marsad' ),
			'engagedSessions'                       => __( 'Engaged sessions', 'marsad' ),
			'engagementRate'                        => __( 'Engagement rate', 'marsad' ),
			'bounceRate'                            => __( 'Bounce rate', 'marsad' ),
			'screenPageViews'                       => __( 'Views', 'marsad' ),
			'screenPageViewsPerSession'             => __( 'Views per session', 'marsad' ),
			'averageSessionDuration'                => __( 'Average session duration', 'marsad' ),
			'userEngagementDuration'                => __( 'Engagement time', 'marsad' ),
			'eventCount'                            => __( 'Event count', 'marsad' ),
			'keyEvents'                             => __( 'Key events', 'marsad' ),
			'sessionsPerUser'                       => __( 'Sessions per user', 'marsad' ),
			'pagePath'                              => __( 'Page', 'marsad' ),
			'pageTitle'                             => __( 'Page title', 'marsad' ),
			'landingPage'                           => __( 'Landing page', 'marsad' ),
			'sessionDefaultChannelGroup'            => __( 'Channel', 'marsad' ),
			'sessionSourceMedium'                   => __( 'Source / medium', 'marsad' ),
			'sessionCampaignName'                   => __( 'Campaign', 'marsad' ),
			'sessionSource'                         => __( 'Source', 'marsad' ),
			'country'                               => __( 'Country', 'marsad' ),
			'city'                                  => __( 'City', 'marsad' ),
			'language'                              => __( 'Language', 'marsad' ),
			'deviceCategory'                        => __( 'Device', 'marsad' ),
			'operatingSystem'                       => __( 'Operating system', 'marsad' ),
			'browser'                               => __( 'Browser', 'marsad' ),
			'mobileDeviceModel'                     => __( 'Device model', 'marsad' ),
			'screenResolution'                      => __( 'Screen resolution', 'marsad' ),
			'newVsReturning'                        => __( 'New or returning', 'marsad' ),
			'userAgeBracket'                        => __( 'Age', 'marsad' ),
			'userGender'                            => __( 'Gender', 'marsad' ),
			'dayOfWeek'                             => __( 'Day of week', 'marsad' ),
			'hour'                                  => __( 'Hour', 'marsad' ),
			'eventName'                             => __( 'Event', 'marsad' ),
			'searchTerm'                            => __( 'Search term', 'marsad' ),
			'linkUrl'                               => __( 'Link', 'marsad' ),
			'unifiedScreenName'                     => __( 'Page or screen', 'marsad' ),
			'customEvent:googlesitekit_post_author' => __( 'Author', 'marsad' ),
			'customEvent:category'                  => __( 'Category', 'marsad' ),
			'customEvent:googlesitekit_post_date'   => __( 'Publish date', 'marsad' ),
			'customEvent:percentage'                => __( 'Scroll depth (%)', 'marsad' ),
		);
		// Video metrics are the property's own; Google's name for them is the best label there is.
		$meta = get_transient( self::META . $this->property() );
		foreach ( (array) ( is_array( $meta ) ? ( $meta['metrics'] ?? array() ) : array() ) as $api => $name ) {
			$labels[ $api ] = (string) $name;
		}
		return $labels;
	}

	/** GA4 gives country and city names, not codes; the rest is in Marsad_Snapshots::FORMATS. */
	public function snapshot_formats(): array {
		return array(
			'country'                             => 'text',
			'city'                                => 'text',
			'pageTitle'                           => 'text',
			'unifiedScreenName'                   => 'text',
			'customEvent:googlesitekit_post_date' => 'text',
		);
	}

	/**
	 * Who is on the site right now (last 30 minutes), cached a minute so polling dashboards
	 * share one answer.
	 *
	 * @return array|null array( 'active' => int, 'pages', 'countries', 'devices' => list of
	 *                    array( 'name' => string, 'users' => int ), 'at' => unix time ); null on error
	 */
	public function realtime() {
		$property = $this->property();
		if ( '' === $property ) {
			return null;
		}
		$key = self::REALTIME . $property;
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return is_array( $hit ) && isset( $hit['active'] ) ? $hit : null;
		}
		try {
			$auth = Marsad_Google_Account::bearer( $this->get( 'service_account' ), self::SCOPE );
			$run  = function ( $dim, $limit ) use ( $property, $auth ) {
				$body = array( 'metrics' => array( array( 'name' => 'activeUsers' ) ) );
				if ( '' !== $dim ) {
					$body['dimensions'] = array( array( 'name' => $dim ) );
					$body['orderBys']   = array( array( 'metric' => array( 'metricName' => 'activeUsers' ), 'desc' => true ) );
					$body['limit']      = $limit;
				}
				return Marsad_Http::json( 'POST', self::API . $property . ':runRealtimeReport', $body, $auth );
			};
			$total = $run( '', 0 );
			$out   = array(
				'active'    => (int) ( $total['rows'][0]['metricValues'][0]['value'] ?? 0 ),
				'pages'     => self::realtime_rows( $run( 'unifiedScreenName', 10 ) ),
				'countries' => self::realtime_rows( $run( 'country', 10 ) ),
				'devices'   => self::realtime_rows( $run( 'deviceCategory', 10 ) ),
				'at'        => time(),
			);
		} catch ( Marsad_Exception $e ) {
			// Failures are cached too, so a broken connection is not asked again on every poll.
			set_transient( $key, array(), MINUTE_IN_SECONDS );
			return null;
		}
		set_transient( $key, $out, MINUTE_IN_SECONDS );
		return $out;
	}

	private static function realtime_rows( array $data ) {
		$out = array();
		foreach ( (array) ( $data['rows'] ?? array() ) as $r ) {
			$out[] = array(
				'name'  => (string) ( $r['dimensionValues'][0]['value'] ?? '' ),
				'users' => (int) ( $r['metricValues'][0]['value'] ?? 0 ),
			);
		}
		return $out;
	}

	private function property() {
		return preg_replace( '/\D/', '', (string) $this->get( 'property_id' ) );
	}
}
