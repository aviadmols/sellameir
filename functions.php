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

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.2.58' );

require_once get_stylesheet_directory() . '/inc/cursor-db-bridge.php';
require_once get_stylesheet_directory() . '/inc/sella-performance.php';
require_once get_stylesheet_directory() . '/inc/sella-page-cache.php';
require_once get_stylesheet_directory() . '/inc/sella-security.php';
require_once get_stylesheet_directory() . '/inc/sella-delay-tracking.php';
require_once get_stylesheet_directory() . '/inc/sella-typography.php';
require_once get_stylesheet_directory() . '/inc/sella-legacy-redirects.php';
require_once get_stylesheet_directory() . '/inc/sella-footer-links.php';
require_once get_stylesheet_directory() . '/inc/sella-legal-pages.php';

/**
 * WooCommerce product modules.
 */
function sella_load_woocommerce_modules() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once get_stylesheet_directory() . '/inc/product-book-fields.php';
	require_once get_stylesheet_directory() . '/inc/product-badges.php';
	require_once get_stylesheet_directory() . '/inc/home-books-cart.php';
	require_once get_stylesheet_directory() . '/inc/sella-home-books.php';
	require_once get_stylesheet_directory() . '/inc/sella-upsell-popups.php';
	require_once get_stylesheet_directory() . '/inc/sella-back-in-stock.php';
	require_once get_stylesheet_directory() . '/inc/sella-shop-banners.php';
	require_once get_stylesheet_directory() . '/inc/sella-unlimited-stock.php';
	require_once get_stylesheet_directory() . '/inc/sella-customer-fields.php';
	require_once get_stylesheet_directory() . '/inc/sella-order-address.php';
	require_once get_stylesheet_directory() . '/inc/sella-checkout-offers.php';
	require_once get_stylesheet_directory() . '/inc/sella-thankyou.php';
	require_once get_stylesheet_directory() . '/inc/sella-free-shipping.php';
	require_once get_stylesheet_directory() . '/inc/sella-add-to-cart-links.php';
	require_once get_stylesheet_directory() . '/inc/sella-cart-drawer.php';
	require_once get_stylesheet_directory() . '/inc/sella-header-account.php';
	require_once get_stylesheet_directory() . '/inc/sella-abandoned-cart.php';
	require_once get_stylesheet_directory() . '/inc/sella-email-gate.php';
	require_once get_stylesheet_directory() . '/inc/sella-ai-consult.php';
	require_once get_stylesheet_directory() . '/inc/sella-product-page.php';
	require_once get_stylesheet_directory() . '/inc/sella-netanyahu1000.php';
	require_once get_stylesheet_directory() . '/inc/sella-subscription-plans.php';
	require_once get_stylesheet_directory() . '/inc/sella-leads.php';
	require_once get_stylesheet_directory() . '/inc/sella-first-chapter.php';
	require_once get_stylesheet_directory() . '/inc/sella-activetrail.php';
	require_once get_stylesheet_directory() . '/inc/sella-seo.php';
	require_once get_stylesheet_directory() . '/inc/sella-shopify-import.php';
	require_once get_stylesheet_directory() . '/inc/sella-shopify-app.php';
	require_once get_stylesheet_directory() . '/inc/sella-merchant-feed.php';
	require_once get_stylesheet_directory() . '/inc/sella-hidden-categories.php';
	require_once get_stylesheet_directory() . '/inc/sella-shop-sort.php';
	require_once get_stylesheet_directory() . '/inc/sella-hidden-subscriptions.php';
	require_once get_stylesheet_directory() . '/inc/sella-promo-five-books.php';
	require_once get_stylesheet_directory() . '/inc/sella-members-only.php';
}
add_action( 'after_setup_theme', 'sella_load_woocommerce_modules', 20 );

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

	wp_enqueue_script(
		'sella-mobile-cart-count',
		get_stylesheet_directory_uri() . '/assets/js/mobile-cart-count.js',
		[],
		HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);

	wp_enqueue_script(
		'sella-store-notifications',
		get_stylesheet_directory_uri() . '/assets/js/sella-store-notifications.js',
		[],
		HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);

	if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
		wp_enqueue_script(
			'sella-shop-search',
			get_stylesheet_directory_uri() . '/assets/js/sella-shop-search.js',
			[],
			HELLO_ELEMENTOR_CHILD_VERSION,
			true
		);
	}

	if ( function_exists( 'is_product' ) && is_product() ) {
		wp_enqueue_script(
			'sella-sticky-product-cart',
			get_stylesheet_directory_uri() . '/assets/js/sticky-product-cart.js',
			[],
			HELLO_ELEMENTOR_CHILD_VERSION,
			true
		);
	}

	// The cart block's labels, before the block renders them: in the head,
	// since the block's own script is deferred and would run first otherwise.
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		wp_enqueue_script(
			'sella-cart-page',
			get_stylesheet_directory_uri() . '/assets/js/sella-cart-page.js',
			[ 'wp-hooks', 'wp-i18n' ],
			HELLO_ELEMENTOR_CHILD_VERSION,
			false
		);
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
		wp_enqueue_script(
			'sella-checkout-fields',
			get_stylesheet_directory_uri() . '/assets/js/sella-checkout-fields.js',
			[ 'jquery' ],
			HELLO_ELEMENTOR_CHILD_VERSION,
			true
		);
	}

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

/**
 * Add a product thumbnail to checkout order-review rows.
 *
 * @param string $product_name Product name HTML.
 * @param array  $cart_item Cart item data.
 * @return string
 */
function sella_checkout_product_thumbnail( $product_name, $cart_item ) {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
		return $product_name;
	}

	$image    = $cart_item['data']->get_image( array( 72, 92 ), array( 'class' => 'sella-checkout-product-image' ) );
	$quantity = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0;
	$badge    = $quantity > 0 ? '<span class="sella-checkout-product-qty">' . esc_html( (string) $quantity ) . '</span>' : '';

	return '<span class="sella-checkout-product">'
		. '<span class="sella-checkout-product-media">' . $image . $badge . '</span>'
		. '<span class="sella-checkout-product-name">' . $product_name . '</span>'
		. '</span>';
}
add_filter( 'woocommerce_cart_item_name', 'sella_checkout_product_thumbnail', 20, 2 );

/**
 * Drop the "× 2" line under the product name — the badge on the cover says it.
 *
 * @return string
 */
function sella_checkout_hide_inline_quantity() {
	return '';
}
add_filter( 'woocommerce_checkout_cart_item_quantity', 'sella_checkout_hide_inline_quantity', 20 );

/**
 * List the credit-card gateway (PayPlus) before PayPal. The first gateway is
 * also the one checkout selects when the shopper has not picked one yet.
 *
 * @param array $gateways Available gateways, keyed by ID.
 * @return array
 */
function sella_credit_card_gateway_first( $gateways ) {
	if ( ! is_array( $gateways ) || ! isset( $gateways['lets_payplus'] ) ) {
		return $gateways;
	}

	return array( 'lets_payplus' => $gateways['lets_payplus'] ) + $gateways;
}
add_filter( 'woocommerce_available_payment_gateways', 'sella_credit_card_gateway_first', 100 );


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