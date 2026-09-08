<?php
/**
 * Product badges / tags for shop products.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_BADGE_CPT', 'sella_badge' );
define( 'SELLA_BADGE_META_COLOR', '_sella_badge_color' );
define( 'SELLA_BADGE_META_TEXT_COLOR', '_sella_badge_text_color' );
define( 'SELLA_BADGE_META_SCOPE', '_sella_badge_scope' );
define( 'SELLA_BADGE_META_PRODUCTS', '_sella_badge_products' );
define( 'SELLA_BADGE_META_CATEGORIES', '_sella_badge_categories' );
define( 'SELLA_BADGE_META_ENABLED', '_sella_badge_enabled' );

/**
 * Register badge CPT (storage only — managed via custom admin page).
 */
function sella_badge_register_cpt() {
	register_post_type(
		SELLA_BADGE_CPT,
		array(
			'labels'             => array(
				'name'          => 'תגיות לחנות',
				'singular_name' => 'תגית לחנות',
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'capability_type'    => 'post',
			'map_meta_cap'       => false,
			'capabilities'       => array(
				'edit_post'              => 'manage_woocommerce',
				'read_post'              => 'manage_woocommerce',
				'delete_post'            => 'manage_woocommerce',
				'edit_posts'             => 'manage_woocommerce',
				'edit_others_posts'      => 'manage_woocommerce',
				'publish_posts'          => 'manage_woocommerce',
				'read_private_posts'     => 'manage_woocommerce',
				'delete_posts'           => 'manage_woocommerce',
				'delete_others_posts'    => 'manage_woocommerce',
				'delete_private_posts'   => 'manage_woocommerce',
				'delete_published_posts' => 'manage_woocommerce',
				'edit_private_posts'     => 'manage_woocommerce',
				'edit_published_posts'   => 'manage_woocommerce',
				'create_posts'           => 'manage_woocommerce',
			),
			'hierarchical'       => false,
			'supports'           => array( 'title', 'page-attributes' ),
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
			'show_in_rest'       => false,
		)
	);
}
add_action( 'init', 'sella_badge_register_cpt' );

/**
 * Clear badge query cache after changes.
 */
function sella_badge_bust_cache() {
	// Static caches reset per-request; this is a hook point for future object-cache.
	wp_cache_delete( 'sella_badge_all_enabled', 'sella' );
}

/**
 * Persist badge meta from request/array.
 *
 * @param int   $post_id Post ID.
 * @param array $source  Source data (usually $_POST).
 */
function sella_badge_persist_meta( $post_id, $source ) {
	$enabled = ! empty( $source['sella_badge_enabled'] ) ? '1' : '0';
	update_post_meta( $post_id, SELLA_BADGE_META_ENABLED, $enabled );

	$color = isset( $source['sella_badge_color'] ) ? sanitize_hex_color( wp_unslash( $source['sella_badge_color'] ) ) : '';
	update_post_meta( $post_id, SELLA_BADGE_META_COLOR, $color ? $color : '#c45c26' );

	$text_color = isset( $source['sella_badge_text_color'] ) ? sanitize_hex_color( wp_unslash( $source['sella_badge_text_color'] ) ) : '';
	update_post_meta( $post_id, SELLA_BADGE_META_TEXT_COLOR, $text_color ? $text_color : '#ffffff' );

	$scope = isset( $source['sella_badge_scope'] ) ? sanitize_key( wp_unslash( $source['sella_badge_scope'] ) ) : 'products';
	if ( ! in_array( $scope, array( 'products', 'categories' ), true ) ) {
		$scope = 'products';
	}
	update_post_meta( $post_id, SELLA_BADGE_META_SCOPE, $scope );

	$products = array();
	if ( ! empty( $source['sella_badge_products'] ) && is_array( $source['sella_badge_products'] ) ) {
		$products = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_badge_products'] ) ) ) ) );
	}
	update_post_meta( $post_id, SELLA_BADGE_META_PRODUCTS, $products );

	$categories = array();
	if ( ! empty( $source['sella_badge_categories'] ) && is_array( $source['sella_badge_categories'] ) ) {
		$categories = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source['sella_badge_categories'] ) ) ) ) );
	}
	update_post_meta( $post_id, SELLA_BADGE_META_CATEGORIES, $categories );

	sella_badge_bust_cache();
}

/**
 * Custom admin menu — this is where labels are created/edited.
 */
function sella_badge_register_admin_page() {
	add_menu_page(
		'תגיות לחנות',
		'תגיות לחנות',
		'manage_woocommerce',
		'sella-shop-badges',
		'sella_badge_render_manage_page',
		'dashicons-tag',
		58
	);
}
add_action( 'admin_menu', 'sella_badge_register_admin_page' );

/**
 * Handle create / update / delete from the manage page.
 */
function sella_badge_handle_manage_actions() {
	if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( empty( $_POST['sella_badge_manage_action'] ) || empty( $_POST['_wpnonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sella_badge_manage' ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['sella_badge_manage_action'] ) );

	if ( 'delete' === $action ) {
		$badge_id = isset( $_POST['badge_id'] ) ? absint( $_POST['badge_id'] ) : 0;
		if ( $badge_id && SELLA_BADGE_CPT === get_post_type( $badge_id ) ) {
			wp_trash_post( $badge_id );
			sella_badge_bust_cache();
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'sella-shop-badges', 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	if ( 'save' === $action ) {
		$badge_id = isset( $_POST['badge_id'] ) ? absint( $_POST['badge_id'] ) : 0;
		$label    = isset( $_POST['sella_badge_label'] ) ? sanitize_text_field( wp_unslash( $_POST['sella_badge_label'] ) ) : '';

		if ( '' === $label ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'sella-shop-badges', 'error' => 'empty' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( $badge_id && SELLA_BADGE_CPT === get_post_type( $badge_id ) ) {
			wp_update_post(
				array(
					'ID'         => $badge_id,
					'post_title' => $label,
					'post_status'=> 'publish',
				)
			);
		} else {
			$badge_id = wp_insert_post(
				array(
					'post_type'   => SELLA_BADGE_CPT,
					'post_title'  => $label,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $badge_id ) ) {
				wp_safe_redirect( add_query_arg( array( 'page' => 'sella-shop-badges', 'error' => 'save' ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		sella_badge_persist_meta( $badge_id, $_POST );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'sella-shop-badges',
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
add_action( 'admin_init', 'sella_badge_handle_manage_actions' );

/**
 * Restore WooCommerce "All Products" / "Add New" if a bad submenu registration removed them.
 */
function sella_restore_woocommerce_products_menu() {
	global $submenu;

	$parent = 'edit.php?post_type=product';
	if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
		return;
	}

	foreach ( $submenu[ $parent ] as $index => $item ) {
		$slug = isset( $item[2] ) ? (string) $item[2] : '';
		if ( 'edit.php?post_type=' . SELLA_BADGE_CPT === $slug || false !== strpos( $slug, 'sella_badge' ) ) {
			unset( $submenu[ $parent ][ $index ] );
		}
	}
	$submenu[ $parent ] = array_values( $submenu[ $parent ] );

	$slugs = array();
	foreach ( $submenu[ $parent ] as $item ) {
		if ( isset( $item[2] ) ) {
			$slugs[] = $item[2];
		}
	}

	$prepend = array();

	if ( ! in_array( 'edit.php?post_type=product', $slugs, true ) ) {
		$prepend[] = array( 'כל המוצרים', 'edit_products', 'edit.php?post_type=product' );
	}

	if ( ! in_array( 'post-new.php?post_type=product', $slugs, true ) ) {
		$prepend[] = array( 'הוסף חדש', 'edit_products', 'post-new.php?post_type=product' );
	}

	if ( ! empty( $prepend ) ) {
		$submenu[ $parent ] = array_merge( $prepend, $submenu[ $parent ] );
	}
}
add_action( 'admin_menu', 'sella_restore_woocommerce_products_menu', 9999 );

/**
 * Render fields for create/edit form.
 *
 * @param int $badge_id Badge ID (0 = new).
 */
function sella_badge_render_form_fields( $badge_id = 0 ) {
	$label      = $badge_id ? get_the_title( $badge_id ) : '';
	$color      = $badge_id ? ( get_post_meta( $badge_id, SELLA_BADGE_META_COLOR, true ) ?: '#c45c26' ) : '#c45c26';
	$text_color = $badge_id ? ( get_post_meta( $badge_id, SELLA_BADGE_META_TEXT_COLOR, true ) ?: '#ffffff' ) : '#ffffff';
	$scope      = $badge_id ? ( get_post_meta( $badge_id, SELLA_BADGE_META_SCOPE, true ) ?: 'products' ) : 'products';
	$products   = $badge_id ? get_post_meta( $badge_id, SELLA_BADGE_META_PRODUCTS, true ) : array();
	$categories = $badge_id ? get_post_meta( $badge_id, SELLA_BADGE_META_CATEGORIES, true ) : array();
	$enabled    = $badge_id ? get_post_meta( $badge_id, SELLA_BADGE_META_ENABLED, true ) : '1';
	$enabled    = ( '' === $enabled ) ? '1' : $enabled;

	if ( ! is_array( $products ) ) {
		$products = array();
	}
	if ( ! is_array( $categories ) ) {
		$categories = array();
	}
	$products   = array_map( 'absint', $products );
	$categories = array_map( 'absint', $categories );

	$all_categories = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	if ( is_wp_error( $all_categories ) ) {
		$all_categories = array();
	}

	$preview = $label ? $label : 'טקסט התגית';
	?>
	<div class="sella-badge-admin" dir="rtl">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sella_badge_label">טקסט התגית (LABEL)</label></th>
				<td>
					<input type="text" class="regular-text" name="sella_badge_label" id="sella_badge_label" value="<?php echo esc_attr( $label ); ?>" required placeholder="למשל: חדש, מבצע, רבי מכר" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sella_badge_enabled">פעילה</label></th>
				<td>
					<label>
						<input type="checkbox" name="sella_badge_enabled" id="sella_badge_enabled" value="1" <?php checked( $enabled, '1' ); ?> />
						הצג תגית זו בחנות
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sella_badge_color">צבע רקע</label></th>
				<td><input type="text" class="sella-color-field" name="sella_badge_color" id="sella_badge_color" value="<?php echo esc_attr( $color ); ?>" data-default-color="#c45c26" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="sella_badge_text_color">צבע טקסט</label></th>
				<td><input type="text" class="sella-color-field" name="sella_badge_text_color" id="sella_badge_text_color" value="<?php echo esc_attr( $text_color ); ?>" data-default-color="#ffffff" /></td>
			</tr>
			<tr>
				<th scope="row">תצוגה מקדימה</th>
				<td>
					<span class="sella-badge-preview sella-badge-preview--live" style="background:<?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $text_color ); ?>;">
						<?php echo esc_html( $preview ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<th scope="row">הצגה לפי</th>
				<td>
					<fieldset>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="sella_badge_scope" value="products" <?php checked( $scope, 'products' ); ?> />
							מוצרים ספציפיים (בחירה מרשימה לפי שם)
						</label>
						<label style="display:block;">
							<input type="radio" name="sella_badge_scope" value="categories" <?php checked( $scope, 'categories' ); ?> />
							קטגוריות מוצרים (בחירה מרשימה)
						</label>
					</fieldset>
				</td>
			</tr>
			<tr class="sella-badge-scope-row" data-scope="products">
				<th scope="row"><label for="sella_badge_products">מוצרים</label></th>
				<td>
					<select class="wc-product-search" multiple="multiple" style="width:100%;max-width:640px;" id="sella_badge_products" name="sella_badge_products[]" data-placeholder="חפשו והוסיפו מוצרים לפי שם..." data-action="woocommerce_json_search_products" data-allow_clear="true">
						<?php
						foreach ( $products as $product_id ) {
							$product = wc_get_product( $product_id );
							if ( ! $product ) {
								continue;
							}
							echo '<option value="' . esc_attr( (string) $product_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
						}
						?>
					</select>
				</td>
			</tr>
			<tr class="sella-badge-scope-row" data-scope="categories">
				<th scope="row"><label for="sella_badge_categories">קטגוריות</label></th>
				<td>
					<select id="sella_badge_categories" name="sella_badge_categories[]" multiple="multiple" class="sella-category-select" style="width:100%;max-width:640px;min-height:140px;">
						<?php foreach ( $all_categories as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $categories, true ) ); ?>>
								<?php echo esc_html( $term->name . ' (' . (int) $term->count . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
	</div>
	<?php
}

/**
 * Main manage page.
 */
function sella_badge_render_manage_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;

	$query = new WP_Query(
		array(
			'post_type'      => SELLA_BADGE_CPT,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 100,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		)
	);
	?>
	<div class="wrap" dir="rtl">
		<h1>תגיות לחנות</h1>
		<p>כאן מגדירים את ה־LABEL שמופיע על הספרים בחנות: טקסט, צבע, ומוצרים/קטגוריות.</p>

		<?php if ( ! empty( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>התגית נשמרה בהצלחה.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>התגית הועברה לפח.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['error'] ) && 'empty' === $_GET['error'] ) : ?>
			<div class="notice notice-error is-dismissible"><p>חובה למלא טקסט לתגית.</p></div>
		<?php endif; ?>

		<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:24px;align-items:start;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;">
				<h2 style="margin-top:0;"><?php echo $edit_id ? 'עריכת תגית' : 'תגית חדשה'; ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'sella_badge_manage' ); ?>
					<input type="hidden" name="sella_badge_manage_action" value="save" />
					<input type="hidden" name="badge_id" value="<?php echo esc_attr( (string) $edit_id ); ?>" />
					<?php sella_badge_render_form_fields( $edit_id ); ?>
					<?php submit_button( $edit_id ? 'עדכן תגית' : 'צור תגית' ); ?>
					<?php if ( $edit_id ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sella-shop-badges' ) ); ?>">ביטול עריכה</a>
					<?php endif; ?>
				</form>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;">
				<h2 style="margin-top:0;">תגיות קיימות</h2>
				<?php if ( ! $query->have_posts() ) : ?>
					<p>עדיין אין תגיות. צרו את הראשונה בטופס.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>תצוגה</th>
								<th>שיוך</th>
								<th>סטטוס</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							while ( $query->have_posts() ) :
								$query->the_post();
								$bid        = get_the_ID();
								$color      = get_post_meta( $bid, SELLA_BADGE_META_COLOR, true ) ?: '#c45c26';
								$text_color = get_post_meta( $bid, SELLA_BADGE_META_TEXT_COLOR, true ) ?: '#ffffff';
								$scope      = get_post_meta( $bid, SELLA_BADGE_META_SCOPE, true );
								$enabled    = get_post_meta( $bid, SELLA_BADGE_META_ENABLED, true );
								$enabled    = ( '' === $enabled ) ? '1' : $enabled;
								if ( 'categories' === $scope ) {
									$cats  = get_post_meta( $bid, SELLA_BADGE_META_CATEGORIES, true );
									$scope_label = 'קטגוריות (' . ( is_array( $cats ) ? count( $cats ) : 0 ) . ')';
								} else {
									$prods = get_post_meta( $bid, SELLA_BADGE_META_PRODUCTS, true );
									$scope_label = 'מוצרים (' . ( is_array( $prods ) ? count( $prods ) : 0 ) . ')';
								}
								?>
								<tr>
									<td>
										<span class="sella-badge-chip" style="background:<?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $text_color ); ?>;">
											<?php echo esc_html( get_the_title() ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $scope_label ); ?></td>
									<td><?php echo '1' === $enabled ? 'פעילה' : 'כבויה'; ?></td>
									<td style="white-space:nowrap;">
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=sella-shop-badges&edit=' . $bid ) ); ?>">עריכה</a>
										<form method="post" style="display:inline;" onsubmit="return confirm('למחוק את התגית?');">
											<?php wp_nonce_field( 'sella_badge_manage' ); ?>
											<input type="hidden" name="sella_badge_manage_action" value="delete" />
											<input type="hidden" name="badge_id" value="<?php echo esc_attr( (string) $bid ); ?>" />
											<button type="submit" class="button button-small button-link-delete">מחיקה</button>
										</form>
									</td>
								</tr>
							<?php endwhile; ?>
							<?php wp_reset_postdata(); ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Admin assets for the manage page.
 *
 * @param string $hook Hook.
 */
function sella_badge_admin_assets( $hook ) {
	if ( 'toplevel_page_sella-shop-badges' !== $hook ) {
		return;
	}

	wp_enqueue_style( 'woocommerce_admin_styles' );
	wp_enqueue_script( 'wc-enhanced-select' );
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );

	wp_enqueue_style(
		'sella-product-badges-admin',
		get_stylesheet_directory_uri() . '/assets/css/product-badges-admin.css',
		array( 'woocommerce_admin_styles', 'wp-color-picker' ),
		HELLO_ELEMENTOR_CHILD_VERSION
	);

	wp_enqueue_script(
		'sella-product-badges-admin',
		get_stylesheet_directory_uri() . '/assets/js/product-badges-admin.js',
		array( 'jquery', 'wp-color-picker', 'wc-enhanced-select' ),
		HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'sella_badge_admin_assets' );

/**
 * Get all enabled badges.
 *
 * @return array<int, array<string, mixed>>
 */
function sella_badge_get_all_enabled() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$query = new WP_Query(
		array(
			'post_type'              => SELLA_BADGE_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		)
	);

	$badges = array();
	foreach ( $query->posts as $post ) {
		$enabled = get_post_meta( $post->ID, SELLA_BADGE_META_ENABLED, true );
		if ( '' !== $enabled && '1' !== $enabled ) {
			continue;
		}

		$products   = get_post_meta( $post->ID, SELLA_BADGE_META_PRODUCTS, true );
		$categories = get_post_meta( $post->ID, SELLA_BADGE_META_CATEGORIES, true );

		$badges[] = array(
			'id'         => $post->ID,
			'text'       => get_the_title( $post ),
			'color'      => get_post_meta( $post->ID, SELLA_BADGE_META_COLOR, true ) ?: '#c45c26',
			'text_color' => get_post_meta( $post->ID, SELLA_BADGE_META_TEXT_COLOR, true ) ?: '#ffffff',
			'scope'      => get_post_meta( $post->ID, SELLA_BADGE_META_SCOPE, true ) ?: 'products',
			'products'   => is_array( $products ) ? array_map( 'absint', $products ) : array(),
			'categories' => is_array( $categories ) ? array_map( 'absint', $categories ) : array(),
		);
	}

	$cache = $badges;
	return $cache;
}

/**
 * Badges that apply to a product.
 *
 * @param int $product_id Product ID.
 * @return array<int, array<string, mixed>>
 */
function sella_badge_get_for_product( $product_id ) {
	$product_id = absint( $product_id );
	if ( ! $product_id ) {
		return array();
	}

	$product_cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $product_cats ) ) {
		$product_cats = array();
	}
	$product_cats = array_map( 'absint', $product_cats );

	$matched = array();
	foreach ( sella_badge_get_all_enabled() as $badge ) {
		if ( 'categories' === $badge['scope'] ) {
			if ( empty( $badge['categories'] ) ) {
				continue;
			}
			if ( array_intersect( $badge['categories'], $product_cats ) ) {
				$matched[] = $badge;
			}
			continue;
		}

		if ( in_array( $product_id, $badge['products'], true ) ) {
			$matched[] = $badge;
		}
	}

	return $matched;
}

/**
 * Render badge HTML for a product.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function sella_badge_render_html( $product_id ) {
	$badges = sella_badge_get_for_product( $product_id );
	if ( empty( $badges ) ) {
		return '';
	}

	$html = '<div class="sella-product-badges" aria-hidden="false">';
	foreach ( $badges as $badge ) {
		$html .= sprintf(
			'<span class="sella-product-badge" style="background-color:%1$s;color:%2$s;">%3$s</span>',
			esc_attr( $badge['color'] ),
			esc_attr( $badge['text_color'] ),
			esc_html( $badge['text'] )
		);
	}
	$html .= '</div>';

	return $html;
}

/**
 * Build product_id => badges map for frontend injection into custom book cards.
 *
 * @return array<string, array<int, array<string, string>>>
 */
function sella_badge_build_product_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();

	foreach ( sella_badge_get_all_enabled() as $badge ) {
		$product_ids = array();

		if ( 'categories' === $badge['scope'] ) {
			if ( empty( $badge['categories'] ) ) {
				continue;
			}

			$query = new WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $badge['categories'],
						),
					),
				)
			);
			$product_ids = array_map( 'absint', $query->posts );
		} else {
			$product_ids = $badge['products'];
		}

		$entry = array(
			'text'       => $badge['text'],
			'color'      => $badge['color'],
			'text_color' => $badge['text_color'],
		);

		foreach ( $product_ids as $product_id ) {
			if ( ! $product_id ) {
				continue;
			}
			$key = (string) $product_id;
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array();
			}
			$map[ $key ][] = $entry;
		}
	}

	return $map;
}

/**
 * Attach badges to product images (works where WooCommerce get_image is used).
 *
 * @param string     $image Image HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function sella_badge_filter_product_image( $image, $product ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $image;
	}

	if ( ! $product instanceof WC_Product ) {
		return $image;
	}

	if ( false !== strpos( $image, 'sella-product-badges' ) ) {
		return $image;
	}

	$badges = sella_badge_render_html( $product->get_id() );
	if ( ! $badges ) {
		return $image;
	}

	return '<span class="sella-product-image-with-badges">' . $image . $badges . '</span>';
}
add_filter( 'woocommerce_product_get_image', 'sella_badge_filter_product_image', 20, 2 );

/**
 * Frontend assets — including injection into custom .book-card-item grids.
 */
function sella_badge_frontend_assets() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	wp_enqueue_style(
		'sella-product-badges',
		get_stylesheet_directory_uri() . '/assets/css/product-badges.css',
		array(),
		HELLO_ELEMENTOR_CHILD_VERSION
	);

	$map = sella_badge_build_product_map();
	if ( empty( $map ) ) {
		return;
	}

	wp_enqueue_script(
		'sella-product-badges',
		get_stylesheet_directory_uri() . '/assets/js/product-badges.js',
		array(),
		HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);

	wp_localize_script(
		'sella-product-badges',
		'sellaProductBadges',
		array(
			'map' => $map,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'sella_badge_frontend_assets', 25 );

/**
 * Ensure CSS/JS when shortcode/manual render is used.
 */
function sella_badge_enqueue_with_wc() {
	sella_badge_frontend_assets();
}

/**
 * Shortcode: [sella_product_badges] or [sella_product_badges id="123"]
 *
 * @param array $atts Atts.
 * @return string
 */
function sella_badge_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id' => 0,
		),
		$atts,
		'sella_product_badges'
	);

	$product_id = absint( $atts['id'] );
	if ( ! $product_id && function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( get_the_ID() );
		if ( $product ) {
			$product_id = $product->get_id();
		}
	}

	if ( ! $product_id ) {
		return '';
	}

	sella_badge_enqueue_with_wc();
	return sella_badge_render_html( $product_id );
}
add_shortcode( 'sella_product_badges', 'sella_badge_shortcode' );
