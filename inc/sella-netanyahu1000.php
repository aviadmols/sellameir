<?php
/**
 * The "מהדורת האלף - נתניהו" page template (templates/netanyahu1000.php):
 * its product lookup and its stylesheet and script.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The product the page's buy button adds to the cart.
 *
 * @param int $page_id Page ID.
 * @return WC_Product|null
 */
function sella_n1000_product( $page_id ) {
	$product_id = absint( get_post_meta( $page_id, 'sella_product_id', true ) );

	if ( ! $product_id ) {
		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				's'              => 'מהדורת האלף',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$product_id = $found ? (int) $found[0] : 0;
	}

	$product = $product_id ? wc_get_product( $product_id ) : null;

	return $product ? $product : null;
}

/**
 * Load the template's stylesheet and script on pages that use it.
 */
function sella_n1000_assets() {
	if ( ! is_page_template( 'templates/netanyahu1000.php' ) ) {
		return;
	}

	wp_enqueue_style(
		'sella-netanyahu1000',
		get_stylesheet_directory_uri() . '/assets/css/sella-netanyahu1000.css',
		array( 'hello-elementor-child-style' ),
		HELLO_ELEMENTOR_CHILD_VERSION
	);

	wp_enqueue_script(
		'sella-netanyahu1000',
		get_stylesheet_directory_uri() . '/assets/js/sella-netanyahu1000.js',
		array(),
		HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'sella_n1000_assets', 20 );
