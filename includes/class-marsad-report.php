<?php
/**
 * Builds everything the dashboard shows for one date range from the store.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Report {

	/** Days to show; 0 means everything since the first recorded day. */
	const RANGES = array( 7, 28, 90, 365, 0 );

	/** @var array */
	public $range;
	/** @var string[] */
	public $days;
	/** @var bool the "All time" view */
	public $all = false;

	public function __construct( $n ) {
		$n           = in_array( (int) $n, self::RANGES, true ) ? (int) $n : 28;
		$this->all   = 0 === $n;
		$this->range = Marsad_Aggregate::range( $this->all ? 1 : $n, gmdate( 'Y-m-d' ) );
		if ( $this->all ) {
			// From the first recorded day; there is no earlier period to compare with.
			$first       = Marsad_Store::first_day();
			$this->range = array(
				'from'      => ( $first && $first < $this->range['to'] ) ? $first : gmdate( 'Y-m-d', strtotime( $this->range['to'] . ' UTC' ) - 364 * DAY_IN_SECONDS ),
				'to'        => $this->range['to'],
				'prev_from' => null,
				'prev_to'   => null,
			);
		}
		$this->days  = Marsad_Aggregate::days( $this->range['from'], $this->range['to'] );
	}

	public function length() {
		return count( $this->days );
	}

	/** The menu value of this view: 7, 28, 90, 365 or 0 (all time). */
	public function key() {
		return $this->all ? 0 : $this->length();
	}

	/** The snapshot range this view reads: '7', '28', '90', '365' or 'all'. */
	public function range_key(): string {
		return $this->all ? 'all' : (string) $this->length();
	}

	/**
	 * One metric of one source: value over the range, previous-period value, change, series.
	 */
	public function metric( $source, $metric, array $def ) {
		$agg = $def['agg'] ?? 'sum';
		$cur = Marsad_Store::daily( $source, $metric, $this->range['from'], $this->until( $agg ) );
		$pre = null === $this->range['prev_from'] ? array() : Marsad_Store::daily( $source, $metric, $this->range['prev_from'], $this->range['prev_to'] );
		$value = Marsad_Aggregate::collapse( $cur, $agg );
		$prev  = Marsad_Aggregate::collapse( $pre, $agg );
		return array(
			'value'  => $value,
			'prev'   => $prev,
			'change' => Marsad_Aggregate::change( $value, $prev ),
			'series' => Marsad_Aggregate::series( $cur, $this->days, $agg ),
		);
	}

	/**
	 * Counters stop at yesterday, a finished day. Running totals (followers, campaign
	 * totals) are recorded once a day as "now", so the latest one is today's.
	 */
	private function until( $agg ) {
		return 'last' === $agg ? gmdate( 'Y-m-d' ) : $this->range['to'];
	}

	public function table( $source, $metric, array $def ) {
		$b = Marsad_Store::breakdown( $source, $metric, $this->range['from'], $this->until( $def['agg'] ?? 'sum' ) );
		if ( 'last' === ( $def['agg'] ?? 'sum' ) ) {
			// Snapshot tables (latest videos) show only the most recent day that has data.
			$latest = '';
			foreach ( $b as $by_day ) {
				$latest = max( $latest, max( array_keys( $by_day ) ) );
			}
			foreach ( $b as $dim => $by_day ) {
				if ( ! isset( $by_day[ $latest ] ) ) {
					unset( $b[ $dim ] );
				}
			}
		}
		return Marsad_Aggregate::top( $b, $def['agg'] ?? 'sum', $def['limit'] ?? 10 );
	}

	/**
	 * Sections for every enabled source, in group order.
	 */
	public function sections() {
		$order = array( 'live' => -1, 'web' => 0, 'search' => 1, 'apps' => 2, 'social' => 3, 'content' => 4, 'custom' => 5 );
		$list  = array_values( Marsad_Registry::enabled() );
		usort(
			$list,
			function ( $a, $b ) use ( $order ) {
				return ( $order[ $a->group() ] ?? 9 ) <=> ( $order[ $b->group() ] ?? 9 );
			}
		);
		$out = array();
		foreach ( $list as $c ) {
			$metrics = array();
			foreach ( $c->metrics() as $key => $def ) {
				$metrics[ $key ] = array_merge( $def, $this->metric( $c->id(), $key, $def ) );
			}
			$tables = array();
			foreach ( $c->tables() as $key => $def ) {
				$rows = $this->table( $c->id(), $key, $def );
				if ( $rows ) {
					$tables[ $key ] = array_merge( $def, array( 'rows' => $rows ) );
				}
			}
			$out[] = array(
				'connector' => $c,
				'metrics'   => $metrics,
				'tables'    => $tables,
			);
		}
		return $out;
	}

	/**
	 * Headline numbers across sources. Each entry sums the listed source:metric pairs;
	 * missing sources simply contribute nothing, and an entry with no sources is dropped.
	 */
	public function headline( array $sections ) {
		$defs = array(
			'visitors' => array( 'label' => __( 'Daily website & app users', 'marsad' ), 'from' => array( 'ga4:users' ) ),
			'views'    => array( 'label' => __( 'Page & screen views', 'marsad' ), 'from' => array( 'ga4:views' ) ),
			'search'   => array( 'label' => __( 'Clicks from Google Search', 'marsad' ), 'from' => array( 'search_console:clicks' ) ),
			'installs' => array( 'label' => __( 'App installs', 'marsad' ), 'from' => array( 'google_play:installs', 'app_store:downloads' ) ),
			'social'   => array( 'label' => __( 'Social audience', 'marsad' ), 'from' => array( 'youtube:subscribers', 'facebook:followers', 'instagram:followers', 'telegram:members', 'x:followers', 'threads:followers', 'tiktok:followers' ) ),
			'posts'    => array( 'label' => __( 'Posts published', 'marsad' ), 'from' => array( 'wordpress:posts' ) ),
		);
		$defs = apply_filters( 'marsad_headline', $defs );
		$idx  = array();
		foreach ( $sections as $s ) {
			foreach ( $s['metrics'] as $k => $m ) {
				$idx[ $s['connector']->id() . ':' . $k ] = $m;
			}
		}
		$out = array();
		foreach ( $defs as $key => $d ) {
			$value  = null;
			$prev   = 0.0;
			$series = array_fill( 0, $this->length(), 0.0 );
			$parts  = array();
			foreach ( $d['from'] as $ref ) {
				if ( ! isset( $idx[ $ref ] ) || null === $idx[ $ref ]['value'] ) {
					continue;
				}
				$m       = $idx[ $ref ];
				$parts[] = $ref;
				$value   = ( $value ?? 0 ) + $m['value'];
				// One part without history makes the comparison meaningless.
				$prev    = ( null === $prev || null === $m['prev'] ) ? null : $prev + $m['prev'];
				foreach ( $m['series'] as $i => $v ) {
					$series[ $i ] += (float) $v;
				}
			}
			if ( null === $value ) {
				continue;
			}
			$out[ $key ] = array(
				'label'  => $d['label'],
				'value'  => $value,
				'change' => Marsad_Aggregate::change( $value, $prev ),
				'series' => $series,
				'parts'  => $parts,
			);
		}
		return $out;
	}
}
