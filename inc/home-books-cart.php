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
 * The books and their order come from the "ספרים בדף הבית" screen
 * (sella-home-books.php); with an empty list the slider shows 12 random books.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Author of a book: the "מחבר" field, or the older _author meta.
 *
 * @param int $product_id Product ID.
 * @return string Empty when the book has no author.
 */
function sella_home_book_author( $product_id ) {
	$author_name = get_post_meta( $product_id, 'מחבר', true );
	if ( ! $author_name ) {
		$author_name = get_post_meta( $product_id, '_author', true );
	}
	return (string) $author_name;
}

/**
 * One homepage book card.
 *
 * @param WC_Product $product    Product.
 * @param string     $card_class Classes on the card.
 * @param bool       $lazy       Lazy-load the cover.
 * @param bool       $focusable  False for the hidden copy in the marquee, so
 *                               keyboard users do not tab through every book twice.
 * @return string
 */
function sella_home_book_card_html( $product, $card_class = 'book-card-item static-book-card', $lazy = true, $focusable = true ) {
	$product_id    = $product->get_id();
	$product_title = get_the_title( $product_id );
	$author_name   = sella_home_book_author( $product_id );
	if ( ! $author_name ) {
		$author_name = 'מחבר לא ידוע';
	}

	$product_price   = $product->get_price_html();
	// The back of the book (--bg-img) is barely seen; 'medium' is plenty for it.
	$product_image   = get_the_post_thumbnail_url( $product_id, 'medium' );
	// The front shows up to 265px wide on desktop and 110px on mobile, so the
	// browser picks from every size of the cover: 'medium' alone (about 200px
	// wide) was stretched on desktop and looked pixelated.
	$thumb_id        = (int) get_post_thumbnail_id( $product_id );
	$cover_srcset    = $thumb_id ? wp_get_attachment_image_srcset( $thumb_id, 'medium' ) : '';
	$product_url     = $product->get_permalink();
	$add_to_cart_url = $product->add_to_cart_url();
	$tabindex        = $focusable ? '' : ' tabindex="-1"';

	$bg_img_style = $product_image
		? ' style="--bg-img:url(\'' . esc_url( $product_image ) . '\')"'
		: '';

	return '
			<div class="' . esc_attr( $card_class ) . '">
				<a href="' . esc_url( $product_url ) . '" class="book-link-wrapper"' . $tabindex . '>
					<div class="book-3d-container">
						<div class="book-3d"' . $bg_img_style . '>
							<img src="' . esc_url( $product_image ) . '"' . ( $cover_srcset ? ' srcset="' . esc_attr( $cover_srcset ) . '" sizes="(max-width: 767px) 120px, 270px"' : '' ) . ' alt="' . esc_attr( $product_title ) . '" class="book-cover-img"' . ( $lazy ? ' loading="lazy"' : '' ) . ' decoding="async">
						</div>
					</div>
					<h3 class="book-title">' . esc_html( $product_title ) . '</h3>
					<span class="book-author">' . esc_html( $author_name ) . '</span>
					<div class="book-price">' . $product_price . '</div>
				</a>
				<div class="book-action-area">
					<a href="' . esc_url( $add_to_cart_url ) . '" class="book-add-to-cart-btn" data-product_id="' . absint( $product_id ) . '"' . $tabindex . '>הוספה לסל</a>
				</div>
			</div>';
}

/**
 * Render the homepage books slider with add-to-cart buttons.
 *
 * @return string
 */
function sella_random_books_shortcode() {
	$chosen = function_exists( 'sella_home_books_products' ) ? sella_home_books_products( 'bestsellers' ) : array();

	$products = array();
	if ( $chosen ) {
		$products = $chosen;
	} else {
		$loop = new WP_Query(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 12,
				'orderby'        => 'rand',
				'fields'         => 'ids',
			)
		);
		foreach ( $loop->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$products[] = $product;
			}
		}
	}

	if ( ! $products ) {
		return '';
	}

	$products_html = '';
	foreach ( $products as $product ) {
		$products_html .= sella_home_book_card_html( $product );
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
