<?php
/**
 * Bootstrap: capabilities, cron, and wiring of the admin, sync and updater pieces.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Plugin {

	const CAP_VIEW   = 'marsad_view';
	const CAP_MANAGE = 'marsad_manage';
	const CRON_HOOK  = 'marsad_sync';
	const OPT_SETTINGS = 'marsad_settings';

	public static function boot() {
		load_plugin_textdomain( 'marsad', false, dirname( plugin_basename( MARSAD_FILE ) ) . '/languages' );

		Marsad_Store::maybe_upgrade();
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( 'Marsad_Sync', 'run_all' ) );
		self::ensure_cron();

		if ( is_admin() ) {
			Marsad_Admin::init();
		}
		Marsad_Updater::init();
	}

	public static function activate() {
		Marsad_Store::install();
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_VIEW );
			$admin->add_cap( self::CAP_MANAGE );
		}
		self::apply_view_roles( self::settings()['view_roles'] );
		self::ensure_cron();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function defaults() {
		return array(
			'view_roles'     => array( 'administrator' ),
			'interval'       => 'hourly',
			'retention_days' => 730,
			'backfill_days'  => 90,
		);
	}

	public static function settings() {
		$s = get_option( self::OPT_SETTINGS, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function save_settings( array $s ) {
		$s = wp_parse_args( $s, self::defaults() );
		update_option( self::OPT_SETTINGS, $s, false );
		self::apply_view_roles( $s['view_roles'] );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::ensure_cron();
	}

	/**
	 * Grants marsad_view to exactly the chosen roles. Administrators always keep both caps.
	 */
	public static function apply_view_roles( array $roles ) {
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( 'administrator' === $name || in_array( $name, $roles, true ) ) {
				$role->add_cap( self::CAP_VIEW );
			} else {
				$role->remove_cap( self::CAP_VIEW );
			}
		}
	}

	public static function cron_schedules( $schedules ) {
		$schedules['marsad_6h'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'marsad' ),
		);
		return $schedules;
	}

	private static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$interval = self::settings()['interval'];
			if ( ! in_array( $interval, array( 'hourly', 'marsad_6h', 'twicedaily', 'daily' ), true ) ) {
				$interval = 'hourly';
			}
			wp_schedule_event( time() + 120, $interval, self::CRON_HOOK );
		}
	}
}
