<?php
/**
 * Any JSON URL you own: campaign dashboards, internal counters, other plugins' APIs.
 * Each line of "Values" maps a label to a dot-path in the response.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Json extends Marsad_Connector {

	public function id() {
		return 'json';
	}

	public function label() {
		$name = trim( (string) $this->get( 'name' ) );
		return '' !== $name ? $name : __( 'Custom JSON source', 'marsad' );
	}

	public function group() {
		return 'custom';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Read numbers from any JSON address, for example a campaign dashboard or another system\'s API.', 'marsad' );
	}

	public function fields() {
		return array(
			'name'   => array( 'label' => __( 'Name on the dashboard', 'marsad' ), 'type' => 'text' ),
			'url'    => array( 'label' => __( 'JSON address', 'marsad' ), 'type' => 'secret', 'required' => true, 'help' => __( 'Stored encrypted, because such addresses often carry a key.', 'marsad' ) ),
			'map'    => array( 'label' => __( 'Values', 'marsad' ), 'type' => 'textarea', 'required' => true, 'help' => __( 'One per line: label = path. Example: Visitors = visitors   or   Shares = totals.shares', 'marsad' ) ),
			'mode'   => array(
				'label'   => __( 'The numbers are…', 'marsad' ),
				'type'    => 'select',
				'options' => array(
					'total' => __( 'running totals (followers, all-time counts)', 'marsad' ),
					'daily' => __( 'counts for today only (the address returns today\'s figures)', 'marsad' ),
				),
			),
		);
	}

	/**
	 * Parsed "label = path" lines, keyed by a stable metric id.
	 */
	public function map() {
		$out = array();
		foreach ( preg_split( '/\r\n|\n/', (string) $this->get( 'map' ) ) as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $label, $path ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $label || '' === $path ) {
				continue;
			}
			$out[ 'm_' . substr( md5( $path ), 0, 10 ) ] = array( 'label' => $label, 'path' => $path );
		}
		return $out;
	}

	public function metrics() {
		$agg = 'daily' === $this->get( 'mode', 'total' ) ? 'sum' : 'last';
		$out = array();
		foreach ( $this->map() as $id => $m ) {
			$out[ $id ] = array( 'label' => $m['label'], 'agg' => $agg, 'kpi' => true, 'chart' => true );
		}
		return $out;
	}

	public function fetch( $from, $to ) {
		$data = Marsad_Http::json( 'GET', (string) $this->get( 'url' ) );
		$rows = array();
		foreach ( $this->map() as $id => $m ) {
			$v = self::path( $data, $m['path'] );
			if ( is_numeric( $v ) ) {
				$rows[] = $this->row( $id, $this->today(), $v );
			}
		}
		if ( ! $rows ) {
			throw new Marsad_Exception( __( 'The address answered, but none of the paths pointed at a number.', 'marsad' ) );
		}
		return $rows;
	}

	public static function path( $data, $path ) {
		foreach ( explode( '.', $path ) as $part ) {
			if ( is_array( $data ) && array_key_exists( $part, $data ) ) {
				$data = $data[ $part ];
			} else {
				return null;
			}
		}
		return $data;
	}
}
