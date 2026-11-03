<?php
/**
 * Apple App Store downloads from the App Store Connect daily sales summary reports.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_App_Store extends Marsad_Connector {

	// Product Type Identifiers in the Sales report.
	const DOWNLOADS   = array( '1', '1F', '1T', 'F1', '1E', '1EP', '1EU' );
	const REDOWNLOADS = array( '3', '3F', '3T', 'F3' );
	const UPDATES     = array( '7', '7F', '7T', 'F7' );

	public function id() {
		return 'app_store';
	}

	public function label() {
		return __( 'App Store', 'marsad' );
	}

	public function group() {
		return 'apps';
	}

	public function description() {
		return __( 'First-time downloads, re-downloads and updates of your iPhone, iPad and Apple TV apps, by country.', 'marsad' );
	}

	public function fields() {
		return array(
			'issuer_id' => array( 'label' => __( 'Issuer ID', 'marsad' ), 'type' => 'text', 'required' => true ),
			'key_id'    => array( 'label' => __( 'Key ID', 'marsad' ), 'type' => 'text', 'required' => true ),
			'vendor'    => array( 'label' => __( 'Vendor number', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'App Store Connect → Payments and Financial Reports (top left).', 'marsad' ) ),
			'apple_id'  => array( 'label' => __( 'Apple ID of the app (optional)', 'marsad' ), 'type' => 'text', 'help' => __( 'Leave empty to count every app of the account.', 'marsad' ) ),
			'p8'        => array( 'label' => __( 'Private key (.p8)', 'marsad' ), 'type' => 'textarea_secret', 'required' => true ),
		);
	}

	public function instructions() {
		return array(
			$this->step( __( 'Generate an API key with the Sales or Finance role. Copy the Issuer ID and Key ID shown on that page.', 'marsad' ), 'https://appstoreconnect.apple.com/access/integrations/api', __( 'App Store Connect API keys', 'marsad' ) ),
			__( 'Download the .p8 file (Apple lets you download it only once) and paste its contents here.', 'marsad' ),
			$this->step( __( 'The vendor number is at the top left of Payments and Financial Reports.', 'marsad' ), 'https://appstoreconnect.apple.com/itc/payments_and_financial_reports', __( 'Payments and Financial Reports', 'marsad' ) ),
			__( 'Apple publishes each day\'s report the next day; today is never available.', 'marsad' ),
		);
	}

	public function window_days() {
		return 4;
	}

	// One request per day; Apple keeps daily reports for about a year.
	public function max_backfill() {
		return 60;
	}

	public function metrics() {
		return array(
			'downloads'   => array( 'label' => __( 'First-time downloads', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'redownloads' => array( 'label' => __( 'Re-downloads', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'updates'     => array( 'label' => __( 'Updates', 'marsad' ), 'agg' => 'sum', 'kpi' => true ),
		);
	}

	public function tables() {
		return array(
			'downloads_by_country' => array( 'label' => __( 'Downloads by country', 'marsad' ), 'dim_label' => __( 'Country', 'marsad' ), 'agg' => 'sum', 'limit' => 15 ),
		);
	}

	private function jwt() {
		$now = time();
		return Marsad_Jwt::sign(
			array( 'alg' => 'ES256', 'kid' => trim( (string) $this->get( 'key_id' ) ), 'typ' => 'JWT' ),
			array( 'iss' => trim( (string) $this->get( 'issuer_id' ) ), 'iat' => $now, 'exp' => $now + 1100, 'aud' => 'appstoreconnect-v1' ),
			(string) $this->get( 'p8' )
		);
	}

	public function fetch( $from, $to ) {
		$auth = array(
			'Authorization'   => 'Bearer ' . $this->jwt(),
			'Accept'          => 'application/a-gzip',
		);
		$last = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$rows = array();
		foreach ( Marsad_Aggregate::days( $from, min( $to, $last ) ) as $day ) {
			$q   = array(
				'filter[frequency]'     => 'DAILY',
				'filter[reportType]'    => 'SALES',
				'filter[reportSubType]' => 'SUMMARY',
				'filter[vendorNumber]'  => trim( (string) $this->get( 'vendor' ) ),
				'filter[reportDate]'    => $day,
				'filter[version]'       => '1_1',
			);
			$res = Marsad_Http::request( 'GET', 'https://api.appstoreconnect.apple.com/v1/salesReports?' . http_build_query( $q ), array( 'headers' => $auth, 'timeout' => 40 ) );
			if ( 404 === $res['code'] ) {
				continue; // No sales that day, or not published yet.
			}
			if ( 200 !== $res['code'] ) {
				throw new Marsad_Exception( Marsad_Http::error_message( $res['code'], json_decode( $res['body'], true ), $res['body'] ) );
			}
			$tsv = ( "\x1f\x8b" === substr( $res['body'], 0, 2 ) ) ? gzdecode( $res['body'] ) : $res['body'];
			$rows = array_merge( $rows, self::parse( (string) $tsv, $day, trim( (string) $this->get( 'apple_id' ) ) ) );
		}
		return $rows;
	}

	/**
	 * Public for tests.
	 */
	public static function parse( $tsv, $day, $apple_id = '' ) {
		$lines = preg_split( '/\r\n|\n/', trim( $tsv ) );
		$head  = str_getcsv( array_shift( $lines ), "\t", '"', '\\' );
		$i     = array_flip( $head );
		$sum   = array( 'downloads' => 0, 'redownloads' => 0, 'updates' => 0 );
		$cty   = array();
		foreach ( $lines as $line ) {
			$c = str_getcsv( $line, "\t", '"', '\\' );
			if ( $apple_id && isset( $i['Apple Identifier'] ) && ( $c[ $i['Apple Identifier'] ] ?? '' ) !== $apple_id ) {
				continue;
			}
			$type  = $c[ $i['Product Type Identifier'] ?? -1 ] ?? '';
			$units = (float) ( $c[ $i['Units'] ?? -1 ] ?? 0 );
			if ( in_array( $type, self::DOWNLOADS, true ) ) {
				$sum['downloads'] += $units;
				$cc                = $c[ $i['Country Code'] ?? -1 ] ?? '';
				if ( '' !== $cc ) {
					$cty[ $cc ] = ( $cty[ $cc ] ?? 0 ) + $units;
				}
			} elseif ( in_array( $type, self::REDOWNLOADS, true ) ) {
				$sum['redownloads'] += $units;
			} elseif ( in_array( $type, self::UPDATES, true ) ) {
				$sum['updates'] += $units;
			}
		}
		$rows = array();
		foreach ( $sum as $metric => $v ) {
			$rows[] = array( 'metric' => $metric, 'dim' => '', 'day' => $day, 'value' => $v );
		}
		foreach ( $cty as $cc => $v ) {
			$rows[] = array( 'metric' => 'downloads_by_country', 'dim' => $cc, 'day' => $day, 'value' => $v );
		}
		return $rows;
	}
}
