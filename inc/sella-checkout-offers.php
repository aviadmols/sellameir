<?php
/**
 * Checkout offers: books offered at a special price in a popup centered on the
 * checkout page.
 *
 * Each offer is one book with its own price, its own slide text and its own
 * cart rule. Every offer whose rule matches the cart becomes a slide in the
 * same popup, in the order set in the admin.
 *
 * The special price belongs to the cart line added from the popup (marked with
 * cart item data), is limited to one copy, and holds only while the offer's
 * rule still matches — remove the book that unlocked it and the price reverts.
 * Books added from an offer never count toward any offer's rule.
 *
 * Admin: Upsell popups > Checkout offers (?page=sella-checkout-offers).
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_OFFER_CPT', 'sella_checkout_offer' );
define( 'SELLA_OFFER_META', '_sella_checkout_offer' );
define( 'SELLA_OFFER_SETTINGS', 'sella_checkout_offers_settings' );
define( 'SELLA_OFFER_ADMIN_SLUG', 'sella-checkout-offers' );
define( 'SELLA_OFFER_CART_KEY', 'sella_checkout_offer' );
define( 'SELLA_OFFER_ITEM_META', '_sella_checkout_offer_id' );
define( 'SELLA_OFFER_DEFAULT_BUTTON', 'הוספה להזמנה' );

/**
 * Private storage for offers; edited only through the screen below.
 */
function sella_offer_register_cpt() {
	register_post_type(
		SELLA_OFFER_CPT,
		array(
			'labels'       => array(
				'name'          => 'הצעות בדף התשלום',
				'singular_name' => 'הצעה בדף התשלום',
			),
			'public'       => false,
			'show_ui'      => false,
			'supports'     => array( 'title', 'page-attributes' ),
			'rewrite'      => false,
			'query_var'    => false,
			'show_in_rest' => false,
		)
	);
}
add_action( 'init', 'sella_offer_register_cpt' );

/**
 * Management screen, under the upsell menu.
 */
function sella_offer_register_admin_page() {
	add_submenu_page(
		'sella-upsell-popups',
		'הצעות בדף התשלום',
		'הצעות בדף התשלום',
		'manage_woocommerce',
		SELLA_OFFER_ADMIN_SLUG,
		'sella_offer_render_admin_page'
	);
}
add_action( 'admin_menu', 'sella_offer_register_admin_page', 20 );

/**
 * ---------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------------
 */

/**
 * Cart rules an offer can use.
 *
 * @return array<string, string>
 */
function sella_offer_rules() {
	return array(
		'any'          => 'בכל הזמנה',
		'contains'     => 'רק כשבסל יש לפחות אחד מהספרים או מהקטגוריות שבחרתם',
		'not_contains' => 'רק כשבסל אין אף אחד מהספרים או מהקטגוריות שבחרתם',
	);
}

/**
 * Every field an offer stores, with its default.
 *
 * @return array<string, mixed>
 */
function sella_offer_defaults() {
	return array(
		'enabled'         => true,
		'product_id'      => 0,
		'price'           => '',
		'headline'        => '',
		'text'            => '',
		'button'          => '',
		'rule'            => 'any',
		'rule_products'   => array(),
		'rule_categories' => array(),
		'min_total'       => 0,
		'max_total'       => 0,
	);
}

/**
 * One offer, with defaults filled in, plus its ID and slider position.
 *
 * @param int $offer_id Offer ID.
 * @return array<string, mixed>
 */
function sella_offer_get( $offer_id ) {
	$stored = get_post_meta( $offer_id, SELLA_OFFER_META, true );
	$offer  = wp_parse_args( is_array( $stored ) ? $stored : array(), sella_offer_defaults() );

	$offer['id']    = (int) $offer_id;
	$offer['order'] = (int) get_post_field( 'menu_order', $offer_id );

	return $offer;
}

/**
 * Save an offer's fields.
 *
 * @param int   $offer_id Offer ID.
 * @param array $offer    Offer fields.
 */
function sella_offer_store( $offer_id, $offer ) {
	update_post_meta( $offer_id, SELLA_OFFER_META, array_intersect_key( $offer, sella_offer_defaults() ) );
}

/**
 * All offer IDs, in slider order.
 *
 * @return array<int, int>
 */
function sella_offer_ids() {
	$ids = get_posts(
		array(
			'post_type'      => SELLA_OFFER_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	$ids = array_map( 'absint', $ids );
	update_meta_cache( 'post', $ids );

	return $ids;
}

/**
 * Popup settings shared by all offers.
 *
 * @return array{delay: int, frequency: string}
 */
function sella_offer_settings() {
	$settings = get_option( SELLA_OFFER_SETTINGS, array() );
	$settings = is_array( $settings ) ? $settings : array();

	return array(
		'delay'     => isset( $settings['delay'] ) ? max( 0, min( 60, absint( $settings['delay'] ) ) ) : 2,
		'frequency' => isset( $settings['frequency'] ) && 'always' === $settings['frequency'] ? 'always' : 'session',
	);
}

/**
 * Clean a list of IDs from a form.
 *
 * @param mixed $value Posted value.
 * @return array<int, int>
 */
function sella_offer_id_list( $value ) {
	return is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $value ) ) ) ) ) : array();
}

/**
 * Read an offer from the edit form.
 *
 * @param array $source Request data.
 * @return array<string, mixed>
 */
function sella_offer_sanitize( $source ) {
	$rules = sella_offer_rules();
	$rule  = isset( $source['sella_offer_rule'] ) ? sanitize_key( wp_unslash( $source['sella_offer_rule'] ) ) : 'any';
	$price = isset( $source['sella_offer_price'] ) ? wc_format_decimal( wp_unslash( $source['sella_offer_price'] ) ) : '';

	return array(
		'enabled'         => ! empty( $source['sella_offer_enabled'] ),
		'product_id'      => isset( $source['sella_offer_product'] ) ? absint( $source['sella_offer_product'] ) : 0,
		'price'           => '' === $price ? '' : (string) max( 0, (float) $price ),
		'headline'        => sanitize_text_field( wp_unslash( $source['sella_offer_headline'] ?? '' ) ),
		'text'            => sanitize_textarea_field( wp_unslash( $source['sella_offer_text'] ?? '' ) ),
		'button'          => sanitize_text_field( wp_unslash( $source['sella_offer_button'] ?? '' ) ),
		'rule'            => isset( $rules[ $rule ] ) ? $rule : 'any',
		'rule_products'   => sella_offer_id_list( $source['sella_offer_rule_products'] ?? array() ),
		'rule_categories' => sella_offer_id_list( $source['sella_offer_rule_categories'] ?? array() ),
		'min_total'       => max( 0, (float) wp_unslash( $source['sella_offer_min_total'] ?? 0 ) ),
		'max_total'       => max( 0, (float) wp_unslash( $source['sella_offer_max_total'] ?? 0 ) ),
	);
}

/**
 * A price as plain text — the popup prints it with textContent, not as markup.
 *
 * @param float $amount Price.
 * @return string
 */
function sella_offer_format_price( $amount ) {
	return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * The cover to show for a book.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function sella_offer_image_url( $product ) {
	/* "medium" keeps the cover's own proportions; woocommerce_thumbnail is hard-cropped square. */
	return wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: wc_placeholder_img_src( 'medium' );
}

/**
 * Why an offer cannot show right now, in words for the admin; '' when it can.
 *
 * @param array           $offer   Offer.
 * @param WC_Product|null $product The offer's book.
 * @return string
 */
function sella_offer_problem( $offer, $product ) {
	if ( ! $product ) {
		return 'הספר נמחק מהחנות — ההצעה לא תוצג.';
	}
	if ( ! $product->is_type( 'simple' ) ) {
		return 'אפשר להציע רק ספר רגיל, לא מוצר עם וריאציות.';
	}
	if ( ! $product->is_in_stock() ) {
		return 'הספר אזל מהמלאי — ההצעה לא תוצג עד שיחזור.';
	}
	if ( ! $product->is_purchasable() ) {
		return 'הספר לא זמין לרכישה — ההצעה לא תוצג.';
	}
	if ( '' === $offer['price'] ) {
		return 'לא הוגדר מחיר להצעה.';
	}
	return '';
}

/**
 * The offer's book, if it can be sold from the popup right now.
 *
 * @param array $offer Offer.
 * @return WC_Product|null
 */
function sella_offer_product( $offer ) {
	$product = $offer['product_id'] ? wc_get_product( $offer['product_id'] ) : null;
	return $product && '' === sella_offer_problem( $offer, $product ) ? $product : null;
}

/**
 * ---------------------------------------------------------------------------
 * Cart rules
 * ---------------------------------------------------------------------------
 */

/**
 * What the cart holds, for the rules. Lines added from an offer are left out,
 * so an offer can never unlock itself or another offer.
 *
 * @param WC_Cart $cart Cart.
 * @return array{products: array<int, int>, terms: array<int, int>, total: float, in_cart: array<int, int>}
 */
function sella_offer_cart_snapshot( $cart ) {
	$snapshot = array(
		'products' => array(),
		'terms'    => array(),
		'total'    => 0.0,
		'in_cart'  => array(),
	);

	foreach ( $cart->get_cart() as $item ) {
		$product_id   = absint( $item['product_id'] ?? 0 );
		$variation_id = absint( $item['variation_id'] ?? 0 );

		$snapshot['in_cart'][] = $product_id;

		if ( ! empty( $item[ SELLA_OFFER_CART_KEY ] ) ) {
			continue;
		}

		$snapshot['products'][] = $product_id;
		if ( $variation_id ) {
			$snapshot['products'][] = $variation_id;
		}

		$terms = wc_get_product_term_ids( $product_id, 'product_cat' );
		if ( is_array( $terms ) ) {
			$snapshot['terms'] = array_merge( $snapshot['terms'], array_map( 'absint', $terms ) );
		}

		if ( ! empty( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ) {
			$snapshot['total'] += (float) wc_get_price_to_display( $item['data'], array( 'qty' => $item['quantity'] ) );
		}
	}

	$snapshot['products'] = array_values( array_unique( array_filter( $snapshot['products'] ) ) );
	$snapshot['terms']    = array_values( array_unique( array_filter( $snapshot['terms'] ) ) );
	$snapshot['in_cart']  = array_values( array_unique( array_filter( $snapshot['in_cart'] ) ) );

	return $snapshot;
}

/**
 * Does the cart unlock this offer?
 *
 * @param array $offer    Offer.
 * @param array $snapshot Cart snapshot.
 * @return bool
 */
function sella_offer_rule_passes( $offer, $snapshot ) {
	// An offer is an add-on to a real order, never an order of its own.
	if ( ! $offer['enabled'] || empty( $snapshot['products'] ) ) {
		return false;
	}

	if ( $offer['min_total'] > 0 && $snapshot['total'] < $offer['min_total'] ) {
		return false;
	}
	if ( $offer['max_total'] > 0 && $snapshot['total'] > $offer['max_total'] ) {
		return false;
	}

	$products = array_map( 'absint', (array) $offer['rule_products'] );
	$terms    = array_map( 'absint', (array) $offer['rule_categories'] );

	// A rule with nothing chosen is not a rule.
	if ( 'any' === $offer['rule'] || ( empty( $products ) && empty( $terms ) ) ) {
		return true;
	}

	$matched = ! empty( array_intersect( $products, $snapshot['products'] ) )
		|| ! empty( array_intersect( $terms, $snapshot['terms'] ) );

	return 'contains' === $offer['rule'] ? $matched : ! $matched;
}

/**
 * The rule in one line of plain Hebrew, for the offers list.
 *
 * @param array $offer Offer.
 * @return string
 */
function sella_offer_rule_summary( $offer ) {
	$parts = array();

	if ( 'any' !== $offer['rule'] && ( $offer['rule_products'] || $offer['rule_categories'] ) ) {
		$names = array();
		foreach ( (array) $offer['rule_products'] as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$names[] = $product->get_name();
			}
		}
		foreach ( (array) $offer['rule_categories'] as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = 'קטגוריית ' . $term->name;
			}
		}

		$shown = array_slice( $names, 0, 3 );
		$more  = count( $names ) - count( $shown );
		$list  = implode( ', ', $shown ) . ( $more > 0 ? ' ועוד ' . $more : '' );

		$parts[] = ( 'contains' === $offer['rule'] ? 'כשבסל יש: ' : 'כשבסל אין: ' ) . $list;
	}

	if ( $offer['min_total'] > 0 ) {
		$parts[] = 'סל מ-' . sella_offer_format_price( $offer['min_total'] );
	}
	if ( $offer['max_total'] > 0 ) {
		$parts[] = 'סל עד ' . sella_offer_format_price( $offer['max_total'] );
	}

	return $parts ? implode( ' · ', $parts ) : 'בכל הזמנה';
}

/**
 * ---------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------------
 */

/**
 * Save, delete, switch on/off, and save the popup settings.
 */
function sella_offer_handle_admin_actions() {
	if ( ! is_admin() || empty( $_POST['sella_offer_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	check_admin_referer( 'sella_offer_manage' );

	$action   = sanitize_key( wp_unslash( $_POST['sella_offer_action'] ) );
	$offer_id = isset( $_POST['offer_id'] ) ? absint( $_POST['offer_id'] ) : 0;
	$list_url = admin_url( 'admin.php?page=' . SELLA_OFFER_ADMIN_SLUG );

	if ( $offer_id && SELLA_OFFER_CPT !== get_post_type( $offer_id ) ) {
		$offer_id = 0;
	}

	if ( 'settings' === $action ) {
		update_option(
			SELLA_OFFER_SETTINGS,
			array(
				'delay'     => isset( $_POST['sella_offer_delay'] ) ? absint( $_POST['sella_offer_delay'] ) : 2,
				'frequency' => isset( $_POST['sella_offer_frequency'] ) ? sanitize_key( wp_unslash( $_POST['sella_offer_frequency'] ) ) : 'session',
			),
			false
		);
		wp_safe_redirect( add_query_arg( 'notice', 'settings', $list_url ) );
		exit;
	}

	if ( 'delete' === $action && $offer_id ) {
		wp_delete_post( $offer_id, true );
		wp_safe_redirect( add_query_arg( 'notice', 'deleted', $list_url ) );
		exit;
	}

	if ( 'toggle' === $action && $offer_id ) {
		$offer            = sella_offer_get( $offer_id );
		$offer['enabled'] = ! $offer['enabled'];
		sella_offer_store( $offer_id, $offer );
		wp_safe_redirect( add_query_arg( 'notice', $offer['enabled'] ? 'enabled' : 'disabled', $list_url ) );
		exit;
	}

	if ( 'save' !== $action ) {
		return;
	}

	$offer    = sella_offer_sanitize( $_POST );
	$product  = $offer['product_id'] ? wc_get_product( $offer['product_id'] ) : null;
	$edit_url = add_query_arg( 'edit', $offer_id ? $offer_id : 'new', $list_url );

	if ( ! $product || ! $product->is_type( 'simple' ) ) {
		wp_safe_redirect( add_query_arg( 'error', 'product', $edit_url ) );
		exit;
	}
	if ( '' === $offer['price'] ) {
		wp_safe_redirect( add_query_arg( 'error', 'price', $edit_url ) );
		exit;
	}

	$post_id = wp_insert_post(
		array(
			'ID'          => $offer_id,
			'post_type'   => SELLA_OFFER_CPT,
			'post_status' => 'publish',
			'post_title'  => $product->get_name(),
			'menu_order'  => isset( $_POST['sella_offer_order'] ) ? absint( $_POST['sella_offer_order'] ) : 0,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect( add_query_arg( 'error', 'save', $edit_url ) );
		exit;
	}

	sella_offer_store( $post_id, $offer );
	wp_safe_redirect( add_query_arg( 'notice', 'saved', $list_url ) );
	exit;
}
add_action( 'admin_init', 'sella_offer_handle_admin_actions' );

/**
 * Book details for the live preview, fetched when the book changes.
 *
 * @param WC_Product $product Product.
 * @return array<string, mixed>
 */
function sella_offer_product_preview( $product ) {
	return array(
		'name'     => $product->get_name(),
		'image'    => sella_offer_image_url( $product ),
		'price'    => sella_offer_format_price( wc_get_price_to_display( $product ) ),
		'priceRaw' => (float) $product->get_price(),
		'simple'   => $product->is_type( 'simple' ),
	);
}

/**
 * Admin AJAX: details of the book just picked in the form.
 */
function sella_offer_ajax_product_info() {
	check_ajax_referer( 'sella_offer_admin', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error();
	}

	$product = wc_get_product( isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0 );
	if ( ! $product ) {
		wp_send_json_error();
	}

	wp_send_json_success( sella_offer_product_preview( $product ) );
}
add_action( 'wp_ajax_sella_offer_product_info', 'sella_offer_ajax_product_info' );

/**
 * Admin assets: WooCommerce's product search, the popup's own CSS for the
 * preview, and the form script.
 *
 * @param string $hook Admin hook.
 */
function sella_offer_admin_assets( $hook ) {
	if ( false === strpos( $hook, SELLA_OFFER_ADMIN_SLUG ) ) {
		return;
	}

	$base = get_stylesheet_directory_uri();

	wp_enqueue_style( 'woocommerce_admin_styles' );
	wp_enqueue_script( 'wc-enhanced-select' );
	wp_enqueue_style( 'sella-checkout-offers', $base . '/assets/css/sella-checkout-offers.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_style( 'sella-checkout-offers-admin', $base . '/assets/css/sella-checkout-offers-admin.css', array( 'woocommerce_admin_styles', 'sella-checkout-offers' ), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-checkout-offers-admin', $base . '/assets/js/sella-checkout-offers-admin.js', array( 'jquery', 'wc-enhanced-select' ), HELLO_ELEMENTOR_CHILD_VERSION, true );
	wp_localize_script(
		'sella-checkout-offers-admin',
		'sellaOfferAdmin',
		array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'sella_offer_admin' ),
			'currency'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'priceFormat'   => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
			'decimals'      => wc_get_price_decimals(),
			'placeholder'   => wc_placeholder_img_src( 'medium' ),
			'defaultButton' => SELLA_OFFER_DEFAULT_BUTTON,
			'noProduct'     => 'שם הספר',
			'chooseProduct' => 'בחרו את הספר שמוצע.',
			'notSimple'     => 'אפשר להציע רק ספר רגיל, לא מוצר עם וריאציות.',
			'confirmDelete' => 'למחוק את ההצעה? אי אפשר לבטל את זה.',
		)
	);
}
add_action( 'admin_enqueue_scripts', 'sella_offer_admin_assets' );

/**
 * The screen: the offers list, or one offer's form.
 */
function sella_offer_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
	if ( '' === $edit ) {
		sella_offer_render_list();
		return;
	}

	$offer_id = absint( $edit );
	sella_offer_render_form( $offer_id && SELLA_OFFER_CPT === get_post_type( $offer_id ) ? $offer_id : 0 );
}

/**
 * Offers list and popup settings.
 */
function sella_offer_render_list() {
	$list_url = admin_url( 'admin.php?page=' . SELLA_OFFER_ADMIN_SLUG );
	$new_url  = add_query_arg( 'edit', 'new', $list_url );
	$settings = sella_offer_settings();
	$ids      = sella_offer_ids();
	$notice   = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
	$notices  = array(
		'saved'    => 'ההצעה נשמרה.',
		'deleted'  => 'ההצעה נמחקה.',
		'enabled'  => 'ההצעה הופעלה.',
		'disabled' => 'ההצעה כובתה.',
		'settings' => 'הגדרות הפופאפ נשמרו.',
	);
	?>
	<div class="wrap sella-offer-admin" dir="rtl">
		<h1 class="wp-heading-inline">הצעות בדף התשלום</h1>
		<a class="page-title-action" href="<?php echo esc_url( $new_url ); ?>">הוספת הצעה</a>
		<hr class="wp-header-end" />

		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
		<?php endif; ?>

		<p class="sella-offer-lead">
			ספרים שמוצעים ללקוח במחיר מיוחד, בפופאפ שנפתח במרכז דף התשלום.
			לכל הצעה יש ספר אחד, מחיר, טקסט ותנאי משלה. כשכמה הצעות מתאימות לסל של הלקוח, הן מוצגות יחד בסליידר — לפי הסדר שבטבלה.
		</p>

		<?php if ( empty( $ids ) ) : ?>
			<div class="sella-offer-empty">
				<p>עדיין אין הצעות.</p>
				<a class="button button-primary button-hero" href="<?php echo esc_url( $new_url ); ?>">יצירת ההצעה הראשונה</a>
			</div>
		<?php else : ?>
			<table class="widefat sella-offer-table">
				<thead>
					<tr>
						<th class="sella-offer-table__cover"><span class="screen-reader-text">עטיפה</span></th>
						<th>ספר</th>
						<th>מחיר בהצעה</th>
						<th>מתי מוצג</th>
						<th>סטטוס</th>
						<th><span class="screen-reader-text">פעולות</span></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $ids as $offer_id ) :
					$offer    = sella_offer_get( $offer_id );
					$product  = $offer['product_id'] ? wc_get_product( $offer['product_id'] ) : null;
					$problem  = sella_offer_problem( $offer, $product );
					$edit_url = add_query_arg( 'edit', $offer_id, $list_url );
					$regular  = $product ? (float) $product->get_price() : 0;
					?>
					<tr class="<?php echo $offer['enabled'] ? '' : 'is-disabled'; ?>">
						<td class="sella-offer-table__cover">
							<?php if ( $product ) : ?><img src="<?php echo esc_url( sella_offer_image_url( $product ) ); ?>" alt="" /><?php endif; ?>
						</td>
						<td class="sella-offer-table__book">
							<a class="row-title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $product ? $product->get_name() : get_the_title( $offer_id ) ); ?></a>
							<?php if ( $offer['headline'] ) : ?><span class="sella-offer-table__headline"><?php echo esc_html( $offer['headline'] ); ?></span><?php endif; ?>
							<?php if ( $problem ) : ?><span class="sella-offer-warning"><?php echo esc_html( $problem ); ?></span><?php endif; ?>
						</td>
						<td class="sella-offer-table__price">
							<?php if ( '' !== $offer['price'] ) : ?>
								<strong><?php echo esc_html( sella_offer_format_price( $offer['price'] ) ); ?></strong>
								<?php if ( $regular > (float) $offer['price'] ) : ?><del><?php echo esc_html( sella_offer_format_price( $regular ) ); ?></del><?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="sella-offer-table__rule"><?php echo esc_html( sella_offer_rule_summary( $offer ) ); ?></td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'sella_offer_manage' ); ?>
								<input type="hidden" name="sella_offer_action" value="toggle" />
								<input type="hidden" name="offer_id" value="<?php echo esc_attr( (string) $offer_id ); ?>" />
								<button type="submit" class="sella-offer-status <?php echo $offer['enabled'] ? 'is-on' : 'is-off'; ?>" title="<?php echo $offer['enabled'] ? 'לחיצה תכבה את ההצעה' : 'לחיצה תפעיל את ההצעה'; ?>">
									<?php echo $offer['enabled'] ? 'פעילה' : 'כבויה'; ?>
								</button>
							</form>
						</td>
						<td class="sella-offer-table__actions">
							<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">עריכה</a>
							<form method="post" class="sella-offer-delete">
								<?php wp_nonce_field( 'sella_offer_manage' ); ?>
								<input type="hidden" name="sella_offer_action" value="delete" />
								<input type="hidden" name="offer_id" value="<?php echo esc_attr( (string) $offer_id ); ?>" />
								<button type="submit" class="button button-small button-link-delete">מחיקה</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form method="post" class="sella-offer-settings">
			<h2>הגדרות הפופאפ</h2>
			<?php wp_nonce_field( 'sella_offer_manage' ); ?>
			<input type="hidden" name="sella_offer_action" value="settings" />
			<p>
				<label>
					לפתוח את הפופאפ
					<input type="number" min="0" max="60" name="sella_offer_delay" value="<?php echo esc_attr( (string) $settings['delay'] ); ?>" class="small-text" />
					שניות אחרי הכניסה לדף התשלום
				</label>
			</p>
			<p>
				<label>
					להציג את הפופאפ
					<select name="sella_offer_frequency">
						<option value="session" <?php selected( $settings['frequency'], 'session' ); ?>>פעם אחת בכל ביקור באתר</option>
						<option value="always" <?php selected( $settings['frequency'], 'always' ); ?>>בכל כניסה לדף התשלום</option>
					</select>
				</label>
			</p>
			<p class="description">אם הלקוח כבר התחיל למלא פרטים כשהזמן עבר, הפופאפ לא קופץ לו באמצע ההקלדה.</p>
			<?php submit_button( 'שמירת הגדרות', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/**
 * One offer's form, with a live preview of its slide.
 *
 * @param int $offer_id Offer ID, 0 for a new offer.
 */
function sella_offer_render_form( $offer_id ) {
	$list_url   = admin_url( 'admin.php?page=' . SELLA_OFFER_ADMIN_SLUG );
	$offer      = $offer_id ? sella_offer_get( $offer_id ) : array_merge( sella_offer_defaults(), array( 'order' => count( sella_offer_ids() ) + 1 ) );
	$product    = $offer['product_id'] ? wc_get_product( $offer['product_id'] ) : null;
	$rules      = sella_offer_rules();
	$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
	$categories = is_wp_error( $categories ) ? array() : $categories;
	$error      = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
	$errors     = array(
		'product' => 'בחרו ספר רגיל להצעה (לא מוצר עם וריאציות).',
		'price'   => 'מלאו את המחיר בהצעה.',
		'save'    => 'השמירה נכשלה. נסו שוב.',
	);
	?>
	<div class="wrap sella-offer-admin" dir="rtl">
		<a class="sella-offer-back" href="<?php echo esc_url( $list_url ); ?>">&rarr; כל ההצעות</a>
		<h1><?php echo $offer_id ? 'עריכת הצעה' : 'הצעה חדשה'; ?></h1>

		<?php if ( isset( $errors[ $error ] ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $errors[ $error ] ); ?></p></div>
		<?php endif; ?>

		<form method="post" class="sella-offer-edit" data-product="<?php echo esc_attr( $product ? wp_json_encode( sella_offer_product_preview( $product ) ) : '' ); ?>">
			<?php wp_nonce_field( 'sella_offer_manage' ); ?>
			<input type="hidden" name="sella_offer_action" value="save" />
			<input type="hidden" name="offer_id" value="<?php echo esc_attr( (string) $offer_id ); ?>" />

			<div class="sella-offer-edit__grid">
				<div class="sella-offer-edit__main">

					<section class="sella-offer-section">
						<h2><span class="sella-offer-step">1</span> הספר והמחיר</h2>
						<div class="sella-offer-field">
							<label for="sella_offer_product">איזה ספר מציעים?</label>
							<select class="wc-product-search" id="sella_offer_product" name="sella_offer_product" style="width:100%;max-width:560px;" data-action="woocommerce_json_search_products" data-exclude_type="variable,grouped,external" data-placeholder="הקלידו לפחות 3 אותיות משם הספר" data-allow_clear="true">
								<?php if ( $product ) : ?>
									<option value="<?php echo esc_attr( (string) $product->get_id() ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
								<?php endif; ?>
							</select>
							<p class="sella-offer-product-error" hidden></p>
							<p class="sella-offer-regular" hidden>המחיר הרגיל של הספר: <strong></strong></p>
						</div>
						<div class="sella-offer-field">
							<label for="sella_offer_price">המחיר בהצעה</label>
							<input type="number" id="sella_offer_price" name="sella_offer_price" class="sella-offer-price-input" min="0" step="0.01" inputmode="decimal" required value="<?php echo esc_attr( (string) $offer['price'] ); ?>" />
							<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>
							<p class="description">המחיר שהלקוח ישלם כשהוא מוסיף את הספר מהפופאפ. חל על עותק אחד, וכל עוד התנאי בשלב 3 מתקיים.</p>
						</div>
					</section>

					<section class="sella-offer-section">
						<h2><span class="sella-offer-step">2</span> הטקסט בסליידר</h2>
						<div class="sella-offer-field">
							<label for="sella_offer_headline">כותרת</label>
							<input type="text" id="sella_offer_headline" name="sella_offer_headline" value="<?php echo esc_attr( $offer['headline'] ); ?>" placeholder="למשל: רגע לפני שמסיימים" />
							<p class="description">שורה קצרה בצבע המותג, מעל שם הספר.</p>
						</div>
						<div class="sella-offer-field">
							<label for="sella_offer_text">טקסט</label>
							<textarea id="sella_offer_text" name="sella_offer_text" rows="4" placeholder="למשל: מי שקונה את הספר הזה אוהב גם את זה — ועכשיו הוא במחיר מיוחד רק להזמנה הזו."><?php echo esc_textarea( $offer['text'] ); ?></textarea>
							<p class="description">מופיע מתחת לשם הספר. ירידת שורה נשמרת כמו שהיא.</p>
						</div>
						<div class="sella-offer-field">
							<label for="sella_offer_button">טקסט הכפתור</label>
							<input type="text" id="sella_offer_button" name="sella_offer_button" value="<?php echo esc_attr( $offer['button'] ); ?>" placeholder="<?php echo esc_attr( SELLA_OFFER_DEFAULT_BUTTON ); ?>" />
							<p class="description">ריק = "<?php echo esc_html( SELLA_OFFER_DEFAULT_BUTTON ); ?>".</p>
						</div>
					</section>

					<section class="sella-offer-section">
						<h2><span class="sella-offer-step">3</span> מתי להציג</h2>
						<div class="sella-offer-field">
							<label for="sella_offer_rule">להציג את ההצעה</label>
							<select id="sella_offer_rule" name="sella_offer_rule">
								<?php foreach ( $rules as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $offer['rule'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="sella-offer-rule-match" <?php echo 'any' === $offer['rule'] ? 'hidden' : ''; ?>>
								<label for="sella_offer_rule_products">ספרים</label>
								<select class="wc-product-search" multiple="multiple" id="sella_offer_rule_products" name="sella_offer_rule_products[]" style="width:100%;max-width:560px;" data-action="woocommerce_json_search_products" data-placeholder="חפשו ספרים לפי שם">
									<?php
									foreach ( (array) $offer['rule_products'] as $rule_product_id ) :
										$rule_product = wc_get_product( $rule_product_id );
										if ( ! $rule_product ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( (string) $rule_product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $rule_product->get_formatted_name() ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<label for="sella_offer_rule_categories">קטגוריות</label>
								<select class="wc-enhanced-select" multiple="multiple" id="sella_offer_rule_categories" name="sella_offer_rule_categories[]" style="width:100%;max-width:560px;" data-placeholder="בחרו קטגוריות">
									<?php foreach ( $categories as $term ) : ?>
										<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, array_map( 'absint', (array) $offer['rule_categories'] ), true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">מספיק שאחד מהם נמצא בסל.</p>
							</div>
						</div>
						<div class="sella-offer-field">
							<span class="sella-offer-field__label">סכום הספרים בסל</span>
							<div class="sella-offer-inline">
								<label>מ- <input type="number" min="0" step="1" name="sella_offer_min_total" class="small-text" value="<?php echo esc_attr( $offer['min_total'] ? (string) $offer['min_total'] : '' ); ?>" placeholder="ללא" /></label>
								<label>עד <input type="number" min="0" step="1" name="sella_offer_max_total" class="small-text" value="<?php echo esc_attr( $offer['max_total'] ? (string) $offer['max_total'] : '' ); ?>" placeholder="ללא" /></label>
							</div>
							<p class="description">לפני משלוח והנחות. ריק = בלי הגבלה.</p>
						</div>
						<p class="sella-offer-note">ההצעה לא מוצגת ללקוח שהספר כבר נמצא בסל שלו. ספרים שנוספו מהצעה לא נספרים בתנאים.</p>
					</section>

					<section class="sella-offer-section">
						<h2><span class="sella-offer-step">4</span> פרסום</h2>
						<div class="sella-offer-field">
							<label class="sella-offer-check"><input type="checkbox" name="sella_offer_enabled" value="1" <?php checked( $offer['enabled'] ); ?> /> ההצעה פעילה</label>
						</div>
						<div class="sella-offer-field">
							<label for="sella_offer_order">מיקום בסליידר</label>
							<input type="number" id="sella_offer_order" name="sella_offer_order" min="0" step="1" class="small-text" value="<?php echo esc_attr( (string) $offer['order'] ); ?>" />
							<p class="description">מספר נמוך מוצג קודם.</p>
						</div>
					</section>

					<p class="sella-offer-submit">
						<?php submit_button( $offer_id ? 'שמירת השינויים' : 'יצירת ההצעה', 'primary', 'submit', false ); ?>
						<a class="button" href="<?php echo esc_url( $list_url ); ?>">ביטול</a>
					</p>
				</div>

				<aside class="sella-offer-edit__preview">
					<h2>תצוגה מקדימה</h2>
					<div class="sella-offer-preview">
						<div class="sella-offers sella-offers--preview is-open">
							<div class="sella-offers__dialog">
								<span class="sella-offers__close" aria-hidden="true">&times;</span>
								<div class="sella-offers__slide">
									<p class="sella-offers__headline" data-preview="headline"></p>
									<div class="sella-offers__body">
										<img class="sella-offers__cover" data-preview="image" src="" alt="" />
										<div class="sella-offers__info">
											<h3 class="sella-offers__name" data-preview="name"></h3>
											<p class="sella-offers__text" data-preview="text"></p>
											<p class="sella-offers__price">
												<span class="sella-offers__price-now" data-preview="now"></span>
												<del class="sella-offers__price-was" data-preview="was"></del>
											</p>
										</div>
									</div>
									<span class="sella-offers__add" data-preview="button"></span>
									<span class="sella-offers__dismiss">לא תודה, להמשיך לתשלום</span>
								</div>
							</div>
						</div>
					</div>
					<p class="description">כך תיראה השקופית בפופאפ. הטקסטים מתעדכנים תוך כדי הקלדה.</p>
				</aside>
			</div>
		</form>
	</div>
	<?php
}

/**
 * ---------------------------------------------------------------------------
 * Checkout popup
 * ---------------------------------------------------------------------------
 */

/**
 * The slides for this cart: every live offer whose rule matches, one per book.
 *
 * @return array<int, array<string, mixed>>
 */
function sella_offer_active_slides() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return array();
	}

	$snapshot = sella_offer_cart_snapshot( WC()->cart );
	$slides   = array();
	$books    = array();

	foreach ( sella_offer_ids() as $offer_id ) {
		$offer = sella_offer_get( $offer_id );

		if ( in_array( $offer['product_id'], $snapshot['in_cart'], true ) || isset( $books[ $offer['product_id'] ] ) ) {
			continue;
		}
		if ( ! sella_offer_rule_passes( $offer, $snapshot ) ) {
			continue;
		}

		$product = sella_offer_product( $offer );
		if ( ! $product ) {
			continue;
		}

		$now = (float) wc_get_price_to_display( $product, array( 'price' => $offer['price'] ) );
		$was = (float) wc_get_price_to_display( $product );

		$books[ $offer['product_id'] ] = true;
		$slides[]                      = array(
			'id'       => $offer_id,
			'name'     => $product->get_name(),
			'image'    => sella_offer_image_url( $product ),
			'headline' => $offer['headline'],
			'text'     => $offer['text'],
			'button'   => '' !== $offer['button'] ? $offer['button'] : SELLA_OFFER_DEFAULT_BUTTON,
			'price'    => sella_offer_format_price( $now ),
			'regular'  => $was > $now ? sella_offer_format_price( $was ) : '',
		);
	}

	return $slides;
}

/**
 * Load the popup on checkout when the cart unlocks at least one offer.
 */
function sella_offer_frontend_assets() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() ) {
		return;
	}

	$slides = sella_offer_active_slides();
	if ( empty( $slides ) ) {
		return;
	}

	$settings = sella_offer_settings();
	$base     = get_stylesheet_directory_uri();

	wp_enqueue_style( 'sella-checkout-offers', $base . '/assets/css/sella-checkout-offers.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-checkout-offers', $base . '/assets/js/sella-checkout-offers.js', array(), HELLO_ELEMENTOR_CHILD_VERSION, true );
	wp_localize_script(
		'sella-checkout-offers',
		'sellaCheckoutOffers',
		array(
			'offers'    => $slides,
			'delay'     => $settings['delay'],
			'frequency' => $settings['frequency'],
			'addUrl'    => WC_AJAX::get_endpoint( 'sella_offer_add' ),
			'nonce'     => wp_create_nonce( 'sella_offer_add' ),
			'labels'    => array(
				'dialog'   => 'הצעה מיוחדת להזמנה שלך',
				'close'    => 'סגירה',
				'dismiss'  => 'לא תודה, להמשיך לתשלום',
				'previous' => 'ההצעה הקודמת',
				'next'     => 'ההצעה הבאה',
				'slide'    => 'הצעה %1$s מתוך %2$s',
				'adding'   => 'מוסיף להזמנה...',
				'added'    => 'נוסף להזמנה ✓',
				'error'    => 'לא הצלחנו להוסיף. נסו שוב.',
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'sella_offer_frontend_assets', 30 );

/**
 * Add an offer's book to the cart, after checking again that it still applies.
 */
function sella_offer_ajax_add() {
	check_ajax_referer( 'sella_offer_add', 'nonce' );

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'החנות אינה זמינה כרגע.' ) );
	}

	$offer_id = isset( $_POST['offer_id'] ) ? absint( $_POST['offer_id'] ) : 0;
	$offer    = $offer_id && SELLA_OFFER_CPT === get_post_type( $offer_id ) && 'publish' === get_post_status( $offer_id ) ? sella_offer_get( $offer_id ) : null;
	$snapshot = sella_offer_cart_snapshot( WC()->cart );

	if ( ! $offer || ! sella_offer_rule_passes( $offer, $snapshot ) ) {
		wp_send_json_error( array( 'message' => 'ההצעה כבר לא זמינה.' ) );
	}
	if ( in_array( $offer['product_id'], $snapshot['in_cart'], true ) ) {
		wp_send_json_error( array( 'message' => 'הספר כבר נמצא בהזמנה.' ) );
	}
	if ( ! sella_offer_product( $offer ) ) {
		wp_send_json_error( array( 'message' => 'הספר אינו זמין כרגע.' ) );
	}

	$added = WC()->cart->add_to_cart( $offer['product_id'], 1, 0, array(), array( SELLA_OFFER_CART_KEY => $offer_id ) );
	if ( ! $added ) {
		$notices = wc_get_notices( 'error' );
		wc_clear_notices();
		wp_send_json_error(
			array(
				'message' => ! empty( $notices[0]['notice'] ) ? wp_strip_all_tags( $notices[0]['notice'] ) : 'לא הצלחנו להוסיף את הספר.',
			)
		);
	}

	WC()->cart->calculate_totals();
	wc_clear_notices();

	wp_send_json_success( array( 'offerId' => $offer_id ) );
}
/* WooCommerce's own AJAX endpoint: front-end context, cart and session ready. */
add_action( 'wc_ajax_sella_offer_add', 'sella_offer_ajax_add' );

/**
 * ---------------------------------------------------------------------------
 * The special price in the cart
 * ---------------------------------------------------------------------------
 */

/**
 * Price the lines added from an offer: one copy, at the offer price, while the
 * offer is live and its rule matches; otherwise at the book's normal price.
 *
 * Runs before every totals calculation and when the cart loads from the
 * session, so the mini cart shows the same price as the checkout.
 *
 * @param WC_Cart $cart Cart.
 */
function sella_offer_apply_prices( $cart ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	$snapshot = null;

	foreach ( $cart->get_cart() as $key => $item ) {
		if ( empty( $item[ SELLA_OFFER_CART_KEY ] ) || empty( $item['data'] ) || ! is_a( $item['data'], 'WC_Product' ) ) {
			continue;
		}

		if ( $item['quantity'] > 1 ) {
			$cart->set_quantity( $key, 1, false );
		}

		$snapshot = $snapshot ?? sella_offer_cart_snapshot( $cart );
		$offer_id = absint( $item[ SELLA_OFFER_CART_KEY ] );
		$offer    = SELLA_OFFER_CPT === get_post_type( $offer_id ) && 'publish' === get_post_status( $offer_id ) ? sella_offer_get( $offer_id ) : null;

		if ( $offer && '' !== $offer['price'] && absint( $item['product_id'] ) === $offer['product_id'] && sella_offer_rule_passes( $offer, $snapshot ) ) {
			$item['data']->set_price( $offer['price'] );
			continue;
		}

		// The offer no longer applies: back to the normal price.
		$fresh = wc_get_product( $item['data']->get_id() );
		if ( $fresh ) {
			$item['data']->set_price( $fresh->get_price() );
		}
	}
}
add_action( 'woocommerce_before_calculate_totals', 'sella_offer_apply_prices', 20 );
add_action( 'woocommerce_cart_loaded_from_session', 'sella_offer_apply_prices', 20 );

/**
 * An offer line is one copy: show the quantity as fixed text on the cart page.
 *
 * @param string $html          Quantity field.
 * @param string $cart_item_key Cart key.
 * @param array  $cart_item     Cart item.
 * @return string
 */
function sella_offer_fixed_quantity( $html, $cart_item_key, $cart_item = array() ) {
	if ( empty( $cart_item[ SELLA_OFFER_CART_KEY ] ) ) {
		return $html;
	}
	return sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', esc_attr( $cart_item_key ) );
}
add_filter( 'woocommerce_cart_item_quantity', 'sella_offer_fixed_quantity', 10, 3 );

/**
 * Remember on the order line that it came from a checkout offer.
 *
 * @param WC_Order_Item_Product $item          Line item.
 * @param string                $cart_item_key Cart key.
 * @param array                 $values        Cart item.
 */
function sella_offer_tag_order_item( $item, $cart_item_key, $values ) {
	if ( ! empty( $values[ SELLA_OFFER_CART_KEY ] ) ) {
		$item->add_meta_data( SELLA_OFFER_ITEM_META, absint( $values[ SELLA_OFFER_CART_KEY ] ), true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'sella_offer_tag_order_item', 10, 3 );

/**
 * Show that tag under the line on the admin order screen.
 *
 * @param int                   $item_id Item ID.
 * @param WC_Order_Item_Product $item    Line item.
 */
function sella_offer_admin_order_item_flag( $item_id, $item ) {
	if ( ! is_admin() || ! is_a( $item, 'WC_Order_Item_Product' ) || ! $item->get_meta( SELLA_OFFER_ITEM_META ) ) {
		return;
	}

	echo '<div class="sella-offer-order-flag" style="margin-top:6px;padding:3px 8px;display:inline-block;border-radius:3px;background:#efeefe;color:#4b46c9;font-size:12px;font-weight:600;">נוסף מהצעה בדף התשלום</div>';
}
add_action( 'woocommerce_after_order_itemmeta', 'sella_offer_admin_order_item_flag', 10, 2 );
