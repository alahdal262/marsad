<?php
/**
 * Instagram professional account (linked to a Facebook Page) through the Graph API.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Instagram extends Marsad_Connector {

	public function id() {
		return 'instagram';
	}

	public function label() {
		return __( 'Instagram', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Followers and number of posts of an Instagram business or creator account.', 'marsad' );
	}

	public function fields() {
		return array(
			'ig_user_id' => array( 'label' => __( 'Instagram account ID', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'From /{page-id}?fields=instagram_business_account in Graph API Explorer.', 'marsad' ) ),
			'token'      => array( 'label' => __( 'Page access token', 'marsad' ), 'type' => 'secret', 'required' => true, 'help' => __( 'The Page token of the Facebook Page linked to the account, with instagram_basic.', 'marsad' ) ),
		);
	}

	public function metrics() {
		return array(
			'followers' => array( 'label' => __( 'Followers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'posts'     => array( 'label' => __( 'Posts', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
		);
	}

	public function fetch( $from, $to ) {
		$u   = Marsad_Http::json( 'GET', Marsad_Connector_Facebook::GRAPH . rawurlencode( trim( (string) $this->get( 'ig_user_id' ) ) ) . '?' . http_build_query( array( 'fields' => 'followers_count,media_count', 'access_token' => $this->get( 'token' ) ) ) );
		$day = $this->today();
		return array(
			$this->row( 'followers', $day, $u['followers_count'] ?? 0 ),
			$this->row( 'posts', $day, $u['media_count'] ?? 0 ),
		);
	}
}
