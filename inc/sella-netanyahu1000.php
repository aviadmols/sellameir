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
 * The template's photos, shipped in assets/images/netanyahu1000/. The WSJ
 * logo is an SVG, which the media library refuses, so it stays a theme file.
 *
 * @return string[]
 */
function sella_n1000_media_files() {
	return array( 'bibi-1000-book.png', 'map-bg.jpg', 'book-1000-1.jpg', 'book-1000-2.jpg', 'book-1000-3.jpg', 'book-1000-5.jpg', 'book-1000-6.jpg' );
}

/**
 * Copy the photos into the media library, once, the first time an admin
 * opens the dashboard. The attachment IDs are kept in an option, file name
 * => ID; a file that fails is retried on the next dashboard visit.
 */
function sella_n1000_import_media() {
	if ( ! current_user_can( 'upload_files' ) || wp_doing_ajax() ) {
		return;
	}

	$ids     = (array) get_option( 'sella_n1000_media', array() );
	$missing = array_diff( sella_n1000_media_files(), array_keys( array_filter( $ids, 'wp_attachment_is_image' ) ) );
	if ( ! $missing ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$dir = get_stylesheet_directory() . '/assets/images/netanyahu1000/';
	foreach ( $missing as $file ) {
		$bits = is_readable( $dir . $file ) ? file_get_contents( $dir . $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bits ) {
			continue;
		}

		$upload = wp_upload_bits( 'netanyahu1000-' . $file, null, $bits );
		if ( ! empty( $upload['error'] ) ) {
			continue;
		}

		$id = wp_insert_attachment(
			array(
				'post_title'     => 'מהדורת האלף - ' . pathinfo( $file, PATHINFO_FILENAME ),
				'post_mime_type' => wp_check_filetype( $upload['file'] )['type'],
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			continue;
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		$ids[ $file ] = $id;
		update_option( 'sella_n1000_media', $ids, false );
	}
}
add_action( 'admin_init', 'sella_n1000_import_media' );

/**
 * A photo's media-library attachment ID, or 0 before the import ran.
 *
 * @param string $file File name in assets/images/netanyahu1000/.
 * @return int
 */
function sella_n1000_media_id( $file ) {
	$ids = (array) get_option( 'sella_n1000_media', array() );
	$id  = isset( $ids[ $file ] ) ? (int) $ids[ $file ] : 0;

	return $id && wp_attachment_is_image( $id ) ? $id : 0;
}

/**
 * src, srcset and sizes attributes for a photo: the media-library copy when
 * there is one, the theme file otherwise.
 *
 * @param string $file  File name in assets/images/netanyahu1000/.
 * @param string $sizes The sizes attribute.
 * @return string Escaped attributes.
 */
function sella_n1000_img_attrs( $file, $sizes ) {
	$id = sella_n1000_media_id( $file );
	if ( ! $id ) {
		return 'src="' . esc_url( get_stylesheet_directory_uri() . '/assets/images/netanyahu1000/' . $file ) . '"';
	}

	$attrs  = 'src="' . esc_url( wp_get_attachment_image_url( $id, 'full' ) ) . '"';
	$srcset = wp_get_attachment_image_srcset( $id, 'full' );
	if ( $srcset ) {
		$attrs .= ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( $sizes ) . '"';
	}

	return $attrs;
}

/**
 * A photo's full-size URL, for the lightbox.
 *
 * @param string $file File name in assets/images/netanyahu1000/.
 * @return string
 */
function sella_n1000_img_url( $file ) {
	$id = sella_n1000_media_id( $file );

	return $id ? wp_get_attachment_image_url( $id, 'full' ) : get_stylesheet_directory_uri() . '/assets/images/netanyahu1000/' . $file;
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
