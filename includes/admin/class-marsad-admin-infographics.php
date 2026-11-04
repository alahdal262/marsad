<?php
/**
 * The drawings of the dashboard: a donut, split bars, country bars with code chips, the
 * weekly heatmap, mini bars and month columns. Plain SVG and CSS, no library, readable in RTL;
 * each drawing carries its numbers in words for screen readers.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Infographics {

	const COLORS = array( '#1d4ed8', '#b45309', '#0f766e', '#7c3aed', '#be185d', '#0369a1', '#5b6472' );

	/**
	 * GA4 reports countries by English name; the chips show the ISO code instead of a flag image.
	 * Names Google spells its own way are listed as Google spells them.
	 */
	const COUNTRY_CODES = array(
		'Afghanistan' => 'AF', 'Albania' => 'AL', 'Algeria' => 'DZ', 'Andorra' => 'AD', 'Angola' => 'AO', 'Argentina' => 'AR',
		'Armenia' => 'AM', 'Australia' => 'AU', 'Austria' => 'AT', 'Azerbaijan' => 'AZ', 'Bahamas' => 'BS', 'Bahrain' => 'BH',
		'Bangladesh' => 'BD', 'Belarus' => 'BY', 'Belgium' => 'BE', 'Benin' => 'BJ', 'Bolivia' => 'BO', 'Bosnia & Herzegovina' => 'BA',
		'Botswana' => 'BW', 'Brazil' => 'BR', 'Brunei' => 'BN', 'Bulgaria' => 'BG', 'Burkina Faso' => 'BF', 'Burundi' => 'BI',
		'Cambodia' => 'KH', 'Cameroon' => 'CM', 'Canada' => 'CA', 'Chad' => 'TD', 'Chile' => 'CL', 'China' => 'CN', 'Colombia' => 'CO',
		'Comoros' => 'KM', 'Congo - Brazzaville' => 'CG', 'Congo - Kinshasa' => 'CD', 'Costa Rica' => 'CR', 'Côte d’Ivoire' => 'CI',
		'Croatia' => 'HR', 'Cuba' => 'CU', 'Cyprus' => 'CY', 'Czechia' => 'CZ', 'Denmark' => 'DK', 'Djibouti' => 'DJ',
		'Dominican Republic' => 'DO', 'Ecuador' => 'EC', 'Egypt' => 'EG', 'El Salvador' => 'SV', 'Eritrea' => 'ER', 'Estonia' => 'EE',
		'Ethiopia' => 'ET', 'Finland' => 'FI', 'France' => 'FR', 'Gabon' => 'GA', 'Gambia' => 'GM', 'Georgia' => 'GE', 'Germany' => 'DE',
		'Ghana' => 'GH', 'Greece' => 'GR', 'Guatemala' => 'GT', 'Guinea' => 'GN', 'Haiti' => 'HT', 'Honduras' => 'HN', 'Hong Kong' => 'HK',
		'Hungary' => 'HU', 'Iceland' => 'IS', 'India' => 'IN', 'Indonesia' => 'ID', 'Iran' => 'IR', 'Iraq' => 'IQ', 'Ireland' => 'IE',
		'Israel' => 'IL', 'Italy' => 'IT', 'Jamaica' => 'JM', 'Japan' => 'JP', 'Jordan' => 'JO', 'Kazakhstan' => 'KZ', 'Kenya' => 'KE',
		'Kosovo' => 'XK', 'Kuwait' => 'KW', 'Kyrgyzstan' => 'KG', 'Laos' => 'LA', 'Latvia' => 'LV', 'Lebanon' => 'LB', 'Libya' => 'LY',
		'Lithuania' => 'LT', 'Luxembourg' => 'LU', 'Madagascar' => 'MG', 'Malawi' => 'MW', 'Malaysia' => 'MY', 'Maldives' => 'MV',
		'Mali' => 'ML', 'Malta' => 'MT', 'Mauritania' => 'MR', 'Mauritius' => 'MU', 'Mexico' => 'MX', 'Moldova' => 'MD', 'Mongolia' => 'MN',
		'Montenegro' => 'ME', 'Morocco' => 'MA', 'Mozambique' => 'MZ', 'Myanmar (Burma)' => 'MM', 'Namibia' => 'NA', 'Nepal' => 'NP',
		'Netherlands' => 'NL', 'New Zealand' => 'NZ', 'Nicaragua' => 'NI', 'Niger' => 'NE', 'Nigeria' => 'NG', 'North Macedonia' => 'MK',
		'Norway' => 'NO', 'Oman' => 'OM', 'Pakistan' => 'PK', 'Palestine' => 'PS', 'Panama' => 'PA', 'Paraguay' => 'PY', 'Peru' => 'PE',
		'Philippines' => 'PH', 'Poland' => 'PL', 'Portugal' => 'PT', 'Puerto Rico' => 'PR', 'Qatar' => 'QA', 'Romania' => 'RO',
		'Russia' => 'RU', 'Rwanda' => 'RW', 'Saudi Arabia' => 'SA', 'Senegal' => 'SN', 'Serbia' => 'RS', 'Sierra Leone' => 'SL',
		'Singapore' => 'SG', 'Slovakia' => 'SK', 'Slovenia' => 'SI', 'Somalia' => 'SO', 'South Africa' => 'ZA', 'South Korea' => 'KR',
		'South Sudan' => 'SS', 'Spain' => 'ES', 'Sri Lanka' => 'LK', 'Sudan' => 'SD', 'Sweden' => 'SE', 'Switzerland' => 'CH',
		'Syria' => 'SY', 'Taiwan' => 'TW', 'Tajikistan' => 'TJ', 'Tanzania' => 'TZ', 'Thailand' => 'TH', 'Togo' => 'TG',
		'Trinidad & Tobago' => 'TT', 'Tunisia' => 'TN', 'Türkiye' => 'TR', 'Turkey' => 'TR', 'Turkmenistan' => 'TM', 'Uganda' => 'UG',
		'Ukraine' => 'UA', 'United Arab Emirates' => 'AE', 'United Kingdom' => 'GB', 'United States' => 'US', 'Uruguay' => 'UY',
		'Uzbekistan' => 'UZ', 'Venezuela' => 'VE', 'Vietnam' => 'VN', 'Yemen' => 'YE', 'Zambia' => 'ZM', 'Zimbabwe' => 'ZW',
	);

	/** The chip text of a country name or ISO code; '' when unknown, so nothing wrong is shown. */
	public static function country_code( $name ) {
		$name = trim( (string) $name );
		if ( preg_match( '/^[A-Za-z]{2,3}$/', $name ) ) {
			return strtoupper( $name );
		}
		return self::COUNTRY_CODES[ $name ] ?? '';
	}

	/**
	 * Largest parts first, the rest folded into "Other" so a donut never has slivers.
	 *
	 * @param array $parts list of array( label, value )
	 * @return array list of array( label, value, share 0–1 )
	 */
	public static function shares( array $parts, $keep = 5 ) {
		$parts = array_values(
			array_filter(
				$parts,
				function ( $p ) {
					return is_numeric( $p[1] ) && $p[1] > 0;
				}
			)
		);
		usort(
			$parts,
			function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		if ( count( $parts ) > $keep + 1 ) {
			$rest    = array_slice( $parts, $keep );
			$parts   = array_slice( $parts, 0, $keep );
			$parts[] = array( __( 'Other', 'marsad' ), array_sum( array_column( $rest, 1 ) ) );
		}
		$total = array_sum( array_column( $parts, 1 ) );
		$out   = array();
		foreach ( $parts as $p ) {
			$out[] = array( (string) $p[0], (float) $p[1], $total > 0 ? $p[1] / $total : 0 );
		}
		return $out;
	}

	private static function pct( $share ) {
		return number_format_i18n( $share * 100, 1 ) . '%';
	}

	/** "Organic Search 45.0%, Direct 30.0%, …" for screen readers. */
	private static function describe( $title, array $shares ) {
		return $title . ': ' . implode(
			'، ',
			array_map(
				function ( $s ) {
					return $s[0] . ' ' . self::pct( $s[2] );
				},
				$shares
			)
		);
	}

	/**
	 * A donut of shares with the total in the middle and a legend beside it.
	 *
	 * @param array  $parts list of array( label, value )
	 * @param string $title accessible name
	 * @param string $unit  word under the total, e.g. "sessions"
	 */
	public static function donut( array $parts, $title, $unit ) {
		$shares = self::shares( $parts );
		if ( ! $shares ) {
			return false;
		}
		$total = array_sum( array_column( $shares, 1 ) );
		// Radius chosen so the circumference is 100 and dash lengths are percentages.
		$svg    = '<svg class="marsad-donut" viewBox="0 0 42 42" role="img" aria-label="' . esc_attr( self::describe( $title, $shares ) ) . '"><circle cx="21" cy="21" r="15.9155" fill="none" stroke="#eef1f5" stroke-width="6"/>';
		$offset = 25;
		foreach ( $shares as $i => $s ) {
			$len  = round( $s[2] * 100, 3 );
			$svg .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="' . esc_attr( self::COLORS[ $i % count( self::COLORS ) ] ) . '" stroke-width="6" stroke-dasharray="' . esc_attr( $len . ' ' . ( 100 - $len ) ) . '" stroke-dashoffset="' . esc_attr( round( $offset, 3 ) ) . '"><title>' . esc_html( $s[0] . ' ' . self::pct( $s[2] ) ) . '</title></circle>';
			$offset -= $len;
		}
		$svg .= '<text x="21" y="21" text-anchor="middle" class="marsad-donut-num">' . esc_html( $total >= 1e4 ? Marsad_Svg::short( $total ) : number_format_i18n( round( $total ) ) ) . '</text>';
		$svg .= '<text x="21" y="26.5" text-anchor="middle" class="marsad-donut-unit">' . esc_html( $unit ) . '</text></svg>';
		echo '<div class="marsad-donut-box">' . $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		self::legend( $shares );
		echo '</div>';
		return true;
	}

	/** One bar split into shares, labelled with percentages. */
	public static function split( array $parts, $title ) {
		$shares = self::shares( $parts, 4 );
		if ( ! $shares ) {
			return false;
		}
		echo '<div class="marsad-split" role="img" aria-label="' . esc_attr( self::describe( $title, $shares ) ) . '">';
		foreach ( $shares as $i => $s ) {
			echo '<i style="flex-grow:' . esc_attr( round( $s[2] * 1000 ) ) . ';--c:' . esc_attr( self::COLORS[ $i % count( self::COLORS ) ] ) . '">' . ( $s[2] >= 0.12 ? esc_html( self::pct( $s[2] ) ) : '' ) . '</i>';
		}
		echo '</div>';
		self::legend( $shares );
		return true;
	}

	private static function legend( array $shares ) {
		echo '<ul class="marsad-list marsad-keyed">';
		foreach ( $shares as $i => $s ) {
			echo '<li><span><i style="--c:' . esc_attr( self::COLORS[ $i % count( self::COLORS ) ] ) . '"></i>' . esc_html( $s[0] ) . '</span><b>' . esc_html( self::pct( $s[2] ) ) . ' <small>' . esc_html( number_format_i18n( round( $s[1] ) ) ) . '</small></b></li>';
		}
		echo '</ul>';
	}

	/**
	 * Countries as bars, each with its ISO code in a chip.
	 *
	 * @param array $rows list of array( name, value )
	 */
	public static function countries( array $rows, $limit = 5 ) {
		$rows = array_slice( array_values( array_filter( $rows, function ( $r ) { return is_numeric( $r[1] ) && $r[1] > 0; } ) ), 0, $limit );
		if ( ! $rows ) {
			return false;
		}
		$top = max( array_column( $rows, 1 ) );
		echo '<ol class="marsad-rank marsad-countries">';
		foreach ( $rows as $r ) {
			$code = self::country_code( $r[0] );
			echo '<li><span class="marsad-chip" dir="ltr" aria-hidden="true">' . esc_html( '' === $code ? '··' : $code ) . '</span><div><p><span class="marsad-dim">' . esc_html( $r[0] ) . '</span><b>' . esc_html( number_format_i18n( round( $r[1] ) ) ) . '</b></p>';
			echo '<span class="marsad-track"><i style="width:' . esc_attr( $top > 0 ? round( $r[1] / $top * 100, 1 ) : 0 ) . '%"></i></span></div></li>';
		}
		echo '</ol>';
		return true;
	}

	/**
	 * Active users by weekday and hour: a 7 × 24 grid shaded by value, plus the same numbers as
	 * a table for screen readers. GA4 numbers weekdays 0 (Sunday) to 6; the grid starts on the
	 * site's first day of the week.
	 *
	 * @param array $payload heatmap table payload (dims dayOfWeek, hour)
	 */
	public static function heatmap( array $payload, $title ) {
		$grid = array_fill( 0, 7, array_fill( 0, 24, 0.0 ) );
		$any  = false;
		foreach ( Marsad_Insights::rows_of( $payload ) as $r ) {
			$day  = $r['d'][0] ?? '';
			$hour = $r['d'][1] ?? '';
			$v    = array_values( $r['m'] )[0] ?? null;
			if ( ! is_numeric( $day ) || ! is_numeric( $hour ) || null === $v || $day < 0 || $day > 6 || $hour < 0 || $hour > 23 ) {
				continue;
			}
			$grid[ (int) $day ][ (int) $hour ] += $v;
			$any                                = true;
		}
		if ( ! $any ) {
			return false;
		}
		$max   = max( array_map( 'max', $grid ) );
		$first = (int) get_option( 'start_of_week', 0 );
		$order = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$order[] = ( $first + $i ) % 7;
		}
		// 1 January 2023 was a Sunday: day n of the week is that date plus n days.
		$names = array();
		foreach ( range( 0, 6 ) as $d ) {
			$names[ $d ] = date_i18n( 'D', gmmktime( 12, 0, 0, 1, 1 + $d, 2023 ) );
		}
		$best = array( 0, 0 );
		foreach ( $grid as $d => $hours ) {
			foreach ( $hours as $h => $v ) {
				if ( $v > $grid[ $best[0] ][ $best[1] ] ) {
					$best = array( $d, $h );
				}
			}
		}
		$label = sprintf(
			/* translators: 1: chart name, 2: weekday, 3: hour such as 14:00, 4: number of users */
			__( '%1$s. Busiest: %2$s at %3$s, with %4$s active users.', 'marsad' ),
			$title,
			$names[ $best[0] ],
			sprintf( '%02d:00', $best[1] ),
			number_format_i18n( round( $max ) )
		);

		echo '<div class="marsad-heat-scroll"><div class="marsad-heat" role="img" aria-label="' . esc_attr( $label ) . '">';
		echo '<span></span>';
		for ( $h = 0; $h < 24; $h++ ) {
			echo '<span class="marsad-heat-h">' . ( 0 === $h % 6 ? esc_html( sprintf( '%02d', $h ) ) : '' ) . '</span>';
		}
		foreach ( $order as $d ) {
			echo '<span class="marsad-heat-d">' . esc_html( $names[ $d ] ) . '</span>';
			for ( $h = 0; $h < 24; $h++ ) {
				$v = $grid[ $d ][ $h ];
				echo '<i style="--a:' . esc_attr( $max > 0 ? round( 0.06 + 0.94 * $v / $max, 3 ) : 0 ) . '" title="' . esc_attr( $names[ $d ] . ' ' . sprintf( '%02d:00', $h ) . ' · ' . number_format_i18n( round( $v ) ) ) . '"></i>';
			}
		}
		echo '</div></div>';
		echo '<p class="marsad-heat-scale" aria-hidden="true"><span>' . esc_html__( 'Fewer', 'marsad' ) . '</span><i></i><span>' . esc_html__( 'More', 'marsad' ) . '</span></p>';

		echo '<table class="marsad-sr"><caption>' . esc_html( $title ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Day', 'marsad' ) . '</th>';
		for ( $h = 0; $h < 24; $h++ ) {
			echo '<th scope="col">' . esc_html( sprintf( '%02d:00', $h ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $order as $d ) {
			echo '<tr><th scope="row">' . esc_html( $names[ $d ] ) . '</th>';
			for ( $h = 0; $h < 24; $h++ ) {
				echo '<td>' . esc_html( number_format_i18n( round( $grid[ $d ][ $h ] ) ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		return true;
	}

	/** Small columns for a card; decorative, the card states the numbers. Oldest first. */
	public static function mini_bars( array $values, $color ) {
		$values = array_map( 'floatval', array_values( $values ) );
		$n      = count( $values );
		$max    = $n ? max( $values ) : 0;
		if ( $n < 2 || $max <= 0 ) {
			return '';
		}
		$w   = 120 / $n;
		$svg = '<svg class="marsad-spark" viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
		foreach ( $values as $i => $v ) {
			$h    = round( max( 0.5, $v / $max * 30 ), 2 );
			$svg .= '<rect x="' . round( $i * $w + $w * 0.15, 2 ) . '" y="' . round( 32 - $h, 2 ) . '" width="' . round( $w * 0.7, 2 ) . '" height="' . $h . '" rx="0.6" fill="' . esc_attr( $color ) . '"/>';
		}
		return $svg . '</svg>';
	}

	/**
	 * Columns with a label under the first, middle and last one, e.g. views by publish month.
	 *
	 * @param array  $points label => value, oldest first
	 * @param string $title  accessible name
	 */
	public static function columns( array $points, $title ) {
		$points = array_filter( $points, 'is_numeric' );
		if ( ! $points || max( $points ) <= 0 ) {
			return false;
		}
		$labels = array_map( 'strval', array_keys( $points ) );
		$values = array_map( 'floatval', array_values( $points ) );
		$n      = count( $values );
		$max    = max( $values );
		$best   = array_search( $max, $values, true );
		/* translators: 1: chart name, 2: label of the highest column, 3: its value */
		$desc = sprintf( __( '%1$s. Highest: %2$s with %3$s.', 'marsad' ), $title, $labels[ $best ], number_format_i18n( round( $max ) ) );
		$w    = 600 / $n;
		$svg  = '<svg class="marsad-chart" viewBox="0 0 600 180" role="img" aria-label="' . esc_attr( $desc ) . '">';
		foreach ( $values as $i => $v ) {
			$h    = round( $v / $max * 150, 2 );
			$svg .= '<rect x="' . round( $i * $w + $w * 0.12, 2 ) . '" y="' . round( 155 - $h, 2 ) . '" width="' . round( $w * 0.76, 2 ) . '" height="' . $h . '" rx="2" fill="#1d4ed8"><title>' . esc_html( $labels[ $i ] . ': ' . number_format_i18n( round( $v ) ) ) . '</title></rect>';
		}
		foreach ( array_unique( array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ) ) as $i ) {
			$anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
			$x      = 0 === $i ? 0 : ( $n - 1 === $i ? 600 : round( ( $i + 0.5 ) * $w, 1 ) );
			$svg   .= '<text x="' . $x . '" y="174" class="marsad-axis" text-anchor="' . $anchor . '">' . esc_html( $labels[ $i ] ) . '</text>';
		}
		echo $svg . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		return true;
	}
}
