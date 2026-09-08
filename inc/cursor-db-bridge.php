<?php
/**
 * Cursor DB Bridge — generate a token and expose a read-only REST API
 * so an AI agent can inspect this site's WordPress database.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CURSOR_BRIDGE_OPTION_HASH', 'cursor_db_bridge_token_hash' );
define( 'CURSOR_BRIDGE_OPTION_META', 'cursor_db_bridge_token_meta' );
define( 'CURSOR_BRIDGE_NS', 'cursor-bridge/v1' );

/**
 * Register admin page under Tools.
 */
function cursor_bridge_admin_menu() {
	add_management_page(
		'חיבור Cursor ל-DB',
		'חיבור Cursor',
		'manage_options',
		'cursor-db-bridge',
		'cursor_bridge_render_admin_page'
	);
}
add_action( 'admin_menu', 'cursor_bridge_admin_menu' );

/**
 * Handle generate / revoke actions.
 */
function cursor_bridge_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_POST['cursor_bridge_action'] ) || empty( $_POST['_wpnonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cursor_bridge_action' ) ) {
		wp_die( 'Nonce לא תקין.' );
	}

	$action = sanitize_key( wp_unslash( $_POST['cursor_bridge_action'] ) );

	if ( 'generate' === $action ) {
		$raw = cursor_bridge_generate_token();
		set_transient( 'cursor_bridge_plaintext_once', $raw, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => 'cursor-db-bridge', 'generated' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	if ( 'revoke' === $action ) {
		delete_option( CURSOR_BRIDGE_OPTION_HASH );
		delete_option( CURSOR_BRIDGE_OPTION_META );
		delete_transient( 'cursor_bridge_plaintext_once' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'cursor-db-bridge', 'revoked' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}
}
add_action( 'admin_init', 'cursor_bridge_handle_actions' );

/**
 * Create a new token. Returns plaintext once; stores only a hash.
 *
 * @return string
 */
function cursor_bridge_generate_token() {
	$raw  = 'cb_' . wp_generate_password( 40, false, false );
	$hash = wp_hash_password( $raw );

	update_option( CURSOR_BRIDGE_OPTION_HASH, $hash, false );
	update_option(
		CURSOR_BRIDGE_OPTION_META,
		array(
			'created_at' => time(),
			'created_by' => get_current_user_id(),
			'last_used'  => null,
		),
		false
	);

	return $raw;
}

/**
 * Validate Bearer token from request.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function cursor_bridge_permission_check( WP_REST_Request $request ) {
	$hash = get_option( CURSOR_BRIDGE_OPTION_HASH );
	if ( empty( $hash ) ) {
		return new WP_Error( 'cursor_bridge_disabled', 'הגשר כבוי — אין טוקן פעיל.', array( 'status' => 403 ) );
	}

	$auth = $request->get_header( 'authorization' );
	if ( empty( $auth ) || ! preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
		return new WP_Error( 'cursor_bridge_unauthorized', 'חסר Authorization: Bearer <token>.', array( 'status' => 401 ) );
	}

	$token = trim( $m[1] );
	if ( ! wp_check_password( $token, $hash ) ) {
		return new WP_Error( 'cursor_bridge_forbidden', 'טוקן לא תקין.', array( 'status' => 403 ) );
	}

	$meta = get_option( CURSOR_BRIDGE_OPTION_META, array() );
	if ( ! is_array( $meta ) ) {
		$meta = array();
	}
	$meta['last_used'] = time();
	update_option( CURSOR_BRIDGE_OPTION_META, $meta, false );

	return true;
}

/**
 * Register read-only REST routes.
 */
function cursor_bridge_register_routes() {
	register_rest_route(
		CURSOR_BRIDGE_NS,
		'/ping',
		array(
			'methods'             => 'GET',
			'callback'            => 'cursor_bridge_endpoint_ping',
			'permission_callback' => 'cursor_bridge_permission_check',
		)
	);

	register_rest_route(
		CURSOR_BRIDGE_NS,
		'/info',
		array(
			'methods'             => 'GET',
			'callback'            => 'cursor_bridge_endpoint_info',
			'permission_callback' => 'cursor_bridge_permission_check',
		)
	);

	register_rest_route(
		CURSOR_BRIDGE_NS,
		'/tables',
		array(
			'methods'             => 'GET',
			'callback'            => 'cursor_bridge_endpoint_tables',
			'permission_callback' => 'cursor_bridge_permission_check',
		)
	);

	register_rest_route(
		CURSOR_BRIDGE_NS,
		'/describe/(?P<table>[a-zA-Z0-9_]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'cursor_bridge_endpoint_describe',
			'permission_callback' => 'cursor_bridge_permission_check',
			'args'                => array(
				'table' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		CURSOR_BRIDGE_NS,
		'/query',
		array(
			'methods'             => 'POST',
			'callback'            => 'cursor_bridge_endpoint_query',
			'permission_callback' => 'cursor_bridge_permission_check',
			'args'                => array(
				'sql'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'limit' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 100,
				),
			),
		)
	);

	register_rest_route(
		CURSOR_BRIDGE_NS,
		'/option/(?P<name>[a-zA-Z0-9_\-]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'cursor_bridge_endpoint_option',
			'permission_callback' => 'cursor_bridge_permission_check',
			'args'                => array(
				'name' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'cursor_bridge_register_routes' );

/**
 * Ensure table name belongs to this WP install prefix and exists.
 *
 * @param string $table Table name.
 * @return string|WP_Error
 */
function cursor_bridge_validate_table( $table ) {
	global $wpdb;

	$table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
	if ( empty( $table ) || 0 !== strpos( $table, $wpdb->prefix ) ) {
		return new WP_Error( 'cursor_bridge_bad_table', 'מותרות רק טבלאות עם ה-prefix של האתר.', array( 'status' => 400 ) );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above.
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return new WP_Error( 'cursor_bridge_missing_table', 'הטבלה לא קיימת.', array( 'status' => 404 ) );
	}

	return $table;
}

/**
 * Allow only a single SELECT / SHOW / DESCRIBE / EXPLAIN statement.
 *
 * @param string $sql SQL.
 * @return true|WP_Error
 */
function cursor_bridge_assert_readonly_sql( $sql ) {
	$normalized = trim( $sql );
	$normalized = preg_replace( '/\/\*.*?\*\//s', '', $normalized );
	$normalized = preg_replace( '/--.*?$/m', '', $normalized );
	$normalized = trim( $normalized );

	if ( '' === $normalized ) {
		return new WP_Error( 'cursor_bridge_empty_sql', 'שאילתה ריקה.', array( 'status' => 400 ) );
	}

	if ( false !== strpos( $normalized, ';' ) ) {
		$parts = array_filter( array_map( 'trim', explode( ';', $normalized ) ) );
		if ( count( $parts ) > 1 ) {
			return new WP_Error( 'cursor_bridge_multi_sql', 'מותרת שאילתה אחת בלבד.', array( 'status' => 400 ) );
		}
		$normalized = reset( $parts );
	}

	if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $normalized ) ) {
		return new WP_Error( 'cursor_bridge_write_denied', 'מותרות רק שאילתות קריאה (SELECT / SHOW / DESCRIBE / EXPLAIN).', array( 'status' => 403 ) );
	}

	$blocked = array( 'INTO OUTFILE', 'INTO DUMPFILE', 'FOR UPDATE', 'LOCK IN', 'SLEEP(', 'BENCHMARK(' );
	$upper   = strtoupper( $normalized );
	foreach ( $blocked as $needle ) {
		if ( false !== strpos( $upper, $needle ) ) {
			return new WP_Error( 'cursor_bridge_blocked', 'השאילתה מכילה ביטוי חסום.', array( 'status' => 403 ) );
		}
	}

	return true;
}

/**
 * @return WP_REST_Response
 */
function cursor_bridge_endpoint_ping() {
	return rest_ensure_response(
		array(
			'ok'      => true,
			'message' => 'Cursor DB Bridge פעיל',
			'time'    => gmdate( 'c' ),
		)
	);
}

/**
 * @return WP_REST_Response
 */
function cursor_bridge_endpoint_info() {
	global $wpdb, $wp_version;

	return rest_ensure_response(
		array(
			'site_url'        => site_url(),
			'home_url'        => home_url(),
			'name'            => get_bloginfo( 'name' ),
			'wp_version'      => $wp_version,
			'php_version'     => PHP_VERSION,
			'table_prefix'    => $wpdb->prefix,
			'stylesheet'      => get_stylesheet(),
			'template'        => get_template(),
			'is_multisite'    => is_multisite(),
			'active_plugins'  => get_option( 'active_plugins', array() ),
			'timezone_string' => wp_timezone_string(),
		)
	);
}

/**
 * @return WP_REST_Response
 */
function cursor_bridge_endpoint_tables() {
	global $wpdb;

	$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

	return rest_ensure_response(
		array(
			'prefix' => $wpdb->prefix,
			'tables' => array_values( $tables ),
			'count'  => count( $tables ),
		)
	);
}

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function cursor_bridge_endpoint_describe( WP_REST_Request $request ) {
	global $wpdb;

	$table = cursor_bridge_validate_table( $request['table'] );
	if ( is_wp_error( $table ) ) {
		return $table;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );

	return rest_ensure_response(
		array(
			'table'   => $table,
			'columns' => $columns,
		)
	);
}

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function cursor_bridge_endpoint_query( WP_REST_Request $request ) {
	global $wpdb;

	$sql = $request->get_param( 'sql' );
	$check = cursor_bridge_assert_readonly_sql( $sql );
	if ( is_wp_error( $check ) ) {
		return $check;
	}

	$limit = (int) $request->get_param( 'limit' );
	if ( $limit < 1 ) {
		$limit = 100;
	}
	if ( $limit > 500 ) {
		$limit = 500;
	}

	$normalized = trim( $sql );
	$normalized = rtrim( $normalized, ';' );

	if ( preg_match( '/^SELECT\b/i', $normalized ) && ! preg_match( '/\bLIMIT\s+\d+/i', $normalized ) ) {
		$normalized .= ' LIMIT ' . $limit;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional read-only bridge after validation.
	$rows = $wpdb->get_results( $normalized, ARRAY_A );

	if ( null === $rows && ! empty( $wpdb->last_error ) ) {
		return new WP_Error( 'cursor_bridge_sql_error', $wpdb->last_error, array( 'status' => 400 ) );
	}

	return rest_ensure_response(
		array(
			'sql'   => $normalized,
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'rows'  => $rows ? $rows : array(),
		)
	);
}

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function cursor_bridge_endpoint_option( WP_REST_Request $request ) {
	$name = $request['name'];

	$blocked = array(
		'cursor_db_bridge_token_hash',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
	);

	if ( in_array( $name, $blocked, true ) ) {
		return new WP_Error( 'cursor_bridge_secret', 'אופציה זו חסומה.', array( 'status' => 403 ) );
	}

	$value = get_option( $name, null );
	if ( null === $value ) {
		return new WP_Error( 'cursor_bridge_option_missing', 'האופציה לא נמצאה.', array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'name'  => $name,
			'value' => $value,
		)
	);
}

/**
 * Admin UI.
 */
function cursor_bridge_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$has_token   = (bool) get_option( CURSOR_BRIDGE_OPTION_HASH );
	$meta        = get_option( CURSOR_BRIDGE_OPTION_META, array() );
	$plaintext   = get_transient( 'cursor_bridge_plaintext_once' );
	$base        = esc_url_raw( rest_url( CURSOR_BRIDGE_NS ) );
	$ping_url    = esc_url_raw( rest_url( CURSOR_BRIDGE_NS . '/ping' ) );

	if ( $plaintext ) {
		// Show once, then clear so it cannot be re-read from the page later.
		delete_transient( 'cursor_bridge_plaintext_once' );
	}

	$bundle = '';
	if ( $plaintext ) {
		$bundle = wp_json_encode(
			array(
				'bridge'      => 'cursor-db-bridge',
				'version'     => 1,
				'site_url'    => site_url(),
				'rest_base'   => $base,
				'token'       => $plaintext,
				'auth_header' => 'Bearer ' . $plaintext,
				'endpoints'   => array(
					'ping'     => $base . '/ping',
					'info'     => $base . '/info',
					'tables'   => $base . '/tables',
					'describe' => $base . '/describe/{table}',
					'query'    => $base . '/query',
					'option'   => $base . '/option/{name}',
				),
				'usage'       => 'הדבק את ה-JSON הזה בצ׳אט עם Cursor. השתמש ב-Authorization: Bearer <token> לכל בקשה.',
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);
	}
	?>
	<div class="wrap" dir="rtl" style="max-width:860px;">
		<h1>חיבור Cursor ל-DB</h1>
		<p>כאן מייצרים טוקן חד־פעמי שמאפשר לסוכן Cursor לקרוא (בלבד) מנתוני וורדפרס דרך REST API מאובטח — בלי לחשוף סיסמאות DB.</p>

		<?php if ( ! empty( $_GET['revoked'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>הטוקן בוטל.</p></div>
		<?php endif; ?>

		<?php if ( $plaintext && $bundle ) : ?>
			<div class="notice notice-warning" style="padding:12px 16px;">
				<p><strong>הטוקן מוצג פעם אחת בלבד.</strong> העתק את הבלוק למטה והדבק אותו בצ׳אט עם Cursor.</p>
			</div>
			<label for="cursor-bridge-bundle"><strong>חבילת חיבור להדבקה ב-Cursor:</strong></label>
			<textarea id="cursor-bridge-bundle" class="large-text code" rows="22" readonly onclick="this.select();"><?php echo esc_textarea( $bundle ); ?></textarea>
			<p>
				<button type="button" class="button button-primary" onclick="navigator.clipboard.writeText(document.getElementById('cursor-bridge-bundle').value); this.textContent='הועתק!';">העתק ללוח</button>
			</p>
		<?php endif; ?>

		<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:20px 0;">
			<h2 style="margin-top:0;">סטטוס</h2>
			<p>
				<strong>טוקן פעיל:</strong>
				<?php echo $has_token ? '<span style="color:#008a20;">כן</span>' : '<span style="color:#d63638;">לא</span>'; ?>
			</p>
			<?php if ( $has_token && is_array( $meta ) ) : ?>
				<p><strong>נוצר:</strong> <?php echo ! empty( $meta['created_at'] ) ? esc_html( wp_date( 'd/m/Y H:i', (int) $meta['created_at'] ) ) : '—'; ?></p>
				<p><strong>שימוש אחרון:</strong> <?php echo ! empty( $meta['last_used'] ) ? esc_html( wp_date( 'd/m/Y H:i', (int) $meta['last_used'] ) ) : 'עדיין לא נוצל'; ?></p>
			<?php endif; ?>
			<p><strong>כתובת בדיקה:</strong> <code><?php echo esc_html( $ping_url ); ?></code></p>
		</div>

		<form method="post" style="display:inline-block;margin-left:8px;">
			<?php wp_nonce_field( 'cursor_bridge_action' ); ?>
			<input type="hidden" name="cursor_bridge_action" value="generate" />
			<?php submit_button( $has_token ? 'צור טוקן חדש (מבטל את הישן)' : 'צור טוקן', 'primary', 'submit', false ); ?>
		</form>

		<?php if ( $has_token ) : ?>
			<form method="post" style="display:inline-block;" onsubmit="return confirm('לבטל את הטוקן הפעיל?');">
				<?php wp_nonce_field( 'cursor_bridge_action' ); ?>
				<input type="hidden" name="cursor_bridge_action" value="revoke" />
				<?php submit_button( 'בטל טוקן', 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<hr style="margin:28px 0;" />
		<h2>איך משתמשים</h2>
		<ol>
			<li>לחץ על <strong>צור טוקן</strong>.</li>
			<li>העתק את חבילת ה-JSON שמופיעה.</li>
			<li>הדבק אותה בצ׳אט עם Cursor ובקש ממנו להתחבר / לשאול שאילתות.</li>
			<li>כשסיימת — בטל את הטוקן.</li>
		</ol>
		<p style="color:#646970;">הגשר מאפשר רק קריאה: רשימת טבלאות, DESCRIBE, SELECT, וקריאת options (ללא סודות מערכת).</p>
	</div>
	<?php
}
