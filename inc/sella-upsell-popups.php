<?php
/**
 * Configurable WooCommerce upsell side popups.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_UPSELL_CPT', 'sella_upsell' );
define( 'SELLA_UPSELL_META_ENABLED', '_sella_upsell_enabled' );
define( 'SELLA_UPSELL_META_PRODUCTS', '_sella_upsell_products' );
define( 'SELLA_UPSELL_META_TRIGGER', '_sella_upsell_trigger' );
define( 'SELLA_UPSELL_META_DELAY', '_sella_upsell_delay' );
define( 'SELLA_UPSELL_META_FREQUENCY', '_sella_upsell_frequency' );
define( 'SELLA_UPSELL_META_SCOPE', '_sella_upsell_scope' );
define( 'SELLA_UPSELL_META_SCOPE_PRODUCTS', '_sella_upsell_scope_products' );
define( 'SELLA_UPSELL_META_SCOPE_PAGES', '_sella_upsell_scope_pages' );
define( 'SELLA_UPSELL_META_PRODUCT_CATEGORIES', '_sella_upsell_product_categories' );
define( 'SELLA_UPSELL_META_PRODUCT_TAGS', '_sella_upsell_product_tags' );
define( 'SELLA_UPSELL_META_AUDIENCE', '_sella_upsell_audience' );
define( 'SELLA_UPSELL_META_CART_RULE', '_sella_upsell_cart_rule' );
define( 'SELLA_UPSELL_META_CART_PRODUCTS', '_sella_upsell_cart_products' );
define( 'SELLA_UPSELL_META_CART_CATEGORIES', '_sella_upsell_cart_categories' );
define( 'SELLA_UPSELL_META_CART_MIN_TOTAL', '_sella_upsell_cart_min_total' );
define( 'SELLA_UPSELL_META_CART_MAX_TOTAL', '_sella_upsell_cart_max_total' );
define( 'SELLA_UPSELL_META_CART_CROSS_SELLS', '_sella_upsell_cart_cross_sells' );
define( 'SELLA_UPSELL_META_TIMER', '_sella_upsell_timer' );
define( 'SELLA_UPSELL_META_TIMER_MINUTES', '_sella_upsell_timer_minutes' );
define( 'SELLA_UPSELL_META_TIMER_LABEL', '_sella_upsell_timer_label' );

/**
 * Default text shown before the countdown.
 */
define( 'SELLA_UPSELL_TIMER_DEFAULT_LABEL', 'ההצעה מסתיימת בעוד' );

/**
 * Cap on books sent to the browser per popup, so a whole category does not
 * end up inlined on every page load.
 */
define( 'SELLA_UPSELL_MAX_ITEMS', 30 );

/**
 * Register private storage for popup definitions.
 */
function sella_upsell_register_cpt() {
	register_post_type(
		SELLA_UPSELL_CPT,
		array(
			'labels' => array(
				'name'          => 'פופאפים של Upsell',
				'singular_name' => 'פופאפ Upsell',
			),
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => false,
			'capabilities'        => array_fill_keys(
				array(
					'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
					'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts',
					'delete_private_posts', 'delete_published_posts', 'edit_private_posts',
					'edit_published_posts', 'create_posts',
				),
				'manage_woocommerce'
			),
			'supports'            => array( 'title', 'page-attributes' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'show_in_rest'        => false,
		)
	);
}
add_action( 'init', 'sella_upsell_register_cpt' );

/**
 * Add the management page below WooCommerce.
 */
function sella_upsell_register_admin_page() {
	add_menu_page(
		'פופאפים של Upsell',
		'פופאפים של Upsell',
		'manage_woocommerce',
		'sella-upsell-popups',
		'sella_upsell_render_admin_page',
		'dashicons-megaphone',
		58.1
	);
}
add_action( 'admin_menu', 'sella_upsell_register_admin_page' );

/**
 * Supported popup values.
 *
 * @return array<string, string>
 */
function sella_upsell_options() {
	return array(
		'triggers'   => array(
			'immediate'    => 'מיד עם כניסת הגולש',
			'delay'       => 'אחרי השהיה',
			'scroll'      => 'אחרי גלילה',
			'exit_intent' => 'כשעומדים לצאת מהעמוד',
			'add_to_cart' => 'אחרי הוספה לסל',
		),
		'frequencies' => array(
			'every_visit' => 'בכל ביקור',
			'session'    => 'פעם אחת בכל ביקור באתר',
			'day'        => 'פעם אחת ביום',
			'once'       => 'פעם אחת בלבד',
		),
		'cart_rules' => array(
			'any'          => 'בלי תנאי — בכל מצב של הסל',
			'contains'     => 'רק אם בסל יש אחד מהספרים / מהקטגוריות שנבחרו',
			'not_contains' => 'רק אם בסל אין אף אחד מהספרים / מהקטגוריות שנבחרו',
			'not_empty'    => 'רק אם יש משהו בסל',
			'empty'        => 'רק אם הסל ריק',
		),
		'audiences'  => array(
			'all'        => 'כל הגולשים',
			'logged_in'  => 'רק משתמשים רשומים (מחוברים)',
			'logged_out' => 'רק גולשים שאינם רשומים',
		),
		'scopes'     => array(
			'all'            => 'בכל האתר',
			'product'        => 'בדפי מוצר',
			'shop'           => 'בחנות ובקטגוריות',
			'cart'           => 'בדף הסל',
			'checkout'       => 'בדף התשלום',
			'products'       => 'רק בדפי ספרים מסוימים',
			'pages'          => 'רק בעמודים ספציפיים',
		),
	);
}

/**
 * Sanitize and persist one popup.
 *
 * @param int   $post_id Popup ID.
 * @param array $source Request data.
 */
function sella_upsell_save_meta( $post_id, $source ) {
	$options = sella_upsell_options();
	$enabled = ! empty( $source['sella_upsell_enabled'] ) ? '1' : '0';
	$trigger = isset( $source['sella_upsell_trigger'] ) ? sanitize_key( wp_unslash( $source['sella_upsell_trigger'] ) ) : 'delay';
	$scope   = isset( $source['sella_upsell_scope'] ) ? sanitize_key( wp_unslash( $source['sella_upsell_scope'] ) ) : 'all';
	$freq    = isset( $source['sella_upsell_frequency'] ) ? sanitize_key( wp_unslash( $source['sella_upsell_frequency'] ) ) : 'session';
	$audience = isset( $source['sella_upsell_audience'] ) ? sanitize_key( wp_unslash( $source['sella_upsell_audience'] ) ) : 'all';

	if ( ! isset( $options['triggers'][ $trigger ] ) ) {
		$trigger = 'delay';
	}
	if ( ! isset( $options['audiences'][ $audience ] ) ) {
		$audience = 'all';
	}

	$cart_rule = isset( $source['sella_upsell_cart_rule'] ) ? sanitize_key( wp_unslash( $source['sella_upsell_cart_rule'] ) ) : 'any';
	if ( ! isset( $options['cart_rules'][ $cart_rule ] ) ) {
		$cart_rule = 'any';
	}
	if ( ! isset( $options['scopes'][ $scope ] ) ) {
		$scope = 'all';
	}
	if ( ! isset( $options['frequencies'][ $freq ] ) ) {
		$freq = 'session';
	}

	$products = array();
	if ( ! empty( $source['sella_upsell_products'] ) && is_array( $source['sella_upsell_products'] ) ) {
		foreach ( wp_unslash( $source['sella_upsell_products'] ) as $product_id ) {
			$product_id = absint( $product_id );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
			if ( $product && $product->is_purchasable() ) {
				$products[] = $product_id;
			}
		}
	}

	$scope_products = array();
	if ( ! empty( $source['sella_upsell_scope_products'] ) && is_array( $source['sella_upsell_scope_products'] ) ) {
		$scope_products = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_upsell_scope_products'] ) ) ) ) );
	}

	$scope_pages = array();
	if ( ! empty( $source['sella_upsell_scope_pages'] ) && is_array( $source['sella_upsell_scope_pages'] ) ) {
		$scope_pages = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_upsell_scope_pages'] ) ) ) ) );
	}

	$product_categories = array();
	if ( ! empty( $source['sella_upsell_product_categories'] ) && is_array( $source['sella_upsell_product_categories'] ) ) {
		$product_categories = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_upsell_product_categories'] ) ) ) ) );
	}

	$product_tags = array();
	if ( ! empty( $source['sella_upsell_product_tags'] ) && is_array( $source['sella_upsell_product_tags'] ) ) {
		$product_tags = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_upsell_product_tags'] ) ) ) ) );
	}

	update_post_meta( $post_id, SELLA_UPSELL_META_ENABLED, $enabled );
	update_post_meta( $post_id, SELLA_UPSELL_META_PRODUCTS, array_values( array_unique( $products ) ) );
	update_post_meta( $post_id, SELLA_UPSELL_META_TRIGGER, $trigger );
	update_post_meta( $post_id, SELLA_UPSELL_META_DELAY, max( 0, min( 120, absint( $source['sella_upsell_delay'] ?? 5 ) ) ) );
	update_post_meta( $post_id, SELLA_UPSELL_META_FREQUENCY, $freq );
	update_post_meta( $post_id, SELLA_UPSELL_META_SCOPE, $scope );
	update_post_meta( $post_id, SELLA_UPSELL_META_SCOPE_PRODUCTS, $scope_products );
	update_post_meta( $post_id, SELLA_UPSELL_META_SCOPE_PAGES, $scope_pages );
	update_post_meta( $post_id, SELLA_UPSELL_META_PRODUCT_CATEGORIES, $product_categories );
	update_post_meta( $post_id, SELLA_UPSELL_META_PRODUCT_TAGS, $product_tags );
	update_post_meta( $post_id, SELLA_UPSELL_META_AUDIENCE, $audience );

	$cart_products = array();
	if ( ! empty( $source['sella_upsell_cart_products'] ) && is_array( $source['sella_upsell_cart_products'] ) ) {
		$cart_products = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_upsell_cart_products'] ) ) ) ) );
	}

	$cart_categories = array();
	if ( ! empty( $source['sella_upsell_cart_categories'] ) && is_array( $source['sella_upsell_cart_categories'] ) ) {
		$cart_categories = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_upsell_cart_categories'] ) ) ) ) );
	}

	update_post_meta( $post_id, SELLA_UPSELL_META_CART_RULE, $cart_rule );
	update_post_meta( $post_id, SELLA_UPSELL_META_CART_PRODUCTS, $cart_products );
	update_post_meta( $post_id, SELLA_UPSELL_META_CART_CATEGORIES, $cart_categories );
	update_post_meta( $post_id, SELLA_UPSELL_META_CART_MIN_TOTAL, max( 0, (float) ( $source['sella_upsell_cart_min_total'] ?? 0 ) ) );
	update_post_meta( $post_id, SELLA_UPSELL_META_CART_MAX_TOTAL, max( 0, (float) ( $source['sella_upsell_cart_max_total'] ?? 0 ) ) );
	update_post_meta( $post_id, SELLA_UPSELL_META_CART_CROSS_SELLS, ! empty( $source['sella_upsell_cart_cross_sells'] ) ? '1' : '0' );

	$timer_label = isset( $source['sella_upsell_timer_label'] ) ? sanitize_text_field( wp_unslash( $source['sella_upsell_timer_label'] ) ) : '';
	update_post_meta( $post_id, SELLA_UPSELL_META_TIMER, ! empty( $source['sella_upsell_timer'] ) ? '1' : '0' );
	update_post_meta( $post_id, SELLA_UPSELL_META_TIMER_MINUTES, max( 1, min( 1440, absint( $source['sella_upsell_timer_minutes'] ?? 15 ) ) ) );
	update_post_meta( $post_id, SELLA_UPSELL_META_TIMER_LABEL, '' !== $timer_label ? $timer_label : SELLA_UPSELL_TIMER_DEFAULT_LABEL );
}

/**
 * Handle admin save/delete actions.
 */
function sella_upsell_handle_admin_actions() {
	if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) || empty( $_POST['sella_upsell_action'] ) ) {
		return;
	}

	if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sella_upsell_manage' ) ) {
		return;
	}

	$action  = sanitize_key( wp_unslash( $_POST['sella_upsell_action'] ) );
	$post_id = isset( $_POST['upsell_id'] ) ? absint( $_POST['upsell_id'] ) : 0;

	if ( 'delete' === $action && $post_id && SELLA_UPSELL_CPT === get_post_type( $post_id ) ) {
		wp_trash_post( $post_id );
		wp_safe_redirect( admin_url( 'admin.php?page=sella-upsell-popups&deleted=1' ) );
		exit;
	}

	if ( 'save' !== $action ) {
		return;
	}

	$title = isset( $_POST['sella_upsell_title'] ) ? sanitize_text_field( wp_unslash( $_POST['sella_upsell_title'] ) ) : '';
	if ( '' === $title ) {
		wp_safe_redirect( admin_url( 'admin.php?page=sella-upsell-popups&error=title' ) );
		exit;
	}

	$post_data = array(
		'post_type'   => SELLA_UPSELL_CPT,
		'post_title'  => $title,
		'post_status' => 'publish',
	);
	if ( $post_id && SELLA_UPSELL_CPT === get_post_type( $post_id ) ) {
		$post_data['ID'] = $post_id;
		$post_id         = wp_update_post( $post_data, true );
	} else {
		$post_id = wp_insert_post( $post_data, true );
	}

	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=sella-upsell-popups&error=save' ) );
		exit;
	}

	sella_upsell_save_meta( $post_id, $_POST );
	wp_safe_redirect( admin_url( 'admin.php?page=sella-upsell-popups&saved=1' ) );
	exit;
}
add_action( 'admin_init', 'sella_upsell_handle_admin_actions' );

/**
 * Render the product selector and settings form.
 *
 * @param int $upsell_id Popup ID.
 */
function sella_upsell_render_form( $upsell_id = 0 ) {
	$options        = sella_upsell_options();
	$title          = $upsell_id ? get_the_title( $upsell_id ) : '';
	$enabled        = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_ENABLED, true ) : '1';
	$products       = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_PRODUCTS, true ) : array();
	$trigger        = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_TRIGGER, true ) : 'delay';
	$delay          = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_DELAY, true ) : '5';
	$frequency      = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_FREQUENCY, true ) : 'session';
	$scope          = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_SCOPE, true ) : 'all';
	$scope_products = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_SCOPE_PRODUCTS, true ) : array();
	$scope_pages    = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_SCOPE_PAGES, true ) : array();
	$product_cats   = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_PRODUCT_CATEGORIES, true ) : array();
	$product_tags   = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_PRODUCT_TAGS, true ) : array();
	$audience       = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_AUDIENCE, true ) : 'all';
	$audience       = isset( $options['audiences'][ $audience ] ) ? $audience : 'all';
	$live_count     = $upsell_id ? sella_upsell_count_items( $upsell_id ) : 0;
	$cart_rule      = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_CART_RULE, true ) : 'any';
	$cart_rule      = isset( $options['cart_rules'][ $cart_rule ] ) ? $cart_rule : 'any';
	$cart_products  = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_CART_PRODUCTS, true ) : array();
	$cart_cats      = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_CART_CATEGORIES, true ) : array();
	$cart_products  = is_array( $cart_products ) ? array_map( 'absint', $cart_products ) : array();
	$cart_cats      = is_array( $cart_cats ) ? array_map( 'absint', $cart_cats ) : array();
	$cart_min       = $upsell_id ? (float) get_post_meta( $upsell_id, SELLA_UPSELL_META_CART_MIN_TOTAL, true ) : 0;
	$cart_max       = $upsell_id ? (float) get_post_meta( $upsell_id, SELLA_UPSELL_META_CART_MAX_TOTAL, true ) : 0;
	$cross_sells    = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_CART_CROSS_SELLS, true ) : '0';
	$timer          = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_TIMER, true ) : '0';
	$timer_minutes  = $upsell_id ? absint( get_post_meta( $upsell_id, SELLA_UPSELL_META_TIMER_MINUTES, true ) ) : 15;
	$timer_minutes  = $timer_minutes ? $timer_minutes : 15;
	$timer_label    = $upsell_id ? get_post_meta( $upsell_id, SELLA_UPSELL_META_TIMER_LABEL, true ) : '';
	$timer_label    = '' !== $timer_label ? $timer_label : SELLA_UPSELL_TIMER_DEFAULT_LABEL;
	$products       = is_array( $products ) ? array_map( 'absint', $products ) : array();
	$scope_products = is_array( $scope_products ) ? array_map( 'absint', $scope_products ) : array();
	$scope_pages    = is_array( $scope_pages ) ? array_map( 'absint', $scope_pages ) : array();
	$product_cats   = is_array( $product_cats ) ? array_map( 'absint', $product_cats ) : array();
	$product_tags   = is_array( $product_tags ) ? array_map( 'absint', $product_tags ) : array();
	$categories     = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
	$tags           = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
	$pages          = get_pages( array( 'sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => 'publish' ) );
	$categories     = is_wp_error( $categories ) ? array() : $categories;
	$tags           = is_wp_error( $tags ) ? array() : $tags;
	?>
	<div class="sella-upsell-admin" dir="rtl">
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="sella_upsell_title">שם הפופאפ</label></th>
				<td><input class="regular-text" type="text" id="sella_upsell_title" name="sella_upsell_title" value="<?php echo esc_attr( $title ); ?>" required placeholder="למשל: הצעה אחרי הוספה לסל" /></td>
			</tr>
			<tr>
				<th>סטטוס</th>
				<td><label><input type="checkbox" name="sella_upsell_enabled" value="1" <?php checked( $enabled, '1' ); ?> /> פופאפ פעיל</label></td>
			</tr>
			<tr>
				<th><label for="sella_upsell_products">ספרים שיופיעו</label></th>
				<td>
					<select class="wc-product-search" multiple="multiple" style="width:100%;max-width:700px;" id="sella_upsell_products" name="sella_upsell_products[]" data-placeholder="חפשו והוסיפו ספרים לפי שם. סדר הבחירה הוא סדר הסליידר." data-action="woocommerce_json_search_products">
						<?php foreach ( $products as $product_id ) : $product = wc_get_product( $product_id ); if ( ! $product ) { continue; } ?>
							<option value="<?php echo esc_attr( (string) $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">גררו את הספרים בתוך הרשימה כדי לשנות את הסדר.</p>
				</td>
			</tr>
			<tr>
				<th>מקור הספרים</th>
				<td>
					<p><strong>מוצרים שנבחרו ידנית</strong> נשארים בסדר הסליידר שבחרתם.</p>
					<label for="sella_upsell_product_categories">קטגוריות ספרים</label><br />
					<select id="sella_upsell_product_categories" name="sella_upsell_product_categories[]" multiple="multiple" style="width:100%;max-width:700px;min-height:110px;">
						<?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $product_cats, true ) ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
					</select>
					<br /><label for="sella_upsell_product_tags">תגיות ספרים</label><br />
					<select id="sella_upsell_product_tags" name="sella_upsell_product_tags[]" multiple="multiple" style="width:100%;max-width:700px;min-height:110px;">
						<?php foreach ( $tags as $term ) : ?><option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $product_tags, true ) ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
					</select>
					<p class="description">המוצרים מהקטגוריות והתגיות מצטרפים אחרי המוצרים הידניים, ללא כפילויות.</p>
					<?php if ( $upsell_id ) : ?>
						<p class="sella-upsell-count">
							<strong>סה"כ ספרים שיוצגו בפופאפ הזה: <?php echo esc_html( (string) $live_count ); ?></strong>
							<?php if ( $live_count > SELLA_UPSELL_MAX_ITEMS ) : ?>
								<br /><span class="description">בסליידר עצמו יוצגו <?php echo esc_html( (string) SELLA_UPSELL_MAX_ITEMS ); ?> הספרים הראשונים, כדי לא להכביד על טעינת האתר.</span>
							<?php endif; ?>
							<br /><span class="description">הספירה כוללת רק ספרים שניתן לקנות ושקיימים במלאי, ומתעדכנת אחרי שמירה.</span>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="sella_upsell_cart_rule">תנאי לפי הסל</label></th>
				<td>
					<select id="sella_upsell_cart_rule" name="sella_upsell_cart_rule"><?php foreach ( $options['cart_rules'] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cart_rule, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					<div class="sella-upsell-cart-match" <?php echo in_array( $cart_rule, array( 'contains', 'not_contains' ), true ) ? '' : 'hidden'; ?> style="margin-top:12px;">
						<label for="sella_upsell_cart_products">ספרים בסל</label><br />
						<select class="wc-product-search" multiple="multiple" style="width:100%;max-width:700px;" id="sella_upsell_cart_products" name="sella_upsell_cart_products[]" data-placeholder="בחרו את הספרים שהימצאותם בסל קובעת" data-action="woocommerce_json_search_products">
							<?php foreach ( $cart_products as $product_id ) : $product = wc_get_product( $product_id ); if ( ! $product ) { continue; } ?>
								<option value="<?php echo esc_attr( (string) $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<br /><label for="sella_upsell_cart_categories">קטגוריות בסל</label><br />
						<select id="sella_upsell_cart_categories" name="sella_upsell_cart_categories[]" multiple="multiple" style="width:100%;max-width:700px;min-height:110px;">
							<?php foreach ( $categories as $term ) : ?><option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $cart_cats, true ) ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
						</select>
						<p class="description">מספיק שאחד מהם נמצא בסל כדי שהתנאי יתקיים.</p>
					</div>
					<p style="margin-top:12px;">
						<label>סכום מינימלי בסל <input type="number" min="0" step="1" name="sella_upsell_cart_min_total" value="<?php echo esc_attr( $cart_min ? (string) $cart_min : '' ); ?>" placeholder="ללא" style="width:110px;" /></label>
						<label style="margin-inline-start:14px;">סכום מקסימלי בסל <input type="number" min="0" step="1" name="sella_upsell_cart_max_total" value="<?php echo esc_attr( $cart_max ? (string) $cart_max : '' ); ?>" placeholder="ללא" style="width:110px;" /></label>
					</p>
					<p><label><input type="checkbox" name="sella_upsell_cart_cross_sells" value="1" <?php checked( $cross_sells, '1' ); ?> /> להוסיף לסליידר את המוצרים המשלימים (Cross-sells) של הספרים שכבר בסל</label></p>
					<p class="description">התנאים נבדקים בשרת בכל טעינת עמוד, ומחדש אחרי כל הוספה לסל — כך שהפופאפ מגיב לסל בזמן אמת.</p>
				</td>
			</tr>
			<tr>
				<th><label for="sella_upsell_audience">למי להציג</label></th>
				<td>
					<select id="sella_upsell_audience" name="sella_upsell_audience"><?php foreach ( $options['audiences'] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $audience, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					<p class="description">"רשומים" = גולשים שמחוברים לחשבון באתר. אפשר ליצור פופאפ אחד לרשומים ופופאפ אחר לגולשים אנונימיים.</p>
				</td>
			</tr>
			<tr>
				<th><label for="sella_upsell_trigger">מתי להציג</label></th>
				<td>
					<select id="sella_upsell_trigger" name="sella_upsell_trigger">
						<?php foreach ( $options['triggers'] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $trigger, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select>
					<label class="sella-upsell-delay"> השהיה בשניות <input type="number" min="0" max="120" name="sella_upsell_delay" value="<?php echo esc_attr( (string) $delay ); ?>" /></label>
				</td>
			</tr>
			<tr>
				<th><label for="sella_upsell_frequency">תדירות</label></th>
				<td><select id="sella_upsell_frequency" name="sella_upsell_frequency"><?php foreach ( $options['frequencies'] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $frequency, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
			</tr>
			<tr>
				<th>טיימר</th>
				<td>
					<label><input type="checkbox" id="sella_upsell_timer" name="sella_upsell_timer" value="1" <?php checked( $timer, '1' ); ?> /> להציג טיימר רץ בפופאפ</label>
					<div class="sella-upsell-timer-settings" <?php echo '1' === $timer ? '' : 'hidden'; ?> style="margin-top:10px;">
						<label>אורך הטיימר בדקות <input type="number" min="1" max="1440" name="sella_upsell_timer_minutes" value="<?php echo esc_attr( (string) $timer_minutes ); ?>" style="width:90px;" /></label>
						<label style="margin-inline-start:14px;">טקסט לפני הטיימר <input class="regular-text" type="text" name="sella_upsell_timer_label" value="<?php echo esc_attr( $timer_label ); ?>" /></label>
						<p class="description">לתצוגה בלבד: הטיימר לא משנה מחיר ולא סוגר את ההצעה. הוא נספר מהפעם הראשונה שהגולש רואה את הפופאפ, ממשיך בין עמודים באותו ביקור, ומתחיל מחדש כשהוא מגיע לאפס.</p>
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="sella_upsell_scope">איפה להציג</label></th>
				<td>
					<select id="sella_upsell_scope" name="sella_upsell_scope"><?php foreach ( $options['scopes'] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $scope, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					<div class="sella-upsell-scope-products" <?php echo 'products' === $scope ? '' : 'hidden'; ?> style="margin-top:10px;">
						<select class="wc-product-search" multiple="multiple" style="width:100%;max-width:700px;" name="sella_upsell_scope_products[]" data-placeholder="בחרו את דפי המוצר שבהם להציג" data-action="woocommerce_json_search_products">
							<?php foreach ( $scope_products as $product_id ) : $product = wc_get_product( $product_id ); if ( ! $product ) { continue; } ?>
								<option value="<?php echo esc_attr( (string) $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="sella-upsell-scope-pages" <?php echo 'pages' === $scope ? '' : 'hidden'; ?> style="margin-top:10px;">
						<label for="sella_upsell_scope_pages">עמודים להצגה</label><br />
						<select id="sella_upsell_scope_pages" name="sella_upsell_scope_pages[]" multiple="multiple" style="width:100%;max-width:700px;min-height:140px;">
							<?php foreach ( $pages as $page ) : ?><option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( in_array( (int) $page->ID, $scope_pages, true ) ); ?>><?php echo esc_html( $page->post_title ); ?></option><?php endforeach; ?>
						</select>
					</div>
				</td>
			</tr>
		</table>
		<p><strong>איך זה עובד:</strong> הפופאפ נפתח מהצד, מציג סליידר של הספרים שבחרתם, ומסתיר אוטומטית ספרים שכבר נמצאים בסל.</p>
	</div>
	<?php
}

/**
 * Render management page.
 */
function sella_upsell_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$options = sella_upsell_options();
	$query   = new WP_Query(
		array(
			'post_type'      => SELLA_UPSELL_CPT,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 100,
			'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
		)
	);
	?>
	<div class="wrap" dir="rtl">
		<h1>פופאפים של Upsell</h1>
		<p>צרו הצעות צדדיות לספרים, הגדירו את סדר הסליידר ובחרו מתי והיכן להציג כל הצעה.</p>
		<?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>הפופאפ נשמר בהצלחה.</p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p>הפופאפ נמחק.</p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['error'] ) ) : ?><div class="notice notice-error"><p>לא ניתן לשמור את הפופאפ. בדקו שמילאתם שם.</p></div><?php endif; ?>
		<div class="sella-upsell-admin-grid">
			<div class="sella-upsell-admin-panel">
				<h2><?php echo $edit_id ? 'עריכת פופאפ' : 'פופאפ חדש'; ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'sella_upsell_manage' ); ?>
					<input type="hidden" name="sella_upsell_action" value="save" />
					<input type="hidden" name="upsell_id" value="<?php echo esc_attr( (string) $edit_id ); ?>" />
					<?php sella_upsell_render_form( $edit_id ); ?>
					<?php submit_button( $edit_id ? 'עדכון פופאפ' : 'יצירת פופאפ' ); ?>
					<?php if ( $edit_id ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sella-upsell-popups' ) ); ?>">ביטול עריכה</a><?php endif; ?>
				</form>
			</div>
			<div class="sella-upsell-admin-panel">
				<h2>פופאפים קיימים</h2>
				<?php if ( ! $query->have_posts() ) : ?><p>עדיין לא נוצרו פופאפים.</p><?php else : ?>
				<table class="widefat striped"><thead><tr><th>שם</th><th>ספרים</th><th>טריגר</th><th>קהל</th><th>סטטוס</th><th></th></tr></thead><tbody>
				<?php while ( $query->have_posts() ) : $query->the_post(); $id = get_the_ID(); $count = sella_upsell_count_items( $id ); $trigger_key = get_post_meta( $id, SELLA_UPSELL_META_TRIGGER, true ); $audience_key = get_post_meta( $id, SELLA_UPSELL_META_AUDIENCE, true ); $is_enabled = get_post_meta( $id, SELLA_UPSELL_META_ENABLED, true ); ?>
					<tr><td><strong><?php echo esc_html( get_the_title() ); ?></strong></td><td><?php echo esc_html( (string) $count ); ?><?php if ( $count > SELLA_UPSELL_MAX_ITEMS ) : ?> <span class="description">(מוצגים <?php echo esc_html( (string) SELLA_UPSELL_MAX_ITEMS ); ?>)</span><?php endif; ?></td><td><?php echo esc_html( $options['triggers'][ $trigger_key ] ?? 'אחרי השהיה' ); ?></td><td><?php echo esc_html( $options['audiences'][ $audience_key ] ?? 'כל הגולשים' ); ?></td><td><?php echo '1' === $is_enabled ? 'פעיל' : 'כבוי'; ?></td><td style="white-space:nowrap;"><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=sella-upsell-popups&edit=' . $id ) ); ?>">עריכה</a><form method="post" style="display:inline;"><?php wp_nonce_field( 'sella_upsell_manage' ); ?><input type="hidden" name="sella_upsell_action" value="delete" /><input type="hidden" name="upsell_id" value="<?php echo esc_attr( (string) $id ); ?>" /><button type="submit" class="button button-small button-link-delete">מחיקה</button></form></td></tr>
				<?php endwhile; wp_reset_postdata(); ?>
				</tbody></table>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Load WooCommerce's product search assets for the admin form.
 *
 * @param string $hook Admin hook.
 */
function sella_upsell_admin_assets( $hook ) {
	if ( 'toplevel_page_sella-upsell-popups' !== $hook ) {
		return;
	}

	wp_enqueue_style( 'woocommerce_admin_styles' );
	wp_enqueue_script( 'wc-enhanced-select' );
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_style( 'sella-upsell-admin', get_stylesheet_directory_uri() . '/assets/css/sella-upsell-admin.css', array( 'woocommerce_admin_styles' ), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-upsell-admin', get_stylesheet_directory_uri() . '/assets/js/sella-upsell-admin.js', array( 'jquery', 'wc-enhanced-select', 'jquery-ui-sortable' ), HELLO_ELEMENTOR_CHILD_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'sella_upsell_admin_assets' );

/**
 * Build the ordered product list for a popup.
 *
 * @param int $popup_id Popup ID.
 * @return array<int, int>
 */
function sella_upsell_get_product_ids( $popup_id, $cart_products = array() ) {
	$ids = get_post_meta( $popup_id, SELLA_UPSELL_META_PRODUCTS, true );
	$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();

	// "Books that go with what is already in the cart": WooCommerce cross-sells.
	if ( ! empty( $cart_products ) && '1' === get_post_meta( $popup_id, SELLA_UPSELL_META_CART_CROSS_SELLS, true ) ) {
		$cross = array();
		foreach ( $cart_products as $cart_product_id ) {
			$cart_product = wc_get_product( absint( $cart_product_id ) );
			if ( $cart_product ) {
				$cross = array_merge( $cross, array_map( 'absint', $cart_product->get_cross_sell_ids() ) );
			}
		}
		$ids = array_values( array_unique( array_merge( $ids, array_filter( $cross ) ) ) );
	}

	$tax_query = array( 'relation' => 'OR' );
	$categories = get_post_meta( $popup_id, SELLA_UPSELL_META_PRODUCT_CATEGORIES, true );
	$tags       = get_post_meta( $popup_id, SELLA_UPSELL_META_PRODUCT_TAGS, true );
	if ( is_array( $categories ) && ! empty( $categories ) ) {
		$tax_query[] = array(
			'taxonomy' => 'product_cat',
			'field'    => 'term_id',
			'terms'    => array_map( 'absint', $categories ),
		);
	}
	if ( is_array( $tags ) && ! empty( $tags ) ) {
		$tax_query[] = array(
			'taxonomy' => 'product_tag',
			'field'    => 'term_id',
			'terms'    => array_map( 'absint', $tags ),
		);
	}

	if ( count( $tax_query ) > 1 ) {
		$taxonomy_ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => $tax_query,
			)
		);
		$ids = array_values( array_unique( array_merge( $ids, array_map( 'absint', $taxonomy_ids ) ) ) );
	}

	return $ids;
}

/**
 * Format a price as plain text, without WooCommerce's screen-reader wrappers
 * or HTML entities - the popup prints it as text, not as markup.
 *
 * @param float $amount Price.
 * @return string
 */
function sella_upsell_format_price( $amount ) {
	return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Build the popup slides: purchasable, in-stock books only.
 *
 * @param int             $popup_id Popup ID.
 * @param array<int, int> $exclude  Product IDs to skip (what is already in the cart).
 * @param int             $limit    Maximum slides, 0 for all.
 * @param array<int, int> $cart_products Cart product IDs, for the cross-sell source.
 * @return array<int, array<string, mixed>>
 */
function sella_upsell_collect_items( $popup_id, $exclude = array(), $limit = 0, $cart_products = array() ) {
	$items = array();

	foreach ( sella_upsell_get_product_ids( $popup_id, $cart_products ) as $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id || in_array( $product_id, $exclude, true ) ) {
			continue;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->is_type( 'simple' ) ) {
			continue;
		}

		$price   = wc_get_price_to_display( $product );
		$regular = wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );

		$items[] = array(
			'id'      => $product_id,
			'name'    => $product->get_name(),
			'url'     => get_permalink( $product_id ),
			'price'   => sella_upsell_format_price( $price ),
			'regular' => ( $product->is_on_sale() && $regular > $price ) ? sella_upsell_format_price( $regular ) : '',
			/* "medium" keeps the cover's own proportions; woocommerce_thumbnail is hard-cropped square. */
			'image'   => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: wc_placeholder_img_src( 'medium' ),
		);

		if ( $limit && count( $items ) >= $limit ) {
			break;
		}
	}

	return $items;
}

/**
 * How many books a popup would show right now, ignoring the cart.
 *
 * @param int $popup_id Popup ID.
 * @return int
 */
function sella_upsell_count_items( $popup_id ) {
	return count( sella_upsell_collect_items( $popup_id ) );
}

/**
 * What the cart holds right now: product IDs, their category terms, and the total.
 *
 * @return array{products: array<int, int>, terms: array<int, int>, total: float}
 */
function sella_upsell_cart_snapshot() {
	$snapshot = array(
		'products' => array(),
		'terms'    => array(),
		'total'    => 0.0,
	);

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $snapshot;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$product_id   = absint( $item['product_id'] ?? 0 );
		$variation_id = absint( $item['variation_id'] ?? 0 );

		if ( $product_id ) {
			$snapshot['products'][] = $product_id;
			$terms                  = wc_get_product_term_ids( $product_id, 'product_cat' );
			if ( is_array( $terms ) ) {
				$snapshot['terms'] = array_merge( $snapshot['terms'], array_map( 'absint', $terms ) );
			}
		}
		if ( $variation_id ) {
			$snapshot['products'][] = $variation_id;
		}
	}

	$snapshot['products'] = array_values( array_unique( array_filter( $snapshot['products'] ) ) );
	$snapshot['terms']    = array_values( array_unique( array_filter( $snapshot['terms'] ) ) );
	$snapshot['total']    = method_exists( WC()->cart, 'get_displayed_subtotal' )
		? (float) WC()->cart->get_displayed_subtotal()
		: (float) WC()->cart->get_subtotal();

	return $snapshot;
}

/**
 * Does the cart satisfy this popup's rule?
 *
 * @param int   $popup_id Popup ID.
 * @param array $snapshot Cart snapshot.
 * @return bool
 */
function sella_upsell_cart_rule_passes( $popup_id, $snapshot ) {
	$min   = (float) get_post_meta( $popup_id, SELLA_UPSELL_META_CART_MIN_TOTAL, true );
	$max   = (float) get_post_meta( $popup_id, SELLA_UPSELL_META_CART_MAX_TOTAL, true );
	$total = (float) $snapshot['total'];

	if ( $min > 0 && $total < $min ) {
		return false;
	}
	if ( $max > 0 && $total > $max ) {
		return false;
	}

	$rule = get_post_meta( $popup_id, SELLA_UPSELL_META_CART_RULE, true ) ?: 'any';

	if ( 'empty' === $rule ) {
		return empty( $snapshot['products'] );
	}
	if ( 'not_empty' === $rule ) {
		return ! empty( $snapshot['products'] );
	}
	if ( 'contains' !== $rule && 'not_contains' !== $rule ) {
		return true;
	}

	$products = get_post_meta( $popup_id, SELLA_UPSELL_META_CART_PRODUCTS, true );
	$terms    = get_post_meta( $popup_id, SELLA_UPSELL_META_CART_CATEGORIES, true );
	$products = is_array( $products ) ? array_map( 'absint', $products ) : array();
	$terms    = is_array( $terms ) ? array_map( 'absint', $terms ) : array();

	// A rule with nothing chosen is not a rule.
	if ( empty( $products ) && empty( $terms ) ) {
		return true;
	}

	$matched = ! empty( array_intersect( $products, $snapshot['products'] ) )
		|| ! empty( array_intersect( $terms, $snapshot['terms'] ) );

	return 'contains' === $rule ? $matched : ! $matched;
}

/**
 * Where the shopper is right now, in the popup's own vocabulary.
 *
 * @return array{scope: string, object_id: int}
 */
function sella_upsell_page_context() {
	$scope = 'all';
	if ( function_exists( 'is_product' ) && is_product() ) {
		$scope = 'product';
	} elseif ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
		$scope = 'shop';
	} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
		$scope = 'cart';
	} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
		$scope = 'checkout';
	}

	return array(
		'scope'     => $scope,
		'object_id' => absint( get_queried_object_id() ),
	);
}

/**
 * Return enabled popups that match the current page, shopper and cart.
 *
 * @param array|null $context Page context; read from the query when null.
 * @return array<int, array<string, mixed>>
 */
function sella_upsell_get_active( $context = null ) {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array();
	}

	// An AJAX refresh has no page context of its own, so the browser sends it back.
	if ( is_array( $context ) ) {
		$scope_context = $context['scope'];
		$object_id     = absint( $context['object_id'] );
	} else {
		$context       = sella_upsell_page_context();
		$scope_context = $context['scope'];
		$object_id     = $context['object_id'];
	}

	$query = new WP_Query(
		array(
			'post_type'              => SELLA_UPSELL_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => 20,
			'orderby'                => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		)
	);

	$snapshot = sella_upsell_cart_snapshot();
	$cart_ids = $snapshot['products'];

	$active = array();
	foreach ( $query->posts as $post ) {
		$enabled = get_post_meta( $post->ID, SELLA_UPSELL_META_ENABLED, true );
		if ( '' !== $enabled && '1' !== $enabled ) {
			continue;
		}

		$audience = get_post_meta( $post->ID, SELLA_UPSELL_META_AUDIENCE, true ) ?: 'all';
		if ( 'logged_in' === $audience && ! is_user_logged_in() ) {
			continue;
		}
		if ( 'logged_out' === $audience && is_user_logged_in() ) {
			continue;
		}

		$scope = get_post_meta( $post->ID, SELLA_UPSELL_META_SCOPE, true ) ?: 'all';
		if ( 'all' !== $scope && 'products' !== $scope && 'pages' !== $scope && $scope !== $scope_context ) {
			continue;
		}
		if ( 'products' === $scope ) {
			if ( 'product' !== $scope_context ) {
				continue;
			}
			$allowed = get_post_meta( $post->ID, SELLA_UPSELL_META_SCOPE_PRODUCTS, true );
			if ( ! is_array( $allowed ) || ! in_array( $object_id, array_map( 'absint', $allowed ), true ) ) {
				continue;
			}
		}
		if ( 'pages' === $scope ) {
			$allowed = get_post_meta( $post->ID, SELLA_UPSELL_META_SCOPE_PAGES, true );
			if ( ! is_array( $allowed ) || ! in_array( $object_id, array_map( 'absint', $allowed ), true ) ) {
				continue;
			}
		}

		if ( ! sella_upsell_cart_rule_passes( $post->ID, $snapshot ) ) {
			continue;
		}

		$items = sella_upsell_collect_items( $post->ID, $cart_ids, SELLA_UPSELL_MAX_ITEMS, $cart_ids );
		if ( empty( $items ) ) {
			continue;
		}

		$active[] = array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'items'     => $items,
			'trigger'   => get_post_meta( $post->ID, SELLA_UPSELL_META_TRIGGER, true ) ?: 'delay',
			'delay'     => absint( get_post_meta( $post->ID, SELLA_UPSELL_META_DELAY, true ) ?: 5 ),
			'frequency' => get_post_meta( $post->ID, SELLA_UPSELL_META_FREQUENCY, true ) ?: 'session',
			'timer'     => '1' === get_post_meta( $post->ID, SELLA_UPSELL_META_TIMER, true )
				? array(
					'minutes' => absint( get_post_meta( $post->ID, SELLA_UPSELL_META_TIMER_MINUTES, true ) ?: 15 ),
					'label'   => get_post_meta( $post->ID, SELLA_UPSELL_META_TIMER_LABEL, true ) ?: SELLA_UPSELL_TIMER_DEFAULT_LABEL,
				)
				: null,
		);
	}
	return $active;
}

/**
 * Enqueue the frontend popup and pass server-filtered products to it.
 */
function sella_upsell_frontend_assets() {
	$popups = sella_upsell_get_active();
	if ( empty( $popups ) ) {
		return;
	}

	wp_enqueue_style( 'sella-upsell', get_stylesheet_directory_uri() . '/assets/css/sella-upsell.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-upsell', get_stylesheet_directory_uri() . '/assets/js/sella-upsell.js', array( 'jquery' ), HELLO_ELEMENTOR_CHILD_VERSION, true );
	wp_localize_script(
		'sella-upsell',
		'sellaUpsellData',
		array(
			'popups'     => $popups,
			'ajaxUrl'    => WC_AJAX::get_endpoint( 'sella_upsell_add' ),
			'refreshUrl' => WC_AJAX::get_endpoint( 'sella_upsell_refresh' ),
			'context'    => sella_upsell_page_context(),
			'nonce'      => wp_create_nonce( 'sella_upsell_add' ),
			'cartUrl'    => wc_get_cart_url(),
			'isCart'     => function_exists( 'is_cart' ) && is_cart(),
			'isCheckout' => function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page(),
			'labels'     => array(
				'add'      => 'הוספה לסל',
				'adding'   => 'מוסיף...',
				'added'    => 'נוסף לסל!',
				'error'    => 'לא הצלחנו להוסיף לסל',
				'next'     => 'הבא',
				'previous' => 'הקודם',
				'close'    => 'סגירה',
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'sella_upsell_frontend_assets', 30 );

/**
 * Add a popup book to the cart and report back what the page needs to refresh.
 */
function sella_upsell_ajax_add_to_cart() {
	check_ajax_referer( 'sella_upsell_add', 'nonce' );

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'החנות אינה זמינה כרגע.' ) );
	}

	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;
	$product    = $product_id ? wc_get_product( $product_id ) : false;

	if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		wp_send_json_error( array( 'message' => 'הספר אינו זמין לרכישה.' ) );
	}

	$popup_id    = isset( $_POST['popup_id'] ) ? absint( $_POST['popup_id'] ) : 0;
	$popup_title = ( $popup_id && SELLA_UPSELL_CPT === get_post_type( $popup_id ) ) ? get_the_title( $popup_id ) : '';

	$added = WC()->cart->add_to_cart( $product_id, $quantity );
	if ( ! $added ) {
		$notices = wc_get_notices( 'error' );
		wc_clear_notices();
		wp_send_json_error(
			array(
				'message' => ! empty( $notices[0]['notice'] ) ? wp_strip_all_tags( $notices[0]['notice'] ) : 'לא הצלחנו להוסיף את הספר לסל.',
			)
		);
	}

	sella_upsell_remember_source( $product_id, $popup_id, $popup_title );

	WC()->cart->calculate_totals();
	if ( WC()->session ) {
		WC()->session->set( 'refresh_totals', true );
	}
	wc_clear_notices();

	ob_start();
	woocommerce_mini_cart();
	$mini_cart = ob_get_clean();

	wp_send_json_success(
		array(
			'productId' => $product_id,
			'cartCount' => WC()->cart->get_cart_contents_count(),
			'cartHash'  => WC()->cart->get_cart_hash(),
			'fragments' => apply_filters(
				'woocommerce_add_to_cart_fragments',
				array(
					'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
				)
			),
		)
	);
}
/* WooCommerce's own AJAX endpoint: front-end context, cart and session ready. */
add_action( 'wc_ajax_sella_upsell_add', 'sella_upsell_ajax_add_to_cart' );

/**
 * ---------------------------------------------------------------------------
 * Attribution: which books were added from a popup.
 *
 * Kept in the customer session while they shop, then written onto the order
 * line as meta whose key starts with an underscore, so WooCommerce hides it
 * from the customer while the shop team sees it on the order screen.
 * ---------------------------------------------------------------------------
 */

define( 'SELLA_UPSELL_SESSION_SOURCE', 'sella_upsell_source' );
define( 'SELLA_UPSELL_ITEM_META', '_sella_upsell_source' );
define( 'SELLA_UPSELL_ITEM_META_ID', '_sella_upsell_popup_id' );
define( 'SELLA_UPSELL_ORDER_META', '_sella_upsell_used' );

/**
 * Remember that this book came from a popup.
 *
 * @param int    $product_id  Product added.
 * @param int    $popup_id    Popup ID.
 * @param string $popup_title Popup name.
 */
function sella_upsell_remember_source( $product_id, $popup_id, $popup_title ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	$map = WC()->session->get( SELLA_UPSELL_SESSION_SOURCE );
	$map = is_array( $map ) ? $map : array();

	$map[ absint( $product_id ) ] = array(
		'popup_id' => absint( $popup_id ),
		'title'    => (string) $popup_title,
		'time'     => time(),
	);

	WC()->session->set( SELLA_UPSELL_SESSION_SOURCE, $map );
}

/**
 * Tag the order line the book ended up on.
 *
 * @param WC_Order_Item_Product $item          Line item.
 * @param string                $cart_item_key Cart key.
 * @param array                 $values        Cart item.
 */
function sella_upsell_tag_order_item( $item, $cart_item_key, $values ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	$map = WC()->session->get( SELLA_UPSELL_SESSION_SOURCE );
	if ( ! is_array( $map ) || empty( $map ) ) {
		return;
	}

	$candidates = array( absint( $values['variation_id'] ?? 0 ), absint( $values['product_id'] ?? 0 ) );
	foreach ( $candidates as $product_id ) {
		if ( ! $product_id || ! isset( $map[ $product_id ] ) ) {
			continue;
		}

		$source = $map[ $product_id ];
		$item->add_meta_data( SELLA_UPSELL_ITEM_META, $source['title'] !== '' ? $source['title'] : 'פופאפ Upsell', true );
		if ( ! empty( $source['popup_id'] ) ) {
			$item->add_meta_data( SELLA_UPSELL_ITEM_META_ID, $source['popup_id'], true );
		}
		return;
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'sella_upsell_tag_order_item', 10, 3 );

/**
 * Flag the order itself and leave a private note listing what the popups sold.
 *
 * @param int      $order_id Order ID.
 * @param array    $data     Posted data.
 * @param WC_Order $order    Order.
 */
function sella_upsell_tag_order( $order_id, $data = array(), $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$sold = array();
	foreach ( $order->get_items() as $item ) {
		$source = $item->get_meta( SELLA_UPSELL_ITEM_META );
		if ( $source ) {
			$sold[] = $item->get_name() . ' (' . $source . ')';
		}
	}

	if ( empty( $sold ) ) {
		return;
	}

	$order->update_meta_data( SELLA_UPSELL_ORDER_META, 'yes' );
	$order->add_order_note( 'נמכר דרך פופאפ Upsell: ' . implode( ', ', $sold ) );
	$order->save();

	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( SELLA_UPSELL_SESSION_SOURCE, array() );
	}
}
add_action( 'woocommerce_checkout_order_processed', 'sella_upsell_tag_order', 20, 3 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'sella_upsell_tag_order', 20, 1 );

/**
 * Show the tag under the line item on the admin order screen.
 *
 * @param int                   $item_id Item ID.
 * @param WC_Order_Item_Product $item    Line item.
 */
function sella_upsell_admin_order_item_flag( $item_id, $item ) {
	if ( ! is_admin() || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
		return;
	}

	$source = $item->get_meta( SELLA_UPSELL_ITEM_META );
	if ( ! $source ) {
		return;
	}

	echo '<div class="sella-upsell-order-flag" style="margin-top:6px;padding:3px 8px;display:inline-block;border-radius:3px;background:#efeefe;color:#4b46c9;font-size:12px;font-weight:600;">נוסף מפופאפ: ' . esc_html( $source ) . '</div>';
}
add_action( 'woocommerce_after_order_itemmeta', 'sella_upsell_admin_order_item_flag', 10, 2 );

/**
 * Re-evaluate the popups for the cart as it is now.
 */
function sella_upsell_ajax_refresh() {
	check_ajax_referer( 'sella_upsell_add', 'nonce' );

	$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
	if ( ! in_array( $scope, array( 'all', 'product', 'shop', 'cart', 'checkout' ), true ) ) {
		$scope = 'all';
	}

	wp_send_json_success(
		array(
			'popups' => sella_upsell_get_active(
				array(
					'scope'     => $scope,
					'object_id' => isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : 0,
				)
			),
		)
	);
}
add_action( 'wc_ajax_sella_upsell_refresh', 'sella_upsell_ajax_refresh' );
