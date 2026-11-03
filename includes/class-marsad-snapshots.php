<?php
/**
 * Range snapshots: each source's own report for a whole range (7, 28, 90, 365 days, all time),
 * stored as JSON. Daily rows cannot give range totals of non-additive numbers (deduplicated
 * users, weighted CTR and position) nor long tables, so the dashboard reads numbers and tables
 * from here and keeps the daily rows for charts.
 *
 * Payloads come in two shapes only, so one renderer draws them all:
 *   totals  array( 'metrics' => array( key => array( 'value' => n, 'prev' => n|null ) ) )
 *   table   array( 'dims' => [], 'metrics' => [], 'rows' => [ array( 'd' => [], 'm' => [], 'p' => [] ) ],
 *                  'sort' => key, 'total_rows' => n )
 *
 * Errors (option marsad_snapshot_errors): array( source => array( range_key => array( 'at', 'message' ) ) )
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Snapshots {

	const RANGES     = array( '7', '28', '90', '365', 'all' );
	const OPT_ERRORS = 'marsad_snapshot_errors';

	/** Seconds a range stays fresh: short ranges move fastest, so they are refreshed most often. */
	const TTL = array(
		'7'   => 3600,
		'28'  => 10800,
		'90'  => 43200,
		'365' => 86400,
		'all' => 86400,
	);

	/** Every format a metric or dimension can be shown in. */
	const FORMAT_TYPES = array( 'number', 'percent', 'decimal', 'seconds', 'duration', 'text', 'link', 'country' );

	/**
	 * How known keys are shown. Ratios are stored as Google returns them (0–1) and turned into
	 * percentages only when shown. "seconds" is a short time per unit (an average session),
	 * "duration" a long total (all engagement time). Unlisted metrics are numbers, unlisted
	 * dimensions text; connectors add their own through snapshot_formats().
	 */
	const FORMATS = array(
		'activeUsers'               => 'number',
		'totalUsers'                => 'number',
		'newUsers'                  => 'number',
		'sessions'                  => 'number',
		'engagedSessions'           => 'number',
		'engagementRate'            => 'percent',
		'bounceRate'                => 'percent',
		'screenPageViews'           => 'number',
		'screenPageViewsPerSession' => 'decimal',
		'averageSessionDuration'    => 'seconds',
		'userEngagementDuration'    => 'duration',
		'eventCount'                => 'number',
		'keyEvents'                 => 'number',
		'sessionsPerUser'           => 'decimal',
		'clicks'                    => 'number',
		'impressions'               => 'number',
		'ctr'                       => 'percent',
		'position'                  => 'decimal',
		'potential_clicks'          => 'number',
		'pagePath'                  => 'link',
		'landingPage'               => 'link',
		'page'                      => 'link',
		'linkUrl'                   => 'link',
		'lastDownloaded'            => 'text',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'marsad_snapshots';
	}

	/**
	 * Store one report, replacing what was there for the same source, range and report.
	 *
	 * @throws Marsad_Exception when the payload cannot be encoded (it would otherwise vanish silently)
	 */
	public static function put( $source, $range_key, $report, array $payload ): void {
		global $wpdb;
		// Invalid UTF-8 (a title cut mid-letter upstream) is replaced instead of failing the whole report.
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $json ) ) {
			/* translators: %s: report name */
			throw new Marsad_Exception( sprintf( __( 'The "%s" report could not be saved.', 'marsad' ), (string) $report ) );
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built here.
		$sql = "INSERT INTO {$table} (source,range_key,report,payload,fetched_at) VALUES (%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE payload=VALUES(payload), fetched_at=VALUES(fetched_at)";
		$wpdb->query( $wpdb->prepare( $sql, (string) $source, (string) $range_key, mb_substr( (string) $report, 0, 80 ), $json, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array|null array( 'payload' => array, 'fetched_at' => unix time ), or null when never stored
	 */
	public static function get( $source, $range_key, $report ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT payload, fetched_at FROM {$table} WHERE source=%s AND range_key=%s AND report=%s", (string) $source, (string) $range_key, (string) $report ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$payload = json_decode( (string) $row->payload, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		return array(
			'payload'    => $payload,
			'fetched_at' => (int) strtotime( $row->fetched_at . ' UTC' ),
		);
	}

	/**
	 * Every stored report of one source and range.
	 *
	 * @return array report => payload
	 */
	public static function reports( $source, $range_key ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT report, payload FROM {$table} WHERE source=%s AND range_key=%s", (string) $source, (string) $range_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$payload = json_decode( (string) $r->payload, true );
			if ( is_array( $payload ) ) {
				$out[ (string) $r->report ] = $payload;
			}
		}
		return $out;
	}

	/**
	 * The dates a range covers. Like the daily view it ends yesterday (UTC) and compares with the
	 * same number of days right before; all time starts at $start (the source's first day worth
	 * asking for) and has nothing to compare with.
	 *
	 * @param string      $range_key one of RANGES; anything else is read as '28'
	 * @param string|null $today     Y-m-d, default today (UTC)
	 * @param string|null $start     Y-m-d, first day of 'all'; default a year
	 * @return array array( 'from', 'to', 'prev_from' => string|null, 'prev_to' => string|null )
	 */
	public static function range( $range_key, $today = null, $start = null ): array {
		$today     = $today ? (string) $today : gmdate( 'Y-m-d' );
		$range_key = in_array( (string) $range_key, self::RANGES, true ) ? (string) $range_key : '28';
		if ( 'all' !== $range_key ) {
			return Marsad_Aggregate::range( (int) $range_key, $today );
		}
		$to = Marsad_Aggregate::range( 1, $today )['to'];
		return array(
			'from'      => ( $start && (string) $start <= $to ) ? (string) $start : gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - 364 * DAY_IN_SECONDS ),
			'to'        => $to,
			'prev_from' => null,
			'prev_to'   => null,
		);
	}

	/** True when the range's 'totals' report is missing or older than $ttl seconds. */
	public static function stale( $source, $range_key, $ttl ): bool {
		$got = self::get( $source, $range_key, 'totals' );
		return null === $got || time() - $got['fetched_at'] >= (int) $ttl;
	}

	/**
	 * Refresh the stale ranges of one source, most urgent first, until the deadline. Whatever is
	 * left is picked up by the next run. A failing range is recorded and the next one is tried.
	 *
	 * @param int $deadline unix time to stop at
	 * @return int reports written
	 */
	public static function refresh( Marsad_Connector $c, $deadline ): int {
		$written = 0;
		foreach ( self::queue( $c ) as $key ) {
			if ( time() >= $deadline ) {
				break;
			}
			// Cleared first, so a connector can record a partial problem (one report rejected) during the call.
			self::clear_error( $c->id(), $key );
			try {
				$payloads = $c->snapshots( $key, self::range( $key, null, $c->history_start() ) );
				foreach ( $payloads as $report => $payload ) {
					if ( is_array( $payload ) ) {
						self::put( $c->id(), $key, (string) $report, $payload );
						$written++;
					}
				}
			} catch ( Throwable $e ) {
				// Throwable, not Exception: an unexpected API shape raising a TypeError must not end the cron run.
				self::record_error( $c->id(), $key, $e->getMessage() );
			}
		}
		return $written;
	}

	/**
	 * Stale ranges in the order to refresh them: never fetched first (shortest range first, as the
	 * dashboard opens on short ranges), then the most overdue relative to its TTL. A range that
	 * failed last time goes after the others, so one broken range cannot use up every run.
	 *
	 * @return string[] range keys
	 */
	private static function queue( Marsad_Connector $c ): array {
		$errors = self::errors( $c->id() );
		$todo   = array();
		foreach ( self::RANGES as $i => $key ) {
			$got = self::get( $c->id(), $key, 'totals' );
			$ttl = self::TTL[ $key ];
			$age = null === $got ? null : time() - $got['fetched_at'];
			if ( null !== $age && $age < $ttl ) {
				continue;
			}
			$todo[] = array(
				'key'     => $key,
				'failed'  => isset( $errors[ $key ] ) ? 1 : 0,
				'urgency' => null === $age ? INF : $age / $ttl,
				'order'   => $i,
			);
		}
		// The index breaks ties explicitly: usort is not stable before PHP 8.
		usort(
			$todo,
			function ( $a, $b ) {
				return array( $a['failed'], $b['urgency'], $a['order'] ) <=> array( $b['failed'], $a['urgency'], $b['order'] );
			}
		);
		return array_column( $todo, 'key' );
	}

	/**
	 * Per-range problems of one source, or of every source.
	 *
	 * @return array range_key => array( 'at', 'message' ) for one source; source => that, for all
	 */
	public static function errors( $source = null ): array {
		$all = get_option( self::OPT_ERRORS, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $source ) {
			return $all;
		}
		return isset( $all[ $source ] ) && is_array( $all[ $source ] ) ? $all[ $source ] : array();
	}

	/**
	 * Note a problem with one range. Several problems in the same refresh are kept together,
	 * so a connector can report each report it had to drop.
	 */
	public static function record_error( $source, $range_key, $message ): void {
		$all  = self::errors();
		$prev = $all[ $source ][ $range_key ]['message'] ?? '';
		$all[ $source ][ $range_key ] = array(
			'at'      => time(),
			'message' => '' === $prev ? (string) $message : $prev . ' | ' . $message,
		);
		update_option( self::OPT_ERRORS, $all, false );
	}

	public static function clear_error( $source, $range_key ): void {
		$all = self::errors();
		if ( ! isset( $all[ $source ][ $range_key ] ) ) {
			return;
		}
		unset( $all[ $source ][ $range_key ] );
		if ( ! $all[ $source ] ) {
			unset( $all[ $source ] );
		}
		update_option( self::OPT_ERRORS, $all, false );
	}

	/**
	 * How each key of a source's payloads is shown: the shared map plus the connector's own.
	 *
	 * @return array key => one of FORMAT_TYPES
	 */
	public static function formats( ?Marsad_Connector $c = null ): array {
		$own = $c ? $c->snapshot_formats() : array();
		return array_merge( self::FORMATS, array_intersect( $own, self::FORMAT_TYPES ) );
	}

	public static function delete_source( $source ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'source' => (string) $source ) );
		$all = self::errors();
		if ( isset( $all[ $source ] ) ) {
			unset( $all[ $source ] );
			update_option( self::OPT_ERRORS, $all, false );
		}
	}
}
