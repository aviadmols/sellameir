<?php
/**
 * Front-end weight trims that do not change how the site looks.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drop WordPress's emoji detection script and styles: every current browser
 * draws emoji itself, and the script runs on every page view.
 */
function sella_disable_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );
}
add_action( 'init', 'sella_disable_emoji' );

/**
 * Show text in a fallback font while the custom fonts (polin) download,
 * instead of leaving it invisible. Elementor Pro prints 'auto' by default.
 *
 * @return string
 */
function sella_custom_fonts_display() {
	return 'swap';
}
add_filter( 'elementor_pro/custom_fonts/font_display', 'sella_custom_fonts_display' );
