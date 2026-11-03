<?php
/**
 * Base class for a data source. A connector declares its settings fields and the metrics it
 * produces, and fetches rows for a date range. Everything else (storage, scheduling, dashboard)
 * is generic, so a third-party connector only has to extend this and hook `marsad_connectors`.
 *
 * Metric definition keys:
 *   label   string  shown on the dashboard
 *   agg     sum|avg|last|max   how days collapse into one number (see Marsad_Aggregate::collapse)
 *   format  number|percent|seconds|decimal
 *   kpi     bool    show as a headline card
 *   chart   bool    plot as a line on the source's chart
 *   better  up|down which direction is good, for colouring the change
 *
 * Table definition keys (keyed by a breakdown metric):
 *   label, dim_label, agg, limit, link (dims are URLs or paths on this site)
 */

defined( 'ABSPATH' ) || exit;

abstract class Marsad_Connector {

	/** @var array decrypted field values */
	protected $values = array();

	abstract public function id();

	abstract public function label();

	/**
	 * Fetch rows covering $from..$to (Y-m-d, inclusive).
	 *
	 * @return array list of array( 'metric', 'dim', 'day', 'value' )
	 * @throws Marsad_Exception
	 */
	abstract public function fetch( $from, $to );

	/** web | search | social | apps | content | custom */
	public function group() {
		return 'custom';
	}

	public function description() {
		return '';
	}

	/** Step-by-step setup instructions shown under the fields. */
	public function instructions() {
		return array();
	}

	public function fields() {
		return array();
	}

	public function metrics() {
		return array();
	}

	public function tables() {
		return array();
	}

	/** Days re-fetched on every run, so late-arriving data is corrected. */
	public function window_days() {
		return 3;
	}

	/** Upper bound for the first import. */
	public function max_backfill() {
		return 90;
	}

	/** Snapshot sources only know "now"; their history builds up one day per day. */
	public function is_snapshot() {
		return false;
	}

	/** True for sources that need nothing configured (WordPress itself). */
	public function always_on() {
		return false;
	}

	public function set_values( array $values ) {
		$this->values = $values;
	}

	public function get( $key, $default = '' ) {
		return isset( $this->values[ $key ] ) && '' !== $this->values[ $key ] ? $this->values[ $key ] : $default;
	}

	/**
	 * Values the admin can pick for a field instead of typing them, e.g. the Analytics
	 * properties of the connected Google account. Null when there is nothing to offer.
	 *
	 * @return array|null value => label
	 */
	public function choices( $key ) {
		return null;
	}

	/** For Google sources: a service account key of its own, or the connected Google account. */
	protected function has_google_auth() {
		return '' !== trim( (string) $this->get( 'service_account' ) ) || Marsad_Google_Account::connected();
	}

	public function is_configured() {
		foreach ( $this->fields() as $key => $f ) {
			if ( ! empty( $f['required'] ) && '' === trim( (string) $this->get( $key ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Proves the credentials work. Returns a one-line summary for the admin.
	 *
	 * @throws Marsad_Exception
	 */
	public function test() {
		$day  = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$rows = $this->fetch( $this->is_snapshot() ? gmdate( 'Y-m-d' ) : $day, $this->is_snapshot() ? gmdate( 'Y-m-d' ) : $day );
		/* translators: %d: number of values */
		return sprintf( _n( 'Connected — %d value received.', 'Connected — %d values received.', count( $rows ), 'marsad' ), count( $rows ) );
	}

	protected function row( $metric, $day, $value, $dim = '' ) {
		return array(
			'metric' => $metric,
			'dim'    => (string) $dim,
			'day'    => $day,
			'value'  => (float) $value,
		);
	}

	protected function today() {
		return gmdate( 'Y-m-d' );
	}
}
