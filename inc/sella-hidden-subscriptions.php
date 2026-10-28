<?php
/**
 * The subscriptions, out of the shop for now.
 *
 * The Shibolet subscriptions are sold on /subscription/ (the plans table,
 * sella-subscription-plans.php), not as books. Their products are left out of
 * every product list shoppers see: the shop, its categories and search, the
 * shop grid's AJAX filter, the homepage sliders, related books, the Store and
 * REST APIs, the sitemap and the Merchant feed. Their own pages send on to
 * /subscription/. The plans' buttons (add to cart, straight to the checkout)
 * work as before, and the admin sees the products as usual.
 *
 * Which products: those of a subscription type, those a plan sells, and those
 * named for one ("מנוי", "מינוי", "שיבולת פלוס"). To show them again, return
 * false from the sella_hide_subscriptions filter.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_HS_CACHE', 'sella_hidden_subscription_ids' );

/**
 * @return bool
 */
function sella_hs_enabled() {
	return (bool) apply_filters( 'sella_hide_subscriptions', true );
}

/**
 * IDs of the subscription products. Read with $wpdb, not WP_Query, since
 * product queries ask for them.
 *
 * @return int[]
 */
function sella_hs_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$cached = get_transient( SELLA_HS_CACHE );
	if ( is_array( $cached ) ) {
		$ids = array_map( 'intval', $cached );
		return $ids;
	}

	global $wpdb;
	$found = array();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$titles = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft')" );
	foreach ( $titles as $row ) {
		if ( preg_match( '/מנוי|מינוי|שיבולת פלוס/u', $row->post_title ) ) {
			$found[] = $row->ID;
		}
	}

	$typed = $wpdb->get_col(
		"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		WHERE tt.taxonomy = 'product_type' AND t.slug IN ('subscription', 'variable-subscription')"
	);
	// phpcs:enable

	$sold = array();
	if ( function_exists( 'sella_plans_get' ) ) {
		foreach ( sella_plans_get()['plans'] as $plan ) {
			$sold[] = $plan['product_id'];
		}
	}

	$ids = array_values( array_unique( array_filter( array_map( 'intval', array_merge( $found, $typed, $sold ) ) ) ) );
	set_transient( SELLA_HS_CACHE, $ids, DAY_IN_SECONDS );
	return $ids;
}

/**
 * A product saved or a plan changed: work the list out again.
 */
function sella_hs_forget() {
	delete_transient( SELLA_HS_CACHE );
}
add_action( 'save_post_product', 'sella_hs_forget' );
add_action( 'deleted_post', 'sella_hs_forget' );
add_action( 'update_option_sella_subscription_plans', 'sella_hs_forget' );
add_action( 'add_option_sella_subscription_plans', 'sella_hs_forget' );

/**
 * Whether this request is a shopper's. The admin's screens, their AJAX (the
 * product search in the plans table) and the block editor's REST calls see
 * the subscriptions; the site's own AJAX, as the shop grid's, does not.
 *
 * @return bool
 */
function sella_hs_applies() {
	if ( ! sella_hs_enabled() ) {
		return false;
	}
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
 * Leave them out of product lists.
 *
 * @param WP_Query $query Query.
 */
function sella_hs_query( $query ) {
	if ( $query->is_singular() || ! sella_hs_applies() ) {
		return;
	}
	$types = (array) $query->get( 'post_type' );
	if ( ! in_array( 'product', $types, true ) && ! $query->is_tax( get_object_taxonomies( 'product' ) ) ) {
		return;
	}
	$ids = sella_hs_ids();
	if ( $ids ) {
		$query->set( 'post__not_in', array_merge( array_filter( array_map( 'intval', (array) $query->get( 'post__not_in' ) ) ), $ids ) );
	}
}
add_action( 'pre_get_posts', 'sella_hs_query' );

/**
 * Not shown where WooCommerce asks (related books, upsells, cross-sells).
 *
 * @param bool $visible    Visible.
 * @param int  $product_id Product.
 * @return bool
 */
function sella_hs_visible( $visible, $product_id ) {
	return $visible && sella_hs_applies() && in_array( (int) $product_id, sella_hs_ids(), true ) ? false : $visible;
}
add_filter( 'woocommerce_product_is_visible', 'sella_hs_visible', 10, 2 );

/**
 * A subscription's own page sends on to the plans. Temporary (302), since
 * they are hidden for now.
 */
function sella_hs_redirect() {
	if ( ! is_singular( 'product' ) || ! sella_hs_enabled() || current_user_can( 'edit_products' ) ) {
		return;
	}
	if ( in_array( (int) get_queried_object_id(), sella_hs_ids(), true ) ) {
		wp_safe_redirect( home_url( '/subscription/' ), 302 );
		exit;
	}
}
add_action( 'template_redirect', 'sella_hs_redirect', 5 );

/**
 * Not in the sitemap.
 *
 * @param int[] $ids Posts left out.
 * @return int[]
 */
function sella_hs_sitemap( $ids ) {
	return sella_hs_enabled() ? array_merge( (array) $ids, sella_hs_ids() ) : $ids;
}
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', 'sella_hs_sitemap' );
