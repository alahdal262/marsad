<?php
/**
 * The site's own publishing activity. Needs no setup.
 */

defined( 'ABSPATH' ) || exit;

class Marsad_Connector_Wordpress extends Marsad_Connector {

	public function id() {
		return 'wordpress';
	}

	public function label() {
		return __( 'WordPress', 'marsad' );
	}

	public function group() {
		return 'content';
	}

	public function always_on() {
		return true;
	}

	public function description() {
		return __( 'Posts published, comments and the most active authors on this site.', 'marsad' );
	}

	public function max_backfill() {
		return 365;
	}

	public function full_history() {
		return true;
	}

	/** The day of the first post ever published, deleted ones included. */
	public function history_start() {
		global $wpdb;
		$types = $this->types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$first = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(post_date_gmt) FROM {$wpdb->posts} WHERE post_status IN ('publish','trash') AND post_type IN ($in) AND post_date_gmt > '1971-01-01'", $types ) );
		return $first ? substr( $first, 0, 10 ) : parent::history_start();
	}

	private function types() {
		return (array) apply_filters( 'marsad_wordpress_post_types', array( 'post' ) );
	}

	/**
	 * Count a published (or trashed) post that is deleted for good. WordPress keeps no
	 * record of those, so they can only be counted from the day Marsad is installed.
	 */
	public static function on_delete( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'trash', 'private' ), true ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, (array) apply_filters( 'marsad_wordpress_post_types', array( 'post' ) ), true ) ) {
			return;
		}
		Marsad_Store::increment( 'wordpress', 'posts_deleted', gmdate( 'Y-m-d' ) );
	}

	public function metrics() {
		return array(
			'posts'         => array( 'label' => __( 'Posts published', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'comments'      => array( 'label' => __( 'Approved comments', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'posts_trashed' => array( 'label' => __( 'Posts moved to the trash', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'better' => 'down' ),
			'posts_deleted' => array( 'label' => __( 'Posts deleted permanently', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'better' => 'down' ),
		);
	}

	public function tables() {
		return array(
			'posts_by_author'   => array( 'label' => __( 'Most active authors', 'marsad' ), 'dim_label' => __( 'Author', 'marsad' ), 'agg' => 'sum', 'limit' => 10 ),
			'posts_by_category' => array( 'label' => __( 'Busiest categories', 'marsad' ), 'dim_label' => __( 'Category', 'marsad' ), 'agg' => 'sum', 'limit' => 10 ),
		);
	}

	public function fetch( $from, $to ) {
		global $wpdb;
		$rows  = array();
		$start = $from . ' 00:00:00';
		$end   = $to . ' 23:59:59';

		$types = $this->types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// Posts later moved to the trash were still published on their day.
		$live  = "(post_status='publish' OR (post_status='trash' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} tm WHERE tm.post_id={$wpdb->posts}.ID AND tm.meta_key='_wp_trash_meta_status' AND tm.meta_value='publish')))";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(post_date_gmt) d, COUNT(*) n FROM {$wpdb->posts} WHERE {$live} AND post_type IN ($in) AND post_date_gmt BETWEEN %s AND %s GROUP BY d", array_merge( $types, array( $start, $end ) ) ) );
		foreach ( $posts as $r ) {
			$rows[] = $this->row( 'posts', $r->d, $r->n );
		}

		$trashed = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(FROM_UNIXTIME(m.meta_value)) d, COUNT(*) n FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_wp_trash_meta_time' WHERE p.post_status='trash' AND p.post_type IN ($in) AND m.meta_value BETWEEN %d AND %d GROUP BY d", array_merge( $types, array( strtotime( $start . ' UTC' ), strtotime( $end . ' UTC' ) ) ) ) );
		foreach ( $trashed as $r ) {
			$rows[] = $this->row( 'posts_trashed', $r->d, $r->n );
		}

		$comments = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(comment_date_gmt) d, COUNT(*) n FROM {$wpdb->comments} WHERE comment_approved='1' AND comment_type IN ('','comment') AND comment_date_gmt BETWEEN %s AND %s GROUP BY d", $start, $end ) );
		foreach ( $comments as $r ) {
			$rows[] = $this->row( 'comments', $r->d, $r->n );
		}

		$authors = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(p.post_date_gmt) d, u.display_name a, COUNT(*) n FROM {$wpdb->posts} p JOIN {$wpdb->users} u ON u.ID=p.post_author WHERE " . str_replace( "{$wpdb->posts}.ID", 'p.ID', $live ) . " AND p.post_type IN ($in) AND p.post_date_gmt BETWEEN %s AND %s GROUP BY d, a", array_merge( $types, array( $start, $end ) ) ) );
		foreach ( $authors as $r ) {
			$rows[] = $this->row( 'posts_by_author', $r->d, $r->n, $r->a );
		}

		$cats = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(p.post_date_gmt) d, t.name c, COUNT(*) n FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='category' JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE " . str_replace( "{$wpdb->posts}.ID", 'p.ID', $live ) . " AND p.post_type IN ($in) AND p.post_date_gmt BETWEEN %s AND %s GROUP BY d, c", array_merge( $types, array( $start, $end ) ) ) );
		// phpcs:enable
		foreach ( $cats as $r ) {
			$rows[] = $this->row( 'posts_by_category', $r->d, $r->n, $r->c );
		}
		return $rows;
	}
}
