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
 * - הזמנות ולקוחות: the orders of the period, filtered by what was bought
 *   (a subscription and books in the same order, only books, chosen books —
 *   any of them or all of them), with the customer's contact and full address,
 *   and an export of the same list to a CSV file Excel opens in Hebrew.
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
 * @return array{tab: string, period: string, from: string, to: string, what: string, products: int[], match: string, paged: int}
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
			'lines'  => array(),
			'sub'    => false,
			'book'   => false,
			'offer'  => false,
		);
	}

	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		sella_sd_fill_totals( $orders, $chunk );
		sella_sd_fill_lines( $orders, $chunk );
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
		if ( $pass && sella_sd_has_products( $order, $state['products'], $state['match'] ) ) {
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

	foreach ( $orders as $order ) {
		$revenue += $order['total'];
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

	$export = wp_nonce_url(
		add_query_arg( 'action', 'sella_sd_export', str_replace( admin_url( 'admin.php' ), admin_url( 'admin-post.php' ), sella_sd_url() ) ),
		'sella_sd_export'
	);
	?>
	<form class="sella-sd__filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( SELLA_SD_SLUG ); ?>" />
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

		<div class="sella-sd__filter-actions">
			<button type="submit" class="button button-primary button-hero">הצגת ההזמנות</button>
			<?php if ( 'all' !== $state['what'] || $state['products'] ) : ?>
				<a class="sella-sd__reset" href="<?php echo esc_url( sella_sd_url( array( 'what' => 'all', 'products' => array() ) ) ); ?>">ניקוי הסינון</a>
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
			<a class="button button-primary sella-sd__export" href="<?php echo esc_url( $export ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> ייצוא לאקסל</a>
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
					<td class="num"><strong><?php echo esc_html( sella_sd_money( $data['total'] ) ); ?></strong></td>
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
 * The filtered orders as a CSV file.
 */
function sella_sd_export() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_sd_export' );

	$state  = sella_sd_state();
	$range  = sella_sd_range( $state );
	$orders = sella_sd_filtered_orders( $state );
	$types  = sella_sd_offer_types();

	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="orders-' . $range['from'] . '-' . $range['to'] . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv(
		$out,
		array( 'מספר הזמנה', 'תאריך', 'סטטוס', 'שם פרטי', 'שם משפחה', 'טלפון', 'אימייל', 'רחוב', 'מספר בית', 'דירה', 'קומה', 'כניסה', 'עיר', 'מיקוד', 'כתובת מלאה', 'משלוח', 'הערה להזמנה', 'מנויים', 'ספרים', 'מספר ספרים', 'נוסף מהצעה', 'סכום ההזמנה' )
	);

	$statuses = wc_get_order_statuses();
	foreach ( $orders as $id => $data ) {
		$order = wc_get_order( $id );
		if ( ! $order ) {
			continue;
		}
		$address = sella_sd_address( $order );
		$subs    = array();
		$books   = array();
		$offers  = array();
		$count   = 0;
		foreach ( $data['lines'] as $line ) {
			$text = $line['name'] . ( $line['qty'] > 1 ? ' (' . $line['qty'] . ' עותקים)' : '' );
			if ( $line['sub'] ) {
				$subs[] = $text;
			} else {
				$books[] = $text;
				$count  += $line['qty'];
			}
			if ( $line['source'] ) {
				$offers[] = $line['name'] . ' (' . $types[ $line['source']['type'] ]['short'] . ')';
			}
		}
		$date = $order->get_date_created();

		$cells = array(
			$order->get_order_number(),
			$date ? $date->date_i18n( 'd/m/Y H:i' ) : '',
			$statuses[ 'wc-' . $order->get_status() ] ?? $order->get_status(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$order->get_billing_phone(),
			$order->get_billing_email(),
			$address['street'],
			$address['building'],
			$address['apartment'],
			$address['floor'],
			$address['entrance'],
			$address['city'],
			$address['postcode'],
			sella_sd_address_line( $address ),
			$order->get_shipping_method(),
			$order->get_customer_note(),
			implode( ' | ', $subs ),
			implode( ' | ', $books ),
			$count,
			implode( ' | ', $offers ),
			wc_format_decimal( $data['total'], 2 ),
		);
		// A cell starting with = + - @ would run as a formula in Excel; a phone number is safe.
		$cells = array_map(
			function ( $cell ) {
				$cell = (string) $cell;
				return preg_match( '/^[=+\-@]/', $cell ) && ! preg_match( '/^\+?[\d\s\-]+$/', $cell ) ? "'" . $cell : $cell;
			},
			$cells
		);
		// Excel drops a phone number's leading zero unless it is written as text.
		if ( preg_match( '/^0\d{6,}$/', $cells[5] ) ) {
			$cells[5] = '="' . $cells[5] . '"';
		}
		fputcsv( $out, $cells );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_sella_sd_export', 'sella_sd_export' );
