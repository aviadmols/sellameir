<?php
/**
 * Sales dashboard: one admin screen, in Hebrew, that answers three questions.
 *
 * - סקירה כללית: how much the shop sold in a period — revenue, orders, books,
 *   new subscriptions, the money the offers brought — a bar per day (or per
 *   month for long periods) and the best-selling books.
 * - הצעות ופופאפים: how much each offer actually sold, from the order lines
 *   each one tags:
 *     upsell popups          _sella_upsell_source / _sella_upsell_popup_id (sella-upsell-popups.php)
 *     checkout offers        _sella_checkout_offer_id (sella-checkout-offers.php)
 *     After Sale (LETS)      _lets_after_sell, the offer's name (lets-payplus-woocommerce)
 *     deals in the cart      "מבצע", the deal's name (Smart Cart plugin)
 *   A line counts once, for the first of these it carries, in that order.
 *   Published popups and checkout offers that sold nothing are listed too.
 * - משלוחים: what customers paid for shipping (the shipping lines, with tax),
 *   per method, and how many orders shipped free or not at all.
 * - קופונים: each coupon used — how often, the discount it gave, the orders'
 *   total, the first and last use, and every use with its date and amount.
 * - הזמנות ולקוחות: the orders of the period, filtered by what was bought
 *   (a subscription and books in the same order, only books, chosen books —
 *   any of them or all of them), by shipping method and by coupon, with the
 *   customer's contact and full address. The export button submits the filter
 *   form itself, so the file holds exactly the orders of the filters chosen,
 *   in the layout of the LETS gift list (see the export section below).
 *
 * Only paid orders count (wc_get_is_paid_statuses(): processing, completed).
 * A subscription line is one LETS marks with _lets_subscription, or a product
 * sella-hidden-subscriptions.php counts as a subscription; every other line
 * is a book. Revenue is what the customer paid: order totals, and line total
 * plus its tax for a line.
 *
 * The figures are read with a few queries straight from the order item tables
 * (the same under HPOS and the classic post storage), so a year of orders
 * loads without building an order object for each one; full order objects are
 * loaded only for the rows on screen and for the export.
 *
 * Admin: דשבורד מכירות (?page=sella-sales-dashboard).
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_SD_SLUG', 'sella-sales-dashboard' );
define( 'SELLA_SD_PER_PAGE', 50 );

/**
 * ---------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------------
 */

/**
 * The menu item, just above the upsell popups.
 */
function sella_sd_register_page() {
	add_menu_page( 'דשבורד מכירות', 'דשבורד מכירות', 'manage_woocommerce', SELLA_SD_SLUG, 'sella_sd_render_page', 'dashicons-chart-area', 57.9 );
}
add_action( 'admin_menu', 'sella_sd_register_page' );

/**
 * @param string $hook Admin hook.
 */
function sella_sd_assets( $hook ) {
	if ( false === strpos( $hook, SELLA_SD_SLUG ) ) {
		return;
	}
	$file = get_stylesheet_directory() . '/assets/css/sella-sales-dashboard-admin.css';
	wp_enqueue_style( 'sella-sales-dashboard', get_stylesheet_directory_uri() . '/assets/css/sella-sales-dashboard-admin.css', array(), (string) filemtime( $file ) );

	// The book picker on the orders tab is WooCommerce's own product search.
	$state = sella_sd_state();
	if ( 'orders' === $state['tab'] ) {
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
	}
}
add_action( 'admin_enqueue_scripts', 'sella_sd_assets' );

/**
 * ---------------------------------------------------------------------------
 * State: tab, period and filters, from the query string
 * ---------------------------------------------------------------------------
 */

/**
 * @return array<string, string>
 */
function sella_sd_tabs() {
	return array(
		'overview' => 'סקירה כללית',
		'offers'   => 'הצעות ופופאפים',
		'shipping' => 'משלוחים',
		'coupons'  => 'קופונים',
		'orders'   => 'הזמנות ולקוחות',
	);
}

/**
 * @return array<string, string>
 */
function sella_sd_periods() {
	return array(
		'today'      => 'היום',
		'7d'         => '7 ימים אחרונים',
		'month'      => 'החודש',
		'last_month' => 'החודש שעבר',
		'year'       => 'השנה',
	);
}

/**
 * What an order must hold to be listed.
 *
 * @return array<string, string>
 */
function sella_sd_what_options() {
	return array(
		'all'        => 'כל ההזמנות',
		'sub_books'  => 'מנוי + ספרים באותה הזמנה',
		'sub'        => 'הזמנות עם מנוי',
		'books_only' => 'ספרים בלבד, בלי מנוי',
		'offers'     => 'הזמנות עם מוצר מהצעה או פופאפ',
	);
}

/**
 * The screen's state, read once.
 *
 * @return array{tab: string, period: string, from: string, to: string, what: string, products: int[], match: string, ship: string, coupon: string, paged: int}
 */
function sella_sd_state() {
	static $state = null;
	if ( null !== $state ) {
		return $state;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$get = function ( $key ) {
		return isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	};

	$tab    = $get( 'tab' );
	$period = $get( 'period' );
	$what   = $get( 'what' );
	$from   = $get( 'from' );
	$to     = $get( 'to' );

	$products = array();
	if ( isset( $_GET['products'] ) ) {
		$products = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_GET['products'] ) ) ) ) );
	}
	// phpcs:enable

	$date = '/^\d{4}-\d{2}-\d{2}$/';

	$state = array(
		'tab'      => isset( sella_sd_tabs()[ $tab ] ) ? $tab : 'overview',
		'period'   => isset( sella_sd_periods()[ $period ] ) || 'custom' === $period ? $period : 'month',
		'from'     => preg_match( $date, $from ) ? $from : '',
		'to'       => preg_match( $date, $to ) ? $to : '',
		'what'     => isset( sella_sd_what_options()[ $what ] ) ? $what : 'all',
		'products' => $products,
		'match'    => 'all' === $get( 'match' ) ? 'all' : 'any',
		'ship'     => $get( 'ship' ),
		'coupon'   => $get( 'coupon' ),
		'paged'    => max( 1, absint( $get( 'paged' ) ) ),
	);

	if ( 'custom' === $state['period'] && ( '' === $state['from'] || '' === $state['to'] ) ) {
		$state['period'] = 'month';
	}

	return $state;
}

/**
 * A link to the screen with the current state, changed by $args.
 *
 * @param array $args State to change; null removes a key.
 * @return string
 */
function sella_sd_url( $args = array() ) {
	$state = array_merge( sella_sd_state(), array( 'paged' => 1 ), $args );

	$query = array(
		'page'   => SELLA_SD_SLUG,
		'tab'    => $state['tab'],
		'period' => $state['period'],
	);
	if ( 'custom' === $state['period'] ) {
		$query['from'] = $state['from'];
		$query['to']   = $state['to'];
	}
	if ( 'all' !== $state['what'] ) {
		$query['what'] = $state['what'];
	}
	if ( $state['products'] ) {
		$query['products'] = $state['products'];
		$query['match']    = $state['match'];
	}
	if ( '' !== $state['ship'] ) {
		$query['ship'] = $state['ship'];
	}
	if ( '' !== $state['coupon'] ) {
		$query['coupon'] = $state['coupon'];
	}
	if ( $state['paged'] > 1 ) {
		$query['paged'] = $state['paged'];
	}

	return add_query_arg( urlencode_deep( $query ), admin_url( 'admin.php' ) );
}

/**
 * The dates the period covers, in the site's time zone.
 *
 * @param array $state Screen state.
 * @return array{from: string, to: string, label: string}
 */
function sella_sd_range( $state ) {
	$today = new DateTimeImmutable( 'now', wp_timezone() );
	$to    = $today;

	switch ( $state['period'] ) {
		case 'today':
			$from = $today;
			break;
		case '7d':
			$from = $today->modify( '-6 days' );
			break;
		case 'last_month':
			$from = $today->modify( 'first day of last month' );
			$to   = $today->modify( 'last day of last month' );
			break;
		case 'year':
			$from = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
			break;
		case 'custom':
			$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $state['from'], wp_timezone() );
			$to   = DateTimeImmutable::createFromFormat( '!Y-m-d', $state['to'], wp_timezone() );
			if ( ! $from || ! $to ) {
				$from = $today->modify( 'first day of this month' );
				$to   = $today;
			} elseif ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
			break;
		default:
			$from = $today->modify( 'first day of this month' );
	}

	$label = $from->format( 'Y-m-d' ) === $to->format( 'Y-m-d' )
		? $from->format( 'j.n.Y' )
		: $from->format( 'j.n.Y' ) . ' – ' . $to->format( 'j.n.Y' );

	return array(
		'from'  => $from->format( 'Y-m-d' ),
		'to'    => $to->format( 'Y-m-d' ),
		'label' => $label,
	);
}

/**
 * ---------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------------
 */

/**
 * Item meta read for every line.
 *
 * @return string[]
 */
function sella_sd_item_meta_keys() {
	return array(
		'_product_id',
		'_variation_id',
		'_qty',
		'_line_total',
		'_line_tax',
		'_lets_subscription',
		'_sella_checkout_offer_id',
		'_sella_upsell_source',
		'_sella_upsell_popup_id',
		'_lets_after_sell',
		'מבצע',
	);
}

/**
 * The kinds of offers, in the order a line is attributed to them.
 *
 * @return array<string, array{title: string, short: string, hint: string}>
 */
function sella_sd_offer_types() {
	return array(
		'checkout'   => array(
			'title' => 'הצעות בדף התשלום',
			'short' => 'הצעה בדף התשלום',
			'hint'  => 'הפופאפ במרכז דף התשלום, עם המחיר המיוחד',
		),
		'popup'      => array(
			'title' => 'פופאפים באתר (Upsell)',
			'short' => 'פופאפ',
			'hint'  => 'הפופאפים הצדדיים בעמודי האתר ובסל',
		),
		'after_sell' => array(
			'title' => 'הצעות אחרי הרכישה (After Sale)',
			'short' => 'After Sale',
			'hint'  => 'ההצעה בעמוד התודה, בתשלום בלחיצה אחת',
		),
		'deal'       => array(
			'title' => 'מבצעים בסל',
			'short' => 'מבצע',
			'hint'  => 'מבצעי הכמות של העגלה החכמה, כמו 5 ספרים ב-200',
		),
	);
}

/**
 * Subscription product IDs.
 *
 * @return int[]
 */
function sella_sd_subscription_ids() {
	static $ids = null;
	if ( null === $ids ) {
		$ids = function_exists( 'sella_hs_ids' ) ? array_map( 'intval', sella_hs_ids() ) : array();
	}
	return $ids;
}

/**
 * Whether WooCommerce keeps orders in its own tables.
 *
 * @return bool
 */
function sella_sd_hpos() {
	return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * The paid orders of a period, each with its total, date and lines.
 *
 * @param string $from First day, Y-m-d.
 * @param string $to   Last day, Y-m-d.
 * @return array<int, array> Order ID => order, newest first.
 */
function sella_sd_orders( $from, $to ) {
	static $cache = array();
	$key = $from . '|' . $to;
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$ids = wc_get_orders(
		array(
			'type'         => 'shop_order',
			'status'       => wc_get_is_paid_statuses(),
			'date_created' => $from . '...' . $to,
			'limit'        => -1,
			'return'       => 'ids',
			'orderby'      => 'date',
			'order'        => 'DESC',
		)
	);
	$ids = array_map( 'intval', (array) $ids );

	$orders = array();
	foreach ( $ids as $id ) {
		$orders[ $id ] = array(
			'id'     => $id,
			'total'  => 0.0,
			'date'   => '',
			'lines'      => array(),
			'sub'        => false,
			'book'       => false,
			'offer'      => false,
			'shipping'   => array(),
			'ship_total' => 0.0,
			'coupons'    => array(),
			'discount'   => 0.0,
		);
	}

	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		sella_sd_fill_totals( $orders, $chunk );
		sella_sd_fill_lines( $orders, $chunk );
		sella_sd_fill_shipping_coupons( $orders, $chunk );
	}

	$cache[ $key ] = $orders;
	return $orders;
}

/**
 * Order totals and dates (local), for a chunk of IDs.
 *
 * @param array $orders Orders, by reference.
 * @param int[] $ids    IDs.
 */
function sella_sd_fill_totals( &$orders, $ids ) {
	global $wpdb;
	$in = implode( ',', array_map( 'intval', $ids ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer IDs.
	if ( sella_sd_hpos() ) {
		$rows = $wpdb->get_results( "SELECT id, total_amount AS total, date_created_gmt AS created FROM {$wpdb->prefix}wc_orders WHERE id IN ({$in})" );
		foreach ( $rows as $row ) {
			$orders[ (int) $row->id ]['total'] = (float) $row->total;
			$orders[ (int) $row->id ]['date']  = get_date_from_gmt( $row->created, 'Y-m-d' );
		}
	} else {
		$rows = $wpdb->get_results(
			"SELECT p.ID AS id, p.post_date AS created, pm.meta_value AS total FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
			WHERE p.ID IN ({$in})"
		);
		foreach ( $rows as $row ) {
			$orders[ (int) $row->id ]['total'] = (float) $row->total;
			$orders[ (int) $row->id ]['date']  = substr( (string) $row->created, 0, 10 );
		}
	}
	// phpcs:enable
}

/**
 * Order lines, with what bought them, for a chunk of IDs.
 *
 * @param array $orders Orders, by reference.
 * @param int[] $ids    IDs.
 */
function sella_sd_fill_lines( &$orders, $ids ) {
	global $wpdb;
	$in   = implode( ',', array_map( 'intval', $ids ) );
	$keys = implode( ',', array_map( function ( $k ) use ( $wpdb ) {
		return $wpdb->prepare( '%s', $k );
	}, sella_sd_item_meta_keys() ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer IDs, prepared keys.
	$rows = $wpdb->get_results(
		"SELECT oi.order_item_id, oi.order_id, oi.order_item_name, m.meta_key, m.meta_value
		FROM {$wpdb->prefix}woocommerce_order_items oi
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
		WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$in}) AND m.meta_key IN ({$keys})
		ORDER BY oi.order_item_id"
	);
	// phpcs:enable

	$items = array();
	foreach ( $rows as $row ) {
		$id = (int) $row->order_item_id;
		if ( ! isset( $items[ $id ] ) ) {
			$items[ $id ] = array(
				'order_id' => (int) $row->order_id,
				'name'     => (string) $row->order_item_name,
				'meta'     => array(),
			);
		}
		$items[ $id ]['meta'][ $row->meta_key ] = (string) $row->meta_value;
	}

	$subs = sella_sd_subscription_ids();
	foreach ( $items as $item ) {
		if ( ! isset( $orders[ $item['order_id'] ] ) ) {
			continue;
		}
		$m          = $item['meta'];
		$product_id = (int) ( $m['_product_id'] ?? 0 );
		$is_sub     = ! empty( $m['_lets_subscription'] ) || ( $product_id && in_array( $product_id, $subs, true ) );

		$line = array(
			'name'         => $item['name'],
			'product_id'   => $product_id,
			'variation_id' => (int) ( $m['_variation_id'] ?? 0 ),
			'qty'          => max( 1, (int) ( $m['_qty'] ?? 1 ) ),
			'total'        => (float) ( $m['_line_total'] ?? 0 ) + (float) ( $m['_line_tax'] ?? 0 ),
			'sub'          => $is_sub,
			'source'       => sella_sd_line_source( $m ),
		);

		$order = &$orders[ $item['order_id'] ];
		$order['lines'][] = $line;
		if ( $is_sub ) {
			$order['sub'] = true;
		} else {
			$order['book'] = true;
		}
		if ( $line['source'] ) {
			$order['offer'] = true;
		}
		unset( $order );
	}
}

/**
 * Shipping lines (method name, what the customer paid for it with its tax)
 * and coupons (code, discount with its tax), for a chunk of IDs.
 *
 * @param array $orders Orders, by reference.
 * @param int[] $ids    IDs.
 */
function sella_sd_fill_shipping_coupons( &$orders, $ids ) {
	global $wpdb;
	$in = implode( ',', array_map( 'intval', $ids ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer IDs.
	$rows = $wpdb->get_results(
		"SELECT oi.order_item_id, oi.order_id, oi.order_item_type, oi.order_item_name, m.meta_key, m.meta_value
		FROM {$wpdb->prefix}woocommerce_order_items oi
		LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
			AND m.meta_key IN ('cost', 'total_tax', 'discount_amount', 'discount_amount_tax')
		WHERE oi.order_item_type IN ('shipping', 'coupon') AND oi.order_id IN ({$in})
		ORDER BY oi.order_item_id"
	);
	// phpcs:enable

	$items = array();
	foreach ( $rows as $row ) {
		$id = (int) $row->order_item_id;
		if ( ! isset( $items[ $id ] ) ) {
			$items[ $id ] = array(
				'order_id' => (int) $row->order_id,
				'type'     => (string) $row->order_item_type,
				'name'     => trim( (string) $row->order_item_name ),
				'meta'     => array(),
			);
		}
		if ( null !== $row->meta_key ) {
			$items[ $id ]['meta'][ $row->meta_key ] = (float) $row->meta_value;
		}
	}

	foreach ( $items as $item ) {
		if ( ! isset( $orders[ $item['order_id'] ] ) ) {
			continue;
		}
		$m     = $item['meta'];
		$order = &$orders[ $item['order_id'] ];
		if ( 'shipping' === $item['type'] ) {
			$total               = ( $m['cost'] ?? 0 ) + ( $m['total_tax'] ?? 0 );
			$order['shipping'][] = array(
				'name'  => '' !== $item['name'] ? $item['name'] : 'משלוח',
				'total' => $total,
			);
			$order['ship_total'] += $total;
		} else {
			$discount           = ( $m['discount_amount'] ?? 0 ) + ( $m['discount_amount_tax'] ?? 0 );
			$order['coupons'][] = array(
				'code'     => $item['name'],
				'discount' => $discount,
			);
			$order['discount'] += $discount;
		}
		unset( $order );
	}
}

/**
 * Whether an order passes the shipping filter.
 *
 * @param array  $order Order.
 * @param string $ship  '' (any), '__none', '__free' or a method name.
 * @return bool
 */
function sella_sd_ship_passes( $order, $ship ) {
	if ( '' === $ship ) {
		return true;
	}
	if ( '__none' === $ship ) {
		return ! $order['shipping'];
	}
	if ( '__free' === $ship ) {
		return $order['shipping'] && $order['ship_total'] <= 0;
	}
	return in_array( $ship, array_column( $order['shipping'], 'name' ), true );
}

/**
 * Whether an order passes the coupon filter.
 *
 * @param array  $order  Order.
 * @param string $coupon '' (any), '__any', '__none' or a coupon code.
 * @return bool
 */
function sella_sd_coupon_passes( $order, $coupon ) {
	if ( '' === $coupon ) {
		return true;
	}
	if ( '__any' === $coupon ) {
		return (bool) $order['coupons'];
	}
	if ( '__none' === $coupon ) {
		return ! $order['coupons'];
	}
	$codes = array_map( 'wc_strtolower', array_column( $order['coupons'], 'code' ) );
	return in_array( wc_strtolower( $coupon ), $codes, true );
}

/**
 * What brought a line into the order, if an offer did.
 *
 * @param array $m Line meta.
 * @return array{type: string, key: string, name: string}|null
 */
function sella_sd_line_source( $m ) {
	if ( ! empty( $m['_sella_checkout_offer_id'] ) ) {
		return array( 'type' => 'checkout', 'key' => (string) absint( $m['_sella_checkout_offer_id'] ), 'name' => '' );
	}
	if ( ! empty( $m['_sella_upsell_source'] ) || ! empty( $m['_sella_upsell_popup_id'] ) ) {
		$popup = absint( $m['_sella_upsell_popup_id'] ?? 0 );
		$name  = (string) ( $m['_sella_upsell_source'] ?? '' );
		return array( 'type' => 'popup', 'key' => $popup ? (string) $popup : 'name:' . $name, 'name' => $name );
	}
	if ( ! empty( $m['_lets_after_sell'] ) ) {
		return array( 'type' => 'after_sell', 'key' => 'name:' . $m['_lets_after_sell'], 'name' => $m['_lets_after_sell'] );
	}
	if ( ! empty( $m['מבצע'] ) ) {
		return array( 'type' => 'deal', 'key' => 'name:' . $m['מבצע'], 'name' => $m['מבצע'] );
	}
	return null;
}

/**
 * Whether an order holds the chosen products.
 *
 * @param array  $order    Order.
 * @param int[]  $products Product IDs.
 * @param string $match    'any' or 'all'.
 * @return bool
 */
function sella_sd_has_products( $order, $products, $match ) {
	if ( ! $products ) {
		return true;
	}
	$have = array();
	foreach ( $order['lines'] as $line ) {
		$have[] = $line['product_id'];
		$have[] = $line['variation_id'];
	}
	$found = array_intersect( $products, $have );
	return 'all' === $match ? count( $found ) === count( $products ) : (bool) $found;
}

/**
 * The orders of the period that pass the orders tab's filters.
 *
 * @param array $state Screen state.
 * @return array<int, array>
 */
function sella_sd_filtered_orders( $state ) {
	$range = sella_sd_range( $state );
	$out   = array();

	foreach ( sella_sd_orders( $range['from'], $range['to'] ) as $id => $order ) {
		switch ( $state['what'] ) {
			case 'sub_books':
				$pass = $order['sub'] && $order['book'];
				break;
			case 'sub':
				$pass = $order['sub'];
				break;
			case 'books_only':
				$pass = $order['book'] && ! $order['sub'];
				break;
			case 'offers':
				$pass = $order['offer'];
				break;
			default:
				$pass = true;
		}
		if ( $pass
			&& sella_sd_has_products( $order, $state['products'], $state['match'] )
			&& sella_sd_ship_passes( $order, $state['ship'] )
			&& sella_sd_coupon_passes( $order, $state['coupon'] )
		) {
			$out[ $id ] = $order;
		}
	}

	return $out;
}

/**
 * ---------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------------
 */

/**
 * A sum of money, whole shekels.
 *
 * @param float $amount Amount.
 * @return string
 */
function sella_sd_money( $amount ) {
	return number_format_i18n( round( (float) $amount ), 0 ) . ' ' . html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
}

/**
 * @param float $part  Part.
 * @param float $whole Whole.
 * @return string
 */
function sella_sd_percent( $part, $whole ) {
	return $whole > 0 ? number_format_i18n( 100 * $part / $whole, 1 ) . '%' : '0%';
}

/**
 * The name of an offer, for the offers tab.
 *
 * @param string $type Offer type.
 * @param string $key  Offer key.
 * @param string $name Name kept on the line.
 * @return string
 */
function sella_sd_offer_name( $type, $key, $name ) {
	if ( 'checkout' === $type ) {
		$title = get_the_title( (int) $key );
		return '' !== $title ? $title : 'הצעה #' . $key . ' (נמחקה)';
	}
	if ( 'popup' === $type && ctype_digit( $key ) ) {
		$title = get_the_title( (int) $key );
		if ( '' !== $title ) {
			return $title;
		}
	}
	return '' !== $name ? $name : 'ללא שם';
}

/**
 * The address an order ships to, in parts. The shipping address when there
 * is one, else the billing one.
 *
 * @param WC_Order $order Order.
 * @return array<string, string>
 */
function sella_sd_address( $order ) {
	$type = '' !== trim( $order->get_shipping_address_1() . $order->get_shipping_city() ) ? 'shipping' : 'billing';
	$get  = function ( $field ) use ( $order, $type ) {
		$method = 'get_' . $type . '_' . $field;
		return trim( (string) $order->$method() );
	};
	$part = function ( $part ) use ( $order, $type ) {
		if ( function_exists( 'sella_order_address_part' ) ) {
			$value = sella_order_address_part( $order, $type, $part );
			if ( '' === $value && 'shipping' === $type ) {
				$value = sella_order_address_part( $order, 'billing', $part );
			}
			return $value;
		}
		return '';
	};

	return array(
		'street'    => $get( 'address_1' ),
		'building'  => $part( 'building_number' ),
		'apartment' => $part( 'apartment_number' ),
		'floor'     => $part( 'floor' ),
		'entrance'  => $part( 'entrance' ),
		'line2'     => $get( 'address_2' ),
		'city'      => $get( 'city' ),
		'postcode'  => $get( 'postcode' ),
	);
}

/**
 * The address as one line.
 *
 * @param array $a Address parts.
 * @return string
 */
function sella_sd_address_line( $a ) {
	$street = $a['street'];
	if ( '' !== $a['building'] && ! preg_match( '/(^|\s)' . preg_quote( $a['building'], '/' ) . '$/u', $street ) ) {
		$street = trim( $street . ' ' . $a['building'] );
	}
	$more = array();
	if ( '' !== $a['apartment'] ) {
		$more[] = 'דירה ' . $a['apartment'];
	}
	if ( '' !== $a['floor'] ) {
		$more[] = 'קומה ' . $a['floor'];
	}
	if ( '' !== $a['entrance'] ) {
		$more[] = 'כניסה ' . $a['entrance'];
	}
	if ( '' !== $a['line2'] ) {
		$more[] = $a['line2'];
	}
	return implode( ', ', array_filter( array( $street, implode( ', ', $more ), $a['city'] ) ) );
}

/**
 * ---------------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------------------
 */

/**
 * The screen.
 */
function sella_sd_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$state = sella_sd_state();
	$range = sella_sd_range( $state );
	?>
	<div class="wrap sella-sd" dir="rtl">
		<h1 class="sella-sd__title">דשבורד מכירות</h1>

		<nav class="sella-sd__tabs" aria-label="חלקי הדשבורד">
			<?php foreach ( sella_sd_tabs() as $key => $label ) : ?>
				<a class="sella-sd__tab<?php echo $key === $state['tab'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( sella_sd_url( array( 'tab' => $key ) ) ); ?>"<?php echo $key === $state['tab'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php sella_sd_render_period_bar( $state, $range ); ?>

		<?php
		if ( 'offers' === $state['tab'] ) {
			sella_sd_render_offers( $range );
		} elseif ( 'shipping' === $state['tab'] ) {
			sella_sd_render_shipping( $range );
		} elseif ( 'coupons' === $state['tab'] ) {
			sella_sd_render_coupons( $range );
		} elseif ( 'orders' === $state['tab'] ) {
			sella_sd_render_orders( $state, $range );
		} else {
			sella_sd_render_overview( $range );
		}
		?>
	</div>
	<?php
}

/**
 * The period buttons and the "other dates" form.
 *
 * @param array $state Screen state.
 * @param array $range Dates.
 */
function sella_sd_render_period_bar( $state, $range ) {
	?>
	<div class="sella-sd__period">
		<div class="sella-sd__period-buttons" role="group" aria-label="תקופה">
			<?php foreach ( sella_sd_periods() as $key => $label ) : ?>
				<a class="sella-sd__chip<?php echo $key === $state['period'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( sella_sd_url( array( 'period' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>
		<form class="sella-sd__custom<?php echo 'custom' === $state['period'] ? ' is-active' : ''; ?>" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( SELLA_SD_SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $state['tab'] ); ?>" />
			<input type="hidden" name="period" value="custom" />
			<?php sella_sd_hidden_filters( $state ); ?>
			<label>מתאריך <input type="date" name="from" value="<?php echo esc_attr( $range['from'] ); ?>" required /></label>
			<label>עד תאריך <input type="date" name="to" value="<?php echo esc_attr( $range['to'] ); ?>" required /></label>
			<button type="submit" class="button">הצגה</button>
		</form>
	</div>
	<p class="sella-sd__range">מוצגים נתונים לתאריכים <strong><?php echo esc_html( $range['label'] ); ?></strong> · הזמנות ששולמו בלבד</p>
	<?php
}

/**
 * The orders tab's filters as hidden fields, so changing dates keeps them.
 *
 * @param array $state Screen state.
 */
function sella_sd_hidden_filters( $state ) {
	if ( 'orders' !== $state['tab'] ) {
		return;
	}
	if ( 'all' !== $state['what'] ) {
		echo '<input type="hidden" name="what" value="' . esc_attr( $state['what'] ) . '" />';
	}
	foreach ( $state['products'] as $id ) {
		echo '<input type="hidden" name="products[]" value="' . esc_attr( (string) $id ) . '" />';
	}
	if ( $state['products'] ) {
		echo '<input type="hidden" name="match" value="' . esc_attr( $state['match'] ) . '" />';
	}
	foreach ( array( 'ship', 'coupon' ) as $key ) {
		if ( '' !== $state[ $key ] ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $state[ $key ] ) . '" />';
		}
	}
}

/**
 * A row of number cards.
 *
 * @param array $cards Each: label, value, note (optional), tone (optional).
 */
function sella_sd_render_cards( $cards ) {
	echo '<div class="sella-sd__cards">';
	foreach ( $cards as $card ) {
		printf(
			'<div class="sella-sd__card%s"><div class="sella-sd__card-label">%s</div><div class="sella-sd__card-value">%s</div>%s</div>',
			! empty( $card['tone'] ) ? ' is-' . esc_attr( $card['tone'] ) : '',
			esc_html( $card['label'] ),
			esc_html( $card['value'] ),
			! empty( $card['note'] ) ? '<div class="sella-sd__card-note">' . esc_html( $card['note'] ) . '</div>' : ''
		);
	}
	echo '</div>';
}

/**
 * ---------------------------------------------------------------------------
 * Tab: overview
 * ---------------------------------------------------------------------------
 */

/**
 * @param array $range Dates.
 */
function sella_sd_render_overview( $range ) {
	$orders = sella_sd_orders( $range['from'], $range['to'] );

	$revenue   = 0.0;
	$books     = 0;
	$subs      = 0;
	$both      = 0;
	$offer_sum = 0.0;
	$top       = array();
	$sub_kinds = array();

	$shipping = 0.0;
	$discount = 0.0;
	$coupons  = 0;

	foreach ( $orders as $order ) {
		$revenue  += $order['total'];
		$shipping += $order['ship_total'];
		$discount += $order['discount'];
		if ( $order['coupons'] ) {
			++$coupons;
		}
		if ( $order['sub'] && $order['book'] ) {
			++$both;
		}
		foreach ( $order['lines'] as $line ) {
			if ( $line['source'] ) {
				$offer_sum += $line['total'];
			}
			$key = $line['product_id'] ? $line['product_id'] : 'name:' . $line['name'];
			if ( $line['sub'] ) {
				$subs += $line['qty'];
				if ( ! isset( $sub_kinds[ $key ] ) ) {
					$sub_kinds[ $key ] = array( 'name' => $line['name'], 'qty' => 0, 'total' => 0.0 );
				}
				$sub_kinds[ $key ]['qty']   += $line['qty'];
				$sub_kinds[ $key ]['total'] += $line['total'];
				continue;
			}
			$books += $line['qty'];
			if ( ! isset( $top[ $key ] ) ) {
				$top[ $key ] = array( 'name' => $line['name'], 'qty' => 0, 'total' => 0.0 );
			}
			$top[ $key ]['qty']   += $line['qty'];
			$top[ $key ]['total'] += $line['total'];
		}
	}

	$count = count( $orders );

	sella_sd_render_cards(
		array(
			array( 'label' => 'סך המכירות', 'value' => sella_sd_money( $revenue ), 'tone' => 'main' ),
			array( 'label' => 'הזמנות', 'value' => number_format_i18n( $count ), 'note' => $count ? 'ממוצע להזמנה ' . sella_sd_money( $revenue / $count ) : '' ),
			array( 'label' => 'ספרים שנמכרו', 'value' => number_format_i18n( $books ) ),
			array( 'label' => 'מנויים חדשים', 'value' => number_format_i18n( $subs ), 'note' => $both ? number_format_i18n( $both ) . ' מהם קנו גם ספרים' : '' ),
			array( 'label' => 'הכניסו ההצעות והפופאפים', 'value' => sella_sd_money( $offer_sum ), 'note' => sella_sd_percent( $offer_sum, $revenue ) . ' מכלל המכירות' ),
			array( 'label' => 'דמי משלוח ששולמו', 'value' => sella_sd_money( $shipping ), 'note' => 'מתוך סך המכירות' ),
			array( 'label' => 'הנחות בקופונים', 'value' => sella_sd_money( $discount ), 'note' => $coupons ? number_format_i18n( $coupons ) . ' הזמנות עם קופון' : 'לא הופעלו קופונים' ),
		)
	);

	if ( ! $count ) {
		echo '<div class="sella-sd__empty">אין הזמנות ששולמו בתאריכים האלה.</div>';
		return;
	}

	sella_sd_render_chart( $orders, $range );

	uasort(
		$top,
		function ( $a, $b ) {
			return $b['qty'] <=> $a['qty'] ?: $b['total'] <=> $a['total'];
		}
	);
	uasort(
		$sub_kinds,
		function ( $a, $b ) {
			return $b['qty'] <=> $a['qty'];
		}
	);
	?>
	<div class="sella-sd__grid">
		<section class="sella-sd__panel">
			<h2>הספרים הנמכרים ביותר</h2>
			<?php if ( $top ) : ?>
				<table class="sella-sd__table">
					<thead><tr><th>ספר</th><th class="num">עותקים</th><th class="num">מכירות</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $top, 0, 10, true ) as $row ) : ?>
						<tr><td><?php echo esc_html( $row['name'] ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['qty'] ) ); ?></td><td class="num"><?php echo esc_html( sella_sd_money( $row['total'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="sella-sd__muted">לא נמכרו ספרים בתאריכים האלה.</p>
			<?php endif; ?>
		</section>

		<section class="sella-sd__panel">
			<h2>מנויים שנרכשו</h2>
			<?php if ( $sub_kinds ) : ?>
				<table class="sella-sd__table">
					<thead><tr><th>מסלול</th><th class="num">מנויים</th><th class="num">מכירות</th></tr></thead>
					<tbody>
					<?php foreach ( $sub_kinds as $row ) : ?>
						<tr><td><?php echo esc_html( $row['name'] ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['qty'] ) ); ?></td><td class="num"><?php echo esc_html( sella_sd_money( $row['total'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $both ) : ?>
					<p class="sella-sd__link-row"><a href="<?php echo esc_url( sella_sd_url( array( 'tab' => 'orders', 'what' => 'sub_books' ) ) ); ?>">לרשימת <?php echo esc_html( number_format_i18n( $both ) ); ?> ההזמנות של מנוי + ספרים ←</a></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="sella-sd__muted">לא נרכשו מנויים בתאריכים האלה.</p>
			<?php endif; ?>
		</section>
	</div>
	<?php
}

/**
 * Sales per day, or per month when the period is longer than two months.
 *
 * @param array $orders Orders.
 * @param array $range  Dates.
 */
function sella_sd_render_chart( $orders, $range ) {
	$tz    = wp_timezone();
	$start = new DateTimeImmutable( $range['from'], $tz );
	$end   = new DateTimeImmutable( $range['to'], $tz );
	$days  = (int) $start->diff( $end )->days + 1;

	if ( $days < 2 ) {
		return;
	}

	$monthly = $days > 62;
	$buckets = array();
	$cursor  = $monthly ? $start->modify( 'first day of this month' ) : $start;
	while ( $cursor <= $end ) {
		$key             = $cursor->format( $monthly ? 'Y-m' : 'Y-m-d' );
		$buckets[ $key ] = array(
			'label'  => $cursor->format( $monthly ? 'n/Y' : 'j.n' ),
			'total'  => 0.0,
			'orders' => 0,
		);
		$cursor = $cursor->modify( $monthly ? '+1 month' : '+1 day' );
	}

	foreach ( $orders as $order ) {
		$key = $monthly ? substr( $order['date'], 0, 7 ) : $order['date'];
		if ( isset( $buckets[ $key ] ) ) {
			$buckets[ $key ]['total'] += $order['total'];
			++$buckets[ $key ]['orders'];
		}
	}

	$max   = max( 1, max( array_column( $buckets, 'total' ) ) );
	$every = (int) ceil( count( $buckets ) / 12 );
	$i     = 0;
	?>
	<section class="sella-sd__panel">
		<h2><?php echo $monthly ? 'מכירות לפי חודש' : 'מכירות לפי יום'; ?></h2>
		<div class="sella-sd__chart" role="img" aria-label="<?php echo esc_attr( $monthly ? 'גרף מכירות לפי חודש' : 'גרף מכירות לפי יום' ); ?>">
			<div class="sella-sd__chart-max"><?php echo esc_html( sella_sd_money( $max ) ); ?></div>
			<div class="sella-sd__bars">
				<?php foreach ( $buckets as $bucket ) : ?>
					<?php $tip = $bucket['label'] . ' · ' . sella_sd_money( $bucket['total'] ) . ' · ' . $bucket['orders'] . ' הזמנות'; ?>
					<div class="sella-sd__bar-slot" tabindex="0" data-tip="<?php echo esc_attr( $tip ); ?>">
						<div class="sella-sd__bar" style="height:<?php echo esc_attr( (string) round( 100 * $bucket['total'] / $max, 2 ) ); ?>%"></div>
						<span class="sella-sd__bar-label"><?php echo 0 === $i++ % $every ? esc_html( $bucket['label'] ) : ''; ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<details class="sella-sd__as-table">
			<summary>הצגה כטבלה</summary>
			<table class="sella-sd__table">
				<thead><tr><th><?php echo $monthly ? 'חודש' : 'יום'; ?></th><th class="num">הזמנות</th><th class="num">מכירות</th></tr></thead>
				<tbody>
				<?php foreach ( $buckets as $bucket ) : ?>
					<tr><td><?php echo esc_html( $bucket['label'] ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $bucket['orders'] ) ); ?></td><td class="num"><?php echo esc_html( sella_sd_money( $bucket['total'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	</section>
	<?php
}

/**
 * ---------------------------------------------------------------------------
 * Tab: offers
 * ---------------------------------------------------------------------------
 */

/**
 * @param array $range Dates.
 */
function sella_sd_render_offers( $range ) {
	$orders  = sella_sd_orders( $range['from'], $range['to'] );
	$types   = sella_sd_offer_types();
	$report  = array_fill_keys( array_keys( $types ), array() );
	$revenue = 0.0;

	// Published popups and checkout offers, so one that sold nothing shows as 0.
	$live = array(
		'popup'    => defined( 'SELLA_UPSELL_CPT' ) ? SELLA_UPSELL_CPT : '',
		'checkout' => defined( 'SELLA_OFFER_CPT' ) ? SELLA_OFFER_CPT : '',
	);
	foreach ( $live as $type => $post_type ) {
		if ( '' === $post_type ) {
			continue;
		}
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		foreach ( $posts as $post_id ) {
			$report[ $type ][ (string) $post_id ] = array( 'name' => sella_sd_offer_name( $type, (string) $post_id, '' ), 'orders' => array(), 'qty' => 0, 'total' => 0.0 );
		}
	}

	foreach ( $orders as $order ) {
		$revenue += $order['total'];
		foreach ( $order['lines'] as $line ) {
			$s = $line['source'];
			if ( ! $s ) {
				continue;
			}
			if ( ! isset( $report[ $s['type'] ][ $s['key'] ] ) ) {
				$report[ $s['type'] ][ $s['key'] ] = array( 'name' => sella_sd_offer_name( $s['type'], $s['key'], $s['name'] ), 'orders' => array(), 'qty' => 0, 'total' => 0.0 );
			}
			$row                       = &$report[ $s['type'] ][ $s['key'] ];
			$row['orders'][ $order['id'] ] = true;
			$row['qty']               += $line['qty'];
			$row['total']             += $line['total'];
			unset( $row );
		}
	}

	$cards = array();
	$all   = 0.0;
	foreach ( $types as $type => $info ) {
		$sum = array_sum( array_column( $report[ $type ], 'total' ) );
		$all += $sum;
		$cards[] = array( 'label' => $info['title'], 'value' => sella_sd_money( $sum ), 'note' => sella_sd_percent( $sum, $revenue ) . ' מכלל המכירות' );
	}
	array_unshift( $cards, array( 'label' => 'סך הכל מכל ההצעות', 'value' => sella_sd_money( $all ), 'note' => sella_sd_percent( $all, $revenue ) . ' מתוך ' . sella_sd_money( $revenue ), 'tone' => 'main' ) );

	sella_sd_render_cards( $cards );
	?>
	<p class="sella-sd__explain">כל מוצר שנוסף להזמנה מהצעה נספר פעם אחת, לפי מה שהלקוח שילם עליו בפועל. הספירה מתחילה מהיום שבו התחילו לסמן את ההזמנות לכל סוג הצעה.</p>
	<?php
	foreach ( $types as $type => $info ) {
		$rows = $report[ $type ];
		uasort(
			$rows,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		$sum = array_sum( array_column( $rows, 'total' ) );
		?>
		<section class="sella-sd__panel">
			<div class="sella-sd__panel-head">
				<h2><?php echo esc_html( $info['title'] ); ?></h2>
				<span class="sella-sd__muted"><?php echo esc_html( $info['hint'] ); ?></span>
			</div>
			<?php if ( $rows ) : ?>
				<table class="sella-sd__table">
					<thead><tr><th>הצעה</th><th class="num">הזמנות</th><th class="num">מוצרים שנמכרו</th><th class="num">הכניסה</th><th class="sella-sd__share-col">חלק מההצעות מסוג זה</th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php $share = $sum > 0 ? 100 * $row['total'] / $sum : 0; ?>
						<tr class="<?php echo $row['total'] > 0 ? '' : 'is-zero'; ?>">
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( count( $row['orders'] ) ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['qty'] ) ); ?></td>
							<td class="num"><strong><?php echo esc_html( sella_sd_money( $row['total'] ) ); ?></strong></td>
							<td class="sella-sd__share-col"><span class="sella-sd__meter"><span style="width:<?php echo esc_attr( (string) round( $share, 1 ) ); ?>%"></span></span> <?php echo esc_html( number_format_i18n( $share, 0 ) ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="sella-sd__muted">אין מכירות מהצעות מהסוג הזה בתאריכים האלה.</p>
			<?php endif; ?>
		</section>
		<?php
	}
}

/**
 * ---------------------------------------------------------------------------
 * Tab: orders and customers
 * ---------------------------------------------------------------------------
 */

/**
 * @param array $state Screen state.
 * @param array $range Dates.
 */
function sella_sd_render_orders( $state, $range ) {
	$orders = sella_sd_filtered_orders( $state );
	$total  = array_sum( array_column( $orders, 'total' ) );
	$count  = count( $orders );
	$pages  = max( 1, (int) ceil( $count / SELLA_SD_PER_PAGE ) );
	$paged  = min( $state['paged'], $pages );
	$shown  = array_slice( $orders, ( $paged - 1 ) * SELLA_SD_PER_PAGE, SELLA_SD_PER_PAGE, true );

	// The shipping methods and coupons of the period, for the two selects.
	$methods = array();
	$codes   = array();
	foreach ( sella_sd_orders( $range['from'], $range['to'] ) as $order ) {
		foreach ( $order['shipping'] as $line ) {
			$methods[ $line['name'] ] = true;
		}
		foreach ( $order['coupons'] as $line ) {
			$codes[ wc_strtolower( $line['code'] ) ] = true;
		}
	}
	// A chosen one stays in the list even when the period has none of it.
	if ( '' !== $state['ship'] && '__' !== substr( $state['ship'], 0, 2 ) ) {
		$methods[ $state['ship'] ] = true;
	}
	if ( '' !== $state['coupon'] && '__' !== substr( $state['coupon'], 0, 2 ) ) {
		$codes[ wc_strtolower( $state['coupon'] ) ] = true;
	}
	ksort( $methods );
	ksort( $codes );

	$ship_options = array(
		''       => 'כל שיטות המשלוח',
		'__free' => 'משלוח חינם (0 ₪)',
		'__none' => 'בלי משלוח',
	);
	foreach ( array_keys( $methods ) as $name ) {
		$ship_options[ $name ] = $name;
	}
	$coupon_options = array(
		''       => 'עם קופון או בלי',
		'__any'  => 'רק הזמנות עם קופון',
		'__none' => 'רק הזמנות בלי קופון',
	);
	foreach ( array_keys( $codes ) as $code ) {
		$coupon_options[ $code ] = 'קופון: ' . $code;
	}
	?>
	<form id="sella-sd-filters" class="sella-sd__filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( SELLA_SD_SLUG ); ?>" />
		<?php wp_nonce_field( 'sella_sd_export', 'sd_nonce', false ); ?>
		<input type="hidden" name="tab" value="orders" />
		<input type="hidden" name="period" value="<?php echo esc_attr( $state['period'] ); ?>" />
		<?php if ( 'custom' === $state['period'] ) : ?>
			<input type="hidden" name="from" value="<?php echo esc_attr( $range['from'] ); ?>" />
			<input type="hidden" name="to" value="<?php echo esc_attr( $range['to'] ); ?>" />
		<?php endif; ?>

		<div class="sella-sd__filter">
			<div class="sella-sd__filter-title">1. מה נרכש בהזמנה?</div>
			<div class="sella-sd__radios">
				<?php foreach ( sella_sd_what_options() as $key => $label ) : ?>
					<label class="sella-sd__radio"><input type="radio" name="what" value="<?php echo esc_attr( $key ); ?>" <?php checked( $state['what'], $key ); ?> /> <span><?php echo esc_html( $label ); ?></span></label>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="sella-sd__filter">
			<div class="sella-sd__filter-title">2. ספרים או מוצרים מסוימים <span class="sella-sd__muted">(לא חובה)</span></div>
			<select class="wc-product-search sella-sd__products" multiple="multiple" name="products[]" data-placeholder="הקלידו שם של ספר..." data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
				<?php foreach ( $state['products'] as $id ) : ?>
					<?php $product = wc_get_product( $id ); ?>
					<?php if ( $product ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<div class="sella-sd__radios is-small">
				<label class="sella-sd__radio"><input type="radio" name="match" value="any" <?php checked( $state['match'], 'any' ); ?> /> <span>הזמנות עם לפחות אחד מהם</span></label>
				<label class="sella-sd__radio"><input type="radio" name="match" value="all" <?php checked( $state['match'], 'all' ); ?> /> <span>הזמנות עם כולם יחד</span></label>
			</div>
		</div>

		<div class="sella-sd__filter">
			<div class="sella-sd__filter-title">3. משלוח וקופון <span class="sella-sd__muted">(לא חובה)</span></div>
			<div class="sella-sd__selects">
				<label>שיטת משלוח
					<select name="ship">
						<?php foreach ( $ship_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $state['ship'], (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>קופון
					<select name="coupon">
						<?php foreach ( $coupon_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( wc_strtolower( $state['coupon'] ), (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</div>

		<div class="sella-sd__filter-actions">
			<button type="submit" class="button button-primary button-hero">הצגת ההזמנות</button>
			<?php if ( 'all' !== $state['what'] || $state['products'] || '' !== $state['ship'] || '' !== $state['coupon'] ) : ?>
				<a class="sella-sd__reset" href="<?php echo esc_url( sella_sd_url( array( 'what' => 'all', 'products' => array(), 'ship' => '', 'coupon' => '' ) ) ); ?>">ניקוי הסינון</a>
			<?php endif; ?>
		</div>
	</form>

	<div class="sella-sd__result-bar">
		<div class="sella-sd__result-count">
			נמצאו <strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> הזמנות
			<?php if ( $count ) : ?>
				· סך הכל <strong><?php echo esc_html( sella_sd_money( $total ) ); ?></strong>
			<?php endif; ?>
		</div>
		<?php if ( $count ) : ?>
			<?php
			/*
			 * Submits the filter form itself, to the export: the file holds the
			 * orders of the filters as they are in the form now, even ones
			 * picked after the list was last shown.
			 */
			?>
			<div class="sella-sd__export-wrap">
				<button type="submit" form="sella-sd-filters" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" name="action" value="sella_sd_export" class="button button-primary sella-sd__export"><span class="dashicons dashicons-download" aria-hidden="true"></span> ייצוא ההזמנות המסוננות לאקסל</button>
				<span class="sella-sd__muted">הקובץ כולל רק את ההזמנות שמתאימות לסינון שבחרתם</span>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! $count ) : ?>
		<div class="sella-sd__empty">אין הזמנות שמתאימות לסינון בתאריכים האלה.</div>
		<?php
		return;
	endif;
	?>

	<div class="sella-sd__table-wrap">
		<table class="sella-sd__table sella-sd__orders">
			<thead>
				<tr><th>הזמנה</th><th>לקוח</th><th>כתובת למשלוח</th><th>מה נרכש</th><th class="num">סכום</th></tr>
			</thead>
			<tbody>
			<?php foreach ( $shown as $id => $data ) : ?>
				<?php
				$order = wc_get_order( $id );
				if ( ! $order ) {
					continue;
				}
				$date = $order->get_date_created();
				?>
				<tr>
					<td>
						<a class="sella-sd__order-link" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
						<div class="sella-sd__muted"><?php echo esc_html( $date ? $date->date_i18n( 'j.n.Y' ) : '' ); ?></div>
					</td>
					<td>
						<strong><?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?></strong>
						<div class="sella-sd__muted" dir="ltr"><?php echo esc_html( $order->get_billing_phone() ); ?></div>
						<div class="sella-sd__muted" dir="ltr"><?php echo esc_html( $order->get_billing_email() ); ?></div>
					</td>
					<td><?php echo esc_html( sella_sd_address_line( sella_sd_address( $order ) ) ); ?></td>
					<td>
						<?php foreach ( $data['lines'] as $line ) : ?>
							<span class="sella-sd__item<?php echo $line['sub'] ? ' is-sub' : ''; ?><?php echo $line['source'] ? ' is-offer' : ''; ?>"<?php echo $line['source'] ? ' title="' . esc_attr( 'נוסף מהצעה: ' . sella_sd_offer_types()[ $line['source']['type'] ]['short'] ) . '"' : ''; ?>><?php echo esc_html( $line['name'] . ( $line['qty'] > 1 ? ' (' . $line['qty'] . ')' : '' ) ); ?></span>
						<?php endforeach; ?>
					</td>
					<td class="num">
						<strong><?php echo esc_html( sella_sd_money( $data['total'] ) ); ?></strong>
						<?php if ( $data['shipping'] ) : ?>
							<div class="sella-sd__muted"><?php echo esc_html( $data['ship_total'] > 0 ? 'משלוח ' . sella_sd_money( $data['ship_total'] ) : 'משלוח חינם' ); ?></div>
						<?php endif; ?>
						<?php foreach ( $data['coupons'] as $coupon ) : ?>
							<div class="sella-sd__coupon-note"><span dir="ltr"><?php echo esc_html( $coupon['code'] ); ?></span> −<?php echo esc_html( sella_sd_money( $coupon['discount'] ) ); ?></div>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="sella-sd__legend"><span class="sella-sd__item is-sub">מנוי</span> <span class="sella-sd__item">ספר</span> <span class="sella-sd__item is-offer">נוסף מהצעה</span></p>

	<?php if ( $pages > 1 ) : ?>
		<nav class="sella-sd__pages" aria-label="עמודים">
			<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
				<a class="sella-sd__chip<?php echo $p === $paged ? ' is-active' : ''; ?>" href="<?php echo esc_url( sella_sd_url( array( 'paged' => $p ) ) ); ?>"><?php echo esc_html( (string) $p ); ?></a>
			<?php endfor; ?>
		</nav>
	<?php endif; ?>
	<?php
}

/**
 * ---------------------------------------------------------------------------
 * Tab: shipping
 * ---------------------------------------------------------------------------
 */

/**
 * What customers paid for shipping, per method.
 *
 * @param array $range Dates.
 */
function sella_sd_render_shipping( $range ) {
	$orders  = sella_sd_orders( $range['from'], $range['to'] );
	$methods = array();
	$total   = 0.0;
	$paid    = 0;
	$free    = 0;
	$none    = 0;

	foreach ( $orders as $order ) {
		if ( ! $order['shipping'] ) {
			++$none;
			continue;
		}
		$total += $order['ship_total'];
		if ( $order['ship_total'] > 0 ) {
			++$paid;
		} else {
			++$free;
		}
		foreach ( $order['shipping'] as $line ) {
			if ( ! isset( $methods[ $line['name'] ] ) ) {
				$methods[ $line['name'] ] = array( 'orders' => 0, 'paid' => 0, 'free' => 0, 'total' => 0.0 );
			}
			$row = &$methods[ $line['name'] ];
			++$row['orders'];
			$row['total'] += $line['total'];
			if ( $line['total'] > 0 ) {
				++$row['paid'];
			} else {
				++$row['free'];
			}
			unset( $row );
		}
	}

	uasort(
		$methods,
		function ( $a, $b ) {
			return $b['total'] <=> $a['total'] ?: $b['orders'] <=> $a['orders'];
		}
	);

	sella_sd_render_cards(
		array(
			array( 'label' => 'סך דמי המשלוח ששולמו', 'value' => sella_sd_money( $total ), 'tone' => 'main', 'note' => count( $orders ) ? 'ממוצע ' . sella_sd_money( $paid ? $total / $paid : 0 ) . ' להזמנה עם משלוח בתשלום' : '' ),
			array( 'label' => 'הזמנות עם משלוח בתשלום', 'value' => number_format_i18n( $paid ) ),
			array( 'label' => 'הזמנות עם משלוח חינם', 'value' => number_format_i18n( $free ) ),
			array( 'label' => 'הזמנות בלי משלוח', 'value' => number_format_i18n( $none ), 'note' => 'למשל מנוי בלבד' ),
		)
	);
	?>
	<p class="sella-sd__explain">הסכומים הם דמי המשלוח שהלקוחות שילמו באתר, כולל מע״מ. מה שהמשלוח עלה לכם מול חברת השליחויות לא נשמר באתר.</p>

	<section class="sella-sd__panel">
		<h2>לפי שיטת משלוח</h2>
		<?php if ( $methods ) : ?>
			<table class="sella-sd__table">
				<thead><tr><th>שיטת משלוח</th><th class="num">הזמנות</th><th class="num">בתשלום</th><th class="num">חינם</th><th class="num">שולם על משלוח</th><th class="num">ממוצע</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $methods as $name => $row ) : ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $row['paid'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $row['free'] ) ); ?></td>
						<td class="num"><strong><?php echo esc_html( sella_sd_money( $row['total'] ) ); ?></strong></td>
						<td class="num"><?php echo esc_html( sella_sd_money( $row['paid'] ? $row['total'] / $row['paid'] : 0 ) ); ?></td>
						<td class="num"><a href="<?php echo esc_url( sella_sd_url( array( 'tab' => 'orders', 'what' => 'all', 'products' => array(), 'coupon' => '', 'ship' => (string) $name ) ) ); ?>">להזמנות ←</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="sella-sd__muted">אין הזמנות עם משלוח בתאריכים האלה.</p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * ---------------------------------------------------------------------------
 * Tab: coupons
 * ---------------------------------------------------------------------------
 */

/**
 * Which coupons were used, how often, when and for how much.
 *
 * @param array $range Dates.
 */
function sella_sd_render_coupons( $range ) {
	$orders  = sella_sd_orders( $range['from'], $range['to'] );
	$coupons = array();
	$revenue = 0.0;
	$with    = 0;

	foreach ( $orders as $order ) {
		$revenue += $order['total'];
		if ( ! $order['coupons'] ) {
			continue;
		}
		++$with;
		foreach ( $order['coupons'] as $line ) {
			$code = wc_strtolower( $line['code'] );
			if ( ! isset( $coupons[ $code ] ) ) {
				$coupons[ $code ] = array( 'uses' => array(), 'discount' => 0.0, 'sales' => 0.0, 'first' => $order['date'], 'last' => $order['date'] );
			}
			$row               = &$coupons[ $code ];
			$row['uses'][]     = array( 'id' => $order['id'], 'discount' => $line['discount'], 'total' => $order['total'] );
			$row['discount']  += $line['discount'];
			$row['sales']     += $order['total'];
			$row['first']      = min( $row['first'], $order['date'] );
			$row['last']       = max( $row['last'], $order['date'] );
			unset( $row );
		}
	}

	uasort(
		$coupons,
		function ( $a, $b ) {
			return count( $b['uses'] ) <=> count( $a['uses'] ) ?: $b['discount'] <=> $a['discount'];
		}
	);

	$discount = array_sum( array_column( $coupons, 'discount' ) );
	$sales    = 0.0;
	foreach ( $orders as $order ) {
		if ( $order['coupons'] ) {
			$sales += $order['total'];
		}
	}
	$day = function ( $date ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $d ? $d->format( 'j.n.Y' ) : $date;
	};

	sella_sd_render_cards(
		array(
			array( 'label' => 'סך ההנחות בקופונים', 'value' => sella_sd_money( $discount ), 'tone' => 'main' ),
			array( 'label' => 'הזמנות עם קופון', 'value' => number_format_i18n( $with ), 'note' => sella_sd_percent( $with, count( $orders ) ) . ' מכלל ההזמנות' ),
			array( 'label' => 'מכירות בהזמנות עם קופון', 'value' => sella_sd_money( $sales ), 'note' => sella_sd_percent( $sales, $revenue ) . ' מכלל המכירות' ),
			array( 'label' => 'קופונים שונים שהופעלו', 'value' => number_format_i18n( count( $coupons ) ) ),
		)
	);

	if ( ! $coupons ) {
		echo '<div class="sella-sd__empty">לא הופעלו קופונים בתאריכים האלה.</div>';
		return;
	}
	?>
	<section class="sella-sd__panel">
		<h2>הקופונים שהופעלו</h2>
		<table class="sella-sd__table">
			<thead><tr><th>קופון</th><th class="num">שימושים</th><th class="num">סך ההנחה</th><th class="num">סכום ההזמנות</th><th class="num">שימוש ראשון</th><th class="num">שימוש אחרון</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $coupons as $code => $row ) : ?>
				<tr>
					<td><strong dir="ltr"><?php echo esc_html( (string) $code ); ?></strong></td>
					<td class="num"><?php echo esc_html( number_format_i18n( count( $row['uses'] ) ) ); ?></td>
					<td class="num"><strong><?php echo esc_html( sella_sd_money( $row['discount'] ) ); ?></strong></td>
					<td class="num"><?php echo esc_html( sella_sd_money( $row['sales'] ) ); ?></td>
					<td class="num"><?php echo esc_html( $day( $row['first'] ) ); ?></td>
					<td class="num"><?php echo esc_html( $day( $row['last'] ) ); ?></td>
					<td class="num"><a href="<?php echo esc_url( sella_sd_url( array( 'tab' => 'orders', 'what' => 'all', 'products' => array(), 'ship' => '', 'coupon' => (string) $code ) ) ); ?>">להזמנות ←</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="sella-sd__panel">
		<h2>מתי ובאיזה סכום — כל שימוש בכל קופון</h2>
		<?php foreach ( $coupons as $code => $row ) : ?>
			<details class="sella-sd__coupon">
				<summary><strong dir="ltr"><?php echo esc_html( (string) $code ); ?></strong> · <?php echo esc_html( number_format_i18n( count( $row['uses'] ) ) ); ?> שימושים · הנחה של <?php echo esc_html( sella_sd_money( $row['discount'] ) ); ?></summary>
				<table class="sella-sd__table">
					<thead><tr><th>תאריך ושעה</th><th>הזמנה</th><th>לקוח</th><th class="num">הנחה</th><th class="num">סכום ההזמנה</th></tr></thead>
					<tbody>
					<?php foreach ( $row['uses'] as $use ) : ?>
						<?php
						$order = wc_get_order( $use['id'] );
						if ( ! $order ) {
							continue;
						}
						$date = $order->get_date_created();
						?>
						<tr>
							<td><?php echo esc_html( $date ? $date->date_i18n( 'j.n.Y H:i' ) : '' ); ?></td>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
							<td><?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?></td>
							<td class="num"><?php echo esc_html( sella_sd_money( $use['discount'] ) ); ?></td>
							<td class="num"><?php echo esc_html( sella_sd_money( $use['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endforeach; ?>
	</section>
	<?php
}

/**
 * ---------------------------------------------------------------------------
 * Export: the courier's sheet of LETS (app.lets.co.il, GiftListExporter)
 * ---------------------------------------------------------------------------
 *
 * The same columns, in the same order and with the same headers, as the gift
 * list LETS exports: first name, last name, phone, city, street, house,
 * entrance, apartment, floor, the note to write on the delivery. Then, only
 * when the file has a subscription in it, LETS's two membership columns (the
 * date they joined, which is the order's date here, and the subscription).
 * Last, the books of the order.
 *
 * The address is split as LETS splits it (GiftShippingAddress::splitLines):
 * the house, apartment, floor and entrance fields of the checkout when the
 * order has them, else the number at the end of the street and the labelled
 * parts of the second line; what is left of that line goes into the note,
 * before the customer's note. Cells are guarded against formulas as LETS
 * guards them (CsvCell): a number such as a phone is left as it is.
 */

/** A house number at the end of the street: "הרצל 12", "הרצל 12א", "הרצל 12/3". */
define( 'SELLA_SD_TRAILING_BUILDING', '/^(.*\S)\s+(\d+[א-תa-zA-Z]?(?:\/\d+)?)$/u' );

/**
 * The labels of the parts in a second address line.
 *
 * @return array<string, string>
 */
function sella_sd_address2_labels() {
	return array(
		'apartment' => 'דירה|apt\.?|apartment',
		'floor'     => 'קומה|floor',
		'entrance'  => 'כניסה|entrance',
	);
}

/**
 * One order's courier columns, in LETS's order.
 *
 * @param WC_Order $order Order.
 * @return string[]
 */
function sella_sd_courier_fields( $order ) {
	$type = '' !== trim( $order->get_shipping_address_1() . $order->get_shipping_city() ) ? 'shipping' : 'billing';
	$get  = function ( $field ) use ( $order, $type ) {
		$method = 'get_' . $type . '_' . $field;
		return is_callable( array( $order, $method ) ) ? trim( (string) $order->$method() ) : '';
	};
	$a = sella_sd_address( $order );

	// Name: the block's two fields, else the billing ones, else one name split.
	$first = $get( 'first_name' );
	$last  = $get( 'last_name' );
	if ( '' === $first . $last ) {
		$first = trim( $order->get_billing_first_name() );
		$last  = trim( $order->get_billing_last_name() );
	}
	if ( '' === $last ) {
		$parts = preg_split( '/\s+/u', $first, 2 );
		$first = (string) ( $parts[0] ?? '' );
		$last  = (string) ( $parts[1] ?? '' );
	}

	$phone = $get( 'phone' );
	if ( '' === $phone ) {
		$phone = trim( $order->get_billing_phone() );
	}

	// Street and house.
	$street   = $a['street'];
	$building = $a['building'];
	if ( '' !== $building ) {
		$suffix = ' ' . $building;
		if ( $street !== $building && substr( $street, -strlen( $suffix ) ) === $suffix ) {
			$street = substr( $street, 0, -strlen( $suffix ) );
		}
	} elseif ( preg_match( SELLA_SD_TRAILING_BUILDING, $street, $match ) ) {
		$street   = $match[1];
		$building = $match[2];
	}

	// Apartment, floor, entrance: their fields, else lifted out of line 2.
	$parts = array(
		'apartment' => $a['apartment'],
		'floor'     => $a['floor'],
		'entrance'  => $a['entrance'],
	);
	$rest  = $a['line2'];
	foreach ( sella_sd_address2_labels() as $part => $labels ) {
		$pattern = '/(?:^|[,·])\s*(?:' . $labels . ')\s*:?\s*([^,·]+)/iu';
		if ( preg_match( $pattern, $rest, $match ) ) {
			if ( '' === $parts[ $part ] ) {
				$parts[ $part ] = trim( $match[1] );
			}
			$rest = (string) preg_replace( $pattern, '', $rest, 1 );
		}
	}
	$rest = trim( (string) preg_replace( '/^[\s,·\-]+|[\s,·\-]+$/u', '', $rest ) );

	// The note: what is left of line 2, then the customer's note, said once.
	$note = trim( (string) $order->get_customer_note() );
	if ( '' !== $rest && '' !== $note && false !== mb_stripos( $note, $rest ) ) {
		$rest = '';
	}

	return array(
		$first,
		$last,
		$phone,
		$a['city'],
		trim( $street ),
		trim( $building ),
		$parts['entrance'],
		$parts['apartment'],
		$parts['floor'],
		implode( ' · ', array_filter( array( $rest, $note ), 'strlen' ) ),
	);
}

/**
 * A cell that would start a formula gets an apostrophe; a number is left alone.
 *
 * @param mixed $value Cell.
 * @return string
 */
function sella_sd_csv_cell( $value ) {
	$value = (string) $value;
	if ( '' === $value || ! in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) || is_numeric( $value ) ) {
		return $value;
	}
	return "'" . $value;
}

/**
 * The filtered orders as a CSV file, in LETS's courier sheet layout.
 */
function sella_sd_export() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_sd_export', 'sd_nonce' );

	$state  = sella_sd_state();
	$range  = sella_sd_range( $state );
	$orders = sella_sd_filtered_orders( $state );

	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
	}

	// LETS's membership columns, only when a subscription is in the file.
	$with_subs = false;
	foreach ( $orders as $data ) {
		if ( $data['sub'] ) {
			$with_subs = true;
			break;
		}
	}

	$headers = array( 'שם פרטי', 'שם משפחה', 'טלפון', 'עיר', 'רחוב', 'בית', 'כניסה', 'דירה', 'קומה', 'הערות שייכתבו על המשלוח' );
	if ( $with_subs ) {
		$headers[] = 'תאריך הצטרפות';
		$headers[] = 'מנוי';
	}
	$headers[] = 'ספרים';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="orders-' . $range['from'] . '-' . $range['to'] . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv( $out, $headers, ',', '"', '' );

	foreach ( $orders as $id => $data ) {
		$order = wc_get_order( $id );
		if ( ! $order ) {
			continue;
		}

		$subs  = array();
		$books = array();
		foreach ( $data['lines'] as $line ) {
			if ( $line['sub'] ) {
				$subs[ $line['name'] ] = true;
			} else {
				$books[] = $line['name'] . ( $line['qty'] > 1 ? ' (' . $line['qty'] . ' עותקים)' : '' );
			}
		}

		$cells = sella_sd_courier_fields( $order );
		if ( $with_subs ) {
			$date    = $order->get_date_created();
			$cells[] = $subs && $date ? $date->date_i18n( 'd/m/Y' ) : '';
			$cells[] = implode( ', ', array_keys( $subs ) );
		}
		$cells[] = implode( ' | ', $books );

		fputcsv( $out, array_map( 'sella_sd_csv_cell', $cells ), ',', '"', '' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_sella_sd_export', 'sella_sd_export' );
