<?php
/**
 * Just enough of WordPress for Marsad's classes to run under plain PHP.
 * State (options, transients) lives in globals so each test can reset it.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARSAD_VERSION', '0.1.0' );
define( 'MARSAD_FILE', dirname( __DIR__ ) . '/marsad.php' );
define( 'MARSAD_UPDATE_REPO', 'alahdal262/marsad' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'LOGGED_IN_KEY', 'test-key' );
define( 'LOGGED_IN_SALT', 'test-salt' );

$GLOBALS['wp_test_options']    = array();
$GLOBALS['wp_test_transients'] = array();

function marsad_test_reset() {
	$GLOBALS['wp_test_options']    = array();
	$GLOBALS['wp_test_transients'] = array();
	Marsad_Http::$mock             = null;
	Marsad_Registry::reset_cache();
	$GLOBALS['wpdb']               = new Marsad_Test_Wpdb();
}

function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === $n ? $a : $b; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function apply_filters( $tag, $v ) { return $v; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function get_current_user_id() { return 1; }
function date_i18n( $f, $t ) { return gmdate( $f, $t ); }
function is_wp_error( $x ) { return false; }

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['wp_test_options'] ) ? $GLOBALS['wp_test_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['wp_test_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['wp_test_options'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['wp_test_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['wp_test_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['wp_test_transients'][ $k ] ); return true; }
function get_site_transient( $k ) { return get_transient( 'site_' . $k ); }
function set_site_transient( $k, $v, $e = 0 ) { return set_transient( 'site_' . $k, $v, $e ); }
function delete_site_transient( $k ) { return delete_transient( 'site_' . $k ); }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }

/** Records what Marsad_Store writes; enough to test the sync flow. */
class Marsad_Test_Wpdb {
	public $prefix  = 'wp_';
	public $queries = array();
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$sql = str_replace( array( '%s', '%f', '%d' ), array( "'%s'", '%F', '%d' ), $sql );
		return vsprintf( $sql, $args );
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function db_server_info() { return '11.8.9-MariaDB'; }
	public $first_day = null;
	public function get_var( $sql ) { $this->queries[] = $sql; return false !== strpos( $sql, 'MIN(day)' ) ? $this->first_day : null; }
	public function get_row( $sql ) { $this->queries[] = $sql; return null; }
	/** Rows Store reads back: array( source, metric, dim, day, value ). */
	public $fixture = array();
	public function get_results( $sql ) {
		$this->queries[] = $sql;
		if ( ! preg_match( "/source='([^']*)' AND metric='([^']*)'.*BETWEEN '([^']*)' AND '([^']*)'/", $sql, $m ) ) {
			return array();
		}
		$breakdown = false !== strpos( $sql, "dim<>''" );
		$out       = array();
		foreach ( $this->fixture as $f ) {
			list( $source, $metric, $dim, $day, $value ) = $f;
			if ( $source === $m[1] && $metric === $m[2] && $day >= $m[3] && $day <= $m[4] && $breakdown === ( '' !== $dim ) ) {
				$out[] = (object) array( 'dim' => $dim, 'day' => $day, 'value' => $value );
			}
		}
		return $out;
	}
	public function delete( $t, $w ) { $this->queries[] = 'DELETE ' . $t; return 1; }
}

require dirname( __DIR__ ) . '/includes/autoload.php';
