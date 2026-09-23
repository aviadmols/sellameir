<?php
/**
 * No cart page: the Smart Cart drawer is the cart.
 *
 * Links and buttons to the cart already open the drawer (Smart Cart catches
 * clicks on any "cart" link). What still reaches /cart/ is a typed address or a
 * redirect: an empty checkout, a cancelled payment, a non-JS add to cart. Those
 * go back to the page the shopper came from, or to the shop, and the drawer
 * opens there. With an empty cart there is nothing to show, so the shop opens
 * without it.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send visits to the cart page elsewhere.
 */
function sella_cart_page_redirect() {
	if ( ! is_cart() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || isset( $_GET['elementor-preview'] ) || is_customize_preview() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$shop = wc_get_page_permalink( 'shop' );

	if ( ! WC()->cart || WC()->cart->is_empty() ) {
		wp_safe_redirect( $shop );
		exit;
	}

	$target  = $shop;
	$referer = wp_get_referer();
	if ( $referer ) {
		$path = trailingslashit( (string) wp_parse_url( $referer, PHP_URL_PATH ) );
		$skip = array(
			trailingslashit( (string) wp_parse_url( wc_get_cart_url(), PHP_URL_PATH ) ),
			trailingslashit( (string) wp_parse_url( wc_get_checkout_url(), PHP_URL_PATH ) ),
		);
		if ( ! in_array( $path, $skip, true ) ) {
			$target = strtok( $referer, '#' );
		}
	}

	wp_safe_redirect( $target . '#sella-cart' );
	exit;
}
add_action( 'template_redirect', 'sella_cart_page_redirect', 5 );

/**
 * Open the drawer when the page was reached from the cart page.
 */
function sella_cart_drawer_open_script() {
	if ( ! wp_script_is( 'smart-cart', 'registered' ) ) {
		return;
	}

	$script = <<<'JS'
(function () {
  if (window.location.hash !== '#sella-cart') {
    return;
  }
  if (window.history && window.history.replaceState) {
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
  }
  var tries = 0;
  (function open() {
    if (window.SmartCartAPI && window.SmartCartAPI.open) {
      window.SmartCartAPI.open();
    } else if (tries++ < 50) {
      window.setTimeout(open, 100);
    }
  })();
})();
JS;

	wp_add_inline_script( 'smart-cart', $script );
}
add_action( 'wp_enqueue_scripts', 'sella_cart_drawer_open_script', 100 );
