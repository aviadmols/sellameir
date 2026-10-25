<?php
/**
 * Hidden product categories: kept for the admin only.
 *
 * A "קטגוריה נסתרת" checkbox on the product category form. A hidden category
 * keeps working everywhere a rule or a report looks at it (coupons, checkout
 * offers, has_term(), the product's own categories), but shoppers never see
 * it: it is left out of category lists and the shop filter buttons, of the
 * category links on product pages, of breadcrumbs, of the sitemap and the
 * Merchant feed, and its archive page is a 404 for anyone who cannot edit
 * products. The products in it are shown as usual.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_HC_OPTION', 'sella_hidden_product_cats' );

/**
 * IDs of the hidden categories.
 *
 * @return int[]
 */
function sella_hc_ids() {
	$ids = get_option( SELLA_HC_OPTION, array() );
	return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Whether this request is for someone working in the admin, who sees hidden
 * categories: admin screens, and AJAX or REST calls from people who can edit
 * products.
 *
 * @return bool
 */
function sella_hc_admin_view() {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return true;
	}
	$api = wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	return $api && current_user_can( 'edit_products' );
}

/*
 * ------------------------------------------------------------------
 * The checkbox
 * ------------------------------------------------------------------
 */

/**
 * On the "add category" form.
 */
function sella_hc_add_field() {
	?>
	<div class="form-field term-sella-hidden-wrap">
		<label><input type="checkbox" name="sella_hidden_cat" value="1"> קטגוריה נסתרת</label>
		<p>מוצגת רק בניהול האתר. באתר עצמו לא רואים אותה בשום מקום, אבל המוצרים שבה מוצגים כרגיל.</p>
	</div>
	<?php
}
add_action( 'product_cat_add_form_fields', 'sella_hc_add_field', 20 );

/**
 * On the "edit category" form.
 *
 * @param WP_Term $term Category.
 */
function sella_hc_edit_field( $term ) {
	?>
	<tr class="form-field term-sella-hidden-wrap">
		<th scope="row">קטגוריה נסתרת</th>
		<td>
			<label><input type="checkbox" name="sella_hidden_cat" value="1" <?php checked( in_array( (int) $term->term_id, sella_hc_ids(), true ) ); ?>> להציג רק בניהול האתר</label>
			<p class="description">באתר עצמו לא רואים את הקטגוריה בשום מקום: לא בכפתורי הסינון בחנות, לא בעמוד המוצר, לא בפירורי הלחם ולא בגוגל. העמוד שלה מחזיר 404 למבקרים. המוצרים שבה מוצגים כרגיל, וחוקים שמשתמשים בה (קופונים, הצעות בדף התשלום) ממשיכים לעבוד.</p>
		</td>
	</tr>
	<?php
}
add_action( 'product_cat_edit_form_fields', 'sella_hc_edit_field', 20 );

/**
 * Save the checkbox. WordPress checked the form's nonce and the user's right
 * to manage categories before these hooks run.
 *
 * @param int $term_id Category.
 */
function sella_hc_save( $term_id ) {
	$ids = array_diff( sella_hc_ids(), array( (int) $term_id ) );
	if ( ! empty( $_POST['sella_hidden_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids[] = (int) $term_id;
	}
	update_option( SELLA_HC_OPTION, array_values( array_unique( $ids ) ), true );
	if ( function_exists( 'sella_mf_flush' ) ) {
		sella_mf_flush();
	}
}
add_action( 'created_product_cat', 'sella_hc_save' );
add_action( 'edited_product_cat', 'sella_hc_save' );

/**
 * A deleted category leaves the list.
 *
 * @param int $term_id Category.
 */
function sella_hc_forget( $term_id ) {
	update_option( SELLA_HC_OPTION, array_values( array_diff( sella_hc_ids(), array( (int) $term_id ) ) ), true );
}
add_action( 'delete_product_cat', 'sella_hc_forget' );

/**
 * Mark hidden categories in the admin list.
 *
 * @param string $name Category name as listed.
 * @param WP_Term $term Category.
 * @return string
 */
function sella_hc_list_name( $name, $term = null ) {
	if ( is_admin() && $term instanceof WP_Term && 'product_cat' === $term->taxonomy && in_array( (int) $term->term_id, sella_hc_ids(), true ) ) {
		$name .= ' — נסתרת';
	}
	return $name;
}
add_filter( 'term_name', 'sella_hc_list_name', 10, 2 );

/*
 * ------------------------------------------------------------------
 * Out of sight on the site
 * ------------------------------------------------------------------
 */

/**
 * Leave hidden categories out of category lists: widgets, blocks, the shop's
 * subcategories, the filter bar.
 *
 * Only listings: a query for one product's categories (object_ids) or for a
 * named category (slug, name, include) still finds it, so has_term() and
 * category rules keep working.
 *
 * @param array    $args       Term query arguments.
 * @param string[] $taxonomies Taxonomies.
 * @return array
 */
function sella_hc_term_args( $args, $taxonomies ) {
	if ( ! in_array( 'product_cat', (array) $taxonomies, true ) || sella_hc_admin_view() ) {
		return $args;
	}
	foreach ( array( 'object_ids', 'slug', 'name', 'include', 'term_taxonomy_id' ) as $key ) {
		if ( ! empty( $args[ $key ] ) ) {
			return $args;
		}
	}
	$hidden = sella_hc_ids();
	if ( $hidden ) {
		$args['exclude'] = array_merge( wp_parse_id_list( isset( $args['exclude'] ) ? $args['exclude'] : array() ), $hidden );
	}
	return $args;
}
add_filter( 'get_terms_args', 'sella_hc_term_args', 10, 2 );

/**
 * Hidden categories' URLs, to recognise their links.
 *
 * @return string[]
 */
function sella_hc_links() {
	static $links = null;
	if ( null === $links ) {
		$links = array();
		foreach ( sella_hc_ids() as $id ) {
			$link = get_term_link( $id, 'product_cat' );
			if ( ! is_wp_error( $link ) ) {
				$links[] = untrailingslashit( $link );
			}
		}
	}
	return $links;
}

/**
 * The "Categories:" links on a product page (WooCommerce and Elementor's
 * product meta both build them with get_the_term_list).
 *
 * @param string[] $links Link HTML.
 * @return string[]
 */
function sella_hc_term_links( $links ) {
	if ( sella_hc_admin_view() || ! sella_hc_ids() ) {
		return $links;
	}
	return array_values(
		array_filter(
			$links,
			function ( $html ) {
				return ! preg_match( '#href="([^"]+)"#', $html, $m ) || ! in_array( untrailingslashit( html_entity_decode( $m[1] ) ), sella_hc_links(), true );
			}
		)
	);
}
add_filter( 'term_links-product_cat', 'sella_hc_term_links' );

/**
 * WooCommerce breadcrumb on a product: never through a hidden category.
 *
 * @param WP_Term   $main_term Category the breadcrumb goes through.
 * @param WP_Term[] $terms     The product's categories.
 * @return WP_Term
 */
function sella_hc_breadcrumb_term( $main_term, $terms ) {
	$hidden = sella_hc_ids();
	if ( ! $main_term || ! in_array( (int) $main_term->term_id, $hidden, true ) ) {
		return $main_term;
	}
	foreach ( $terms as $term ) {
		if ( ! in_array( (int) $term->term_id, $hidden, true ) ) {
			return $term;
		}
	}
	return $main_term;
}
add_filter( 'woocommerce_breadcrumb_main_term', 'sella_hc_breadcrumb_term', 10, 2 );

/**
 * Yoast breadcrumbs (and the BreadcrumbList schema built from them).
 *
 * @param array $crumbs Crumbs with url and text.
 * @return array
 */
function sella_hc_yoast_crumbs( $crumbs ) {
	if ( ! sella_hc_ids() ) {
		return $crumbs;
	}
	return array_values(
		array_filter(
			$crumbs,
			function ( $crumb ) {
				return empty( $crumb['url'] ) || ! in_array( untrailingslashit( $crumb['url'] ), sella_hc_links(), true );
			}
		)
	);
}
add_filter( 'wpseo_breadcrumb_links', 'sella_hc_yoast_crumbs' );

/**
 * The category's own page: 404 for visitors, noindex and out of the sitemap.
 */
function sella_hc_archive_404() {
	if ( is_tax( 'product_cat' ) && in_array( get_queried_object_id(), sella_hc_ids(), true ) && ! current_user_can( 'edit_products' ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
add_action( 'template_redirect', 'sella_hc_archive_404', 1 );

/**
 * Keep hidden categories out of Yoast's sitemap.
 *
 * @param int[] $ids Term IDs Yoast leaves out.
 * @return int[]
 */
function sella_hc_sitemap( $ids ) {
	return array_merge( (array) $ids, sella_hc_ids() );
}
add_filter( 'wpseo_exclude_from_sitemap_by_term_ids', 'sella_hc_sitemap' );

/**
 * The shop filter bar comes from a shortcode outside the theme. If it builds
 * its buttons from a fixed list of IDs, the term query above cannot drop the
 * hidden ones, so their buttons are hidden here too.
 */
function sella_hc_filter_css() {
	$ids = sella_hc_ids();
	if ( ! $ids || sella_hc_admin_view() ) {
		return;
	}
	$selectors = array();
	foreach ( $ids as $id ) {
		$selectors[] = '.sibolet-categories-wrapper .cat-filter-btn[data-cat-id="' . (int) $id . '"]';
	}
	echo '<style id="sella-hidden-cats">' . implode( ',', $selectors ) . '{display:none!important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers only.
}
add_action( 'wp_head', 'sella_hc_filter_css', 50 );
