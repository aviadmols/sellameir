<?php
/**
 * Homepage books section — add an "add to cart" button to each card.
 *
 * The homepage best-sellers slider is rendered by the WPCode snippet
 * "הצגת 3 ספרים בדף הבית" (shortcode [random_three_books]). The snippet cannot
 * be edited from the theme, so we re-register the shortcode here (on init,
 * late priority — after WPCode registered its version) with the same markup
 * plus a .book-add-to-cart-btn button. The button is handled site-wide by the
 * delegated AJAX click handler in the "סל קניות" snippet.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the homepage books slider with add-to-cart buttons.
 *
 * @return string
 */
function sella_random_books_shortcode() {
	$args = array(
		'post_type'      => 'product',
		'posts_per_page' => 12,
		'orderby'        => 'rand',
	);
	$loop = new WP_Query( $args );

	$products_html = '';
	if ( $loop->have_posts() ) {
		while ( $loop->have_posts() ) {
			$loop->the_post();
			global $product;

			$author_name = get_post_meta( get_the_ID(), 'מחבר', true );
			if ( ! $author_name ) {
				$author_name = get_post_meta( get_the_ID(), '_author', true );
			}
			if ( ! $author_name ) {
				$author_name = 'מחבר לא ידוע';
			}

			$product_id      = $product->get_id();
			$product_title   = get_the_title();
			$product_price   = $product->get_price_html();
			$product_image   = get_the_post_thumbnail_url( get_the_ID(), 'large' );
			$product_url     = get_permalink();
			$add_to_cart_url = $product->add_to_cart_url();

			$bg_img_style = $product_image
				? ' style="--bg-img:url(\'' . esc_url( $product_image ) . '\')"'
				: '';

			$products_html .= '
			<div class="book-card-item static-book-card">
				<a href="' . esc_url( $product_url ) . '" class="book-link-wrapper">
					<div class="book-3d-container">
						<div class="book-3d"' . $bg_img_style . '>
							<img src="' . esc_url( $product_image ) . '" alt="' . esc_attr( $product_title ) . '" class="book-cover-img">
						</div>
					</div>
					<h3 class="book-title">' . esc_html( $product_title ) . '</h3>
					<span class="book-author">' . esc_html( $author_name ) . '</span>
					<div class="book-price">' . $product_price . '</div>
				</a>
				<div class="book-action-area">
					<a href="' . esc_url( $add_to_cart_url ) . '" class="book-add-to-cart-btn" data-product_id="' . absint( $product_id ) . '">הוספה לסל</a>
				</div>
			</div>';
		}
		wp_reset_postdata();
	} else {
		return '';
	}

	return '
	<div class="static-books-section">
		<button type="button" class="books-scroll-arrow arrow-right" aria-label="קודם">
			<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
		</button>
		<div class="static-books-grid">
			' . $products_html . '
		</div>
		<button type="button" class="books-scroll-arrow arrow-left" aria-label="הבא">
			<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
		</button>
	</div>';
}

/**
 * Override the WPCode-registered shortcode with our version.
 * Late priority so it always wins over the snippet's add_shortcode call.
 */
function sella_override_random_books_shortcode() {
	add_shortcode( 'random_three_books', 'sella_random_books_shortcode' );
}
add_action( 'init', 'sella_override_random_books_shortcode', 99 );
