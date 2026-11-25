<?php
/**
 * Events (the "event" post type of CPT UI): tickets sold through WooCommerce.
 *
 * A box on the event's edit screen, "כרטיסים":
 *   - the ticket price;
 *   - the event's date and time, read from the event's own fields (ACF or a
 *     plain custom field) and shown there; a date typed in the box wins;
 *   - "SOLD OUT", a checkbox;
 *   - a button that makes the ticket product: a virtual simple product, out
 *     of the shop, its search, the sitemap and the Merchant feed, linked to
 *     the event both ways (_sella_event_id on the product,
 *     _sella_event_product_id on the event). Saving the event keeps the
 *     product's name, price, image and stock status in step.
 *
 * On the event's page, the button of the Elementor add-to-cart widget in the
 * single-event template is swapped, on the finished page, for a quantity and
 * the "buy" button (in the widget's text, icon and style), which puts the
 * ticket in the cart (Smart Cart catches the form and opens the drawer;
 * without JavaScript the form posts to the shop). Once the event has started
 * the button reads "אירוע עבר", and with the checkbox on it reads "SOLD OUT";
 * in both cases the ticket can no longer be bought by any route. The same
 * button is the [sella_event_buy] shortcode (id="" for another event).
 *
 * [sella_events] lists every event: upcoming ones first, soonest first, then
 * past ones, latest first. show="upcoming" or show="past" for one group. On
 * /events/ it takes the place of the old list (the "next event" snippet).
 *
 * The guests' page cache keeps pages for hours, so it is emptied when an
 * event starts (a single cron event per event).
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_EV_POST_TYPE', 'event' );
define( 'SELLA_EV_PRICE_META', '_sella_event_price' );
define( 'SELLA_EV_WHEN_META', '_sella_event_when' );
define( 'SELLA_EV_SOLD_OUT_META', '_sella_event_sold_out' );
define( 'SELLA_EV_PRODUCT_META', '_sella_event_product_id' );
define( 'SELLA_EV_EVENT_META', '_sella_event_id' );
define( 'SELLA_EV_IDS_CACHE', 'sella_event_product_ids' );
define( 'SELLA_EV_CRON', 'sella_event_started' );

/*
 * ------------------------------------------------------------------
 * The event's data
 * ------------------------------------------------------------------
 */

/**
 * Text for the states and the buttons.
 *
 * @param string $key Key.
 * @return string
 */
function sella_ev_text( $key ) {
	$texts = array(
		'buy'      => 'רכישת כרטיס לאירוע',
		'sold_out' => 'SOLD OUT',
		'past'     => 'אירוע עבר',
		'details'  => 'לפרטים ורכישה',
		'none'     => 'אין אירועים כרגע.',
	);
	$texts = apply_filters( 'sella_event_texts', $texts );
	return isset( $texts[ $key ] ) ? (string) $texts[ $key ] : '';
}

/**
 * The linked ticket product, when it still exists.
 *
 * @param int $event_id Event ID.
 * @return WC_Product|null
 */
function sella_ev_product( $event_id ) {
	$product_id = (int) get_post_meta( $event_id, SELLA_EV_PRODUCT_META, true );
	if ( ! $product_id || 'trash' === get_post_status( $product_id ) ) {
		return null;
	}
	$product = wc_get_product( $product_id );
	return $product instanceof WC_Product ? $product : null;
}

/**
 * The event a ticket product belongs to.
 *
 * @param WC_Product|int $product Product or ID.
 * @return int Event ID, or 0.
 */
function sella_ev_event_of( $product ) {
	$product_id = $product instanceof WC_Product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : (int) $product;
	if ( ! $product_id ) {
		return 0;
	}
	$event_id = (int) get_post_meta( $product_id, SELLA_EV_EVENT_META, true );
	return $event_id && SELLA_EV_POST_TYPE === get_post_type( $event_id ) ? $event_id : 0;
}

/**
 * The ticket price typed in the box, or the product's own.
 *
 * @param int $event_id Event ID.
 * @return string Price, or ''.
 */
function sella_ev_price( $event_id ) {
	$price = (string) get_post_meta( $event_id, SELLA_EV_PRICE_META, true );
	if ( '' === $price ) {
		$product = sella_ev_product( $event_id );
		$price   = $product ? (string) $product->get_regular_price() : '';
	}
	return $price;
}

/**
 * Whether the SOLD OUT box is checked.
 *
 * @param int $event_id Event ID.
 * @return bool
 */
function sella_ev_is_sold_out( $event_id ) {
	return 'yes' === get_post_meta( $event_id, SELLA_EV_SOLD_OUT_META, true );
}

/**
 * Read a date or a date and time stored in one of the usual shapes: ACF's
 * Ymd and Y-m-d H:i:s, Y-m-d, d/m/Y, d.m.Y, a Unix timestamp. In the site's
 * time zone.
 *
 * @param mixed $value Stored value.
 * @return array{date:string,time:string}|null date Y-m-d, time H:i or ''.
 */
function sella_ev_parse_date( $value ) {
	if ( ! is_scalar( $value ) ) {
		return null;
	}
	$value = trim( (string) $value );
	$m     = array();

	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::\d{2})?)?$/', $value, $m ) ) {
		$y = (int) $m[1];
		$o = (int) $m[2];
		$d = (int) $m[3];
	} elseif ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
		$y = (int) $m[1];
		$o = (int) $m[2];
		$d = (int) $m[3];
	} elseif ( preg_match( '/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})(?:\s+(\d{1,2}):(\d{2}))?$/', $value, $m ) ) {
		$y    = (int) $m[3];
		$o    = (int) $m[2];
		$d    = (int) $m[1];
		$m[4] = isset( $m[4] ) ? $m[4] : '';
		$m[5] = isset( $m[5] ) ? $m[5] : '';
	} elseif ( preg_match( '/^\d{10}$/', $value ) ) {
		$dt = ( new DateTimeImmutable( '@' . $value ) )->setTimezone( wp_timezone() );
		return array(
			'date' => $dt->format( 'Y-m-d' ),
			'time' => '00:00' === $dt->format( 'H:i' ) ? '' : $dt->format( 'H:i' ),
		);
	} else {
		return null;
	}

	if ( $y < 2000 || $y > 2100 || ! checkdate( $o, $d, $y ) ) {
		return null;
	}

	$time = '';
	if ( isset( $m[4], $m[5] ) && '' !== $m[4] && '' !== $m[5] && (int) $m[4] < 24 && (int) $m[5] < 60 ) {
		$time = sprintf( '%02d:%02d', (int) $m[4], (int) $m[5] );
	}
	return array(
		'date' => sprintf( '%04d-%02d-%02d', $y, $o, $d ),
		'time' => $time,
	);
}

/**
 * Read a time of day: H:i or H:i:s.
 *
 * @param mixed $value Stored value.
 * @return string H:i, or ''.
 */
function sella_ev_parse_time( $value ) {
	$m = array();
	if ( is_scalar( $value ) && preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim( (string) $value ), $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}
	return '';
}

/**
 * The event's own fields, as name => [label, type, raw value]. ACF's field
 * objects when ACF knows the post, else the custom fields that do not start
 * with an underscore.
 *
 * @param int $event_id Event ID.
 * @return array<string, array{label:string,type:string,value:mixed}>
 */
function sella_ev_fields( $event_id ) {
	static $memo = array();
	if ( isset( $memo[ $event_id ] ) ) {
		return $memo[ $event_id ];
	}

	$fields = array();
	if ( function_exists( 'get_field_objects' ) ) {
		$objects = get_field_objects( $event_id, false );
		foreach ( is_array( $objects ) ? $objects : array() as $name => $object ) {
			$fields[ (string) $name ] = array(
				'label' => isset( $object['label'] ) ? (string) $object['label'] : '',
				'type'  => isset( $object['type'] ) ? (string) $object['type'] : '',
				'value' => get_post_meta( $event_id, $name, true ),
			);
		}
	}

	foreach ( (array) get_post_meta( $event_id ) as $key => $values ) {
		$key = (string) $key;
		if ( '_' === substr( $key, 0, 1 ) || isset( $fields[ $key ] ) ) {
			continue;
		}
		$fields[ $key ] = array(
			'label' => '',
			'type'  => '',
			'value' => isset( $values[0] ) ? maybe_unserialize( $values[0] ) : '',
		);
	}

	$memo[ $event_id ] = $fields;
	return $fields;
}

/**
 * The first of the event's fields whose name or label matches, with a text value.
 *
 * @param int    $event_id Event ID.
 * @param string $pattern  Regex for the name or the label.
 * @return string
 */
function sella_ev_field_text( $event_id, $pattern ) {
	foreach ( sella_ev_fields( $event_id ) as $name => $field ) {
		if ( ! is_scalar( $field['value'] ) || '' === trim( (string) $field['value'] ) ) {
			continue;
		}
		if ( preg_match( $pattern, $name ) || ( '' !== $field['label'] && preg_match( $pattern, $field['label'] ) ) ) {
			return trim( wp_strip_all_tags( (string) $field['value'] ) );
		}
	}
	return '';
}

/**
 * The date and time the event's own fields hold. A field named or labelled
 * for a date is preferred over any other field that happens to hold one.
 *
 * @param int $event_id Event ID.
 * @return array{date:string,time:string,source:string}|null
 */
function sella_ev_detected_when( $event_id ) {
	$date_re = '/date|when|start|תאריך|מועד/iu';
	$time_re = '/time|hour|שעה/iu';
	$best    = null;
	$score   = -1;
	$time    = '';

	foreach ( sella_ev_fields( $event_id ) as $name => $field ) {
		$named = preg_match( $date_re, $name ) || preg_match( $date_re, $field['label'] );

		if ( in_array( $field['type'], array( 'time_picker' ), true ) || ( '' === $field['type'] && ( preg_match( $time_re, $name ) || preg_match( $time_re, $field['label'] ) ) ) ) {
			$t = sella_ev_parse_time( $field['value'] );
			if ( '' !== $t && '' === $time ) {
				$time = $t;
				continue;
			}
		}

		if ( '' !== $field['type'] && ! in_array( $field['type'], array( 'date_picker', 'date_time_picker', 'text' ), true ) ) {
			continue;
		}
		// A bare 10-digit number is only a date in a field meant for one.
		if ( ! $named && is_scalar( $field['value'] ) && preg_match( '/^\d{10}$/', trim( (string) $field['value'] ) ) ) {
			continue;
		}
		$parsed = sella_ev_parse_date( $field['value'] );
		if ( ! $parsed ) {
			continue;
		}
		$this_score = ( in_array( $field['type'], array( 'date_picker', 'date_time_picker' ), true ) ? 2 : 0 ) + ( $named ? 1 : 0 ) + ( '' !== $parsed['time'] ? 1 : 0 );
		if ( $this_score > $score ) {
			$score  = $this_score;
			$best   = $parsed + array( 'source' => ( '' !== $field['label'] ? $field['label'] : $name ) );
		}
	}

	if ( $best && '' === $best['time'] ) {
		$best['time'] = $time;
	}
	return apply_filters( 'sella_event_detected_when', $best, $event_id );
}

/**
 * When the event takes place: the box's date when typed, else its own fields.
 *
 * @param int $event_id Event ID.
 * @return array{date:string,time:string}|null
 */
function sella_ev_when( $event_id ) {
	$own = sella_ev_parse_date( get_post_meta( $event_id, SELLA_EV_WHEN_META, true ) );
	return $own ? $own : sella_ev_detected_when( $event_id );
}

/**
 * The moment the event starts, as a timestamp: its time, or the end of its
 * day when it has none. Null without a date.
 *
 * @param int $event_id Event ID.
 * @return int|null
 */
function sella_ev_start( $event_id ) {
	$when = sella_ev_when( $event_id );
	if ( ! $when ) {
		return null;
	}
	$clock = '' !== $when['time'] ? $when['time'] . ':00' : '23:59:59';
	try {
		$dt = new DateTimeImmutable( $when['date'] . ' ' . $clock, wp_timezone() );
	} catch ( Exception $e ) {
		return null;
	}
	return (int) apply_filters( 'sella_event_start', $dt->getTimestamp(), $event_id );
}

/**
 * Whether the event has started (no date: never).
 *
 * @param int $event_id Event ID.
 * @return bool
 */
function sella_ev_is_past( $event_id ) {
	$start = sella_ev_start( $event_id );
	return null !== $start && $start <= time();
}

/**
 * The event's state: past, sold_out, on_sale (a product to buy) or none.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function sella_ev_state( $event_id ) {
	if ( sella_ev_is_past( $event_id ) ) {
		return 'past';
	}
	if ( sella_ev_is_sold_out( $event_id ) ) {
		return 'sold_out';
	}
	$product = sella_ev_product( $event_id );
	return $product && 'publish' === $product->get_status() && 'publish' === get_post_status( $event_id ) && '' !== (string) $product->get_price() ? 'on_sale' : 'none';
}

/**
 * The date as the cards print it: 09.06.2027 | 18:30.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function sella_ev_date_text( $event_id ) {
	$when = sella_ev_when( $event_id );
	if ( ! $when ) {
		return '';
	}
	list( $y, $m, $d ) = explode( '-', $when['date'] );
	return $d . '.' . $m . '.' . $y . ( '' !== $when['time'] ? ' | ' . $when['time'] : '' );
}

/**
 * The event's place, from a field named or labelled for one.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function sella_ev_location( $event_id ) {
	return (string) apply_filters( 'sella_event_location', sella_ev_field_text( $event_id, '/location|place|venue|address|מיקום|מקום|כתובת/iu' ), $event_id );
}

/**
 * The line under the title: the excerpt, or a subtitle field.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function sella_ev_subtitle( $event_id ) {
	$text = has_excerpt( $event_id ) ? get_post_field( 'post_excerpt', $event_id ) : sella_ev_field_text( $event_id, '/sub_?title|subtitle|excerpt|תת|כותרת משנה|תקציר/iu' );
	return (string) apply_filters( 'sella_event_subtitle', wp_strip_all_tags( (string) $text ), $event_id );
}

/*
 * ------------------------------------------------------------------
 * The ticket product
 * ------------------------------------------------------------------
 */

/**
 * The ticket's name in the cart and on the order.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function sella_ev_product_name( $event_id ) {
	return (string) apply_filters( 'sella_event_product_name', 'כרטיס: ' . get_the_title( $event_id ), $event_id );
}

/**
 * Bring the product in step with the event: name, price, image, stock
 * status, published only while the event is, out of the catalog.
 *
 * @param WC_Product $product  Product.
 * @param int        $event_id Event ID.
 */
function sella_ev_sync_product( $product, $event_id ) {
	$price = (string) get_post_meta( $event_id, SELLA_EV_PRICE_META, true );

	$product->set_name( sella_ev_product_name( $event_id ) );
	if ( '' !== $price ) {
		$product->set_regular_price( $price );
	}
	$product->set_catalog_visibility( 'hidden' );
	$product->set_virtual( true );
	$product->set_manage_stock( false );
	$product->set_stock_status( sella_ev_is_sold_out( $event_id ) ? 'outofstock' : 'instock' );
	$product->set_status( 'publish' === get_post_status( $event_id ) ? 'publish' : 'private' );
	$thumb = (int) get_post_thumbnail_id( $event_id );
	if ( $thumb ) {
		$product->set_image_id( $thumb );
	}
	$product->update_meta_data( SELLA_EV_EVENT_META, $event_id );
	$product->save();
}

/**
 * Make the event's ticket product (or return the one it has).
 *
 * @param int $event_id Event ID.
 * @return WC_Product|WP_Error
 */
function sella_ev_create_product( $event_id ) {
	$existing = sella_ev_product( $event_id );
	if ( $existing ) {
		return $existing;
	}
	if ( '' === (string) get_post_meta( $event_id, SELLA_EV_PRICE_META, true ) ) {
		return new WP_Error( 'sella_event_price', 'יש להזין מחיר לפני יצירת המוצר.' );
	}

	$product = new WC_Product_Simple();
	$product->set_sold_individually( false );
	$product->set_reviews_allowed( false );
	sella_ev_sync_product( $product, $event_id );
	if ( ! $product->get_id() ) {
		return new WP_Error( 'sella_event_product', 'יצירת המוצר נכשלה.' );
	}

	update_post_meta( $event_id, SELLA_EV_PRODUCT_META, $product->get_id() );
	sella_ev_forget_ids();
	return $product;
}

/**
 * IDs of every ticket product.
 *
 * @return int[]
 */
function sella_ev_product_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$cached = get_transient( SELLA_EV_IDS_CACHE );
	if ( is_array( $cached ) ) {
		$ids = array_map( 'intval', $cached );
		return $ids;
	}
	global $wpdb;
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", SELLA_EV_EVENT_META ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	set_transient( SELLA_EV_IDS_CACHE, $ids, DAY_IN_SECONDS );
	return $ids;
}

/**
 * Work the list out again.
 */
function sella_ev_forget_ids() {
	delete_transient( SELLA_EV_IDS_CACHE );
}
add_action( 'deleted_post', 'sella_ev_forget_ids' );

/**
 * A ticket cannot be bought once the event has started, while it is sold
 * out, or while the event is not published. WooCommerce then also takes it
 * out of carts that hold it.
 *
 * @param bool       $purchasable Purchasable.
 * @param WC_Product $product     Product.
 * @return bool
 */
function sella_ev_purchasable( $purchasable, $product ) {
	if ( ! $purchasable ) {
		return false;
	}
	$event_id = sella_ev_event_of( $product );
	if ( ! $event_id ) {
		return $purchasable;
	}
	return ! sella_ev_is_past( $event_id ) && ! sella_ev_is_sold_out( $event_id ) && 'publish' === get_post_status( $event_id );
}
add_filter( 'woocommerce_is_purchasable', 'sella_ev_purchasable', 20, 2 );

/**
 * Links to the ticket lead to the event: in the cart drawer, the order and
 * the emails.
 *
 * @param string  $link Permalink.
 * @param WP_Post $post Post.
 * @return string
 */
function sella_ev_product_link( $link, $post ) {
	if ( 'product' !== $post->post_type || ! in_array( (int) $post->ID, sella_ev_product_ids(), true ) ) {
		return $link;
	}
	$event_id = sella_ev_event_of( (int) $post->ID );
	return $event_id ? get_permalink( $event_id ) : $link;
}
add_filter( 'post_type_link', 'sella_ev_product_link', 10, 2 );

/**
 * The product's own page, reached by its old address, sends to the event.
 */
function sella_ev_product_redirect() {
	if ( ! is_singular( 'product' ) ) {
		return;
	}
	$event_id = sella_ev_event_of( (int) get_queried_object_id() );
	if ( $event_id && 'publish' === get_post_status( $event_id ) ) {
		wp_safe_redirect( get_permalink( $event_id ), 302 );
		exit;
	}
}
add_action( 'template_redirect', 'sella_ev_product_redirect', 5 );

/**
 * Whether this request is a shopper's (the same rule as the hidden
 * subscriptions: the admin and its AJAX see the tickets).
 *
 * @return bool
 */
function sella_ev_applies() {
	if ( wp_doing_ajax() ) {
		return false === strpos( (string) wp_get_referer(), '/wp-admin/' );
	}
	if ( is_admin() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return ! current_user_can( 'edit_products' );
	}
	return true;
}

/**
 * Tickets are left out of every product list shoppers see, also those that
 * do not ask WooCommerce about catalog visibility (the shop grid, the cart
 * drawer's search).
 *
 * @param WP_Query $query Query.
 */
function sella_ev_query( $query ) {
	if ( $query->is_singular() || ! sella_ev_applies() ) {
		return;
	}
	$types = (array) $query->get( 'post_type' );
	if ( ! in_array( 'product', $types, true ) && ! $query->is_tax( get_object_taxonomies( 'product' ) ) ) {
		return;
	}
	$ids = sella_ev_product_ids();
	if ( $ids ) {
		$query->set( 'post__not_in', array_merge( array_filter( array_map( 'intval', (array) $query->get( 'post__not_in' ) ) ), $ids ) );
	}
}
add_action( 'pre_get_posts', 'sella_ev_query' );

/**
 * Not in related books, upsells or cross-sells.
 *
 * @param bool $visible    Visible.
 * @param int  $product_id Product ID.
 * @return bool
 */
function sella_ev_visible( $visible, $product_id ) {
	return $visible && sella_ev_applies() && in_array( (int) $product_id, sella_ev_product_ids(), true ) ? false : $visible;
}
add_filter( 'woocommerce_product_is_visible', 'sella_ev_visible', 10, 2 );

/**
 * Not in the sitemap.
 *
 * @param int[] $ids Posts left out.
 * @return int[]
 */
function sella_ev_sitemap( $ids ) {
	return array_merge( (array) $ids, sella_ev_product_ids() );
}
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', 'sella_ev_sitemap' );

/*
 * ------------------------------------------------------------------
 * The edit screen
 * ------------------------------------------------------------------
 */

/**
 * The "כרטיסים" box.
 */
function sella_ev_add_meta_box() {
	add_meta_box( 'sella-event-tickets', 'כרטיסים', 'sella_ev_render_meta_box', SELLA_EV_POST_TYPE, 'side', 'high' );
}
add_action( 'add_meta_boxes', 'sella_ev_add_meta_box' );

/**
 * The product part of the box: a link to it, or the button that makes it.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function sella_ev_product_box_html( $event_id ) {
	$product = sella_ev_product( $event_id );
	if ( $product ) {
		return sprintf(
			'<p class="sella-ev-box__product">מוצר מחובר: <a href="%s">%s</a> <span class="description">(#%d, מוסתר בחנות ובחיפוש)</span></p>',
			esc_url( (string) get_edit_post_link( $product->get_id() ) ),
			esc_html( $product->get_name() ),
			(int) $product->get_id()
		);
	}
	return '<p><button type="button" class="button button-primary sella-ev-create">יצירת מוצר לרכישה</button></p>'
		. '<p class="description">ייווצר מוצר מוסתר בחנות ובתוצאות החיפוש, מחובר לאירוע הזה. הכפתור בעמוד האירוע יוסיף אותו לסל.</p>';
}

/**
 * Print the box.
 *
 * @param WP_Post $post Event.
 */
function sella_ev_render_meta_box( $post ) {
	$event_id = (int) $post->ID;
	$when     = (string) get_post_meta( $event_id, SELLA_EV_WHEN_META, true );
	$detected = sella_ev_detected_when( $event_id );
	$state    = sella_ev_state( $event_id );

	wp_nonce_field( 'sella_event_save', 'sella_event_nonce' );
	?>
	<div class="sella-ev-box" data-event="<?php echo (int) $event_id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sella_event_product_' . $event_id ) ); ?>">
		<p>
			<label for="sella_event_price"><strong>מחיר כרטיס (₪)</strong></label><br>
			<input type="number" min="0" step="0.01" id="sella_event_price" name="sella_event_price" value="<?php echo esc_attr( (string) get_post_meta( $event_id, SELLA_EV_PRICE_META, true ) ); ?>" style="width:100%">
		</p>
		<p>
			<label for="sella_event_when"><strong>מועד האירוע</strong></label><br>
			<input type="datetime-local" id="sella_event_when" name="sella_event_when" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( $when, 0, 16 ) ) ); ?>" style="width:100%">
			<span class="description">
				<?php
				if ( $detected ) {
					list( $y, $m, $d ) = explode( '-', $detected['date'] );
					printf(
						/* translators: 1: date, 2: field name */
						esc_html( 'מהשדה "%2$s" של האירוע: %1$s. מילוי כאן גובר עליו.' ),
						esc_html( $d . '.' . $m . '.' . $y . ( '' !== $detected['time'] ? ' ' . $detected['time'] : '' ) ),
						esc_html( $detected['source'] )
					);
				} else {
					echo esc_html( 'לא נמצא תאריך בשדות האירוע. מלאו כאן, כדי שהכפתור יתחלף ל"אירוע עבר" כשהאירוע מתחיל.' );
				}
				?>
			</span>
		</p>
		<p>
			<label><input type="checkbox" name="sella_event_sold_out" value="yes" <?php checked( sella_ev_is_sold_out( $event_id ) ); ?>> <strong>SOLD OUT</strong> – אזלו הכרטיסים</label>
		</p>
		<?php if ( 'past' === $state ) : ?>
			<p class="description">האירוע כבר התחיל: הכפתור מציג "<?php echo esc_html( sella_ev_text( 'past' ) ); ?>".</p>
		<?php endif; ?>
		<div class="sella-ev-box__product-wrap"><?php echo sella_ev_product_box_html( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the function. ?></div>
		<p class="sella-ev-box__error" style="color:#d63638;display:none"></p>
	</div>
	<script>
	( function () {
		var box = document.querySelector( '.sella-ev-box' );
		if ( ! box ) { return; }
		box.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.sella-ev-create' );
			if ( ! btn ) { return; }
			var err = box.querySelector( '.sella-ev-box__error' );
			var body = new FormData();
			body.append( 'action', 'sella_event_create_product' );
			body.append( 'event', box.getAttribute( 'data-event' ) );
			body.append( 'nonce', box.getAttribute( 'data-nonce' ) );
			body.append( 'price', box.querySelector( '#sella_event_price' ).value );
			body.append( 'sold_out', box.querySelector( '[name="sella_event_sold_out"]' ).checked ? 'yes' : '' );
			btn.disabled = true;
			err.style.display = 'none';
			fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( json && json.success ) {
						box.querySelector( '.sella-ev-box__product-wrap' ).innerHTML = json.data.html;
					} else {
						err.textContent = ( json && json.data && json.data.message ) || 'שגיאה';
						err.style.display = '';
						btn.disabled = false;
					}
				} )
				.catch( function () { err.textContent = 'שגיאה'; err.style.display = ''; btn.disabled = false; } );
		} );
	} )();
	</script>
	<?php
}

/**
 * Save the box. Late, after ACF has saved the event's own fields, so the
 * product and the cache purge see the final date.
 *
 * @param int $event_id Event ID.
 */
function sella_ev_save( $event_id ) {
	if ( wp_is_post_revision( $event_id ) || wp_is_post_autosave( $event_id ) ) {
		return;
	}
	if ( isset( $_POST['sella_event_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sella_event_nonce'] ) ), 'sella_event_save' ) && current_user_can( 'edit_post', $event_id ) ) {
		$price = isset( $_POST['sella_event_price'] ) ? wc_format_decimal( wp_unslash( $_POST['sella_event_price'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_decimal.
		update_post_meta( $event_id, SELLA_EV_PRICE_META, '' === $price ? '' : $price );

		$when   = isset( $_POST['sella_event_when'] ) ? sanitize_text_field( wp_unslash( $_POST['sella_event_when'] ) ) : '';
		$parsed = sella_ev_parse_date( $when );
		update_post_meta( $event_id, SELLA_EV_WHEN_META, $parsed ? $parsed['date'] . ( '' !== $parsed['time'] ? ' ' . $parsed['time'] : '' ) : '' );

		update_post_meta( $event_id, SELLA_EV_SOLD_OUT_META, isset( $_POST['sella_event_sold_out'] ) ? 'yes' : 'no' );
	}

	$product = sella_ev_product( $event_id );
	if ( $product ) {
		sella_ev_sync_product( $product, $event_id );
	}
	sella_ev_schedule_purge( $event_id, true );
}
add_action( 'save_post_' . SELLA_EV_POST_TYPE, 'sella_ev_save', 50 );

/**
 * The button in the box: make the product now, with the price typed so far.
 */
function sella_ev_ajax_create_product() {
	$event_id = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
	$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! $event_id || ! wp_verify_nonce( $nonce, 'sella_event_product_' . $event_id ) || ! current_user_can( 'edit_post', $event_id ) || ! current_user_can( 'publish_products' ) ) {
		wp_send_json_error( array( 'message' => 'אין הרשאה.' ), 403 );
	}
	if ( SELLA_EV_POST_TYPE !== get_post_type( $event_id ) ) {
		wp_send_json_error( array( 'message' => 'זה לא אירוע.' ), 400 );
	}

	$price = isset( $_POST['price'] ) ? wc_format_decimal( wp_unslash( $_POST['price'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_decimal.
	if ( '' !== $price ) {
		update_post_meta( $event_id, SELLA_EV_PRICE_META, $price );
	}
	update_post_meta( $event_id, SELLA_EV_SOLD_OUT_META, ( isset( $_POST['sold_out'] ) && 'yes' === $_POST['sold_out'] ) ? 'yes' : 'no' );

	$product = sella_ev_create_product( $event_id );
	if ( is_wp_error( $product ) ) {
		wp_send_json_error( array( 'message' => $product->get_error_message() ), 400 );
	}
	wp_send_json_success( array( 'html' => sella_ev_product_box_html( $event_id ) ) );
}
add_action( 'wp_ajax_sella_event_create_product', 'sella_ev_ajax_create_product' );

/**
 * The events list: a "כרטיסים" column with the price and the state.
 *
 * @param string[] $columns Columns.
 * @return string[]
 */
function sella_ev_columns( $columns ) {
	$columns['sella_event_tickets'] = 'כרטיסים';
	return $columns;
}
add_filter( 'manage_' . SELLA_EV_POST_TYPE . '_posts_columns', 'sella_ev_columns' );

/**
 * Print the column.
 *
 * @param string $column   Column.
 * @param int    $event_id Event ID.
 */
function sella_ev_column( $column, $event_id ) {
	if ( 'sella_event_tickets' !== $column ) {
		return;
	}
	$labels = array(
		'past'     => sella_ev_text( 'past' ),
		'sold_out' => sella_ev_text( 'sold_out' ),
		'on_sale'  => 'במכירה',
		'none'     => sella_ev_product( $event_id ) ? 'לא במכירה' : 'אין מוצר',
	);
	$price = sella_ev_price( $event_id );
	echo esc_html( ( '' !== $price ? wp_strip_all_tags( wc_price( $price ) ) . ' · ' : '' ) . $labels[ sella_ev_state( $event_id ) ] );
	$date = sella_ev_date_text( $event_id );
	if ( '' !== $date ) {
		echo '<br><span class="description">' . esc_html( $date ) . '</span>';
	}
}
add_action( 'manage_' . SELLA_EV_POST_TYPE . '_posts_custom_column', 'sella_ev_column', 10, 2 );

/*
 * ------------------------------------------------------------------
 * The page cache: emptied when an event starts
 * ------------------------------------------------------------------
 */

/**
 * Schedule (or move) the purge for the event's start.
 *
 * @param int  $event_id Event ID.
 * @param bool $move     Replace one already scheduled (the date may have changed).
 */
function sella_ev_schedule_purge( $event_id, $move = false ) {
	$args = array( (int) $event_id );
	$next = wp_next_scheduled( SELLA_EV_CRON, $args );
	if ( $next && ! $move ) {
		return;
	}
	if ( $next ) {
		wp_unschedule_event( $next, SELLA_EV_CRON, $args );
	}
	$start = sella_ev_start( $event_id );
	if ( null !== $start && $start > time() && 'publish' === get_post_status( $event_id ) ) {
		wp_schedule_single_event( $start + 30, SELLA_EV_CRON, $args );
	}
}

/**
 * The event started: guests must see "אירוע עבר", not a cached button.
 */
function sella_ev_on_started() {
	if ( function_exists( 'sella_pc_purge_all' ) ) {
		sella_pc_purge_all();
	}
}
add_action( SELLA_EV_CRON, 'sella_ev_on_started' );

/*
 * ------------------------------------------------------------------
 * The button on the event's page
 * ------------------------------------------------------------------
 */

/**
 * The buy form, or the state in its place.
 *
 * @param int   $event_id Event ID.
 * @param array $args {
 *     @type string $text       Button text.
 *     @type string $icon       Icon HTML, already escaped.
 *     @type string $btn_class  Classes of the button (the Elementor widget's own, to keep its look).
 *     @type string $fallback   What to print for an event with nothing on sale.
 * }
 * @return string
 */
function sella_ev_buy_html( $event_id, $args = array() ) {
	$args  = wp_parse_args(
		$args,
		array(
			'text'      => sella_ev_text( 'buy' ),
			'icon'      => '',
			'btn_class' => '',
			'fallback'  => '',
		)
	);
	$state = sella_ev_state( $event_id );

	if ( 'publish' === get_post_status( $event_id ) ) {
		sella_ev_schedule_purge( $event_id );
	}

	$btn_class = trim( 'sella-event-buy__btn ' . $args['btn_class'] );

	if ( 'past' === $state || 'sold_out' === $state ) {
		return sprintf(
			'<div class="sella-event-buy sella-event-buy--%1$s"><span class="%2$s is-off" aria-disabled="true"><span class="elementor-button-content-wrapper"><span class="elementor-button-text">%3$s</span></span></span></div>',
			esc_attr( str_replace( '_', '-', $state ) ),
			esc_attr( $btn_class ),
			esc_html( sella_ev_text( $state ) )
		);
	}
	if ( 'on_sale' !== $state ) {
		return $args['fallback'];
	}

	$product = sella_ev_product( $event_id );
	$max     = (int) apply_filters( 'sella_event_max_quantity', 10, $event_id );
	wp_enqueue_script( 'sella-events', get_stylesheet_directory_uri() . '/assets/js/sella-events.js', array(), HELLO_ELEMENTOR_CHILD_VERSION, true );

	ob_start();
	?>
	<form class="cart sella-event-buy sella-event-buy--on-sale" method="post" action="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
		<input type="hidden" name="add-to-cart" value="<?php echo (int) $product->get_id(); ?>">
		<span class="sella-event-buy__price"><?php echo wp_kses_post( wc_price( wc_get_price_to_display( $product ) ) ); ?></span>
		<div class="sella-event-qty">
			<button type="button" class="sella-event-qty__btn" data-step="1" aria-label="כרטיס נוסף">+</button>
			<input type="number" class="sella-event-qty__input qty" name="quantity" value="1" min="1" max="<?php echo (int) $max; ?>" step="1" inputmode="numeric" aria-label="מספר כרטיסים">
			<button type="button" class="sella-event-qty__btn" data-step="-1" aria-label="כרטיס אחד פחות">−</button>
		</div>
		<button type="submit" class="<?php echo esc_attr( $btn_class . ' single_add_to_cart_button' ); ?>">
			<span class="elementor-button-content-wrapper">
				<?php if ( '' !== $args['icon'] ) : ?>
					<span class="elementor-button-icon"><?php echo $args['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's icon markup. ?></span>
				<?php endif; ?>
				<span class="elementor-button-text"><?php echo esc_html( $args['text'] ); ?></span>
			</span>
		</button>
	</form>
	<?php
	return (string) ob_get_clean();
}

/*
 * The event's page and the events page are changed on the finished HTML,
 * not inside Elementor: the single-event template is shared by every event,
 * and Elementor's element cache keeps a copy of a widget's output for a day.
 * A buy button rendered inside the widget would show one event's ticket on
 * another, or an old button until the cache expires.
 *
 * The HTML is built when the page starts (output buffering cannot be used
 * inside a buffer callback), and the callback only swaps strings.
 */

/**
 * What the page buffer will put in: the buy form (with the widget's text and
 * icon left as placeholders) and the events list.
 *
 * @var array{form:string,list:string}
 */
$GLOBALS['sella_ev_swap'] = array(
	'form' => '',
	'list' => '',
);

/**
 * Whether this is the page the events list replaces the old list on.
 *
 * @return bool
 */
function sella_ev_is_events_page() {
	return is_page( (string) apply_filters( 'sella_events_page', 'events' ) );
}

/**
 * Build the replacements and start the buffer. After the page cache's and
 * the tracking buffers start, so this one runs first and the cached page
 * holds the result.
 */
function sella_ev_page_start() {
	if ( is_admin() || is_feed() ) {
		return;
	}
	if ( is_singular( SELLA_EV_POST_TYPE ) ) {
		$event_id = (int) get_queried_object_id();
		$form     = sella_ev_buy_html(
			$event_id,
			array(
				'text'      => '%%SELLA_EV_TEXT%%',
				'icon'      => '%%SELLA_EV_ICON%%',
				'btn_class' => 'elementor-button elementor-size-%%SELLA_EV_SIZE%%',
			)
		);
		if ( '' === $form ) {
			return; // Nothing on sale: the widget stays as it is.
		}
		$GLOBALS['sella_ev_swap']['form'] = $form;
	} elseif ( sella_ev_is_events_page() ) {
		$GLOBALS['sella_ev_swap']['list'] = sella_ev_list_shortcode( array() );
	} else {
		return;
	}
	ob_start( 'sella_ev_page_swap' );
}
add_action( 'template_redirect', 'sella_ev_page_start', PHP_INT_MAX );

/**
 * The stepper's script, on the pages that may show the form.
 */
function sella_ev_enqueue() {
	if ( is_singular( SELLA_EV_POST_TYPE ) ) {
		wp_enqueue_script( 'sella-events', get_stylesheet_directory_uri() . '/assets/js/sella-events.js', array(), HELLO_ELEMENTOR_CHILD_VERSION, true );
	}
}
add_action( 'wp_enqueue_scripts', 'sella_ev_enqueue' );

/**
 * Buffer callback: the add-to-cart widget's button becomes the buy form (in
 * the button's own text, icon and size), and the old events list becomes
 * the full one. A page without them passes through unchanged.
 *
 * @param string $html Page HTML.
 * @return string
 */
function sella_ev_page_swap( $html ) {
	$swap = $GLOBALS['sella_ev_swap'];

	if ( '' !== $swap['form'] && false !== strpos( $html, 'elementor-widget-wc-add-to-cart' ) ) {
		$out = preg_replace_callback(
			'#(<div[^>]*\belementor-widget-wc-add-to-cart\b[^>]*>\s*(?:<div class="elementor-widget-container">\s*)?)(<a\b[^>]*\belementor-button\b[^>]*>.*?</a>)#s',
			static function ( $m ) use ( $swap ) {
				$text = sella_ev_text( 'buy' );
				$icon = '';
				$size = 'sm';
				$t    = array();
				$i    = array();
				$s    = array();
				if ( preg_match( '#<span class="elementor-button-text">(.*?)</span>#s', $m[2], $t ) ) {
					$text = trim( html_entity_decode( wp_strip_all_tags( $t[1] ), ENT_QUOTES, 'UTF-8' ) );
				}
				if ( preg_match( '#<span class="elementor-button-icon">(.*?)</span>\s*<span class="elementor-button-text">#s', $m[2], $i ) ) {
					$icon = trim( $i[1] );
				}
				if ( preg_match( '#\belementor-size-([a-z]+)#', $m[2], $s ) ) {
					$size = $s[1];
				}
				return $m[1] . str_replace(
					array( '%%SELLA_EV_TEXT%%', '%%SELLA_EV_ICON%%', '%%SELLA_EV_SIZE%%' ),
					array( esc_html( $text ), $icon, esc_attr( $size ) ),
					$swap['form']
				);
			},
			$html
		);
		$html = is_string( $out ) ? $out : $html;
	}

	if ( '' !== $swap['list'] && false !== strpos( $html, 'sibolet-events-section' ) ) {
		$out  = preg_replace( '#<section class="sibolet-events-section">.*?</section>#s', str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $swap['list'] ), $html, 1 );
		$html = is_string( $out ) ? $out : $html;
	}

	return $html;
}

/**
 * [sella_event_buy id=""]: the button anywhere (the current event by default).
 *
 * @param array $atts Attributes.
 * @return string
 */
function sella_ev_buy_shortcode( $atts ) {
	$atts     = shortcode_atts( array( 'id' => 0 ), $atts, 'sella_event_buy' );
	// In an Elementor template the current post may be the template; the page's event is the queried one.
	$event_id = $atts['id'] ? absint( $atts['id'] ) : ( is_singular( SELLA_EV_POST_TYPE ) ? (int) get_queried_object_id() : (int) get_the_ID() );
	if ( ! $event_id || SELLA_EV_POST_TYPE !== get_post_type( $event_id ) ) {
		return '';
	}
	return sella_ev_buy_html( $event_id, array( 'btn_class' => 'sella-event-buy__btn--theme' ) );
}
add_shortcode( 'sella_event_buy', 'sella_ev_buy_shortcode' );

/*
 * ------------------------------------------------------------------
 * The events page: [sella_events]
 * ------------------------------------------------------------------
 */

/**
 * The published events, upcoming first (soonest first), then past ones
 * (latest first). An event without a date counts as upcoming, after the
 * dated ones.
 *
 * @param string $show all, upcoming or past.
 * @return array{upcoming:int[],past:int[]}
 */
function sella_ev_sorted_ids( $show = 'all' ) {
	$ids = get_posts(
		array(
			'post_type'        => SELLA_EV_POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 200,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);

	$upcoming = array();
	$past     = array();
	foreach ( $ids as $id ) {
		$start = sella_ev_start( (int) $id );
		if ( null !== $start && $start <= time() ) {
			$past[ (int) $id ] = $start;
		} else {
			$upcoming[ (int) $id ] = null === $start ? PHP_INT_MAX : $start;
		}
	}
	asort( $upcoming );
	arsort( $past );

	return array(
		'upcoming' => 'past' === $show ? array() : array_keys( $upcoming ),
		'past'     => 'upcoming' === $show ? array() : array_keys( $past ),
	);
}

/**
 * One event's card.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function sella_ev_card_html( $event_id ) {
	$state    = sella_ev_state( $event_id );
	$subtitle = sella_ev_subtitle( $event_id );
	$date     = sella_ev_date_text( $event_id );
	$location = sella_ev_location( $event_id );
	$price    = sella_ev_price( $event_id );
	$image    = get_the_post_thumbnail( $event_id, 'large', array( 'class' => 'sella-events__img', 'loading' => 'lazy' ) );

	$badge = '';
	if ( 'past' === $state || 'sold_out' === $state ) {
		$badge = '<span class="sella-events__badge sella-events__badge--' . esc_attr( str_replace( '_', '-', $state ) ) . '">' . esc_html( sella_ev_text( $state ) ) . '</span>';
	}

	ob_start();
	?>
	<a href="<?php echo esc_url( get_permalink( $event_id ) ); ?>" class="sella-events__card sella-events__card--<?php echo esc_attr( str_replace( '_', '-', $state ) ); ?>">
		<div class="sella-events__media">
			<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup. ?>
			<?php echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		</div>
		<div class="sella-events__body">
			<h3 class="sella-events__title"><?php echo esc_html( get_the_title( $event_id ) ); ?></h3>
			<?php if ( '' !== $subtitle ) : ?>
				<p class="sella-events__subtitle"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $date || '' !== $location ) : ?>
				<div class="sella-events__meta">
					<?php if ( '' !== $date ) : ?>
						<span class="sella-events__meta-row sella-events__meta-row--date"><?php echo esc_html( $date ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $location ) : ?>
						<span class="sella-events__meta-row sella-events__meta-row--place"><?php echo esc_html( $location ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( 'on_sale' === $state ) : ?>
				<div class="sella-events__foot">
					<span class="sella-events__price"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
					<span class="sella-events__cta"><?php echo esc_html( sella_ev_text( 'details' ) ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	</a>
	<?php
	return (string) ob_get_clean();
}

/**
 * [sella_events show="all|upcoming|past"].
 *
 * @param array $atts Attributes.
 * @return string
 */
function sella_ev_list_shortcode( $atts ) {
	$atts   = shortcode_atts( array( 'show' => 'all' ), $atts, 'sella_events' );
	$groups = sella_ev_sorted_ids( sanitize_key( $atts['show'] ) );

	if ( ! $groups['upcoming'] && ! $groups['past'] ) {
		return '<p class="sella-events__empty">' . esc_html( sella_ev_text( 'none' ) ) . '</p>';
	}

	$html = '<section class="sella-events">';
	if ( $groups['upcoming'] ) {
		$html .= '<div class="sella-events__grid">' . implode( '', array_map( 'sella_ev_card_html', $groups['upcoming'] ) ) . '</div>';
	}
	if ( $groups['past'] ) {
		if ( $groups['upcoming'] ) {
			$html .= '<h2 class="sella-events__heading">אירועים שהיו</h2>';
		}
		$html .= '<div class="sella-events__grid sella-events__grid--past">' . implode( '', array_map( 'sella_ev_card_html', $groups['past'] ) ) . '</div>';
	}
	return $html . '</section>';
}
add_shortcode( 'sella_events', 'sella_ev_list_shortcode' );
