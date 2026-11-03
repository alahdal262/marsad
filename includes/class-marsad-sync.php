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

	public static function run_all() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );
		try {
			foreach ( Marsad_Registry::enabled() as $c ) {
				self::run( $c );
			}
			Marsad_Store::purge_older_than( Marsad_Plugin::settings()['retention_days'] );
		} finally {
			delete_transient( self::LOCK );
		}
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
		$entry  = array(
			'at'         => time(),
			'ok'         => false,
			'message'    => '',
			'rows'       => 0,
			'backfilled' => ! empty( $prev['backfilled'] ),
		);
		try {
			$rows             = $c->fetch( $from, $today );
			$entry['rows']    = Marsad_Store::put( $c->id(), $rows );
			$entry['ok']      = true;
			$entry['backfilled'] = true;
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
