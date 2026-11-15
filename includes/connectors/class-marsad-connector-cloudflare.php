<?php
/**
 * Cloudflare: everything the zone's Analytics page shows, day by day, from the GraphQL
 * Analytics API (httpRequests1dGroups): requests, page views, unique visitors, data served,
 * cache share, encrypted share, threats, 4xx/5xx, and breakdowns by country, status code,
 * content type, browser, HTTP version, TLS version, visitor type and threat kind.
 *
 * Cloudflare only counts traffic it proxies: a hostname set to "DNS only" (grey cloud) shows
 * almost nothing here, whatever the site's real traffic.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Cloudflare extends Marsad_Connector {

	/** host typed or derived → zone id, so the zone is looked up once. */
	const OPT_ZONES = 'marsad_cloudflare_zones';
	const API       = 'https://api.cloudflare.com/client/v4';
	/** Days per query: Cloudflare refuses ranges over 52 weeks; smaller parts keep answers small. */
	const PART = 180;

	public function id() {
		return 'cloudflare';
	}

	public function label() {
		return __( 'Cloudflare', 'marsad' );
	}

	public function group() {
		return 'web';
	}

	public function description() {
		return __( 'Everything Cloudflare\'s analytics shows for your domain: requests, visitors, data served, cache, threats, errors, countries, browsers and more.', 'marsad' );
	}

	public function fields() {
		return array(
			'token' => array(
				'label'    => __( 'API token', 'marsad' ),
				'type'     => 'secret',
				'required' => true,
				'help'     => __( 'A token with Zone → Analytics → Read and Zone → Zone → Read.', 'marsad' ),
			),
			'zone'  => array(
				'label' => __( 'Domain', 'marsad' ),
				'type'  => 'text',
				'help'  => __( 'Leave empty to use this site\'s domain. A subdomain finds its main domain\'s zone by itself.', 'marsad' ),
			),
		);
	}

	public function instructions() {
		return array(
			$this->step( __( 'In Cloudflare open My Profile → API Tokens → Create Token, and pick the template "Read analytics and logs" (or a custom token with Zone → Analytics → Read and Zone → Zone → Read) for your domain.', 'marsad' ), 'https://dash.cloudflare.com/profile/api-tokens', __( 'Open API tokens', 'marsad' ) ),
			__( 'Paste the token here and save. A year of history is imported on the first sync.', 'marsad' ),
			__( 'Cloudflare only counts traffic that passes through it: in DNS the site\'s records must be "Proxied" (orange cloud), not "DNS only".', 'marsad' ),
		);
	}

	public function metrics() {
		return array(
			'requests'         => array( 'label' => __( 'Requests', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'page_views'       => array( 'label' => __( 'Page views', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'visitors'         => array( 'label' => __( 'Unique visitors a day (avg)', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'chart' => true ),
			'bandwidth_gb'     => array( 'label' => __( 'Data served (GB)', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'format' => 'decimal' ),
			'cached_pct'       => array( 'label' => __( 'Requests served from cache', 'marsad' ), 'agg' => 'avg', 'kpi' => true, 'format' => 'percent' ),
			'cached_bytes_pct' => array( 'label' => __( 'Data served from cache', 'marsad' ), 'agg' => 'avg', 'format' => 'percent' ),
			'encrypted_pct'    => array( 'label' => __( 'Encrypted requests (HTTPS)', 'marsad' ), 'agg' => 'avg', 'format' => 'percent' ),
			'threats'          => array( 'label' => __( 'Threats stopped', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'better' => 'down' ),
			'errors_4xx'       => array( 'label' => __( 'Client errors (4xx)', 'marsad' ), 'agg' => 'sum', 'better' => 'down' ),
			'errors_5xx'       => array( 'label' => __( 'Server errors (5xx)', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'better' => 'down' ),
		);
	}

	public function tables() {
		return array(
			'requests_by_country'      => array( 'label' => __( 'Requests by country', 'marsad' ), 'agg' => 'sum', 'limit' => 30 ),
			'bandwidth_by_country'     => array( 'label' => __( 'Data served by country (GB)', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'threats_by_country'       => array( 'label' => __( 'Threats by country', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'requests_by_status'       => array( 'label' => __( 'Requests by response code', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'requests_by_content_type' => array( 'label' => __( 'Requests by content type', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'pageviews_by_browser'     => array( 'label' => __( 'Page views by browser', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'requests_by_visitor_type' => array( 'label' => __( 'Requests by visitor type', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'threats_by_kind'          => array( 'label' => __( 'Threats by kind', 'marsad' ), 'agg' => 'sum', 'limit' => 20 ),
			'requests_by_http_version' => array( 'label' => __( 'Requests by HTTP version', 'marsad' ), 'agg' => 'sum', 'limit' => 10 ),
			'requests_by_tls'          => array( 'label' => __( 'Requests by TLS version', 'marsad' ), 'agg' => 'sum', 'limit' => 10 ),
		);
	}

	public function window_days() {
		return 3;
	}

	public function max_backfill() {
		return 364;
	}

	public function dim_label( $table, $dim ) {
		if ( in_array( $table, array( 'requests_by_country', 'bandwidth_by_country', 'threats_by_country' ), true ) ) {
			return self::country( $dim );
		}
		if ( 'requests_by_visitor_type' === $table ) {
			$types = array(
				'noRecord'          => __( 'Regular visitors', 'marsad' ),
				'searchEngine'      => __( 'Search engines', 'marsad' ),
				'monitoringService' => __( 'Monitoring services', 'marsad' ),
				'whitelist'         => __( 'Allow-listed', 'marsad' ),
				'backupService'     => __( 'Backup services', 'marsad' ),
				'securityScanner'   => __( 'Security scanners', 'marsad' ),
				'badHost'           => __( 'Known bad hosts', 'marsad' ),
				'tor'               => __( 'Tor network', 'marsad' ),
			);
			return $types[ $dim ] ?? $dim;
		}
		return $dim;
	}

	/** A country's name in the admin's language from its two-letter code (Cloudflare sends codes). */
	public static function country( $code ) {
		$code = strtoupper( (string) $code );
		if ( 'XX' === $code || 'T1' === $code ) {
			return 'T1' === $code ? __( 'Tor network', 'marsad' ) : __( 'Unknown', 'marsad' );
		}
		if ( class_exists( 'Locale' ) && preg_match( '/^[A-Z]{2}$/', $code ) ) {
			$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : 'en';
			$name   = Locale::getDisplayRegion( 'und-' . $code, $locale );
			if ( $name && $name !== $code ) {
				return $name;
			}
		}
		return $code;
	}

	/**
	 * @throws Marsad_Exception
	 */
	public function fetch( $from, $to ) {
		$zone = $this->zone_id();
		$rows = array();
		for ( $start = strtotime( $from . ' 12:00 UTC' ), $end = strtotime( $to . ' 12:00 UTC' ); $start <= $end; $start += ( self::PART + 1 ) * DAY_IN_SECONDS ) {
			$part_end = min( $end, $start + self::PART * DAY_IN_SECONDS );
			foreach ( $this->days( $zone, gmdate( 'Y-m-d', $start ), gmdate( 'Y-m-d', $part_end ) ) as $g ) {
				$rows = array_merge( $rows, $this->rows_of_day( $g ) );
			}
		}
		return $rows;
	}

	/** Every number of one day. */
	private function rows_of_day( array $g ) {
		$day  = (string) ( $g['dimensions']['date'] ?? '' );
		$s    = (array) ( $g['sum'] ?? array() );
		$req  = (float) ( $s['requests'] ?? 0 );
		$out  = array(
			$this->row( 'requests', $day, $req ),
			$this->row( 'page_views', $day, $s['pageViews'] ?? 0 ),
			$this->row( 'visitors', $day, $g['uniq']['uniques'] ?? 0 ),
			$this->row( 'bandwidth_gb', $day, ( $s['bytes'] ?? 0 ) / 1e9 ),
			$this->row( 'threats', $day, $s['threats'] ?? 0 ),
		);
		if ( $req > 0 ) {
			$out[] = $this->row( 'cached_pct', $day, 100 * ( $s['cachedRequests'] ?? 0 ) / $req );
			$out[] = $this->row( 'encrypted_pct', $day, 100 * ( $s['encryptedRequests'] ?? 0 ) / $req );
		}
		if ( ! empty( $s['bytes'] ) ) {
			$out[] = $this->row( 'cached_bytes_pct', $day, 100 * ( $s['cachedBytes'] ?? 0 ) / $s['bytes'] );
		}
		$e4 = 0;
		$e5 = 0;
		foreach ( (array) ( $s['responseStatusMap'] ?? array() ) as $r ) {
			$code = (int) ( $r['edgeResponseStatus'] ?? 0 );
			$e4  += ( $code >= 400 && $code < 500 ) ? (float) $r['requests'] : 0;
			$e5  += ( $code >= 500 && $code < 600 ) ? (float) $r['requests'] : 0;
		}
		$out[] = $this->row( 'errors_4xx', $day, $e4 );
		$out[] = $this->row( 'errors_5xx', $day, $e5 );

		// table => array( map, dimension field, value field, divisor )
		$maps = array(
			'requests_by_country'      => array( 'countryMap', 'clientCountryName', 'requests', 1 ),
			'bandwidth_by_country'     => array( 'countryMap', 'clientCountryName', 'bytes', 1e9 ),
			'threats_by_country'       => array( 'countryMap', 'clientCountryName', 'threats', 1 ),
			'requests_by_status'       => array( 'responseStatusMap', 'edgeResponseStatus', 'requests', 1 ),
			'requests_by_content_type' => array( 'contentTypeMap', 'edgeResponseContentTypeName', 'requests', 1 ),
			'pageviews_by_browser'     => array( 'browserMap', 'uaBrowserFamily', 'pageViews', 1 ),
			'requests_by_visitor_type' => array( 'ipClassMap', 'ipType', 'requests', 1 ),
			'threats_by_kind'          => array( 'threatPathingMap', 'threatPathingName', 'requests', 1 ),
			'requests_by_http_version' => array( 'clientHTTPVersionMap', 'clientHTTPProtocol', 'requests', 1 ),
			'requests_by_tls'          => array( 'clientSSLMap', 'clientSSLProtocol', 'requests', 1 ),
		);
		foreach ( $maps as $table => $m ) {
			foreach ( (array) ( $s[ $m[0] ] ?? array() ) as $r ) {
				$v   = (float) ( $r[ $m[2] ] ?? 0 ) / $m[3];
				$dim = trim( (string) ( $r[ $m[1] ] ?? '' ) );
				if ( $v > 0 && '' !== $dim ) {
					$out[] = $this->row( $table, $day, $v, mb_substr( $dim, 0, 120 ) );
				}
			}
		}
		return $out;
	}

	/**
	 * One GraphQL query for a range of at most PART days.
	 *
	 * @throws Marsad_Exception
	 */
	private function days( $zone, $from, $to ) {
		$query = 'query($zone:String!,$from:Date!,$to:Date!){viewer{zones(filter:{zoneTag:$zone}){httpRequests1dGroups(limit:400,filter:{date_geq:$from,date_leq:$to},orderBy:[date_ASC]){'
			. 'dimensions{date} uniq{uniques} sum{requests cachedRequests bytes cachedBytes pageViews threats encryptedRequests encryptedBytes '
			. 'countryMap{clientCountryName requests bytes threats} responseStatusMap{edgeResponseStatus requests} '
			. 'contentTypeMap{edgeResponseContentTypeName requests bytes} browserMap{uaBrowserFamily pageViews} '
			. 'clientHTTPVersionMap{clientHTTPProtocol requests} clientSSLMap{clientSSLProtocol requests} '
			. 'ipClassMap{ipType requests} threatPathingMap{threatPathingName requests}}}}}}';
		$res   = Marsad_Http::json(
			'POST',
			self::API . '/graphql',
			array(
				'query'     => $query,
				'variables' => array( 'zone' => $zone, 'from' => $from, 'to' => $to ),
			),
			$this->auth()
		);
		if ( ! empty( $res['errors'] ) ) {
			$msg = (string) ( $res['errors'][0]['message'] ?? 'unknown error' );
			/* translators: %s: Cloudflare's error message */
			throw new Marsad_Exception( sprintf( __( 'Cloudflare refused the analytics query: %s. The token needs Zone → Analytics → Read for this domain.', 'marsad' ), $msg ) );
		}
		return (array) ( $res['data']['viewer']['zones'][0]['httpRequests1dGroups'] ?? array() );
	}

	/**
	 * The zone id for the typed domain (or this site's), looked up once. A subdomain falls
	 * back to its parent domains: en.example.com lives in the example.com zone.
	 *
	 * @throws Marsad_Exception
	 */
	private function zone_id() {
		$host  = $this->host();
		$cache = get_option( self::OPT_ZONES, array() );
		$cache = is_array( $cache ) ? $cache : array();
		if ( ! empty( $cache[ $host ] ) ) {
			return $cache[ $host ];
		}
		$parts = explode( '.', $host );
		while ( count( $parts ) >= 2 ) {
			$res = Marsad_Http::json( 'GET', self::API . '/zones?' . http_build_query( array( 'name' => implode( '.', $parts ) ) ), null, $this->auth() );
			if ( ! empty( $res['result'][0]['id'] ) ) {
				$cache[ $host ] = (string) $res['result'][0]['id'];
				update_option( self::OPT_ZONES, $cache, false );
				return $cache[ $host ];
			}
			array_shift( $parts );
		}
		/* translators: %s: domain name */
		throw new Marsad_Exception( sprintf( __( 'This token sees no Cloudflare zone for %s. Check the domain, and that the token includes Zone → Zone → Read for it.', 'marsad' ), $host ) );
	}

	/** The domain to report on, bare: no scheme, path or leading www. */
	private function host() {
		$typed = trim( (string) $this->get( 'zone' ) );
		$host  = '' !== $typed ? ( wp_parse_url( false === strpos( $typed, '//' ) ? 'https://' . $typed : $typed, PHP_URL_HOST ) ?: $typed ) : wp_parse_url( home_url(), PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
	}

	private function auth() {
		return array( 'Authorization' => 'Bearer ' . trim( (string) $this->get( 'token' ) ) );
	}
}
