<?php
/**
 * Pure functions that turn stored daily values into what the dashboard shows.
 * No WordPress calls here, so it is unit-tested directly.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Aggregate {

	/**
	 * Every day from $from to $to inclusive, as Y-m-d strings.
	 */
	public static function days( $from, $to ) {
		$out = array();
		$t   = strtotime( $from . ' 00:00:00 UTC' );
		$end = strtotime( $to . ' 00:00:00 UTC' );
		while ( $t <= $end ) {
			$out[] = gmdate( 'Y-m-d', $t );
			$t    += 86400;
		}
		return $out;
	}

	/**
	 * The window ending yesterday with $n days, and the window of the same length right before it.
	 *
	 * @return array array( 'from', 'to', 'prev_from', 'prev_to' )
	 */
	public static function range( $n, $today ) {
		$n    = max( 1, (int) $n );
		$to   = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - 86400 );
		$from = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - ( $n - 1 ) * 86400 );
		$pto  = gmdate( 'Y-m-d', strtotime( $from . ' UTC' ) - 86400 );
		$pfr  = gmdate( 'Y-m-d', strtotime( $pto . ' UTC' ) - ( $n - 1 ) * 86400 );
		return array( 'from' => $from, 'to' => $to, 'prev_from' => $pfr, 'prev_to' => $pto );
	}

	/**
	 * Collapse a day => value map into one number.
	 *  sum  — counters (views, installs)
	 *  avg  — daily gauges where summing double counts (daily active users)
	 *  last — snapshots (followers): the latest day that has a value
	 *  max  — peaks
	 *
	 * @return float|null null when there is no data at all
	 */
	public static function collapse( array $by_day, $agg ) {
		if ( ! $by_day ) {
			return null;
		}
		switch ( $agg ) {
			case 'last':
				ksort( $by_day );
				return (float) end( $by_day );
			case 'avg':
				return array_sum( $by_day ) / count( $by_day );
			case 'max':
				return (float) max( $by_day );
			default:
				return (float) array_sum( $by_day );
		}
	}

	/**
	 * Percentage change from $prev to $cur, or null when it cannot be expressed.
	 */
	public static function change( $cur, $prev ) {
		if ( null === $cur || null === $prev || 0.0 === (float) $prev ) {
			return null;
		}
		return ( $cur - $prev ) / abs( $prev ) * 100;
	}

	/**
	 * A gap-free series over the given days. Snapshot metrics carry the last known value
	 * forward; counters show 0 on days without data.
	 */
	public static function series( array $by_day, array $days, $agg ) {
		$out  = array();
		$last = null;
		foreach ( $days as $d ) {
			if ( isset( $by_day[ $d ] ) ) {
				$last  = (float) $by_day[ $d ];
				$out[] = $last;
			} else {
				$out[] = ( 'last' === $agg ) ? $last : 0.0;
			}
		}
		return $out;
	}

	/**
	 * Rank a breakdown (dim => day => value) over the range and keep the top $limit.
	 *
	 * @return array list of array( dim, value, share ) where share is 0..1 of the listed total
	 */
	public static function top( array $breakdown, $agg, $limit ) {
		$rows = array();
		foreach ( $breakdown as $dim => $by_day ) {
			$v = self::collapse( $by_day, $agg );
			if ( null !== $v ) {
				$rows[] = array( 'dim' => (string) $dim, 'value' => $v );
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);
		$rows  = array_slice( $rows, 0, max( 1, (int) $limit ) );
		$total = array_sum( array_column( $rows, 'value' ) );
		foreach ( $rows as &$r ) {
			$r['share'] = $total > 0 ? $r['value'] / $total : 0;
		}
		return $rows;
	}
}
