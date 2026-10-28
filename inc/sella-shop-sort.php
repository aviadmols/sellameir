<?php
/**
 * Shop sorting: newest, oldest, א–ת, ת–א.
 *
 * The shop grid (#sibolet-archive-grid) comes from the "sibolet" WPCode
 * snippet, which prints every book at once and swaps the grid over AJAX when a
 * category is picked. assets/js/sella-shop-sort.js adds the control to the
 * filter bar and orders whatever the grid holds, again after every swap, so the
 * sorting works inside each category too. The date order is printed here: the
 * published books, newest first, by their publish date.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shop and its category pages.
 *
 * @return bool
 */
function sella_shop_sort_applies() {
	return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
}

/**
 * Published product IDs, newest first.
 *
 * @return int[]
 */
function sella_shop_sort_newest_ids() {
	global $wpdb;

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT ID FROM {$wpdb->posts}
		WHERE post_type = 'product' AND post_status = 'publish'
		ORDER BY post_date DESC, ID DESC"
	);

	return array_map( 'intval', $ids );
}

/**
 * The script, with the date order.
 */
function sella_shop_sort_assets() {
	if ( ! sella_shop_sort_applies() ) {
		return;
	}

	wp_enqueue_script(
		'sella-shop-sort',
		get_stylesheet_directory_uri() . '/assets/js/sella-shop-sort.js',
		array(),
		HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);
	wp_localize_script( 'sella-shop-sort', 'sellaShopSort', array( 'newest' => sella_shop_sort_newest_ids() ) );
}
add_action( 'wp_enqueue_scripts', 'sella_shop_sort_assets', 30 );

/**
 * Lets style.css hold the grid back until the script has ordered it.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function sella_shop_sort_body_class( $classes ) {
	if ( sella_shop_sort_applies() ) {
		$classes[] = 'sella-shop-sortable';
	}
	return $classes;
}
add_filter( 'body_class', 'sella_shop_sort_body_class' );
