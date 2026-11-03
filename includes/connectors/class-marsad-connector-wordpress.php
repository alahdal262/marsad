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

	public function metrics() {
		return array(
			'posts'    => array( 'label' => __( 'Posts published', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
			'comments' => array( 'label' => __( 'Approved comments', 'marsad' ), 'agg' => 'sum', 'kpi' => true, 'chart' => true ),
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

		$types = apply_filters( 'marsad_wordpress_post_types', array( 'post' ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(post_date_gmt) d, COUNT(*) n FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($in) AND post_date_gmt BETWEEN %s AND %s GROUP BY d", array_merge( $types, array( $start, $end ) ) ) );
		foreach ( $posts as $r ) {
			$rows[] = $this->row( 'posts', $r->d, $r->n );
		}

		$comments = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(comment_date_gmt) d, COUNT(*) n FROM {$wpdb->comments} WHERE comment_approved='1' AND comment_type IN ('','comment') AND comment_date_gmt BETWEEN %s AND %s GROUP BY d", $start, $end ) );
		foreach ( $comments as $r ) {
			$rows[] = $this->row( 'comments', $r->d, $r->n );
		}

		$authors = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(p.post_date_gmt) d, u.display_name a, COUNT(*) n FROM {$wpdb->posts} p JOIN {$wpdb->users} u ON u.ID=p.post_author WHERE p.post_status='publish' AND p.post_type IN ($in) AND p.post_date_gmt BETWEEN %s AND %s GROUP BY d, a", array_merge( $types, array( $start, $end ) ) ) );
		foreach ( $authors as $r ) {
			$rows[] = $this->row( 'posts_by_author', $r->d, $r->n, $r->a );
		}

		$cats = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(p.post_date_gmt) d, t.name c, COUNT(*) n FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='category' JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE p.post_status='publish' AND p.post_type IN ($in) AND p.post_date_gmt BETWEEN %s AND %s GROUP BY d, c", array_merge( $types, array( $start, $end ) ) ) );
		// phpcs:enable
		foreach ( $cats as $r ) {
			$rows[] = $this->row( 'posts_by_category', $r->d, $r->n, $r->c );
		}
		return $rows;
	}
}
