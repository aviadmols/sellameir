<?php
/**
 * Sella Page Cache drop-in.
 *
 * inc/sella-page-cache.php copies this file to wp-content/advanced-cache.php.
 * WordPress loads it very early (when WP_CACHE is true), before plugins and the
 * theme, so a saved page is sent without building it again.
 *
 * Only guests with an empty cart get cached pages. Anyone logged in, with items
 * in the cart, or with a WooCommerce session always gets a fresh page.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_PC_DROPIN', '1.0.0' );

if ( ! defined( 'SELLA_PC_DIR' ) ) {
	define( 'SELLA_PC_DIR', WP_CONTENT_DIR . '/cache/sella-page-cache' );
}

// 10 hours: shorter than the 12-hour minimum life of a WordPress nonce, so
// nonces printed in a cached page are still valid when it is served.
if ( ! defined( 'SELLA_PC_TTL' ) ) {
	define( 'SELLA_PC_TTL', 36000 );
}

/**
 * Cache file path for the current request, or null when it must not be cached.
 *
 * @return string|null
 */
function sella_pc_request_file() {
	if ( 'cli' === PHP_SAPI ) {
		return null;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return null;
	}

	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( preg_match( '/^(wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/', $name ) ) {
			return null;
		}
	}

	// Ad-click parameters do not change the page, so they share its cache entry.
	// Any other parameter (add-to-cart, s, orderby, wc-ajax...) skips the cache.
	if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
		parse_str( $_SERVER['QUERY_STRING'], $query );
		foreach ( array_keys( $query ) as $param ) {
			if ( ! preg_match( '/^(utm_[a-z_]+|fbclid|gclid|gbraid|wbraid|msclkid|ttclid|igshid|_gl)$/i', $param ) ) {
				return null;
			}
		}
	}

	$path = parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );

	// WordPress pages end with a slash; files such as robots.txt, sitemaps and
	// wp-*.php do not, and admin, API and feed paths are left alone.
	if ( ! is_string( $path ) || '/' !== substr( $path, -1 ) || preg_match( '#/(wp-admin|wp-json|wp-content|wp-includes|feed)/#', $path ) ) {
		return null;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ) ) : '';
	if ( '' === $host ) {
		return null;
	}

	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );

	// Same test as wp_is_mobile(), so a plugin that prints different markup
	// for phones never has its mobile page served to desktops or the reverse.
	$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	$mobile = preg_match( '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/', $ua );

	return SELLA_PC_DIR . '/' . $host . '/' . md5( rawurldecode( $path ) ) . ( $https ? '-s' : '' ) . ( $mobile ? '-m' : '-d' ) . '.html';
}

$sella_pc_file = sella_pc_request_file();

if ( $sella_pc_file ) {
	if ( is_file( $sella_pc_file ) && filemtime( $sella_pc_file ) > time() - SELLA_PC_TTL ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Sella-Cache: HIT' );
		if ( 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
			readfile( $sella_pc_file );
		}
		exit;
	}

	// The plugin saves the page to this file once WordPress has built it.
	define( 'SELLA_PC_CANDIDATE', $sella_pc_file );
	header( 'X-Sella-Cache: MISS' );
}

unset( $sella_pc_file );
