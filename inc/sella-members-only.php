<?php
/**
 * Books for subscribers only: not for sale in the shop.
 *
 * A checkbox on the product's General tab ("למנויים בלבד") marks a book that
 * comes with the Shibolet subscription instead of being sold. In the shop,
 * the categories, the homepage sliders and the related books the card keeps
 * its cover, name and author, but shows no price, wears a "למנויים בלבד"
 * label on the cover (through the badges module, product-badges.php) and
 * its button reads "להצטרפות" and leads to /subscription/. The book's own
 * page sends there too, and the book cannot be added to a cart by any route.
 *
 * Editors (edit_products) still open the product page, to check it.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_MEMBERS_ONLY_META', '_sella_members_only' );
define( 'SELLA_MEMBERS_ONLY_LABEL', 'למנויים בלבד' );
define( 'SELLA_MEMBERS_ONLY_BUTTON', 'להצטרפות' );
define( 'SELLA_MEMBERS_ONLY_CACHE', 'sella_members_only_ids' );

/**
 * Where the button and the book's page lead.
 *
 * @return string
 */
function sella_members_only_url() {
	return (string) apply_filters( 'sella_members_only_url', home_url( '/subscription/' ) );
}

/**
 * Whether a product is for subscribers only.
 *
 * @param WC_Product|int $product Product or ID.
 * @return bool
 */
function sella_is_members_only( $product ) {
	$product_id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
	if ( ! $product_id ) {
		return false;
	}
	return 'yes' === get_post_meta( $product_id, SELLA_MEMBERS_ONLY_META, true );
}

/**
 * The shoppers' side: the shop, the AJAX it uses, and the public APIs.
 * The admin keeps the real price and button, and an editor keeps them in
 * the REST API (the product editor reads from there).
 *
 * @return bool
 */
function sella_members_only_applies() {
	if ( wp_doing_ajax() ) {
		return false === strpos( (string) wp_get_referer(), '/wp-admin/' );
	}
	if ( is_admin() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return ! current_user_can( 'edit_products' );
	}
	return true;
}

/**
 * The card button, "להצטרפות": a link to the subscription page. No
 * add-to-cart classes, so neither WooCommerce nor the tracking plugin treat
 * the click as a purchase.
 *
 * @param WC_Product $product Product.
 * @param string     $attrs   Extra attributes, already escaped.
 * @return string
 */
function sella_members_only_button_html( $product, $attrs = '' ) {
	return sprintf(
		'<a href="%s" class="sella-members-btn" data-product_id="%d" aria-label="%s"%s>%s</a>',
		esc_url( sella_members_only_url() ),
		$product->get_id(),
		esc_attr( SELLA_MEMBERS_ONLY_BUTTON . ' למנויים: ' . $product->get_name() . ' ' . SELLA_MEMBERS_ONLY_LABEL ),
		$attrs ? ' ' . $attrs : '',
		esc_html( SELLA_MEMBERS_ONLY_BUTTON )
	);
}

/**
 * The label on the cover, in the badges module's shape.
 *
 * @return array{text:string,color:string,text_color:string}
 */
function sella_members_only_badge() {
	return array(
		'text'       => SELLA_MEMBERS_ONLY_LABEL,
		'color'      => '#746FED', // The site's accent: seen on dark covers too.
		'text_color' => '#ffffff',
	);
}

/**
 * "למנויים בלבד" first among a flagged book's badges.
 *
 * @param array $badges     Badges.
 * @param int   $product_id Product ID.
 * @return array
 */
function sella_members_only_product_badges( $badges, $product_id ) {
	if ( sella_members_only_applies() && sella_is_members_only( $product_id ) ) {
		array_unshift( $badges, sella_members_only_badge() );
	}
	return $badges;
}
add_filter( 'sella_badge_product_badges', 'sella_members_only_product_badges', 10, 2 );

/**
 * The same, in the map the cards get from the browser.
 *
 * @param array $map product_id => badges.
 * @return array
 */
function sella_members_only_product_map( $map ) {
	if ( ! sella_members_only_applies() ) {
		return $map;
	}
	foreach ( sella_members_only_ids() as $product_id ) {
		$key = (string) $product_id;
		if ( ! isset( $map[ $key ] ) ) {
			$map[ $key ] = array();
		}
		array_unshift( $map[ $key ], sella_members_only_badge() );
	}
	return $map;
}
add_filter( 'sella_badge_product_map', 'sella_members_only_product_map' );

/*
 * ------------------------------------------------------------------
 * Product editor
 * ------------------------------------------------------------------
 */

/**
 * Checkbox on the product's General tab.
 */
function sella_members_only_field() {
	woocommerce_wp_checkbox(
		array(
			'id'          => SELLA_MEMBERS_ONLY_META,
			'label'       => SELLA_MEMBERS_ONLY_LABEL,
			'description' => 'לא למכירה בחנות: בלי מחיר, תגית "למנויים בלבד" על הכריכה, הכפתור "להצטרפות" ולחיצה על הספר מובילה לדף המנויים',
		)
	);
}
add_action( 'woocommerce_product_options_general_product_data', 'sella_members_only_field' );

/**
 * Save the checkbox.
 *
 * @param WC_Product $product Product being saved.
 */
function sella_members_only_save( $product ) {
	$product->update_meta_data( SELLA_MEMBERS_ONLY_META, isset( $_POST[ SELLA_MEMBERS_ONLY_META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the product form nonce.
}
add_action( 'woocommerce_admin_process_product_object', 'sella_members_only_save' );

/**
 * Products list: "למנויים בלבד" next to the name.
 *
 * @param string[] $states States.
 * @param WP_Post  $post   Post.
 * @return string[]
 */
function sella_members_only_post_state( $states, $post ) {
	if ( 'product' === $post->post_type && sella_is_members_only( $post->ID ) ) {
		$states['sella_members_only'] = SELLA_MEMBERS_ONLY_LABEL;
	}
	return $states;
}
add_filter( 'display_post_states', 'sella_members_only_post_state', 10, 2 );

/**
 * The flagged IDs, for the sitemap. Rebuilt after any product is saved.
 *
 * @return int[]
 */
function sella_members_only_ids() {
	$cached = get_transient( SELLA_MEMBERS_ONLY_CACHE );
	if ( is_array( $cached ) ) {
		return array_map( 'intval', $cached );
	}

	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'yes'", SELLA_MEMBERS_ONLY_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ids = array_map( 'intval', (array) $ids );
	set_transient( SELLA_MEMBERS_ONLY_CACHE, $ids, DAY_IN_SECONDS );
	return $ids;
}

/**
 * Forget the cached IDs.
 */
function sella_members_only_forget() {
	delete_transient( SELLA_MEMBERS_ONLY_CACHE );
}
add_action( 'save_post_product', 'sella_members_only_forget' );
add_action( 'deleted_post', 'sella_members_only_forget' );

/*
 * ------------------------------------------------------------------
 * The shop
 * ------------------------------------------------------------------
 */

/**
 * Cannot be bought: no add to cart by link, AJAX, the Store API or the
 * cart drawer.
 *
 * @param bool       $purchasable Purchasable.
 * @param WC_Product $product     Product.
 * @return bool
 */
function sella_members_only_not_purchasable( $purchasable, $product ) {
	return $purchasable && sella_members_only_applies() && sella_is_members_only( $product ) ? false : $purchasable;
}
add_filter( 'woocommerce_is_purchasable', 'sella_members_only_not_purchasable', 20, 2 );

/**
 * No price on the cards: the book is not for sale.
 *
 * @param string     $html    Price HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function sella_members_only_price_html( $html, $product ) {
	return sella_members_only_applies() && sella_is_members_only( $product ) ? '' : $html;
}
add_filter( 'woocommerce_get_price_html', 'sella_members_only_price_html', 99, 2 );

/**
 * The card button in every WooCommerce loop (the shop grid, the categories,
 * the related books): "להצטרפות", to the subscription page.
 *
 * @param string     $link    Button HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function sella_members_only_loop_button( $link, $product ) {
	if ( ! $product instanceof WC_Product || ! sella_members_only_applies() || ! sella_is_members_only( $product ) ) {
		return $link;
	}
	return sella_members_only_button_html( $product );
}
add_filter( 'woocommerce_loop_add_to_cart_link', 'sella_members_only_loop_button', 99, 2 );

/**
 * The card itself links to the subscription page where WooCommerce prints
 * the link.
 *
 * @param string     $url     Permalink.
 * @param WC_Product $product Product.
 * @return string
 */
function sella_members_only_loop_link( $url, $product ) {
	return $product instanceof WC_Product && sella_members_only_applies() && sella_is_members_only( $product ) ? sella_members_only_url() : $url;
}
add_filter( 'woocommerce_loop_product_link', 'sella_members_only_loop_link', 20, 2 );

/**
 * The book's own page sends on to the subscription page. Temporary (302):
 * the book may go on sale later.
 */
function sella_members_only_redirect() {
	if ( ! is_singular( 'product' ) || current_user_can( 'edit_products' ) ) {
		return;
	}
	if ( sella_is_members_only( get_queried_object_id() ) ) {
		wp_safe_redirect( sella_members_only_url(), 302 );
		exit;
	}
}
add_action( 'template_redirect', 'sella_members_only_redirect', 5 );

/**
 * A page that only redirects has no place in the sitemap.
 *
 * @param int[] $ids Posts left out.
 * @return int[]
 */
function sella_members_only_sitemap( $ids ) {
	return array_merge( (array) $ids, sella_members_only_ids() );
}
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', 'sella_members_only_sitemap' );
