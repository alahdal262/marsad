<?php
/**
 * The dashboard: headline numbers across all sources, then one section per source.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Dashboard {

	const COLORS = array( '#2563eb', '#16a34a', '#d97706', '#db2777', '#7c3aed', '#0891b2' );

	public static function render() {
		if ( ! current_user_can( Marsad_Plugin::CAP_VIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to view statistics.', 'marsad' ), 403 );
		}
		$n        = (int) ( $_GET['range'] ?? 28 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$report   = new Marsad_Report( $n );
		$sections = $report->sections();
		$headline = $report->headline( $sections );
		$manage   = current_user_can( Marsad_Plugin::CAP_MANAGE );
		$status   = Marsad_Sync::status();
		$last     = $status ? max( array_column( $status, 'at' ) ) : 0;

		echo '<div class="wrap marsad">';
		echo '<div class="marsad-top"><div><h1>' . esc_html__( 'Marsad', 'marsad' ) . '</h1>';
		echo '<p class="marsad-sub">' . esc_html( sprintf( /* translators: 1: start date, 2: end date */ __( '%1$s – %2$s, compared with the previous period', 'marsad' ), date_i18n( get_option( 'date_format' ), strtotime( $report->range['from'] . ' 12:00 UTC' ) ), date_i18n( get_option( 'date_format' ), strtotime( $report->range['to'] . ' 12:00 UTC' ) ) ) ) . '</p></div>';
		echo '<div class="marsad-actions"><nav class="marsad-ranges">';
		foreach ( Marsad_Report::RANGES as $r ) {
			/* translators: %d: number of days */
			$label = 365 === $r ? __( 'Year', 'marsad' ) : sprintf( __( '%d days', 'marsad' ), $r );
			echo '<a class="' . ( $report->length() === $r ? 'is-on' : '' ) . '" href="' . esc_url( Marsad_Admin::url( '', array( 'range' => $r ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( $manage ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'marsad_sync_now' );
			echo '<input type="hidden" name="action" value="marsad_sync_now"><button class="button">' . esc_html__( 'Sync now', 'marsad' ) . '</button></form>';
		}
		echo '<span class="marsad-muted">' . esc_html( sprintf( /* translators: %s: e.g. "5 minutes ago" */ __( 'Updated %s', 'marsad' ), Marsad_Admin::ago( $last ) ) ) . '</span>';
		echo '</div></div>';
		Marsad_Admin::print_notice();

		$only_wp = 1 === count( $sections ) && 'wordpress' === $sections[0]['connector']->id();
		if ( $only_wp ) {
			echo '<div class="marsad-empty"><h2>' . esc_html__( 'Connect your first source', 'marsad' ) . '</h2><p>' . esc_html__( 'Marsad already counts your posts and comments. Connect Google Analytics, Search Console, your social accounts and your apps to see everything here.', 'marsad' ) . '</p>';
			if ( $manage ) {
				echo '<a class="button button-primary" href="' . esc_url( Marsad_Admin::url( 'connections' ) ) . '">' . esc_html__( 'Open Connections', 'marsad' ) . '</a>';
			}
			echo '</div>';
		}

		if ( $headline ) {
			echo '<section class="marsad-headline">';
			$i = 0;
			foreach ( $headline as $h ) {
				$color = self::COLORS[ $i++ % count( self::COLORS ) ];
				echo '<div class="marsad-hl" style="--c:' . esc_attr( $color ) . '"><span class="marsad-hl-label">' . esc_html( $h['label'] ) . '</span>';
				echo '<b>' . esc_html( Marsad_Admin::format( $h['value'] ) ) . '</b>' . Marsad_Admin::change_badge( $h['change'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is escaped.
				echo Marsad_Svg::sparkline( $h['series'], $color ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped attributes.
			}
			echo '</section>';
		}

		foreach ( $sections as $s ) {
			self::section( $s, $report, $status );
		}
		echo '</div>';
	}

	private static function section( array $s, Marsad_Report $report, array $status ) {
		/** @var Marsad_Connector $c */
		$c  = $s['connector'];
		$st = $status[ $c->id() ] ?? array();
		echo '<section class="marsad-source marsad-g-' . esc_attr( $c->group() ) . '" id="marsad-' . esc_attr( $c->id() ) . '">';
		echo '<header><h2>' . esc_html( $c->label() ) . '</h2>';
		if ( $st && empty( $st['ok'] ) ) {
			echo '<span class="marsad-err" title="' . esc_attr( $st['message'] ) . '">' . esc_html__( 'Last sync failed', 'marsad' ) . '</span>';
		}
		if ( $c->is_snapshot() ) {
			echo '<span class="marsad-muted">' . esc_html__( 'Recorded once a day; history starts the day you connected it.', 'marsad' ) . '</span>';
		}
		echo '</header>';

		$cards = array_filter( $s['metrics'], function ( $m ) { return ! empty( $m['kpi'] ); } );
		if ( $cards ) {
			echo '<div class="marsad-kpis">';
			$i = 0;
			foreach ( $cards as $m ) {
				$color = self::COLORS[ $i++ % count( self::COLORS ) ];
				echo '<div class="marsad-kpi"><span>' . esc_html( $m['label'] ) . '</span><b>' . esc_html( Marsad_Admin::format( $m['value'], $m['format'] ?? 'number' ) ) . '</b>';
				echo Marsad_Admin::change_badge( $m['change'], $m['better'] ?? 'up' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo Marsad_Svg::sparkline( $m['series'], $color ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		if ( $sets && $report->length() > 1 ) {
			echo '<div class="marsad-chartbox">' . Marsad_Svg::line_chart( $report->days, $sets ) . '<ul class="marsad-legend">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $sets as $set ) {
				echo '<li style="--c:' . esc_attr( $set['color'] ) . '">' . esc_html( $set['label'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $s['tables'] ) {
			echo '<div class="marsad-tables">';
			foreach ( $s['tables'] as $t ) {
				self::table( $t );
			}
			echo '</div>';
		}
		echo '</section>';
	}

	private static function table( array $t ) {
		echo '<div class="marsad-table"><h3>' . esc_html( $t['label'] ) . '</h3><ol>';
		foreach ( $t['rows'] as $r ) {
			$dim  = $r['dim'];
			$name = esc_html( $dim );
			if ( ! empty( $t['link'] ) ) {
				$href = 0 === strpos( $dim, 'http' ) ? $dim : home_url( $dim );
				$name = '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . esc_html( urldecode( $dim ) ) . '</a>';
			}
			echo '<li><span class="marsad-dim">' . $name . '</span><b>' . esc_html( Marsad_Admin::format( $r['value'] ) ) . '</b><i style="width:' . esc_attr( round( $r['share'] * 100, 1 ) ) . '%"></i></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name escaped above.
		}
		echo '</ol></div>';
	}
}
