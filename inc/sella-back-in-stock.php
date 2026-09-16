<?php
/**
 * "Notify me when back in stock" for out-of-stock books.
 *
 * Visitors leave an email on an out-of-stock product (or variation). When the
 * stock status returns to "instock", Action Scheduler sends the queued
 * notifications in small batches using the WooCommerce email template.
 *
 * Where the form renders:
 * - Classic template / Elementor Pro "Add to cart" widget: woocommerce_simple_add_to_cart
 *   and woocommerce_after_add_to_cart_form (outside the variations <form>).
 * - [sella_back_in_stock] shortcode, for Elementor templates.
 * - Auto attach: the current Elementor product template prints its own
 *   .out-of-stock-message via a shortcode and fires no add-to-cart hooks, so when
 *   nothing above rendered, the form is printed hidden in the footer and JS moves
 *   it next to the visible out-of-stock message.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_BIS_DB_VERSION', '1.0.0' );
define( 'SELLA_BIS_OPTION_DB_VERSION', 'sella_bis_db_version' );
define( 'SELLA_BIS_ACTION_SEND', 'sella_bis_send_batch' );
define( 'SELLA_BIS_ACTION_GROUP', 'sella-back-in-stock' );
define( 'SELLA_BIS_ADMIN_SLUG', 'sella-back-in-stock' );

/**
 * Subscriptions table name.
 *
 * @return string
 */
function sella_bis_table() {
	global $wpdb;
	return $wpdb->prefix . 'sella_back_in_stock';
}

/**
 * Create or upgrade the subscriptions table when the schema version changes.
 */
function sella_bis_maybe_install() {
	if ( SELLA_BIS_DB_VERSION === get_option( SELLA_BIS_OPTION_DB_VERSION ) ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = sella_bis_table();
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta is picky: one column per line and two spaces after PRIMARY KEY.
	dbDelta(
		"CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		product_id bigint(20) unsigned NOT NULL DEFAULT 0,
		email varchar(190) NOT NULL DEFAULT '',
		status varchar(20) NOT NULL DEFAULT 'pending',
		token varchar(64) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		sent_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY product_email (product_id,email),
		KEY status_product (status,product_id),
		KEY token (token)
		) {$charset_collate};"
	);

	// Only store the version once the table really exists, so a failed run retries.
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		update_option( SELLA_BIS_OPTION_DB_VERSION, SELLA_BIS_DB_VERSION );
	}
}
add_action( 'init', 'sella_bis_maybe_install', 5 );

/**
 * Subscription statuses and their admin labels.
 *
 * @return array<string, string>
 */
function sella_bis_statuses() {
	return array(
		'pending'      => 'ממתין',
		'sent'         => 'נשלח',
		'unsubscribed' => 'הוסר',
	);
}

/**
 * Stock statuses that trigger sending.
 *
 * @return array<int, string>
 */
function sella_bis_notify_statuses() {
	return (array) apply_filters( 'sella_bis_notify_statuses', array( 'instock' ) );
}

/**
 * Product types that show the form (variations are handled through their parent).
 *
 * @return array<int, string>
 */
function sella_bis_supported_types() {
	return (array) apply_filters( 'sella_bis_supported_types', array( 'simple', 'variable' ) );
}

/**
 * Frontend copy.
 *
 * @return array<string, string>
 */
function sella_bis_labels() {
	return array(
		'title'        => 'הספר אזל מהמלאי',
		'text'         => 'השאירו מייל ונעדכן אתכם ברגע שהוא יחזור',
		'placeholder'  => 'כתובת אימייל',
		'button'       => 'עדכנו אותי',
		'sending'      => 'שולחים...',
		'success'      => 'מעולה! נשלח לך מייל כשהספר יחזור למלאי',
		'invalidEmail' => 'נא להזין כתובת אימייל תקינה',
		'error'        => 'משהו השתבש. נסו שוב בעוד רגע',
		'expired'      => 'פג תוקף הדף. רעננו את העמוד ונסו שוב',
		'inStock'      => 'הספר חזר למלאי! אפשר להזמין אותו עכשיו',
		'tooMany'      => 'יותר מדי ניסיונות. נסו שוב בעוד כמה דקות',
		'unavailable'  => 'לא ניתן להירשם להתראה על ספר זה',
	);
}

/**
 * Whether visitors may subscribe to this product or variation.
 *
 * @param WC_Product|false|null $product Product.
 * @return bool
 */
function sella_bis_is_subscribable( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	if ( $product->is_type( 'variation' ) ) {
		$parent = wc_get_product( $product->get_parent_id() );
		return $parent && 'publish' === $parent->get_status() && 'publish' === $product->get_status();
	}

	return in_array( $product->get_type(), sella_bis_supported_types(), true ) && 'publish' === $product->get_status();
}

/**
 * Track which products already rendered the form in this request.
 *
 * @param int $product_id Product ID to mark (0 = read only).
 * @return array<int, bool>
 */
function sella_bis_rendered_products( $product_id = 0 ) {
	static $ids = array();
	if ( $product_id ) {
		$ids[ absint( $product_id ) ] = true;
	}
	return $ids;
}

/**
 * Whether we are rendering inside the Elementor editor / preview.
 *
 * @return bool
 */
function sella_bis_is_elementor_editor() {
	if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
		return false;
	}

	$elementor = \Elementor\Plugin::$instance;
	return ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) || ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() );
}

/**
 * Enqueue frontend assets once and pass settings to JS.
 */
function sella_bis_enqueue_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	wp_enqueue_style( 'sella-back-in-stock', get_stylesheet_directory_uri() . '/assets/css/sella-back-in-stock.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-back-in-stock', get_stylesheet_directory_uri() . '/assets/js/sella-back-in-stock.js', array( 'jquery' ), HELLO_ELEMENTOR_CHILD_VERSION, true );

	// The email is pre-filled from JS (not in the markup) so cached widget HTML never carries it.
	$user = wp_get_current_user();
	wp_localize_script(
		'sella-back-in-stock',
		'sellaBackInStock',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'sella_bis_subscribe' ),
			'userEmail' => ( $user && $user->exists() ) ? $user->user_email : '',
			'anchors'   => (string) apply_filters( 'sella_bis_auto_attach_anchors', '.out-of-stock-message, .elementor-widget-woocommerce-product-stock .stock.out-of-stock, .summary .stock.out-of-stock' ),
			'labels'    => sella_bis_labels(),
		)
	);
}

/**
 * Load assets in <head> on out-of-stock product pages (other renders enqueue on demand).
 */
function sella_bis_frontend_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product = wc_get_product( get_queried_object_id() );
	if ( sella_bis_is_subscribable( $product ) && ! $product->is_in_stock() ) {
		sella_bis_enqueue_assets();
	}
}
add_action( 'wp_enqueue_scripts', 'sella_bis_frontend_assets', 30 );

/**
 * Build the subscribe form markup.
 *
 * Simple products render only while out of stock. Variable products always
 * render in "variation" mode: visible when the whole product is out of stock,
 * otherwise hidden until JS finds an out-of-stock variation.
 *
 * @param WC_Product $product Product.
 * @param array      $args    { show_title: bool, auto: bool, preview: bool }.
 * @return string
 */
function sella_bis_render_form( $product, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'show_title' => true,
			'auto'       => false,
			'preview'    => false,
		)
	);

	if ( ! sella_bis_is_subscribable( $product ) || $product->is_type( 'variation' ) ) {
		return '';
	}

	$is_variable  = $product->is_type( 'variable' );
	$out_of_stock = ! $product->is_in_stock();
	if ( ! $is_variable && ! $out_of_stock && ! $args['preview'] ) {
		return '';
	}

	static $instance = 0;
	++$instance;

	sella_bis_rendered_products( $product->get_id() );
	sella_bis_enqueue_assets();

	$labels  = sella_bis_labels();
	$uid     = 'sella-bis-' . $instance;
	$visible = $out_of_stock || $args['preview'];
	$classes = array( 'sella-bis' );
	if ( ! $args['show_title'] ) {
		$classes[] = 'sella-bis--no-title';
	}

	ob_start();
	?>
	<div
		id="<?php echo esc_attr( $uid ); ?>"
		class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
		data-mode="<?php echo esc_attr( $is_variable ? 'variation' : 'simple' ); ?>"
		data-product-id="<?php echo esc_attr( (string) $product->get_id() ); ?>"
		data-parent-id="<?php echo esc_attr( (string) $product->get_id() ); ?>"
		data-initial-visible="<?php echo $visible ? '1' : '0'; ?>"
		<?php echo $args['auto'] ? 'data-auto-attach="1"' : ''; ?>
		<?php echo ( $visible && ! $args['auto'] ) ? '' : 'hidden'; ?>
	>
		<p class="sella-bis__title"><?php echo esc_html( $labels['title'] ); ?></p>
		<p class="sella-bis__text"><?php echo esc_html( $labels['text'] ); ?></p>
		<form class="sella-bis__form" novalidate>
			<label class="sella-bis__sr" for="<?php echo esc_attr( $uid . '-email' ); ?>"><?php echo esc_html( $labels['placeholder'] ); ?></label>
			<input class="sella-bis__input" type="email" id="<?php echo esc_attr( $uid . '-email' ); ?>" name="sella_bis_email" placeholder="<?php echo esc_attr( $labels['placeholder'] ); ?>" autocomplete="email" inputmode="email" required />
			<span class="sella-bis__sr" aria-hidden="true">
				<label for="<?php echo esc_attr( $uid . '-website' ); ?>">השאירו שדה זה ריק</label>
				<input type="text" id="<?php echo esc_attr( $uid . '-website' ); ?>" name="sella_bis_website" value="" tabindex="-1" autocomplete="off" />
			</span>
			<button class="sella-bis__button" type="submit"><?php echo esc_html( $labels['button'] ); ?></button>
		</form>
		<p class="sella-bis__message" role="status" aria-live="polite"></p>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Classic / Elementor Pro add-to-cart area for simple products.
 * Out-of-stock simple products print no <form>, only the stock notice, so the
 * form goes right after the template (priority 30).
 */
function sella_bis_render_in_simple_template() {
	global $product;

	if ( $product instanceof WC_Product && $product->is_type( 'simple' ) && ! isset( sella_bis_rendered_products()[ $product->get_id() ] ) ) {
		echo sella_bis_render_form( $product, array( 'show_title' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
	}
}
add_action( 'woocommerce_simple_add_to_cart', 'sella_bis_render_in_simple_template', 35 );

/**
 * Variable products: render after the variations form, never inside it (nested forms break).
 */
function sella_bis_render_after_variations_form() {
	global $product;

	if ( $product instanceof WC_Product && $product->is_type( 'variable' ) && ! isset( sella_bis_rendered_products()[ $product->get_id() ] ) ) {
		echo sella_bis_render_form( $product, array( 'show_title' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
	}
}
add_action( 'woocommerce_after_add_to_cart_form', 'sella_bis_render_after_variations_form' );

/**
 * Fallback: print a hidden form on out-of-stock product pages that rendered
 * nothing yet; JS attaches it next to the visible out-of-stock message.
 */
function sella_bis_render_auto_attach() {
	if ( ! function_exists( 'is_product' ) || ! is_product() || ! apply_filters( 'sella_bis_auto_attach', true ) ) {
		return;
	}

	$product = wc_get_product( get_queried_object_id() );
	if ( ! sella_bis_is_subscribable( $product ) || $product->is_in_stock() || isset( sella_bis_rendered_products()[ $product->get_id() ] ) ) {
		return;
	}

	echo sella_bis_render_form( $product, array( 'auto' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
}
add_action( 'wp_footer', 'sella_bis_render_auto_attach', 5 );

/**
 * Shortcode: [sella_back_in_stock] or [sella_back_in_stock id="123" show_title="no"]
 *
 * @param array $atts Atts.
 * @return string
 */
function sella_bis_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'         => 0,
			'show_title' => 'yes',
		),
		$atts,
		'sella_back_in_stock'
	);

	$product = null;
	if ( absint( $atts['id'] ) ) {
		$product = wc_get_product( absint( $atts['id'] ) );
	} elseif ( isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ) {
		$product = $GLOBALS['product'];
	} elseif ( function_exists( 'is_product' ) && is_product() ) {
		$product = wc_get_product( get_queried_object_id() );
	} else {
		$product = wc_get_product( get_the_ID() );
	}

	return sella_bis_render_form(
		$product,
		array(
			'show_title' => ! in_array( strtolower( (string) $atts['show_title'] ), array( 'no', '0', 'false' ), true ),
			// Show the form in the Elementor editor even when the preview book is in stock.
			'preview'    => sella_bis_is_elementor_editor(),
		)
	);
}
add_shortcode( 'sella_back_in_stock', 'sella_bis_shortcode' );

/**
 * Insert or re-activate a subscription.
 *
 * @param int    $product_id Product or variation ID.
 * @param string $email      Validated email.
 * @return string|false 'created', 'exists', 'reactivated' or false on failure.
 */
function sella_bis_add_subscription( $product_id, $email ) {
	global $wpdb;

	$table = sella_bis_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE product_id = %d AND email = %s", $product_id, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $row ) {
		if ( 'pending' === $row->status ) {
			return 'exists';
		}

		// Already notified or unsubscribed earlier: asking again means they want the next restock.
		$updated = $wpdb->update(
			$table,
			array(
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
				'sent_at'    => null,
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false === $updated ? false : 'reactivated';
	}

	$inserted = $wpdb->insert(
		$table,
		array(
			'product_id' => $product_id,
			'email'      => $email,
			'status'     => 'pending',
			'token'      => wp_generate_password( 32, false, false ),
			'created_at' => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);

	// A parallel request may have inserted the same pair (unique key).
	return $inserted ? 'created' : 'exists';
}

/**
 * AJAX: subscribe an email to a product.
 */
function sella_bis_ajax_subscribe() {
	$labels = sella_bis_labels();

	if ( ! check_ajax_referer( 'sella_bis_subscribe', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => $labels['expired'] ), 403 );
	}

	// Honeypot: bots fill every field. Pretend success so they learn nothing.
	if ( ! empty( $_POST['sella_bis_website'] ) ) {
		wp_send_json_success( array( 'message' => $labels['success'] ) );
	}

	$email = isset( $_POST['sella_bis_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['sella_bis_email'] ) ) ) : '';
	if ( '' === $email || ! is_email( $email ) || strlen( $email ) > 190 ) {
		wp_send_json_error( array( 'message' => $labels['invalidEmail'] ), 400 );
	}

	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$product    = $product_id ? wc_get_product( $product_id ) : false;
	if ( ! sella_bis_is_subscribable( $product ) ) {
		wp_send_json_error( array( 'message' => $labels['unavailable'] ), 400 );
	}
	if ( $product->is_in_stock() ) {
		wp_send_json_error( array( 'message' => $labels['inStock'] ), 409 );
	}

	// Light per-IP rate limit.
	$ip   = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
	$key  = 'sella_bis_rate_' . md5( (string) $ip );
	$hits = (int) get_transient( $key );
	if ( $hits >= 10 ) {
		wp_send_json_error( array( 'message' => $labels['tooMany'] ), 429 );
	}
	set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );

	if ( false === sella_bis_add_subscription( $product->get_id(), $email ) ) {
		wp_send_json_error( array( 'message' => $labels['error'] ), 500 );
	}

	// Same answer for new and existing subscriptions, so the form never reveals who subscribed.
	wp_send_json_success( array( 'message' => $labels['success'] ) );
}
add_action( 'wp_ajax_sella_bis_subscribe', 'sella_bis_ajax_subscribe' );
add_action( 'wp_ajax_nopriv_sella_bis_subscribe', 'sella_bis_ajax_subscribe' );

/**
 * Whether a product still has pending subscriptions.
 *
 * @param int $product_id Product ID.
 * @param int $after_id   Only count rows after this ID.
 * @return bool
 */
function sella_bis_has_pending( $product_id, $after_id = 0 ) {
	global $wpdb;

	$table = sella_bis_table();
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d AND status = 'pending' AND id > %d LIMIT 1", $product_id, $after_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Queue a sending batch for a product.
 *
 * @param int $product_id Product ID.
 * @param int $after_id   Cursor: continue after this subscription ID.
 * @return bool Whether something was queued (or already is).
 */
function sella_bis_queue_product( $product_id, $after_id = 0 ) {
	$product_id = absint( $product_id );
	$after_id   = absint( $after_id );
	if ( ! $product_id || ! sella_bis_has_pending( $product_id, $after_id ) ) {
		return false;
	}

	// Positional args: the batch callback receives ( $product_id, $after_id ).
	$args = array( $product_id, $after_id );

	if ( function_exists( 'as_enqueue_async_action' ) ) {
		$queued = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( SELLA_BIS_ACTION_SEND, $args, SELLA_BIS_ACTION_GROUP )
			: false !== as_next_scheduled_action( SELLA_BIS_ACTION_SEND, $args, SELLA_BIS_ACTION_GROUP );
		if ( ! $queued ) {
			as_enqueue_async_action( SELLA_BIS_ACTION_SEND, $args, SELLA_BIS_ACTION_GROUP );
		}
		return true;
	}

	wp_schedule_single_event( time(), SELLA_BIS_ACTION_SEND, $args );
	return true;
}

/**
 * Stock status changed: queue notifications when the product is back.
 *
 * @param int    $product_id   Product or variation ID.
 * @param string $stock_status New stock status.
 */
function sella_bis_on_stock_status_change( $product_id, $stock_status ) {
	if ( in_array( $stock_status, sella_bis_notify_statuses(), true ) ) {
		sella_bis_queue_product( $product_id );
	}
}
add_action( 'woocommerce_product_set_stock_status', 'sella_bis_on_stock_status_change', 10, 2 );
add_action( 'woocommerce_variation_set_stock_status', 'sella_bis_on_stock_status_change', 10, 2 );

/**
 * Send one batch of notifications for a product, then queue the next batch.
 *
 * @param int $product_id Product ID.
 * @param int $after_id   Cursor.
 */
function sella_bis_send_batch( $product_id, $after_id = 0 ) {
	global $wpdb;

	$product_id = absint( $product_id );
	$after_id   = absint( $after_id );
	$product    = wc_get_product( $product_id );

	// Went out of stock again before the queue ran: rows stay pending for the next restock.
	if ( ! sella_bis_is_subscribable( $product ) || ! in_array( $product->get_stock_status(), sella_bis_notify_statuses(), true ) ) {
		return;
	}

	$table = sella_bis_table();
	$limit = max( 1, absint( apply_filters( 'sella_bis_batch_size', 25 ) ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, token FROM {$table} WHERE product_id = %d AND status = 'pending' AND id > %d ORDER BY id ASC LIMIT %d", $product_id, $after_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( empty( $rows ) ) {
		return;
	}

	$last_id = $after_id;
	foreach ( $rows as $row ) {
		$last_id = (int) $row->id;

		// Claim the row before sending so overlapping runs never email twice.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sent', sent_at = %s WHERE id = %d AND status = 'pending'", current_time( 'mysql' ), $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $claimed ) {
			continue;
		}

		if ( ! sella_bis_send_email( $row, $product ) ) {
			// Keep it pending; the cursor moves on so a failing address cannot loop forever.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', sent_at = NULL WHERE id = %d", $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	if ( count( $rows ) === $limit ) {
		sella_bis_queue_product( $product_id, $last_id );
	}
}
add_action( SELLA_BIS_ACTION_SEND, 'sella_bis_send_batch', 10, 2 );

/**
 * Unsubscribe URL for a subscription token.
 *
 * @param string $token Token.
 * @return string
 */
function sella_bis_unsubscribe_url( $token ) {
	return add_query_arg( 'sella_bis_unsubscribe', rawurlencode( $token ), home_url( '/' ) );
}

/**
 * Send the Hebrew back-in-stock email through the store's email template.
 *
 * @param object     $row     Subscription row (email, token).
 * @param WC_Product $product Product.
 * @return bool
 */
function sella_bis_send_email( $row, $product ) {
	if ( ! function_exists( 'WC' ) || ! is_email( $row->email ) ) {
		return false;
	}

	$mailer    = WC()->mailer();
	$name      = wp_strip_all_tags( $product->get_name() );
	$permalink = $product->get_permalink();
	$image_id  = $product->get_image_id(); // Variations fall back to the parent image.
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : '';
	$image_url = $image_url ? $image_url : wc_placeholder_img_src( 'woocommerce_single' );
	$subject   = sprintf( 'חדשות טובות: %s חזר למלאי', $name );

	ob_start();
	?>
	<div dir="rtl" style="direction:rtl;text-align:right;">
		<p style="margin:0 0 16px;">שלום,</p>
		<p style="margin:0 0 24px;">ביקשתם שנעדכן אתכם כשהספר יחזור למלאי, והוא כבר כאן. אפשר להזמין אותו עכשיו באתר.</p>
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 24px;">
			<tr>
				<td align="center" style="padding:0 0 16px;text-align:center;">
					<a href="<?php echo esc_url( $permalink ); ?>"><img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="200" style="display:block;width:200px;max-width:100%;height:auto;margin:0 auto;border:0;border-radius:7px;" /></a>
				</td>
			</tr>
			<tr>
				<td align="center" style="padding:0 0 20px;font-size:20px;font-weight:700;line-height:1.3;text-align:center;"><?php echo esc_html( $name ); ?></td>
			</tr>
			<tr>
				<td align="center" style="text-align:center;">
					<a href="<?php echo esc_url( $permalink ); ?>" style="display:inline-block;padding:12px 34px;border:1.7px solid #746fed;border-radius:50px;background-color:#746fed;color:#ffffff;font-size:17px;font-weight:700;text-decoration:none;">להזמנת הספר</a>
				</td>
			</tr>
		</table>
		<p style="margin:24px 0 0;color:#8a8a8a;font-size:12px;line-height:1.5;">
			קיבלתם את המייל כי ביקשתם עדכון על חזרת הספר למלאי. זו התראה חד־פעמית.
			<a href="<?php echo esc_url( sella_bis_unsubscribe_url( $row->token ) ); ?>" style="color:#8a8a8a;text-decoration:underline;">להסרה מההתראה</a>
		</p>
	</div>
	<?php
	$message = $mailer->wrap_message( 'הספר חזר למלאי!', ob_get_clean() );

	return (bool) $mailer->send( $row->email, $subject, $message );
}

/**
 * Handle unsubscribe links: mark the row unsubscribed and show a short confirmation.
 */
function sella_bis_handle_unsubscribe() {
	if ( ! isset( $_GET['sella_bis_unsubscribe'] ) || ! is_string( $_GET['sella_bis_unsubscribe'] ) ) {
		return;
	}

	global $wpdb;

	$table = sella_bis_table();
	$token = preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['sella_bis_unsubscribe'] ) );
	$row   = 32 === strlen( $token ) ? $wpdb->get_row( $wpdb->prepare( "SELECT id, product_id, status FROM {$table} WHERE token = %s", $token ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $row && 'unsubscribed' !== $row->status ) {
		$wpdb->update( $table, array( 'status' => 'unsubscribed' ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
	}

	$product = $row ? wc_get_product( (int) $row->product_id ) : false;
	$back    = $product ? $product->get_permalink() : home_url( '/' );
	$title   = $row ? 'הוסרתם מההתראה' : 'הקישור אינו תקף';
	$text    = $row ? 'לא נשלח לכם יותר מייל על חזרת הספר הזה למלאי.' : 'ייתכן שהקישור שגוי או שכבר אינו פעיל.';

	$html  = '<div dir="rtl" style="text-align:center;">';
	$html .= '<h1 style="margin:0 0 12px;padding:0;border:0;font-size:26px;">' . esc_html( $title ) . '</h1>';
	$html .= '<p style="margin:0 0 22px;font-size:16px;">' . esc_html( $text ) . '</p>';
	$html .= '<p><a href="' . esc_url( $back ) . '" style="display:inline-block;padding:10px 28px;border-radius:50px;background:#746fed;color:#fff;text-decoration:none;">' . esc_html( $product ? 'חזרה לספר' : 'חזרה לאתר' ) . '</a></p>';
	$html .= '</div>';

	nocache_headers();
	wp_die(
		$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		esc_html( $title ),
		array(
			'response'       => 200,
			'text_direction' => 'rtl',
		)
	);
}
add_action( 'template_redirect', 'sella_bis_handle_unsubscribe', 1 );

/**
 * Admin page URL.
 *
 * @param array $args Extra query args.
 * @return string
 */
function sella_bis_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => SELLA_BIS_ADMIN_SLUG ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Add the management page below WooCommerce.
 */
function sella_bis_register_admin_page() {
	$hook = add_submenu_page(
		'woocommerce',
		'התראות חזרה למלאי',
		'התראות חזרה למלאי',
		'manage_woocommerce',
		SELLA_BIS_ADMIN_SLUG,
		'sella_bis_render_admin_page'
	);

	// The hook suffix depends on the translated WooCommerce menu title, so bind through load-{hook}.
	if ( $hook ) {
		add_action( 'load-' . $hook, 'sella_bis_admin_load' );
	}
}
add_action( 'admin_menu', 'sella_bis_register_admin_page', 55 );

/**
 * Enqueue admin CSS only on our page.
 */
function sella_bis_admin_load() {
	add_action( 'admin_enqueue_scripts', 'sella_bis_admin_assets' );
}

/**
 * Admin assets.
 */
function sella_bis_admin_assets() {
	wp_enqueue_style( 'sella-back-in-stock-admin', get_stylesheet_directory_uri() . '/assets/css/sella-back-in-stock-admin.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
}

/**
 * Handle admin delete / send-now actions.
 */
function sella_bis_handle_admin_actions() {
	if ( ! is_admin() || empty( $_POST['sella_bis_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sella_bis_manage' ) ) {
		return;
	}

	global $wpdb;

	$table    = sella_bis_table();
	$action   = sanitize_key( wp_unslash( $_POST['sella_bis_action'] ) );
	$referer  = wp_get_referer();
	$redirect = ( $referer && false !== strpos( $referer, 'page=' . SELLA_BIS_ADMIN_SLUG ) ) ? $referer : sella_bis_admin_url();
	$redirect = remove_query_arg( array( 'deleted', 'queued', 'error' ), $redirect );

	if ( 'delete' === $action ) {
		$ids = array();
		if ( ! empty( $_POST['sella_bis_delete_row'] ) ) {
			$ids = array( absint( $_POST['sella_bis_delete_row'] ) );
		} elseif ( ! empty( $_POST['sella_bis_ids'] ) && is_array( $_POST['sella_bis_ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_POST['sella_bis_ids'] ) );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		$deleted = 0;
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$deleted      = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		wp_safe_redirect( add_query_arg( 'deleted', $deleted, $redirect ) );
		exit;
	}

	if ( 'send_now' === $action ) {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product || ! in_array( $product->get_stock_status(), sella_bis_notify_statuses(), true ) ) {
			wp_safe_redirect( add_query_arg( 'error', 'not_in_stock', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'queued', sella_bis_queue_product( $product_id ) ? '1' : '0', $redirect ) );
		exit;
	}
}
add_action( 'admin_init', 'sella_bis_handle_admin_actions' );

/**
 * Product name + edit link and stock label for admin tables.
 *
 * @param int $product_id Product ID.
 * @return array{html:string,stock:string,in_stock:bool}
 */
function sella_bis_admin_product_info( $product_id ) {
	static $cache = array();

	$product_id = absint( $product_id );
	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		$cache[ $product_id ] = array(
			'html'     => '<span class="sella-bis-muted">' . esc_html( sprintf( 'מוצר שנמחק (#%d)', $product_id ) ) . '</span>',
			'stock'    => '',
			'in_stock' => false,
		);
		return $cache[ $product_id ];
	}

	$edit_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	$options = wc_get_stock_status_options();
	$status  = $product->get_stock_status();

	$cache[ $product_id ] = array(
		'html'     => '<a href="' . esc_url( admin_url( 'post.php?post=' . $edit_id . '&action=edit' ) ) . '">' . esc_html( wp_strip_all_tags( $product->get_name() ) ) . '</a>',
		'stock'    => isset( $options[ $status ] ) ? $options[ $status ] : $status,
		'in_stock' => in_array( $status, sella_bis_notify_statuses(), true ),
	);
	return $cache[ $product_id ];
}

/**
 * Render management page.
 */
function sella_bis_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	global $wpdb;

	$table          = sella_bis_table();
	$statuses       = sella_bis_statuses();
	$status         = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$status         = isset( $statuses[ $status ] ) ? $status : '';
	$filter_product = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
	$search         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page       = 50;
	$table_ready    = SELLA_BIS_DB_VERSION === get_option( SELLA_BIS_OPTION_DB_VERSION );
	$queued         = isset( $_GET['queued'] ) ? sanitize_key( wp_unslash( $_GET['queued'] ) ) : null;

	$where  = array( '1=1' );
	$params = array();
	if ( $status ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}
	if ( $filter_product ) {
		$where[]  = 'product_id = %d';
		$params[] = $filter_product;
	}
	if ( '' !== $search ) {
		$where[]  = 'email LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search ) . '%';
	}
	$where_sql = implode( ' AND ', $where );

	$total   = 0;
	$rows    = array();
	$summary = array();
	if ( $table_ready ) {
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$summary   = $wpdb->get_results( "SELECT product_id, SUM(status = 'pending') AS pending, SUM(status = 'sent') AS sent, SUM(status = 'unsubscribed') AS unsubscribed, COUNT(*) AS total FROM {$table} GROUP BY product_id ORDER BY pending DESC, MAX(created_at) DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$total_pages = (int) ceil( $total / $per_page );
	?>
	<div class="wrap sella-bis-admin" dir="rtl">
		<h1>התראות חזרה למלאי</h1>
		<p>גולשים שהשאירו מייל בדף של ספר שאזל מהמלאי. כשהספר חוזר למלאי המיילים נשלחים אוטומטית ברקע, במנות קטנות.</p>

		<?php if ( ! $table_ready ) : ?><div class="notice notice-error"><p>טבלת ההתראות עדיין לא נוצרה. רעננו את הדף; אם ההודעה חוזרת, בדקו הרשאות מסד נתונים.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( 'נמחקו %d הרשמות.', absint( $_GET['deleted'] ) ) ); ?></p></div><?php endif; ?>
		<?php if ( null !== $queued ) : ?><div class="notice notice-<?php echo '1' === $queued ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo '1' === $queued ? 'השליחה נכנסה לתור ותתבצע ברקע בדקות הקרובות.' : 'אין הרשמות ממתינות לספר הזה.'; ?></p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['error'] ) ) : ?><div class="notice notice-error is-dismissible"><p>הספר לא במלאי כרגע, לכן לא נשלחו התראות.</p></div><?php endif; ?>

		<div class="sella-bis-admin-panel">
			<h2>לפי ספר</h2>
			<?php if ( empty( $summary ) ) : ?>
				<p>עדיין אין הרשמות.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>ספר</th><th>מלאי</th><th class="sella-bis-num">ממתינים</th><th class="sella-bis-num">נשלחו</th><th class="sella-bis-num">הוסרו</th><th class="sella-bis-num">סה״כ</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $summary as $item ) : $info = sella_bis_admin_product_info( $item->product_id ); ?>
						<tr>
							<td><?php echo wp_kses_post( $info['html'] ); ?></td>
							<td><?php echo esc_html( $info['stock'] ); ?></td>
							<td class="sella-bis-num"><strong><?php echo esc_html( (string) (int) $item->pending ); ?></strong></td>
							<td class="sella-bis-num"><?php echo esc_html( (string) (int) $item->sent ); ?></td>
							<td class="sella-bis-num"><?php echo esc_html( (string) (int) $item->unsubscribed ); ?></td>
							<td class="sella-bis-num"><?php echo esc_html( (string) (int) $item->total ); ?></td>
							<td class="sella-bis-admin-actions">
								<a class="button button-small" href="<?php echo esc_url( sella_bis_admin_url( array( 'product_id' => (int) $item->product_id ) ) ); ?>">הצגת הרשמות</a>
								<?php if ( $info['in_stock'] && (int) $item->pending > 0 ) : ?>
									<form method="post">
										<?php wp_nonce_field( 'sella_bis_manage' ); ?>
										<input type="hidden" name="sella_bis_action" value="send_now" />
										<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) (int) $item->product_id ); ?>" />
										<button type="submit" class="button button-small button-primary">שליחה עכשיו</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">"שליחה עכשיו" מופיע לספרים שבמלאי ויש להם הרשמות ממתינות, למשל אם המלאי עודכן בייבוא שלא עבר דרך WooCommerce.</p>
			<?php endif; ?>
		</div>

		<div class="sella-bis-admin-panel">
			<h2>כל ההרשמות <span class="sella-bis-muted">(<?php echo esc_html( (string) $total ); ?>)</span></h2>

			<form method="get" class="sella-bis-admin-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( SELLA_BIS_ADMIN_SLUG ); ?>" />
				<?php if ( $filter_product ) : ?><input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $filter_product ); ?>" /><?php endif; ?>
				<select name="status">
					<option value="">כל הסטטוסים</option>
					<?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="חיפוש לפי אימייל" />
				<button type="submit" class="button">סינון</button>
				<?php if ( $status || $filter_product || '' !== $search ) : ?><a class="button-link" href="<?php echo esc_url( sella_bis_admin_url() ); ?>">ניקוי סינון</a><?php endif; ?>
			</form>

			<?php if ( $filter_product ) : $filter_info = sella_bis_admin_product_info( $filter_product ); ?>
				<p>מציג הרשמות עבור: <?php echo wp_kses_post( $filter_info['html'] ); ?></p>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p>לא נמצאו הרשמות.</p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'sella_bis_manage' ); ?>
					<input type="hidden" name="sella_bis_action" value="delete" />
					<table class="widefat striped">
						<thead>
							<tr>
								<td class="manage-column check-column"><input type="checkbox" aria-label="בחירת הכל" /></td>
								<th>ספר</th>
								<th>אימייל</th>
								<th>סטטוס</th>
								<th>נרשם</th>
								<th>נשלח</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rows as $row ) : $info = sella_bis_admin_product_info( $row->product_id ); ?>
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" name="sella_bis_ids[]" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /></th>
								<td><?php echo wp_kses_post( $info['html'] ); ?></td>
								<td><a href="<?php echo esc_url( 'mailto:' . $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
								<td><span class="sella-bis-status sella-bis-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( isset( $statuses[ $row->status ] ) ? $statuses[ $row->status ] : $row->status ); ?></span></td>
								<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $row->created_at ) ); ?></td>
								<td><?php echo $row->sent_at ? esc_html( mysql2date( 'd/m/Y H:i', $row->sent_at ) ) : '<span class="sella-bis-muted">—</span>'; ?></td>
								<td class="sella-bis-admin-actions"><button type="submit" class="button button-small button-link-delete" name="sella_bis_delete_row" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" onclick="return confirm('למחוק את ההרשמה?');">מחיקה</button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><button type="submit" class="button" onclick="return confirm('למחוק את ההרשמות המסומנות?');">מחיקת המסומנות</button></p>
				</form>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%', remove_query_arg( array( 'deleted', 'queued', 'error' ) ) ),
									'format'    => '',
									'current'   => $paged,
									'total'     => $total_pages,
									'prev_text' => '&rarr;',
									'next_text' => '&larr;',
								)
							)
						);
						?>
					</div></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
