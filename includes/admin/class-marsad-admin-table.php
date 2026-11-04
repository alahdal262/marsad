<?php
/**
 * The one table every snapshot report is shown in: sortable headers, a filter box, the first
 * 25 rows with the rest behind "Show all", a change column and an Export CSV link.
 *
 * Without JavaScript it still works: the extra rows sit in a <details>, the order is the one
 * Google sorted by, and the filter box stays hidden. Payload values are untrusted (page titles,
 * search queries), so every one is escaped where it is printed.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Table {

	/** Rows shown before "Show all". */
	const VISIBLE = 25;

	/** Metrics where a fall is good news, so the change badge turns green on a fall. */
	const LOWER_IS_BETTER = array( 'position', 'bounceRate' );

	/**
	 * Values computed from two stored metrics, e.g. average engagement time per user.
	 * key => array( numerator, denominator, format )
	 */
	const DERIVED = array(
		'avgEngagement' => array( 'userEngagementDuration', 'activeUsers', 'seconds' ),
	);

	/** Formatter of one value; formats are those of Marsad_Snapshots::FORMAT_TYPES. */
	public static function fmt( $v, $format ) {
		if ( null === $v || '' === $v ) {
			return '—';
		}
		if ( ! is_numeric( $v ) ) {
			return is_scalar( $v ) ? (string) $v : '—';
		}
		switch ( $format ) {
			case 'percent':
				// Stored as Google returns ratios (0–1).
				return number_format_i18n( $v * 100, 1 ) . '%';
			case 'decimal':
				return number_format_i18n( $v, 1 );
			case 'seconds':
				return Marsad_Admin::format( $v, 'seconds' );
			case 'duration':
				if ( $v < 3600 ) {
					return Marsad_Admin::format( $v, 'seconds' );
				}
				/* translators: %s: number of hours, e.g. 12.5 */
				return sprintf( __( '%s h', 'marsad' ), number_format_i18n( $v / 3600, 1 ) );
			case 'text':
			case 'link':
			case 'country':
				return (string) $v;
			default:
				return number_format_i18n( round( $v ) );
		}
	}

	/** A key's label: the connector's own translation, then the dashboard's, then the key itself. */
	public static function label( $c, $key ) {
		$own = $c instanceof Marsad_Connector ? $c->snapshot_labels() : array();
		if ( isset( $own[ $key ] ) && '' !== (string) $own[ $key ] ) {
			return (string) $own[ $key ];
		}
		$mine = array(
			'avgEngagement' => __( 'Avg. engagement time', 'marsad' ),
		);
		return $mine[ $key ] ?? (string) $key;
	}

	/** Format of a key for this connector: dimensions default to text, metrics to number. */
	public static function format_of( $c, $key, $is_dim ) {
		if ( isset( self::DERIVED[ $key ] ) ) {
			return self::DERIVED[ $key ][2];
		}
		$all = Marsad_Snapshots::formats( $c instanceof Marsad_Connector ? $c : null );
		return $all[ $key ] ?? ( $is_dim ? 'text' : 'number' );
	}

	public static function better( $key ) {
		return in_array( $key, self::LOWER_IS_BETTER, true ) ? 'down' : 'up';
	}

	/**
	 * The payload as one list of columns, derived ones included.
	 *
	 * @return array array( 'dims' => string[], 'metrics' => string[], 'rows' => Marsad_Insights::rows_of() rows )
	 */
	public static function normalize( array $payload, array $derive = array() ) {
		$rows    = Marsad_Insights::rows_of( $payload );
		$dims    = array_values( array_map( 'strval', array_filter( (array) ( $payload['dims'] ?? array() ), 'is_scalar' ) ) );
		$metrics = array_values( array_filter( (array) ( $payload['metrics'] ?? array() ), 'is_string' ) );
		foreach ( $derive as $key ) {
			if ( ! isset( self::DERIVED[ $key ] ) || ! in_array( self::DERIVED[ $key ][0], $metrics, true ) || ! in_array( self::DERIVED[ $key ][1], $metrics, true ) ) {
				continue;
			}
			list( $num, $den ) = self::DERIVED[ $key ];
			foreach ( $rows as &$r ) {
				$r['m'][ $key ] = ( null !== $r['m'][ $num ] && $r['m'][ $den ] ) ? $r['m'][ $num ] / $r['m'][ $den ] : null;
				$r['p'][ $key ] = ( null !== $r['p'][ $num ] && $r['p'][ $den ] ) ? $r['p'][ $num ] / $r['p'][ $den ] : null;
			}
			unset( $r );
			$metrics[] = $key;
		}
		return array(
			'dims'    => $dims,
			'metrics' => $metrics,
			'rows'    => $rows,
		);
	}

	/**
	 * Print one report.
	 *
	 * @param Marsad_Connector $c
	 * @param string           $range_key
	 * @param string           $report  report key, for the CSV link and the anchor
	 * @param array            $payload table payload
	 * @param array            $opt     columns  metric keys to show, in order (default all)
	 *                                  derive   keys of DERIVED to add
	 *                                  change   metric whose change gets its own column (default the sort metric)
	 *                                  title    accessible name of the table
	 *                                  empty    text when there are no rows
	 */
	public static function render( Marsad_Connector $c, $range_key, $report, array $payload, array $opt = array() ) {
		$t    = self::normalize( $payload, (array) ( $opt['derive'] ?? array() ) );
		$rows = $t['rows'];
		if ( ! $rows ) {
			echo '<p class="marsad-sub marsad-empty">' . esc_html( $opt['empty'] ?? __( 'Nothing recorded in this period.', 'marsad' ) ) . '</p>';
			return;
		}
		$cols = isset( $opt['columns'] ) ? array_values( array_intersect( (array) $opt['columns'], $t['metrics'] ) ) : $t['metrics'];
		$sort = is_string( $payload['sort'] ?? null ) ? $payload['sort'] : '';

		// The change column follows the metric Google sorted by, when the report has a previous period.
		$change = $opt['change'] ?? ( in_array( $sort, $cols, true ) ? $sort : ( $cols[0] ?? '' ) );
		$has_p  = false;
		foreach ( $rows as $r ) {
			if ( null !== ( $r['p'][ $change ] ?? null ) ) {
				$has_p = true;
				break;
			}
		}
		$change = $has_p ? $change : '';

		// A page title is shown as the text of its path's link, not as a column of its own.
		$dims  = $t['dims'];
		$title = array_search( 'pageTitle', $dims, true );
		$link  = null;
		foreach ( $dims as $i => $d ) {
			if ( 'link' === self::format_of( $c, $d, true ) ) {
				$link = $i;
				break;
			}
		}
		$merge = ( false !== $title && null !== $link ) ? $title : null;

		$name  = (string) ( $opt['title'] ?? $report );
		$total = is_numeric( $payload['total_rows'] ?? null ) ? (int) $payload['total_rows'] : count( $rows );
		$count = count( $rows );

		echo '<div class="marsad-table" data-marsad-table data-visible="' . esc_attr( self::VISIBLE ) . '" data-less="' . esc_attr__( 'Show fewer', 'marsad' ) . '">';
		echo '<div class="marsad-table-tools">';
		echo '<input type="search" class="marsad-filter" hidden placeholder="' . esc_attr__( 'Filter rows', 'marsad' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: table name */ __( 'Filter rows of %s', 'marsad' ), $name ) ) . '">';
		if ( $total > $count ) {
			/* translators: 1: rows shown, 2: rows Google has */
			$note = sprintf( __( 'Top %1$s of %2$s', 'marsad' ), number_format_i18n( $count ), number_format_i18n( $total ) . ( empty( $payload['total_more'] ) ? '' : '+' ) );
		} else {
			/* translators: %s: number of rows */
			$note = sprintf( _n( '%s row', '%s rows', $count, 'marsad' ), number_format_i18n( $count ) );
		}
		echo '<span class="marsad-sub">' . esc_html( $note ) . '</span>';
		echo '<a class="marsad-btn marsad-btn-sm" href="' . esc_url( self::export_url( $c->id(), $range_key, $report ) ) . '">' . esc_html__( 'Export CSV', 'marsad' ) . '</a>';
		echo '</div>';

		echo '<div class="marsad-table-scroll"><table class="marsad-tbl"><caption class="marsad-sr">' . esc_html( $name ) . '</caption><thead><tr>';
		foreach ( $dims as $i => $d ) {
			if ( $i !== $merge ) {
				self::th( self::label( $c, $d ), 'text', $d === $sort );
			}
		}
		foreach ( $cols as $m ) {
			self::th( self::label( $c, $m ), 'num', $m === $sort );
		}
		if ( $change ) {
			self::th( __( 'Change', 'marsad' ), 'num', false );
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, self::VISIBLE ) as $r ) {
			self::tr( $c, $r, $dims, $cols, $change, $link, $merge );
		}
		echo '</tbody></table></div>';

		if ( $count > self::VISIBLE ) {
			/* translators: %s: number of rows */
			echo '<details class="marsad-showall"><summary>' . esc_html( sprintf( __( 'Show all %s', 'marsad' ), number_format_i18n( $count ) ) ) . '</summary>';
			echo '<div class="marsad-table-scroll"><table class="marsad-tbl"><tbody>';
			foreach ( array_slice( $rows, self::VISIBLE ) as $r ) {
				self::tr( $c, $r, $dims, $cols, $change, $link, $merge );
			}
			echo '</tbody></table></div></details>';
		}
		echo '<p class="marsad-sub marsad-table-none" hidden>' . esc_html__( 'No rows match.', 'marsad' ) . '</p>';
		echo '</div>';
	}

	private static function th( $label, $type, $sorted ) {
		echo '<th scope="col"' . ( $sorted ? ' aria-sort="descending"' : '' ) . ' class="marsad-th-' . esc_attr( $type ) . '"><button type="button" class="marsad-sort" data-type="' . esc_attr( $type ) . '">' . esc_html( $label ) . '</button></th>';
	}

	private static function tr( Marsad_Connector $c, array $r, array $dims, array $cols, $change, $link, $merge ) {
		echo '<tr>';
		foreach ( $dims as $i => $d ) {
			if ( $i === $merge ) {
				continue;
			}
			$v = $r['d'][ $i ] ?? '';
			echo '<td data-v="' . esc_attr( mb_strtolower( $v ) ) . '">';
			if ( $i === $link && null !== $merge && '' !== trim( $r['d'][ $merge ] ?? '' ) ) {
				echo self::cell( $c, $d, $v, $r['d'][ $merge ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cell().
			} else {
				echo self::cell( $c, $d, $v ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cell().
			}
			echo '</td>';
		}
		foreach ( $cols as $m ) {
			$v = $r['m'][ $m ] ?? null;
			echo '<td class="marsad-td-num" data-v="' . esc_attr( null === $v ? '' : $v ) . '">' . esc_html( self::fmt( $v, self::format_of( $c, $m, false ) ) ) . '</td>';
		}
		if ( $change ) {
			$pct = Marsad_Aggregate::change( $r['m'][ $change ] ?? null, $r['p'][ $change ] ?? null );
			echo '<td class="marsad-td-num" data-v="' . esc_attr( null === $pct ? '' : round( $pct, 2 ) ) . '">';
			echo self::change_cell( $r['m'][ $change ] ?? null, $r['p'][ $change ] ?? null, self::better( $change ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo '</td>';
		}
		echo '</tr>';
	}

	/**
	 * The change badge of one row. "New" only when the previous period really had zero; an unknown
	 * previous value (Google cut its answer short) is a dash, and zero both times shows nothing.
	 */
	public static function change_cell( $now, $before, $better ) {
		if ( is_numeric( $before ) && 0.0 === (float) $before ) {
			return ( is_numeric( $now ) && (float) $now > 0 ) ? '<span class="marsad-change marsad-good">' . esc_html__( 'New', 'marsad' ) . '</span>' : '';
		}
		if ( null === $before || null === $now ) {
			return '<span class="marsad-flat" title="' . esc_attr__( 'Not known for the previous period', 'marsad' ) . '">—</span>';
		}
		return Marsad_Admin::change_badge( Marsad_Aggregate::change( $now, $before ), $better );
	}

	/** One dimension value as escaped markup: a link, a country chip or text. */
	public static function cell( $c, $key, $value, $title = '' ) {
		$format = self::format_of( $c, $key, true );
		if ( 'country' === $format ) {
			return '<span class="marsad-chip" dir="ltr">' . esc_html( strtoupper( $value ) ) . '</span>';
		}
		if ( 'link' === $format ) {
			$href = self::href( $value );
			$text = '' !== $title ? $title : self::readable( $value );
			if ( '' === $href ) {
				return '<span class="marsad-cell-text" dir="auto">' . esc_html( $text ) . '</span>';
			}
			$out = '<a class="marsad-cell-text" dir="auto" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
			if ( '' !== $title ) {
				$out .= '<small class="marsad-cell-path" dir="ltr">' . esc_html( self::readable( $value ) ) . '</small>';
			}
			return $out;
		}
		return '<span class="marsad-cell-text" dir="auto">' . esc_html( $value ) . '</span>';
	}

	/** Paths of this site and full web addresses get a link; anything else (javascript:) does not. */
	public static function href( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '#^https?://#i', $value ) ) {
			return $value;
		}
		if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) ) {
			return home_url( $value );
		}
		return '';
	}

	/** Arabic slugs arrive percent-encoded; shown decoded when that gives valid text. */
	public static function readable( $value ) {
		$dec = rawurldecode( (string) $value );
		return mb_check_encoding( $dec, 'UTF-8' ) ? $dec : (string) $value;
	}

	public static function export_url( $source, $range_key, $report ) {
		return add_query_arg(
			array(
				'action'   => 'marsad_export',
				'source'   => $source,
				'range'    => $range_key,
				'report'   => $report,
				'_wpnonce' => wp_create_nonce( 'marsad_export' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/* ── CSV ─────────────────────────────────────────────────────────── */

	/**
	 * A stored report as a CSV download: every stored row, translated headers, and a UTF-8 BOM
	 * so Excel opens Arabic correctly. Null when the source, range or report does not exist.
	 *
	 * @return array|null array( 'name' => file name, 'body' => CSV )
	 */
	public static function export( $source, $range_key, $report ) {
		$c = Marsad_Registry::get( (string) $source );
		if ( ! $c || ! in_array( (string) $range_key, Marsad_Snapshots::RANGES, true ) || '' === (string) $report ) {
			return null;
		}
		$got = Marsad_Snapshots::get( $c->id(), (string) $range_key, (string) $report );
		if ( ! $got ) {
			return null;
		}
		$name = 'marsad-' . $c->id() . '-' . $report . '-' . $range_key . '-' . gmdate( 'Y-m-d' ) . '.csv';
		return array(
			'name' => preg_replace( '/[^A-Za-z0-9_.-]/', '-', $name ),
			'body' => "\xEF\xBB\xBF" . self::csv( $c, $got['payload'] ),
		);
	}

	/** The CSV text (without BOM) of a totals or table payload. */
	public static function csv( Marsad_Connector $c, array $payload ) {
		$lines = array();
		if ( isset( $payload['metrics'] ) && is_array( $payload['metrics'] ) && ! isset( $payload['rows'] ) ) {
			$lines[] = array( __( 'Metric', 'marsad' ), __( 'Value', 'marsad' ), __( 'Previous period', 'marsad' ) );
			foreach ( $payload['metrics'] as $key => $m ) {
				$f       = self::format_of( $c, (string) $key, false );
				$lines[] = array( self::csv_label( $c, (string) $key, $f ), self::csv_num( $m['value'] ?? null, $f ), self::csv_num( $m['prev'] ?? null, $f ) );
			}
		} else {
			$t    = self::normalize( $payload );
			$prev = array();
			foreach ( $t['metrics'] as $m ) {
				foreach ( $t['rows'] as $r ) {
					if ( null !== $r['p'][ $m ] ) {
						$prev[] = $m;
						break;
					}
				}
			}
			$head = array();
			foreach ( $t['dims'] as $d ) {
				$head[] = self::label( $c, $d );
			}
			foreach ( $t['metrics'] as $m ) {
				$head[] = self::csv_label( $c, $m, self::format_of( $c, $m, false ) );
			}
			foreach ( $prev as $m ) {
				/* translators: %s: column name */
				$head[] = sprintf( __( '%s, previous period', 'marsad' ), self::csv_label( $c, $m, self::format_of( $c, $m, false ) ) );
			}
			$lines[] = $head;
			foreach ( $t['rows'] as $r ) {
				$line = array();
				foreach ( array_keys( $t['dims'] ) as $i ) {
					$line[] = (string) ( $r['d'][ $i ] ?? '' );
				}
				foreach ( $t['metrics'] as $m ) {
					$line[] = self::csv_num( $r['m'][ $m ], self::format_of( $c, $m, false ) );
				}
				foreach ( $prev as $m ) {
					$line[] = self::csv_num( $r['p'][ $m ], self::format_of( $c, $m, false ) );
				}
				$lines[] = $line;
			}
		}
		// csv_line() quotes every field and neutralises formulas in text, headers included.
		return implode( '', array_map( array( __CLASS__, 'csv_line' ), $lines ) );
	}

	/** Ratios become percentages in the file too, and say so in their header. */
	private static function csv_label( $c, $key, $format ) {
		return self::label( $c, $key ) . ( 'percent' === $format ? ' (%)' : '' );
	}

	private static function csv_num( $v, $format ) {
		if ( ! is_numeric( $v ) ) {
			return '';
		}
		return 'percent' === $format ? (string) round( $v * 100, 2 ) : (string) round( (float) $v, 4 );
	}

	/**
	 * A text cell that could run as a formula in a spreadsheet, also after a separator another locale
	 * uses (Excel splits on ";" in many countries), gets a leading quote so it stays text.
	 */
	private static function safe( $s ) {
		$s = (string) $s;
		if ( ( '' !== $s && false !== strpos( "=+-@\t\r", $s[0] ) ) || preg_match( '/[;,\t]\s*[=+\-@]/', $s ) ) {
			$s = "'" . str_replace( ';', ',', $s );
		}
		return $s;
	}

	/** One CSV line with every field quoted, so a separator inside a value never splits it. */
	public static function csv_line( array $fields ) {
		return implode(
			',',
			array_map(
				function ( $f ) {
					// Numbers (negative ones included) stay numbers; only text can hide a formula.
					$f = is_numeric( $f ) ? (string) $f : self::safe( $f );
					return '"' . str_replace( '"', '""', $f ) . '"';
				},
				$fields
			)
		) . "\r\n";
	}
}
