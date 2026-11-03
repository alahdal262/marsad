<?php
/**
 * Server-rendered SVG charts: no JavaScript library, no CDN, works in RTL and in emails.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Svg {

	/**
	 * Points for a polyline fitting $values into a $w × $h box (with $pad inside).
	 * Nulls are skipped. Public for tests.
	 */
	public static function points( array $values, $w, $h, $pad = 2, $min = null, $max = null ) {
		$nums = array_filter(
			$values,
			function ( $v ) {
				return null !== $v;
			}
		);
		if ( ! $nums ) {
			return '';
		}
		$min   = null === $min ? min( $nums ) : $min;
		$max   = null === $max ? max( $nums ) : $max;
		$span  = ( $max - $min ) ?: 1;
		$n     = count( $values );
		$step  = $n > 1 ? ( $w - 2 * $pad ) / ( $n - 1 ) : 0;
		$out   = array();
		foreach ( array_values( $values ) as $i => $v ) {
			if ( null === $v ) {
				continue;
			}
			$x     = $pad + $i * $step;
			$y     = $h - $pad - ( ( $v - $min ) / $span ) * ( $h - 2 * $pad );
			$out[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}
		return implode( ' ', $out );
	}

	public static function sparkline( array $values, $color = 'currentColor' ) {
		$pts = self::points( $values, 120, 32, 2 );
		if ( '' === $pts ) {
			return '';
		}
		return '<svg class="marsad-spark" viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="' . esc_attr( $pts ) . '"/></svg>';
	}

	/**
	 * Multi-line chart with a shared y scale, light grid and first/middle/last day labels.
	 *
	 * @param string[] $days
	 * @param array    $sets list of array( 'label', 'values', 'color' )
	 */
	public static function line_chart( array $days, array $sets ) {
		$w   = 720;
		$h   = 220;
		$pl  = 8;
		$pb  = 22;
		$all = array();
		foreach ( $sets as $s ) {
			foreach ( $s['values'] as $v ) {
				if ( null !== $v ) {
					$all[] = $v;
				}
			}
		}
		if ( ! $all ) {
			return '';
		}
		$max = max( $all );
		$max = $max > 0 ? $max : 1;
		$svg = '<svg class="marsad-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
		for ( $g = 0; $g <= 4; $g++ ) {
			$y    = round( ( $h - $pb ) * $g / 4, 1 );
			$val  = $max * ( 4 - $g ) / 4;
			$svg .= '<line x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" class="marsad-grid"/>';
			$svg .= '<text x="' . ( $w - 4 ) . '" y="' . ( $y + 12 ) . '" class="marsad-axis" text-anchor="end">' . esc_html( self::short( $val ) ) . '</text>';
		}
		$n = count( $days );
		foreach ( array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ) as $i ) {
			if ( ! isset( $days[ $i ] ) ) {
				continue;
			}
			$x    = $n > 1 ? $pl + $i * ( $w - 2 * $pl ) / ( $n - 1 ) : $pl;
			$svg .= '<text x="' . round( $x, 1 ) . '" y="' . ( $h - 4 ) . '" class="marsad-axis" text-anchor="' . ( 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' ) ) . '">' . esc_html( date_i18n( 'j M', strtotime( $days[ $i ] . ' 12:00 UTC' ) ) ) . '</text>';
		}
		foreach ( $sets as $s ) {
			$pts = self::points( $s['values'], $w, $h - $pb, $pl, 0, $max );
			if ( '' !== $pts ) {
				$svg .= '<polyline fill="none" stroke="' . esc_attr( $s['color'] ) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" points="' . esc_attr( $pts ) . '"><title>' . esc_html( $s['label'] ) . '</title></polyline>';
			}
		}
		return $svg . '</svg>';
	}

	/** 1234 → 1.2K, 2500000 → 2.5M. Public for tests. */
	public static function short( $n ) {
		$a = abs( $n );
		if ( $a >= 1e9 ) {
			return round( $n / 1e9, 1 ) . 'B';
		}
		if ( $a >= 1e6 ) {
			return round( $n / 1e6, 1 ) . 'M';
		}
		if ( $a >= 1e4 ) {
			return round( $n / 1e3, 1 ) . 'K';
		}
		return (string) round( $n );
	}
}
