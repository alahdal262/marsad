<?php
/**
 * Admin screens: Dashboard (marsad_view), Connections and Settings (marsad_manage).
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin {

	const SLUG = 'marsad';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'save_connector', 'test_connector', 'sync_now', 'forget_connector', 'save_settings', 'check_update', 'google_connect', 'google_return', 'google_disconnect', 'export' ) as $a ) {
			add_action( 'admin_post_marsad_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'wp_dashboard_setup', array( 'Marsad_Admin_Widgets', 'register' ) );
		add_action( 'wp_ajax_marsad_realtime', array( __CLASS__, 'ajax_realtime' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MARSAD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Marsad', 'marsad' ), __( 'Marsad', 'marsad' ), Marsad_Plugin::CAP_VIEW, self::SLUG, array( 'Marsad_Admin_Dashboard', 'render' ), 'dashicons-chart-area', 3 );
		add_submenu_page( self::SLUG, __( 'Dashboard', 'marsad' ), __( 'Dashboard', 'marsad' ), Marsad_Plugin::CAP_VIEW, self::SLUG, array( 'Marsad_Admin_Dashboard', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Connections', 'marsad' ), __( 'Connections', 'marsad' ), Marsad_Plugin::CAP_CONNECT, self::SLUG . '-connections', array( 'Marsad_Admin_Connections', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'marsad' ), __( 'Settings', 'marsad' ), Marsad_Plugin::CAP_MANAGE, self::SLUG . '-settings', array( 'Marsad_Admin_Settings', 'render' ) );
	}

	public static function assets( $hook ) {
		if ( 'index.php' === $hook ) {
			// The WordPress Dashboard: only the styles, and only when it shows a Marsad section.
			if ( Marsad_Plugin::dashboard_tabs() ) {
				wp_enqueue_style( 'marsad-admin', MARSAD_URL . 'assets/admin.css', array(), MARSAD_VERSION );
			}
			return;
		}
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'marsad-admin', MARSAD_URL . 'assets/admin.css', array(), MARSAD_VERSION );
		wp_enqueue_script( 'marsad-admin', MARSAD_URL . 'assets/admin.js', array(), MARSAD_VERSION, true );
	}

	public static function action_links( $links ) {
		if ( current_user_can( Marsad_Plugin::CAP_VIEW ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Dashboard', 'marsad' ) . '</a>' );
		}
		return $links;
	}

	public static function url( $page = '', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG . ( $page ? '-' . $page : '' ) ), $args ), admin_url( 'admin.php' ) );
	}

	/* ── notices ─────────────────────────────────────────────────────── */

	public static function notice( $type, $text ) {
		set_transient( 'marsad_notice_' . get_current_user_id(), array( $type, $text ), 60 );
	}

	public static function print_notice() {
		$n = get_transient( 'marsad_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'marsad_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}
	}

	/* ── form handlers (admin-post.php) ─────────────────────────────── */

	private static function guard( $action, $cap = Marsad_Plugin::CAP_MANAGE ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'marsad' ), 403 );
		}
		check_admin_referer( 'marsad_' . $action );
	}

	/**
	 * May the current user change this source (or add a custom one, for the "new" id)?
	 * Managers always; others only for what Settings gives their role.
	 */
	public static function may_change( $id ) {
		if ( Marsad_Admin_Connections::NEW_ID === $id || Marsad_Registry::is_custom( (string) $id ) ) {
			return Marsad_Plugin::can_connect( 'custom' );
		}
		return Marsad_Plugin::can_connect( (string) $id );
	}

	/** guard() for a source: the role must be allowed this very source. */
	private static function guard_source( $action ) {
		self::guard( $action, Marsad_Plugin::CAP_CONNECT );
		$id = sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( ! self::may_change( $id ) ) {
			wp_die( esc_html__( 'Your role may not connect this source.', 'marsad' ), 403 );
		}
	}

	private static function connector_from_post() {
		$id = sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$c  = Marsad_Registry::get( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Unknown source.', 'marsad' ), 400 );
		}
		return $c;
	}

	private static function back( $page, array $args = array() ) {
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	public static function handle_save_connector() {
		self::guard_source( 'save_connector' );
		$fields = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field type in Marsad_Registry::save().
		if ( Marsad_Admin_Connections::NEW_ID === sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) ) ) {
			$draft = new Marsad_Connector_Json( Marsad_Admin_Connections::NEW_ID );
			$draft->set_values( array_map( 'strval', $fields ) );
			if ( ! $draft->is_configured() ) {
				self::notice( 'error', __( 'Fill in the JSON address and at least one value line.', 'marsad' ) );
				self::back( 'connections', array( 'open' => Marsad_Admin_Connections::NEW_ID ) );
			}
			$c = Marsad_Registry::get( Marsad_Registry::add_custom() );
		} else {
			$c = self::connector_from_post();
		}
		Marsad_Registry::save( $c, ! empty( $_POST['enabled'] ), $fields );
		$c = Marsad_Registry::get( $c->id() );
		if ( ! empty( $_POST['enabled'] ) && $c->is_configured() ) {
			$st = Marsad_Sync::run( $c );
			self::notice( $st['ok'] ? 'success' : 'error', $c->label() . ': ' . ( $st['ok'] ? __( 'saved and synced.', 'marsad' ) . ' ' . $st['message'] : $st['message'] ) );
		} elseif ( ! empty( $_POST['enabled'] ) ) {
			/* translators: %s: comma-separated list of what is still needed */
			self::notice( 'warning', $c->label() . ': ' . sprintf( __( 'saved, but it cannot sync yet. Still missing: %s.', 'marsad' ), implode( '، ', $c->missing() ) ) );
		} else {
			self::notice( 'success', $c->label() . ': ' . __( 'saved.', 'marsad' ) );
		}
		self::back( 'connections', array( 'open' => $c->id() ) );
	}

	public static function handle_test_connector() {
		self::guard_source( 'test_connector' );
		$c = self::connector_from_post();
		try {
			self::notice( 'success', $c->label() . ': ' . $c->test() );
		} catch ( Exception $e ) {
			self::notice( 'error', $c->label() . ': ' . $e->getMessage() );
		}
		self::back( 'connections', array( 'open' => $c->id() ) );
	}

	public static function handle_sync_now() {
		self::guard( 'sync_now' );
		delete_transient( Marsad_Sync::LOCK );
		// Someone is waiting for the result, so range snapshots get longer than on cron.
		Marsad_Sync::run_all( 90 );
		$bad = array();
		foreach ( Marsad_Sync::status() as $id => $st ) {
			$c = Marsad_Registry::get( $id );
			if ( $c && Marsad_Registry::is_enabled( $c ) && empty( $st['ok'] ) ) {
				$bad[] = $c->label();
			}
		}
		if ( $bad ) {
			/* translators: %s: comma-separated source names */
			self::notice( 'warning', sprintf( __( 'Synced, but these sources failed: %s. See Connections for the reason.', 'marsad' ), implode( '، ', $bad ) ) );
		} else {
			self::notice( 'success', __( 'All sources are up to date.', 'marsad' ) );
		}
		$page = sanitize_key( wp_unslash( $_POST['back'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::back( in_array( $page, array( 'connections', 'settings' ), true ) ? $page : '' );
	}

	public static function handle_forget_connector() {
		self::guard_source( 'forget_connector' );
		$c = self::connector_from_post();
		if ( Marsad_Registry::is_custom( $c->id() ) ) {
			Marsad_Registry::remove_custom( $c->id() );
		} else {
			Marsad_Registry::forget( $c->id() );
		}
		Marsad_Sync::forget( $c->id() );
		if ( ! empty( $_POST['delete_data'] ) ) {
			Marsad_Store::delete_source( $c->id() );
			Marsad_Snapshots::delete_source( $c->id() );
		}
		self::notice( 'success', $c->label() . ': ' . __( 'disconnected.', 'marsad' ) );
		self::back( 'connections' );
	}

	/* ── dashboard data ─────────────────────────────────────────────── */

	/**
	 * One stored report as a CSV download (GET admin-post.php?action=marsad_export&source=&range=&report=).
	 * Anyone who may see the dashboard may take its tables away; the nonce stops a link
	 * planted elsewhere from triggering downloads.
	 */
	public static function handle_export() {
		self::guard( 'export', Marsad_Plugin::CAP_VIEW );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$source = sanitize_key( wp_unslash( $_GET['source'] ?? '' ) );
		$range  = preg_replace( '/[^a-z0-9]/', '', (string) wp_unslash( $_GET['range'] ?? '' ) );
		// Report keys keep their case (totals_googleNews), so sanitize_key() would break them.
		$report = preg_replace( '/[^A-Za-z0-9_]/', '', (string) wp_unslash( $_GET['report'] ?? '' ) );
		// phpcs:enable
		// A table is only as visible as the tab that shows it.
		$tabs = array( 'ga4' => array( 'audience', 'content', 'overview' ), 'search_console' => array( 'search', 'overview' ) );
		if ( ! array_intersect( $tabs[ $source ] ?? array( 'platforms' ), Marsad_Plugin::allowed_tabs() ) ) {
			wp_die( esc_html__( 'You are not allowed to view statistics.', 'marsad' ), 403 );
		}
		$file = Marsad_Admin_Table::export( $source, $range, $report );
		if ( ! $file ) {
			wp_die( esc_html__( 'This report is not available. It may not have been fetched yet.', 'marsad' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file['name'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV file, not HTML.
		exit;
	}

	/** Active users right now, for the dashboard's live card (polled every minute). */
	public static function ajax_realtime() {
		if ( ! current_user_can( Marsad_Plugin::CAP_VIEW ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to view statistics.', 'marsad' ) ), 403 );
			return;
		}
		check_ajax_referer( 'marsad_realtime' );
		if ( ! in_array( 'audience', Marsad_Plugin::allowed_tabs(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to view statistics.', 'marsad' ) ), 403 );
			return;
		}
		$c    = Marsad_Registry::get( 'ga4' );
		$data = null;
		if ( $c && Marsad_Registry::is_enabled( $c ) && method_exists( $c, 'realtime' ) ) {
			try {
				$data = $c->realtime();
			} catch ( Throwable $e ) {
				$data = null;
			}
		}
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Live numbers are not available right now.', 'marsad' ) ) );
			return;
		}
		wp_send_json_success( self::realtime_shape( $data ) );
	}

	/**
	 * The live answer in one shape for the browser: active => int, pages/countries/devices =>
	 * list of array( name, value ). Lists may arrive as a snapshot table, as name => value, or
	 * as rows keyed by Google's dimension names.
	 */
	public static function realtime_shape( array $data ) {
		return array(
			'active'    => is_numeric( $data['active'] ?? null ) ? (int) round( $data['active'] ) : 0,
			'pages'     => self::realtime_list( $data['pages'] ?? array() ),
			'countries' => self::realtime_list( $data['countries'] ?? array() ),
			'devices'   => self::realtime_list( $data['devices'] ?? array() ),
		);
	}

	private static function realtime_list( $list ) {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		if ( isset( $list['rows'] ) && is_array( $list['rows'] ) ) {
			foreach ( Marsad_Insights::rows_of( $list ) as $r ) {
				$out[] = array( implode( ' · ', array_filter( $r['d'], 'strlen' ) ), array_values( $r['m'] )[0] ?? null );
			}
		} else {
			foreach ( $list as $k => $v ) {
				if ( is_string( $k ) && is_numeric( $v ) ) {
					$out[] = array( $k, $v );
				} elseif ( is_array( $v ) ) {
					$name = $v['name'] ?? $v['dim'] ?? $v['label'] ?? $v['unifiedScreenName'] ?? $v['country'] ?? $v['deviceCategory'] ?? $v[0] ?? '';
					$out[] = array( $name, $v['value'] ?? $v['users'] ?? $v['activeUsers'] ?? $v[1] ?? null );
				}
			}
		}
		$clean = array();
		foreach ( $out as $row ) {
			if ( is_scalar( $row[0] ) && '' !== trim( (string) $row[0] ) && is_numeric( $row[1] ) ) {
				$clean[] = array(
					'name'  => (string) $row[0],
					'value' => (int) round( $row[1] ),
				);
			}
		}
		return array_slice( $clean, 0, 10 );
	}

	/* ── Connect with Google ─────────────────────────────────────────── */

	public static function handle_google_connect() {
		self::guard( 'google_connect', Marsad_Plugin::CAP_CONNECT );
		if ( ! Marsad_Plugin::can_connect( 'google' ) ) {
			wp_die( esc_html__( 'Your role may not connect this source.', 'marsad' ), 403 );
		}
		if ( ! Marsad_Google_Account::relay_ready( 'google' ) ) {
			self::notice( 'error', __( 'One-click Google sign-in is not available right now. Use a service account key under "Advanced" in each Google card.', 'marsad' ) );
			self::back( 'connections' );
		}
		$return = add_query_arg( '_wpnonce', wp_create_nonce( 'marsad_google_return' ), admin_url( 'admin-post.php' ) );
		wp_redirect( Marsad_Google_Account::connect_url( $return ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the relay is a fixed, filterable address.
		exit;
	}

	/** Google → relay → here, with a one-time handover code (or the reason it failed). */
	public static function handle_google_return() {
		if ( ! Marsad_Plugin::can_connect( 'google' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'marsad' ), 403 );
		}
		check_admin_referer( 'marsad_google_return' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$error = sanitize_key( wp_unslash( $_GET['marsad_error'] ?? '' ) );
		$code  = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_GET['marsad_code'] ?? '' ) );
		// phpcs:enable
		if ( 'access_denied' === $error ) {
			self::notice( 'warning', __( 'Google sign-in was cancelled. Nothing was changed.', 'marsad' ) );
		} elseif ( $error || '' === $code ) {
			/* translators: %s: short error code */
			self::notice( 'error', sprintf( __( 'Google sign-in failed (%s). Please try again.', 'marsad' ), $error ? $error : 'no_code' ) );
		} else {
			try {
				Marsad_Google_Account::finish( $code );
				/* translators: %s: Google account e-mail */
				self::notice( 'success', sprintf( __( 'Google connected as %s. Now pick your Analytics property, Search Console site and YouTube channel below.', 'marsad' ), Marsad_Google_Account::email() ) );
			} catch ( Marsad_Exception $e ) {
				self::notice( 'error', $e->getMessage() );
			}
		}
		self::back( 'connections', array( 'open' => 'ga4' ) );
	}

	public static function handle_google_disconnect() {
		self::guard( 'google_disconnect', Marsad_Plugin::CAP_CONNECT );
		if ( ! Marsad_Plugin::can_connect( 'google' ) ) {
			wp_die( esc_html__( 'Your role may not connect this source.', 'marsad' ), 403 );
		}
		Marsad_Google_Account::disconnect();
		self::notice( 'success', __( 'Google disconnected. Sources that used it stop until you connect again or add a service account.', 'marsad' ) );
		self::back( 'connections' );
	}

	public static function handle_save_settings() {
		self::guard( 'save_settings' );
		$roles = array_map( 'sanitize_key', (array) wp_unslash( $_POST['view_roles'] ?? array() ) );
		$roles = array_values( array_intersect( $roles, array_keys( wp_roles()->roles ) ) );
		Marsad_Plugin::save_settings(
			array(
				'view_roles'     => array_unique( array_merge( array( 'administrator' ), $roles ) ),
				'interval'       => in_array( $_POST['interval'] ?? '', array( 'hourly', 'marsad_6h', 'twicedaily', 'daily' ), true ) ? sanitize_key( $_POST['interval'] ) : 'hourly',
				'retention_days' => max( 30, min( 3650, (int) ( $_POST['retention_days'] ?? 730 ) ) ),
				'backfill_days'  => max( 7, min( 480, (int) ( $_POST['backfill_days'] ?? 90 ) ) ),
				'role_tabs'      => self::posted_role_lists( 'role_tabs' ),
				'role_connect'   => self::posted_role_lists( 'role_connect' ),
				'role_widgets'   => self::posted_role_lists( 'role_widgets' ),
			)
		);
		self::notice( 'success', __( 'Settings saved.', 'marsad' ) );
		self::back( 'settings' );
	}

	/**
	 * role => ticked values for every role shown on the form, so a role with nothing ticked is
	 * stored as "nothing" rather than falling back to the default. save_settings() validates.
	 */
	private static function posted_role_lists( $field ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$shown  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles_shown'] ?? array() ) );
		$posted = (array) wp_unslash( $_POST[ $field ] ?? array() );
		// phpcs:enable
		$out = array();
		foreach ( $shown as $role ) {
			$out[ $role ] = array_map( 'sanitize_key', (array) ( $posted[ $role ] ?? array() ) );
		}
		return $out;
	}

	public static function handle_check_update() {
		self::guard( 'check_update' );
		$r = Marsad_Updater::latest( true );
		delete_site_transient( 'update_plugins' );
		if ( $r && Marsad_Updater::is_newer( $r['version'], MARSAD_VERSION ) ) {
			/* translators: %s: version number */
			self::notice( 'warning', sprintf( __( 'Version %s is available. Update it from the Plugins page.', 'marsad' ), $r['version'] ) );
		} elseif ( $r ) {
			self::notice( 'success', __( 'You have the latest version.', 'marsad' ) );
		} else {
			self::notice( 'error', __( 'Could not reach GitHub to check for updates.', 'marsad' ) );
		}
		self::back( 'settings' );
	}

	/* ── shared formatting ──────────────────────────────────────────── */

	public static function format( $value, $format = 'number' ) {
		if ( null === $value ) {
			return '—';
		}
		switch ( $format ) {
			case 'percent':
				return number_format_i18n( $value, 1 ) . '%';
			case 'decimal':
				return number_format_i18n( $value, 1 );
			case 'seconds':
				$s = (int) round( $value );
				return sprintf( '%d:%02d', intdiv( $s, 60 ), $s % 60 );
			default:
				return number_format_i18n( round( $value ) );
		}
	}

	public static function change_badge( $change, $better = 'up' ) {
		if ( null === $change ) {
			return '';
		}
		$good  = ( 'down' === $better ) ? $change < 0 : $change > 0;
		$class = abs( $change ) < 0.5 ? 'flat' : ( $good ? 'good' : 'bad' );
		$arrow = $change > 0 ? '▲' : ( $change < 0 ? '▼' : '•' );
		return '<span class="marsad-change marsad-' . esc_attr( $class ) . '">' . esc_html( $arrow . ' ' . number_format_i18n( abs( $change ), 1 ) . '%' ) . '</span>';
	}

	public static function ago( $ts ) {
		if ( ! $ts ) {
			return __( 'never', 'marsad' );
		}
		/* translators: %s: human time difference, e.g. "5 minutes" */
		return sprintf( __( '%s ago', 'marsad' ), human_time_diff( (int) $ts, time() ) );
	}
}
