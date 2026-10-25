<?php
/**
 * SEO fixes on top of Yoast for the move from Shopify.
 *
 * - Product structured data: the Elementor product template does not run
 *   woocommerce_single_product_summary, where WooCommerce collects it, so
 *   product pages had no Product/Offer (price and stock in Google results).
 * - Meta descriptions: Shopify gave every product one from its description;
 *   here they were empty. Pages and terms without one get it from their text.
 * - Shop archives: "ארכיון" is dropped from term titles. Brands repeat the
 *   categories, and most tags hold a single book, so those are noindex and
 *   out of the sitemap.
 * - The admin warns when the live domain is blocked from search engines.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_SEO_LIVE_HOST', 'sellameir.com' );
define( 'SELLA_SEO_DESC_LENGTH', 155 );

/*
 * ------------------------------------------------------------------
 * Product structured data
 * ------------------------------------------------------------------
 */

/**
 * Hand the product to WooCommerce's structured data before it prints it in
 * the footer (wp_footer, 10), when the product template skipped the hook
 * that normally does this.
 */
function sella_seo_product_structured_data() {
	if ( ! is_product() || did_action( 'woocommerce_single_product_summary' ) ) {
		return;
	}
	if ( ! isset( WC()->structured_data ) || ! is_object( WC()->structured_data ) ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( $product ) {
		WC()->structured_data->generate_product_data( $product );
	}
}
add_action( 'wp_footer', 'sella_seo_product_structured_data', 5 );

/**
 * Add the book's barcode as its GTIN when WooCommerce has none.
 *
 * @param array      $markup  Product markup.
 * @param WC_Product $product Product.
 * @return array
 */
function sella_seo_product_markup( $markup, $product ) {
	$barcode = preg_replace( '/\D/', '', (string) get_post_meta( $product->get_id(), '_sella_barcode', true ) );
	if ( empty( $markup['gtin'] ) && empty( $markup['gtin13'] ) && 13 === strlen( $barcode ) ) {
		$markup['gtin13'] = $barcode;
	}
	return $markup;
}
add_filter( 'woocommerce_structured_data_product', 'sella_seo_product_markup', 10, 2 );

/*
 * ------------------------------------------------------------------
 * Meta descriptions
 * ------------------------------------------------------------------
 */

/**
 * Cut text to a description length on a word boundary.
 *
 * @param string $text Text, may hold HTML and shortcodes.
 * @return string
 */
function sella_seo_trim_description( $text ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	if ( mb_strlen( $text ) <= SELLA_SEO_DESC_LENGTH ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, SELLA_SEO_DESC_LENGTH );
	$space = mb_strrpos( $cut, ' ' );
	return rtrim( false === $space ? $cut : mb_substr( $cut, 0, $space ), ' ,.;:–-' ) . '…';
}

/**
 * Description for the current page from its own text.
 *
 * @return string
 */
function sella_seo_fallback_description() {
	if ( is_front_page() ) {
		return get_bloginfo( 'description' );
	}

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		// A short excerpt ("כריכה רכה") says less than the start of the full text.
		$excerpt = sella_seo_trim_description( $post->post_excerpt );
		$content = sella_seo_trim_description( $post->post_content );
		return mb_strlen( $excerpt ) >= 70 || '' === $content ? $excerpt : $content;
	}

	if ( is_tax( array( 'product_cat', 'product_tag', 'product_brand' ) ) ) {
		$term = get_queried_object();
		$text = sella_seo_trim_description( term_description( $term ) );
		return '' !== $text ? $text : sprintf( '%s: כל הספרים באתר הוצאת סלע מאיר.', $term->name );
	}

	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return sella_seo_trim_description( get_post_field( 'post_excerpt', wc_get_page_id( 'shop' ) ) );
	}

	return '';
}

/**
 * Fill an empty Yoast description (meta, Open Graph, X) from the page.
 *
 * @param string $description Description Yoast would print.
 * @return string
 */
function sella_seo_description( $description ) {
	if ( '' !== trim( (string) $description ) ) {
		return $description;
	}
	static $fallback = null;
	if ( null === $fallback ) {
		$fallback = sella_seo_fallback_description();
	}
	return $fallback;
}
add_filter( 'wpseo_metadesc', 'sella_seo_description' );
add_filter( 'wpseo_opengraph_desc', 'sella_seo_description' );
add_filter( 'wpseo_twitter_description', 'sella_seo_description' );

/*
 * ------------------------------------------------------------------
 * Shop archives
 * ------------------------------------------------------------------
 */

/**
 * "ארכיון ספרים • סלע מאיר" becomes "ספרים • סלע מאיר".
 *
 * @param string $title Title.
 * @return string
 */
function sella_seo_term_title( $title ) {
	if ( is_tax( array( 'product_cat', 'product_tag', 'product_brand' ) ) ) {
		$title = preg_replace( '/^ארכיון\s+/u', '', $title );
	}
	return $title;
}
add_filter( 'wpseo_title', 'sella_seo_term_title' );
add_filter( 'wpseo_opengraph_title', 'sella_seo_term_title' );

/**
 * Product tags holding fewer than two products: a page with one book on it
 * repeats that book's page.
 *
 * @return int[]
 */
function sella_seo_thin_tag_ids() {
	static $ids = null;
	if ( null === $ids ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);
		$ids   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term->count < 2 ) {
				$ids[] = (int) $term->term_id;
			}
		}
	}
	return $ids;
}

/**
 * Brands and thin tags: noindex, but links on them are still followed.
 *
 * @param array $robots Yoast robots values.
 * @return array
 */
function sella_seo_robots( $robots ) {
	$thin_tag = is_tax( 'product_tag' ) && in_array( get_queried_object_id(), sella_seo_thin_tag_ids(), true );
	if ( is_tax( 'product_brand' ) || $thin_tag ) {
		$robots['index'] = 'noindex';
	}
	return $robots;
}
add_filter( 'wpseo_robots_array', 'sella_seo_robots' );

/**
 * Keep brands out of the sitemap.
 *
 * @param bool   $excluded Whether Yoast leaves the taxonomy out.
 * @param string $taxonomy Taxonomy name.
 * @return bool
 */
function sella_seo_sitemap_taxonomies( $excluded, $taxonomy ) {
	return 'product_brand' === $taxonomy ? true : $excluded;
}
add_filter( 'wpseo_sitemap_exclude_taxonomy', 'sella_seo_sitemap_taxonomies', 10, 2 );

/**
 * Keep thin tags out of the sitemap.
 *
 * @param int[] $ids Term IDs Yoast leaves out.
 * @return int[]
 */
function sella_seo_sitemap_terms( $ids ) {
	return array_merge( (array) $ids, sella_seo_thin_tag_ids() );
}
add_filter( 'wpseo_exclude_from_sitemap_by_term_ids', 'sella_seo_sitemap_terms' );

/*
 * ------------------------------------------------------------------
 * Launch check
 * ------------------------------------------------------------------
 */

/**
 * On the live domain, warn while "Discourage search engines" is still on:
 * every page then says noindex and Google drops the site.
 */
function sella_seo_launch_notice() {
	if ( ! current_user_can( 'manage_options' ) || '0' !== (string) get_option( 'blog_public' ) ) {
		return;
	}
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( SELLA_SEO_LIVE_HOST !== preg_replace( '/^www\./', '', $host ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>האתר חסום למנועי חיפוש.</strong> כל העמודים מסומנים noindex וגוגל יוריד אותם מהתוצאות. יש לבטל את הסימון "בקש ממנועי חיפוש לא לאנדקס את האתר" ב<a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">הגדרות ← קריאה</a>.</p></div>';
}
add_action( 'admin_notices', 'sella_seo_launch_notice' );
