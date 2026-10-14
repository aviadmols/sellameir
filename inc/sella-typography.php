<?php
/**
 * The site's typography: Google Sans everywhere, every font size 1px smaller.
 *
 * - assets/css/sella-typography.css puts Google Sans on every element, over
 *   whatever font Elementor, the plugins or the page CSS set.
 * - assets/js/sella-font-scale.js, printed inline at the end of <head>,
 *   takes 1px off every font-size the stylesheets declare, on desktop and
 *   mobile alike. Google Sans runs larger than polin did.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Fonts URL for Google Sans, which comes in weights 400 to 700; text
 * set lighter or heavier shows at the nearest of those.
 */
define( 'SELLA_GOOGLE_SANS_URL', 'https://fonts.googleapis.com/css2?family=Google+Sans:ital,wght@0,400..700;1,400..700&display=swap' );

/**
 * Whether the page is Elementor's editor preview, where Elementor rewrites
 * its CSS as the page is edited and the sizes should show as set.
 *
 * @return bool
 */
function sella_typography_is_editor_preview() {
	return isset( $_GET['elementor-preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Load Google Sans and the rule that applies it.
 */
function sella_typography_enqueue() {
	wp_enqueue_style( 'sella-google-sans', SELLA_GOOGLE_SANS_URL, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

	$css = get_stylesheet_directory() . '/assets/css/sella-typography.css';
	wp_enqueue_style(
		'sella-typography',
		get_stylesheet_directory_uri() . '/assets/css/sella-typography.css',
		array( 'sella-google-sans' ),
		(string) filemtime( $css )
	);
}
add_action( 'wp_enqueue_scripts', 'sella_typography_enqueue', 30 );

/**
 * Connect to the font file host early, before the Google Fonts CSS names
 * the files.
 *
 * @param array  $urls          Resource hint URLs.
 * @param string $relation_type The hint type.
 * @return array
 */
function sella_typography_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type && ! is_admin() ) {
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'sella_typography_resource_hints', 10, 2 );

/**
 * Elementor's own Google Fonts (Roboto, from the kit's defaults) are no
 * longer shown anywhere, so they are not downloaded either.
 */
add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );

/**
 * Print the 1px font-size script last in <head>, after every stylesheet in
 * it, so it runs once they have loaded and before the page is first drawn.
 */
function sella_typography_print_font_scale() {
	if ( sella_typography_is_editor_preview() ) {
		return;
	}

	$js = file_get_contents( get_stylesheet_directory() . '/assets/js/sella-font-scale.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $js ) {
		return;
	}

	wp_print_inline_script_tag( $js, array( 'id' => 'sella-font-scale' ) );
}
add_action( 'wp_head', 'sella_typography_print_font_scale', PHP_INT_MAX );
