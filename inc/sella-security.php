<?php
/**
 * Security.
 *
 * - The admin's login is at /office. wp-login.php and wp-admin answer 404 to
 *   anyone not logged in, so bots looking for the login find nothing. Kept as
 *   they were: wp-login.php?action=postpass (the form of password-protected
 *   pages), admin-ajax.php and admin-post.php (the site's own forms and AJAX),
 *   and wp-admin for whoever is logged in. Every link WordPress makes to the
 *   login (the forms, the password reset email, log out) points at /office.
 *   In an emergency, define( 'SELLA_LOGIN_SLUG', false ); in wp-config.php
 *   brings wp-login.php back.
 * - Failed logins, here and on the shop's account page, are counted per
 *   visitor's IP: after 5 in 15 minutes that IP cannot try again for 30
 *   minutes. The message never says whether a username exists.
 * - Usernames are not given away: no users list in the REST API for visitors,
 *   no ?author=N lookups, no authors in the sitemap.
 * - XML-RPC off, the theme and plugin file editors off, no versions in the
 *   page source, and security headers on every page (also on the cached ones,
 *   sent by the page cache drop-in, which also turns away attack requests
 *   before WordPress loads: inc/page-cache/advanced-cache.php).
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SELLA_LOGIN_SLUG' ) ) {
	define( 'SELLA_LOGIN_SLUG', 'office' );
}
define( 'SELLA_SEC_MAX_FAILS', 5 );
define( 'SELLA_SEC_FAIL_WINDOW', 15 * MINUTE_IN_SECONDS );
define( 'SELLA_SEC_LOCKOUT', 30 * MINUTE_IN_SECONDS );

// No editing of theme and plugin files from the admin.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

/*
 * ------------------------------------------------------------------
 * The login at /office
 * ------------------------------------------------------------------
 */

/**
 * @return bool
 */
function sella_sec_login_moved() {
	return is_string( SELLA_LOGIN_SLUG ) && '' !== SELLA_LOGIN_SLUG;
}

/**
 * The request path, decoded, without the slashes around it.
 *
 * @return string
 */
function sella_sec_path() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return strtolower( trim( rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) ), '/' ) );
}

/**
 * A plain 404 for the login's old addresses, and the end of the request.
 */
function sella_sec_not_found() {
	nocache_headers();
	wp_die(
		'<p>הדף לא נמצא.</p><p><a href="' . esc_url( home_url( '/' ) ) . '">לדף הבית</a></p>',
		'הדף לא נמצא',
		array( 'response' => 404 )
	);
}

/**
 * wp-login.php and wp-admin, for anyone not logged in: not found.
 */
function sella_sec_login_gate() {
	if ( ! sella_sec_login_moved() || defined( 'SELLA_SEC_LOGIN' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	global $pagenow;

	if ( 'wp-login.php' === $pagenow ) {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Password-protected pages post their password here, from pages
		// that may have been cached before the login moved.
		if ( 'postpass' !== $action ) {
			sella_sec_not_found();
		}
		return;
	}

	if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && ! in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
		sella_sec_not_found();
	}
}
add_action( 'init', 'sella_sec_login_gate', 1 );

/**
 * /office: WordPress's own login screen, as if wp-login.php was asked for.
 * After wp_loaded, as wp-login.php itself runs once WordPress has loaded.
 */
function sella_sec_serve_login() {
	if ( ! sella_sec_login_moved() || sella_sec_path() !== strtolower( SELLA_LOGIN_SLUG ) ) {
		return;
	}

	// The variables wp-login.php shares with its own functions.
	global $error, $interim_login, $action, $user_login, $pagenow;
	$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	define( 'SELLA_SEC_LOGIN', true );
	nocache_headers();
	require_once ABSPATH . 'wp-login.php';
	exit;
}
add_action( 'wp_loaded', 'sella_sec_serve_login', 1 );

/**
 * Links to wp-login.php go to /office.
 *
 * @param string $url URL.
 * @return string
 */
function sella_sec_login_url( $url ) {
	if ( ! sella_sec_login_moved() || ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
		return $url;
	}
	return preg_replace( '#/wp-login\.php#', '/' . SELLA_LOGIN_SLUG, $url, 1 );
}
add_filter( 'site_url', 'sella_sec_login_url', 99 );
add_filter( 'network_site_url', 'sella_sec_login_url', 99 );
add_filter( 'wp_redirect', 'sella_sec_login_url', 99 );
add_filter( 'login_url', 'sella_sec_login_url', 99 );
add_filter( 'logout_url', 'sella_sec_login_url', 99 );
add_filter( 'lostpassword_url', 'sella_sec_login_url', 99 );
add_filter( 'register_url', 'sella_sec_login_url', 99 );

/*
 * ------------------------------------------------------------------
 * Failed logins
 * ------------------------------------------------------------------
 */

/**
 * Whether an IP is one of Cloudflare's, which stands in front of the site.
 *
 * @param string $ip IP.
 * @return bool
 */
function sella_sec_is_cloudflare( $ip ) {
	$ranges = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);
	$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $bin ) {
		return false;
	}
	foreach ( $ranges as $range ) {
		list( $net, $bits ) = explode( '/', $range );
		$net = inet_pton( $net );
		if ( strlen( $net ) !== strlen( $bin ) ) {
			continue;
		}
		$bytes = intdiv( (int) $bits, 8 );
		$rest  = (int) $bits % 8;
		if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			continue;
		}
		if ( ! $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		if ( ( $bin[ $bytes ] & $mask ) === ( $net[ $bytes ] & $mask ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The visitor's IP. Cloudflare's header is believed only when the request
 * really comes from Cloudflare, so it cannot be forged to dodge the count.
 *
 * @return string
 */
function sella_sec_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && sella_sec_is_cloudflare( $ip ) ) {
		$cf = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
			$ip = $cf;
		}
	}
	return $ip;
}

/**
 * @return string
 */
function sella_sec_key() {
	return 'sella_sec_' . md5( sella_sec_ip() );
}

/**
 * Before the password is checked: an IP that failed too often waits.
 *
 * @param WP_User|WP_Error|null $user User so far.
 * @return WP_User|WP_Error|null
 */
function sella_sec_check_lockout( $user ) {
	$until = (int) get_transient( sella_sec_key() . '_lock' );
	if ( $until > time() ) {
		return new WP_Error(
			'sella_locked',
			sprintf( 'יותר מדי ניסיונות התחברות שגויים. אפשר לנסות שוב בעוד %d דקות.', max( 1, (int) ceil( ( $until - time() ) / 60 ) ) )
		);
	}
	return $user;
}
add_filter( 'authenticate', 'sella_sec_check_lockout', 1 );

/**
 * After the checks: one message for a wrong username and a wrong password,
 * so the form does not tell which usernames exist.
 *
 * @param WP_User|WP_Error|null $user User.
 * @return WP_User|WP_Error|null
 */
function sella_sec_generic_error( $user ) {
	if ( is_wp_error( $user ) && array_intersect( $user->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
		return new WP_Error( 'sella_login_failed', 'שם המשתמש או הסיסמה שגויים.' );
	}
	return $user;
}
add_filter( 'authenticate', 'sella_sec_generic_error', 99 );

/**
 * Count a failed login; the fifth in the window locks the IP out.
 */
function sella_sec_count_failure() {
	$key   = sella_sec_key();
	$fails = get_transient( $key );
	$fails = is_array( $fails ) && $fails['since'] > time() - SELLA_SEC_FAIL_WINDOW ? $fails : array( 'count' => 0, 'since' => time() );
	++$fails['count'];

	if ( $fails['count'] >= SELLA_SEC_MAX_FAILS ) {
		set_transient( $key . '_lock', time() + SELLA_SEC_LOCKOUT, SELLA_SEC_LOCKOUT );
		delete_transient( $key );
		return;
	}
	set_transient( $key, $fails, SELLA_SEC_FAIL_WINDOW );
}
add_action( 'wp_login_failed', 'sella_sec_count_failure' );

/**
 * A good login starts the count over.
 */
function sella_sec_clear_failures() {
	delete_transient( sella_sec_key() );
}
add_action( 'wp_login', 'sella_sec_clear_failures' );

/*
 * ------------------------------------------------------------------
 * Usernames
 * ------------------------------------------------------------------
 */

/**
 * The REST users list, only for those who edit content (the block editor
 * uses it to pick an author).
 *
 * @param array $endpoints Routes.
 * @return array
 */
function sella_sec_rest_users( $endpoints ) {
	if ( current_user_can( 'edit_posts' ) ) {
		return $endpoints;
	}
	foreach ( array_keys( $endpoints ) as $route ) {
		if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'sella_sec_rest_users' );

/**
 * ?author=N would redirect to the author's archive, whose address is the
 * username: for visitors, the home page instead.
 */
function sella_sec_author_scan() {
	if ( isset( $_GET['author'] ) && ! is_admin() && ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_action( 'init', 'sella_sec_author_scan', 2 );

add_filter( 'wpseo_sitemap_exclude_author', '__return_empty_array' );

/*
 * ------------------------------------------------------------------
 * The rest
 * ------------------------------------------------------------------
 */

add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

// No WordPress or WooCommerce version in the page source.
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

/**
 * The security headers, for pages WordPress builds. Cached pages get the same
 * from the drop-in (sella_pc_security_headers()).
 */
function sella_sec_headers() {
	if ( headers_sent() ) {
		return;
	}
	if ( function_exists( 'sella_pc_security_headers' ) ) {
		sella_pc_security_headers();
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), usb=()' );
	if ( is_ssl() ) {
		header( 'Strict-Transport-Security: max-age=31536000' );
	}
	header_remove( 'X-Powered-By' );
}
add_action( 'send_headers', 'sella_sec_headers' );
add_action( 'admin_init', 'sella_sec_headers' );
add_action( 'login_init', 'sella_sec_headers' );
