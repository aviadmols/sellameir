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
 * Register badge CPT.
 */
function sella_badge_register_cpt() {
	register_post_type(
		SELLA_BADGE_CPT,
		array(
			'labels'              => array(
				'name'               => 'תגיות מוצרים',
				'singular_name'      => 'תגית מוצר',
				'add_new'            => 'תגית חדשה',
				'add_new_item'       => 'הוספת תגית',
				'edit_item'          => 'עריכת תגית',
				'new_item'           => 'תגית חדשה',
				'view_item'          => 'צפייה בתגית',
				'search_items'       => 'חיפוש תגיות',
				'not_found'          => 'לא נמצאו תגיות',
				'not_found_in_trash' => 'לא נמצאו תגיות בפח',
				'menu_name'          => 'תגיות מוצרים',
				'all_items'          => 'כל התגיות',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=product',
			'capability_type'     => 'product',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'page-attributes' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'show_in_rest'        => false,
			'menu_position'       => 56,
		)
	);
}
add_action( 'init', 'sella_badge_register_cpt' );

/**
 * Meta box.
 */
function sella_badge_add_meta_boxes() {
	add_meta_box(
		'sella_badge_settings',
		'הגדרות תגית',
		'sella_badge_render_meta_box',
		SELLA_BADGE_CPT,
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'sella_badge_add_meta_boxes' );

/**
 * @param WP_Post $post Post.
 */
function sella_badge_render_meta_box( $post ) {
	wp_nonce_field( 'sella_badge_save', 'sella_badge_nonce' );

	$color      = get_post_meta( $post->ID, SELLA_BADGE_META_COLOR, true ) ?: '#c45c26';
	$text_color = get_post_meta( $post->ID, SELLA_BADGE_META_TEXT_COLOR, true ) ?: '#ffffff';
	$scope      = get_post_meta( $post->ID, SELLA_BADGE_META_SCOPE, true ) ?: 'products';
	$products   = get_post_meta( $post->ID, SELLA_BADGE_META_PRODUCTS, true );
	$categories = get_post_meta( $post->ID, SELLA_BADGE_META_CATEGORIES, true );
	$enabled    = get_post_meta( $post->ID, SELLA_BADGE_META_ENABLED, true );
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

	$preview_text = $post->post_title ? $post->post_title : 'טקסט התגית';
	?>
	<div class="sella-badge-admin" dir="rtl">
		<p class="description">כותרת התגית למעלה היא הטקסט שיופיע על המוצר בחנות.</p>

		<table class="form-table" role="presentation">
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
				<td>
					<input type="text" class="sella-color-field" name="sella_badge_color" id="sella_badge_color" value="<?php echo esc_attr( $color ); ?>" data-default-color="#c45c26" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sella_badge_text_color">צבע טקסט</label></th>
				<td>
					<input type="text" class="sella-color-field" name="sella_badge_text_color" id="sella_badge_text_color" value="<?php echo esc_attr( $text_color ); ?>" data-default-color="#ffffff" />
				</td>
			</tr>
			<tr>
				<th scope="row">תצוגה מקדימה</th>
				<td>
					<span class="sella-badge-preview" style="background:<?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $text_color ); ?>;">
						<?php echo esc_html( $preview_text ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<th scope="row">הצגה לפי</th>
				<td>
					<fieldset>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="sella_badge_scope" value="products" <?php checked( $scope, 'products' ); ?> />
							מוצרים ספציפיים (בחירה מרשימה)
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
					<select class="wc-product-search" multiple="multiple" style="width:100%;" id="sella_badge_products" name="sella_badge_products[]" data-placeholder="חפשו והוסיפו מוצרים לפי שם..." data-action="woocommerce_json_search_products" data-allow_clear="true">
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
					<p class="description">בחרו מהרשימה לפי שם המוצר — לא לפי מק״ט.</p>
				</td>
			</tr>
			<tr class="sella-badge-scope-row" data-scope="categories">
				<th scope="row"><label for="sella_badge_categories">קטגוריות</label></th>
				<td>
					<select id="sella_badge_categories" name="sella_badge_categories[]" multiple="multiple" class="sella-category-select" style="width:100%;min-height:140px;">
						<?php foreach ( $all_categories as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $categories, true ) ); ?>>
								<?php echo esc_html( $term->name . ' (' . (int) $term->count . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">בחרו קטגוריה אחת או יותר מהרשימה. התגית תופיע על כל המוצרים בקטגוריות שנבחרו.</p>
				</td>
			</tr>
		</table>
	</div>
	<?php
}

/**
 * Save badge meta.
 *
 * @param int $post_id Post ID.
 */
function sella_badge_save_meta( $post_id ) {
	if ( ! isset( $_POST['sella_badge_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sella_badge_nonce'] ) ), 'sella_badge_save' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( SELLA_BADGE_CPT !== get_post_type( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$enabled = isset( $_POST['sella_badge_enabled'] ) ? '1' : '0';
	update_post_meta( $post_id, SELLA_BADGE_META_ENABLED, $enabled );

	$color = isset( $_POST['sella_badge_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['sella_badge_color'] ) ) : '';
	update_post_meta( $post_id, SELLA_BADGE_META_COLOR, $color ? $color : '#c45c26' );

	$text_color = isset( $_POST['sella_badge_text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['sella_badge_text_color'] ) ) : '';
	update_post_meta( $post_id, SELLA_BADGE_META_TEXT_COLOR, $text_color ? $text_color : '#ffffff' );

	$scope = isset( $_POST['sella_badge_scope'] ) ? sanitize_key( wp_unslash( $_POST['sella_badge_scope'] ) ) : 'products';
	if ( ! in_array( $scope, array( 'products', 'categories' ), true ) ) {
		$scope = 'products';
	}
	update_post_meta( $post_id, SELLA_BADGE_META_SCOPE, $scope );

	$products = array();
	if ( ! empty( $_POST['sella_badge_products'] ) && is_array( $_POST['sella_badge_products'] ) ) {
		$products = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['sella_badge_products'] ) ) ) ) );
	}
	update_post_meta( $post_id, SELLA_BADGE_META_PRODUCTS, $products );

	$categories = array();
	if ( ! empty( $_POST['sella_badge_categories'] ) && is_array( $_POST['sella_badge_categories'] ) ) {
		$categories = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['sella_badge_categories'] ) ) ) ) );
	}
	update_post_meta( $post_id, SELLA_BADGE_META_CATEGORIES, $categories );
}
add_action( 'save_post_' . SELLA_BADGE_CPT, 'sella_badge_save_meta' );

/**
 * Admin assets.
 *
 * @param string $hook Hook.
 */
function sella_badge_admin_assets( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || SELLA_BADGE_CPT !== $screen->post_type ) {
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
 * Admin columns.
 *
 * @param array $columns Columns.
 * @return array
 */
function sella_badge_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['sella_badge_preview'] = 'תצוגה';
			$new['sella_badge_scope']   = 'שיוך';
			$new['sella_badge_status']  = 'סטטוס';
		}
	}
	return $new;
}
add_filter( 'manage_' . SELLA_BADGE_CPT . '_posts_columns', 'sella_badge_columns' );

/**
 * @param string $column Column.
 * @param int    $post_id Post ID.
 */
function sella_badge_column_content( $column, $post_id ) {
	if ( 'sella_badge_preview' === $column ) {
		$color      = get_post_meta( $post_id, SELLA_BADGE_META_COLOR, true ) ?: '#c45c26';
		$text_color = get_post_meta( $post_id, SELLA_BADGE_META_TEXT_COLOR, true ) ?: '#ffffff';
		$title      = get_the_title( $post_id );
		echo '<span class="sella-badge-preview" style="background:' . esc_attr( $color ) . ';color:' . esc_attr( $text_color ) . ';">' . esc_html( $title ) . '</span>';
		return;
	}

	if ( 'sella_badge_scope' === $column ) {
		$scope = get_post_meta( $post_id, SELLA_BADGE_META_SCOPE, true );
		if ( 'categories' === $scope ) {
			$cats = get_post_meta( $post_id, SELLA_BADGE_META_CATEGORIES, true );
			$count = is_array( $cats ) ? count( $cats ) : 0;
			echo esc_html( 'קטגוריות (' . $count . ')' );
		} else {
			$products = get_post_meta( $post_id, SELLA_BADGE_META_PRODUCTS, true );
			$count    = is_array( $products ) ? count( $products ) : 0;
			echo esc_html( 'מוצרים (' . $count . ')' );
		}
		return;
	}

	if ( 'sella_badge_status' === $column ) {
		$enabled = get_post_meta( $post_id, SELLA_BADGE_META_ENABLED, true );
		$enabled = ( '' === $enabled ) ? '1' : $enabled;
		echo '1' === $enabled ? '<span style="color:#008a20;">פעילה</span>' : '<span style="color:#d63638;">כבויה</span>';
	}
}
add_action( 'manage_' . SELLA_BADGE_CPT . '_posts_custom_column', 'sella_badge_column_content', 10, 2 );

/**
 * Title placeholder.
 *
 * @param string $text Text.
 * @param string $post_type Type.
 * @return string
 */
function sella_badge_enter_title( $text, $post_type ) {
	if ( SELLA_BADGE_CPT === $post_type ) {
		return 'טקסט התגית (למשל: חדש, מבצע, רבי מכר)';
	}
	return $text;
}
add_filter( 'enter_title_here', 'sella_badge_enter_title', 10, 2 );

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
