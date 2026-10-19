<?php
/**
 * Thank-you page: the order table reads like the checkout's order summary.
 *
 * Each line gets the book cover with the quantity on it as a badge, the same
 * markup sella_checkout_product_thumbnail() builds at checkout, so both share the
 * .sella-checkout-product styles in style.css. The totals lose their trailing
 * colons and the payment-method row (the overview above the table already names
 * it), and the order total is marked so the stylesheet can set it apart.
 *
 * The filters are added only while the thank-you page prints its order table and
 * removed right after: WooCommerce runs the same filters for order emails, which
 * can be sent during this very request when the gateway confirms the payment on
 * the redirect back to the site.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the thank-you filters just before the order lines are printed.
 *
 * @return void
 */
function sella_thankyou_start_items() {
	if ( ! is_order_received_page() ) {
		return;
	}

	add_filter( 'woocommerce_order_item_name', 'sella_thankyou_item_name', 20, 2 );
	add_filter( 'woocommerce_order_item_quantity_html', '__return_empty_string', 20 );
}
add_action( 'woocommerce_order_details_before_order_table_items', 'sella_thankyou_start_items' );

/**
 * Swap the line filters for the totals filter once the order lines are printed.
 *
 * @return void
 */
function sella_thankyou_end_items() {
	remove_filter( 'woocommerce_order_item_name', 'sella_thankyou_item_name', 20 );
	remove_filter( 'woocommerce_order_item_quantity_html', '__return_empty_string', 20 );

	if ( is_order_received_page() ) {
		add_filter( 'woocommerce_get_order_item_totals', 'sella_thankyou_order_totals', 20 );
	}
}
add_action( 'woocommerce_order_details_after_order_table_items', 'sella_thankyou_end_items' );

/**
 * Remove the totals filter once the table is done.
 *
 * @return void
 */
function sella_thankyou_end_table() {
	remove_filter( 'woocommerce_get_order_item_totals', 'sella_thankyou_order_totals', 20 );
}
add_action( 'woocommerce_order_details_after_order_table', 'sella_thankyou_end_table' );

/**
 * Put the book cover, with the quantity as a badge, next to the product name.
 *
 * @param string        $name Product name HTML, linked when the product is visible.
 * @param WC_Order_Item $item Order line.
 * @return string
 */
function sella_thankyou_item_name( $name, $item ) {
	if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
		return $name;
	}

	$product = $item->get_product();
	if ( ! $product ) {
		return $name;
	}

	$image    = $product->get_image( array( 72, 92 ), array( 'class' => 'sella-checkout-product-image' ) );
	$quantity = absint( $item->get_quantity() );
	$badge    = $quantity > 0 ? '<span class="sella-checkout-product-qty">' . esc_html( (string) $quantity ) . '</span>' : '';

	return '<span class="sella-checkout-product">'
		. '<span class="sella-checkout-product-media">' . $image . $badge . '</span>'
		. '<span class="sella-checkout-product-name">' . $name . '</span>'
		. '</span>';
}

/**
 * Shape the totals rows like the checkout summary.
 *
 * @param array $totals Rows keyed by total type, each with a label and a value.
 * @return array
 */
function sella_thankyou_order_totals( $totals ) {
	unset( $totals['payment_method'] );

	foreach ( $totals as $key => $total ) {
		$totals[ $key ]['label'] = rtrim( trim( $total['label'] ), ':' );
	}

	if ( isset( $totals['order_total'] ) ) {
		$totals['order_total']['value'] = '<span class="sella-thankyou-total">' . $totals['order_total']['value'] . '</span>';
	}

	return $totals;
}
