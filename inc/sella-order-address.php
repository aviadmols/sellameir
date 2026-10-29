<?php
/**
 * The full street address on orders: building, apartment, floor, entrance.
 *
 * The checkout asks for these as their own fields (billing_building_number,
 * billing_apartment_number, billing_floor, billing_entrance and the shipping
 * twins). WooCommerce keeps such fields as order meta, but the address it
 * prints in the order emails, on the admin order screen, on the thank-you
 * page and in My Account is built from its standard fields only, so they
 * were left out. Here they are folded into that address: the building
 * number after the street, the rest on the second address line.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The extra address parts, in print order, with the word each one gets.
 *
 * @return array Field suffix => label ('' for the building number).
 */
function sella_order_address_parts() {
	return array(
		'building_number'  => '',
		'apartment_number' => 'דירה',
		'floor'            => 'קומה',
		'entrance'         => 'כניסה',
	);
}

/**
 * One extra part of an order's address.
 *
 * WooCommerce saves an unknown billing_/shipping_ checkout field as meta
 * with a leading underscore; the key without it is read too, in case the
 * code that added the fields saves them that way.
 *
 * @param WC_Order $order Order.
 * @param string   $type  'billing' or 'shipping'.
 * @param string   $part  Field suffix.
 * @return string
 */
function sella_order_address_part( $order, $type, $part ) {
	$key   = $type . '_' . $part;
	$value = $order->get_meta( '_' . $key );
	if ( '' === $value || null === $value ) {
		$value = $order->get_meta( $key );
	}
	return trim( wp_strip_all_tags( (string) $value ) );
}

/**
 * Add the extra parts to a formatted address.
 *
 * A shipping address with no parts of its own borrows the billing ones when
 * it is the same street, which is what the checkout does when the order is
 * not shipped elsewhere.
 *
 * @param array    $address Address fields WooCommerce is about to format.
 * @param WC_Order $order   Order.
 * @param string   $type    'billing' or 'shipping'.
 * @return array
 */
function sella_order_address_complete( $address, $order, $type ) {
	if ( ! is_array( $address ) || ! $order instanceof WC_Order ) {
		return $address;
	}

	$values = array();
	foreach ( array_keys( sella_order_address_parts() ) as $part ) {
		$values[ $part ] = sella_order_address_part( $order, $type, $part );
	}

	if ( 'shipping' === $type && '' === implode( '', $values ) && $order->get_shipping_address_1() === $order->get_billing_address_1() ) {
		foreach ( array_keys( $values ) as $part ) {
			$values[ $part ] = sella_order_address_part( $order, 'billing', $part );
		}
	}

	if ( '' === implode( '', $values ) ) {
		return $address;
	}

	$street   = isset( $address['address_1'] ) ? trim( (string) $address['address_1'] ) : '';
	$building = $values['building_number'];
	// Skip it when the street line already ends with the number.
	if ( '' !== $building && ! preg_match( '/(^|\s)' . preg_quote( $building, '/' ) . '$/u', $street ) ) {
		$address['address_1'] = trim( $street . ' ' . $building );
	}

	$more = array();
	foreach ( sella_order_address_parts() as $part => $label ) {
		if ( '' !== $label && '' !== $values[ $part ] ) {
			$more[] = $label . ' ' . $values[ $part ];
		}
	}
	if ( $more ) {
		$line2               = isset( $address['address_2'] ) ? trim( (string) $address['address_2'] ) : '';
		$address['address_2'] = implode( ', ', array_filter( array( $line2, implode( ', ', $more ) ) ) );
	}

	return $address;
}

/**
 * Billing address, as printed.
 *
 * @param array    $address Address fields.
 * @param WC_Order $order   Order.
 * @return array
 */
function sella_order_billing_address_complete( $address, $order ) {
	return sella_order_address_complete( $address, $order, 'billing' );
}
add_filter( 'woocommerce_order_formatted_billing_address', 'sella_order_billing_address_complete', 20, 2 );

/**
 * Shipping address, as printed.
 *
 * @param array    $address Address fields.
 * @param WC_Order $order   Order.
 * @return array
 */
function sella_order_shipping_address_complete( $address, $order ) {
	return sella_order_address_complete( $address, $order, 'shipping' );
}
add_filter( 'woocommerce_order_formatted_shipping_address', 'sella_order_shipping_address_complete', 20, 2 );
