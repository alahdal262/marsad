<?php
/**
 * Pulls every enabled connector into the store. Runs from WP-Cron and from the "Sync now" button.
 *
 * Status (option marsad_status): array( id => array( 'at', 'ok', 'message', 'rows', 'backfilled' ) )
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Sync {

	const OPT_STATUS = 'marsad_status';
	const LOCK       = 'marsad_sync_lock';

	public static function status() {
		$s = get_option( self::OPT_STATUS, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Daily rows for every source, then range snapshots for as long as $snapshot_seconds allows.
	 *
	 * @param int $snapshot_seconds shared budget of the snapshot phase; 45 s suits shared hosting
	 */
	public static function run_all( $snapshot_seconds = 45 ) {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );
		try {
			foreach ( Marsad_Registry::enabled() as $c ) {
				self::run( $c );
			}
			$keep = array();
			foreach ( Marsad_Registry::all() as $c ) {
				if ( $c->full_history() ) {
					$keep[] = $c->id();
				}
			}
			Marsad_Store::purge_older_than( Marsad_Plugin::settings()['retention_days'], $keep );
			self::snapshot_phase( $snapshot_seconds );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Refresh range snapshots of every enabled source that has them, sharing one deadline.
	 * Ranges left over are stale on the next run and picked up then.
	 *
	 * @return int reports written
	 */
	public static function snapshot_phase( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$limit   = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 && function_exists( 'set_time_limit' ) ) {
			// The daily runs already used part of PHP's limit (it restarts here); a last API call
			// may also run past the deadline, hence the minute of margin.
			set_time_limit( max( $limit, $seconds + 60 ) );
		}
		$deadline = time() + $seconds;
		$written  = 0;
		foreach ( Marsad_Registry::enabled() as $c ) {
			if ( time() >= $deadline ) {
				break;
			}
			if ( ! $c->supports_snapshots() ) {
				continue;
			}
			try {
				$written += Marsad_Snapshots::refresh( $c, $deadline );
			} catch ( Throwable $e ) {
				// refresh() records per-range failures itself; this only keeps one source from stopping the rest.
				continue;
			}
		}
		return $written;
	}

	/**
	 * Sync one connector. The first successful run imports history; later runs refresh
	 * the last few days.
	 *
	 * @return array the status entry written
	 */
	public static function run( Marsad_Connector $c ) {
		$all    = self::status();
		$prev   = $all[ $c->id() ] ?? array();
		$today  = gmdate( 'Y-m-d' );
		$days   = empty( $prev['backfilled'] )
			? min( (int) Marsad_Plugin::settings()['backfill_days'], $c->max_backfill() )
			: $c->window_days();
		$from   = $c->is_snapshot() ? $today : gmdate( 'Y-m-d', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$start  = '';
		if ( $c->full_history() ) {
			// Everything since the first day, once; also for sites that imported less before.
			$start = $c->history_start();
			if ( empty( $prev['backfilled_from'] ) || $prev['backfilled_from'] > $start ) {
				$from = $start;
			}
		}
		$entry  = array(
			'at'              => time(),
			'ok'              => false,
			'message'         => '',
			'rows'            => 0,
			'backfilled'      => ! empty( $prev['backfilled'] ),
			'backfilled_from' => $prev['backfilled_from'] ?? '',
		);
		try {
			$rows             = $c->fetch( $from, $today );
			$entry['rows']    = Marsad_Store::put( $c->id(), $rows );
			$entry['ok']      = true;
			$entry['backfilled'] = true;
			if ( '' !== $start && $from === $start ) {
				$entry['backfilled_from'] = $start;
			}
			/* translators: %d: number of values */
			$entry['message'] = sprintf( _n( '%d value updated.', '%d values updated.', $entry['rows'], 'marsad' ), $entry['rows'] );
		} catch ( Exception $e ) {
			$entry['message'] = $e->getMessage();
		}
		$all            = self::status();
		$all[ $c->id() ] = $entry;
		update_option( self::OPT_STATUS, $all, false );
		return $entry;
	}

	public static function forget( $id ) {
		$all = self::status();
		unset( $all[ $id ] );
		update_option( self::OPT_STATUS, $all, false );
	}
}
