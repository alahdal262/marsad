<?php

class Marsad_Test_Connector extends Marsad_Connector {
	public $calls = array();
	public $fail  = false;
	public function id() { return 'fake'; }
	public function label() { return 'Fake'; }
	public function fields() {
		return array(
			'name'  => array( 'label' => 'Name', 'type' => 'text', 'required' => true ),
			'token' => array( 'label' => 'Token', 'type' => 'secret', 'required' => true ),
		);
	}
	public function window_days() { return 3; }
	public function max_backfill() { return 30; }
	public function fetch( $from, $to ) {
		$this->calls[] = array( $from, $to );
		if ( $this->fail ) {
			throw new Marsad_Exception( 'boom' );
		}
		return array( $this->row( 'm', $to, 5 ) );
	}
}

function test_registry_encrypts_secrets_and_keeps_them_when_left_empty() {
	$c = new Marsad_Test_Connector();
	Marsad_Registry::save( $c, true, array( 'name' => ' Site <b>A</b> ', 'token' => 'abc' ) );
	$saved = Marsad_Registry::saved()['fake'];
	same( 'Site A', $saved['values']['name'], 'text sanitised' );
	check( 0 === strpos( $saved['values']['token'], 'mz1:' ), 'secret encrypted at rest' );

	Marsad_Registry::save( $c, true, array( 'name' => 'Site B', 'token' => '' ) );
	$again = Marsad_Registry::saved()['fake'];
	same( $saved['values']['token'], $again['values']['token'], 'empty secret input keeps the stored secret' );
	same( 'abc', Marsad_Crypto::decrypt( $again['values']['token'] ) );
	check( Marsad_Registry::has_secret( 'fake', 'token' ) );
}

function test_connector_configured_only_with_required_fields() {
	$c = new Marsad_Test_Connector();
	$c->set_values( array( 'name' => 'x', 'token' => '' ) );
	check( ! $c->is_configured() );
	$c->set_values( array( 'name' => 'x', 'token' => 't' ) );
	check( $c->is_configured() );
}

function test_sync_backfills_first_then_refreshes_window() {
	$c     = new Marsad_Test_Connector();
	$today = gmdate( 'Y-m-d' );
	$st    = Marsad_Sync::run( $c );
	check( $st['ok'] && $st['backfilled'] );
	same( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), $c->calls[0][0], 'first run: min(setting 90, connector 30) days' );
	same( $today, $c->calls[0][1] );
	Marsad_Sync::run( $c );
	same( gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), $c->calls[1][0], 'later runs: window only' );
	check( false !== strpos( implode( ' ', $GLOBALS['wpdb']->queries ), 'INSERT INTO wp_marsad_metrics' ), 'rows written' );
}

function test_sync_failure_is_recorded_and_retries_backfill() {
	$c       = new Marsad_Test_Connector();
	$c->fail = true;
	$st      = Marsad_Sync::run( $c );
	check( ! $st['ok'] );
	same( 'boom', $st['message'] );
	check( empty( $st['backfilled'] ), 'a failed first run does not count as imported' );
	$c->fail = false;
	Marsad_Sync::run( $c );
	same( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), $c->calls[1][0], 'backfill retried' );
}

function test_updater_reads_github_release() {
	$rel = Marsad_Updater::from_github(
		array(
			'tag_name' => 'v0.2.0',
			'assets'   => array( array( 'name' => 'other.zip', 'browser_download_url' => 'x' ), array( 'name' => 'marsad.zip', 'browser_download_url' => 'https://github.com/a/b/releases/download/v0.2.0/marsad.zip' ) ),
			'body'     => 'notes',
		)
	);
	same( '0.2.0', $rel['version'] );
	same( 'https://github.com/a/b/releases/download/v0.2.0/marsad.zip', $rel['zip'] );
	same( null, Marsad_Updater::from_github( array( 'tag_name' => 'v1', 'assets' => array() ) ), 'release without marsad.zip is ignored' );
	same( null, Marsad_Updater::from_github( array( 'tag_name' => 'v9', 'draft' => true, 'assets' => array( array( 'name' => 'marsad.zip', 'browser_download_url' => 'u' ) ) ) ) );
	check( Marsad_Updater::is_newer( '0.2.0', '0.1.0' ) );
	check( ! Marsad_Updater::is_newer( '0.1.0', '0.1.0' ) );
	check( ! Marsad_Updater::is_newer( '', '0.1.0' ) );
}

function test_updater_injects_update_only_when_newer() {
	set_site_transient( 'marsad_release', array( 'version' => '0.3.0', 'zip' => 'https://z/marsad.zip', 'body' => '', 'url' => '', 'published' => '' ) );
	$t = Marsad_Updater::inject( (object) array( 'response' => array(), 'no_update' => array() ) );
	$item = reset( $t->response );
	same( '0.3.0', $item->new_version );
	same( 'https://z/marsad.zip', $item->package );

	set_site_transient( 'marsad_release', array( 'version' => '0.1.0', 'zip' => 'https://z/marsad.zip', 'body' => '', 'url' => '', 'published' => '' ) );
	$t = Marsad_Updater::inject( (object) array( 'response' => array(), 'no_update' => array() ) );
	same( array(), $t->response );
	same( 1, count( $t->no_update ) );
}
