<?php
/**
 * The media hub (/media/): "כל התוכן" lists every post, the newest too.
 *
 * The hub (its shortcode and the sela_meir_hub_filter_grid AJAX action are
 * not in this theme) shows the newest posts in its slider and the list
 * beside it, and leaves them out of the grid below through post__not_in. A
 * post just published was then only in the slider, never in the grid, until
 * seven newer ones pushed it down. The grid's own filters ("מאמרים",
 * "פודקאסטים") already list everything.
 *
 * Here the exclusion is dropped from the grid's query: on the /media/ page
 * and in the AJAX that filters and pages the grid. The grid is told apart
 * from the slider (3 posts) and the side list (4) by its page size; the
 * paging ("טען עוד תכנים") is WordPress's own and works as before.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this request draws the hub's grid.
 *
 * @return bool
 */
function sella_mh_request() {
	if ( wp_doing_ajax() ) {
		return isset( $_POST['action'] ) && 'sela_meir_hub_filter_grid' === $_POST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only, the hub's own public request.
	}
	return ! is_admin() && is_page( (string) apply_filters( 'sella_media_hub_page', 'media' ) );
}

/**
 * Let the grid list the posts the slider shows.
 *
 * @param WP_Query $query Query.
 */
function sella_mh_show_all( $query ) {
	if ( $query->is_main_query() || ! $query->get( 'post__not_in' ) || ! sella_mh_request() ) {
		return;
	}
	$per_page = (int) $query->get( 'posts_per_page' );
	if ( $per_page > 0 && $per_page < 8 ) {
		return; // The slider and the side list keep their own.
	}
	$types = array_filter( (array) $query->get( 'post_type' ) );
	if ( $types && ! array_intersect( $types, array( 'post', 'podcast', 'any' ) ) ) {
		return;
	}
	$query->set( 'post__not_in', array() );
}
add_action( 'pre_get_posts', 'sella_mh_show_all', 99 );
