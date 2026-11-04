<?php
/**
 * Key findings: a few short sentences read straight off the range snapshots (and, for sources
 * without snapshots, the daily rows), so the owner sees what changed without opening a table.
 *
 * Every rule is silent when its data is missing or too thin to mean anything: a finding is
 * only ever a fact from the stored numbers, never a guess.
 *
 * A finding: array( 'text' => translated sentence, 'tone' => good|bad|neutral,
 *                   'tab' => dashboard tab that explains it, 'anchor' => report id or '' )
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Insights {

	const MAX = 8;

	/** Smallest previous value a change is measured from: 3 → 9 sessions is not "+200%". */
	const MIN_BASE = 20;

	/** @var array source => report => payload, for the range being read */
	private static $snaps = array();

	/**
	 * Up to eight findings for one range, most useful first.
	 *
	 * @param string $range_key one of Marsad_Snapshots::RANGES
	 * @return array[] list of array( 'text', 'tone', 'tab', 'anchor' )
	 */
	public static function from_snapshots( $range_key ) {
		$range_key   = in_array( (string) $range_key, Marsad_Snapshots::RANGES, true ) ? (string) $range_key : '28';
		self::$snaps = array();
		foreach ( array( 'ga4', 'search_console' ) as $source ) {
			$c                      = Marsad_Registry::get( $source );
			self::$snaps[ $source ] = ( $c && Marsad_Registry::is_enabled( $c ) ) ? Marsad_Snapshots::reports( $source, $range_key ) : array();
		}
		$rules = array( 'channel_change', 'rising_query', 'growing_country', 'top_page', 'weak_page', 'opportunity', 'mobile_share', 'new_share', 'youtube', 'posts' );
		$out   = array();
		foreach ( $rules as $rule ) {
			if ( count( $out ) >= self::MAX ) {
				break;
			}
			$found = call_user_func( array( __CLASS__, $rule ), $range_key );
			if ( $found ) {
				$out[] = $found;
			}
		}
		return $out;
	}

	/* ── rules ───────────────────────────────────────────────────────── */

	/** The traffic channel whose sessions moved most, in sessions rather than percent. */
	private static function channel_change() {
		$best = null;
		foreach ( self::rows( 'ga4', 'channels' ) as $r ) {
			$cur  = $r['m']['sessions'] ?? null;
			$prev = $r['p']['sessions'] ?? null;
			if ( null === $cur || null === $prev || $prev < self::MIN_BASE ) {
				continue;
			}
			$pct = ( $cur - $prev ) / $prev * 100;
			if ( abs( $pct ) < 5 ) {
				continue;
			}
			if ( ! $best || abs( $cur - $prev ) > abs( $best['cur'] - $best['prev'] ) ) {
				$best = array( 'name' => $r['d'][0] ?? '', 'cur' => $cur, 'prev' => $prev, 'pct' => $pct );
			}
		}
		if ( ! $best || '' === $best['name'] ) {
			return null;
		}
		$up   = $best['pct'] > 0;
		$text = $up
			/* translators: 1: traffic channel such as "Organic Search", 2: change such as "12.5%", 3: previous sessions, 4: sessions now */
			? __( 'Sessions from %1$s rose %2$s, from %3$s to %4$s.', 'marsad' )
			/* translators: 1: traffic channel such as "Organic Search", 2: change such as "12.5%", 3: previous sessions, 4: sessions now */
			: __( 'Sessions from %1$s fell %2$s, from %3$s to %4$s.', 'marsad' );
		return self::finding( sprintf( $text, $best['name'], self::pct( abs( $best['pct'] ) ), self::num( $best['prev'] ), self::num( $best['cur'] ) ), $up ? 'good' : 'bad', 'audience', 'ga4-channels' );
	}

	/** The query that gained the most clicks (Search Console's "rising" report, biggest first). */
	private static function rising_query() {
		foreach ( self::rows( 'search_console', 'rising' ) as $r ) {
			$query = self::short( $r['d'][0] ?? '' );
			$cur   = $r['m']['clicks'] ?? null;
			$prev  = $r['p']['clicks'] ?? null;
			if ( '' === $query || null === $cur || $cur <= (float) $prev ) {
				continue;
			}
			if ( ! $prev ) {
				/* translators: 1: search query, 2: number of clicks */
				$text = sprintf( __( 'New in search: “%1$s” brought %2$s clicks, up from none.', 'marsad' ), $query, self::num( $cur ) );
			} else {
				/* translators: 1: search query, 2: number of extra clicks */
				$text = sprintf( __( 'Rising in search: “%1$s” brought %2$s more clicks than in the previous period.', 'marsad' ), $query, self::num( $cur - $prev ) );
			}
			return self::finding( $text, 'good', 'search', 'search_console-rising' );
		}
		return null;
	}

	/** The country with the fastest growth among those with a real audience. */
	private static function growing_country() {
		$rows  = self::rows( 'ga4', 'countries' );
		$total = array_sum( array_map( function ( $r ) { return (float) ( $r['m']['activeUsers'] ?? 0 ); }, $rows ) );
		$best  = null;
		foreach ( $rows as $r ) {
			$cur  = $r['m']['activeUsers'] ?? null;
			$prev = $r['p']['activeUsers'] ?? null;
			// A country worth a sentence holds at least 2% of the listed visitors.
			if ( null === $cur || null === $prev || $prev < self::MIN_BASE || $cur < 0.02 * $total ) {
				continue;
			}
			$pct = ( $cur - $prev ) / $prev * 100;
			if ( $pct >= 10 && ( ! $best || $pct > $best['pct'] ) ) {
				$best = array( 'name' => $r['d'][0] ?? '', 'cur' => $cur, 'pct' => $pct );
			}
		}
		if ( ! $best || '' === $best['name'] ) {
			return null;
		}
		/* translators: 1: country, 2: growth such as "35.0%", 3: visitors now */
		return self::finding( sprintf( __( 'Visitors from %1$s grew %2$s, to %3$s.', 'marsad' ), $best['name'], self::pct( $best['pct'] ), self::num( $best['cur'] ) ), 'good', 'audience', 'ga4-countries' );
	}

	/** The page with the most views. */
	private static function top_page() {
		$top = null;
		foreach ( self::rows( 'ga4', 'pages' ) as $r ) {
			$views = $r['m']['screenPageViews'] ?? null;
			if ( null !== $views && $views > 0 && ( ! $top || $views > $top['views'] ) ) {
				$top = array( 'name' => self::page_name( $r ), 'views' => $views );
			}
		}
		if ( ! $top || '' === $top['name'] ) {
			return null;
		}
		/* translators: 1: page title, 2: number of views */
		return self::finding( sprintf( __( 'The most viewed page was “%1$s”, with %2$s views.', 'marsad' ), $top['name'], self::num( $top['views'] ) ), 'neutral', 'content', 'ga4-pages' );
	}

	/**
	 * A page many people open but few engage with: among the 20 most viewed, the busiest one
	 * whose engagement rate is under 60% of the site's.
	 */
	private static function weak_page() {
		$site = self::total( 'ga4', 'engagementRate' );
		$site = $site ? $site['value'] : null;
		if ( null === $site || $site <= 0 ) {
			return null;
		}
		$rows = array_filter(
			self::rows( 'ga4', 'pages' ),
			function ( $r ) {
				return null !== ( $r['m']['screenPageViews'] ?? null ) && null !== ( $r['m']['engagementRate'] ?? null );
			}
		);
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['m']['screenPageViews'] <=> $a['m']['screenPageViews'];
			}
		);
		foreach ( array_slice( $rows, 0, 20 ) as $r ) {
			$name = self::page_name( $r );
			if ( '' !== $name && $r['m']['screenPageViews'] > 0 && $r['m']['engagementRate'] < 0.6 * $site ) {
				/* translators: 1: page title, 2: number of views, 3: the page's engagement rate, 4: the whole site's engagement rate */
				$text = __( '“%1$s” gets many views (%2$s) but only %3$s are engaged, against %4$s across the site.', 'marsad' );
				return self::finding( sprintf( $text, $name, self::num( $r['m']['screenPageViews'] ), self::pct( $r['m']['engagementRate'] * 100 ), self::pct( $site * 100 ) ), 'bad', 'content', 'ga4-pages' );
			}
		}
		return null;
	}

	/** The search opportunity with the most clicks to win. */
	private static function opportunity() {
		$best = null;
		foreach ( self::rows( 'search_console', 'opportunities' ) as $r ) {
			$gain = $r['m']['potential_clicks'] ?? null;
			if ( null !== $gain && $gain >= 1 && '' !== ( $r['d'][0] ?? '' ) && ( ! $best || $gain > $best['m']['potential_clicks'] ) ) {
				$best = $r;
			}
		}
		if ( ! $best || null === ( $best['m']['impressions'] ?? null ) || null === ( $best['m']['position'] ?? null ) ) {
			return null;
		}
		/* translators: 1: search query, 2: number of times shown, 3: average position such as 7.4, 4: number of clicks */
		$text = __( 'Opportunity: “%1$s” was shown %2$s times at position %3$s; ranking higher could bring about %4$s more clicks.', 'marsad' );
		return self::finding( sprintf( $text, self::short( $best['d'][0] ), self::num( $best['m']['impressions'] ), number_format_i18n( $best['m']['position'], 1 ), self::num( $best['m']['potential_clicks'] ) ), 'neutral', 'search', 'search_console-opportunities' );
	}

	private static function mobile_share() {
		$share = self::share( 'ga4', 'devices', 'activeUsers', 'mobile' );
		if ( null === $share ) {
			return null;
		}
		/* translators: %s: share such as "72.4%" */
		return self::finding( sprintf( __( '%s of visitors used a mobile phone.', 'marsad' ), self::pct( $share * 100 ) ), 'neutral', 'audience', 'ga4-devices' );
	}

	private static function new_share() {
		$share = self::share( 'ga4', 'new_returning', 'activeUsers', 'new' );
		if ( null === $share ) {
			return null;
		}
		/* translators: %s: share such as "64.0%" */
		return self::finding( sprintf( __( '%s of visitors were new to the site.', 'marsad' ), self::pct( $share * 100 ) ), 'neutral', 'audience', 'ga4-new_returning' );
	}

	/** Subscribers gained or lost; YouTube has no snapshots, so this reads the daily rows. */
	private static function youtube( $range_key ) {
		$m = self::daily( 'youtube', 'subscribers', $range_key );
		if ( ! $m || null === $m['value'] || null === $m['prev'] ) {
			return null;
		}
		$delta = $m['value'] - $m['prev'];
		if ( 0.0 === (float) round( $delta ) ) {
			return null;
		}
		$text = $delta > 0
			/* translators: 1: subscribers gained, 2: subscribers now */
			? __( 'YouTube gained %1$s subscribers in this period, to %2$s.', 'marsad' )
			/* translators: 1: subscribers lost, 2: subscribers now */
			: __( 'YouTube lost %1$s subscribers in this period, down to %2$s.', 'marsad' );
		return self::finding( sprintf( $text, self::num( abs( $delta ) ), self::num( $m['value'] ) ), $delta > 0 ? 'good' : 'bad', 'platforms', 'src-youtube' );
	}

	private static function posts( $range_key ) {
		$m = self::daily( 'wordpress', 'posts', $range_key );
		if ( ! $m || null === $m['value'] || null === $m['prev'] ) {
			return null;
		}
		$delta = $m['value'] - $m['prev'];
		if ( 0.0 === (float) $delta ) {
			/* translators: %s: number of posts */
			return self::finding( sprintf( __( '%s posts were published, the same number as in the previous period.', 'marsad' ), self::num( $m['value'] ) ), 'neutral', 'content', '' );
		}
		$text = $delta > 0
			/* translators: 1: posts published, 2: how many more than before */
			? __( '%1$s posts were published, %2$s more than in the previous period.', 'marsad' )
			/* translators: 1: posts published, 2: how many fewer than before */
			: __( '%1$s posts were published, %2$s fewer than in the previous period.', 'marsad' );
		return self::finding( sprintf( $text, self::num( $m['value'] ), self::num( abs( $delta ) ) ), $delta > 0 ? 'good' : 'bad', 'content', '' );
	}

	/* ── reading ─────────────────────────────────────────────────────── */

	/**
	 * Table payload rows with metrics by name. Values that are not numbers become null, so
	 * a malformed payload reads as missing data rather than as zero.
	 *
	 * @return array[] list of array( 'd' => string[], 'm' => key => float|null, 'p' => key => float|null )
	 */
	public static function rows_of( array $payload ) {
		$metrics = array_values( array_filter( (array) ( $payload['metrics'] ?? array() ), 'is_string' ) );
		$out     = array();
		foreach ( (array) ( $payload['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$d = array_map(
				function ( $v ) {
					return is_scalar( $v ) ? (string) $v : '';
				},
				array_values( (array) ( $row['d'] ?? array() ) )
			);
			$m = array_values( (array) ( $row['m'] ?? array() ) );
			$p = array_values( (array) ( $row['p'] ?? array() ) );
			$r = array( 'd' => $d, 'm' => array(), 'p' => array() );
			foreach ( $metrics as $i => $key ) {
				$r['m'][ $key ] = isset( $m[ $i ] ) && is_numeric( $m[ $i ] ) ? (float) $m[ $i ] : null;
				$r['p'][ $key ] = isset( $p[ $i ] ) && is_numeric( $p[ $i ] ) ? (float) $p[ $i ] : null;
			}
			$out[] = $r;
		}
		return $out;
	}

	private static function rows( $source, $report ) {
		$payload = self::$snaps[ $source ][ $report ] ?? null;
		return is_array( $payload ) ? self::rows_of( $payload ) : array();
	}

	/** @return array|null array( 'value', 'prev' ) of one metric of the 'totals' report */
	private static function total( $source, $metric ) {
		$m = self::$snaps[ $source ]['totals']['metrics'][ $metric ] ?? null;
		if ( ! is_array( $m ) || ! is_numeric( $m['value'] ?? null ) ) {
			return null;
		}
		return array(
			'value' => (float) $m['value'],
			'prev'  => is_numeric( $m['prev'] ?? null ) ? (float) $m['prev'] : null,
		);
	}

	/** Share (0–1) of the row named $name in one metric, or null when it is not there. */
	private static function share( $source, $report, $metric, $name ) {
		$rows  = self::rows( $source, $report );
		$total = 0.0;
		$part  = null;
		foreach ( $rows as $r ) {
			$v      = (float) ( $r['m'][ $metric ] ?? 0 );
			$total += $v;
			if ( strtolower( $r['d'][0] ?? '' ) === $name ) {
				$part = (float) $part + $v;
			}
		}
		return ( null === $part || $total <= 0 ) ? null : $part / $total;
	}

	/** A daily metric of an enabled source over the range, as the dashboard computes it. */
	private static function daily( $source, $metric, $range_key ) {
		$c = Marsad_Registry::get( $source );
		if ( ! $c || ! Marsad_Registry::is_enabled( $c ) ) {
			return null;
		}
		$defs = $c->metrics();
		if ( ! isset( $defs[ $metric ] ) ) {
			return null;
		}
		$report = new Marsad_Report( 'all' === $range_key ? 0 : (int) $range_key );
		return $report->metric( $source, $metric, $defs[ $metric ] );
	}

	/** A page's title when the report has one, else its path, readable. */
	private static function page_name( array $r ) {
		$title = trim( $r['d'][1] ?? '' );
		if ( '' !== $title ) {
			return self::short( $title );
		}
		$path = (string) ( $r['d'][0] ?? '' );
		$dec  = rawurldecode( $path );
		return self::short( mb_check_encoding( $dec, 'UTF-8' ) ? $dec : $path );
	}

	/* ── wording ─────────────────────────────────────────────────────── */

	private static function finding( $text, $tone, $tab, $anchor ) {
		return array(
			'text'   => $text,
			'tone'   => $tone,
			'tab'    => $tab,
			'anchor' => $anchor,
		);
	}

	private static function num( $v ) {
		return number_format_i18n( round( (float) $v ) );
	}

	private static function pct( $v ) {
		return number_format_i18n( (float) $v, 1 ) . '%';
	}

	/**
	 * Titles and queries come from visitors and editors: long ones are cut for a sentence, and
	 * each is isolated so an Arabic query keeps the English sentence around it in order (and
	 * the other way round).
	 */
	private static function short( $s ) {
		$s = trim( (string) preg_replace( '/[\x{2066}-\x{2069}\s]+/u', ' ', (string) $s ) );
		$s = mb_strlen( $s ) > 80 ? rtrim( mb_substr( $s, 0, 78 ) ) . '…' : $s;
		return '' === $s ? '' : "\u{2068}" . $s . "\u{2069}";
	}
}
