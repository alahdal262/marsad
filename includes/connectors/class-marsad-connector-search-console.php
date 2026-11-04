<?php
/**
 * Google Search Console: how the site appears in Google Search.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Search_Console extends Marsad_Connector {

	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	const API = 'https://www.googleapis.com/webmasters/v3/sites/';

	/** Search types with their own totals; discover and googleNews accept no query dimension, so totals only. */
	const TYPES = array( 'web', 'image', 'video', 'news', 'discover', 'googleNews' );

	/** Metric order of every Search Analytics table. */
	const METRICS = array( 'clicks', 'impressions', 'ctr', 'position' );

	/**
	 * Rows asked for when a table is kept shorter: the API's maximum, so total_rows tells how many
	 * queries there really were, previous values are found for every kept row, and opportunities
	 * and movers see the long tail rather than the top only.
	 */
	const WIDE = 25000;

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

	/** Search Console keeps 16 months; 'all' asks for exactly that. */
	public function history_start() {
		return gmdate( 'Y-m-d', time() - 486 * DAY_IN_SECONDS );
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
			// 'all' includes the fresh, not yet final days, as the Search Console UI does; the window re-fetches them later.
			$total = Marsad_Http::json( 'POST', $url, array( 'startDate' => $from, 'endDate' => $to, 'dimensions' => array( 'date' ), 'dataState' => 'all', 'rowLimit' => 25000 ), $auth );
			$query = Marsad_Http::json( 'POST', $url, array( 'startDate' => $from, 'endDate' => $to, 'dimensions' => array( 'date', 'query' ), 'dataState' => 'all', 'rowLimit' => 25000 ), $auth );
			$page  = Marsad_Http::json( 'POST', $url, array( 'startDate' => $from, 'endDate' => $to, 'dimensions' => array( 'date', 'page' ), 'dataState' => 'all', 'rowLimit' => 25000 ), $auth );
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

	public function supports_snapshots(): bool {
		return true;
	}

	/**
	 * Google's own answer for the whole range: weighted CTR and position, every query instead of
	 * the top 50 of each day, all search types, and what the queries say about opportunities.
	 *
	 * @throws Marsad_Exception when even the web totals cannot be read (nothing else would work)
	 */
	public function snapshots( $range_key, array $range ): array {
		$site = trim( (string) $this->get( 'site' ) );
		if ( '' === $site ) {
			throw new Marsad_Exception( __( 'Property ID is missing.', 'marsad' ) );
		}
		$base = self::API . rawurlencode( $site );
		try {
			$auth = Marsad_Google_Account::bearer( $this->get( 'service_account' ), self::SCOPE );
			$web  = $this->totals( $base, $auth, $range, 'web' );
		} catch ( Marsad_Exception $e ) {
			throw new Marsad_Exception( Marsad_Google_Auth::explain( $e, 'Google Search Console API', 'searchconsole.googleapis.com' ), (int) $e->getCode() );
		}
		// The generic renderer looks for 'totals'; web search is what the Search Console UI opens on.
		$out = array(
			'totals'     => $web,
			'totals_web' => $web,
		);

		// From here on a report that fails is noted and dropped; the others still arrive.
		foreach ( array_slice( self::TYPES, 1 ) as $type ) {
			$this->attempt(
				$range_key,
				'totals_' . $type,
				function () use ( &$out, $base, $auth, $range, $type ) {
					$out[ 'totals_' . $type ] = $this->totals( $base, $auth, $range, $type );
				}
			);
		}
		$this->attempt(
			$range_key,
			'queries',
			function () use ( &$out, $base, $auth, $range ) {
				list( $cur, $old ) = $this->pair( $base, $auth, $range, array( 'query' ), self::WIDE );
				$out['queries']       = self::top( $cur, 1000 );
				$out['opportunities'] = self::opportunities( $cur );
				if ( null !== $old ) {
					// Queries that had clicks and are gone now fell the furthest; they are only known when nothing was cut off.
					$lost           = count( $cur['rows'] ) < self::WIDE ? self::lost( $cur, $old ) : array();
					$cur['rows']    = array_merge( $cur['rows'], $lost );
					$movers         = self::movers( $cur );
					$out['rising']  = $movers['rising'];
					$out['falling'] = $movers['falling'];
				}
			}
		);
		$tables = array(
			// Only 500 pages are kept; 5,000 still finds their previous values without a 25,000-row answer in memory.
			'pages'      => array( 'page', 5000, 500 ),
			'countries'  => array( 'country', 250, 250 ),
			'devices'    => array( 'device', 10, 10 ),
			'appearance' => array( 'searchAppearance', 100, 100 ),
		);
		foreach ( $tables as $report => $t ) {
			$this->attempt(
				$range_key,
				$report,
				function () use ( &$out, $base, $auth, $range, $report, $t ) {
					$out[ $report ] = self::top( $this->pair( $base, $auth, $range, array( $t[0] ), $t[1] )[0], $t[2] );
				}
			);
		}
		$this->attempt(
			$range_key,
			'query_pages',
			function () use ( &$out, $base, $auth, $range ) {
				$out['query_pages'] = self::parse_rows( $this->query( $base, $auth, $range['from'], $range['to'], array( 'query', 'page' ), 'web', 1000 ), array( 'query', 'page' ) );
			}
		);
		// Sitemaps have no dates; stored under every range so each range is complete on its own.
		$this->attempt(
			$range_key,
			'sitemaps',
			function () use ( &$out, $base, $auth ) {
				$out['sitemaps'] = self::sitemaps( Marsad_Http::json( 'GET', $base . '/sitemaps', null, $auth ) );
			}
		);
		return $out;
	}

	public function snapshot_labels(): array {
		return array(
			'clicks'            => __( 'Search clicks', 'marsad' ),
			'impressions'       => __( 'Search impressions', 'marsad' ),
			'ctr'               => __( 'Click-through rate', 'marsad' ),
			'position'          => __( 'Average position', 'marsad' ),
			'potential_clicks'  => __( 'Potential extra clicks', 'marsad' ),
			'clicks_change'     => __( 'Change in clicks', 'marsad' ),
			'query'             => __( 'Query', 'marsad' ),
			'page'              => __( 'Page', 'marsad' ),
			'country'           => __( 'Country', 'marsad' ),
			'device'            => __( 'Device', 'marsad' ),
			'searchAppearance'  => __( 'Search appearance', 'marsad' ),
			'path'              => __( 'Sitemap', 'marsad' ),
			'lastDownloaded'    => __( 'Last read by Google', 'marsad' ),
			'submitted'         => __( 'Submitted', 'marsad' ),
			'indexed'           => __( 'Indexed', 'marsad' ),
			'errors'            => __( 'Errors', 'marsad' ),
			'warnings'          => __( 'Warnings', 'marsad' ),
			// Report names, so every screen that lists reports names them the same way.
			'totals_web'        => __( 'Web search', 'marsad' ),
			'totals_image'      => __( 'Image search', 'marsad' ),
			'totals_video'      => __( 'Video search', 'marsad' ),
			'totals_news'       => __( 'News search', 'marsad' ),
			'totals_discover'   => __( 'Discover', 'marsad' ),
			'totals_googleNews' => __( 'Google News', 'marsad' ),
			'queries'           => __( 'Top search queries', 'marsad' ),
			'pages'             => __( 'Pages clicked from Google', 'marsad' ),
			'countries'         => __( 'Search by country', 'marsad' ),
			'devices'           => __( 'Search by device', 'marsad' ),
			'appearance'        => __( 'Search appearance', 'marsad' ),
			'query_pages'       => __( 'Which page answers which query', 'marsad' ),
			'opportunities'     => __( 'Search opportunities', 'marsad' ),
			'rising'            => __( 'Rising queries', 'marsad' ),
			'falling'           => __( 'Falling queries', 'marsad' ),
			'sitemaps'          => __( 'Sitemaps', 'marsad' ),
		);
	}

	/** Search Console countries are ISO-3 codes (GA4's are names), and sitemap paths are links. */
	public function snapshot_formats(): array {
		return array(
			'country' => 'country',
			'path'    => 'link',
		);
	}

	/**
	 * Run one report; if it fails, note why against the range and carry on with the rest.
	 * Throwable, as in Marsad_Snapshots::refresh(): an unexpected answer shape costs one report, not the range.
	 */
	private function attempt( $range_key, $report, callable $fn ) {
		try {
			$fn();
		} catch ( Throwable $e ) {
			$labels = $this->snapshot_labels();
			Marsad_Snapshots::record_error( $this->id(), $range_key, ( $labels[ $report ] ?? $report ) . ': ' . $e->getMessage() );
		}
	}

	/** One Search Analytics request. */
	private function query( $base, array $auth, $from, $to, array $dims, $type, $limit ) {
		$body = array(
			'startDate'       => $from,
			'endDate'         => $to,
			'type'            => $type,
			'dataState'       => 'all',
			'aggregationType' => 'auto',
			'rowLimit'        => (int) $limit,
		);
		if ( $dims ) {
			$body['dimensions'] = $dims;
		}
		return Marsad_Http::json( 'POST', $base . '/searchAnalytics/query', $body, $auth );
	}

	private function totals( $base, array $auth, array $range, $type ) {
		$cur  = $this->query( $base, $auth, $range['from'], $range['to'], array(), $type, 1 );
		$prev = self::has_previous( $range ) ? $this->query( $base, $auth, $range['prev_from'], $range['prev_to'], array(), $type, 1 ) : null;
		return self::totals_payload( $cur, $prev );
	}

	/**
	 * A web table for the range and, when the range has one, the same table for the previous
	 * period mapped onto it as 'p'.
	 *
	 * @return array array( table, previous table or null )
	 */
	private function pair( $base, array $auth, array $range, array $dims, $limit ) {
		$cur = self::parse_rows( $this->query( $base, $auth, $range['from'], $range['to'], $dims, 'web', $limit ), $dims );
		if ( count( $cur['rows'] ) >= $limit ) {
			// Google stopped at the limit: there are more rows than it returned, so the count is a floor.
			$cur['total_more'] = true;
		}
		if ( ! self::has_previous( $range ) ) {
			return array( $cur, null );
		}
		$old = self::parse_rows( $this->query( $base, $auth, $range['prev_from'], $range['prev_to'], $dims, 'web', $limit ), $dims );
		return array( self::with_previous( $cur, $old, count( $old['rows'] ) < $limit ), $old );
	}

	private static function has_previous( array $range ) {
		return ! empty( $range['prev_from'] ) && ! empty( $range['prev_to'] );
	}

	/**
	 * Totals payload from two answers without dimensions. A period with no rows had no clicks
	 * and no impressions, but its CTR and position are unknown rather than zero.
	 *
	 * @param array      $current  searchAnalytics answer for the range
	 * @param array|null $previous the same for the previous period, null when there is none
	 */
	public static function totals_payload( array $current, ?array $previous ): array {
		$read = function ( $data ) {
			$r = $data['rows'][0] ?? null;
			return array(
				'clicks'      => $r ? (int) round( (float) ( $r['clicks'] ?? 0 ) ) : 0,
				'impressions' => $r ? (int) round( (float) ( $r['impressions'] ?? 0 ) ) : 0,
				'ctr'         => $r && isset( $r['ctr'] ) ? (float) $r['ctr'] : null,
				'position'    => $r && isset( $r['position'] ) ? (float) $r['position'] : null,
			);
		};
		$now  = $read( $current );
		$then = null === $previous ? null : $read( $previous );
		$out  = array();
		foreach ( self::METRICS as $m ) {
			$out[ $m ] = array(
				'value' => $now[ $m ],
				'prev'  => null === $then ? null : $then[ $m ],
			);
		}
		return array( 'metrics' => $out );
	}

	/**
	 * A searchAnalytics answer as a table payload, most clicks first. total_rows is the number
	 * of rows Google returned (the true count whenever it is under the row limit).
	 *
	 * @param array    $data searchAnalytics answer
	 * @param string[] $dims the dimensions asked for, in order
	 */
	public static function parse_rows( array $data, array $dims ): array {
		$rows = array();
		foreach ( $data['rows'] ?? array() as $r ) {
			$keys = array_map( 'strval', (array) ( $r['keys'] ?? array() ) );
			if ( count( $keys ) !== count( $dims ) ) {
				continue;
			}
			$rows[] = array(
				'd' => $keys,
				'm' => array(
					(int) round( (float) ( $r['clicks'] ?? 0 ) ),
					(int) round( (float) ( $r['impressions'] ?? 0 ) ),
					(float) ( $r['ctr'] ?? 0 ),
					(float) ( $r['position'] ?? 0 ),
				),
			);
		}
		$rows = self::sort_rows(
			$rows,
			function ( $r ) {
				return $r['m'][0];
			},
			true
		);
		return array(
			'dims'       => array_values( $dims ),
			'metrics'    => self::METRICS,
			'rows'       => $rows,
			'sort'       => 'clicks',
			'total_rows' => count( $rows ),
		);
	}

	/**
	 * Previous-period values of each row as 'p', matched by its dimensions. A row missing from a
	 * complete previous answer had nothing then (0 clicks, 0 impressions); missing from a cut-off
	 * answer it is unknown (null).
	 *
	 * @param bool $complete true when the previous answer was under the row limit
	 */
	public static function with_previous( array $table, array $previous, $complete ): array {
		$index = array();
		foreach ( $previous['rows'] ?? array() as $r ) {
			$index[ implode( "\x1f", $r['d'] ) ] = $r['m'];
		}
		$none = $complete ? array( 0, 0, null, null ) : array( null, null, null, null );
		foreach ( $table['rows'] as $i => $r ) {
			$table['rows'][ $i ]['p'] = $index[ implode( "\x1f", $r['d'] ) ] ?? $none;
		}
		return $table;
	}

	/**
	 * Rows of the previous period that had clicks and do not appear now: they lost everything.
	 * Only meaningful when the current answer was not cut off.
	 *
	 * @return array rows with 'm' = 0 clicks, 0 impressions and 'p' = what they had
	 */
	public static function lost( array $table, array $previous ): array {
		$now = array();
		foreach ( $table['rows'] as $r ) {
			$now[ implode( "\x1f", $r['d'] ) ] = true;
		}
		$out = array();
		foreach ( $previous['rows'] ?? array() as $r ) {
			if ( $r['m'][0] > 0 && ! isset( $now[ implode( "\x1f", $r['d'] ) ] ) ) {
				$out[] = array(
					'd' => $r['d'],
					'm' => array( 0, 0, null, null ),
					'p' => $r['m'],
				);
			}
		}
		return $out;
	}

	/** The first $n rows; total_rows still says how many there were. */
	public static function top( array $table, $n ): array {
		$table['rows'] = array_slice( $table['rows'], 0, (int) $n );
		return $table;
	}

	/**
	 * Queries that rank on page one or two but are clicked less than others at the same place.
	 *
	 * A row qualifies when its position is between 4 and 20, its impressions are at least
	 * max( 50, 1% of the top impressions ), and its CTR is below the median CTR of every row at
	 * the same rounded position. potential_clicks = impressions × the median CTR of rows at
	 * rounded positions 1–3, minus the clicks it already has, never below 0 (null when no row
	 * ranks that high, so nothing is invented). Most impressions first, 100 rows.
	 *
	 * @param array $queries_table table payload with clicks, impressions, ctr and position
	 */
	public static function opportunities( array $queries_table ): array {
		$dims    = $queries_table['dims'] ?? array( 'query' );
		$metrics = array_merge( self::METRICS, array( 'potential_clicks' ) );
		$empty   = array(
			'dims'       => $dims,
			'metrics'    => $metrics,
			'rows'       => array(),
			'sort'       => 'impressions',
			'total_rows' => 0,
		);
		$at = array();
		foreach ( self::METRICS as $m ) {
			$i = array_search( $m, $queries_table['metrics'] ?? array(), true );
			if ( false === $i ) {
				return $empty;
			}
			$at[ $m ] = $i;
		}
		$rows = $queries_table['rows'] ?? array();
		$top  = 0;
		foreach ( $rows as $r ) {
			$top = max( $top, (float) $r['m'][ $at['impressions'] ] );
		}
		$floor = max( 50, 0.01 * $top );
		// Peers are only queries seen often enough to judge: the long tail of one-off queries
		// would pull every median to 0.
		$ctrs = array();
		$best = array();
		foreach ( $rows as $r ) {
			if ( (float) $r['m'][ $at['impressions'] ] < $floor ) {
				continue;
			}
			$pos = (int) round( (float) $r['m'][ $at['position'] ] );
			$ctr = (float) $r['m'][ $at['ctr'] ];
			$ctrs[ $pos ][] = $ctr;
			if ( $pos >= 1 && $pos <= 3 ) {
				$best[] = $ctr;
			}
		}
		$medians = array_map( array( __CLASS__, 'median' ), $ctrs );
		$best    = $best ? self::median( $best ) : null;
		$out     = array();
		foreach ( $rows as $r ) {
			$clicks = (float) $r['m'][ $at['clicks'] ];
			$impr   = (float) $r['m'][ $at['impressions'] ];
			$ctr    = (float) $r['m'][ $at['ctr'] ];
			$pos    = (float) $r['m'][ $at['position'] ];
			if ( $pos < 4 || $pos > 20 || $impr < $floor ) {
				continue;
			}
			$median = $medians[ (int) round( $pos ) ] ?? 0.0;
			// Below its peers; where peers get no clicks either, an unclicked query is still the opportunity.
			if ( ! ( $ctr < $median || ( 0.0 === (float) $median && 0.0 === $clicks ) ) ) {
				continue;
			}
			$out[] = array(
				'd' => $r['d'],
				'm' => array(
					$r['m'][ $at['clicks'] ],
					$r['m'][ $at['impressions'] ],
					$r['m'][ $at['ctr'] ],
					$r['m'][ $at['position'] ],
					null === $best ? null : (int) round( max( 0, $impr * $best - $clicks ) ),
				),
			);
		}
		$out = self::sort_rows(
			$out,
			function ( $r ) {
				return (float) $r['m'][1];
			},
			true
		);
		$empty['rows']       = array_slice( $out, 0, 100 );
		$empty['total_rows'] = count( $out );
		return $empty;
	}

	/**
	 * Queries whose clicks moved most against the previous period. Rising: change > 0, largest
	 * first (new queries, with 0 clicks before, included). Falling: change < 0, largest drop
	 * first. 50 rows each, with 'clicks_change' added as the last metric. Rows whose previous
	 * clicks are unknown are left out.
	 *
	 * @param array $queries_table table payload whose rows carry 'p'
	 * @return array array( 'rising' => table, 'falling' => table )
	 */
	public static function movers( array $queries_table ): array {
		$metrics = $queries_table['metrics'] ?? array();
		$table   = array(
			'dims'       => $queries_table['dims'] ?? array( 'query' ),
			'metrics'    => array_merge( $metrics, array( 'clicks_change' ) ),
			'rows'       => array(),
			'sort'       => 'clicks_change',
			'total_rows' => 0,
		);
		$out = array(
			'rising'  => $table,
			'falling' => $table,
		);
		$ci = array_search( 'clicks', $metrics, true );
		if ( false === $ci ) {
			return $out;
		}
		$up   = array();
		$down = array();
		foreach ( $queries_table['rows'] ?? array() as $r ) {
			if ( ! isset( $r['p'][ $ci ], $r['m'][ $ci ] ) ) {
				continue;
			}
			$delta = $r['m'][ $ci ] - $r['p'][ $ci ];
			if ( 0 == $delta ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- 0 and 0.0 alike.
				continue;
			}
			$row        = $r;
			$row['m'][] = $delta;
			$row['p'][] = null;
			if ( $delta > 0 ) {
				$up[] = $row;
			} else {
				$down[] = $row;
			}
		}
		$change = function ( $r ) {
			return (float) end( $r['m'] );
		};
		foreach ( array( 'rising' => array( $up, true ), 'falling' => array( $down, false ) ) as $key => $list ) {
			$out[ $key ]['rows']       = array_slice( self::sort_rows( $list[0], $change, $list[1] ), 0, 50 );
			$out[ $key ]['total_rows'] = count( $list[0] );
		}
		return $out;
	}

	/**
	 * The sitemaps.list answer as a table. Submitted and indexed come from the 'web' entry of
	 * contents when there is one (images would inflate the page count), otherwise their sum;
	 * indexed is null when Google no longer reports it.
	 */
	public static function sitemaps( array $data ): array {
		$rows = array();
		foreach ( $data['sitemap'] ?? array() as $s ) {
			$path = (string) ( $s['path'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			$web = null;
			$sub = 0;
			$idx = null;
			foreach ( (array) ( $s['contents'] ?? array() ) as $c ) {
				if ( 'web' === ( $c['type'] ?? '' ) ) {
					$web = $c;
				}
				$sub += (int) ( $c['submitted'] ?? 0 );
				if ( isset( $c['indexed'] ) ) {
					$idx = (int) $idx + (int) $c['indexed'];
				}
			}
			if ( null !== $web ) {
				$sub = (int) ( $web['submitted'] ?? 0 );
				$idx = isset( $web['indexed'] ) ? (int) $web['indexed'] : null;
			}
			$when   = isset( $s['lastDownloaded'] ) ? strtotime( (string) $s['lastDownloaded'] ) : false;
			$rows[] = array(
				'd' => array( $path, $when ? gmdate( 'Y-m-d H:i', $when ) : '' ),
				'm' => array( $sub, $idx, (int) ( $s['errors'] ?? 0 ), (int) ( $s['warnings'] ?? 0 ) ),
			);
		}
		$rows = self::sort_rows(
			$rows,
			function ( $r ) {
				return $r['m'][0];
			},
			true
		);
		return array(
			'dims'       => array( 'path', 'lastDownloaded' ),
			'metrics'    => array( 'submitted', 'indexed', 'errors', 'warnings' ),
			'rows'       => $rows,
			'sort'       => 'submitted',
			'total_rows' => count( $rows ),
		);
	}

	/** Sort by one value, keeping the given order among equals (usort is not stable before PHP 8). */
	private static function sort_rows( array $rows, callable $value, $desc ) {
		$keyed = array();
		foreach ( array_values( $rows ) as $i => $r ) {
			$keyed[] = array( $value( $r ), $i, $r );
		}
		usort(
			$keyed,
			function ( $a, $b ) use ( $desc ) {
				$cmp = $desc ? $b[0] <=> $a[0] : $a[0] <=> $b[0];
				return 0 !== $cmp ? $cmp : $a[1] <=> $b[1];
			}
		);
		return array_column( $keyed, 2 );
	}

	private static function median( array $values ) {
		sort( $values );
		$n   = count( $values );
		$mid = intdiv( $n, 2 );
		return $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}
}
