<?php
/**
 * X (Twitter) account numbers through the X API v2. Reading users needs a paid API plan
 * (Basic or higher) since 2023; the free plan can only post.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_X extends Marsad_Connector {

	const API = 'https://api.x.com/2/';

	public function id() {
		return 'x';
	}

	public function label() {
		return __( 'X (Twitter)', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Followers, posts and lists of an X account. X sells read access: this needs a Basic (paid) API plan.', 'marsad' );
	}

	public function fields() {
		return array(
			'username' => array( 'label' => __( 'Account', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( 'The @username, for example @YemenTV. Any public account works.', 'marsad' ) ),
			'bearer'   => array( 'label' => __( 'Bearer token', 'marsad' ), 'type' => 'secret', 'required' => true ),
		);
	}

	public function instructions() {
		return array(
			$this->step( __( 'In the X developer portal, subscribe the project to the Basic plan (reading accounts is not in the free plan).', 'marsad' ), 'https://developer.x.com/en/portal/products', __( 'X API plans', 'marsad' ) ),
			$this->step( __( 'Open your app → Keys and tokens, and copy the Bearer token here.', 'marsad' ), 'https://developer.x.com/en/portal/dashboard', __( 'X developer portal', 'marsad' ) ),
		);
	}

	public function metrics() {
		return array(
			'followers' => array( 'label' => __( 'Followers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
			'posts'     => array( 'label' => __( 'Posts', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
			'following' => array( 'label' => __( 'Following', 'marsad' ), 'agg' => 'last' ),
			'listed'    => array( 'label' => __( 'On lists', 'marsad' ), 'agg' => 'last', 'kpi' => true ),
		);
	}

	public function fetch( $from, $to ) {
		$user = preg_replace( '/[^A-Za-z0-9_]/', '', ltrim( trim( (string) $this->get( 'username' ) ), '@' ) );
		$res  = Marsad_Http::request(
			'GET',
			self::API . 'users/by/username/' . rawurlencode( $user ) . '?user.fields=public_metrics',
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $this->get( 'bearer' ) ) )
		);
		$data = json_decode( $res['body'], true );
		if ( in_array( $res['code'], array( 402, 403 ), true ) ) {
			throw new Marsad_Exception( __( 'X refused to read the account: reading needs a paid API plan (Basic or higher) on the project that owns this token.', 'marsad' ) );
		}
		if ( 200 !== $res['code'] || empty( $data['data']['public_metrics'] ) ) {
			throw new Marsad_Exception( Marsad_Http::error_message( $res['code'], $data, $res['body'] ) );
		}
		$m   = $data['data']['public_metrics'];
		$day = $this->today();
		return array(
			$this->row( 'followers', $day, $m['followers_count'] ?? 0 ),
			$this->row( 'posts', $day, $m['tweet_count'] ?? 0 ),
			$this->row( 'following', $day, $m['following_count'] ?? 0 ),
			$this->row( 'listed', $day, $m['listed_count'] ?? 0 ),
		);
	}
}
