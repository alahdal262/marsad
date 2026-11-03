<?php
/**
 * Google Play installs from the monthly statistics reports Play Console writes to its
 * Cloud Storage bucket (pubsite_prod_…). Read with a service account.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Google_Play extends Marsad_Connector {

	const SCOPE = 'https://www.googleapis.com/auth/devstorage.read_only';

	public function id() {
		return 'google_play';
	}

	public function label() {
		return __( 'Google Play', 'marsad' );
	}

	public function group() {
		return 'apps';
	}

	public function description() {
		return __( 'Daily installs, uninstalls and active devices of your Android app.', 'marsad' );
	}

	public function fields() {
		return array(
			'package'         => array( 'label' => __( 'Package name', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => 'com.example.app' ),
			'bucket'          => array( 'label' => __( 'Reports bucket', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'Play Console → Download reports → Statistics → "Copy Cloud Storage URI": pubsite_prod_… (without gs://).', 'marsad' ) ),
			'service_account' => array( 'label' => __( 'Service account key (JSON)', 'marsad' ), 'type' => 'textarea_secret', 'advanced' => true, 'help' => __( 'Only if you prefer a service account to "Connect with Google": create one, add a JSON key, and give its e-mail read access in the product.', 'marsad' ), 'url' => 'https://console.cloud.google.com/iam-admin/serviceaccounts', 'link' => __( 'Service accounts', 'marsad' ) ),
		);
	}

	public function missing() {
		return $this->missing_google();
	}

	public function instructions() {
		return array(
			__( 'Press "Connect with Google" at the top of this page with an account that can download reports in Play Console.', 'marsad' ),
			$this->step( __( 'In Play Console open Download reports → Statistics, press "Copy Cloud Storage URI" and paste it as the reports bucket.', 'marsad' ), 'https://play.google.com/console/developers', __( 'Play Console', 'marsad' ) ),
			__( 'Type the package name (for example com.example.app) and save.', 'marsad' ),
			__( 'Play writes the reports once a day; new numbers appear with a 1–2 day delay.', 'marsad' ),
		);
	}

	public function window_days() {
		return 5;
	}

	public function max_backfill() {
		return 365;
	}

	public function metrics() {
		return array(
			'installs'       => array( 'label' => __( 'Installs (devices)', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'uninstalls'     => array( 'label' => __( 'Uninstalls (devices)', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true, 'better' => 'down' ),
			'user_installs'  => array( 'label' => __( 'Installs (users)', 'marsad' ), 'agg' => 'sum', 'kpi' => true ),
			'active_devices' => array( 'label' => __( 'Active devices', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
			'total_users'    => array( 'label' => __( 'Users who ever installed', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
		);
	}

	public function fetch( $from, $to ) {
		$bucket  = preg_replace( '#^gs://#', '', trim( (string) $this->get( 'bucket' ), " /\t" ) );
		$package = trim( (string) $this->get( 'package' ) );
		try {
			$auth = Marsad_Google_Account::bearer( $this->get( 'service_account' ), self::SCOPE );
		} catch ( Marsad_Exception $e ) {
			throw new Marsad_Exception( Marsad_Google_Auth::explain( $e, 'Cloud Storage', 'storage.googleapis.com' ) );
		}
		$rows = array();
		foreach ( self::months( $from, $to ) as $ym ) {
			$name = 'stats/installs/installs_' . $package . '_' . $ym . '_overview.csv';
			$res  = Marsad_Http::request( 'GET', 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode( $bucket ) . '/o/' . rawurlencode( $name ) . '?alt=media', array( 'headers' => $auth ) );
			if ( 404 === $res['code'] ) {
				continue; // Month not written yet.
			}
			if ( 403 === $res['code'] ) {
				// Play reports are readable only by accounts Play Console allows; say exactly where.
				$who = '' !== trim( (string) $this->get( 'service_account' ) ) ? Marsad_Google_Auth::client_email( $this->get( 'service_account' ) ) : Marsad_Google_Account::email();
				throw new Marsad_Exception(
					sprintf(
						/* translators: %s: Google account or service account e-mail */
						__( 'Play Console has not given %s access to the reports yet. In Play Console open Users and permissions, add this e-mail (or edit it), and tick the account permission "View app information and download bulk reports (read-only)". Google can take up to 24 hours to apply it.', 'marsad' ),
						$who
					)
				);
			}
			if ( 200 !== $res['code'] ) {
				throw new Marsad_Exception( Marsad_Http::error_message( $res['code'], json_decode( $res['body'], true ), $res['body'] ) );
			}
			foreach ( self::parse_csv( $res['body'] ) as $r ) {
				if ( $r['day'] >= $from && $r['day'] <= $to ) {
					$rows[] = $r;
				}
			}
		}
		return $rows;
	}

	/** YYYYMM strings covering the range. */
	public static function months( $from, $to ) {
		$out = array();
		$t   = strtotime( substr( $from, 0, 7 ) . '-01 UTC' );
		$end = strtotime( substr( $to, 0, 7 ) . '-01 UTC' );
		while ( $t <= $end ) {
			$out[] = gmdate( 'Ym', $t );
			$t     = strtotime( '+1 month', $t );
		}
		return $out;
	}

	/**
	 * Play writes UTF-16LE CSV with a BOM. Columns are matched by name because Google has
	 * renamed them over the years.
	 */
	public static function parse_csv( $raw ) {
		if ( "\xFF\xFE" === substr( $raw, 0, 2 ) ) {
			$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16LE' );
		} elseif ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
			$raw = substr( $raw, 3 );
		}
		$lines = preg_split( '/\r\n|\n|\r/', trim( $raw ) );
		$head  = array_map( 'strtolower', array_map( 'trim', str_getcsv( array_shift( $lines ), ',', '"', '\\' ) ) );
		$col   = array(
			'installs'       => array( 'daily device installs', 'install events' ),
			'uninstalls'     => array( 'daily device uninstalls', 'uninstall events' ),
			'user_installs'  => array( 'daily user installs' ),
			'active_devices' => array( 'active device installs' ),
			'total_users'    => array( 'total user installs' ),
		);
		$idx = array();
		foreach ( $col as $metric => $names ) {
			foreach ( $names as $n ) {
				$i = array_search( $n, $head, true );
				if ( false !== $i ) {
					$idx[ $metric ] = $i;
					break;
				}
			}
		}
		$date_i = array_search( 'date', $head, true );
		$rows   = array();
		foreach ( $lines as $line ) {
			$c = str_getcsv( $line, ',', '"', '\\' );
			if ( false === $date_i || empty( $c[ $date_i ] ) ) {
				continue;
			}
			$day = gmdate( 'Y-m-d', strtotime( trim( $c[ $date_i ] ) . ' UTC' ) );
			foreach ( $idx as $metric => $i ) {
				if ( isset( $c[ $i ] ) && '' !== trim( $c[ $i ] ) ) {
					$rows[] = array( 'metric' => $metric, 'dim' => '', 'day' => $day, 'value' => (float) str_replace( ',', '', $c[ $i ] ) );
				}
			}
		}
		return $rows;
	}
}
