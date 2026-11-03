<?php
/**
 * The site itself: how much content it holds, who can sign in, and how the hosting
 * is doing. Needs no setup. Recorded once a day so the totals get a history.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Site extends Marsad_Connector {

	/** Uploads are walked at most twice a day; big libraries take a few seconds. */
	const SIZE_CACHE = 'marsad_uploads_bytes';

	public function id() {
		return 'site';
	}

	public function label() {
		return __( 'Site & hosting', 'marsad' );
	}

	public function group() {
		return 'site';
	}

	public function always_on() {
		return true;
	}

	public function is_snapshot() {
		return true;
	}

	public function description() {
		return __( 'Totals of posts, pages, media, comments and users, plus database size, uploads size and response time.', 'marsad' );
	}

	public function metrics() {
		$last = function ( $label, $format = 'number' ) {
			return array( 'label' => $label, 'agg' => 'last', 'format' => $format );
		};
		return array(
			'posts_total'       => $last( __( 'Posts', 'marsad' ) ),
			'pages'             => $last( __( 'Pages', 'marsad' ) ),
			'images'            => $last( __( 'Images', 'marsad' ) ),
			'videos'            => $last( __( 'Videos', 'marsad' ) ),
			'other_media'       => $last( __( 'Other files', 'marsad' ) ),
			'comments_approved' => $last( __( 'Approved comments', 'marsad' ) ),
			'comments_pending'  => $last( __( 'Awaiting moderation', 'marsad' ) ),
			'comments_spam'     => $last( __( 'Spam', 'marsad' ) ),
			'comments_trash'    => $last( __( 'In the trash', 'marsad' ) ),
			'users'             => $last( __( 'Users', 'marsad' ) ),
			'categories'        => $last( __( 'Categories', 'marsad' ) ),
			'tags'              => $last( __( 'Tags', 'marsad' ) ),
			'db_mb'             => $last( __( 'Database size (MB)', 'marsad' ), 'decimal' ),
			'db_tables'         => $last( __( 'Database tables', 'marsad' ) ),
			'uploads_mb'        => $last( __( 'Uploads size (MB)', 'marsad' ), 'decimal' ),
			'response_ms'       => array( 'label' => __( 'Home page response (ms)', 'marsad' ), 'agg' => 'avg', 'better' => 'down' ),
		);
	}

	public function tables() {
		return array(
			'users_by_role' => array( 'label' => __( 'Users by role', 'marsad' ), 'agg' => 'last', 'limit' => 20 ),
		);
	}

	public function fetch( $from, $to ) {
		global $wpdb;
		$day  = $this->today();
		$rows = array();
		$put  = function ( $metric, $value, $dim = '' ) use ( &$rows, $day ) {
			if ( null !== $value ) {
				$rows[] = $this->row( $metric, $day, $value, $dim );
			}
		};

		$put( 'posts_total', (int) wp_count_posts( 'post' )->publish );
		$put( 'pages', (int) wp_count_posts( 'page' )->publish );
		foreach ( self::media_counts( (array) wp_count_attachments() ) as $k => $n ) {
			$put( 'other' === $k ? 'other_media' : $k, $n );
		}
		$cm = wp_count_comments();
		$put( 'comments_approved', (int) $cm->approved );
		$put( 'comments_pending', (int) $cm->moderated );
		$put( 'comments_spam', (int) $cm->spam );
		$put( 'comments_trash', (int) $cm->trash );

		$users = count_users();
		$put( 'users', (int) $users['total_users'] );
		$names = wp_roles()->get_names();
		foreach ( $users['avail_roles'] as $role => $n ) {
			if ( $n > 0 && 'none' !== $role ) {
				$put( 'users_by_role', (int) $n, translate_user_role( $names[ $role ] ?? $role ) );
			}
		}
		$put( 'categories', (int) wp_count_terms( array( 'taxonomy' => 'category' ) ) );
		$put( 'tags', (int) wp_count_terms( array( 'taxonomy' => 'post_tag' ) ) );

		// Some hosts hide information_schema; then the size is simply unknown.
		$db = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(data_length + index_length) b, COUNT(*) n FROM information_schema.tables WHERE table_schema = %s', DB_NAME ) );
		if ( $db && $db->n ) {
			$put( 'db_mb', round( $db->b / 1048576, 1 ) );
			$put( 'db_tables', (int) $db->n );
		}

		$bytes = get_transient( self::SIZE_CACHE );
		if ( false === $bytes ) {
			$bytes = self::dir_size( wp_upload_dir()['basedir'] );
			set_transient( self::SIZE_CACHE, null === $bytes ? -1 : $bytes, 12 * HOUR_IN_SECONDS );
		}
		$put( 'uploads_mb', $bytes >= 0 ? round( $bytes / 1048576, 1 ) : null );

		$put( 'response_ms', self::response_ms( home_url( '/' ) ) );
		return $rows;
	}

	/**
	 * Attachment counts by MIME type folded into images, videos and other.
	 *
	 * @param array $by_mime mime => count, as wp_count_attachments() returns
	 */
	public static function media_counts( array $by_mime ) {
		$out = array( 'images' => 0, 'videos' => 0, 'other' => 0 );
		foreach ( $by_mime as $mime => $n ) {
			if ( 'trash' === $mime ) {
				continue;
			}
			$key         = 0 === strpos( $mime, 'image/' ) ? 'images' : ( 0 === strpos( $mime, 'video/' ) ? 'videos' : 'other' );
			$out[ $key ] += (int) $n;
		}
		return $out;
	}

	/**
	 * Total bytes under a folder, or null when it is missing or holds more than $max_files
	 * (an unknown size beats a wrong one).
	 */
	public static function dir_size( $dir, $max_files = 500000 ) {
		if ( ! is_dir( $dir ) ) {
			return null;
		}
		$bytes = 0;
		$files = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			if ( ++$files > $max_files ) {
				return null;
			}
			$bytes += $f->getSize();
		}
		return $bytes;
	}

	/**
	 * How long the home page takes to answer this server, as a visitor's cache would serve it.
	 */
	public static function response_ms( $url ) {
		$t = microtime( true );
		$r = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 2, 'sslverify' => false ) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return null;
		}
		return (int) round( ( microtime( true ) - $t ) * 1000 );
	}

	/**
	 * Live facts about the software and server, shown on the Hosting tab; not stored.
	 */
	public static function stack() {
		global $wpdb, $wp_version;
		$crons   = _get_cron_array();
		$overdue = 0;
		$events  = 0;
		foreach ( (array) $crons as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook ) {
				$events += count( (array) $hook );
				if ( $ts < time() - HOUR_IN_SECONDS ) {
					$overdue += count( (array) $hook );
				}
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array(
			'wp'             => $wp_version ? $wp_version : get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'db'             => $wpdb->db_server_info(),
			'theme'          => wp_get_theme()->get( 'Name' ),
			'plugins_active' => count( (array) get_option( 'active_plugins', array() ) ),
			'plugins_total'  => count( get_plugins() ),
			'memory_limit'   => ini_get( 'memory_limit' ),
			'max_upload'     => size_format( wp_max_upload_size() ),
			'cron_events'    => $events,
			'cron_overdue'   => $overdue,
			'https'          => 0 === strpos( home_url(), 'https://' ),
			'debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
		);
	}
}
