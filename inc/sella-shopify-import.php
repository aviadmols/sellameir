<?php
/**
 * Import from the old Shopify store: blog articles and order history.
 *
 * Admin: WooCommerce > ייבוא משופיפיי (?page=sella-shopify-import).
 *
 * Articles come from the Shopify Admin API (GraphQL) and become posts, with
 * their date, tags, featured image, SEO title and description. Every image in
 * them is downloaded into the media library, because Shopify serves them from
 * sellameir.com/cdn/shop/..., which stops working when the domain moves here.
 * Each imported article is recorded in the legacy redirects, so its old URL
 * goes straight to the new post.
 *
 * Orders come from the same API, or from the CSV that Shopify exports (Orders
 * > Export > All orders), for stores whose app was not given read_all_orders.
 * Each becomes a WooCommerce order in its final status, without a status
 * change: no emails, no stock change, no abandoned-cart or other order hooks.
 * The order keeps its Shopify number (shown as S-1001), its lines are linked
 * to the books here where they can be matched, and it is attached to the
 * customer's account when one exists with the same email (WooCommerce attaches
 * the rest when those customers register).
 *
 * The work runs in small steps from the admin screen and can be stopped and
 * resumed. Anything already imported is skipped, so running again is safe.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_SI_SLUG', 'sella-shopify-import' );
define( 'SELLA_SI_SETTINGS', 'sella_shopify_import_settings' );
define( 'SELLA_SI_STATE', 'sella_shopify_import_state' );
define( 'SELLA_SI_TOKEN', 'sella_shopify_import_token' );
define( 'SELLA_SI_API_VERSION', '2026-07' );
define( 'SELLA_SI_DEFAULT_SHOP', 'sellameir-com.myshopify.com' );
define( 'SELLA_SI_ORDER_KEY', '_sella_shopify_order' );
define( 'SELLA_SI_ARTICLE_KEY', '_sella_shopify_article' );
define( 'SELLA_SI_LOG_LIMIT', 200 );
define( 'SELLA_SI_AUTHOR_SLUG', 'bmeskin' ); // "מערכת סלע מאיר".
define( 'SELLA_SI_AUTHOR_FROM', 'tomeronen' );

/*
 * ------------------------------------------------------------------
 * Settings and state
 * ------------------------------------------------------------------
 */

/**
 * Connection settings.
 *
 * @return array{shop: string, client_id: string, client_secret: string, token: string}
 */
function sella_si_settings() {
	$saved = get_option( SELLA_SI_SETTINGS, array() );
	return wp_parse_args(
		is_array( $saved ) ? $saved : array(),
		array(
			'shop'          => SELLA_SI_DEFAULT_SHOP,
			'client_id'     => '',
			'client_secret' => '',
			'token'         => '',
		)
	);
}

/**
 * Progress of each job: articles, orders (API), orders_csv.
 *
 * @return array
 */
function sella_si_state() {
	$state = get_option( SELLA_SI_STATE, array() );
	return is_array( $state ) ? $state : array();
}

/**
 * One job's progress.
 *
 * @param string $job Job name.
 * @return array
 */
function sella_si_job( $job ) {
	$state = sella_si_state();
	return wp_parse_args(
		isset( $state[ $job ] ) ? $state[ $job ] : array(),
		array(
			'cursor'   => null,
			'done'     => false,
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'total'    => 0,
			'orders'   => 0, // CSV: orders in the file.
			'newest'   => 0, // CSV: only this many of the newest, or 0 for all.
			'since'    => 0, // CSV: the creation time those start from.
			'phase'    => '', // CSV: 'scan' while the file's orders are counted.
			'top'      => array(), // CSV, while counting: the newest creation times so far.
			'last'     => '', // CSV, while counting: the order counted last.
			'log'      => array(),
			'file'     => '',
			'header'   => array(),
			'urls'     => array(),
			'dates'    => array(),
			'blogs'    => array(),
			'wait'     => 0,
		)
	);
}

/**
 * Save one job's progress.
 *
 * @param string $job  Job name.
 * @param array  $data Progress.
 */
function sella_si_save_job( $job, $data ) {
	$state         = sella_si_state();
	$data['log']   = array_slice( $data['log'], -SELLA_SI_LOG_LIMIT );
	$state[ $job ] = $data;
	update_option( SELLA_SI_STATE, $state, false );
}

/**
 * Add a line to a job's log.
 *
 * @param array  $job  Progress, changed in place.
 * @param string $type ok, skip or error.
 * @param string $text Line.
 */
function sella_si_log( &$job, $type, $text ) {
	$job['log'][] = array( $type, $text );
}

/*
 * ------------------------------------------------------------------
 * Shopify Admin API
 * ------------------------------------------------------------------
 */

/**
 * Access token: the one pasted in the settings, or one from the app's client
 * ID and secret (client credentials grant, valid for 24 hours).
 *
 * @return string|WP_Error
 */
function sella_si_token() {
	$settings = sella_si_settings();
	if ( '' !== $settings['token'] ) {
		return $settings['token'];
	}

	// The installed app (sella-shopify-app.php), when connected.
	if ( function_exists( 'sella_sa_token' ) ) {
		$app = sella_sa_token();
		if ( is_wp_error( $app ) || '' !== $app ) {
			return $app;
		}
	}

	$cached = get_transient( SELLA_SI_TOKEN );
	if ( $cached ) {
		return $cached;
	}

	if ( '' === $settings['client_id'] || '' === $settings['client_secret'] ) {
		return new WP_Error( 'sella_si_no_credentials', 'חסרים פרטי החיבור לשופיפיי.' );
	}

	$response = wp_remote_post(
		'https://' . $settings['shop'] . '/admin/oauth/access_token',
		array(
			'timeout' => 30,
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $settings['client_id'],
				'client_secret' => $settings['client_secret'],
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['access_token'] ) ) {
		$error = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
		return new WP_Error( 'sella_si_token', 'שופיפיי סירב לתת טוקן: ' . $error );
	}

	$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 120 : HOUR_IN_SECONDS;
	set_transient( SELLA_SI_TOKEN, $body['access_token'], max( 60, $ttl ) );
	return $body['access_token'];
}

/**
 * Run a GraphQL query. Waits and retries when Shopify throttles.
 *
 * Shopify answers a query it can partly fulfil with the data and an error
 * per field it withheld (customer details, without protected customer data
 * access, come back null); those messages go to $partial.
 *
 * @param string     $query     Query.
 * @param array      $variables Variables.
 * @param array|null $partial   Set to the errors that came with the data.
 * @return array|WP_Error The data object.
 */
function sella_si_graphql( $query, $variables = array(), &$partial = null ) {
	$partial = array();
	$token = sella_si_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$settings = sella_si_settings();
	for ( $try = 0; $try < 4; $try++ ) {
		$response = wp_remote_post(
			'https://' . $settings['shop'] . '/admin/api/' . SELLA_SI_API_VERSION . '/graphql.json',
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'           => 'application/json',
					'X-Shopify-Access-Token' => $token,
				),
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => (object) $variables,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			delete_transient( SELLA_SI_TOKEN );
			return new WP_Error( 'sella_si_auth', 'שופיפיי דחה את החיבור (HTTP ' . $code . '). צריך לבדוק את פרטי האפליקציה ואת ההרשאות שלה.' );
		}

		$throttled = 429 === $code;
		foreach ( isset( $body['errors'] ) && is_array( $body['errors'] ) ? $body['errors'] : array() as $error ) {
			if ( isset( $error['extensions']['code'] ) && 'THROTTLED' === $error['extensions']['code'] ) {
				$throttled = true;
			}
		}
		if ( $throttled ) {
			sleep( 2 + $try * 2 );
			continue;
		}

		$messages = array();
		foreach ( isset( $body['errors'] ) ? (array) $body['errors'] : array() as $error ) {
			$messages[] = is_array( $error ) && isset( $error['message'] ) ? $error['message'] : (string) $error;
		}
		if ( $messages && empty( $body['data'] ) ) {
			return new WP_Error( 'sella_si_graphql', implode( ' / ', $messages ) );
		}
		if ( ! isset( $body['data'] ) ) {
			return new WP_Error( 'sella_si_http', 'תשובה לא צפויה משופיפיי (HTTP ' . $code . ').' );
		}
		$partial = array_values( array_unique( $messages ) );
		return $body['data'];
	}

	return new WP_Error( 'sella_si_throttled', 'שופיפיי מגביל כרגע את קצב הבקשות. נסו שוב בעוד דקה.' );
}

/**
 * Connection check: store name, granted scopes, number of orders.
 *
 * @return array|WP_Error
 */
function sella_si_check_connection() {
	$data = sella_si_graphql( '{ shop { name myshopifyDomain } currentAppInstallation { accessScopes { handle } } }' );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$scopes = array();
	foreach ( $data['currentAppInstallation']['accessScopes'] as $scope ) {
		$scopes[] = $scope['handle'];
	}

	$count  = sella_si_graphql( '{ ordersCount(limit: null) { count } }' );
	$orders = is_wp_error( $count ) ? null : (int) $count['ordersCount']['count'];

	return array(
		'shop'   => $data['shop']['name'],
		'domain' => $data['shop']['myshopifyDomain'],
		'scopes' => $scopes,
		'orders' => $orders,
	);
}

/*
 * ------------------------------------------------------------------
 * Articles
 * ------------------------------------------------------------------
 */

/**
 * Articles per step: each one downloads its images.
 */
define( 'SELLA_SI_ARTICLES_PER_STEP', 4 );

/**
 * One page of articles, oldest first.
 *
 * @param string|null $after Cursor.
 * @return array|WP_Error
 */
function sella_si_fetch_articles( $after ) {
	$query = 'query($after: String, $first: Int!) {
		articles(first: $first, after: $after, sortKey: ID) {
			pageInfo { hasNextPage endCursor }
			nodes {
				id handle title body summary tags isPublished publishedAt createdAt
				author { name }
				image { url altText }
				blog { handle title }
				seoTitle: metafield(namespace: "global", key: "title_tag") { value }
				seoDescription: metafield(namespace: "global", key: "description_tag") { value }
			}
		}
	}';
	return sella_si_graphql(
		$query,
		array(
			'after' => $after,
			'first' => SELLA_SI_ARTICLES_PER_STEP,
		)
	);
}

/**
 * Post already made from this Shopify article.
 *
 * @param string $shopify_id Article GID.
 * @return int Post ID or 0.
 */
function sella_si_existing_post( $shopify_id ) {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'meta_key'       => SELLA_SI_ARTICLE_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $shopify_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Post already made from the article at /blogs/<blog>/<handle>, by either
 * import (the API or the public site).
 *
 * @param string $path "<blog>/<handle>", decoded.
 * @return int Post ID or 0.
 */
function sella_si_post_by_handle( $path ) {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'meta_key'       => '_sella_shopify_handle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $path, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Download an image into the media library, attached to a post.
 *
 * @param string $url     Image URL (may start with //).
 * @param int    $post_id Post.
 * @param string $alt     Alt text.
 * @return int|WP_Error Attachment ID.
 */
function sella_si_sideload( $url, $post_id, $alt = '' ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	if ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	}

	$tmp = download_url( $url, 60 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	$name = rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $name ) ) {
		$name .= '.jpg';
	}

	$id = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp,
		),
		$post_id
	);
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return $id;
	}
	if ( '' !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	return $id;
}

/**
 * Move the images in an article body into the media library and point the
 * body at them. An image that cannot be downloaded keeps its old address and
 * is reported.
 *
 * @param string $html    Body.
 * @param int    $post_id Post the images attach to.
 * @param array  $errors  Failed image URLs, added to.
 * @return string
 */
function sella_si_localize_images( $html, $post_id, &$errors ) {
	$pattern = '#(?:https?:)?//(?:cdn\.shopify\.com|(?:www\.)?sellameir\.com/cdn/shop)/[^"\'\s)<>]+#i';
	if ( ! preg_match_all( $pattern, $html, $matches ) ) {
		return $html;
	}

	$done = array();
	foreach ( array_unique( $matches[0] ) as $found ) {
		$url = html_entity_decode( $found, ENT_QUOTES, 'UTF-8' );
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?|$)/i', $url ) ) {
			continue; // A link to a file or a script, not an image.
		}
		$key = strtok( $url, '?' );
		if ( ! isset( $done[ $key ] ) ) {
			$id           = sella_si_sideload( $url, $post_id );
			$done[ $key ] = is_wp_error( $id ) ? '' : (string) wp_get_attachment_url( $id );
			if ( '' === $done[ $key ] ) {
				$errors[] = $url;
			}
		}
		if ( '' !== $done[ $key ] ) {
			$html = str_replace( $found, $done[ $key ], $html );
		}
	}
	return $html;
}

/**
 * The author imported articles are published under: the "מערכת סלע מאיר"
 * user (user name bmeskin), else whoever runs the import.
 *
 * @return int
 */
function sella_si_author_id() {
	$user = get_user_by( 'slug', SELLA_SI_AUTHOR_SLUG );
	return $user ? (int) $user->ID : get_current_user_id();
}

/**
 * Articles imported before the author was set came in under the person who
 * ran the import (tomeronen). Move every post of theirs to "מערכת סלע מאיר",
 * 40 per admin page load, through wp_update_post so Yoast's author data
 * follows.
 */
function sella_si_move_post_authors() {
	if ( get_option( 'sella_si_authors_moved' ) || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
		return;
	}
	$from = get_user_by( 'slug', SELLA_SI_AUTHOR_FROM );
	$to   = get_user_by( 'slug', SELLA_SI_AUTHOR_SLUG );
	if ( ! $from || ! $to ) {
		return;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'author'         => $from->ID,
			'fields'         => 'ids',
			'posts_per_page' => 40,
		)
	);
	foreach ( $ids as $id ) {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $to->ID,
			)
		);
	}
	if ( count( $ids ) < 40 ) {
		update_option( 'sella_si_authors_moved', gmdate( 'c' ), false );
	}
}
add_action( 'admin_init', 'sella_si_move_post_authors' );

/**
 * Category for a Shopify blog, made when missing.
 *
 * @param array $blog handle and title.
 * @return int Term ID.
 */
function sella_si_blog_category( $blog ) {
	$name = '' !== trim( (string) $blog['title'] ) ? $blog['title'] : $blog['handle'];
	$term = term_exists( $name, 'category' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'category' );
	}
	return is_wp_error( $term ) ? (int) get_option( 'default_category' ) : (int) $term['term_id'];
}

/**
 * Turn one Shopify article into a post.
 *
 * @param array $node Article from the API.
 * @param array $job  Progress, changed in place.
 */
function sella_si_import_article( $node, &$job ) {
	$label = '«' . $node['title'] . '»';

	if ( sella_si_existing_post( $node['id'] ) || sella_si_post_by_handle( $node['blog']['handle'] . '/' . $node['handle'] ) ) {
		++$job['skipped'];
		sella_si_log( $job, 'skip', $label . ' כבר יובא.' );
		return;
	}

	$date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $node['publishedAt'] ? $node['publishedAt'] : $node['createdAt'] ) );

	$post_id = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => $node['isPublished'] ? 'publish' : 'draft',
			'post_title'    => $node['title'],
			'post_name'     => $node['handle'],
			'post_content'  => '',
			'post_excerpt'  => wp_strip_all_tags( (string) $node['summary'] ),
			'post_date'     => get_date_from_gmt( $date_gmt ),
			'post_date_gmt' => $date_gmt,
			'post_author'   => sella_si_author_id(),
			'post_category' => array( sella_si_blog_category( $node['blog'] ) ),
			'tags_input'    => $node['tags'],
			'meta_input'    => array(
				SELLA_SI_ARTICLE_KEY      => $node['id'],
				'_sella_shopify_handle'   => $node['blog']['handle'] . '/' . $node['handle'],
				'_sella_shopify_author'   => isset( $node['author']['name'] ) ? $node['author']['name'] : '',
			),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		++$job['failed'];
		sella_si_log( $job, 'error', $label . ': ' . $post_id->get_error_message() );
		return;
	}

	// The images need the post to attach to, so the body goes in second.
	$image_errors = array();
	$body         = sella_si_localize_images( (string) $node['body'], $post_id, $image_errors );
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $body,
		)
	);

	if ( ! empty( $node['image']['url'] ) ) {
		$thumb = sella_si_sideload( $node['image']['url'], $post_id, (string) $node['image']['altText'] );
		if ( is_wp_error( $thumb ) ) {
			$image_errors[] = $node['image']['url'];
		} else {
			set_post_thumbnail( $post_id, $thumb );
		}
	}

	if ( ! empty( $node['seoTitle']['value'] ) ) {
		update_post_meta( $post_id, '_yoast_wpseo_title', $node['seoTitle']['value'] );
	}
	if ( ! empty( $node['seoDescription']['value'] ) ) {
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $node['seoDescription']['value'] );
	}

	sella_lr_record_move( '/blogs/' . $node['blog']['handle'] . '/' . $node['handle'], $post_id );

	++$job['imported'];
	if ( $image_errors ) {
		sella_si_log( $job, 'error', $label . ' יובא, אבל ' . count( $image_errors ) . ' תמונות לא ירדו: ' . implode( ' ', $image_errors ) );
	} else {
		sella_si_log( $job, 'ok', $label . ' יובא.' );
	}
}

/**
 * One step of the article import.
 *
 * @param array $job Progress, changed in place.
 * @return true|WP_Error
 */
function sella_si_step_articles( &$job ) {
	$data = sella_si_fetch_articles( $job['cursor'] );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	if ( ! isset( $data['articles']['nodes'] ) ) {
		return new WP_Error( 'sella_si_articles', 'שופיפיי לא החזיר מאמרים. כנראה לאפליקציה חסרה ההרשאה read_content.' );
	}

	foreach ( array_filter( $data['articles']['nodes'] ) as $node ) {
		sella_si_import_article( $node, $job );
	}

	$page          = $data['articles']['pageInfo'];
	$job['cursor'] = $page['endCursor'] ? $page['endCursor'] : $job['cursor'];
	$job['done']   = ! $page['hasNextPage'];
	return true;
}

/*
 * ------------------------------------------------------------------
 * Articles from the public site, without an app
 * ------------------------------------------------------------------
 *
 * The article list comes from the store's sitemap, and each article is read
 * from its page in the old theme: og:title, the body in div.post-des, the
 * full-size image in og:image, tags in div.tags. The page shows the date
 * without a year ("12 מאי 17:16"); the year comes from the image's upload
 * time (?v=<timestamp>), else from the sitemap's last change. The blog's
 * Atom feed has exact dates for its latest 30 articles, and they win.
 *
 * Shopify answers fast crawling with 429 or a "Verifying your connection"
 * page; the step then stops and the screen waits a minute before going on.
 */

define( 'SELLA_SI_SITE', 'https://sellameir.com' );
define( 'SELLA_SI_SITE_PER_STEP', 3 );
define( 'SELLA_SI_SITE_WAIT', 60 );

/**
 * Fetch a page of the old store.
 *
 * @param string $url Full URL or a path.
 * @return array{code: int, body: string, blocked: bool}|WP_Error
 */
function sella_si_site_get( $url ) {
	if ( 0 === strpos( $url, '/' ) ) {
		$url = SELLA_SI_SITE . $url;
	}
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 30,
			'headers' => array(
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
				'Accept-Language' => 'he-IL,he;q=0.9',
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	return array(
		'code'    => $code,
		'body'    => $body,
		'blocked' => 429 === $code || 503 === $code || false !== strpos( $body, 'Verifying your connection' ),
	);
}

/**
 * First step: the article URLs from the sitemap, and exact dates and blog
 * names from each blog's Atom feed.
 *
 * @param array $job Progress, changed in place.
 * @return true|WP_Error
 */
function sella_si_site_prepare( &$job ) {
	$index = sella_si_site_get( '/sitemap.xml' );
	if ( is_wp_error( $index ) ) {
		return $index;
	}
	if ( $index['blocked'] ) {
		$job['wait'] = SELLA_SI_SITE_WAIT;
		return true;
	}

	$urls = array();
	preg_match_all( '#<loc>\s*([^<]*sitemap_articles[^<]*)\s*</loc>#', $index['body'], $maps );
	foreach ( $maps[1] as $map_url ) {
		$map = sella_si_site_get( html_entity_decode( $map_url ) );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		if ( $map['blocked'] ) {
			$job['wait'] = SELLA_SI_SITE_WAIT;
			return true;
		}
		preg_match_all( '#<url>(.*?)</url>#s', $map['body'], $entries );
		foreach ( $entries[1] as $entry ) {
			if ( preg_match( '#<loc>\s*([^<]+?)\s*</loc>#', $entry, $loc ) && preg_match( '#/blogs/[^/]+/[^/?]+#', $loc[1] ) ) {
				$lastmod = preg_match( '#<lastmod>([^<]+)</lastmod>#', $entry, $lm ) ? (int) strtotime( $lm[1] ) : 0;
				$urls[]  = array( html_entity_decode( $loc[1] ), $lastmod );
			}
		}
	}
	if ( ! $urls ) {
		return new WP_Error( 'sella_si_site', 'לא נמצאו מאמרים ב-sitemap של האתר הישן.' );
	}

	$dates = array();
	$blogs = array();
	foreach ( array_unique( array_map( 'sella_si_site_blog', wp_list_pluck( $urls, 0 ) ) ) as $blog ) {
		$feed = sella_si_site_get( '/blogs/' . rawurlencode( $blog ) . '.atom' );
		if ( is_wp_error( $feed ) || $feed['blocked'] || 200 !== $feed['code'] ) {
			continue;
		}
		if ( preg_match( '#<title>([^<]+)</title>#', $feed['body'], $t ) ) {
			$name           = trim( preg_replace( '/^.*?[-–:]\s*/u', '', html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' ) ) );
			$blogs[ $blog ] = '' !== $name ? $name : $blog;
		}
		preg_match_all( '#<entry>(.*?)</entry>#s', $feed['body'], $items );
		foreach ( $items[1] as $item ) {
			if ( preg_match( '#<link[^>]+href="([^"]+)"#', $item, $l ) && preg_match( '#<published>([^<]+)</published>#', $item, $p ) ) {
				$dates[ sella_si_site_key( $l[1] ) ] = (int) strtotime( $p[1] );
			}
		}
	}

	$job['urls']   = $urls;
	$job['dates']  = $dates;
	$job['blogs']  = $blogs;
	$job['total']  = count( $urls );
	$job['cursor'] = 0;
	sella_si_log( $job, 'ok', 'נמצאו ' . count( $urls ) . ' מאמרים באתר הישן.' );
	return true;
}

/**
 * "<blog>/<handle>", decoded, for an article URL.
 *
 * @param string $url Article URL.
 * @return string
 */
function sella_si_site_key( $url ) {
	$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	return preg_match( '#/blogs/([^/]+)/([^/]+)#', $path, $m ) ? $m[1] . '/' . $m[2] : '';
}

/**
 * The blog handle of an article URL.
 *
 * @param string $url Article URL.
 * @return string
 */
function sella_si_site_blog( $url ) {
	return strtok( sella_si_site_key( $url ), '/' );
}

/**
 * Inner HTML of the first <div class="$class">, nested divs included.
 *
 * @param string $html  Page.
 * @param string $class Exact class attribute.
 * @return string
 */
function sella_si_inner_div( $html, $class ) {
	$start = strpos( $html, '<div class="' . $class . '"' );
	if ( false === $start ) {
		return '';
	}
	$from  = strpos( $html, '>', $start ) + 1;
	$pos   = $from;
	$depth = 1;
	while ( preg_match( '#<(/?)div\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
		$depth += '/' === $m[1][0] ? -1 : 1;
		$pos    = $m[0][1] + strlen( $m[0][0] );
		if ( 0 === $depth ) {
			return trim( substr( $html, $from, $m[0][1] - $from ) );
		}
	}
	return '';
}

/**
 * Publication time from the page's "12 מאי 17:16", with the year taken from
 * the image's upload time when it falls near that day, else the latest year
 * that is not after the sitemap's last change.
 *
 * @param string $text     Date text from the page.
 * @param int    $image_ts Image upload time, or 0.
 * @param int    $lastmod  Sitemap last change, or 0.
 * @return int Timestamp, 0 when the text cannot be read.
 */
function sella_si_site_date( $text, $image_ts, $lastmod ) {
	$months = array( 'ינו' => 1, 'פבר' => 2, 'מרץ' => 3, 'מרס' => 3, 'אפר' => 4, 'מאי' => 5, 'יונ' => 6, 'יול' => 7, 'אוג' => 8, 'ספט' => 9, 'אוק' => 10, 'נוב' => 11, 'דצמ' => 12 );
	if ( ! preg_match( '/(\d{1,2})\s+(\S+?)[׳\'.]?\s+(\d{1,2}):(\d{2})/u', $text, $m ) ) {
		return 0;
	}
	$month = 0;
	foreach ( $months as $prefix => $number ) {
		if ( 0 === strpos( $m[2], $prefix ) ) {
			$month = $number;
		}
	}
	if ( ! $month ) {
		return 0;
	}

	$tz = new DateTimeZone( 'Asia/Jerusalem' );
	$at = function ( $year ) use ( $m, $month, $tz ) {
		return ( new DateTime( sprintf( '%04d-%02d-%02d %02d:%02d', $year, $month, (int) $m[1], (int) $m[3], (int) $m[4] ), $tz ) )->getTimestamp();
	};

	if ( $image_ts ) {
		$year = (int) gmdate( 'Y', $image_ts );
		foreach ( array( $year, $year - 1, $year + 1 ) as $candidate ) {
			if ( abs( $at( $candidate ) - $image_ts ) < 45 * DAY_IN_SECONDS ) {
				return $at( $candidate );
			}
		}
	}

	$year = (int) gmdate( 'Y', $lastmod ? $lastmod : time() );
	$ts   = $at( $year );
	return $ts > ( $lastmod ? $lastmod : time() ) + DAY_IN_SECONDS ? $at( $year - 1 ) : $ts;
}

/**
 * Read one article page into the shape the API import uses.
 *
 * @param string $url     Article URL.
 * @param string $html    Page.
 * @param int    $lastmod Sitemap last change.
 * @param array  $job     Progress (dates and blog names from the feeds).
 * @return array|WP_Error
 */
function sella_si_site_article( $url, $html, $lastmod, $job ) {
	$meta = function ( $attr, $name ) use ( $html ) {
		return preg_match( '#<meta\s+' . $attr . '="' . preg_quote( $name, '#' ) . '"\s+content="([^"]*)"#', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) : '';
	};

	$key   = sella_si_site_key( $url );
	$blog  = strtok( $key, '/' );
	$title = $meta( 'property', 'og:title' );
	$body  = sella_si_inner_div( $html, 'post-des' );
	if ( '' === $title || '' === $body ) {
		return new WP_Error( 'sella_si_page', 'לא נמצאו כותרת או תוכן בעמוד.' );
	}

	$image    = preg_replace( '#^http://#', 'https://', $meta( 'property', 'og:image:secure_url' ) ? $meta( 'property', 'og:image:secure_url' ) : $meta( 'property', 'og:image' ) );
	$image_ts = preg_match( '/[?&]v=(\d{9,11})/', $image, $v ) ? (int) $v[1] : 0;

	if ( isset( $job['dates'][ $key ] ) ) {
		$published = $job['dates'][ $key ];
	} else {
		$text      = preg_match( '#בתאריך(?:&nbsp;|\s)*<span>([^<]+)</span>#u', $html, $d ) ? html_entity_decode( $d[1], ENT_QUOTES, 'UTF-8' ) : '';
		$published = sella_si_site_date( $text, $image_ts, $lastmod );
		if ( ! $published ) {
			$published = $lastmod ? $lastmod : time();
		}
	}

	$tags = array();
	if ( preg_match_all( '#<a[^>]*>([^<]+)</a>#', sella_si_inner_div( $html, 'tags' ), $t ) ) {
		$tags = array_values( array_filter( array_map( 'trim', array_map( 'html_entity_decode', $t[1] ) ) ) );
	}

	$page_title = preg_match( '#<title>\s*([^<]+?)\s*</title>#', $html, $pt ) ? html_entity_decode( $pt[1], ENT_QUOTES, 'UTF-8' ) : '';
	$page_title = trim( preg_replace( '/\s*[–|-]\s*סלע מאיר\s*$/u', '', $page_title ) );

	return array(
		'id'             => 'site:' . $key,
		'handle'         => substr( $key, strlen( $blog ) + 1 ),
		'title'          => $title,
		'body'           => $body,
		'summary'        => '',
		'tags'           => $tags,
		'isPublished'    => true,
		'publishedAt'    => gmdate( 'c', $published ),
		'createdAt'      => gmdate( 'c', $published ),
		'author'         => null,
		'image'          => '' !== $image ? array( 'url' => $image, 'altText' => $meta( 'property', 'og:image:alt' ) ) : null,
		'blog'           => array(
			'handle' => $blog,
			'title'  => isset( $job['blogs'][ $blog ] ) ? $job['blogs'][ $blog ] : $blog,
		),
		'seoTitle'       => '' !== $page_title && $page_title !== $title ? array( 'value' => $page_title ) : null,
		'seoDescription' => array( 'value' => $meta( 'name', 'description' ) ),
	);
}

/**
 * One step of the import from the public site.
 *
 * @param array $job Progress, changed in place.
 * @return true|WP_Error
 */
function sella_si_step_articles_site( &$job ) {
	if ( empty( $job['urls'] ) ) {
		return sella_si_site_prepare( $job );
	}

	$i    = (int) $job['cursor'];
	$done = 0;
	while ( $i < count( $job['urls'] ) && $done < SELLA_SI_SITE_PER_STEP ) {
		list( $url, $lastmod ) = $job['urls'][ $i ];
		$key                   = sella_si_site_key( $url );

		if ( sella_si_post_by_handle( $key ) ) {
			++$job['skipped'];
			++$i;
			continue; // No need to fetch it.
		}

		$page = sella_si_site_get( $url );
		if ( ! is_wp_error( $page ) && $page['blocked'] ) {
			$job['wait'] = SELLA_SI_SITE_WAIT;
			break; // Same article again after the pause.
		}

		if ( is_wp_error( $page ) || 200 !== $page['code'] ) {
			++$job['failed'];
			sella_si_log( $job, 'error', $key . ': ' . ( is_wp_error( $page ) ? $page->get_error_message() : 'HTTP ' . $page['code'] ) );
		} else {
			$node = sella_si_site_article( $url, $page['body'], $lastmod, $job );
			if ( is_wp_error( $node ) ) {
				++$job['failed'];
				sella_si_log( $job, 'error', $key . ': ' . $node->get_error_message() );
			} else {
				sella_si_import_article( $node, $job );
			}
		}

		++$i;
		++$done;
		sleep( 1 );
	}

	$job['cursor'] = $i;
	$job['done']   = $i >= count( $job['urls'] );
	return true;
}

/*
 * ------------------------------------------------------------------
 * Orders: one shape for both sources
 * ------------------------------------------------------------------
 *
 * An order, from the API or the CSV, is first turned into this array:
 *   name, created, paid, completed, cancelled (timestamps or 0),
 *   financial (PAID, REFUNDED...), fulfillment, currency, email, phone, note,
 *   tags, payment, discount_codes, subtotal, shipping, discount, tax, total,
 *   refunded, shipping_lines [title, total], billing and shipping addresses
 *   in WooCommerce's keys, items [name, quantity, sku, handle, subtotal, total].
 */

/**
 * Orders per API request. Shopify caps a query at 1,000 cost points; ten
 * orders with up to 50 lines each stay well under it.
 */
define( 'SELLA_SI_ORDERS_PER_PAGE', 10 );
define( 'SELLA_SI_ORDER_PAGES_PER_STEP', 1 );
define( 'SELLA_SI_CSV_ORDERS_PER_STEP', 15 );

/**
 * Amount from a Shopify MoneyBag.
 *
 * @param array|null $set MoneyBag.
 * @return float
 */
function sella_si_money( $set ) {
	return isset( $set['shopMoney']['amount'] ) ? (float) $set['shopMoney']['amount'] : 0.0;
}

/**
 * WooCommerce address from a Shopify MailingAddress.
 *
 * @param array|null $a Address.
 * @return array
 */
function sella_si_address( $a ) {
	$a = is_array( $a ) ? $a : array();
	$v = function ( $key ) use ( $a ) {
		return isset( $a[ $key ] ) ? (string) $a[ $key ] : '';
	};
	return array(
		'first_name' => $v( 'firstName' ),
		'last_name'  => $v( 'lastName' ),
		'company'    => $v( 'company' ),
		'address_1'  => $v( 'address1' ),
		'address_2'  => $v( 'address2' ),
		'city'       => $v( 'city' ),
		'state'      => $v( 'province' ),
		'postcode'   => $v( 'zip' ),
		'country'    => $v( 'countryCodeV2' ),
		'phone'      => $v( 'phone' ),
	);
}

/**
 * Order from the API, in the common shape.
 *
 * @param array $n Order node.
 * @return array
 */
function sella_si_order_from_api( $n ) {
	$ts = function ( $value ) {
		return $value ? (int) strtotime( $value ) : 0;
	};

	$items = array();
	foreach ( $n['lineItems']['nodes'] as $line ) {
		$qty     = (int) $line['quantity'];
		$items[] = array(
			'name'     => $line['name'],
			'quantity' => $qty,
			'sku'      => (string) $line['sku'],
			'handle'   => isset( $line['product']['handle'] ) ? $line['product']['handle'] : '',
			'subtotal' => sella_si_money( $line['originalUnitPriceSet'] ) * $qty,
			'total'    => sella_si_money( $line['discountedTotalSet'] ),
		);
	}

	$shipping_lines = array();
	foreach ( $n['shippingLines']['nodes'] as $line ) {
		$shipping_lines[] = array(
			'title' => $line['title'],
			'total' => sella_si_money( $line['originalPriceSet'] ),
		);
	}

	$paid = in_array( $n['displayFinancialStatus'], array( 'PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', 'PARTIALLY_PAID' ), true );

	return array(
		'name'           => $n['name'],
		'created'        => $ts( $n['createdAt'] ),
		'paid'           => $paid ? $ts( $n['processedAt'] ) : 0,
		'completed'      => $ts( $n['closedAt'] ),
		'cancelled'      => $ts( $n['cancelledAt'] ),
		'financial'      => (string) $n['displayFinancialStatus'],
		'fulfillment'    => (string) $n['displayFulfillmentStatus'],
		'currency'       => $n['currencyCode'],
		'email'          => (string) $n['email'],
		'phone'          => (string) $n['phone'],
		'note'           => (string) $n['note'],
		'tags'           => $n['tags'],
		'payment'        => implode( ', ', $n['paymentGatewayNames'] ),
		'discount_codes' => $n['discountCodes'],
		'subtotal'       => sella_si_money( $n['subtotalPriceSet'] ),
		'shipping'       => sella_si_money( $n['totalShippingPriceSet'] ),
		'discount'       => sella_si_money( $n['totalDiscountsSet'] ),
		'tax'            => sella_si_money( $n['totalTaxSet'] ),
		'total'          => sella_si_money( $n['totalPriceSet'] ),
		'refunded'       => sella_si_money( $n['totalRefundedSet'] ),
		'shipping_lines' => $shipping_lines,
		'billing'        => sella_si_address( $n['billingAddress'] ),
		'shipping_addr'  => sella_si_address( $n['shippingAddress'] ),
		'items'          => $items,
		'more_items'     => ! empty( $n['lineItems']['pageInfo']['hasNextPage'] ),
		'test'           => ! empty( $n['test'] ),
	);
}

/**
 * Order from the rows of Shopify's orders CSV (one row per line item; the
 * order's own columns are filled on its first row only).
 *
 * @param array $rows Rows keyed by column name.
 * @return array
 */
function sella_si_order_from_csv( $rows ) {
	$first = $rows[0];
	$col   = function ( $key ) use ( $first ) {
		return isset( $first[ $key ] ) ? trim( (string) $first[ $key ] ) : '';
	};
	$ts    = function ( $value ) {
		return '' !== $value ? (int) strtotime( $value ) : 0;
	};
	$addr  = function ( $prefix ) use ( $col ) {
		$name = preg_split( '/\s+/u', $col( $prefix . ' Name' ), 2 );
		return array(
			'first_name' => isset( $name[0] ) ? $name[0] : '',
			'last_name'  => isset( $name[1] ) ? $name[1] : '',
			'company'    => $col( $prefix . ' Company' ),
			'address_1'  => '' !== $col( $prefix . ' Address1' ) ? $col( $prefix . ' Address1' ) : $col( $prefix . ' Street' ),
			'address_2'  => $col( $prefix . ' Address2' ),
			'city'       => $col( $prefix . ' City' ),
			'state'      => $col( $prefix . ' Province' ),
			'postcode'   => ltrim( $col( $prefix . ' Zip' ), "'" ),
			'country'    => $col( $prefix . ' Country' ),
			'phone'      => ltrim( $col( $prefix . ' Phone' ), "'" ),
		);
	};

	$items = array();
	foreach ( $rows as $row ) {
		if ( '' === trim( (string) $row['Lineitem name'] ) ) {
			continue;
		}
		$qty      = (int) $row['Lineitem quantity'];
		$subtotal = (float) $row['Lineitem price'] * $qty;
		$items[]  = array(
			'name'     => $row['Lineitem name'],
			'quantity' => $qty,
			'sku'      => isset( $row['Lineitem sku'] ) ? ltrim( (string) $row['Lineitem sku'], "'" ) : '',
			'handle'   => '',
			'subtotal' => $subtotal,
			'total'    => $subtotal - ( isset( $row['Lineitem discount'] ) ? (float) $row['Lineitem discount'] : 0 ),
		);
	}

	$financial = strtoupper( $col( 'Financial Status' ) );
	$codes     = '' !== $col( 'Discount Code' ) ? array_map( 'trim', explode( ',', $col( 'Discount Code' ) ) ) : array();

	return array(
		'name'           => $col( 'Name' ),
		'created'        => $ts( $col( 'Created at' ) ),
		'paid'           => $ts( $col( 'Paid at' ) ),
		'completed'      => $ts( $col( 'Fulfilled at' ) ),
		'cancelled'      => $ts( $col( 'Cancelled at' ) ),
		'financial'      => $financial,
		'fulfillment'    => strtoupper( $col( 'Fulfillment Status' ) ),
		'currency'       => '' !== $col( 'Currency' ) ? $col( 'Currency' ) : get_woocommerce_currency(),
		'email'          => $col( 'Email' ),
		'phone'          => ltrim( $col( 'Phone' ), "'" ),
		'note'           => $col( 'Notes' ),
		'tags'           => '' !== $col( 'Tags' ) ? array_map( 'trim', explode( ',', $col( 'Tags' ) ) ) : array(),
		'payment'        => $col( 'Payment Method' ),
		'discount_codes' => $codes,
		'subtotal'       => (float) $col( 'Subtotal' ),
		'shipping'       => (float) $col( 'Shipping' ),
		'discount'       => (float) $col( 'Discount Amount' ),
		'tax'            => (float) $col( 'Taxes' ),
		'total'          => (float) $col( 'Total' ),
		'refunded'       => (float) $col( 'Refunded Amount' ),
		'shipping_lines' => '' !== $col( 'Shipping Method' ) || (float) $col( 'Shipping' ) > 0 ? array(
			array(
				'title' => '' !== $col( 'Shipping Method' ) ? $col( 'Shipping Method' ) : 'משלוח',
				'total' => (float) $col( 'Shipping' ),
			),
		) : array(),
		'billing'        => $addr( 'Billing' ),
		'shipping_addr'  => $addr( 'Shipping' ),
		'items'          => $items,
		'more_items'     => false,
		'test'           => false,
	);
}

/*
 * ------------------------------------------------------------------
 * Orders: into WooCommerce
 * ------------------------------------------------------------------
 */

/**
 * The book here for a Shopify order line: by the old product handle (through
 * the redirect map), then SKU, then title.
 *
 * @param array $item Line.
 * @return int Product ID or 0.
 */
function sella_si_match_product( $item ) {
	static $titles = null;

	if ( '' !== $item['handle'] && function_exists( 'sella_lr_map' ) ) {
		$map = sella_lr_map();
		$key = '/products/' . strtolower( $item['handle'] );
		if ( isset( $map[ $key ] ) && ! $map[ $key ][1] && preg_match( '#^/product/([^/]+)/?$#', $map[ $key ][0], $m ) ) {
			$post = get_page_by_path( $m[1], OBJECT, 'product' );
			if ( $post ) {
				return (int) $post->ID;
			}
		}
	}

	if ( '' !== $item['sku'] ) {
		$id = wc_get_product_id_by_sku( $item['sku'] );
		if ( $id ) {
			return (int) $id;
		}
	}

	if ( null === $titles ) {
		$titles = array();
		foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1 ) ) as $product ) {
			$titles[ sella_si_title_key( $product->post_title ) ] = (int) $product->ID;
		}
	}
	$key = sella_si_title_key( $item['name'] );
	return isset( $titles[ $key ] ) ? $titles[ $key ] : 0;
}

/**
 * Title reduced for matching: no punctuation, no "– הדפסה בהזמנה אישית POD".
 *
 * @param string $title Title.
 * @return string
 */
function sella_si_title_key( $title ) {
	$title = html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );
	$title = preg_replace( '/\s+[-–]\s+(הדפסה בהזמנה אישית.*|מהדורה.*)$/u', '', $title );
	$title = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $title );
	return trim( preg_replace( '/\s+/u', ' ', $title ) );
}

/**
 * WooCommerce status for a Shopify order. Old orders that were never paid are
 * cancelled; recent ones wait on hold.
 *
 * @param array $o Order.
 * @return string
 */
function sella_si_order_status( $o ) {
	if ( $o['cancelled'] || in_array( $o['financial'], array( 'VOIDED', 'EXPIRED' ), true ) ) {
		return 'cancelled';
	}
	if ( 'REFUNDED' === $o['financial'] ) {
		return 'refunded';
	}
	if ( in_array( $o['financial'], array( 'PAID', 'PARTIALLY_REFUNDED', 'PARTIALLY_PAID' ), true ) ) {
		return 'completed';
	}
	return $o['created'] < time() - 60 * DAY_IN_SECONDS ? 'cancelled' : 'on-hold';
}

/**
 * WooCommerce order already made from this Shopify order.
 *
 * @param string $name Shopify order name (#1001), the same in the API and CSV.
 * @return int
 */
function sella_si_existing_order( $name ) {
	$ids = wc_get_orders(
		array(
			'limit'      => 1,
			'return'     => 'ids',
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => SELLA_SI_ORDER_KEY,
					'value' => $name,
				),
			),
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Whether an order has who and where: a name, an email or an address.
 *
 * @param array  $billing  Billing fields.
 * @param array  $shipping Shipping fields.
 * @param string $email    Email.
 * @return bool
 */
function sella_si_has_customer( $billing, $shipping, $email ) {
	foreach ( array( $billing, $shipping ) as $a ) {
		if ( '' !== $a['first_name'] || '' !== $a['last_name'] || '' !== $a['address_1'] ) {
			return true;
		}
	}
	return '' !== $email;
}

/**
 * The customer's details on a WooCommerce order: billing, shipping, email,
 * and the account with that email.
 *
 * @param WC_Order $order Order.
 * @param array    $o     Shopify order, in the common shape.
 */
function sella_si_set_customer( $order, $o ) {
	$billing = $o['billing'];
	if ( '' === $billing['phone'] ) {
		$billing['phone'] = $o['phone'];
	}
	$billing['email'] = $o['email'];
	foreach ( $billing as $key => $value ) {
		$order->{'set_billing_' . $key}( $value );
	}
	foreach ( $o['shipping_addr'] as $key => $value ) {
		$order->{'set_shipping_' . $key}( $value );
	}

	if ( '' !== $o['email'] ) {
		$user = get_user_by( 'email', $o['email'] );
		if ( $user ) {
			$order->set_customer_id( $user->ID );
		}
	}
}

/**
 * An order imported before without its customer (the API withheld the
 * details) gets them now, when this run has them.
 *
 * @param int   $order_id WooCommerce order.
 * @param array $o        Shopify order.
 * @return bool Whether the details were added.
 */
function sella_si_fill_customer( $order_id, $o ) {
	if ( ! sella_si_has_customer( $o['billing'], $o['shipping_addr'], $o['email'] ) ) {
		return false;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}
	$fields = function ( $type ) use ( $order ) {
		return array(
			'first_name' => $order->{'get_' . $type . '_first_name'}(),
			'last_name'  => $order->{'get_' . $type . '_last_name'}(),
			'address_1'  => $order->{'get_' . $type . '_address_1'}(),
		);
	};
	if ( sella_si_has_customer( $fields( 'billing' ), $fields( 'shipping' ), $order->get_billing_email() ) ) {
		return false;
	}
	sella_si_set_customer( $order, $o );
	$order->save();
	return true;
}

/**
 * Create one WooCommerce order from the common shape.
 *
 * @param array $o   Order.
 * @param array $job Progress, changed in place.
 */
function sella_si_import_order( $o, &$job ) {
	if ( '' === $o['name'] || $o['test'] ) {
		++$job['skipped'];
		return;
	}
	$existing = sella_si_existing_order( $o['name'] );
	if ( $existing ) {
		if ( sella_si_fill_customer( $existing, $o ) ) {
			++$job['updated'];
			sella_si_log( $job, 'ok', $o['name'] . ' כבר יובאה, ועכשיו נוספו לה פרטי הלקוח.' );
		} else {
			++$job['skipped'];
			sella_si_log( $job, 'skip', $o['name'] . ' כבר יובאה.' );
		}
		return;
	}

	try {
		$order = new WC_Order();

		// Not "read": setting the status then records no transition, so saving
		// fires no woocommerce_order_status_* hooks (emails, stock, carts).
		$order->set_object_read( false );

		$status = sella_si_order_status( $o );
		$order->set_status( $status );
		$order->set_created_via( 'shopify' );
		$order->set_currency( $o['currency'] );
		$order->set_prices_include_tax( true );
		$order->set_date_created( $o['created'] );
		if ( $o['paid'] ) {
			$order->set_date_paid( $o['paid'] );
		}
		if ( 'completed' === $status ) {
			$order->set_date_completed( $o['completed'] ? $o['completed'] : ( $o['paid'] ? $o['paid'] : $o['created'] ) );
		}
		$order->set_payment_method_title( $o['payment'] );
		$order->set_customer_note( $o['note'] );
		sella_si_set_customer( $order, $o );

		$unmatched = array();
		foreach ( $o['items'] as $line ) {
			$item       = new WC_Order_Item_Product();
			$product_id = sella_si_match_product( $line );
			$item->set_name( $line['name'] );
			$item->set_quantity( $line['quantity'] );
			$item->set_product_id( $product_id );
			$item->set_subtotal( $line['subtotal'] );
			$item->set_total( $line['total'] );
			if ( '' !== $line['sku'] ) {
				$item->add_meta_data( '_sella_shopify_sku', $line['sku'], true );
			}
			$order->add_item( $item );
			if ( ! $product_id ) {
				$unmatched[] = $line['name'];
			}
		}

		foreach ( $o['shipping_lines'] as $line ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( $line['title'] );
			$item->set_total( $line['total'] );
			$order->add_item( $item );
		}

		foreach ( $o['discount_codes'] as $i => $code ) {
			$item = new WC_Order_Item_Coupon();
			$item->set_code( $code );
			$item->set_discount( 0 === $i ? $o['discount'] : 0 );
			$order->add_item( $item );
		}

		// Totals as Shopify charged them; recalculating would apply this
		// site's prices and tax settings. Israeli prices include VAT.
		$order->set_shipping_total( $o['shipping'] );
		$order->set_discount_total( $o['discount'] );
		$order->set_cart_tax( 0 );
		$order->set_total( $o['total'] );

		$order->update_meta_data( SELLA_SI_ORDER_KEY, $o['name'] );
		$order->update_meta_data( '_sella_shopify_financial_status', $o['financial'] );
		$order->update_meta_data( '_sella_shopify_fulfillment_status', $o['fulfillment'] );
		$order->update_meta_data( '_sella_shopify_tax', $o['tax'] );
		if ( $o['tags'] ) {
			$order->update_meta_data( '_sella_shopify_tags', implode( ', ', $o['tags'] ) );
		}
		if ( $o['refunded'] > 0 ) {
			$order->update_meta_data( '_sella_shopify_refunded', $o['refunded'] );
		}

		$order->save();

		$note = sprintf( 'יובאה משופיפיי. הזמנה %s, מצב תשלום: %s, מצב משלוח: %s.', $o['name'], $o['financial'], $o['fulfillment'] );
		if ( $o['refunded'] > 0 ) {
			$note .= ' הוחזרו ' . wc_format_decimal( $o['refunded'], 2 ) . ' ' . $o['currency'] . '.';
		}
		if ( $o['more_items'] ) {
			$note .= ' בהזמנה היו יותר מ-50 שורות, ויובאו רק 50 הראשונות.';
		}
		$order->add_order_note( $note );

		++$job['imported'];
		if ( $unmatched ) {
			sella_si_log( $job, 'skip', $o['name'] . ' יובאה. לא נמצא ספר תואם לשורות: ' . implode( ', ', $unmatched ) . '.' );
		}
	} catch ( Exception $e ) {
		++$job['failed'];
		sella_si_log( $job, 'error', $o['name'] . ': ' . $e->getMessage() );
	}
}

/**
 * One step of the API order import.
 *
 * @param array $job Progress, changed in place.
 * @return true|WP_Error
 */
function sella_si_step_orders( &$job ) {
	$money = '{ shopMoney { amount } }';
	$addr  = '{ firstName lastName company address1 address2 city province zip countryCodeV2 phone }';
	$query = 'query($after: String, $first: Int!) {
		orders(first: $first, after: $after, sortKey: CREATED_AT) {
			pageInfo { hasNextPage endCursor }
			nodes {
				name createdAt processedAt closedAt cancelledAt test
				displayFinancialStatus displayFulfillmentStatus
				email phone currencyCode note tags paymentGatewayNames discountCodes
				subtotalPriceSet ' . $money . ' totalShippingPriceSet ' . $money . '
				totalDiscountsSet ' . $money . ' totalTaxSet ' . $money . '
				totalPriceSet ' . $money . ' totalRefundedSet ' . $money . '
				billingAddress ' . $addr . ' shippingAddress ' . $addr . '
				shippingLines(first: 5) { nodes { title originalPriceSet ' . $money . ' } }
				lineItems(first: 50) {
					pageInfo { hasNextPage }
					nodes { name quantity sku product { handle } originalUnitPriceSet ' . $money . ' discountedTotalSet ' . $money . ' }
				}
			}
		}
	}';

	for ( $page = 0; $page < SELLA_SI_ORDER_PAGES_PER_STEP && ! $job['done']; $page++ ) {
		$data = sella_si_graphql(
			$query,
			array(
				'after' => $job['cursor'],
				'first' => SELLA_SI_ORDERS_PER_PAGE,
			),
			$partial
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! isset( $data['orders']['nodes'] ) ) {
			return new WP_Error( 'sella_si_orders', 'שופיפיי לא החזיר הזמנות. כנראה לאפליקציה חסרה ההרשאה read_orders או הגישה לנתוני לקוחות.' );
		}

		// Orders without their customers are not imported: the run stops
		// here, at this page, and goes on from it once the access is granted.
		$orders   = array_map( 'sella_si_order_from_api', array_filter( $data['orders']['nodes'] ) );
		$withheld = $orders && ! array_filter(
			$orders,
			function ( $o ) {
				return sella_si_has_customer( $o['billing'], $o['shipping_addr'], $o['email'] );
			}
		);
		if ( $partial || $withheld ) {
			return new WP_Error(
				'sella_si_customer_data',
				'שופיפיי לא מסר את פרטי הלקוחות (שם, כתובת, אימייל, טלפון), ולכן הייבוא נעצר לפני שיובאו הזמנות בלעדיהם. צריך לאשר לאפליקציה ב-Dev Dashboard את Protected customer data עם השדות Name, Email, Phone ו-Address, ואז ללחוץ "המשך ייבוא". אפשר גם לייבא מקובץ ה-CSV למטה, שבו הפרטים קיימים תמיד.'
				. ( $partial ? ' שופיפיי: ' . implode( ' / ', array_slice( $partial, 0, 2 ) ) : '' )
			);
		}

		foreach ( $orders as $o ) {
			sella_si_import_order( $o, $job );
		}

		$info          = $data['orders']['pageInfo'];
		$job['cursor'] = $info['endCursor'] ? $info['endCursor'] : $job['cursor'];
		$job['done']   = ! $info['hasNextPage'];
	}
	return true;
}

/**
 * Open the orders CSV at its first data row.
 *
 * @param string $file Path.
 * @return array{0: resource, 1: array}|WP_Error The handle and the header.
 */
function sella_si_csv_open( $file ) {
	$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $fh ) {
		return new WP_Error( 'sella_si_csv', 'לא ניתן לפתוח את קובץ ה-CSV.' );
	}
	// Skip a UTF-8 BOM first: in front of the opening quote it would make
	// the first column read as "Name" with its quotes.
	if ( "\xEF\xBB\xBF" !== fread( $fh, 3 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		rewind( $fh );
	}
	$header = fgetcsv( $fh, 0, ',', '"', '' );
	$header = is_array( $header ) ? array_map( 'trim', $header ) : array( '' );
	if ( ! in_array( 'Name', $header, true ) || ! in_array( 'Lineitem name', $header, true ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return new WP_Error( 'sella_si_csv', 'זה לא קובץ ייצוא הזמנות של שופיפיי: חסרות העמודות Name ו-Lineitem name.' );
	}
	return array( $fh, $header );
}

/**
 * Seconds of reading per step of a CSV job, under the time limits of the
 * server and of the proxy in front of it. A step stops at the next order
 * after this, so the file may be as large as it likes.
 */
define( 'SELLA_SI_CSV_SECONDS', 15 );

/**
 * Bytes per uploaded part: well under the smallest upload_max_filesize.
 */
define( 'SELLA_SI_CSV_CHUNK', 1048576 );

/**
 * A new, random place for an uploaded CSV. The file holds customers' details
 * and sits under uploads until the import ends; the folder answers no one.
 *
 * @return string Path.
 */
function sella_si_csv_new_path() {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'sella-shopify-import';
	wp_mkdir_p( $dir );
	if ( ! is_file( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	if ( ! is_file( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	return $dir . '/orders-' . wp_generate_password( 24, false ) . '.csv';
}

/**
 * A new CSV for the import: the job starts over on it, first counting its
 * orders (and finding the newest ones, when only those are wanted).
 *
 * @param string $file   Path.
 * @param int    $newest The newest this many orders, or 0 for all.
 */
function sella_si_csv_start( $file, $newest ) {
	$job = sella_si_job( 'orders_csv' );
	if ( '' !== $job['file'] && $job['file'] !== $file ) {
		wp_delete_file( $job['file'] );
	}
	sella_si_save_job(
		'orders_csv',
		array_merge(
			$job,
			array(
				'file'     => $file,
				'phase'    => 'scan',
				'newest'   => $newest,
				'since'    => 0,
				'orders'   => 0,
				'top'      => array(),
				'last'     => '',
				'total'    => 0,
				'header'   => array(),
				'cursor'   => null,
				'done'     => false,
				'imported' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'log'      => array(),
			)
		)
	);
}

/**
 * A step of counting the file's orders. With a number of newest orders
 * asked for, keeps the creation times of that many newest seen so far (a
 * min-heap, so it stays that small however long the file is); at the end
 * the oldest of them is where the import starts.
 *
 * The export's order is Shopify's own, so the dates decide which are newest.
 *
 * @param array $job Progress, changed in place.
 * @return true|WP_Error
 */
function sella_si_csv_scan_step( &$job ) {
	$open = sella_si_csv_open( $job['file'] );
	if ( is_wp_error( $open ) ) {
		return $open;
	}
	list( $fh, $header ) = $open;
	if ( null !== $job['cursor'] ) {
		fseek( $fh, (int) $job['cursor'] );
	}

	$name_col = (int) array_search( 'Name', $header, true );
	$date_col = array_search( 'Created at', $header, true );
	$limit    = (int) $job['newest'];
	$heap     = new SplMinHeap();
	foreach ( $job['top'] as $ts ) {
		$heap->insert( (int) $ts );
	}

	$start = microtime( true );
	$eof   = false;
	while ( microtime( true ) - $start < SELLA_SI_CSV_SECONDS ) {
		$row = fgetcsv( $fh, 0, ',', '"', '' );
		if ( false === $row ) {
			$eof = true;
			break;
		}
		$name = isset( $row[ $name_col ] ) ? trim( (string) $row[ $name_col ] ) : '';
		if ( '' === $name || $name === $job['last'] ) {
			continue; // A blank line, or the order's next line item.
		}
		$job['last'] = $name;
		++$job['orders'];
		if ( $limit ) {
			$created = false !== $date_col && isset( $row[ $date_col ] ) ? trim( (string) $row[ $date_col ] ) : '';
			$heap->insert( '' !== $created ? (int) strtotime( $created ) : 0 );
			if ( $heap->count() > $limit ) {
				$heap->extract();
			}
		}
	}
	$job['cursor'] = ftell( $fh );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	$job['top'] = array();
	foreach ( clone $heap as $ts ) {
		$job['top'][] = $ts;
	}
	if ( ! $eof ) {
		return true;
	}

	// Counted: the import itself starts at the top of the file.
	$job['since']  = $limit && $job['orders'] > $limit ? max( 1, (int) $heap->top() ) : 0;
	$job['newest'] = $job['since'] ? $limit : 0;
	$job['total']  = $job['since'] ? $limit : $job['orders'];
	$job['phase']  = '';
	$job['top']    = array();
	$job['last']   = '';
	$job['cursor'] = null;
	sella_si_log(
		$job,
		'ok',
		$job['since']
			? sprintf( 'בקובץ %s הזמנות. מייבא את %s האחרונות, מ-%s והלאה.', number_format_i18n( $job['orders'] ), number_format_i18n( $limit ), wp_date( 'j.n.Y', $job['since'] ) )
			: sprintf( 'בקובץ %s הזמנות. מייבא את כולן.', number_format_i18n( $job['orders'] ) )
	);
	return true;
}

/**
 * AJAX: one part of a CSV upload. The screen sends the file in parts of
 * SELLA_SI_CSV_CHUNK bytes, so its size is not bound by the server's upload
 * limits. Each part says where in the file it starts; a part sent twice
 * (an answer lost on the way) is told where the file really is, and the
 * screen goes on from there.
 */
function sella_si_ajax_csv_chunk() {
	check_ajax_referer( 'sella_si', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'אין הרשאה.' ) );
	}

	$upload = isset( $_POST['upload'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['upload'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$last   = ! empty( $_POST['last'] );
	$chunk  = ! empty( $_FILES['chunk']['tmp_name'] ) && is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ? $_FILES['chunk']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( strlen( $upload ) < 16 ) {
		wp_send_json_error( array( 'message' => 'העלאה לא מזוהה.' ) );
	}

	$key  = 'sella_si_upload_' . get_current_user_id();
	$part = get_transient( $key );
	if ( 0 === $offset ) {
		// A new upload; one left unfinished before is thrown away.
		if ( is_array( $part ) && $part['upload'] !== $upload && is_file( $part['file'] ) ) {
			wp_delete_file( $part['file'] );
		}
		if ( ! is_array( $part ) || $part['upload'] !== $upload ) {
			$part = array(
				'upload' => $upload,
				'file'   => sella_si_csv_new_path(),
			);
			touch( $part['file'] );
		}
	} elseif ( ! is_array( $part ) || $part['upload'] !== $upload ) {
		wp_send_json_error( array( 'message' => 'ההעלאה נקטעה (אולי התחילה העלאה אחרת). צריך להעלות את הקובץ שוב.' ) );
	}

	clearstatcache( true, $part['file'] );
	$size = is_file( $part['file'] ) ? (int) filesize( $part['file'] ) : 0;
	if ( $size !== $offset ) {
		wp_send_json_error( array( 'offset' => $size ) );
	}

	if ( '' !== $chunk ) {
		$data = file_get_contents( $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || false === file_put_contents( $part['file'], $data, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_send_json_error( array( 'message' => 'לא ניתן לשמור את הקובץ בשרת. אולי נגמר המקום באחסון.' ) );
		}
		$offset += strlen( $data );
	} elseif ( ! $last ) {
		wp_send_json_error( array( 'message' => 'חלק מהקובץ לא הגיע לשרת.' ) );
	}

	if ( ! $last ) {
		set_transient( $key, $part, DAY_IN_SECONDS );
		wp_send_json_success( array( 'offset' => $offset ) );
	}

	delete_transient( $key );
	sella_si_csv_start( $part['file'], isset( $_POST['newest'] ) ? absint( $_POST['newest'] ) : 0 );
	wp_send_json_success( array( 'done' => true ) );
}
add_action( 'wp_ajax_sella_si_csv_chunk', 'sella_si_ajax_csv_chunk' );

/**
 * One step of the CSV order import: reads whole orders from the saved byte
 * offset, so a step never splits an order's rows.
 *
 * @param array $job Progress, changed in place.
 * @return true|WP_Error
 */
function sella_si_step_orders_csv( &$job ) {
	if ( '' === $job['file'] || ! is_readable( $job['file'] ) ) {
		return new WP_Error( 'sella_si_csv', 'קובץ ה-CSV לא נמצא. צריך להעלות אותו שוב.' );
	}
	if ( 'scan' === $job['phase'] ) {
		return sella_si_csv_scan_step( $job );
	}

	$open = sella_si_csv_open( $job['file'] );
	if ( is_wp_error( $open ) ) {
		return $open;
	}
	$fh = $open[0];

	if ( null === $job['cursor'] ) {
		$job['header'] = $open[1];
		$job['cursor'] = ftell( $fh );
	}

	fseek( $fh, (int) $job['cursor'] );
	$header   = $job['header'];
	$name_col = (int) array_search( 'Name', $header, true );
	$rows     = array();
	$count    = 0;
	$start    = microtime( true );

	while ( true ) {
		$at  = ftell( $fh );
		$row = fgetcsv( $fh, 0, ',', '"', '' );

		if ( array( null ) === $row ) {
			continue; // Blank line.
		}

		$name = false === $row ? '' : trim( (string) ( isset( $row[ $name_col ] ) ? $row[ $name_col ] : '' ) );
		if ( false === $row || ( $rows && $name !== $rows[0]['Name'] ) ) {
			if ( $rows ) {
				// Older than the newest chosen on upload: passed over, uncounted.
				$o = sella_si_order_from_csv( $rows );
				if ( ! $job['since'] || $o['created'] >= $job['since'] ) {
					sella_si_import_order( $o, $job );
					++$count;
				}
				$rows = array();
			}
			if ( false === $row ) {
				$job['done'] = true;
				break;
			}
			// Enough orders, or a long run of older ones passed over.
			if ( $count >= SELLA_SI_CSV_ORDERS_PER_STEP || microtime( true ) - $start > SELLA_SI_CSV_SECONDS ) {
				$job['cursor'] = $at; // The next step starts on this order's first row.
				break;
			}
		}

		$row             = array_combine( $header, array_pad( array_slice( $row, 0, count( $header ) ), count( $header ), '' ) );
		$row['Name']     = $name;
		$rows[]          = $row;
	}

	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	if ( $job['done'] ) {
		wp_delete_file( $job['file'] );
		$job['file'] = '';
	}
	return true;
}

/*
 * ------------------------------------------------------------------
 * Running a step
 * ------------------------------------------------------------------
 */

/**
 * Quiet the site while importing: no mail of any kind, and none of the
 * order-created hooks other plugins use to sync customers or send campaigns.
 * WooCommerce Analytics is filled afterwards from its own historical import.
 */
function sella_si_quiet() {
	add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
	add_filter( 'woocommerce_can_reduce_order_stock', '__return_false', PHP_INT_MAX );
	remove_all_actions( 'woocommerce_new_order' );
	remove_all_actions( 'woocommerce_update_order' );
	remove_all_actions( 'woocommerce_order_status_changed' );
	wp_defer_term_counting( true );
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}

/**
 * Seconds after which a step's lock counts as left behind (a step that died
 * without releasing it). Longer than a step may run.
 */
define( 'SELLA_SI_LOCK_TTL', 330 );

/**
 * Take a job's lock, so one step runs at a time. A step the browser gave up
 * on may still be running on the server; a second one beside it doubles the
 * load on the database, and both would import the same orders.
 *
 * add_option() fails when the row exists, so two requests cannot both get it.
 *
 * @param string $name Job.
 * @return bool
 */
function sella_si_lock( $name ) {
	$key = 'sella_si_lock_' . $name;
	if ( ! add_option( $key, time(), '', false ) ) {
		if ( (int) get_option( $key ) > time() - SELLA_SI_LOCK_TTL ) {
			return false;
		}
		update_option( $key, time(), false );
	}
	add_action(
		'shutdown',
		function () use ( $key ) {
			delete_option( $key );
		}
	);
	return true;
}

/**
 * AJAX: run one step of a job and report progress.
 */
function sella_si_ajax_step() {
	check_ajax_referer( 'sella_si', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'אין הרשאה.' ) );
	}

	$name = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
	if ( ! in_array( $name, array( 'articles_site', 'articles', 'orders', 'orders_csv', 'seo' ), true ) ) {
		wp_send_json_error( array( 'message' => 'משימה לא מוכרת.' ) );
	}

	if ( ! sella_si_lock( $name ) ) {
		// The previous step is still running: ask again in a little while.
		wp_send_json_success( array_merge( sella_si_progress( sella_si_job( $name ), PHP_INT_MAX ), array( 'wait' => 20, 'busy' => true ) ) );
	}

	$job = sella_si_job( $name );
	if ( ! empty( $_POST['restart'] ) ) {
		$job = array_merge(
			$job,
			array(
				'cursor'   => null,
				'done'     => false,
				'imported' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'log'      => array(),
			)
		);
		if ( 'scan' === $job['phase'] ) {
			$job = array_merge( $job, array( 'orders' => 0, 'top' => array(), 'last' => '' ) );
		}
	}

	$seen        = count( $job['log'] );
	$job['wait'] = 0;
	if ( ! $job['done'] ) {
		sella_si_quiet();
		$fn     = 'sella_si_step_' . $name;
		$result = $fn( $job );
		wp_defer_term_counting( false );
		if ( is_wp_error( $result ) ) {
			sella_si_log( $job, 'error', $result->get_error_message() );
			sella_si_save_job( $name, $job );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) + sella_si_progress( $job, $seen ) );
		}
	}

	sella_si_save_job( $name, $job );
	wp_send_json_success( sella_si_progress( $job, $seen ) );
}
add_action( 'wp_ajax_sella_si_step', 'sella_si_ajax_step' );

/**
 * Progress for the screen.
 *
 * @param array $job  Progress.
 * @param int   $seen Log lines the screen had before this step.
 * @return array
 */
function sella_si_progress( $job, $seen = 0 ) {
	return array(
		'done'     => (bool) $job['done'],
		'imported' => (int) $job['imported'],
		'updated'  => (int) $job['updated'],
		'skipped'  => (int) $job['skipped'],
		'failed'   => (int) $job['failed'],
		'total'    => (int) $job['total'],
		'wait'     => (int) $job['wait'],
		'note'     => 'scan' === $job['phase'] ? sprintf( 'סופר את ההזמנות בקובץ… %s עד עכשיו', number_format_i18n( $job['orders'] ) ) : '',
		'log'      => array_slice( $job['log'], max( 0, min( $seen, count( $job['log'] ) ) ) ),
	);
}

/*
 * ------------------------------------------------------------------
 * Admin screen
 * ------------------------------------------------------------------
 */

/**
 * Under WooCommerce.
 */
function sella_si_register_admin_page() {
	add_submenu_page( 'woocommerce', 'ייבוא משופיפיי', 'ייבוא משופיפיי', 'manage_woocommerce', SELLA_SI_SLUG, 'sella_si_render_page' );
}
add_action( 'admin_menu', 'sella_si_register_admin_page', 60 );

/**
 * Screen assets.
 *
 * @param string $hook Admin hook.
 */
function sella_si_admin_assets( $hook ) {
	if ( false === strpos( $hook, SELLA_SI_SLUG ) ) {
		return;
	}
	$base = get_stylesheet_directory_uri();
	wp_enqueue_style( 'sella-shopify-import-admin', $base . '/assets/css/sella-shopify-import-admin.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-shopify-import-admin', $base . '/assets/js/sella-shopify-import-admin.js', array( 'jquery' ), HELLO_ELEMENTOR_CHILD_VERSION, true );

	$jobs = array();
	foreach ( array( 'articles_site', 'articles', 'orders', 'orders_csv', 'seo' ) as $name ) {
		$jobs[ $name ] = sella_si_progress( sella_si_job( $name ) );
	}
	wp_localize_script(
		'sella-shopify-import-admin',
		'sellaSiAdmin',
		array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'sella_si' ),
			'jobs'        => $jobs,
			'chunk'       => (int) min( SELLA_SI_CSV_CHUNK, max( 65536, (int) ( wp_max_upload_size() / 2 ) ) ),
			'uploadedUrl' => admin_url( 'admin.php?page=' . SELLA_SI_SLUG . '&notice=uploaded#sella-si-csv' ),
			'text'        => array(
				'uploading'    => 'מעלה את הקובץ…',
				'uploadFailed' => 'ההעלאה נכשלה.',
				'uploadRetry'  => 'אין תשובה מהשרת, מנסה שוב…',
				'running'  => 'מייבא…',
				'stopping' => 'עוצר אחרי הצעד הנוכחי…',
				'stopped'  => 'נעצר. אפשר להמשיך מאותה נקודה.',
				'finished' => 'הייבוא הסתיים.',
				'error'    => 'הייבוא נעצר בגלל שגיאה. אפשר לתקן ולהמשיך.',
				'retrying' => 'אין תשובה מהשרת, מנסה שוב…',
				'waiting'  => 'שופיפיי ביקש להאט. ממשיך לבד בעוד דקה…',
				'busy'     => 'הצעד הקודם עוד רץ בשרת. ממתין לו וממשיך…',
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'sella_si_admin_assets' );

/**
 * Save the connection settings, check the connection, or take the CSV.
 */
function sella_si_handle_post() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$back = admin_url( 'admin.php?page=' . SELLA_SI_SLUG );

	// A file over the server's post_max_size arrives as an empty post, without
	// the nonce either: say so instead of "the link you followed has expired".
	if ( empty( $_POST ) && isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 0 ) {
		wp_safe_redirect( add_query_arg( 'notice', 'toobig', $back ) );
		exit;
	}
	check_admin_referer( 'sella_si_form' );

	$action = isset( $_POST['sella_si_action'] ) ? sanitize_key( wp_unslash( $_POST['sella_si_action'] ) ) : '';

	if ( 'settings' === $action ) {
		$old  = sella_si_settings();
		$shop = isset( $_POST['shop'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['shop'] ) ) ) : '';
		$shop = preg_replace( '#^https?://|/.*$#', '', $shop );
		if ( '' !== $shop && false === strpos( $shop, '.' ) ) {
			$shop .= '.myshopify.com';
		}
		$secret = isset( $_POST['client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) ) : '';
		$token  = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		update_option(
			SELLA_SI_SETTINGS,
			array(
				'shop'          => '' !== $shop ? $shop : SELLA_SI_DEFAULT_SHOP,
				'client_id'     => isset( $_POST['client_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) ) : '',
				// Empty secret and token fields keep the saved ones; they are never printed back.
				'client_secret' => '' !== $secret ? $secret : $old['client_secret'],
				'token'         => ! empty( $_POST['clear_token'] ) ? '' : ( '' !== $token ? $token : $old['token'] ),
			),
			false
		);
		delete_transient( SELLA_SI_TOKEN );
		wp_safe_redirect( add_query_arg( 'notice', 'saved', $back ) );
		exit;
	}

	if ( 'check' === $action ) {
		$result = sella_si_check_connection();
		set_transient( 'sella_si_check_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'notice', 'checked', $back ) );
		exit;
	}

	if ( 'csv' === $action ) {
		$error = isset( $_FILES['csv']['error'] ) ? (int) $_FILES['csv']['error'] : UPLOAD_ERR_NO_FILE;
		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			wp_safe_redirect( add_query_arg( 'notice', 'toobig', $back ) );
			exit;
		}
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( add_query_arg( 'notice', 'nofile', $back ) );
			exit;
		}
		// The upload without the screen's script (which sends the file in parts).
		$file = sella_si_csv_new_path();
		if ( ! move_uploaded_file( $_FILES['csv']['tmp_name'], $file ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( add_query_arg( 'notice', 'nofile', $back ) );
			exit;
		}
		sella_si_csv_start( $file, isset( $_POST['newest'] ) ? absint( $_POST['newest'] ) : 0 );
		wp_safe_redirect( add_query_arg( 'notice', 'uploaded', $back ) );
		exit;
	}

	wp_safe_redirect( $back );
	exit;
}
add_action( 'admin_post_sella_si', 'sella_si_handle_post' );

/**
 * One job's panel: counts, buttons, log.
 *
 * @param string $name   Job.
 * @param string $start  Start button label.
 * @param bool   $ready  Whether it can run.
 */
function sella_si_render_job( $name, $start, $ready ) {
	$job = sella_si_job( $name );
	$has = $job['imported'] || $job['skipped'] || $job['failed'] || null !== $job['cursor'];
	?>
	<div class="sella-si-job" data-si-job="<?php echo esc_attr( $name ); ?>">
		<div class="sella-si-counts">
			<span><b data-si-count="imported"><?php echo esc_html( number_format_i18n( $job['imported'] ) ); ?></b> יובאו</span>
			<?php if ( in_array( $name, array( 'orders', 'orders_csv' ), true ) ) : ?>
				<span><b data-si-count="updated"><?php echo esc_html( number_format_i18n( $job['updated'] ) ); ?></b> קיבלו פרטי לקוח</span>
			<?php endif; ?>
			<span><b data-si-count="skipped"><?php echo esc_html( number_format_i18n( $job['skipped'] ) ); ?></b> דולגו</span>
			<span><b data-si-count="failed"><?php echo esc_html( number_format_i18n( $job['failed'] ) ); ?></b> נכשלו</span>
			<span class="sella-si-status" data-si-status><?php echo $job['done'] ? 'הייבוא הסתיים.' : ( $has ? 'נעצר באמצע. אפשר להמשיך.' : '' ); ?></span>
		</div>
		<p class="sella-si-actions">
			<button type="button" class="button button-primary" data-si-start <?php disabled( ! $ready ); ?>><?php echo esc_html( $has && ! $job['done'] ? 'המשך ייבוא' : $start ); ?></button>
			<button type="button" class="button" data-si-stop hidden>עצירה</button>
			<?php if ( $has ) : ?>
				<button type="button" class="button-link" data-si-restart <?php disabled( ! $ready ); ?>>להתחיל מההתחלה</button>
			<?php endif; ?>
		</p>
		<ol class="sella-si-log" data-si-log>
			<?php foreach ( array_reverse( array_slice( $job['log'], -50 ) ) as $line ) : ?>
				<li class="is-<?php echo esc_attr( $line[0] ); ?>"><?php echo esc_html( $line[1] ); ?></li>
			<?php endforeach; ?>
		</ol>
	</div>
	<?php
}

/**
 * The screen.
 */
function sella_si_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$settings  = sella_si_settings();
	$connected = '' !== $settings['token'] || ( '' !== $settings['client_id'] && '' !== $settings['client_secret'] );
	$notice    = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$check     = 'checked' === $notice ? get_transient( 'sella_si_check_' . get_current_user_id() ) : null;
	$csv_job   = sella_si_job( 'orders_csv' );
	$form      = function ( $action ) {
		echo '<input type="hidden" name="action" value="sella_si"><input type="hidden" name="sella_si_action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( 'sella_si_form' );
	};
	?>
	<div class="wrap sella-si-admin">
		<h1>ייבוא משופיפיי</h1>
		<p class="sella-si-lead">מעביר מחנות השופיפיי הישנה את היסטוריית ההזמנות, את מאמרי הבלוג עם התמונות, ואת ה-SEO. הדרך העיקרית היא האפליקציה "Sella → WordPress". בלעדיה אפשר לקרוא את המאמרים מהאתר הישן ואת ההזמנות מקובץ הייצוא. כל ייבוא רץ בצעדים קטנים, אפשר לעצור ולהמשיך בכל רגע, ומה שכבר יובא מדולג, כך שבטוח להריץ שוב. לא נשלחים מיילים ללקוחות והמלאי לא משתנה.</p>

		<?php if ( 'saved' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>פרטי החיבור נשמרו.</p></div>
		<?php elseif ( 'uploaded' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>הקובץ הועלה. אפשר להתחיל לייבא.</p></div>
		<?php elseif ( 'nofile' === $notice ) : ?>
			<div class="notice notice-error is-dismissible"><p>לא התקבל קובץ. צריך לבחור את קובץ ה-CSV ולנסות שוב.</p></div>
		<?php elseif ( 'toobig' === $notice ) : ?>
			<div class="notice notice-error is-dismissible"><p>הקובץ גדול מהמותר בשרת (<?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>). אפשר לבקש מהאחסון להגדיל את upload_max_filesize ואת post_max_size, או לייצא משופיפיי רק חלק מההזמנות (למשל לפי טווח תאריכים) ולהעלות כל קובץ בנפרד.</p></div>
		<?php elseif ( is_wp_error( $check ) ) : ?>
			<div class="notice notice-error"><p><strong>החיבור נכשל:</strong> <?php echo esc_html( $check->get_error_message() ); ?></p></div>
		<?php elseif ( is_array( $check ) ) : ?>
			<?php
			$missing = array_diff( array( 'read_content', 'read_orders', 'read_all_orders', 'read_products', 'read_online_store_navigation' ), $check['scopes'] );
			?>
			<div class="notice <?php echo $missing ? 'notice-warning' : 'notice-success'; ?>">
				<p><strong>מחובר לחנות <?php echo esc_html( $check['shop'] ); ?></strong> (<?php echo esc_html( $check['domain'] ); ?>).
				<?php if ( null !== $check['orders'] ) : ?>
					בחנות <?php echo esc_html( number_format_i18n( $check['orders'] ) ); ?> הזמנות שהאפליקציה רואה.
				<?php endif; ?></p>
				<?php if ( $missing ) : ?>
					<p>חסרות לאפליקציה ההרשאות: <code><?php echo esc_html( implode( ', ', $missing ) ); ?></code>. <?php echo in_array( 'read_all_orders', $missing, true ) ? 'בלי read_all_orders שופיפיי מחזיר רק את ההזמנות מ-60 הימים האחרונים. אפשר להוסיף את ההרשאה, או לייבא את כל ההזמנות מקובץ CSV למטה.' : ''; ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( 'app-connected' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><strong>האפליקציה מחוברת.</strong> אפשר לייבא את המאמרים, ההזמנות וה-SEO.</p></div>
		<?php elseif ( 'app-disconnected' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>החיבור לאפליקציה נותק.</p></div>
		<?php elseif ( 'app-missing' === $notice ) : ?>
			<div class="notice notice-error is-dismissible"><p>קודם צריך לשמור את ה-Client ID וה-Client secret של האפליקציה.</p></div>
		<?php endif; ?>

		<div class="notice notice-warning inline"><p><strong>לייבא לפני העברת הדומיין.</strong> שופיפיי מגיש את תמונות המאמרים מהכתובת sellameir.com/cdn/shop, והיא מפסיקה לעבוד ברגע שהדומיין עובר לאתר הזה. הייבוא מוריד כל תמונה לספריית המדיה.</p></div>

		<?php
		$app      = function_exists( 'sella_sa_auth' ) ? sella_sa_auth() : array();
		$has_keys = '' !== $settings['client_id'] && '' !== $settings['client_secret'];
		?>
		<section class="sella-si-section">
			<h2>1. האפליקציה בשופיפיי</h2>
			<?php if ( $app ) : ?>
				<p class="sella-si-connected"><strong>מחובר לחנות <?php echo esc_html( $app['shop'] ); ?></strong> מאז <?php echo esc_html( wp_date( 'j.n.Y H:i', $app['connected_at'] ) ); ?>. הרשאות: <code><?php echo esc_html( $app['scope'] ); ?></code></p>
			<?php else : ?>
				<p class="description">האפליקציה "Sella → WordPress" נמצאת ב-<a href="https://github.com/aviadmols/sella" target="_blank" rel="noopener">github.com/aviadmols/sella</a>. אחרי שמתקינים אותה על החנות, האתר מושך דרכה את כל ההזמנות, המאמרים, התמונות וה-SEO.</p>
			<?php endif; ?>
			<details class="sella-si-help" <?php echo $app ? '' : 'open'; ?>>
				<summary>איך מתקינים</summary>
				<ol>
					<li>בתיקיית האפליקציה: <code>npm install</code> ואז <code>npm run config:link</code>. נפתח דפדפן להתחברות לשופיפיי, והאפליקציה נוצרת בחשבון.</li>
					<li><code>npm run deploy</code>. הפקודה מעלה לשופיפיי את ההרשאות ואת שתי הכתובות של האתר.</li>
					<li>ב-<a href="https://dev.shopify.com/dashboard" target="_blank" rel="noopener">Dev Dashboard</a>, באפליקציה: לבקש גישה ל-<b>Protected customer data</b> (שם, אימייל, טלפון, כתובת) ולהרשאה <code>read_all_orders</code> (כל ההזמנות, לא רק 60 הימים האחרונים).</li>
					<li>ב-<b>Settings</b> של האפליקציה להעתיק את ה-<b>Client ID</b> וה-<b>Client secret</b> לשדות כאן, ולשמור.</li>
					<li>להתקין את האפליקציה על החנות (<b>Install app</b>), או ללחוץ כאן על "חיבור דרך האפליקציה". שופיפיי מבקש אישור ומחזיר לכאן.</li>
				</ol>
				<p>הכתובות שמוגדרות באפליקציה (בקובץ <code>shopify.app.toml</code>), ואמורות להיות זהות לאלה:</p>
				<p><label class="sella-si-field"><span>App URL</span><input type="text" class="large-text code" dir="ltr" readonly value="<?php echo esc_attr( sella_sa_install_url() ); ?>" onclick="this.select();"></label>
				<label class="sella-si-field"><span>Allowed redirection URL</span><input type="text" class="large-text code" dir="ltr" readonly value="<?php echo esc_attr( sella_sa_callback_url() ); ?>" onclick="this.select();"></label></p>
			</details>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sella-si-form" autocomplete="off">
				<?php $form( 'settings' ); ?>
				<label class="sella-si-field"><span>כתובת החנות</span><input type="text" name="shop" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['shop'] ); ?>"></label>
				<label class="sella-si-field"><span>Client ID</span><input type="text" name="client_id" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['client_id'] ); ?>"></label>
				<label class="sella-si-field"><span>Client secret</span><input type="password" name="client_secret" class="regular-text code" dir="ltr" placeholder="<?php echo '' !== $settings['client_secret'] ? esc_attr( 'שמור. להשאיר ריק כדי לא לשנות.' ) : ''; ?>"></label>
				<details>
					<summary>יש כבר Admin API access token?</summary>
					<label class="sella-si-field"><span>Admin API access token</span><input type="password" name="token" class="regular-text code" dir="ltr" placeholder="<?php echo '' !== $settings['token'] ? esc_attr( 'שמור. להשאיר ריק כדי לא לשנות.' ) : 'shpat_…'; ?>">
						<?php if ( '' !== $settings['token'] ) : ?>
							<small><label><input type="checkbox" name="clear_token" value="1"> למחוק את הטוקן השמור</label></small>
						<?php endif; ?>
					</label>
				</details>
				<p><button type="submit" class="button">שמירה</button></p>
			</form>
			<div class="sella-si-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sella_sa">
					<input type="hidden" name="sella_sa" value="<?php echo $app ? 'disconnect' : 'connect'; ?>">
					<?php wp_nonce_field( 'sella_sa' ); ?>
					<?php if ( $app ) : ?>
						<button type="submit" class="button-link">ניתוק</button>
					<?php else : ?>
						<button type="submit" class="button button-primary" <?php disabled( ! $has_keys ); ?>>חיבור דרך האפליקציה</button>
					<?php endif; ?>
				</form>
				<?php if ( $connected ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $form( 'check' ); ?>
						<button type="submit" class="button">בדיקת חיבור</button>
					</form>
				<?php endif; ?>
			</div>
		</section>

		<section class="sella-si-section">
			<h2>2. מאמרי הבלוג</h2>
			<p class="description">כל מאמר הופך לפוסט עם התאריך המקורי, התגיות, התמונה הראשית, התמונות שבתוכן, הכותרת והתיאור לגוגל. הכתובת הישנה של המאמר מפנה מעכשיו לפוסט החדש. מאמר שכבר יובא בדרך אחת מדולג בשנייה.</p>
			<?php if ( $connected ) : ?>
				<h3>דרך האפליקציה</h3>
				<?php sella_si_render_job( 'articles', 'ייבוא כל המאמרים', true ); ?>
			<?php endif; ?>
			<h3><?php echo $connected ? 'או' : ''; ?> מהאתר הישן, בלי אפליקציה</h3>
			<p class="description">קורא כל מאמר ישירות מ-sellameir.com. איטי בכוונה (כ-10 דקות), כי שופיפיי חוסם קריאה מהירה. אם שופיפיי מבקש להאט, הייבוא ממתין דקה וממשיך לבד.</p>
			<?php sella_si_render_job( 'articles_site', 'ייבוא מהאתר הישן', true ); ?>
		</section>

		<section class="sella-si-section">
			<h2>3. היסטוריית ההזמנות</h2>
			<p class="description">כל הזמנה נוצרת ב-WooCommerce בסטטוס הסופי שלה (שולמה → הושלמה, בוטלה, הוחזרה), עם מספר ההזמנה משופיפיי (למשל S-1001), התאריך, הסכומים ששולמו בפועל ופרטי הלקוח. שורות ההזמנה מקושרות לספרים באתר כשיש התאמה. אם יש באתר לקוח עם אותו אימייל, ההזמנה מופיעה בחשבון שלו. הזמנה שכבר יובאה בדרך אחת מדולגת בשנייה.</p>
			<?php if ( $connected ) : ?>
				<h3>דרך האפליקציה</h3>
				<?php sella_si_render_job( 'orders', 'ייבוא כל ההזמנות', true ); ?>
			<?php endif; ?>
			<h3 id="sella-si-csv"><?php echo $connected ? 'או' : ''; ?> מקובץ CSV, בלי אפליקציה</h3>
			<p class="description">בניהול של שופיפיי: <b>Orders</b> ← <b>Export</b> ← <b>All orders</b> ← <b>CSV for Excel, Numbers, or other spreadsheet programs</b> ← <b>Export orders</b>. כשיש הרבה הזמנות שופיפיי שולח את הקובץ למייל. מעלים את הקובץ כאן בלי לפתוח אותו ב-Excel. הקובץ נמחק מהשרת בסוף הייבוא.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="sella-si-upload">
				<?php $form( 'csv' ); ?>
				<input type="file" name="csv" accept=".csv,text/csv">
				<label class="sella-si-newest">רק ההזמנות האחרונות:
					<input type="number" name="newest" min="1" step="1" inputmode="numeric" placeholder="הכל" class="small-text">
				</label>
				<button type="submit" class="button">העלאת הקובץ</button>
			</form>
			<p class="description">קובץ בכל גודל: הוא עולה בחלקים, ואם ההעלאה נקטעת היא ממשיכה מאותה נקודה. בשדה "רק ההזמנות האחרונות" אפשר לכתוב מספר, למשל 4000, ואז ייובאו רק ההזמנות החדשות ביותר בקובץ לפי תאריך ההזמנה. שדה ריק מייבא את כל ההזמנות.</p>
			<?php if ( '' !== $csv_job['file'] && $csv_job['orders'] && 'scan' !== $csv_job['phase'] ) : ?>
				<p class="sella-si-csv-info">
					בקובץ <?php echo esc_html( number_format_i18n( $csv_job['orders'] ) ); ?> הזמנות.
					<?php if ( $csv_job['since'] ) : ?>
						ייובאו <?php echo esc_html( number_format_i18n( $csv_job['total'] ) ); ?> האחרונות, מ-<?php echo esc_html( wp_date( 'j.n.Y', $csv_job['since'] ) ); ?> והלאה.
					<?php else : ?>
						ייובאו כולן.
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php sella_si_render_job( 'orders_csv', 'ייבוא מהקובץ', '' !== $csv_job['file'] ); ?>
		</section>

		<section class="sella-si-section">
			<h2>4. SEO</h2>
			<p class="description">כותרת ותיאור ה-SEO של כל מוצר בשופיפיי נכנסים ל-Yoast בספר התואם, אם עוד אין שם כותרת או תיאור. ההפניות שהוגדרו בשופיפיי (Online Store ← Navigation ← URL Redirects) מצטרפות להפניות מהאתר הישן. ה-SEO של המאמרים נכנס כבר עם ייבוא המאמרים.</p>
			<?php if ( $connected ) : ?>
				<?php sella_si_render_job( 'seo', 'ייבוא ה-SEO', true ); ?>
			<?php else : ?>
				<p><em>זמין אחרי חיבור האפליקציה.</em></p>
			<?php endif; ?>
		</section>

		<section class="sella-si-section">
			<h2>5. אחרי הייבוא</h2>
			<ul class="sella-si-after">
				<li>WooCommerce ← הגדרות ← <b>Analytics</b> ← <b>Import historical data</b>: כדי שההזמנות הישנות ייכנסו לדוחות המכירות.</li>
				<li>שורות שלא נמצא להן ספר תואם מופיעות ביומן למעלה. ההזמנה עצמה יובאה עם שם הספר והסכום.</li>
			</ul>
		</section>
	</div>
	<?php
}

/*
 * ------------------------------------------------------------------
 * Imported orders around the site
 * ------------------------------------------------------------------
 */

/**
 * Imported orders keep their Shopify number, so a customer finds #1001 as
 * S-1001 and it cannot clash with this site's own order numbers.
 *
 * @param string   $number Order number.
 * @param WC_Order $order  Order.
 * @return string
 */
function sella_si_order_number( $number, $order ) {
	$name = $order instanceof WC_Order ? (string) $order->get_meta( SELLA_SI_ORDER_KEY ) : '';
	return '' !== $name ? 'S-' . ltrim( $name, '#' ) : $number;
}
add_filter( 'woocommerce_order_number', 'sella_si_order_number', 10, 2 );
