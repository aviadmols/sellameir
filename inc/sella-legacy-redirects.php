<?php
/**
 * Redirects from the old Shopify store (sellameir.com) to this site.
 *
 * Every URL in the Shopify sitemap is listed in sella-legacy-redirects-map.php
 * with its page here; the URL shapes Shopify makes on its own (a product under
 * a collection, collection tag filters, blog tags, account pages, search) are
 * rewritten below. Each old URL gets one 301 straight to its final page.
 *
 * A map entry marked "missing" points at a stand-in (the shop, a category, the
 * book an article talks about) because the content was not moved. Once a
 * product or a post with the old Shopify handle as its slug is published here,
 * the redirect goes to it without touching the map.
 *
 * Old paths never exist on this site, so this runs on the staging host too and
 * can be tested there before the domain moves.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Old path => [ new path, true when the content was not moved ].
 *
 * Old paths are decoded (Hebrew, not %D7..), without a trailing slash.
 *
 * @return array<string, array{0: string, 1: bool}>
 */
function sella_lr_map() {
	static $map = null;
	if ( null === $map ) {
		$map = require __DIR__ . '/sella-legacy-redirects-map.php';
	}
	return $map;
}

/**
 * Old paths whose content was imported later (sella-shopify-import.php):
 * old path => post ID. WordPress shortens long Hebrew slugs, so the imported
 * post cannot always be found by its old handle.
 *
 * @return array<string, int>
 */
function sella_lr_moved() {
	$moved = get_option( 'sella_lr_moved', array() );
	return is_array( $moved ) ? $moved : array();
}

/**
 * Record that an old path now lives in a post.
 *
 * @param string $old_path Old path, decoded.
 * @param int    $post_id  Post.
 */
function sella_lr_record_move( $old_path, $post_id ) {
	$moved = sella_lr_moved();
	$moved[ strtolower( rtrim( $old_path, '/' ) ) ] = (int) $post_id;
	update_option( 'sella_lr_moved', $moved, false );
}

/**
 * The request path, decoded, lower-case ASCII, without a trailing slash.
 *
 * @return string
 */
function sella_lr_request_path() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$path = rawurldecode( $path );

	// A WordPress install in a subfolder: compare against the path below it.
	$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
		$path = substr( $path, strlen( $base ) );
	}

	$path = strtolower( rtrim( $path, '/' ) );
	return '' === $path ? '/' : $path;
}

/**
 * Published product or post whose slug is the old Shopify handle.
 *
 * @param string $handle    Decoded handle.
 * @param string $post_type 'product' or 'post'.
 * @return string Decoded path on this site, or '' when there is none.
 */
function sella_lr_find_by_handle( $handle, $post_type ) {
	$post = get_page_by_path( $handle, OBJECT, $post_type );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return '';
	}
	return rawurldecode( (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH ) );
}

/**
 * Where an old path goes, or '' when it is not an old Shopify path.
 *
 * @param string $path Output of sella_lr_request_path().
 * @return string Path on this site, or a full URL for search.
 */
function sella_lr_target( $path ) {
	$map = sella_lr_map();

	// Shopify links every product from each collection as /collections/<c>/products/<handle>,
	// and serves data versions of it at /products/<handle>.json and the like.
	$path = preg_replace( '#^/collections/[^/]+(/products/[^/]+)$#', '$1', $path );
	$path = preg_replace( '#^(/products/[^/.]+)\.(json|js|oembed|xml|atom)$#', '$1', $path );

	$moved = sella_lr_moved();
	if ( isset( $moved[ $path ] ) && 'publish' === get_post_status( $moved[ $path ] ) ) {
		return rawurldecode( (string) wp_parse_url( get_permalink( $moved[ $path ] ), PHP_URL_PATH ) );
	}

	if ( preg_match( '#^/(products|blogs/[^/]+)/([^/]+)$#', $path, $m ) && 'tagged' !== $m[2] ) {
		$moved = isset( $map[ $path ] ) && ! $map[ $path ][1];
		if ( ! $moved ) {
			$found = sella_lr_find_by_handle( $m[2], 'products' === $m[1] ? 'product' : 'post' );
			if ( '' !== $found ) {
				return $found;
			}
		}
		if ( isset( $map[ $path ] ) ) {
			return $map[ $path ][0];
		}
		return 'products' === $m[1] ? '/shop/' : '/media/';
	}

	if ( isset( $map[ $path ] ) ) {
		return $map[ $path ][0];
	}

	// A collection filtered by tag: /collections/<c>/<tag>.
	if ( preg_match( '#^(/collections/[^/]+)/.+$#', $path, $m ) ) {
		return isset( $map[ $m[1] ] ) ? $map[ $m[1] ][0] : '/shop/';
	}

	if ( preg_match( '#^/(collections|products)(/|$)#', $path ) ) {
		return '/shop/';
	}
	if ( preg_match( '#^/blogs(/|$)#', $path ) ) {
		return '/media/';
	}
	if ( preg_match( '#^/(account|policies)(/|$)#', $path ) ) {
		return 0 === strpos( $path, '/account' ) ? '/my-account/' : '/terms/';
	}
	if ( 0 === strpos( $path, '/pages/' ) ) {
		return '/';
	}
	if ( '/search' === $path ) {
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '' === $q ? '/shop/' : add_query_arg( array( 's' => rawurlencode( $q ), 'post_type' => 'product' ), home_url( '/' ) );
	}

	return '';
}

/**
 * Encode a path the way WordPress writes its own permalinks (lower-case %xx),
 * so the redirect lands on the canonical URL and WordPress does not redirect
 * it a second time.
 *
 * @param string $path Decoded path.
 * @return string
 */
function sella_lr_encode_path( $path ) {
	$encoded = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	return preg_replace_callback(
		'/%[0-9A-F]{2}/',
		function ( $m ) {
			return strtolower( $m[0] );
		},
		$encoded
	);
}

/**
 * Links in product and post text that still point at the Shopify store
 * (sellameir.com/products/...) are rewritten to their page here, so they
 * need no redirect once the domain moves.
 *
 * @param string $html Content HTML.
 * @return string
 */
function sella_lr_fix_content_links( $html ) {
	if ( false === stripos( (string) $html, 'sellameir.com/' ) ) {
		return $html;
	}
	return preg_replace_callback(
		'#href=(["\'])https?://(?:www\.)?sellameir\.com(/(?:products|collections|pages|blogs)/[^"\'?\#]*)[^"\']*\1#i',
		function ( $m ) {
			$path   = strtolower( rtrim( rawurldecode( $m[2] ), '/' ) );
			$target = sella_lr_target( $path );
			if ( '' === $target ) {
				return $m[0];
			}
			$url = 0 === strpos( $target, 'http' ) ? $target : home_url( sella_lr_encode_path( $target ) );
			return 'href=' . $m[1] . esc_url( $url ) . $m[1];
		},
		$html
	);
}
add_filter( 'the_content', 'sella_lr_fix_content_links', 20 );
add_filter( 'woocommerce_short_description', 'sella_lr_fix_content_links', 20 );

/**
 * Send old Shopify URLs to their page here. Campaign parameters (utm_*) are
 * kept; Shopify's own (variant, page, sort_by...) are dropped.
 */
function sella_lr_redirect() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$path = sella_lr_request_path();
	if ( ! preg_match( '#^/(products|collections|pages|blogs|policies|account|search|agents\.md)(/|$)#', $path ) ) {
		return;
	}

	$target = sella_lr_target( $path );
	if ( '' === $target ) {
		return;
	}

	$url = 0 === strpos( $target, 'http' ) ? $target : home_url( sella_lr_encode_path( $target ) );

	$keep = array();
	foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === strpos( (string) $key, 'utm_' ) && is_string( $value ) ) {
			$keep[ $key ] = rawurlencode( sanitize_text_field( wp_unslash( $value ) ) );
		}
	}
	if ( $keep ) {
		$url = add_query_arg( $keep, $url );
	}

	wp_safe_redirect( $url, 301, 'Sella legacy redirects' );
	exit;
}
// Before redirect_canonical (10), which would otherwise guess a page for an unknown path.
add_action( 'template_redirect', 'sella_lr_redirect', 0 );
