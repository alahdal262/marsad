<?php
/**
 * One card per source: status, setup steps, fields, and Save / Test / Disconnect.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Admin_Connections {

	/** Connector id the "Add a source" form posts; the handler turns it into a new source. */
	const NEW_ID = 'new';

	public static function render() {
		if ( ! current_user_can( Marsad_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to manage connections.', 'marsad' ), 403 );
		}
		$open   = sanitize_key( wp_unslash( $_GET['open'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = Marsad_Sync::status();
		$groups = array(
			'web'     => __( 'Website', 'marsad' ),
			'search'  => __( 'Search', 'marsad' ),
			'apps'    => __( 'Mobile apps', 'marsad' ),
			'social'  => __( 'Social media', 'marsad' ),
			'content' => __( 'Content', 'marsad' ),
			'custom'  => __( 'Your own sources', 'marsad' ),
		);
		$by_group = array();
		foreach ( Marsad_Registry::all() as $c ) {
			$by_group[ $c->group() ][] = $c;
		}

		echo '<div class="wrap marsad"><div class="marsad-top"><div><h1>' . esc_html__( 'Connections', 'marsad' ) . '</h1>';
		echo '<p class="marsad-sub">' . esc_html__( 'Each site connects its own accounts. Secrets are stored encrypted and are never shown again after saving.', 'marsad' ) . '</p></div>';
		echo '<div class="marsad-actions"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'marsad_sync_now' );
		echo '<input type="hidden" name="action" value="marsad_sync_now"><input type="hidden" name="back" value="connections"><button class="button">' . esc_html__( 'Sync all now', 'marsad' ) . '</button></form></div></div>';
		Marsad_Admin::print_notice();
		self::google_card();

		foreach ( $groups as $g => $title ) {
			if ( empty( $by_group[ $g ] ) && 'custom' !== $g ) {
				continue;
			}
			echo '<h2 class="marsad-group">' . esc_html( $title ) . '</h2>';
			if ( 'custom' === $g ) {
				echo '<p class="marsad-sub">' . esc_html__( 'Any address that returns numbers as JSON: a campaign, a form, a shop, an internal system. Add as many as you need.', 'marsad' ) . '</p>';
			}
			echo '<div class="marsad-conns">';
			foreach ( $by_group[ $g ] ?? array() as $c ) {
				self::card( $c, $status[ $c->id() ] ?? array(), $open === $c->id() );
			}
			if ( 'custom' === $g ) {
				self::add_card( 'new' === $open );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * One sign-in for every Google source. Service accounts stay available per source.
	 */
	private static function google_card() {
		$on = Marsad_Google_Account::connected();
		echo '<section class="marsad-google' . ( $on ? ' is-on' : '' ) . '"><div><h2>' . esc_html__( 'Google account', 'marsad' ) . '</h2>';
		if ( $on ) {
			/* translators: %s: Google account e-mail */
			echo '<p>' . esc_html( sprintf( __( 'Connected as %s. Analytics, Search Console, YouTube and Google Play use this account; pick what to follow in each card below.', 'marsad' ), Marsad_Google_Account::email() ) ) . '</p></div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'marsad_google_disconnect' );
			echo '<input type="hidden" name="action" value="marsad_google_disconnect"><button class="button-link button-link-delete">' . esc_html__( 'Disconnect Google', 'marsad' ) . '</button></form>';
		} else {
			echo '<p>' . esc_html__( 'One sign-in connects Google Analytics, Search Console, YouTube and Google Play. Marsad only reads statistics; you can remove its access at any time.', 'marsad' ) . '</p></div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'marsad_google_connect' );
			echo '<input type="hidden" name="action" value="marsad_google_connect"><button class="marsad-btn marsad-btn-google"><span class="marsad-g" aria-hidden="true">G</span>' . esc_html__( 'Connect with Google', 'marsad' ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * The "Add a source" button: a blank custom source form. Saving it creates the source.
	 */
	private static function add_card( $open ) {
		$c = new Marsad_Connector_Json( self::NEW_ID );
		echo '<details class="marsad-conn marsad-add"' . ( $open ? ' open' : '' ) . ' id="conn-new">';
		echo '<summary><b>' . esc_html__( '+ Add a source', 'marsad' ) . '</b></summary>';
		echo '<p>' . esc_html( $c->description() ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'marsad_save_connector' );
		echo '<input type="hidden" name="action" value="marsad_save_connector"><input type="hidden" name="connector" value="' . esc_attr( self::NEW_ID ) . '"><input type="hidden" name="enabled" value="1">';
		echo '<table class="form-table" role="presentation">';
		foreach ( $c->fields() as $key => $f ) {
			self::field( $c, $key, $f );
		}
		echo '</table><p class="marsad-buttons"><button class="button button-primary">' . esc_html__( 'Add and sync', 'marsad' ) . '</button></p></form>';
		echo '</details>';
	}

	private static function card( Marsad_Connector $c, array $st, $open ) {
		$saved   = Marsad_Registry::saved();
		$enabled = $c->always_on() || ! empty( $saved[ $c->id() ]['enabled'] );
		$state   = 'off';
		$label   = __( 'Not connected', 'marsad' );
		if ( $enabled && $c->is_configured() ) {
			if ( ! $st ) {
				$state = 'wait';
				$label = __( 'Waiting for first sync', 'marsad' );
			} elseif ( ! empty( $st['ok'] ) ) {
				$state = 'ok';
				/* translators: %s: e.g. "5 minutes ago" */
				$label = sprintf( __( 'Connected · synced %s', 'marsad' ), Marsad_Admin::ago( $st['at'] ) );
			} else {
				$state = 'bad';
				$label = __( 'Error', 'marsad' );
			}
		}

		echo '<details class="marsad-conn marsad-s-' . esc_attr( $state ) . '"' . ( $open || 'bad' === $state ? ' open' : '' ) . ' id="conn-' . esc_attr( $c->id() ) . '">';
		echo '<summary><b>' . esc_html( $c->label() ) . '</b><span class="marsad-pill">' . esc_html( $label ) . '</span></summary>';
		echo '<p>' . esc_html( $c->description() ) . '</p>';
		if ( 'bad' === $state ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $st['message'] ) . '</p></div>';
		}
		if ( $c->always_on() ) {
			echo '<p class="marsad-muted">' . esc_html__( 'Always on — nothing to set up.', 'marsad' ) . '</p></details>';
			return;
		}

		$steps = $c->instructions();
		if ( $steps ) {
			echo '<ol class="marsad-steps">';
			foreach ( $steps as $step ) {
				echo '<li>' . esc_html( $step ) . '</li>';
			}
			echo '</ol>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'marsad_save_connector' );
		echo '<input type="hidden" name="action" value="marsad_save_connector"><input type="hidden" name="connector" value="' . esc_attr( $c->id() ) . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Enabled', 'marsad' ) . '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Collect data from this source', 'marsad' ) . '</label></td></tr>';
		foreach ( $c->fields() as $key => $f ) {
			self::field( $c, $key, $f );
		}
		echo '</table><p class="marsad-buttons"><button class="button button-primary">' . esc_html__( 'Save', 'marsad' ) . '</button></p></form>';

		$custom = Marsad_Registry::is_custom( $c->id() );
		if ( $c->is_configured() || $custom ) {
			echo '<div class="marsad-row">';
			if ( $c->is_configured() ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'marsad_test_connector' );
				echo '<input type="hidden" name="action" value="marsad_test_connector"><input type="hidden" name="connector" value="' . esc_attr( $c->id() ) . '"><button class="button">' . esc_html__( 'Test connection', 'marsad' ) . '</button></form>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="marsad-forget">';
			wp_nonce_field( 'marsad_forget_connector' );
			echo '<input type="hidden" name="action" value="marsad_forget_connector"><input type="hidden" name="connector" value="' . esc_attr( $c->id() ) . '">';
			echo '<label><input type="checkbox" name="delete_data" value="1"> ' . esc_html__( 'also delete its collected data', 'marsad' ) . '</label> ';
			$verb = $custom ? __( 'Remove source', 'marsad' ) : __( 'Disconnect', 'marsad' );
			echo '<button class="button-link button-link-delete">' . esc_html( $verb ) . '</button></form>';
			echo '</div>';
		}
		echo '</details>';
	}

	private static function field( Marsad_Connector $c, $key, array $f ) {
		$type  = $f['type'] ?? 'text';
		$name  = 'f[' . $key . ']';
		$id    = 'marsad-' . $c->id() . '-' . $key;
		$value = (string) $c->get( $key );
		$req   = ! empty( $f['required'] ) ? ' <span class="marsad-req">*</span>' : '';
		$adv   = ! empty( $f['advanced'] );
		if ( $adv ) {
			// Advanced options stay folded unless already in use.
			echo '<tr class="marsad-adv"><th>' . esc_html__( 'Advanced', 'marsad' ) . '</th><td><details' . ( '' !== $value ? ' open' : '' ) . '><summary><label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label></summary>';
		} else {
			echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . '</label>' . $req . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $req is static markup.
		}
		$choices = Marsad_Admin_Connections::NEW_ID === $c->id() ? null : $c->choices( $key );
		if ( $choices ) {
			if ( '' !== $value && ! isset( $choices[ $value ] ) ) {
				$choices = array( $value => $value ) + $choices;
			}
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( '— Choose —', 'marsad' ) . '</option>';
			foreach ( $choices as $ov => $ol ) {
				echo '<option value="' . esc_attr( $ov ) . '"' . selected( $value, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
			}
			echo '</select>';
			if ( ! empty( $f['help'] ) ) {
				echo '<p class="description">' . esc_html( $f['help'] ) . '</p>';
			}
			echo $adv ? '</details></td></tr>' : '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		if ( Marsad_Registry::is_secret( $type ) ) {
			$has = Marsad_Registry::has_secret( $c->id(), $key );
			$ph  = $has ? __( 'Saved — leave empty to keep it', 'marsad' ) : '';
			if ( 'textarea_secret' === $type ) {
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text code" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( $ph ) . '"></textarea>';
			} else {
				echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $ph ) . '">';
			}
			if ( 'service_account' === $key && $has ) {
				$email = Marsad_Google_Auth::client_email( $value );
				if ( $email ) {
					echo '<p class="description">' . esc_html__( 'Service account e-mail (give it access in Google):', 'marsad' ) . ' <code class="marsad-copy">' . esc_html( $email ) . '</code></p>';
				}
			}
		} elseif ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
		} elseif ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $f['options'] as $ov => $ol ) {
				echo '<option value="' . esc_attr( $ov ) . '"' . selected( $value, $ov, false ) . '>' . esc_html( $ol ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="' . ( 'url' === $type ? 'url' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" dir="ltr">';
		}
		if ( ! empty( $f['help'] ) ) {
			echo '<p class="description">' . esc_html( $f['help'] ) . '</p>';
		}
		echo $adv ? '</details></td></tr>' : '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
