<?php
/**
 * Free shipping wins: when a free-shipping rate applies, it is the only rate offered.
 *
 * WooCommerce otherwise lists the paid flat rate next to the free one, with the
 * paid rate preselected, and asks the customer to choose. With a single rate left
 * WooCommerce prints it as plain text instead of a radio group, on the cart page
 * and at checkout alike. The filter runs after WooCommerce's rate cache, so it
 * applies on every refresh.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep only the free-shipping rates of a package when it has at least one.
 *
 * @param WC_Shipping_Rate[] $rates   Rates available for the package, keyed by rate ID.
 * @param array              $package The shipping package.
 * @return WC_Shipping_Rate[]
 */
function sella_only_free_shipping_rates( $rates, $package ) {
	$free = array();

	foreach ( $rates as $rate_id => $rate ) {
		if ( 'free_shipping' === $rate->get_method_id() ) {
			$free[ $rate_id ] = $rate;
		}
	}

	return empty( $free ) ? $rates : $free;
}
add_filter( 'woocommerce_package_rates', 'sella_only_free_shipping_rates', 100, 2 );
