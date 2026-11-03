<?php
/**
 * The dashboard, in four tabs: Overview, Content & users, Platforms, Hosting.
 * Everything is server-rendered markup and CSS; no external scripts or fonts.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Dashboard {

	const COLORS = array( '#1d4ed8', '#b45309', '#0f766e', '#7c3aed', '#be185d', '#0369a1' );

	/** Sources that have their own tab instead of a section on Platforms. */
	const CORE = array( 'wordpress', 'site' );

	/** @var Marsad_Report */
	private static $report;

	public static function tabs() {
		return array(
			'overview'  => __( 'Overview', 'marsad' ),
			'content'   => __( 'Content & users', 'marsad' ),
			'platforms' => __( 'Platforms & apps', 'marsad' ),
			'hosting'   => __( 'Hosting', 'marsad' ),
		);
	}

	public static function render() {
		if ( ! current_user_can( Marsad_Plugin::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to view statistics.', 'marsad' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switches.
		$n   = (int) ( $_GET['range'] ?? 28 );
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
		// phpcs:enable
		$tab          = isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
		self::$report = new Marsad_Report( $n );
		$report       = self::$report;
		$status       = Marsad_Sync::status();
		$last         = $status ? max( array_column( $status, 'at' ) ) : 0;

		echo '<div class="wrap marsad marsad-dash">';
		echo '<header class="marsad-head"><div class="marsad-brand"><span class="marsad-logo" aria-hidden="true">' . self::icon( 'logo' ) . '</span><div><h1>' . esc_html__( 'Marsad', 'marsad' ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo '<span class="marsad-sub">' . esc_html( get_bloginfo( 'name' ) . ' · ' . wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</span></div></div>';
		echo '<div class="marsad-actions"><nav class="marsad-ranges" aria-label="' . esc_attr__( 'Period', 'marsad' ) . '">';
		foreach ( Marsad_Report::RANGES as $r ) {
			/* translators: %d: number of days */
			$label = 365 === $r ? __( 'Year', 'marsad' ) : sprintf( __( '%d days', 'marsad' ), $r );
			$on    = $report->length() === $r;
			echo '<a class="' . ( $on ? 'is-on' : '' ) . '"' . ( $on ? ' aria-current="true"' : '' ) . ' href="' . esc_url( Marsad_Admin::url( '', array( 'range' => $r, 'tab' => $tab ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( current_user_can( Marsad_Plugin::CAP_MANAGE ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'marsad_sync_now' );
			echo '<input type="hidden" name="action" value="marsad_sync_now"><button class="marsad-btn marsad-btn-dark">' . self::icon( 'sync' ) . esc_html__( 'Sync now', 'marsad' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		}
		echo '</div></header>';

		echo '<nav class="marsad-tabs" aria-label="' . esc_attr__( 'Dashboard sections', 'marsad' ) . '">';
		foreach ( self::tabs() as $key => $label ) {
			$on = $key === $tab;
			echo '<a href="' . esc_url( Marsad_Admin::url( '', array( 'tab' => $key, 'range' => $report->length() ) ) ) . '"' . ( $on ? ' class="is-on" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		Marsad_Admin::print_notice();

		echo '<div class="marsad-titlebar"><h2>' . esc_html( self::tabs()[ $tab ] ) . '</h2><span class="marsad-sub">';
		echo esc_html(
			sprintf(
				/* translators: 1: start date, 2: end date, 3: e.g. "5 minutes ago" */
				__( '%1$s – %2$s, compared with the period before · synced %3$s', 'marsad' ),
				date_i18n( get_option( 'date_format' ), strtotime( $report->range['from'] . ' 12:00 UTC' ) ),
				date_i18n( get_option( 'date_format' ), strtotime( $report->range['to'] . ' 12:00 UTC' ) ),
				Marsad_Admin::ago( $last )
			)
		);
		echo '</span></div>';

		call_user_func( array( __CLASS__, 'tab_' . $tab ), $status );
		echo '</div>';
	}

	/* ── tabs ────────────────────────────────────────────────────────── */

	private static function tab_overview( array $status ) {
		$report   = self::$report;
		$sections = $report->sections();
		$headline = $report->headline( $sections );

		echo '<section class="marsad-cards" aria-label="' . esc_attr__( 'Key numbers', 'marsad' ) . '">';
		$i = 0;
		foreach ( $headline as $h ) {
			self::stat( $h['label'], Marsad_Admin::format( $h['value'] ), Marsad_Admin::change_badge( $h['change'] ), self::COLORS[ $i++ % count( self::COLORS ) ], $h['series'] );
		}
		$posts = self::metric( 'wordpress', 'posts' );
		if ( $posts && null !== $posts['value'] ) {
			$max = $posts['series'] ? max( $posts['series'] ) : 0;
			/* translators: %s: number of posts */
			self::stat( __( 'Posts per day', 'marsad' ), number_format_i18n( $posts['value'] / $report->length(), 1 ), esc_html( sprintf( __( 'Busiest day: %s', 'marsad' ), number_format_i18n( $max ) ) ) );
		}
		foreach ( self::custom_sources() as $c ) {
			$first = self::first_metric( $c );
			if ( $first ) {
				self::stat( $c->label() . ' · ' . $first['label'], Marsad_Admin::format( $first['value'] ), '' );
			}
		}
		$total = self::metric( 'site', 'posts_total' );
		if ( $total && null !== $total['value'] ) {
			$images = self::metric( 'site', 'images' );
			/* translators: %s: number of images */
			self::stat( __( 'Posts in total', 'marsad' ), Marsad_Admin::format( $total['value'] ), esc_html( sprintf( __( '%s images in the media library', 'marsad' ), Marsad_Admin::format( $images['value'] ?? null ) ) ) );
		}
		if ( ! self::enabled( 'ga4' ) ) {
			self::connect_card( __( 'Website visitors', 'marsad' ), 'ga4', __( 'Connect Google Analytics', 'marsad' ) );
		}
		echo '</section>';

		echo '<div class="marsad-cols">';
		if ( $posts ) {
			self::panel_open( __( 'Posts published per day', 'marsad' ), is_rtl() ? __( 'Newest on the left', 'marsad' ) : __( 'Newest on the right', 'marsad' ), 'wide' );
			self::bars( $posts['series'], $report->days, __( 'posts', 'marsad' ) );
			self::panel_close();
		}
		$cats = $report->table( 'wordpress', 'posts_by_category', array( 'agg' => 'sum', 'limit' => 8 ) );
		self::panel_open( __( 'Busiest categories', 'marsad' ), '', '', self::tab_link( 'content', __( 'All', 'marsad' ) ) );
		self::ranked( $cats );
		self::panel_close();
		echo '</div>';

		echo '<div class="marsad-cols">';
		foreach ( self::custom_sources() as $c ) {
			$rows = array();
			foreach ( $c->metrics() as $key => $def ) {
				$m      = $report->metric( $c->id(), $key, $def );
				$rows[] = array( 'dim' => $def['label'], 'value' => $m['value'] );
			}
			self::panel_open( $c->label(), __( 'Your own source', 'marsad' ) );
			self::funnel( $rows );
			self::panel_close();
		}
		self::panel_open( __( 'Connected sources', 'marsad' ), '', '', current_user_can( Marsad_Plugin::CAP_MANAGE ) ? '<a href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => Marsad_Admin_Connections::NEW_ID ) ) . '#conn-new' ) . '">' . esc_html__( '+ Add a source', 'marsad' ) . '</a>' : '' );
		echo '<ul class="marsad-list">';
		foreach ( Marsad_Registry::all() as $c ) {
			if ( in_array( $c->id(), self::CORE, true ) ) {
				continue;
			}
			list( $state, $label ) = self::state( $c, $status[ $c->id() ] ?? array() );
			echo '<li><span><b>' . esc_html( $c->label() ) . '</b><small>' . esc_html( $c->description() ) . '</small></span><span class="marsad-pill marsad-pill-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span></li>';
		}
		echo '</ul>';
		self::panel_close();
		echo '</div>';
	}

	private static function tab_content( array $status ) {
		$report = self::$report;
		$tiles  = array(
			array( 'posts_total', '', __( 'Published posts', 'marsad' ) ),
			array( 'pages', '', __( 'Published pages', 'marsad' ) ),
			array( 'images', 'uploads_mb', __( 'In the media library', 'marsad' ) ),
			array( 'videos', '', __( 'Uploaded video files', 'marsad' ) ),
			array( 'comments_approved', 'comments_pending', __( 'Approved', 'marsad' ) ),
			array( 'categories', 'tags', __( 'Categories', 'marsad' ) ),
		);
		$defs   = Marsad_Registry::get( 'site' )->metrics();
		echo '<section class="marsad-cards">';
		$i = 0;
		foreach ( $tiles as $t ) {
			$m   = self::metric( 'site', $t[0] );
			$sub = $t[2];
			if ( 'uploads_mb' === $t[1] ) {
				$mb  = self::metric( 'site', 'uploads_mb' );
				$sub = null !== ( $mb['value'] ?? null ) ? self::size( $mb['value'] ) : $sub;
			} elseif ( 'comments_pending' === $t[1] ) {
				/* translators: %s: number of comments */
				$sub = sprintf( __( '%s awaiting moderation', 'marsad' ), Marsad_Admin::format( self::metric( 'site', 'comments_pending' )['value'] ?? null ) );
			} elseif ( 'tags' === $t[1] ) {
				/* translators: %s: number of tags */
				$sub = sprintf( __( 'and %s tags', 'marsad' ), Marsad_Admin::format( self::metric( 'site', 'tags' )['value'] ?? null ) );
			}
			self::stat( $defs[ $t[0] ]['label'], Marsad_Admin::format( $m['value'] ?? null ), esc_html( $sub ), self::COLORS[ $i++ % count( self::COLORS ) ] );
		}
		echo '</section>';

		echo '<div class="marsad-cols">';
		self::panel_open( __( 'Most read', 'marsad' ), __( 'From Google Analytics 4', 'marsad' ), 'wide' );
		$top = self::enabled( 'ga4' ) ? $report->table( 'ga4', 'views_by_page', array( 'agg' => 'sum', 'limit' => 10 ) ) : array();
		if ( $top ) {
			self::ranked( $top, true );
		} else {
			echo '<div class="marsad-skeleton" aria-hidden="true">';
			foreach ( array( 78, 64, 88, 55, 70 ) as $w ) {
				echo '<i style="width:' . esc_attr( $w ) . '%"></i>';
			}
			echo '</div>';
			self::callout( __( 'The most read posts and their views appear here once Google Analytics 4 is connected.', 'marsad' ), 'ga4' );
		}
		self::panel_close();

		echo '<div class="marsad-stack">';
		self::panel_open( __( 'Most active authors', 'marsad' ) );
		$authors = $report->table( 'wordpress', 'posts_by_author', array( 'agg' => 'sum', 'limit' => 6 ) );
		self::ranked( $authors, false, true );
		self::panel_close();

		$roles = $report->table( 'site', 'users_by_role', array( 'agg' => 'last', 'limit' => 20 ) );
		$users = self::metric( 'site', 'users' );
		self::panel_open( __( 'Users', 'marsad' ), '', '', '<b class="marsad-big">' . esc_html( Marsad_Admin::format( $users['value'] ?? null ) ) . '</b>' );
		self::stacked( array_map( function ( $r ) { return array( $r['dim'], $r['value'] ); }, $roles ) );
		self::panel_close();
		echo '</div></div>';

		echo '<div class="marsad-cols">';
		self::panel_open( __( 'Posts by category', 'marsad' ), '', 'wide' );
		echo '<div class="marsad-two">';
		self::ranked( $report->table( 'wordpress', 'posts_by_category', array( 'agg' => 'sum', 'limit' => 12 ) ) );
		echo '</div>';
		self::panel_close();
		self::panel_open( __( 'Comments', 'marsad' ) );
		$parts = array();
		foreach ( array( 'comments_approved', 'comments_pending', 'comments_spam', 'comments_trash' ) as $k ) {
			$parts[] = array( $defs[ $k ]['label'], self::metric( 'site', $k )['value'] ?? null );
		}
		self::stacked( $parts, array( '#047857', '#b45309', '#b91c1c', '#9aa3af' ) );
		$pending = (int) ( self::metric( 'site', 'comments_pending' )['value'] ?? 0 );
		if ( $pending && current_user_can( 'moderate_comments' ) ) {
			/* translators: %s: number of comments */
			echo '<a class="marsad-btn" href="' . esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ) . '">' . esc_html( sprintf( __( 'Review %s pending comments', 'marsad' ), number_format_i18n( $pending ) ) ) . '</a>';
		}
		self::panel_close();
		echo '</div>';
	}

	private static function tab_platforms( array $status ) {
		$report = self::$report;
		$manage = current_user_can( Marsad_Plugin::CAP_MANAGE );

		echo '<div class="marsad-titlebar"><h3>' . esc_html__( 'Your own sources', 'marsad' ) . '</h3>';
		if ( $manage ) {
			echo '<a class="marsad-btn marsad-btn-primary" href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => Marsad_Admin_Connections::NEW_ID ) ) . '#conn-new' ) . '">' . esc_html__( '+ Add a source', 'marsad' ) . '</a>';
		}
		echo '</div>';
		$custom = self::custom_sources();
		if ( ! $custom ) {
			self::callout( __( 'Add any address that returns numbers as JSON: a campaign, a form, a shop, an internal system.', 'marsad' ) );
		}
		foreach ( $custom as $c ) {
			list( $state, $label ) = self::state( $c, $status[ $c->id() ] ?? array() );
			self::panel_open( $c->label(), '', 'full', '<span class="marsad-pill marsad-pill-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>' );
			echo '<div class="marsad-tiles">';
			foreach ( $c->metrics() as $key => $def ) {
				$m = $report->metric( $c->id(), $key, $def );
				echo '<div><span>' . esc_html( $def['label'] ) . '</span><b>' . esc_html( Marsad_Admin::format( $m['value'] ) ) . '</b></div>';
			}
			echo '</div>';
			self::panel_close();
		}

		$sections = array_filter(
			$report->sections(),
			function ( $s ) {
				return ! in_array( $s['connector']->id(), self::CORE, true ) && ! Marsad_Registry::is_custom( $s['connector']->id() );
			}
		);
		if ( $sections ) {
			echo '<div class="marsad-titlebar"><h3>' . esc_html__( 'Connected platforms', 'marsad' ) . '</h3></div>';
			foreach ( $sections as $s ) {
				self::section( $s, $status );
			}
		}

		$off = array_filter(
			Marsad_Registry::all(),
			function ( $c ) {
				return ! in_array( $c->id(), self::CORE, true ) && ! Marsad_Registry::is_custom( $c->id() ) && ! Marsad_Registry::is_enabled( $c );
			}
		);
		if ( $off ) {
			echo '<div class="marsad-titlebar"><h3>' . esc_html__( 'Not connected yet', 'marsad' ) . '</h3><span class="marsad-sub">' . esc_html__( 'Each platform is connected once, with this site\'s own account. Data never passes through a third-party server.', 'marsad' ) . '</span></div>';
			echo '<div class="marsad-cards">';
			foreach ( $off as $c ) {
				echo '<div class="marsad-card marsad-card-off"><div class="marsad-card-top"><b>' . esc_html( $c->label() ) . '</b><span class="marsad-pill">' . esc_html__( 'Not connected', 'marsad' ) . '</span></div>';
				echo '<div class="marsad-tiles marsad-tiles-sm">';
				foreach ( array_slice( array_filter( $c->metrics(), function ( $m ) { return ! empty( $m['kpi'] ); } ), 0, 3 ) as $def ) {
					echo '<div><span>' . esc_html( $def['label'] ) . '</span><b>—</b></div>';
				}
				echo '</div>';
				if ( $manage ) {
					echo '<a class="marsad-btn" href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => $c->id() ) ) . '#conn-' . $c->id() ) . '">' . esc_html__( 'Connect', 'marsad' ) . '</a>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
	}

	private static function tab_hosting( array $status ) {
		$report = self::$report;
		$stack  = Marsad_Connector_Site::stack();
		$failed = array();
		foreach ( $status as $id => $st ) {
			$c = Marsad_Registry::get( $id );
			if ( $c && Marsad_Registry::is_enabled( $c ) && empty( $st['ok'] ) ) {
				$failed[] = $c->label();
			}
		}
		$ok = ! $failed && ! $stack['cron_overdue'];
		echo '<div class="marsad-banner marsad-banner-' . ( $ok ? 'ok' : 'warn' ) . '">' . self::icon( $ok ? 'ok' : 'warn' ) . '<div><b>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		if ( $ok ) {
			echo esc_html__( 'Everything is running normally', 'marsad' );
		} elseif ( $failed ) {
			/* translators: %s: comma-separated source names */
			echo esc_html( sprintf( __( 'These sources failed to sync: %s', 'marsad' ), implode( '، ', $failed ) ) );
		} else {
			/* translators: %s: number of scheduled tasks */
			echo esc_html( sprintf( __( '%s scheduled tasks are more than an hour late', 'marsad' ), number_format_i18n( $stack['cron_overdue'] ) ) );
		}
		echo '</b><span>' . esc_html__( 'Checked on every sync, and live when you open this page.', 'marsad' ) . '</span></div></div>';

		echo '<div class="marsad-cols">';
		// The headline is the latest measurement (today's counts); the bars are finished days.
		$resp   = self::metric( 'site', 'response_ms' );
		$latest = self::enabled( 'site' ) ? $report->metric( 'site', 'response_ms', array( 'agg' => 'last' ) )['value'] : null;
		self::panel_open( __( 'Home page response time', 'marsad' ), __( 'Milliseconds, measured from this server on each sync', 'marsad' ), 'wide', '<b class="marsad-big">' . esc_html( Marsad_Admin::format( $latest ) ) . '</b>' );
		if ( $resp && null !== $resp['value'] ) {
			self::bars( array_map( function ( $v ) { return null === $v ? 0 : $v; }, $resp['series'] ), $report->days, __( 'ms', 'marsad' ) );
		} elseif ( null !== $latest ) {
			self::callout( __( 'The daily chart starts tomorrow, after the first full day of measurements.', 'marsad' ) );
		} else {
			self::callout( __( 'The first measurement appears after the next sync.', 'marsad' ) );
		}
		self::panel_close();

		$up = self::metric( 'site', 'uploads_mb' )['value'] ?? null;
		$db = self::metric( 'site', 'db_mb' )['value'] ?? null;
		self::panel_open( __( 'Space used', 'marsad' ), '', '', '<b class="marsad-big">' . esc_html( null === $up && null === $db ? '—' : self::size( (float) $up + (float) $db ) ) . '</b>' );
		self::stacked( array( array( __( 'Uploaded media', 'marsad' ), $up ), array( __( 'Database', 'marsad' ), $db ) ), array( '#1d4ed8', '#b45309' ), array( __CLASS__, 'size' ) );
		$tables = self::metric( 'site', 'db_tables' )['value'] ?? null;
		if ( $tables ) {
			/* translators: %s: number of tables */
			echo '<p class="marsad-sub">' . esc_html( sprintf( __( 'The database has %s tables.', 'marsad' ), number_format_i18n( $tables ) ) ) . '</p>';
		}
		self::panel_close();
		echo '</div>';

		echo '<section class="marsad-cards">';
		$cells = array(
			array( 'WordPress', $stack['wp'], '' ),
			array( 'PHP', $stack['php'], '-1' === (string) $stack['memory_limit'] ? __( 'No memory limit', 'marsad' ) : sprintf( /* translators: %s: e.g. 256M */ __( 'Memory limit %s', 'marsad' ), $stack['memory_limit'] ) ),
			array( __( 'Database server', 'marsad' ), $stack['db'], '' ),
			array( __( 'Theme', 'marsad' ), $stack['theme'], '' ),
			array( __( 'Plugins', 'marsad' ), $stack['plugins_active'] . ' / ' . $stack['plugins_total'], __( 'active of installed', 'marsad' ) ),
			array( __( 'Largest upload', 'marsad' ), $stack['max_upload'], __( 'per file', 'marsad' ) ),
		);
		foreach ( $cells as $cell ) {
			echo '<div class="marsad-card"><span class="marsad-label">' . esc_html( $cell[0] ) . '</span><b class="marsad-num marsad-num-sm" dir="ltr">' . esc_html( $cell[1] ) . '</b><span class="marsad-sub">' . esc_html( $cell[2] ) . '</span></div>';
		}
		echo '</section>';

		self::panel_open( __( 'WordPress health', 'marsad' ), '', 'full' );
		$yes  = __( 'Yes', 'marsad' );
		$no   = __( 'No', 'marsad' );
		$rows = array(
			array( __( 'Served over HTTPS', 'marsad' ), $stack['https'] ? $yes : $no, $stack['https'] ),
			array( __( 'Debug mode off', 'marsad' ), $stack['debug'] ? $no : $yes, ! $stack['debug'] ),
			array( __( 'Scheduled tasks', 'marsad' ), number_format_i18n( $stack['cron_events'] ), true ),
			array( __( 'Tasks more than an hour late', 'marsad' ), number_format_i18n( $stack['cron_overdue'] ), ! $stack['cron_overdue'] ),
			array( __( 'Sources failing to sync', 'marsad' ), number_format_i18n( count( $failed ) ), ! $failed ),
		);
		echo '<ul class="marsad-list">';
		foreach ( $rows as $r ) {
			echo '<li><span>' . esc_html( $r[0] ) . '</span><b class="' . ( $r[2] ? 'marsad-good' : 'marsad-bad' ) . '">' . esc_html( $r[1] ) . '</b></li>';
		}
		echo '</ul>';
		self::panel_close();
	}

	/* ── building blocks ─────────────────────────────────────────────── */

	private static function stat( $label, $value, $sub_html, $color = '', array $series = array() ) {
		echo '<div class="marsad-card"' . ( $color ? ' style="--c:' . esc_attr( $color ) . '"' : '' ) . '><span class="marsad-label">' . esc_html( $label ) . '</span>';
		echo '<b class="marsad-num">' . esc_html( $value ) . '</b>';
		if ( '' !== $sub_html ) {
			echo '<span class="marsad-sub">' . $sub_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass escaped markup.
		}
		if ( $series && $color ) {
			echo Marsad_Svg::sparkline( $series, $color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}

	private static function connect_card( $label, $id, $cta ) {
		echo '<div class="marsad-card marsad-card-off"><span class="marsad-label">' . esc_html( $label ) . '</span><b class="marsad-num marsad-faint">—</b>';
		if ( current_user_can( Marsad_Plugin::CAP_MANAGE ) ) {
			echo '<a href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => $id ) ) . '#conn-' . $id ) . '">' . esc_html( $cta ) . '</a>';
		}
		echo '</div>';
	}

	private static function panel_open( $title, $sub = '', $size = '', $aside_html = '' ) {
		echo '<section class="marsad-panel' . ( $size ? ' marsad-panel-' . esc_attr( $size ) : '' ) . '"><header><div><h3>' . esc_html( $title ) . '</h3>';
		if ( $sub ) {
			echo '<span class="marsad-sub">' . esc_html( $sub ) . '</span>';
		}
		echo '</div>' . $aside_html . '</header>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass escaped markup.
	}

	private static function panel_close() {
		echo '</section>';
	}

	private static function callout( $text, $connect = '' ) {
		echo '<div class="marsad-callout"><span>' . esc_html( $text ) . '</span>';
		if ( $connect && current_user_can( Marsad_Plugin::CAP_MANAGE ) ) {
			echo '<a class="marsad-btn marsad-btn-primary" href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => $connect ) ) . '#conn-' . $connect ) . '">' . esc_html__( 'Connect now', 'marsad' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Daily columns with an average line. Oldest first in the markup, so RTL reads right to left.
	 */
	private static function bars( array $values, array $days, $unit ) {
		$count = count( $values );
		if ( ! $count ) {
			return;
		}
		$top  = self::nice_max( max( $values ) );
		$avg  = array_sum( $values ) / $count;
		$fmt  = get_option( 'date_format' );
		$desc = sprintf(
			/* translators: 1: smallest value, 2: largest value, 3: average, 4: unit such as "posts" */
			__( 'Daily values between %1$s and %2$s, average %3$s %4$s', 'marsad' ),
			number_format_i18n( min( $values ) ),
			number_format_i18n( max( $values ) ),
			number_format_i18n( $avg, 1 ),
			$unit
		);
		echo '<div class="marsad-bars" role="img" aria-label="' . esc_attr( $desc ) . '"><div class="marsad-bars-y" aria-hidden="true">';
		foreach ( array( 1, 0.75, 0.5, 0.25, 0 ) as $f ) {
			echo '<span>' . esc_html( Marsad_Svg::short( $top * $f ) ) . '</span>';
		}
		echo '</div><div class="marsad-bars-main"><div class="marsad-bars-plot">';
		if ( $top > 0 ) {
			echo '<span class="marsad-bars-avg" style="bottom:' . esc_attr( round( $avg / $top * 100, 2 ) ) . '%"></span>';
		}
		foreach ( $values as $i => $v ) {
			$h   = $top > 0 ? round( $v / $top * 100, 2 ) : 0;
			$day = isset( $days[ $i ] ) ? date_i18n( $fmt, strtotime( $days[ $i ] . ' 12:00 UTC' ) ) : '';
			echo '<i style="height:' . esc_attr( $h ) . '%" title="' . esc_attr( $day . ': ' . number_format_i18n( $v ) ) . '"></i>';
		}
		echo '</div><div class="marsad-bars-x" aria-hidden="true">';
		$marks = array_unique( array( 0, (int) floor( ( $count - 1 ) / 2 ), $count - 1 ) );
		foreach ( $marks as $i ) {
			echo '<span>' . esc_html( isset( $days[ $i ] ) ? date_i18n( 'j M', strtotime( $days[ $i ] . ' 12:00 UTC' ) ) : '' ) . '</span>';
		}
		echo '</div></div></div>';
		/* translators: 1: average value, 2: unit such as "posts" */
		echo '<p class="marsad-legend-line"><span class="marsad-key-avg"></span>' . esc_html( sprintf( __( 'Average %1$s %2$s a day', 'marsad' ), number_format_i18n( $avg, 1 ), $unit ) ) . '</p>';
	}

	/** Round up to 1, 2, 4, 6 or 8 times a power of ten, so the quarter-way axis labels are tidy. */
	public static function nice_max( $v ) {
		if ( $v <= 0 ) {
			return 0;
		}
		$p = pow( 10, floor( log10( $v ) ) );
		foreach ( array( 1, 2, 4, 6, 8, 10 ) as $m ) {
			if ( $v <= $m * $p ) {
				return $m * $p;
			}
		}
		return 10 * $p;
	}

	/**
	 * A ranked list with bars relative to the leader.
	 */
	private static function ranked( array $rows, $link = false, $avatars = false ) {
		if ( ! $rows ) {
			echo '<p class="marsad-sub">' . esc_html__( 'Nothing recorded in this period yet.', 'marsad' ) . '</p>';
			return;
		}
		$top = max( array_column( $rows, 'value' ) );
		echo '<ol class="marsad-rank">';
		foreach ( $rows as $i => $r ) {
			$name = esc_html( $link ? urldecode( $r['dim'] ) : $r['dim'] );
			if ( $link ) {
				$href = 0 === strpos( $r['dim'], 'http' ) ? $r['dim'] : home_url( $r['dim'] );
				$name = '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . $name . '</a>';
			}
			echo '<li>';
			if ( $avatars ) {
				echo '<span class="marsad-avatar" style="--c:' . esc_attr( self::COLORS[ $i % count( self::COLORS ) ] ) . '" aria-hidden="true">' . esc_html( mb_substr( $r['dim'], 0, 1 ) ) . '</span>';
			}
			echo '<div><p><span class="marsad-dim">' . $name . '</span><b>' . esc_html( Marsad_Admin::format( $r['value'] ) ) . '</b></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '<span class="marsad-track"><i style="width:' . esc_attr( $top > 0 ? round( $r['value'] / $top * 100, 1 ) : 0 ) . '%"></i></span></div></li>';
		}
		echo '</ol>';
	}

	/**
	 * Horizontal bars relative to the largest value: reads as a funnel when the
	 * source lists its steps in order.
	 */
	private static function funnel( array $rows ) {
		$vals = array_filter( array_column( $rows, 'value' ), 'is_numeric' );
		if ( ! $vals ) {
			echo '<p class="marsad-sub">' . esc_html__( 'Waiting for the first sync.', 'marsad' ) . '</p>';
			return;
		}
		$top = max( $vals );
		echo '<ul class="marsad-funnel">';
		foreach ( $rows as $r ) {
			$w = ( $top > 0 && null !== $r['value'] ) ? max( 1.5, round( $r['value'] / $top * 100, 1 ) ) : 0;
			echo '<li><span>' . esc_html( $r['dim'] ) . '</span><span class="marsad-track"><i style="width:' . esc_attr( $w ) . '%"></i></span><b>' . esc_html( Marsad_Admin::format( $r['value'] ) ) . '</b></li>';
		}
		echo '</ul>';
	}

	/**
	 * One bar split into parts, with a legend.
	 *
	 * @param array    $parts  array( array( label, value|null ), … )
	 * @param string[] $colors
	 * @param callable $fmt    value formatter for the legend
	 */
	private static function stacked( array $parts, array $colors = array(), $fmt = null ) {
		$colors = $colors ? $colors : self::COLORS;
		$total  = array_sum( array_map( function ( $p ) { return (float) $p[1]; }, $parts ) );
		if ( $total <= 0 ) {
			echo '<p class="marsad-sub">' . esc_html__( 'Recorded on the next sync.', 'marsad' ) . '</p>';
			return;
		}
		$fmt  = $fmt ? $fmt : function ( $v ) { return Marsad_Admin::format( $v ); };
		$desc = implode( '، ', array_map( function ( $p ) use ( $fmt ) { return $p[0] . ' ' . call_user_func( $fmt, $p[1] ); }, $parts ) );
		echo '<div class="marsad-stacked" role="img" aria-label="' . esc_attr( $desc ) . '">';
		foreach ( $parts as $i => $p ) {
			if ( (float) $p[1] > 0 ) {
				echo '<i style="flex-grow:' . esc_attr( (float) $p[1] ) . ';--c:' . esc_attr( $colors[ $i % count( $colors ) ] ) . '"></i>';
			}
		}
		echo '</div><ul class="marsad-list marsad-keyed">';
		foreach ( $parts as $i => $p ) {
			echo '<li><span><i style="--c:' . esc_attr( $colors[ $i % count( $colors ) ] ) . '"></i>' . esc_html( $p[0] ) . '</span><b>' . esc_html( call_user_func( $fmt, $p[1] ) ) . '</b></li>';
		}
		echo '</ul>';
	}

	/** The generic per-source block: KPI cards, a chart and top-N tables. */
	private static function section( array $s, array $status ) {
		/** @var Marsad_Connector $c */
		$c      = $s['connector'];
		$st     = $status[ $c->id() ] ?? array();
		$aside  = ( $st && empty( $st['ok'] ) ) ? '<span class="marsad-pill marsad-pill-bad" title="' . esc_attr( $st['message'] ) . '">' . esc_html__( 'Last sync failed', 'marsad' ) . '</span>' : '';
		$sub    = $c->is_snapshot() ? __( 'Recorded once a day; history starts the day you connected it.', 'marsad' ) : '';
		self::panel_open( $c->label(), $sub, 'full', $aside );

		$cards = array_filter( $s['metrics'], function ( $m ) { return ! empty( $m['kpi'] ); } );
		if ( $cards ) {
			echo '<div class="marsad-cards marsad-cards-inner">';
			$i = 0;
			foreach ( $cards as $m ) {
				self::stat( $m['label'], Marsad_Admin::format( $m['value'], $m['format'] ?? 'number' ), Marsad_Admin::change_badge( $m['change'], $m['better'] ?? 'up' ), self::COLORS[ $i++ % count( self::COLORS ) ], $m['series'] );
			}
			echo '</div>';
		}

		$sets = array();
		$i    = 0;
		foreach ( $s['metrics'] as $m ) {
			if ( ! empty( $m['chart'] ) && null !== $m['value'] && ( $m['format'] ?? 'number' ) === 'number' ) {
				$sets[] = array( 'label' => $m['label'], 'values' => $m['series'], 'color' => self::COLORS[ $i++ % count( self::COLORS ) ] );
			}
		}
		// One shared scale only reads well for up to three lines of similar size.
		$sets = array_slice( $sets, 0, 3 );
		if ( $sets && self::$report->length() > 1 ) {
			echo '<div class="marsad-chartbox">' . Marsad_Svg::line_chart( self::$report->days, $sets ) . '<ul class="marsad-legend">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $sets as $set ) {
				echo '<li style="--c:' . esc_attr( $set['color'] ) . '">' . esc_html( $set['label'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $s['tables'] ) {
			echo '<div class="marsad-cols">';
			foreach ( $s['tables'] as $t ) {
				echo '<div class="marsad-subpanel"><h4>' . esc_html( $t['label'] ) . '</h4>';
				self::ranked( $t['rows'], ! empty( $t['link'] ) );
				echo '</div>';
			}
			echo '</div>';
		}
		self::panel_close();
	}

	/* ── helpers ─────────────────────────────────────────────────────── */

	/** A metric of a source with its definition, or null when the source is off. */
	private static function metric( $source, $key ) {
		$c = Marsad_Registry::get( $source );
		if ( ! $c || ! Marsad_Registry::is_enabled( $c ) ) {
			return null;
		}
		$defs = $c->metrics();
		if ( ! isset( $defs[ $key ] ) ) {
			return null;
		}
		return array_merge( $defs[ $key ], self::$report->metric( $source, $key, $defs[ $key ] ) );
	}

	private static function enabled( $id ) {
		$c = Marsad_Registry::get( $id );
		return $c && Marsad_Registry::is_enabled( $c );
	}

	/** @return Marsad_Connector[] */
	private static function custom_sources() {
		$out = array();
		foreach ( Marsad_Registry::custom_ids() as $id ) {
			$c = Marsad_Registry::get( $id );
			if ( $c && Marsad_Registry::is_enabled( $c ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	private static function first_metric( Marsad_Connector $c ) {
		foreach ( $c->metrics() as $key => $def ) {
			$m = self::$report->metric( $c->id(), $key, $def );
			return null === $m['value'] ? null : array_merge( $def, $m );
		}
		return null;
	}

	/** @return array( state, label ) for a source's pill */
	private static function state( Marsad_Connector $c, array $st ) {
		if ( ! Marsad_Registry::is_enabled( $c ) ) {
			return array( 'off', __( 'Not connected', 'marsad' ) );
		}
		if ( ! $st ) {
			return array( 'wait', __( 'Waiting for first sync', 'marsad' ) );
		}
		return empty( $st['ok'] ) ? array( 'bad', __( 'Error', 'marsad' ) ) : array( 'ok', __( 'Working', 'marsad' ) );
	}

	private static function tab_link( $tab, $label ) {
		return '<a href="' . esc_url( Marsad_Admin::url( '', array( 'tab' => $tab, 'range' => self::$report->length() ) ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/** Megabytes as a readable size. */
	public static function size( $mb ) {
		if ( null === $mb ) {
			return '—';
		}
		// Isolated so "4.3 GB" keeps its order inside Arabic text.
		$text = $mb >= 1024 ? number_format_i18n( $mb / 1024, 1 ) . ' GB' : number_format_i18n( $mb, 1 ) . ' MB';
		return "\u{2066}" . $text . "\u{2069}";
	}

	private static function icon( $name ) {
		$paths = array(
			'logo' => '<path d="M3 20h18"/><path d="M6 16l4-5 3 3 5-7"/><circle cx="18" cy="7" r="1.5"/>',
			'sync' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
			'ok'   => '<circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/>',
			'warn' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5"/><path d="M12 18h.01"/>',
		);
		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}
