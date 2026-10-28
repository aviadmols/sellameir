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
 * Two more answers come from here, before WordPress connects to the database:
 * the requests left over from the old Shopify store, and the redirects of its
 * old URLs once WordPress has worked each one out (sella-legacy-redirects.php).
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_PC_DROPIN', '1.2.0' );

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

/**
 * The security headers (sella-security.php sends the same ones on the pages
 * WordPress builds, by calling this).
 */
function sella_pc_security_headers() {
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), usb=()' );
	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );
	if ( $https ) {
		header( 'Strict-Transport-Security: max-age=31536000' );
	}
	header_remove( 'X-Powered-By' );
}

/**
 * Whether the request is an attack or a scan, turned away before WordPress
 * connects to the database: files nobody should ask for (.env, .git,
 * backups, database tools), and addresses carrying SQL injection, path
 * traversal, script injection or code execution. The patterns are narrow,
 * so a real visitor (a search in Hebrew, an ad click) is never caught.
 *
 * @param string $path Request path, decoded.
 * @return bool
 */
function sella_pc_attack( $path ) {
	if ( preg_match( '#/(\.(env|git|svn|hg|aws|ssh|htpasswd|htaccess|DS_Store|idea|vscode)(/|$)|wp-config\.php.|(php|my|pma)?myadmin|adminer|phpinfo\.php|vendor/phpunit|cgi-bin/)#i', $path ) ) {
		return true;
	}
	if ( preg_match( '#\.(sql|sql\.gz|bak|old|orig|swp|save|tar|tgz|7z)$#i', $path ) ) {
		return true;
	}

	$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
	$all   = $path . '?' . rawurldecode( rawurldecode( $query ) );
	return (bool) preg_match(
		'#(\.\./|\.\.\\\\|/etc/passwd|/proc/self/|php://(input|filter)|phar://|expect://|<\s*script|javascript:\s*\S|\$\{jndi:|\bunion\b[\s+/*]+(all[\s+/*]+)?select\b|\bselect\b.{1,60}\bfrom\b.{1,60}\b(information_schema|wp_users|mysql\.user)\b|\b(sleep|benchmark|load_file)\s*\(|\binto\s+(out|dump)file\b|\b(base64_decode|eval|assert|shell_exec|passthru|proc_open|popen)\s*\()#i',
		$all
	);
}

/**
 * What to answer a request left over from the old Shopify store, or 0.
 *
 * Pages and tabs opened while the domain was Shopify's keep sending its
 * telemetry (/.well-known/shopify/monorail, /api/collect) and calling its
 * cart and search endpoints. Nothing here answers them, and each one used to
 * load all of WordPress to say 404. The telemetry gets 204 with no CORS
 * headers, so the scripts give up; the rest gets 404.
 *
 * @param string $path Request path, decoded.
 * @return int HTTP status, or 0 for a request of this site.
 */
function sella_pc_shopify_leftover( $path ) {
	if ( preg_match( '#^/(\.well-known/shopify/|api/collect(/|$)|api/unstable/|api/20\d\d-\d\d/|cdn/wpm/|cdn/shopifycloud/|wpm@)#i', $path ) ) {
		return 204;
	}
	if ( preg_match( '#^/(cart(/[a-z_]+)?\.(js|json)$|products/[^/]+\.(js|json|oembed|xml)$|collections/[^/]+\.(json|atom)$|search/suggest|recommendations/products|cdn/shop/|checkouts/)#i', $path ) ) {
		return 404;
	}
	return 0;
}

/**
 * Where the redirect of an old Shopify URL is kept once worked out, or null
 * for any other path. The path is matched as sella_lr_request_path() makes it.
 *
 * @param string $path Request path, decoded.
 * @return string|null
 */
function sella_pc_redirect_file( $path ) {
	$path = strtolower( rtrim( $path, '/' ) );
	if ( ! preg_match( '#^/(products|collections|pages|blogs|policies|account|search|agents\.md)(/|$)#', $path ) ) {
		return null;
	}
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ) ) : '';
	return '' === $host ? null : SELLA_PC_DIR . '/' . $host . '/redirects/' . md5( $path ) . '.txt';
}

// Behind Cloudflare, PHP sees plain http, and WordPress builds http:// links
// (fonts, uploads, og:url) that browsers block on the https site. The proxy
// says which scheme the visitor used; WordPress is told before it loads.
if ( empty( $_SERVER['HTTPS'] ) || 'off' === strtolower( $_SERVER['HTTPS'] ) ) {
	$sella_pc_visitor = isset( $_SERVER['HTTP_CF_VISITOR'] ) ? (string) $_SERVER['HTTP_CF_VISITOR'] : '';
	if ( ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) || false !== strpos( $sella_pc_visitor, '"https"' ) ) {
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = 443;
	}
	unset( $sella_pc_visitor );
}

$sella_pc_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
$sella_pc_path   = rawurldecode( (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH ) );

if ( 'cli' !== PHP_SAPI ) {
	if ( sella_pc_attack( $sella_pc_path ) ) {
		http_response_code( 403 );
		header( 'X-Sella-Cache: BLOCKED' );
		header( 'Cache-Control: no-store' );
		exit;
	}

	$sella_pc_status = sella_pc_shopify_leftover( $sella_pc_path );
	if ( $sella_pc_status ) {
		http_response_code( $sella_pc_status );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Sella-Cache: SHOPIFY' );
		exit;
	}

	$sella_pc_redirect = 'GET' === $sella_pc_method || 'HEAD' === $sella_pc_method ? sella_pc_redirect_file( $sella_pc_path ) : null;
	if ( $sella_pc_redirect ) {
		$sella_pc_target = is_file( $sella_pc_redirect ) && filemtime( $sella_pc_redirect ) > time() - SELLA_PC_TTL ? trim( (string) file_get_contents( $sella_pc_redirect ) ) : '';
		if ( '' !== $sella_pc_target ) {
			// Campaign parameters (utm_*) go along, as the WordPress redirect does.
			$sella_pc_keep = array();
			foreach ( $_GET as $sella_pc_key => $sella_pc_value ) {
				if ( preg_match( '/^utm_[a-z0-9_]+$/i', (string) $sella_pc_key ) && is_string( $sella_pc_value ) ) {
					$sella_pc_keep[] = $sella_pc_key . '=' . rawurlencode( trim( strip_tags( $sella_pc_value ) ) );
				}
			}
			if ( $sella_pc_keep ) {
				$sella_pc_target .= ( false === strpos( $sella_pc_target, '?' ) ? '?' : '&' ) . implode( '&', $sella_pc_keep );
			}
			header( 'X-Sella-Cache: REDIRECT' );
			header( 'X-Redirect-By: Sella legacy redirects' );
			header( 'Location: ' . $sella_pc_target, true, 301 );
			exit;
		}
		// sella_lr_redirect() keeps the redirect here once it has it.
		define( 'SELLA_PC_REDIRECT_FILE', $sella_pc_redirect );
	}
}

unset( $sella_pc_method, $sella_pc_path, $sella_pc_status, $sella_pc_redirect, $sella_pc_target, $sella_pc_keep, $sella_pc_key, $sella_pc_value );

$sella_pc_file = sella_pc_request_file();

if ( $sella_pc_file ) {
	if ( is_file( $sella_pc_file ) && filemtime( $sella_pc_file ) > time() - SELLA_PC_TTL ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Sella-Cache: HIT' );
		sella_pc_security_headers();
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
