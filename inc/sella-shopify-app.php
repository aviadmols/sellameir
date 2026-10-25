<?php
/**
 * The "Sella → WordPress" Shopify app: its install, and the SEO import.
 *
 * The app itself is configuration only (github.com/aviadmols/sella:
 * shopify.app.toml). It has no server of its own; this site is its server.
 * Installing it on the store opens the install address below, which sends
 * the store owner to Shopify's consent screen; Shopify then calls back here
 * with a code, and the site trades it for an access token. Both requests are
 * checked with the app's client secret (HMAC) and a one-time state, and only
 * the store set on the import screen is accepted.
 *
 *   Install (App URL):  /wp-json/sella/v1/shopify/install
 *   Allowed redirect:   /wp-json/sella/v1/shopify/callback
 *
 * The token expires after an hour and is renewed with its refresh token (90
 * days) whenever the import asks for it. sella-shopify-import.php uses it for
 * every API call.
 *
 * The SEO import (job "seo" on the import screen): each product's SEO title
 * and description from Shopify go into Yoast on the matching book here, when
 * Yoast has none yet; and the URL redirects set up in Shopify (Online Store >
 * Navigation > URL Redirects) are added to the legacy redirects.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_SA_AUTH', 'sella_shopify_app_auth' );
define( 'SELLA_SA_SCOPES', 'read_content,read_orders,read_all_orders,read_products,read_online_store_navigation' );
define( 'SELLA_SA_SHOP_RE', '/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/' );

/*
 * ------------------------------------------------------------------
 * Addresses and checks
 * ------------------------------------------------------------------
 */

/**
 * The app's install address (its App URL in shopify.app.toml).
 *
 * @return string
 */
function sella_sa_install_url() {
	return rest_url( 'sella/v1/shopify/install' );
}

/**
 * The address Shopify returns to after consent (its redirect URL).
 *
 * @return string
 */
function sella_sa_callback_url() {
	return rest_url( 'sella/v1/shopify/callback' );
}

/**
 * Whether a request really comes from Shopify: the HMAC of its other
 * parameters, sorted, as key=value joined by &, with the client secret.
 *
 * @param array  $params Query parameters as received.
 * @param string $secret Client secret.
 * @return bool
 */
function sella_sa_hmac_ok( $params, $secret ) {
	if ( '' === $secret || empty( $params['hmac'] ) || ! is_string( $params['hmac'] ) ) {
		return false;
	}
	$hmac = $params['hmac'];
	unset( $params['hmac'], $params['signature'] );
	ksort( $params );
	$pairs = array();
	foreach ( $params as $key => $value ) {
		$pairs[] = $key . '=' . ( is_array( $value ) ? '["' . implode( '", "', $value ) . '"]' : $value );
	}
	return hash_equals( hash_hmac( 'sha256', implode( '&', $pairs ), $secret ), $hmac );
}

/**
 * The store a request names, when it is the one set on the import screen.
 *
 * @param array $params Query parameters.
 * @return string '' when it is missing, malformed or another store.
 */
function sella_sa_request_shop( $params ) {
	$shop = isset( $params['shop'] ) && is_string( $params['shop'] ) ? strtolower( $params['shop'] ) : '';
	if ( ! preg_match( SELLA_SA_SHOP_RE, $shop ) ) {
		return '';
	}
	return sella_si_settings()['shop'] === $shop ? $shop : '';
}

/**
 * A plain page for a failed install, shown to the person installing.
 *
 * @param string $message What went wrong and what to do.
 */
function sella_sa_fail( $message ) {
	wp_die( esc_html( $message ), 'החיבור לשופיפיי נכשל', array( 'response' => 400, 'link_url' => admin_url( 'admin.php?page=' . SELLA_SI_SLUG ), 'link_text' => 'למסך הייבוא' ) );
}

/**
 * Send the store owner to Shopify's consent screen.
 *
 * @param string $shop Store.
 */
function sella_sa_authorize( $shop ) {
	$settings = sella_si_settings();
	$state    = wp_generate_password( 32, false );
	set_transient( 'sella_sa_state_' . $state, $shop, 15 * MINUTE_IN_SECONDS );

	$url = 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query(
		array(
			'client_id'    => $settings['client_id'],
			'scope'        => SELLA_SA_SCOPES,
			'redirect_uri' => sella_sa_callback_url(),
			'state'        => $state,
		),
		'',
		'&'
	);
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Shopify's own consent screen.
	exit;
}

/*
 * ------------------------------------------------------------------
 * Install and callback
 * ------------------------------------------------------------------
 */

/**
 * The two public endpoints. Each checks Shopify's signature itself.
 */
function sella_sa_routes() {
	register_rest_route(
		'sella/v1',
		'/shopify/install',
		array(
			'methods'             => 'GET',
			'callback'            => 'sella_sa_install',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'sella/v1',
		'/shopify/callback',
		array(
			'methods'             => 'GET',
			'callback'            => 'sella_sa_callback',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'sella_sa_routes' );

/**
 * Install: Shopify opens the App URL with a signed shop parameter.
 *
 * @param WP_REST_Request $request Request.
 */
function sella_sa_install( $request ) {
	$params   = $request->get_query_params();
	$settings = sella_si_settings();

	if ( '' === $settings['client_id'] || '' === $settings['client_secret'] ) {
		sella_sa_fail( 'עדיין לא נשמרו באתר ה-Client ID וה-Client secret של האפליקציה. צריך להדביק אותם במסך "ייבוא משופיפיי" ולהתקין שוב.' );
	}
	if ( ! sella_sa_hmac_ok( $params, $settings['client_secret'] ) ) {
		sella_sa_fail( 'החתימה של שופיפיי לא תואמת. כנראה ה-Client secret שנשמר באתר לא שייך לאפליקציה הזו.' );
	}
	$shop = sella_sa_request_shop( $params );
	if ( '' === $shop ) {
		sella_sa_fail( 'האפליקציה הותקנה על חנות אחרת מזו שמוגדרת במסך הייבוא (' . $settings['shop'] . ').' );
	}
	sella_sa_authorize( $shop );
}

/**
 * Callback: check everything, trade the code for a token, keep it.
 *
 * @param WP_REST_Request $request Request.
 */
function sella_sa_callback( $request ) {
	$params   = $request->get_query_params();
	$settings = sella_si_settings();

	if ( ! sella_sa_hmac_ok( $params, $settings['client_secret'] ) ) {
		sella_sa_fail( 'החתימה של שופיפיי לא תואמת.' );
	}
	$shop  = sella_sa_request_shop( $params );
	$state = isset( $params['state'] ) && is_string( $params['state'] ) ? $params['state'] : '';
	if ( '' === $shop || '' === $state || get_transient( 'sella_sa_state_' . $state ) !== $shop ) {
		sella_sa_fail( 'הבקשה פגה או לא התחילה מהאתר הזה. צריך לנסות להתחבר שוב.' );
	}
	delete_transient( 'sella_sa_state_' . $state );

	$response = wp_remote_post(
		'https://' . $shop . '/admin/oauth/access_token',
		array(
			'timeout' => 30,
			'headers' => array( 'Accept' => 'application/json' ),
			'body'    => array(
				'client_id'     => $settings['client_id'],
				'client_secret' => $settings['client_secret'],
				'code'          => isset( $params['code'] ) ? (string) $params['code'] : '',
				'expiring'      => '1',
			),
		)
	);
	$body = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['access_token'] ) ) {
		sella_sa_fail( 'שופיפיי לא החזיר מפתח גישה' . ( is_wp_error( $response ) ? ': ' . $response->get_error_message() : '.' ) );
	}

	sella_sa_store( $shop, $body );
	delete_transient( SELLA_SI_TOKEN );
	wp_safe_redirect( admin_url( 'admin.php?page=' . SELLA_SI_SLUG . '&notice=app-connected' ) );
	exit;
}

/**
 * Keep a token response.
 *
 * @param string $shop Store.
 * @param array  $body Token response.
 */
function sella_sa_store( $shop, $body ) {
	$now = time();
	update_option(
		SELLA_SA_AUTH,
		array(
			'shop'               => $shop,
			'access_token'       => (string) $body['access_token'],
			'refresh_token'      => isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '',
			'expires_at'         => ! empty( $body['expires_in'] ) ? $now + (int) $body['expires_in'] : 0,
			'refresh_expires_at' => ! empty( $body['refresh_token_expires_in'] ) ? $now + (int) $body['refresh_token_expires_in'] : 0,
			'scope'              => isset( $body['scope'] ) ? (string) $body['scope'] : '',
			'connected_at'       => $now,
		),
		false
	);
}

/**
 * Saved app connection, or an empty array.
 *
 * @return array
 */
function sella_sa_auth() {
	$auth = get_option( SELLA_SA_AUTH, array() );
	return is_array( $auth ) && ! empty( $auth['access_token'] ) ? $auth : array();
}

/**
 * The app's access token, renewed when it is about to expire.
 *
 * @return string|WP_Error '' when the app is not connected.
 */
function sella_sa_token() {
	$auth = sella_sa_auth();
	if ( ! $auth ) {
		return '';
	}
	if ( ! $auth['expires_at'] || $auth['expires_at'] > time() + 120 ) {
		return $auth['access_token'];
	}
	if ( '' === $auth['refresh_token'] || ( $auth['refresh_expires_at'] && $auth['refresh_expires_at'] < time() ) ) {
		return new WP_Error( 'sella_sa_expired', 'החיבור לאפליקציה פג. צריך ללחוץ שוב על "חיבור דרך האפליקציה".' );
	}

	$settings = sella_si_settings();
	$response = wp_remote_post(
		'https://' . $auth['shop'] . '/admin/oauth/access_token',
		array(
			'timeout' => 30,
			'headers' => array( 'Accept' => 'application/json' ),
			'body'    => array(
				'client_id'     => $settings['client_id'],
				'client_secret' => $settings['client_secret'],
				'grant_type'    => 'refresh_token',
				'refresh_token' => $auth['refresh_token'],
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response; // The refresh token still holds; the next step tries again.
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['access_token'] ) ) {
		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			delete_option( SELLA_SA_AUTH );
			return new WP_Error( 'sella_sa_revoked', 'שופיפיי ביטל את החיבור לאפליקציה. צריך להתחבר מחדש.' );
		}
		return new WP_Error( 'sella_sa_refresh', 'חידוש מפתח הגישה נכשל (HTTP ' . wp_remote_retrieve_response_code( $response ) . ').' );
	}
	sella_sa_store( $auth['shop'], $body );
	return (string) $body['access_token'];
}

/**
 * Buttons on the import screen: connect (for an app already installed) and
 * disconnect.
 */
function sella_sa_handle_post() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_sa' );
	$what = isset( $_POST['sella_sa'] ) ? sanitize_key( wp_unslash( $_POST['sella_sa'] ) ) : '';
	if ( 'disconnect' === $what ) {
		delete_option( SELLA_SA_AUTH );
		delete_transient( SELLA_SI_TOKEN );
		wp_safe_redirect( admin_url( 'admin.php?page=' . SELLA_SI_SLUG . '&notice=app-disconnected' ) );
		exit;
	}
	$settings = sella_si_settings();
	if ( '' === $settings['client_id'] || '' === $settings['client_secret'] ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . SELLA_SI_SLUG . '&notice=app-missing' ) );
		exit;
	}
	sella_sa_authorize( $settings['shop'] );
}
add_action( 'admin_post_sella_sa', 'sella_sa_handle_post' );

/*
 * ------------------------------------------------------------------
 * SEO import
 * ------------------------------------------------------------------
 */

/**
 * One step of the SEO import: a page of products, then the store's URL
 * redirects.
 *
 * @param array $job Progress, changed in place.
 * @return true|WP_Error
 */
function sella_si_step_seo( &$job ) {
	$cursor = is_array( $job['cursor'] ) ? $job['cursor'] : array(
		'phase' => 'products',
		'after' => null,
	);

	if ( 'products' === $cursor['phase'] ) {
		$data = sella_si_graphql(
			'query($after: String) { products(first: 50, after: $after) { pageInfo { hasNextPage endCursor } nodes { handle title seo { title description } } } }',
			array( 'after' => $cursor['after'] )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		foreach ( array_filter( $data['products']['nodes'] ) as $node ) {
			sella_sa_import_product_seo( $node, $job );
		}
		$info            = $data['products']['pageInfo'];
		$cursor['after'] = $info['endCursor'];
		if ( ! $info['hasNextPage'] ) {
			$cursor = array(
				'phase' => 'redirects',
				'after' => null,
			);
		}
		$job['cursor'] = $cursor;
		return true;
	}

	$data = sella_si_graphql(
		'query($after: String) { urlRedirects(first: 250, after: $after) { pageInfo { hasNextPage endCursor } nodes { path target } } }',
		array( 'after' => $cursor['after'] )
	);
	if ( is_wp_error( $data ) ) {
		// Without read_online_store_navigation the products' SEO still counts.
		sella_si_log( $job, 'error', 'ההפניות משופיפיי לא יובאו: ' . $data->get_error_message() );
		$job['done'] = true;
		return true;
	}

	$map = sella_lr_shopify_redirects();
	foreach ( $data['urlRedirects']['nodes'] as $node ) {
		$map[ strtolower( rtrim( rawurldecode( $node['path'] ), '/' ) ) ] = (string) $node['target'];
	}
	update_option( 'sella_lr_shopify', $map, false );
	sella_si_log( $job, 'ok', count( $data['urlRedirects']['nodes'] ) . ' הפניות יובאו משופיפיי (סה"כ ' . count( $map ) . ').' );

	$info            = $data['urlRedirects']['pageInfo'];
	$cursor['after'] = $info['endCursor'];
	$job['cursor']   = $cursor;
	$job['done']     = ! $info['hasNextPage'];
	return true;
}

/**
 * A product's Shopify SEO into Yoast on the matching book, where Yoast has
 * nothing yet.
 *
 * @param array $node Product: handle, title, seo.
 * @param array $job  Progress, changed in place.
 */
function sella_sa_import_product_seo( $node, &$job ) {
	$title = trim( (string) ( $node['seo']['title'] ?? '' ) );
	$desc  = trim( (string) ( $node['seo']['description'] ?? '' ) );
	if ( '' === $title && '' === $desc ) {
		++$job['skipped'];
		return;
	}

	$id = sella_si_match_product(
		array(
			'handle' => $node['handle'],
			'sku'    => '',
			'name'   => $node['title'],
		)
	);
	if ( ! $id ) {
		++$job['skipped'];
		sella_si_log( $job, 'skip', '«' . $node['title'] . '»: אין ספר תואם באתר.' );
		return;
	}

	$set = array();
	if ( '' !== $title && '' === (string) get_post_meta( $id, '_yoast_wpseo_title', true ) ) {
		update_post_meta( $id, '_yoast_wpseo_title', $title );
		$set[] = 'כותרת';
	}
	if ( '' !== $desc && '' === (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true ) ) {
		update_post_meta( $id, '_yoast_wpseo_metadesc', $desc );
		$set[] = 'תיאור';
	}
	if ( $set ) {
		++$job['imported'];
		sella_si_log( $job, 'ok', '«' . $node['title'] . '»: ' . implode( ' ו', $set ) . ' לגוגל.' );
	} else {
		++$job['skipped'];
	}
}
