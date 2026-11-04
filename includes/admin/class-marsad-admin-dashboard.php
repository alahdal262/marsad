<?php
/**
 * The dashboard, in six tabs: Overview, Audience, Content & users, Search, Platforms, Hosting.
 * Everything is server-rendered markup and CSS; no external scripts or fonts. Numbers and tables
 * of a whole range come from the range snapshots (Google's own report for the period); the daily
 * rows only draw the charts.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Dashboard {

	const COLORS = array( '#1d4ed8', '#b45309', '#0f766e', '#7c3aed', '#be185d', '#0369a1' );

	/** Sources that have their own tab instead of a section on Platforms. */
	const CORE = array( 'wordpress', 'site' );

	/** Metrics a platform card shows, in order, for sources whose KPIs are not the right summary. */
	const CARD_KEYS = array(
		'site' => array( 'posts_total', 'images', 'users', 'comments_approved' ),
	);

	/** @var Marsad_Report */
	private static $report;

	/** @var array source => report => payload of the range shown, read once per page */
	private static $snaps = array();

	public static function tabs() {
		return array(
			'overview'  => __( 'Overview', 'marsad' ),
			'audience'  => __( 'Audience', 'marsad' ),
			'content'   => __( 'Content & users', 'marsad' ),
			'search'    => __( 'Search', 'marsad' ),
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
		self::$snaps  = array();
		$report       = self::$report;
		$status       = Marsad_Sync::status();
		$last         = $status ? max( array_column( $status, 'at' ) ) : 0;

		echo '<div class="wrap marsad marsad-dash">';
		echo '<header class="marsad-head"><div class="marsad-brand"><span class="marsad-logo" aria-hidden="true">' . self::icon( 'logo' ) . '</span><div><h1>' . esc_html__( 'Marsad', 'marsad' ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo '<span class="marsad-sub">' . esc_html( get_bloginfo( 'name' ) . ' · ' . wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</span></div></div>';
		echo '<div class="marsad-actions"><nav class="marsad-ranges" aria-label="' . esc_attr__( 'Period', 'marsad' ) . '">';
		foreach ( Marsad_Report::RANGES as $r ) {
			/* translators: %d: number of days */
			$label = 0 === $r ? __( 'All time', 'marsad' ) : ( 365 === $r ? __( 'Year', 'marsad' ) : sprintf( __( '%d days', 'marsad' ), $r ) );
			$on    = $report->key() === $r;
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
			echo '<a href="' . esc_url( Marsad_Admin::url( '', array( 'tab' => $key, 'range' => $report->key() ) ) ) . '"' . ( $on ? ' class="is-on" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		Marsad_Admin::print_notice();

		echo '<div class="marsad-titlebar"><h2>' . esc_html( self::tabs()[ $tab ] ) . '</h2><span class="marsad-sub">';
		echo esc_html(
			sprintf(
				/* translators: 1: start date, 2: end date, 3: e.g. "5 minutes ago" */
				$report->all ? __( '%1$s – %2$s, everything recorded · synced %3$s', 'marsad' ) : __( '%1$s – %2$s, compared with the period before · synced %3$s', 'marsad' ),
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

	/**
	 * An infographic of every platform: headline numbers, key findings, one card per source,
	 * the audience in pictures, then the daily posts chart.
	 */
	private static function tab_overview( array $status ) {
		$report   = self::$report;
		$sections = $report->sections();

		echo '<section class="marsad-cards" aria-label="' . esc_attr__( 'Key numbers', 'marsad' ) . '">';
		$i = 0;
		foreach ( self::headline( $report->headline( $sections ) ) as $h ) {
			$sub = Marsad_Admin::change_badge( $h['change'] ) . ( '' !== $h['note'] ? ' <small class="marsad-note">' . esc_html( $h['note'] ) . '</small>' : '' );
			self::stat( $h['label'], $h['value'], $sub, self::COLORS[ $i++ % count( self::COLORS ) ], $h['series'] );
		}
		if ( ! self::enabled( 'ga4' ) ) {
			self::connect_card( __( 'Website visitors', 'marsad' ), 'ga4', __( 'Connect Google Analytics', 'marsad' ) );
		}
		echo '</section>';

		self::findings();
		self::platform_cards( $sections, $status );
		self::infographic_row();

		$posts = self::metric( 'wordpress', 'posts' );
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
	}

	/**
	 * The six headline numbers. Visitors, page views and search clicks are Google's own figures
	 * for the range (users counted once); without a snapshot they fall back to the daily rows,
	 * where users can only be a daily average and say so.
	 *
	 * @param array $daily Marsad_Report::headline()
	 * @return array[] list of array( label, value (formatted), change, note, series )
	 */
	private static function headline( array $daily ) {
		$defs = array(
			'visitors' => array( __( 'Visitors', 'marsad' ), 'ga4', 'activeUsers', __( 'daily average', 'marsad' ) ),
			'views'    => array( __( 'Page views', 'marsad' ), 'ga4', 'screenPageViews', '' ),
			'search'   => array( __( 'Search clicks', 'marsad' ), 'search_console', 'clicks', '' ),
			'social'   => array( __( 'Social audience', 'marsad' ), '', '', '' ),
			'installs' => array( __( 'App downloads', 'marsad' ), '', '', '' ),
			'posts'    => array( __( 'Posts published', 'marsad' ), '', '', '' ),
		);
		// Entries added through the marsad_headline filter keep their own label, after ours.
		foreach ( $daily as $key => $h ) {
			if ( ! isset( $defs[ $key ] ) ) {
				$defs[ $key ] = array( $h['label'], '', '', '' );
			}
		}
		$out = array();
		foreach ( $defs as $key => $d ) {
			$series = $daily[ $key ]['series'] ?? array();
			$snap   = $d[1] ? self::total( $d[1], $d[2] ) : null;
			if ( $snap ) {
				$out[] = array( 'label' => $d[0], 'value' => Marsad_Admin::format( $snap['value'] ), 'change' => $snap['change'], 'note' => '', 'series' => $series );
			} elseif ( isset( $daily[ $key ] ) ) {
				$out[] = array( 'label' => $d[0], 'value' => Marsad_Admin::format( $daily[ $key ]['value'] ), 'change' => $daily[ $key ]['change'], 'note' => $d[3], 'series' => $series );
			}
		}
		return $out;
	}

	/** Short sentences from Marsad_Insights, each linking to the tab that explains it. */
	private static function findings() {
		$list = Marsad_Insights::from_snapshots( self::$report->range_key() );
		self::panel_open( __( 'Key findings', 'marsad' ), __( 'What stands out in this period', 'marsad' ), 'full' );
		if ( ! $list ) {
			echo '<p class="marsad-sub">' . esc_html__( 'Findings appear here once Google Analytics, Search Console or your posts have enough data for this period.', 'marsad' ) . '</p>';
			self::panel_close();
			return;
		}
		$tones = array(
			'good'    => array( '▲', __( 'Good news:', 'marsad' ) ),
			'bad'     => array( '▼', __( 'Needs attention:', 'marsad' ) ),
			'neutral' => array( '●', __( 'Note:', 'marsad' ) ),
		);
		$tabs  = self::tabs();
		echo '<ul class="marsad-findings">';
		foreach ( $list as $f ) {
			$tone = isset( $tones[ $f['tone'] ] ) ? $f['tone'] : 'neutral';
			$tab  = isset( $tabs[ $f['tab'] ] ) ? $f['tab'] : 'overview';
			$url  = Marsad_Admin::url( '', array( 'tab' => $tab, 'range' => self::$report->key() ) ) . ( '' !== $f['anchor'] ? '#marsad-' . $f['anchor'] : '' );
			echo '<li class="marsad-finding marsad-finding-' . esc_attr( $tone ) . '"><span class="marsad-finding-icon" aria-hidden="true">' . esc_html( $tones[ $tone ][0] ) . '</span>';
			echo '<p><span class="marsad-sr">' . esc_html( $tones[ $tone ][1] ) . ' </span>' . esc_html( $f['text'] ) . '</p>';
			/* translators: %s: dashboard tab name */
			echo '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'Details in %s', 'marsad' ), $tabs[ $tab ] ) ) . '">' . esc_html__( 'Details', 'marsad' ) . '</a></li>';
		}
		echo '</ul>';
		self::panel_close();
	}

	/** One card per enabled source, then the ones still to connect. */
	private static function platform_cards( array $sections, array $status ) {
		echo '<section class="marsad-platforms" aria-labelledby="marsad-platforms-h"><div class="marsad-titlebar"><h3 id="marsad-platforms-h">' . esc_html__( 'Every platform at a glance', 'marsad' ) . '</h3></div>';
		echo '<div class="marsad-pcards">';
		foreach ( $sections as $i => $s ) {
			self::platform_card( $s, $status[ $s['connector']->id() ] ?? array(), Marsad_Admin_Infographics::COLORS[ $i % count( Marsad_Admin_Infographics::COLORS ) ] );
		}
		$off    = array_filter(
			Marsad_Registry::all(),
			function ( $c ) {
				return ! in_array( $c->id(), self::CORE, true ) && ! Marsad_Registry::is_custom( $c->id() ) && ! Marsad_Registry::is_enabled( $c );
			}
		);
		$manage = current_user_can( Marsad_Plugin::CAP_MANAGE );
		if ( $off || $manage ) {
			echo '<article class="marsad-pcard marsad-pcard-off"><header><h4>' . esc_html__( 'Not connected yet', 'marsad' ) . '</h4></header>';
			if ( $off ) {
				echo '<ul class="marsad-list">';
				foreach ( $off as $c ) {
					echo '<li><span>' . esc_html( $c->label() ) . '</span>';
					if ( $manage ) {
						echo '<a href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => $c->id() ) ) . '#conn-' . $c->id() ) . '">' . esc_html__( 'Connect', 'marsad' ) . '</a>';
					}
					echo '</li>';
				}
				echo '</ul>';
			}
			if ( $manage ) {
				echo '<a class="marsad-pcard-link" href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => Marsad_Admin_Connections::NEW_ID ) ) . '#conn-new' ) . '">' . esc_html__( '+ Add a source', 'marsad' ) . '</a>';
			}
			echo '</article>';
		}
		echo '</div></section>';
	}

	private static function platform_card( array $s, array $st, $color ) {
		/** @var Marsad_Connector $c */
		$c      = $s['connector'];
		$tab    = self::source_tab( $c->id() );
		$url    = Marsad_Admin::url( '', array( 'tab' => $tab, 'range' => self::$report->key() ) ) . ( 'platforms' === $tab ? '#marsad-src-' . $c->id() : '' );
		$failed = $st && empty( $st['ok'] );
		echo '<article class="marsad-pcard' . ( $failed ? ' marsad-pcard-bad' : '' ) . '" style="--c:' . esc_attr( $color ) . '">';
		echo '<header><span class="marsad-badge" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $c->label(), 0, 1 ) ) ) . '</span><h4>' . esc_html( $c->label() ) . '</h4>';
		if ( $failed ) {
			echo '<span class="marsad-pill marsad-pill-bad">' . esc_html__( 'Error', 'marsad' ) . '</span>';
		}
		echo '</header>';

		$n = $failed ? null : self::card_numbers( $s );
		if ( $failed ) {
			echo '<div class="marsad-pcard-error"><b>' . esc_html__( 'Last sync failed', 'marsad' ) . '</b><span>' . esc_html( self::cut( (string) ( $st['message'] ?? '' ), 180 ) ) . '</span></div>';
			if ( current_user_can( Marsad_Plugin::CAP_MANAGE ) ) {
				echo '<a href="' . esc_url( Marsad_Admin::url( 'connections', array( 'open' => $c->id() ) ) . '#conn-' . $c->id() ) . '">' . esc_html__( 'Fix it in Connections', 'marsad' ) . '</a>';
			}
		} elseif ( ! $n ) {
			echo '<p class="marsad-sub">' . esc_html__( 'Waiting for the first sync.', 'marsad' ) . '</p>';
		} else {
			echo '<div class="marsad-pcard-main"><b class="marsad-num">' . esc_html( $n['value'] ) . '</b><span class="marsad-label">' . esc_html( $n['label'] ) . '</span>' . Marsad_Admin::change_badge( $n['change'], $n['better'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is escaped markup.
			if ( $n['subs'] ) {
				echo '<dl class="marsad-pcard-sub">';
				foreach ( $n['subs'] as $sub ) {
					echo '<div><dt>' . esc_html( $sub[0] ) . '</dt><dd>' . esc_html( $sub[1] ) . '</dd></div>';
				}
				echo '</dl>';
			}
			echo $n['bars'] ? Marsad_Admin_Infographics::mini_bars( $n['series'], $color ) : Marsad_Svg::sparkline( $n['series'], $color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$err = $c->supports_snapshots() ? ( Marsad_Snapshots::errors( $c->id() )[ self::$report->range_key() ] ?? null ) : null;
			if ( $err ) {
				echo '<p class="marsad-sub marsad-warn-text" title="' . esc_attr( (string) ( $err['message'] ?? '' ) ) . '">' . esc_html__( 'Some reports of this period could not be fetched.', 'marsad' ) . '</p>';
			}
		}
		/* translators: %s: source name */
		echo '<a class="marsad-pcard-link" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'Details: %s', 'marsad' ), $c->label() ) ) . '">' . esc_html__( 'Details', 'marsad' ) . '</a>';
		echo '</article>';
	}

	/**
	 * The card's big number and up to three smaller ones. Google sources read their range
	 * snapshot; the rest read their daily KPIs.
	 *
	 * @return array|null array( label, value, change, better, subs => list of array( label, value ), series, bars )
	 */
	private static function card_numbers( array $s ) {
		$c     = $s['connector'];
		$id    = $c->id();
		$snaps = array(
			'ga4'            => array( 'users', array( 'activeUsers' => __( 'Users', 'marsad' ), 'sessions' => __( 'Sessions', 'marsad' ), 'screenPageViews' => __( 'Page views', 'marsad' ), 'engagementRate' => __( 'Engagement rate', 'marsad' ) ) ),
			'search_console' => array( 'clicks', array( 'clicks' => __( 'Search clicks', 'marsad' ), 'impressions' => __( 'Search impressions', 'marsad' ), 'ctr' => __( 'Click-through rate', 'marsad' ), 'position' => __( 'Average position', 'marsad' ) ) ),
		);
		if ( isset( $snaps[ $id ] ) && self::total( $id, (string) array_keys( $snaps[ $id ][1] )[0] ) ) {
			$out = null;
			foreach ( $snaps[ $id ][1] as $key => $label ) {
				$t = self::total( $id, $key );
				if ( ! $t ) {
					continue;
				}
				$value = Marsad_Admin_Table::fmt( $t['value'], Marsad_Admin_Table::format_of( $c, $key, false ) );
				if ( ! $out ) {
					$out = array( 'label' => $label, 'value' => $value, 'change' => $t['change'], 'better' => Marsad_Admin_Table::better( $key ), 'subs' => array(), 'series' => $s['metrics'][ $snaps[ $id ][0] ]['series'] ?? array(), 'bars' => false );
				} else {
					$out['subs'][] = array( $label, $value );
				}
			}
			return $out;
		}
		if ( 'wordpress' === $id ) {
			$p = $s['metrics']['posts'] ?? null;
			if ( ! $p || null === $p['value'] ) {
				return null;
			}
			$comments = $s['metrics']['comments'] ?? array();
			return array(
				'label'  => __( 'Posts published', 'marsad' ),
				'value'  => Marsad_Admin::format( $p['value'] ),
				'change' => $p['change'],
				'better' => 'up',
				'subs'   => array(
					array( __( 'Posts per day', 'marsad' ), number_format_i18n( $p['value'] / max( 1, self::$report->length() ), 1 ) ),
					array( __( 'Busiest day', 'marsad' ), number_format_i18n( $p['series'] ? max( $p['series'] ) : 0 ) ),
					array( $comments['label'] ?? __( 'Approved comments', 'marsad' ), Marsad_Admin::format( $comments['value'] ?? null ) ),
				),
				'series' => $p['series'],
				'bars'   => true,
			);
		}
		$list = array_filter(
			$s['metrics'],
			function ( $m ) {
				return null !== $m['value'];
			}
		);
		if ( isset( self::CARD_KEYS[ $id ] ) ) {
			// The listed metrics, in the listed order.
			$list = array_filter( array_replace( array_flip( self::CARD_KEYS[ $id ] ), array_intersect_key( $list, array_flip( self::CARD_KEYS[ $id ] ) ) ), 'is_array' );
		} else {
			$kpi  = array_filter(
				$list,
				function ( $m ) {
					return ! empty( $m['kpi'] );
				}
			);
			$list = $kpi ? $kpi : $list;
		}
		if ( ! $list ) {
			return null;
		}
		$first = array_shift( $list );
		$subs  = array();
		foreach ( array_slice( $list, 0, 3 ) as $m ) {
			$subs[] = array( $m['label'], Marsad_Admin::format( $m['value'], $m['format'] ?? 'number' ) );
		}
		return array(
			'label'  => $first['label'],
			'value'  => Marsad_Admin::format( $first['value'], $first['format'] ?? 'number' ),
			'change' => $first['change'],
			'better' => $first['better'] ?? 'up',
			'subs'   => $subs,
			'series' => $first['series'],
			'bars'   => false,
		);
	}

	/** Channels donut, devices, new vs returning, top countries and search types. */
	private static function infographic_row() {
		$ga = self::enabled( 'ga4' );
		$sc = self::enabled( 'search_console' );
		if ( ! $ga && ! $sc ) {
			return;
		}
		echo '<div class="marsad-titlebar"><h3>' . esc_html__( 'The audience in pictures', 'marsad' ) . '</h3></div><div class="marsad-cols marsad-info">';
		if ( $ga ) {
			self::panel_open( __( 'Traffic channels', 'marsad' ), __( 'Sessions by channel', 'marsad' ), '', self::tab_link( 'audience', __( 'All', 'marsad' ) ) );
			if ( ! Marsad_Admin_Infographics::donut( self::parts( 'ga4', 'channels', 'sessions' ), __( 'Traffic channels', 'marsad' ), __( 'sessions', 'marsad' ) ) ) {
				self::waiting();
			}
			self::panel_close();

			self::panel_open( __( 'Devices', 'marsad' ), __( 'Share of active users', 'marsad' ) );
			if ( ! Marsad_Admin_Infographics::split( self::parts( 'ga4', 'devices', 'activeUsers' ), __( 'Devices', 'marsad' ) ) ) {
				self::waiting();
			}
			echo '<h4 class="marsad-subhead">' . esc_html__( 'New and returning visitors', 'marsad' ) . '</h4>';
			if ( ! Marsad_Admin_Infographics::split( self::parts( 'ga4', 'new_returning', 'activeUsers' ), __( 'New and returning visitors', 'marsad' ) ) ) {
				self::waiting();
			}
			self::panel_close();
		}

		// Search Console's countries are ISO codes, GA4's are names; visitors read better, so GA4 first.
		$countries = $ga ? self::parts( 'ga4', 'countries', 'activeUsers' ) : self::parts( 'search_console', 'countries', 'clicks' );
		self::panel_open( __( 'Top countries', 'marsad' ), $ga ? __( 'Active users', 'marsad' ) : __( 'Search clicks', 'marsad' ) );
		if ( ! Marsad_Admin_Infographics::countries( $countries ) ) {
			self::waiting();
		}
		self::panel_close();

		if ( $sc ) {
			$clicks = array();
			$shown  = array();
			foreach ( self::search_types() as $type => $label ) {
				$t = self::total( 'search_console', 'clicks', 'totals_' . $type );
				$i = self::total( 'search_console', 'impressions', 'totals_' . $type );
				if ( $t ) {
					$clicks[] = array( $label, $t['value'] );
				}
				if ( $i ) {
					$shown[] = array( $label, $i['value'] );
				}
			}
			self::panel_open( __( 'Search types', 'marsad' ), __( 'Clicks from each kind of Google search', 'marsad' ), '', self::tab_link( 'search', __( 'All', 'marsad' ) ) );
			if ( ! Marsad_Admin_Infographics::split( $clicks, __( 'Search clicks', 'marsad' ) ) ) {
				self::waiting();
			}
			if ( $shown ) {
				echo '<h4 class="marsad-subhead">' . esc_html__( 'Search impressions', 'marsad' ) . '</h4>';
				Marsad_Admin_Infographics::split( $shown, __( 'Search impressions', 'marsad' ) );
			}
			self::panel_close();
		}
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
		$ga    = Marsad_Registry::get( 'ga4' );
		$pages = self::snap( 'ga4', 'pages' );
		self::panel_open( __( 'Most read', 'marsad' ), __( 'From Google Analytics 4', 'marsad' ), 'wide', '', $pages ? 'marsad-ga4-pages' : '' );
		$top = ( self::enabled( 'ga4' ) && ! $pages ) ? $report->table( 'ga4', 'views_by_page', array( 'agg' => 'sum', 'limit' => 10 ) ) : array();
		if ( $pages ) {
			Marsad_Admin_Table::render(
				$ga,
				$report->range_key(),
				'pages',
				$pages,
				array(
					'title'   => __( 'Most read', 'marsad' ),
					'derive'  => array( 'avgEngagement' ),
					'columns' => array( 'screenPageViews', 'activeUsers', 'avgEngagement', 'engagementRate' ),
					'change'  => 'screenPageViews',
				)
			);
		} elseif ( $top ) {
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
		self::panel_open( __( 'Posts by status', 'marsad' ), __( 'Right now, including drafts and the trash', 'marsad' ) );
		$status_parts = array();
		foreach ( array( 'posts_total' => __( 'Published', 'marsad' ), 'posts_draft' => $defs['posts_draft']['label'], 'posts_pending' => $defs['posts_pending']['label'], 'posts_future' => $defs['posts_future']['label'], 'posts_private' => $defs['posts_private']['label'], 'posts_trash' => $defs['posts_trash']['label'] ) as $k => $label ) {
			$status_parts[] = array( $label, self::metric( 'site', $k )['value'] ?? null );
		}
		self::stacked( $status_parts, array( '#047857', '#1d4ed8', '#b45309', '#7c3aed', '#5b6472', '#b91c1c' ) );
		self::panel_close();

		self::panel_open( __( 'Deleted posts', 'marsad' ), __( 'In the selected period', 'marsad' ) );
		$trashed = self::metric( 'wordpress', 'posts_trashed' );
		$gone    = self::metric( 'wordpress', 'posts_deleted' );
		echo '<ul class="marsad-list">';
		echo '<li><span>' . esc_html__( 'Moved to the trash', 'marsad' ) . '</span><b>' . esc_html( Marsad_Admin::format( $trashed['value'] ?? 0 ) ) . '</b></li>';
		echo '<li><span>' . esc_html__( 'Deleted permanently', 'marsad' ) . '<small>' . esc_html__( 'Counted from the day Marsad was installed: WordPress keeps no record of older ones.', 'marsad' ) . '</small></span><b>' . esc_html( Marsad_Admin::format( $gone['value'] ?? 0 ) ) . '</b></li>';
		echo '<li><span>' . esc_html__( 'In the trash now', 'marsad' ) . '</span><b>' . esc_html( Marsad_Admin::format( self::metric( 'site', 'posts_trash' )['value'] ?? null ) ) . '</b></li>';
		echo '</ul>';
		self::panel_close();
		echo '</div>';

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

		self::content_from_ga( $ga );
	}

	/** Landing pages, authors, categories and content age as Google Analytics measured them. */
	private static function content_from_ga( $ga ) {
		if ( ! $ga || ! self::enabled( 'ga4' ) ) {
			return;
		}
		$age = self::content_age( self::snap( 'ga4', 'post_dates' ) );
		if ( ! array_intersect_key( self::snaps( 'ga4' ), array_flip( array( 'landing', 'authors', 'categories' ) ) ) && ! $age ) {
			return;
		}
		echo '<div class="marsad-titlebar"><h3>' . esc_html__( 'What readers read', 'marsad' ) . '</h3><span class="marsad-sub">' . esc_html__( 'From Google Analytics 4, for the whole period', 'marsad' ) . '</span></div>';
		echo '<div class="marsad-cols">';
		self::report_panel( $ga, 'landing', __( 'Landing pages', 'marsad' ), __( 'Where visits begin', 'marsad' ), 'full' );
		self::report_panel( $ga, 'authors', __( 'Authors', 'marsad' ), __( 'Views of each author\'s posts', 'marsad' ), '', array( 'derive' => array( 'avgEngagement' ), 'columns' => array( 'screenPageViews', 'activeUsers', 'avgEngagement' ) ) );
		self::report_panel( $ga, 'categories', __( 'Categories', 'marsad' ), __( 'Views of each category\'s posts', 'marsad' ) );
		if ( $age ) {
			self::panel_open( __( 'Content age', 'marsad' ), __( 'Views in this period by the month the post was published', 'marsad' ), 'wide', '', 'marsad-ga4-post_dates' );
			Marsad_Admin_Infographics::columns( $age, __( 'Content age', 'marsad' ) );
			self::panel_close();
		}
		echo '</div>';
	}

	/**
	 * Views by publish month, from the post date Site Kit records (20240115 or 2024-01-15),
	 * the last 24 months that have views.
	 *
	 * @return array "Jan 2024" => views, oldest first
	 */
	private static function content_age( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}
		$months = array();
		foreach ( Marsad_Insights::rows_of( $payload ) as $r ) {
			$digits = preg_replace( '/\D/', '', $r['d'][0] ?? '' );
			$v      = array_values( $r['m'] )[0] ?? null;
			if ( strlen( $digits ) < 6 || null === $v ) {
				continue;
			}
			$ym            = substr( $digits, 0, 4 ) . '-' . substr( $digits, 4, 2 );
			$months[ $ym ] = ( $months[ $ym ] ?? 0 ) + $v;
		}
		ksort( $months );
		$out = array();
		foreach ( array_slice( $months, -24, null, true ) as $ym => $v ) {
			$out[ date_i18n( 'M Y', (int) strtotime( $ym . '-01 12:00 UTC' ) ) ] = $v;
		}
		return $out;
	}

	/* ── audience ────────────────────────────────────────────────────── */

	private static function tab_audience( array $status ) {
		$c = self::ready( 'ga4', __( 'Who visits, from where, on which devices and at what time appears here once Google Analytics 4 is connected.', 'marsad' ) );
		if ( ! $c ) {
			return;
		}
		if ( ! self::snaps( 'ga4' ) ) {
			echo '<div class="marsad-cols">';
			self::realtime_panel();
			self::no_snapshots( 'ga4' );
			echo '</div>';
			return;
		}
		self::snap_note( 'ga4' );
		self::totals_cards( $c, self::snap( 'ga4', 'totals' ) );

		echo '<div class="marsad-cols">';
		self::realtime_panel();
		$heat = self::snap( 'ga4', 'heatmap' );
		if ( $heat ) {
			self::panel_open( __( 'When visitors come', 'marsad' ), __( 'Active users by weekday and hour', 'marsad' ), 'wide', '', 'marsad-ga4-heatmap' );
			if ( ! Marsad_Admin_Infographics::heatmap( $heat, __( 'When visitors come', 'marsad' ) ) ) {
				self::waiting();
			}
			self::panel_close();
		}
		echo '</div>';

		$none = __( 'Not available for this property: Google signals is off, or too few visitors to show.', 'marsad' );
		$sets = array(
			__( 'Where visitors come from', 'marsad' ) => array(
				'channels'      => array( __( 'Channels', 'marsad' ), '' ),
				'source_medium' => array( __( 'Source / medium', 'marsad' ), '' ),
				'campaigns'     => array( __( 'Campaigns', 'marsad' ), '' ),
				'social'        => array( __( 'Social networks', 'marsad' ), '' ),
				'referrals'     => array( __( 'Referring sites', 'marsad' ), '' ),
			),
			__( 'Where they are', 'marsad' )           => array(
				'countries' => array( __( 'Countries', 'marsad' ), '' ),
				'cities'    => array( __( 'Cities', 'marsad' ), '' ),
				'languages' => array( __( 'Languages', 'marsad' ), '' ),
			),
			__( 'Who they are and what they use', 'marsad' ) => array(
				'new_returning' => array( __( 'New and returning', 'marsad' ), '' ),
				'age'           => array( __( 'Age', 'marsad' ), $none ),
				'gender'        => array( __( 'Gender', 'marsad' ), $none ),
				'devices'       => array( __( 'Devices', 'marsad' ), '' ),
				'os'            => array( __( 'Operating systems', 'marsad' ), '' ),
				'browsers'      => array( __( 'Browsers', 'marsad' ), '' ),
				'models'        => array( __( 'Phone models', 'marsad' ), '' ),
				'resolutions'   => array( __( 'Screen sizes', 'marsad' ), '' ),
			),
			__( 'What they do', 'marsad' )             => array(
				'events'       => array( __( 'Events', 'marsad' ), '' ),
				'key_events'   => array( __( 'Key events', 'marsad' ), '' ),
				'search_terms' => array( __( 'Searches on the site', 'marsad' ), '' ),
				'outbound'     => array( __( 'Outbound links', 'marsad' ), '' ),
				'scroll'       => array( __( 'Scroll depth', 'marsad' ), '' ),
			),
		);
		foreach ( $sets as $heading => $reports ) {
			if ( ! array_intersect_key( self::snaps( 'ga4' ), $reports ) ) {
				continue;
			}
			echo '<div class="marsad-titlebar"><h3>' . esc_html( $heading ) . '</h3></div><div class="marsad-cols">';
			foreach ( $reports as $key => $r ) {
				self::report_panel( $c, $key, $r[0], '', '', '' !== $r[1] ? array( 'empty' => $r[1] ) : array() );
			}
			echo '</div>';
		}
	}

	/** Active users now, polled every minute from wp_ajax_marsad_realtime by assets/admin.js. */
	private static function realtime_panel() {
		echo '<section class="marsad-panel marsad-realtime" id="marsad-realtime" data-marsad-realtime data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'marsad_realtime' ) ) . '"';
		/* translators: %s: time of day */
		echo ' data-error="' . esc_attr__( 'Live numbers are not available right now.', 'marsad' ) . '" data-updated="' . esc_attr__( 'Updated at %s', 'marsad' ) . '" data-empty="' . esc_attr__( 'Nobody right now.', 'marsad' ) . '">';
		echo '<header><div><h3>' . esc_html__( 'Right now', 'marsad' ) . '</h3><span class="marsad-sub">' . esc_html__( 'Active users in the last 30 minutes, refreshed every minute', 'marsad' ) . '</span></div><span class="marsad-live" aria-hidden="true"></span></header>';
		echo '<div class="marsad-rt-now"><b class="marsad-num" data-rt="active" aria-live="polite">—</b><span class="marsad-label">' . esc_html__( 'active users', 'marsad' ) . '</span></div>';
		echo '<div class="marsad-rt-lists">';
		foreach ( array( 'pages' => __( 'Pages being viewed', 'marsad' ), 'countries' => __( 'Countries', 'marsad' ), 'devices' => __( 'Devices', 'marsad' ) ) as $key => $title ) {
			echo '<div><h4>' . esc_html( $title ) . '</h4><ol class="marsad-list marsad-rt-list" data-rt="' . esc_attr( $key ) . '"></ol></div>';
		}
		echo '</div><p class="marsad-sub" data-rt="status">' . esc_html__( 'Loading…', 'marsad' ) . '</p>';
		echo '<noscript><p class="marsad-sub">' . esc_html__( 'Live numbers need JavaScript.', 'marsad' ) . '</p></noscript>';
		echo '</section>';
	}

	/* ── search ──────────────────────────────────────────────────────── */

	private static function tab_search( array $status ) {
		$c = self::ready( 'search_console', __( 'Search queries, pages, countries and opportunities from Google Search appear here once Search Console is connected.', 'marsad' ) );
		if ( ! $c ) {
			return;
		}
		if ( ! self::snaps( 'search_console' ) ) {
			echo '<div class="marsad-cols">';
			self::no_snapshots( 'search_console' );
			echo '</div>';
			return;
		}
		self::snap_note( 'search_console' );

		echo '<section class="marsad-cards" aria-label="' . esc_attr__( 'Search types', 'marsad' ) . '">';
		$i = 0;
		foreach ( self::search_types() as $type => $label ) {
			$report = self::snap( 'search_console', 'totals_' . $type ) ? 'totals_' . $type : ( 'web' === $type ? 'totals' : '' );
			$clicks = $report ? self::total( 'search_console', 'clicks', $report ) : null;
			if ( ! $clicks ) {
				continue;
			}
			$sub = Marsad_Admin::change_badge( $clicks['change'] ) . '<span class="marsad-mini">';
			foreach ( array( 'impressions' => __( 'Search impressions', 'marsad' ), 'ctr' => __( 'Click-through rate', 'marsad' ), 'position' => __( 'Average position', 'marsad' ) ) as $key => $name ) {
				$t    = self::total( 'search_console', $key, $report );
				$sub .= '<span>' . esc_html( $name ) . ' <b>' . esc_html( Marsad_Admin_Table::fmt( $t ? $t['value'] : null, Marsad_Admin_Table::format_of( $c, $key, false ) ) ) . '</b></span>';
			}
			/* translators: %s: kind of search, e.g. "Image search" */
			self::stat( sprintf( __( 'Clicks from %s', 'marsad' ), $label ), Marsad_Admin::format( $clicks['value'] ), $sub . '</span>', self::COLORS[ $i++ % count( self::COLORS ) ] );
		}
		echo '</section>';

		echo '<div class="marsad-cols">';
		self::report_panel( $c, 'queries', __( 'Search queries', 'marsad' ), __( 'What people typed before they saw the site', 'marsad' ), 'full' );
		self::report_panel( $c, 'pages', __( 'Pages', 'marsad' ), __( 'Pages shown in Google Search', 'marsad' ), 'full' );
		self::report_panel( $c, 'opportunities', __( 'Opportunities', 'marsad' ), __( 'Queries on pages one and two with fewer clicks than usual for their position', 'marsad' ), 'full', array( 'change' => '' ) );
		self::report_panel( $c, 'rising', __( 'Rising queries', 'marsad' ), __( 'Most clicks gained since the previous period', 'marsad' ) );
		self::report_panel( $c, 'falling', __( 'Falling queries', 'marsad' ), __( 'Most clicks lost since the previous period', 'marsad' ) );
		self::report_panel( $c, 'countries', __( 'Countries', 'marsad' ) );
		self::report_panel( $c, 'devices', __( 'Devices', 'marsad' ) );
		self::report_panel( $c, 'appearance', __( 'Search appearance', 'marsad' ), __( 'Rich results, videos and other ways the site appeared', 'marsad' ) );
		self::report_panel( $c, 'query_pages', __( 'Queries and their pages', 'marsad' ), __( 'Which page Google showed for each query', 'marsad' ), 'full' );
		self::report_panel( $c, 'sitemaps', __( 'Sitemaps', 'marsad' ), __( 'As Search Console last read them', 'marsad' ), 'full', array( 'change' => '' ) );
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
			self::panel_open( $c->label(), '', 'full', '<span class="marsad-pill marsad-pill-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>', 'marsad-src-' . $c->id() );
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
			self::bars( array_map( function ( $v ) { return null === $v ? 0 : $v; }, $resp['series'] ), $report->days, __( 'ms', 'marsad' ), 'avg' );
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

	private static function panel_open( $title, $sub = '', $size = '', $aside_html = '', $id = '' ) {
		echo '<section class="marsad-panel' . ( $size ? ' marsad-panel-' . esc_attr( $size ) : '' ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '><header><div><h3>' . esc_html( $title ) . '</h3>';
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
	private static function bars( array $values, array $days, $unit, $agg = 'sum' ) {
		$monthly = count( $values ) > 120;
		if ( $monthly ) {
			// Long periods read better as one column per month.
			$buckets = array();
			foreach ( $values as $i => $v ) {
				$buckets[ substr( $days[ $i ], 0, 7 ) ][] = (float) $v;
			}
			$values = array();
			$days   = array();
			foreach ( $buckets as $ym => $vs ) {
				$values[] = 'avg' === $agg ? array_sum( $vs ) / count( $vs ) : array_sum( $vs );
				$days[]   = $ym . '-01';
			}
		}
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
		$fmt = $monthly ? 'F Y' : $fmt;
		foreach ( $values as $i => $v ) {
			$h   = $top > 0 ? round( $v / $top * 100, 2 ) : 0;
			$day = isset( $days[ $i ] ) ? date_i18n( $fmt, strtotime( $days[ $i ] . ' 12:00 UTC' ) ) : '';
			echo '<i style="height:' . esc_attr( $h ) . '%" title="' . esc_attr( $day . ': ' . number_format_i18n( $v ) ) . '"></i>';
		}
		echo '</div><div class="marsad-bars-x" aria-hidden="true">';
		$marks = array_unique( array( 0, (int) floor( ( $count - 1 ) / 2 ), $count - 1 ) );
		foreach ( $marks as $i ) {
			echo '<span>' . esc_html( isset( $days[ $i ] ) ? date_i18n( $monthly ? 'M Y' : 'j M', strtotime( $days[ $i ] . ' 12:00 UTC' ) ) : '' ) . '</span>';
		}
		echo '</div></div></div>';
		/* translators: 1: average value, 2: unit such as "posts" */
		$line = $monthly ? __( 'Average %1$s %2$s a month', 'marsad' ) : __( 'Average %1$s %2$s a day', 'marsad' );
		echo '<p class="marsad-legend-line"><span class="marsad-key-avg"></span>' . esc_html( sprintf( $line, number_format_i18n( $avg, 1 ), $unit ) ) . '</p>';
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
		self::panel_open( $c->label(), $sub, 'full', $aside, 'marsad-src-' . $c->id() );

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
			$saved = Marsad_Registry::saved();
			if ( ! empty( $saved[ $c->id() ]['enabled'] ) && $c->missing() ) {
				/* translators: %s: comma-separated list of what is still needed */
				return array( 'wait', sprintf( __( 'Missing: %s', 'marsad' ), implode( '، ', $c->missing() ) ) );
			}
			return array( 'off', __( 'Not connected', 'marsad' ) );
		}
		if ( ! $st ) {
			return array( 'wait', __( 'Waiting for first sync', 'marsad' ) );
		}
		return empty( $st['ok'] ) ? array( 'bad', __( 'Error', 'marsad' ) ) : array( 'ok', __( 'Working', 'marsad' ) );
	}

	private static function tab_link( $tab, $label ) {
		return '<a href="' . esc_url( Marsad_Admin::url( '', array( 'tab' => $tab, 'range' => self::$report->key() ) ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/** The tab that explains a source in detail. */
	private static function source_tab( $id ) {
		$tabs = array(
			'ga4'            => 'audience',
			'search_console' => 'search',
			'wordpress'      => 'content',
			'site'           => 'hosting',
		);
		return $tabs[ $id ] ?? 'platforms';
	}

	/* ── range snapshots ─────────────────────────────────────────────── */

	/** Every stored report of an enabled source for the range shown, read once per page. */
	private static function snaps( $source ) {
		if ( ! isset( self::$snaps[ $source ] ) ) {
			self::$snaps[ $source ] = self::enabled( $source ) ? Marsad_Snapshots::reports( $source, self::$report->range_key() ) : array();
		}
		return self::$snaps[ $source ];
	}

	/** One report's payload, or null when it was never fetched. */
	private static function snap( $source, $report ) {
		$all = self::snaps( $source );
		return isset( $all[ $report ] ) && is_array( $all[ $report ] ) ? $all[ $report ] : null;
	}

	/**
	 * One metric of a totals report.
	 *
	 * @return array|null array( 'value', 'prev' => float|null, 'change' => float|null )
	 */
	private static function total( $source, $metric, $report = 'totals' ) {
		$m = self::snap( $source, $report )['metrics'][ $metric ] ?? null;
		if ( ! is_array( $m ) || ! is_numeric( $m['value'] ?? null ) ) {
			return null;
		}
		$value = (float) $m['value'];
		$prev  = is_numeric( $m['prev'] ?? null ) ? (float) $m['prev'] : null;
		return array( 'value' => $value, 'prev' => $prev, 'change' => Marsad_Aggregate::change( $value, $prev ) );
	}

	/**
	 * A table report as label/value pairs for a drawing, with Google's device and visitor
	 * words in the admin's language.
	 *
	 * @return array list of array( label, value )
	 */
	private static function parts( $source, $report, $metric ) {
		$payload = self::snap( $source, $report );
		if ( ! $payload ) {
			return array();
		}
		$words = array(
			'mobile'    => __( 'Mobile', 'marsad' ),
			'desktop'   => __( 'Desktop', 'marsad' ),
			'tablet'    => __( 'Tablet', 'marsad' ),
			'smart tv'  => __( 'Smart TV', 'marsad' ),
			'new'       => __( 'New', 'marsad' ),
			'returning' => __( 'Returning', 'marsad' ),
		);
		$out = array();
		foreach ( Marsad_Insights::rows_of( $payload ) as $r ) {
			$name = (string) ( $r['d'][0] ?? '' );
			$v    = $r['m'][ $metric ] ?? ( array_values( $r['m'] )[0] ?? null );
			if ( '' !== $name && null !== $v ) {
				$out[] = array( $words[ strtolower( $name ) ] ?? $name, $v );
			}
		}
		return $out;
	}

	/** One stored report in a panel, with an anchor findings can link to; nothing if never fetched. */
	private static function report_panel( Marsad_Connector $c, $report, $title, $sub = '', $size = '', array $opt = array() ) {
		$payload = self::snap( $c->id(), $report );
		if ( null === $payload ) {
			return;
		}
		self::panel_open( $title, $sub, $size, '', 'marsad-' . $c->id() . '-' . $report );
		Marsad_Admin_Table::render( $c, self::$report->range_key(), $report, $payload, array_merge( array( 'title' => $title ), $opt ) );
		self::panel_close();
	}

	/** Cards for every metric of a totals report, with its change. */
	private static function totals_cards( Marsad_Connector $c, $payload ) {
		$metrics = is_array( $payload['metrics'] ?? null ) ? $payload['metrics'] : array();
		if ( ! $metrics ) {
			return;
		}
		echo '<section class="marsad-cards marsad-cards-compact" aria-label="' . esc_attr__( 'Totals for the period', 'marsad' ) . '">';
		$i = 0;
		foreach ( $metrics as $key => $m ) {
			$t = self::total( $c->id(), (string) $key );
			if ( ! $t ) {
				continue;
			}
			$key = (string) $key;
			self::stat( Marsad_Admin_Table::label( $c, $key ), Marsad_Admin_Table::fmt( $t['value'], Marsad_Admin_Table::format_of( $c, $key, false ) ), Marsad_Admin::change_badge( $t['change'], Marsad_Admin_Table::better( $key ) ), self::COLORS[ $i++ % count( self::COLORS ) ] );
		}
		echo '</section>';
	}

	/** When the range's report was fetched, and what Google refused, if anything. */
	private static function snap_note( $source ) {
		$got = Marsad_Snapshots::get( $source, self::$report->range_key(), 'totals' );
		if ( $got ) {
			/* translators: %s: e.g. "5 minutes ago" */
			echo '<p class="marsad-sub marsad-snapnote">' . esc_html( sprintf( __( 'Google\'s own report for this period, fetched %s.', 'marsad' ), Marsad_Admin::ago( $got['fetched_at'] ) ) ) . '</p>';
		}
		self::snap_error( $source );
	}

	private static function snap_error( $source ) {
		$err = Marsad_Snapshots::errors( $source )[ self::$report->range_key() ] ?? null;
		if ( is_array( $err ) && '' !== (string) ( $err['message'] ?? '' ) ) {
			/* translators: %s: error message from Google */
			echo '<div class="marsad-callout marsad-callout-warn"><span>' . esc_html( sprintf( __( 'Some reports of this period could not be fetched: %s', 'marsad' ), self::cut( (string) $err['message'], 400 ) ) ) . '</span></div>';
		}
	}

	/** A connected source with no report for this range yet: say why, and that it comes by itself. */
	private static function no_snapshots( $source ) {
		self::panel_open( __( 'The reports for this period are on their way', 'marsad' ), '', 'wide' );
		echo '<div class="marsad-skeleton" aria-hidden="true">';
		foreach ( array( 82, 64, 90, 58, 72 ) as $w ) {
			echo '<i style="width:' . esc_attr( $w ) . '%"></i>';
		}
		echo '</div>';
		echo '<p>' . esc_html__( 'After each sync Marsad asks Google for the full report of every period, in the background. The first ones arrive within one or two syncs; there is nothing to do.', 'marsad' ) . '</p>';
		self::snap_error( $source );
		self::panel_close();
	}

	/** The source when it is connected; otherwise an explanation with a Connect button, and null. */
	private static function ready( $source, $text ) {
		$c = Marsad_Registry::get( $source );
		if ( $c && Marsad_Registry::is_enabled( $c ) ) {
			return $c;
		}
		echo '<div class="marsad-skeleton" aria-hidden="true">';
		foreach ( array( 70, 88, 52 ) as $w ) {
			echo '<i style="width:' . esc_attr( $w ) . '%"></i>';
		}
		echo '</div>';
		self::callout( $text, $source );
		return null;
	}

	private static function waiting() {
		echo '<p class="marsad-sub">' . esc_html__( 'Recorded on the next sync.', 'marsad' ) . '</p>';
	}

	/** Search Console's search types, as the overview and the Search tab name them. */
	private static function search_types() {
		return array(
			'web'        => __( 'Web search', 'marsad' ),
			'image'      => __( 'Image search', 'marsad' ),
			'video'      => __( 'Video search', 'marsad' ),
			'news'       => __( 'News search', 'marsad' ),
			'discover'   => __( 'Discover', 'marsad' ),
			'googleNews' => __( 'Google News', 'marsad' ),
		);
	}

	private static function cut( $s, $n ) {
		return mb_strlen( $s ) > $n ? rtrim( mb_substr( $s, 0, $n - 1 ) ) . '…' : $s;
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
