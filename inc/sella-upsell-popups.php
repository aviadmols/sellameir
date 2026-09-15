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

	if ( ! isset( $options['triggers'][ $trigger ] ) ) {
		$trigger = 'delay';
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
				<table class="widefat striped"><thead><tr><th>שם</th><th>ספרים</th><th>טריגר</th><th>סטטוס</th><th></th></tr></thead><tbody>
				<?php while ( $query->have_posts() ) : $query->the_post(); $id = get_the_ID(); $ids = get_post_meta( $id, SELLA_UPSELL_META_PRODUCTS, true ); $trigger_key = get_post_meta( $id, SELLA_UPSELL_META_TRIGGER, true ); $is_enabled = get_post_meta( $id, SELLA_UPSELL_META_ENABLED, true ); ?>
					<tr><td><strong><?php echo esc_html( get_the_title() ); ?></strong></td><td><?php echo esc_html( is_array( $ids ) ? (string) count( $ids ) : '0' ); ?></td><td><?php echo esc_html( $options['triggers'][ $trigger_key ] ?? 'אחרי השהיה' ); ?></td><td><?php echo '1' === $is_enabled ? 'פעיל' : 'כבוי'; ?></td><td style="white-space:nowrap;"><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=sella-upsell-popups&edit=' . $id ) ); ?>">עריכה</a><form method="post" style="display:inline;"><?php wp_nonce_field( 'sella_upsell_manage' ); ?><input type="hidden" name="sella_upsell_action" value="delete" /><input type="hidden" name="upsell_id" value="<?php echo esc_attr( (string) $id ); ?>" /><button type="submit" class="button button-small button-link-delete">מחיקה</button></form></td></tr>
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
function sella_upsell_get_product_ids( $popup_id ) {
	$ids = get_post_meta( $popup_id, SELLA_UPSELL_META_PRODUCTS, true );
	$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();

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
 * Return enabled popups that match the current page.
 *
 * @return array<int, array<string, mixed>>
 */
function sella_upsell_get_active() {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array();
	}

	$scope_context = 'all';
	if ( function_exists( 'is_product' ) && is_product() ) {
		$scope_context = 'product';
	} elseif ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
		$scope_context = 'shop';
	} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
		$scope_context = 'cart';
	} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
		$scope_context = 'checkout';
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

	$cart_ids = array();
	if ( WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			$cart_ids[] = absint( $item['product_id'] ?? 0 );
			$cart_ids[] = absint( $item['variation_id'] ?? 0 );
		}
		$cart_ids = array_filter( array_unique( $cart_ids ) );
	}

	$active = array();
	foreach ( $query->posts as $post ) {
		$enabled = get_post_meta( $post->ID, SELLA_UPSELL_META_ENABLED, true );
		if ( '' !== $enabled && '1' !== $enabled ) {
			continue;
		}

		$scope = get_post_meta( $post->ID, SELLA_UPSELL_META_SCOPE, true ) ?: 'all';
		if ( 'all' !== $scope && 'products' !== $scope && 'pages' !== $scope && $scope !== $scope_context ) {
			continue;
		}
		if ( 'products' === $scope ) {
			if ( ! is_product() ) {
				continue;
			}
			$allowed = get_post_meta( $post->ID, SELLA_UPSELL_META_SCOPE_PRODUCTS, true );
			$current = get_the_ID();
			if ( ! is_array( $allowed ) || ! in_array( $current, array_map( 'absint', $allowed ), true ) ) {
				continue;
			}
		}
		if ( 'pages' === $scope ) {
			$allowed = get_post_meta( $post->ID, SELLA_UPSELL_META_SCOPE_PAGES, true );
			$current = get_queried_object_id();
			if ( ! is_array( $allowed ) || ! in_array( absint( $current ), array_map( 'absint', $allowed ), true ) ) {
				continue;
			}
		}

		$items = array();
		$product_ids = sella_upsell_get_product_ids( $post->ID );
		foreach ( $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id || in_array( $product_id, $cart_ids, true ) ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->is_type( 'simple' ) ) {
				continue;
			}
			$items[] = array(
				'id'    => $product_id,
				'name'  => $product->get_name(),
				'price' => wp_strip_all_tags( $product->get_price_html() ),
				'image' => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: wc_placeholder_img_src( 'medium' ),
			);
		}
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
			'popups' => $popups,
			'ajaxUrl' => WC_AJAX::get_endpoint( 'add_to_cart' ),
			'cartUrl' => wc_get_cart_url(),
			'currency' => get_woocommerce_currency_symbol(),
			'labels' => array(
				'add'     => 'הוספה לסל',
				'added'   => 'נוסף לסל',
				'next'    => 'הבא',
				'previous'=> 'הקודם',
				'close'   => 'סגירה',
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'sella_upsell_frontend_assets', 30 );
