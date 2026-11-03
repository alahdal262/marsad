<?php
/**
 * Storage for normalised metrics: one row per (source, metric, dimension, day).
 * dim '' is the day's total; any other dim is a breakdown (a page, a country, a platform).
 * install() also creates the range snapshot table (see Marsad_Snapshots).
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Store {

	const DB_VERSION = 2;
	const OPT_DB     = 'marsad_db_version';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'marsad_metrics';
	}

	public static function install() {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$table    = self::table();
		$snapshot = Marsad_Snapshots::table();
		$charset  = $wpdb->get_charset_collate();
		// dim is hashed into dim_key so the unique index stays short whatever the page path or title length.
		dbDelta(
			array(
				"CREATE TABLE {$table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					source varchar(40) NOT NULL,
					metric varchar(60) NOT NULL,
					dim varchar(500) NOT NULL DEFAULT '',
					dim_key char(32) NOT NULL DEFAULT '',
					day date NOT NULL,
					value double NOT NULL DEFAULT 0,
					updated_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY row_key (source,metric,dim_key,day),
					KEY lookup (source,metric,day)
				) {$charset};",
				"CREATE TABLE {$snapshot} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					source varchar(40) NOT NULL,
					range_key varchar(10) NOT NULL,
					report varchar(80) NOT NULL,
					payload longtext NOT NULL,
					fetched_at datetime NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY srr (source,range_key,report)
				) {$charset};",
			)
		);
		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( self::OPT_DB, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @param string $source
	 * @param array  $rows list of array( metric, dim, day, value )
	 * @return int rows written
	 */
	public static function put( $source, array $rows ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$n     = 0;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $r ) {
				$dim      = mb_substr( (string) ( $r['dim'] ?? '' ), 0, 500 );
				$values[] = '(%s,%s,%s,%s,%s,%f,%s)';
				array_push( $args, $source, (string) $r['metric'], $dim, md5( $dim ), (string) $r['day'], (float) $r['value'], $now );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholder list are built here.
			$sql = "INSERT INTO {$table} (source,metric,dim,dim_key,day,value,updated_at) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)';
			$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$n += count( $chunk );
		}
		return $n;
	}

	/**
	 * Daily totals (dim '') for one metric between two days, inclusive: array( 'Y-m-d' => value ).
	 */
	public static function daily( $source, $metric, $from, $to, $dim = '' ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT day, value FROM {$table} WHERE source=%s AND metric=%s AND dim_key=%s AND day BETWEEN %s AND %s ORDER BY day", $source, $metric, md5( $dim ), $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $r ) {
			$out[ $r->day ] = (float) $r->value;
		}
		return $out;
	}

	/**
	 * Breakdown rows (dim != '') for a metric over a range, as array( dim => array( day => value ) ).
	 */
	public static function breakdown( $source, $metric, $from, $to ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT dim, day, value FROM {$table} WHERE source=%s AND metric=%s AND dim<>'' AND day BETWEEN %s AND %s", $source, $metric, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $r ) {
			$out[ $r->dim ][ $r->day ] = (float) $r->value;
		}
		return $out;
	}

	/**
	 * @param string[] $keep sources whose whole history stays (the site's own posts)
	 */
	public static function purge_older_than( $days, array $keep = array() ) {
		global $wpdb;
		$table  = self::table();
		$except = $keep ? ' AND source NOT IN (' . implode( ',', array_fill( 0, count( $keep ), '%s' ) ) . ')' : '';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s{$except}", array_merge( array( gmdate( 'Y-m-d', time() - (int) $days * DAY_IN_SECONDS ) ), $keep ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Add to one day's value (events counted as they happen, such as a permanent deletion). */
	public static function increment( $source, $metric, $day, $by = 1 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (source,metric,dim,dim_key,day,value,updated_at) VALUES (%s,%s,'',%s,%s,%f,%s) ON DUPLICATE KEY UPDATE value=value+%f, updated_at=VALUES(updated_at)", $source, $metric, md5( '' ), $day, (float) $by, current_time( 'mysql', true ), (float) $by ) );
	}

	/** The earliest day anything was recorded, or null when the table is empty. */
	public static function first_day() {
		global $wpdb;
		$table = self::table();
		$day   = $wpdb->get_var( "SELECT MIN(day) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $day ? (string) $day : null;
	}

	public static function delete_source( $source ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'source' => $source ) );
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Marsad_Snapshots::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( Marsad_Snapshots::OPT_ERRORS );
		delete_option( self::OPT_DB );
	}
}
