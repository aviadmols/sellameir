<?php
/**
 * Page cache for guests.
 *
 * Saves each page a guest sees as an HTML file. The drop-in in
 * inc/page-cache/advanced-cache.php is copied to wp-content and serves those
 * files before WordPress, plugins and this theme load. Cart, checkout, my
 * account and logged-in users are never cached.
 *
 * Needs `define( 'WP_CACHE', true );` in wp-config.php; the admin shows a
 * notice until it is there.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_PC_DROPIN_SOURCE', get_stylesheet_directory() . '/inc/page-cache/advanced-cache.php' );
define( 'SELLA_PC_DROPIN_TARGET', WP_CONTENT_DIR . '/advanced-cache.php' );

if ( ! defined( 'SELLA_PC_DIR' ) ) {
	define( 'SELLA_PC_DIR', WP_CONTENT_DIR . '/cache/sella-page-cache' );
}

/*
 * ------------------------------------------------------------------
 * Drop-in install and removal
 * ------------------------------------------------------------------
 */

/**
 * Whether the advanced-cache.php in wp-content is ours.
 *
 * @return bool
 */
function sella_pc_dropin_is_ours() {
	if ( ! is_file( SELLA_PC_DROPIN_TARGET ) ) {
		return false;
	}
	return false !== strpos( (string) file_get_contents( SELLA_PC_DROPIN_TARGET ), 'SELLA_PC_DROPIN' );
}

/**
 * Copy the drop-in into wp-content unless a cache plugin owns that file.
 *
 * @return bool True when our current drop-in is in place.
 */
function sella_pc_install_dropin() {
	if ( is_file( SELLA_PC_DROPIN_TARGET ) && ! sella_pc_dropin_is_ours() ) {
		return false;
	}
	if ( sella_pc_dropin_is_ours() && md5_file( SELLA_PC_DROPIN_TARGET ) === md5_file( SELLA_PC_DROPIN_SOURCE ) ) {
		return true;
	}
	return @copy( SELLA_PC_DROPIN_SOURCE, SELLA_PC_DROPIN_TARGET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/**
 * Switching to another theme: remove our drop-in and the saved pages, so the
 * other theme is never served pages built by this one.
 */
function sella_pc_uninstall() {
	if ( sella_pc_dropin_is_ours() ) {
		@unlink( SELLA_PC_DROPIN_TARGET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	sella_pc_purge_all();
}
add_action( 'switch_theme', 'sella_pc_uninstall' );

/**
 * Keep the drop-in in step with the theme after each deploy, and warn in the
 * admin when caching cannot run.
 */
function sella_pc_admin_check() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$installed = sella_pc_install_dropin();

	add_action(
		'admin_notices',
		function () use ( $installed ) {
			if ( ! $installed ) {
				echo '<div class="notice notice-error"><p><strong>מטמון העמודים:</strong> ';
				if ( is_file( SELLA_PC_DROPIN_TARGET ) ) {
					echo 'הקובץ wp-content/advanced-cache.php שייך לתוסף מטמון אחר. יש לכבות את התוסף האחר.';
				} else {
					echo 'לא ניתן לכתוב את wp-content/advanced-cache.php. יש להעתיק אליו ידנית את הקובץ inc/page-cache/advanced-cache.php מהתבנית.';
				}
				echo '</p></div>';
			} elseif ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
				echo '<div class="notice notice-warning"><p><strong>מטמון העמודים:</strong> המטמון עדיין לא פעיל. יש להוסיף לקובץ wp-config.php, מעל השורה "That\'s all, stop editing":<br><code>define( \'WP_CACHE\', true );</code></p></div>';
			}
		}
	);
}
add_action( 'admin_init', 'sella_pc_admin_check' );

/**
 * A new theme version can change markup and asset versions: start over with
 * an empty cache the first time it runs.
 */
function sella_pc_purge_on_theme_update() {
	if ( get_option( 'sella_pc_theme_version' ) === HELLO_ELEMENTOR_CHILD_VERSION ) {
		return;
	}
	update_option( 'sella_pc_theme_version', HELLO_ELEMENTOR_CHILD_VERSION, true );
	sella_pc_purge_all();
}
add_action( 'init', 'sella_pc_purge_on_theme_update' );

/*
 * ------------------------------------------------------------------
 * Saving pages
 * ------------------------------------------------------------------
 */

/**
 * Whether the page WordPress is about to render may be saved.
 *
 * @return bool
 */
function sella_pc_page_cacheable() {
	if ( ! defined( 'SELLA_PC_CANDIDATE' ) || is_user_logged_in() ) {
		return false;
	}

	if ( is_admin() || is_preview() || is_search() || is_feed() || is_404() || is_robots() || is_trackback() || post_password_required() ) {
		return false;
	}

	if ( function_exists( 'is_woocommerce' ) && ( is_cart() || is_checkout() || is_account_page() || is_wc_endpoint_url() ) ) {
		return false;
	}

	return true;
}

/**
 * Start buffering the page once WordPress knows which page it is.
 *
 * One step before PHP_INT_MAX: buffers that rewrite the page (such as
 * sella_delay_tracking_start) start after this one, sit inside it, and so
 * run before the page is stored.
 */
function sella_pc_start_buffer() {
	if ( sella_pc_page_cacheable() ) {
		ob_start( 'sella_pc_store' );
	}
}
add_action( 'template_redirect', 'sella_pc_start_buffer', PHP_INT_MAX - 1 );

/**
 * Output-buffer callback: save the finished page, then send it unchanged.
 *
 * @param string $html Page HTML.
 * @return string
 */
function sella_pc_store( $html ) {
	if ( ! sella_pc_response_storable( $html ) ) {
		return $html;
	}

	$file = SELLA_PC_CANDIDATE;
	$dir  = dirname( $file );

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return $html;
	}

	// Write to a temp file and rename, so a visitor never gets half a page.
	$tmp = $file . '.' . uniqid( '', true ) . '.tmp';
	if ( false !== file_put_contents( $tmp, $html . "\n<!-- sella-page-cache " . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->' ) ) {
		@rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	if ( is_file( $tmp ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	return $html;
}

/**
 * Last checks on the finished response, after every plugin had its say.
 *
 * @param string $html Page HTML.
 * @return bool
 */
function sella_pc_response_storable( $html ) {
	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return false;
	}

	if ( 200 !== http_response_code() || false === stripos( $html, '</html>' ) ) {
		return false;
	}

	foreach ( headers_list() as $header ) {
		if ( 0 === stripos( $header, 'Set-Cookie:' ) ) {
			return false;
		}
		if ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) ) {
			return false;
		}
	}

	// A page built for someone with a cart or a pending notice is personal.
	if ( function_exists( 'WC' ) ) {
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			return false;
		}
		if ( function_exists( 'wc_notice_count' ) && WC()->session && wc_notice_count() > 0 ) {
			return false;
		}
	}

	return true;
}

/*
 * ------------------------------------------------------------------
 * Clearing the cache
 * ------------------------------------------------------------------
 */

/**
 * Delete every saved page.
 */
function sella_pc_purge_all() {
	if ( ! is_dir( SELLA_PC_DIR ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( SELLA_PC_DIR, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} else {
			@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}

/**
 * Ask for a full purge at the end of this request. Many hooks can fire during
 * one save; the folder is emptied only once.
 */
function sella_pc_schedule_purge() {
	static $scheduled = false;
	if ( ! $scheduled ) {
		$scheduled = true;
		add_action( 'shutdown', 'sella_pc_purge_all' );
	}
}

/**
 * Purge when a post of any type is saved (products, pages, Elementor
 * templates, WPCode snippets, upsell popups...), except revisions and autosaves.
 *
 * @param int $post_id Post ID.
 */
function sella_pc_purge_on_post( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'auto-draft' === get_post_status( $post_id ) ) {
		return;
	}
	sella_pc_schedule_purge();
}
add_action( 'save_post', 'sella_pc_purge_on_post' );
add_action( 'deleted_post', 'sella_pc_purge_on_post' );
add_action( 'trashed_post', 'sella_pc_purge_on_post' );

// Stock, price and catalog changes, including stock drops from new orders.
foreach ( array(
	'woocommerce_product_set_stock',
	'woocommerce_variation_set_stock',
	'woocommerce_product_set_stock_status',
	'woocommerce_variation_set_stock_status',
	'woocommerce_update_product',
	'edited_term',
	'created_term',
	'delete_term',
	'comment_post',
	'wp_set_comment_status',
	'wp_update_nav_menu',
	'customize_save_after',
	'upgrader_process_complete',
	'activated_plugin',
	'deactivated_plugin',
	'elementor/core/files/clear_cache',
) as $sella_pc_hook ) {
	add_action( $sella_pc_hook, 'sella_pc_schedule_purge' );
}
unset( $sella_pc_hook );

/**
 * Purge when settings are saved in the admin: theme screens such as badges
 * and shop banners store their data as options.
 *
 * Front-end requests and cron also write options (transients, counters), so
 * only changes made from an admin screen count.
 *
 * @param string $option Option name.
 */
function sella_pc_purge_on_option( $option ) {
	if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( 0 === strpos( $option, '_transient' ) || 0 === strpos( $option, '_site_transient' ) || 'cron' === $option ) {
		return;
	}
	sella_pc_schedule_purge();
}
add_action( 'updated_option', 'sella_pc_purge_on_option' );
add_action( 'added_option', 'sella_pc_purge_on_option' );

/*
 * ------------------------------------------------------------------
 * Admin bar button
 * ------------------------------------------------------------------
 */

/**
 * Add a "clear cache" item to the admin bar.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 */
function sella_pc_admin_bar( $bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$bar->add_node(
		array(
			'id'    => 'sella-page-cache',
			'title' => 'ניקוי מטמון',
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=sella_pc_purge' ), 'sella_pc_purge' ),
		)
	);
}
add_action( 'admin_bar_menu', 'sella_pc_admin_bar', 100 );

/**
 * Handle the admin bar button.
 */
function sella_pc_handle_purge() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_pc_purge' );

	sella_pc_purge_all();

	// Back to the page the button was clicked on.
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_sella_pc_purge', 'sella_pc_handle_purge' );
