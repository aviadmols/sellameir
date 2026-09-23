<?php
/**
 * Point every "add to cart" link at the shop page.
 *
 * WooCommerce builds add_to_cart_url() on the current page's address, so a
 * related-products card on a product page links to
 * /product/<slug>/?add-to-cart=911 and a cart-page card to /cart/?add-to-cart=N.
 * The server's nginx rules answer those two with a bare 404 before WordPress
 * runs (the "index.php is not found" lines in the error log), which is what
 * crawlers and shoppers without JavaScript hit. /shop/?add-to-cart=N and
 * /?add-to-cart=N reach WordPress, so links are rebuilt on the shop page.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move an add-to-cart link's query string onto the shop page.
 *
 * @param string     $url     Add-to-cart URL.
 * @param WC_Product $product Product.
 * @return string
 */
function sella_add_to_cart_url_on_shop( $url, $product ) {
	if ( false === strpos( $url, 'add-to-cart=' ) ) {
		return $url; // Out of stock or variable: the link is the product permalink.
	}

	$shop = wc_get_page_permalink( 'shop' );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	// The home page and the shop page already work, and keep the shopper where they are.
	$safe = array( '/', (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), (string) wp_parse_url( $shop, PHP_URL_PATH ) );
	if ( '' !== $path && in_array( trailingslashit( $path ), $safe, true ) ) {
		return $url;
	}

	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	if ( '' === $query ) {
		return $url;
	}

	return $shop . ( false === strpos( $shop, '?' ) ? '?' : '&' ) . $query;
}
add_filter( 'woocommerce_product_add_to_cart_url', 'sella_add_to_cart_url_on_shop', 99, 2 );
