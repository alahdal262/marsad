<?php
/**
 * Updates from GitHub releases. Each release must attach a marsad.zip whose top folder is
 * "marsad/"; the release tag (v1.2.3) is the version. Sites then see the update on
 * Dashboard → Updates like any other plugin.
 *
 * Turn off with define( 'MARSAD_DISABLE_GITHUB_UPDATES', true ) — for example in a build
 * shipped through WordPress.org, which updates plugins itself.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Updater {

	const CACHE = 'marsad_release';
	const ASSET = 'marsad.zip';

	public static function init() {
		if ( defined( 'MARSAD_DISABLE_GITHUB_UPDATES' ) && MARSAD_DISABLE_GITHUB_UPDATES ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function basename() {
		return plugin_basename( MARSAD_FILE );
	}

	/**
	 * Latest release as array( version, zip, body, url, published ), or null.
	 */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}
		$release = null;
		try {
			$res = Marsad_Http::request(
				'GET',
				'https://api.github.com/repos/' . MARSAD_UPDATE_REPO . '/releases/latest',
				array( 'headers' => array( 'Accept' => 'application/vnd.github+json' ), 'timeout' => 10 )
			);
			if ( 200 === $res['code'] ) {
				$release = self::from_github( json_decode( $res['body'], true ) );
			}
		} catch ( Marsad_Exception $e ) {
			$release = null;
		}
		// Cache misses too, so an unreachable GitHub does not slow every admin page.
		set_site_transient( self::CACHE, $release ? $release : array( 'version' => '' ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Public for tests.
	 */
	public static function from_github( $data ) {
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$zip = '';
		foreach ( $data['assets'] ?? array() as $a ) {
			if ( self::ASSET === ( $a['name'] ?? '' ) ) {
				$zip = $a['browser_download_url'] ?? '';
			}
		}
		if ( '' === $zip ) {
			return null;
		}
		return array(
			'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
			'zip'       => $zip,
			'body'      => (string) ( $data['body'] ?? '' ),
			'url'       => (string) ( $data['html_url'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	public static function is_newer( $remote, $local ) {
		return '' !== $remote && version_compare( $remote, $local, '>' );
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$r = self::latest();
		if ( ! $r ) {
			return $transient;
		}
		$item = (object) array(
			'id'          => 'github.com/' . MARSAD_UPDATE_REPO,
			'slug'        => 'marsad',
			'plugin'      => self::basename(),
			'new_version' => $r['version'],
			'url'         => 'https://github.com/' . MARSAD_UPDATE_REPO,
			'package'     => $r['zip'],
			'requires'    => '6.5',
			'requires_php' => '7.4',
		);
		if ( self::is_newer( $r['version'], MARSAD_VERSION ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'marsad' !== $args->slug ) {
			return $result;
		}
		$r = self::latest();
		return (object) array(
			'name'          => 'Marsad — Unified Analytics',
			'slug'          => 'marsad',
			'version'       => $r ? $r['version'] : MARSAD_VERSION,
			'author'        => '<a href="https://github.com/' . esc_attr( MARSAD_UPDATE_REPO ) . '">Marsad</a>',
			'homepage'      => 'https://github.com/' . MARSAD_UPDATE_REPO,
			'download_link' => $r ? $r['zip'] : '',
			'requires'      => '6.5',
			'requires_php'  => '7.4',
			'last_updated'  => $r ? $r['published'] : '',
			'sections'      => array(
				'description' => esc_html__( 'One dashboard for every number your site lives on.', 'marsad' ),
				'changelog'   => $r ? wpautop( esc_html( $r['body'] ) ) : '',
			),
		);
	}

	/**
	 * GitHub's auto-generated source archives unpack to "marsad-1.2.3/"; keep the folder
	 * name "marsad" so the plugin stays active after the update.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . 'marsad/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want ) ) {
			return $want;
		}
		return $source;
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}
}
