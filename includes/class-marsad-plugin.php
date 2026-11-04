<?php
/**
 * Bootstrap: capabilities, cron, and wiring of the admin, sync and updater pieces.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Plugin {

	const CAP_VIEW   = 'marsad_view';
	const CAP_MANAGE = 'marsad_manage';
	/** Given to roles allowed to connect some sources; which ones is in settings role_connect. */
	const CAP_CONNECT = 'marsad_connect';
	/** Connect permissions that are not a single source. */
	const CONNECT_EXTRA = array( 'google', 'custom' );
	const CRON_HOOK  = 'marsad_sync';
	const OPT_SETTINGS = 'marsad_settings';

	/**
	 * The plugin speaks the admin's own language (their profile language, else the site's).
	 * Files are per language, so fr_FR, fr_CA and fr_BE all read marsad-fr.l10n.php; a
	 * translation from translate.wordpress.org, if installed, still wins.
	 */
	public static function load_translations() {
		$locale = determine_locale();
		$lang   = strtolower( substr( $locale, 0, 2 ) );
		foreach ( array_unique( array( $locale, $lang ) ) as $code ) {
			if ( is_readable( MARSAD_DIR . 'languages/marsad-' . $code . '.l10n.php' ) ) {
				load_textdomain( 'marsad', MARSAD_DIR . 'languages/marsad-' . $code . '.mo', $locale );
				return;
			}
		}
	}

	public static function boot() {
		self::load_translations();

		Marsad_Store::maybe_upgrade();
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'before_delete_post', array( 'Marsad_Connector_Wordpress', 'on_delete' ), 10, 2 );
		add_action( 'rest_api_init', array( 'Marsad_Connector_Stream', 'register_route' ) );
		add_action( self::CRON_HOOK, array( 'Marsad_Sync', 'run_all' ) );
		// Managers can always connect; no role change needed on existing sites.
		add_filter(
			'user_has_cap',
			function ( $all ) {
				if ( ! empty( $all[ self::CAP_MANAGE ] ) ) {
					$all[ self::CAP_CONNECT ] = true;
				}
				return $all;
			}
		);
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
			// role => tabs it sees (absent: every tab), role => sources it may connect (absent: none).
			'role_tabs'      => array(),
			'role_connect'   => array(),
			// role => sections shown on the WordPress Dashboard (absent: the overview).
			'role_widgets'   => array(),
		);
	}

	/**
	 * Tabs the current user may open: every tab for managers and for roles nobody restricted,
	 * otherwise the union of what their roles were given.
	 *
	 * @return string[] tab keys, in dashboard order
	 */
	public static function allowed_tabs() {
		$all = array_keys( Marsad_Admin_Dashboard::tabs() );
		if ( current_user_can( self::CAP_MANAGE ) ) {
			return $all;
		}
		$map  = self::settings()['role_tabs'];
		$tabs = array();
		foreach ( (array) wp_get_current_user()->roles as $role ) {
			if ( ! isset( $map[ $role ] ) ) {
				return $all;
			}
			$tabs = array_merge( $tabs, (array) $map[ $role ] );
		}
		return array_values( array_intersect( $all, $tabs ) );
	}

	/**
	 * Sections the current user gets on the WordPress Dashboard: what their roles were given
	 * there (the overview when nobody chose), limited to the sections they may see.
	 *
	 * @return string[] tab keys, in dashboard order
	 */
	public static function dashboard_tabs() {
		if ( ! current_user_can( self::CAP_VIEW ) ) {
			return array();
		}
		$map  = self::settings()['role_widgets'];
		$want = array();
		foreach ( (array) wp_get_current_user()->roles as $role ) {
			$want = array_merge( $want, (array) ( $map[ $role ] ?? array( 'overview' ) ) );
		}
		return array_values( array_intersect( self::allowed_tabs(), $want ) );
	}

	/** May the current user connect this source ('google' for the Google account, 'custom' for own sources)? */
	public static function can_connect( $id ) {
		if ( current_user_can( self::CAP_MANAGE ) ) {
			return true;
		}
		if ( ! current_user_can( self::CAP_CONNECT ) ) {
			return false;
		}
		$map = self::settings()['role_connect'];
		foreach ( (array) wp_get_current_user()->roles as $role ) {
			if ( in_array( (string) $id, (array) ( $map[ $role ] ?? array() ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Source ids a role can be allowed to connect, plus the Google account and own sources. */
	public static function connectable() {
		$ids = array();
		foreach ( Marsad_Registry::all() as $c ) {
			if ( ! $c->always_on() && ! Marsad_Registry::is_custom( $c->id() ) ) {
				$ids[] = $c->id();
			}
		}
		return array_merge( self::CONNECT_EXTRA, $ids );
	}

	public static function settings() {
		$s = get_option( self::OPT_SETTINGS, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function save_settings( array $s ) {
		$s = wp_parse_args( $s, self::defaults() );
		// Only real tabs and sources survive, whatever was posted.
		$tabs  = array_keys( Marsad_Admin_Dashboard::tabs() );
		$conns = self::connectable();
		foreach ( array( 'role_tabs' => $tabs, 'role_connect' => $conns, 'role_widgets' => $tabs ) as $key => $valid ) {
			$clean = array();
			foreach ( (array) $s[ $key ] as $role => $list ) {
				// Administrators see and connect everything, but choose their own Dashboard sections.
				if ( ( 'administrator' !== $role || 'role_widgets' === $key ) && isset( wp_roles()->roles[ $role ] ) ) {
					$clean[ $role ] = array_values( array_intersect( array_map( 'strval', (array) $list ), $valid ) );
				}
			}
			$s[ $key ] = $clean;
		}
		update_option( self::OPT_SETTINGS, $s, false );
		self::apply_view_roles( $s['view_roles'] );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		self::ensure_cron();
	}

	/**
	 * Grants marsad_view to exactly the chosen roles. Administrators always keep both caps.
	 */
	public static function apply_view_roles( array $roles ) {
		$connect = self::settings()['role_connect'];
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( 'administrator' === $name || in_array( $name, $roles, true ) ) {
				$role->add_cap( self::CAP_VIEW );
			} else {
				$role->remove_cap( self::CAP_VIEW );
			}
			if ( 'administrator' !== $name && ! empty( $connect[ $name ] ) ) {
				$role->add_cap( self::CAP_CONNECT );
			} else {
				$role->remove_cap( self::CAP_CONNECT );
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
