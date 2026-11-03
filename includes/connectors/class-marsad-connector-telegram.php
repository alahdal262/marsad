<?php
/**
 * Telegram channel subscribers through a bot that is an admin of the channel.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Telegram extends Marsad_Connector {

	public function id() {
		return 'telegram';
	}

	public function label() {
		return __( 'Telegram', 'marsad' );
	}

	public function group() {
		return 'social';
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Subscribers of a Telegram channel or members of a group.', 'marsad' );
	}

	public function fields() {
		return array(
			'chat'  => array( 'label' => __( 'Channel', 'marsad' ), 'type' => 'text', 'required' => true, 'help' => __( '@username of a public channel, or the numeric -100… id.', 'marsad' ) ),
			'token' => array( 'label' => __( 'Bot token', 'marsad' ), 'type' => 'secret', 'required' => true, 'help' => __( 'Any bot works for a public channel; a private channel needs the bot added as an administrator.', 'marsad' ) ),
		);
	}

	public function instructions() {
		return array(
			$this->step( __( 'Open @BotFather in Telegram, send /newbot, choose a name, and copy the token it replies with.', 'marsad' ), 'https://t.me/BotFather', __( 'Open BotFather', 'marsad' ) ),
			__( 'Paste the token and your channel\'s @username here, then save.', 'marsad' ),
		);
	}

	public function metrics() {
		return array(
			'members' => array( 'label' => __( 'Subscribers', 'marsad' ), 'agg' => 'last', 'kpi' => true, 'chart' => true ),
		);
	}

	public function fetch( $from, $to ) {
		$token = trim( (string) $this->get( 'token' ) );
		if ( ! preg_match( '/^\d+:[A-Za-z0-9_-]+$/', $token ) ) {
			throw new Marsad_Exception( __( 'The bot token looks wrong. It has the form 123456:ABC-xyz.', 'marsad' ) );
		}
		$res = Marsad_Http::json( 'GET', 'https://api.telegram.org/bot' . $token . '/getChatMemberCount?' . http_build_query( array( 'chat_id' => trim( (string) $this->get( 'chat' ) ) ) ) );
		if ( empty( $res['ok'] ) ) {
			throw new Marsad_Exception( $res['description'] ?? 'Telegram error' );
		}
		return array( $this->row( 'members', $this->today(), $res['result'] ?? 0 ) );
	}
}
