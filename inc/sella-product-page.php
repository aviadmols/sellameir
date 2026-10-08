<?php
/**
 * Book page text the Elementor template does not let us change per book.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "מעניין לקרוא גם" over the related books, without the name of the book on
 * the page: a long name made the heading run over two or three lines.
 *
 * On the document as it is printed, since Elementor's element cache keeps the
 * widget's own output.
 *
 * @param string $content Document HTML.
 * @return string
 */
function sella_product_related_heading( $content ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() || false === strpos( $content, 'elementor-element-eb0841b' ) ) {
		return $content;
	}

	return preg_replace(
		'#(<div[^>]*\belementor-element-eb0841b\b.*?)מעניין לקרוא ביחד עם.*?(</p>|</div>)#su',
		'$1מעניין לקרוא גם$2',
		$content,
		1
	);
}
add_filter( 'elementor/frontend/the_content', 'sella_product_related_heading' );
