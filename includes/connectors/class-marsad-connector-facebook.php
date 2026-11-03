<?php
/**
 * Facebook Page followers through the Graph API with a Page access token.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Facebook extends Marsad_Connector {

	const GRAPH = 'https://graph.facebook.com/v21.0/';

	public function id() {
		return 'facebook';
	}

	public function label() {
		return __( 'Facebook Page', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Page followers and likes, recorded every day so you can see growth.', 'marsad' );
	}

	public function fields() {
		return array(
			'page_id' => array( 'label' => __( 'Page ID', 'marsad' ), 'type' => 'text', 'required' => true ),
			'token'   => array( 'label' => __( 'Page access token', 'marsad' ), 'type' => 'secret', 'required' => true, 'help' => __( 'Use a long-lived Page token (never expires) with pages_read_engagement.', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			__( 'Create an app at developers.facebook.com (type Business).', 'marsad' ),
			__( 'In Graph API Explorer pick the app and your Page, grant pages_read_engagement and pages_show_list, generate a token.', 'marsad' ),
			__( 'Exchange it for a long-lived token, then call /me/accounts to get the Page token that never expires.', 'marsad' ),
		);
	}

	public function metrics() {
		return array(
			'followers' => array( 'label' => __( 'Followers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'fans'      => array( 'label' => __( 'Page likes', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
			'talking'   => array( 'label' => __( 'People talking about this', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
		);
	}

	public function fetch( $from, $to ) {
		$page = Marsad_Http::json( 'GET', self::GRAPH . rawurlencode( trim( (string) $this->get( 'page_id' ) ) ) . '?' . http_build_query( array( 'fields' => 'followers_count,fan_count,talking_about_count', 'access_token' => $this->get( 'token' ) ) ) );
		$day  = $this->today();
		return array(
			$this->row( 'followers', $day, $page['followers_count'] ?? 0 ),
			$this->row( 'fans', $day, $page['fan_count'] ?? 0 ),
			$this->row( 'talking', $day, $page['talking_about_count'] ?? 0 ),
		);
	}
}
