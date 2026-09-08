<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

require_once get_stylesheet_directory() . '/inc/cursor-db-bridge.php';

/**
 * Book product fields (WooCommerce).
 */
if ( class_exists( 'WooCommerce' ) ) {
	require_once get_stylesheet_directory() . '/inc/product-book-fields.php';
}

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );


add_filter('upload_mimes', function ($mimes) {
    $mimes['json'] = 'application/json';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($extension !== 'json') {
        return $data;
    }

    $content = file_get_contents($file);

    if ($content === false) {
        return $data;
    }

    json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $data;
    }

    return [
        'ext' => 'json',
        'type' => 'application/json',
        'proper_filename' => $filename,
    ];
}, 10, 4);