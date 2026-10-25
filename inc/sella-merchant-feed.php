<?php
/**
 * Google Merchant Center product feed.
 *
 * The feed is at /feed/google-merchant/ (also ?feed=google-merchant), in
 * Google's RSS 2.0 format. Merchant Center fetches it on a schedule, so
 * prices, sales and stock there follow the site without uploads.
 *
 * Every book that can be bought is in it: published, visible in the catalog,
 * priced above 0, not virtual (the digital subscription), and not excluded
 * with the checkbox on the product's General tab. A variable product is sent
 * as its variations, grouped by item_group_id. The barcode from the book
 * fields is the GTIN when its check digit is valid.
 *
 * custom_label_0 is "רבי מכר", custom_label_1 "חדש", custom_label_2 the
 * brand, for splitting Shopping campaigns.
 *
 * Admin: WooCommerce > פיד גוגל מרצ'נט (?page=sella-merchant-feed) shows the
 * feed's address and what was left out of it and why.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_MF_FEED', 'google-merchant' );
define( 'SELLA_MF_SLUG', 'sella-merchant-feed' );
define( 'SELLA_MF_CACHE', 'sella_mf_feed' );
define( 'SELLA_MF_EXCLUDE_META', '_sella_mf_exclude' );
define( 'SELLA_MF_REWRITE_VERSION', '1' );
define( 'SELLA_MF_BESTSELLER_CAT', 'best-seller' );
define( 'SELLA_MF_NEW_CAT', 'new' );
define( 'SELLA_MF_DEFAULT_BRAND', 'סלע מאיר' );
define( 'SELLA_MF_GOOGLE_CATEGORY', '784' ); // Media > Books.

/*
 * ------------------------------------------------------------------
 * Feed address
 * ------------------------------------------------------------------
 */

/**
 * Register the feed, and refresh the rewrite rules once so the pretty
 * address works without a visit to Settings > Permalinks.
 */
function sella_mf_register_feed() {
	add_feed( SELLA_MF_FEED, 'sella_mf_render_feed' );
	if ( get_option( 'sella_mf_rewrite' ) !== SELLA_MF_REWRITE_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'sella_mf_rewrite', SELLA_MF_REWRITE_VERSION, true );
	}
}
add_action( 'init', 'sella_mf_register_feed' );

/**
 * The feed's public address.
 *
 * @return string
 */
function sella_mf_feed_url() {
	return get_feed_link( SELLA_MF_FEED );
}

/*
 * ------------------------------------------------------------------
 * Which products, and their fields
 * ------------------------------------------------------------------
 */

/**
 * Why a product stays out of the feed, or '' when it goes in.
 *
 * @param WC_Product $product Product (a variation is checked as itself).
 * @return string
 */
function sella_mf_exclusion_reason( $product ) {
	$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;

	if ( 'yes' === $parent->get_meta( SELLA_MF_EXCLUDE_META ) ) {
		return 'הוצא ידנית מהפיד';
	}
	if ( 'publish' !== $parent->get_status() ) {
		return 'לא מפורסם';
	}
	if ( ! in_array( $parent->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true ) ) {
		return 'מוסתר מהחנות';
	}
	if ( $product->is_type( 'variation' ) && 'publish' !== $product->get_status() ) {
		return 'וריאציה כבויה';
	}
	if ( $product->is_virtual() ) {
		return 'מוצר דיגיטלי או מנוי';
	}
	if ( (float) $product->get_price() <= 0 ) {
		return 'אין מחיר (עדיין לא במכירה)';
	}
	if ( ! $product->is_purchasable() ) {
		return 'לא ניתן לרכישה';
	}
	if ( ! $product->get_image_id() && ! $parent->get_image_id() ) {
		return 'אין תמונה';
	}
	return '';
}

/**
 * A barcode as a GTIN, when it is 8, 12, 13 or 14 digits with a valid check
 * digit; otherwise ''.
 *
 * @param string $barcode Barcode.
 * @return string
 */
function sella_mf_gtin( $barcode ) {
	$digits = preg_replace( '/\D/', '', (string) $barcode );
	if ( ! in_array( strlen( $digits ), array( 8, 12, 13, 14 ), true ) ) {
		return '';
	}
	$sum  = 0;
	$body = strrev( substr( $digits, 0, -1 ) );
	for ( $i = 0, $n = strlen( $body ); $i < $n; $i++ ) {
		$sum += (int) $body[ $i ] * ( 0 === $i % 2 ? 3 : 1 );
	}
	return ( 10 - $sum % 10 ) % 10 === (int) substr( $digits, -1 ) ? $digits : '';
}

/**
 * Price with tax, as Google wants it: "69.00 ILS".
 *
 * @param WC_Product $product Product.
 * @param float      $price   Price as stored.
 * @return string
 */
function sella_mf_price( $product, $price ) {
	$gross = wc_get_price_including_tax( $product, array( 'price' => $price ) );
	return wc_format_decimal( $gross, 2 ) . ' ' . get_woocommerce_currency();
}

/**
 * A URL with every non-ASCII character percent-encoded (Hebrew file names in
 * uploads), which Google requires.
 *
 * @param string $url URL.
 * @return string
 */
function sella_mf_url( $url ) {
	return preg_replace_callback(
		'/[^\x21-\x7E]+/',
		function ( $m ) {
			return rawurlencode( $m[0] );
		},
		(string) $url
	);
}

/**
 * Plain text from product HTML, cut to Google's limit.
 *
 * @param string $html  HTML.
 * @param int    $limit Characters.
 * @return string
 */
function sella_mf_text( $html, $limit ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) $html ) );
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…' : $text;
}

/**
 * The feed fields for one product or variation.
 *
 * @param WC_Product $product Product or variation.
 * @return array<string, string|string[]> Field => value; a list repeats the field.
 */
function sella_mf_item( $product ) {
	$parent    = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
	$parent_id = $parent->get_id();

	$title = $parent->get_name();
	if ( $product->is_type( 'variation' ) ) {
		$attrs = array_filter( array_values( $product->get_variation_attributes( false ) ) );
		if ( $attrs ) {
			$title .= ' – ' . implode( ', ', $attrs );
		}
	}

	$description = sella_mf_text( $parent->get_description(), 5000 );
	if ( '' === $description ) {
		$description = sella_mf_text( $parent->get_short_description(), 5000 );
	}
	if ( '' === $description ) {
		$description = $parent->get_name();
	}

	$image_id = $product->get_image_id() ? $product->get_image_id() : $parent->get_image_id();
	$gallery  = array();
	foreach ( array_slice( $parent->get_gallery_image_ids(), 0, 10 ) as $id ) {
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( $url ) {
			$gallery[] = sella_mf_url( $url );
		}
	}

	$brands = wp_get_post_terms( $parent_id, 'product_brand', array( 'fields' => 'names' ) );
	$brand  = ! is_wp_error( $brands ) && $brands ? $brands[0] : SELLA_MF_DEFAULT_BRAND;
	$cats   = wp_get_post_terms( $parent_id, 'product_cat' );
	if ( ! is_wp_error( $cats ) && function_exists( 'sella_hc_ids' ) ) {
		$hidden = sella_hc_ids();
		$cats   = array_values( array_filter( $cats, fn( $cat ) => ! in_array( (int) $cat->term_id, $hidden, true ) ) );
	}
	$slugs  = is_wp_error( $cats ) ? array() : wp_list_pluck( $cats, 'slug' );
	$names  = is_wp_error( $cats ) ? array() : wp_list_pluck( $cats, 'name' );
	$types  = array_values( array_diff( $slugs, array( 'books', SELLA_MF_NEW_CAT, SELLA_MF_BESTSELLER_CAT ) ) );

	$stock = $product->get_stock_status();
	$item  = array(
		'g:id'                      => (string) $product->get_id(),
		'g:title'                   => sella_mf_text( $title, 150 ),
		'g:description'             => $description,
		'g:link'                    => sella_mf_url( $product->get_permalink() ),
		'g:image_link'              => sella_mf_url( wp_get_attachment_image_url( $image_id, 'full' ) ),
		'g:additional_image_link'   => $gallery,
		'g:availability'            => 'instock' === $stock ? 'in_stock' : ( 'onbackorder' === $stock ? 'backorder' : 'out_of_stock' ),
		'g:price'                   => sella_mf_price( $product, $product->get_regular_price() ? $product->get_regular_price() : $product->get_price() ),
		'g:condition'               => 'new',
		'g:brand'                   => $brand,
		'g:google_product_category' => SELLA_MF_GOOGLE_CATEGORY,
		'g:product_type'            => $types ? $names[ array_search( $types[0], $slugs, true ) ] : 'ספרים',
		'g:custom_label_0'          => in_array( SELLA_MF_BESTSELLER_CAT, $slugs, true ) ? 'רבי מכר' : '',
		'g:custom_label_1'          => in_array( SELLA_MF_NEW_CAT, $slugs, true ) ? 'חדש' : '',
		'g:custom_label_2'          => $brand,
	);

	if ( $product->is_on_sale() && $product->get_sale_price() ) {
		$item['g:sale_price'] = sella_mf_price( $product, $product->get_sale_price() );
		$from                 = $product->get_date_on_sale_from();
		$to                   = $product->get_date_on_sale_to();
		if ( $from && $to ) {
			$item['g:sale_price_effective_date'] = $from->format( 'Y-m-d\TH:iO' ) . '/' . $to->format( 'Y-m-d\TH:iO' );
		}
	}

	$gtin = sella_mf_gtin( $parent->get_meta( '_sella_barcode' ) );
	if ( '' !== $gtin && ! $product->is_type( 'variation' ) ) {
		$item['g:gtin'] = $gtin;
	} else {
		$item['g:identifier_exists'] = 'no';
	}

	if ( $product->is_type( 'variation' ) ) {
		$item['g:item_group_id'] = (string) $parent_id;
	}

	return $item;
}

/**
 * Every product with its feed fields or the reason it was left out.
 *
 * @return array{items: array[], skipped: array[], no_gtin: array[]}
 */
function sella_mf_collect() {
	$ids = wc_get_products(
		array(
			'status' => array( 'publish', 'private' ),
			'limit'  => -1,
			'return' => 'ids',
		)
	);

	$items   = array();
	$skipped = array();
	$no_gtin = array();

	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		$units = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
		foreach ( $units as $unit ) {
			$reason = sella_mf_exclusion_reason( $unit );
			if ( '' !== $reason ) {
				$skipped[] = array( $unit->get_id(), $unit->get_name(), $reason );
				continue;
			}
			$item    = sella_mf_item( $unit );
			$items[] = $item;
			if ( empty( $item['g:gtin'] ) ) {
				$no_gtin[] = array( $unit->get_id(), $unit->get_name() );
			}
		}
	}

	return compact( 'items', 'skipped', 'no_gtin' );
}

/*
 * ------------------------------------------------------------------
 * The XML
 * ------------------------------------------------------------------
 */

/**
 * The feed document. Kept for an hour, and rebuilt as soon as a product
 * changes.
 *
 * @return string
 */
function sella_mf_xml() {
	$cached = get_transient( SELLA_MF_CACHE );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$x = static function ( $value ) {
		return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	};

	$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$out .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n";
	$out .= '<title>' . $x( get_bloginfo( 'name' ) ) . "</title>\n";
	$out .= '<link>' . $x( home_url( '/' ) ) . "</link>\n";
	$out .= '<description>' . $x( 'ספרי ' . get_bloginfo( 'name' ) ) . "</description>\n";

	foreach ( sella_mf_collect()['items'] as $item ) {
		$out .= "<item>\n";
		foreach ( $item as $field => $value ) {
			foreach ( (array) $value as $one ) {
				if ( '' !== (string) $one ) {
					$out .= '<' . $field . '>' . $x( $one ) . '</' . $field . ">\n";
				}
			}
		}
		$out .= "</item>\n";
	}
	$out .= "</channel>\n</rss>\n";

	set_transient( SELLA_MF_CACHE, $out, HOUR_IN_SECONDS );
	return $out;
}

/**
 * Send the feed.
 */
function sella_mf_render_feed() {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
	}
	echo sella_mf_xml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped while building it.
	exit;
}

/**
 * A product change (price, sale, stock, text) rebuilds the feed on the next
 * fetch.
 */
function sella_mf_flush() {
	delete_transient( SELLA_MF_CACHE );
}
add_action( 'woocommerce_update_product', 'sella_mf_flush' );
add_action( 'woocommerce_new_product', 'sella_mf_flush' );
add_action( 'woocommerce_update_product_variation', 'sella_mf_flush' );
add_action( 'woocommerce_product_set_stock_status', 'sella_mf_flush' );
add_action( 'woocommerce_variation_set_stock_status', 'sella_mf_flush' );
add_action( 'woocommerce_scheduled_sales', 'sella_mf_flush', 20 );
add_action( 'trashed_post', 'sella_mf_flush' );

/*
 * ------------------------------------------------------------------
 * Product editor: leave a product out
 * ------------------------------------------------------------------
 */

/**
 * Checkbox on the product's General tab.
 */
function sella_mf_exclude_field() {
	woocommerce_wp_checkbox(
		array(
			'id'          => SELLA_MF_EXCLUDE_META,
			'label'       => 'גוגל מרצ\'נט',
			'description' => 'לא לכלול את המוצר בפיד',
		)
	);
}
add_action( 'woocommerce_product_options_general_product_data', 'sella_mf_exclude_field' );

/**
 * Save the checkbox.
 *
 * @param WC_Product $product Product being saved.
 */
function sella_mf_exclude_save( $product ) {
	$product->update_meta_data( SELLA_MF_EXCLUDE_META, isset( $_POST[ SELLA_MF_EXCLUDE_META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the product form nonce.
}
add_action( 'woocommerce_admin_process_product_object', 'sella_mf_exclude_save' );

/*
 * ------------------------------------------------------------------
 * Admin screen
 * ------------------------------------------------------------------
 */

/**
 * Under WooCommerce.
 */
function sella_mf_register_admin_page() {
	add_submenu_page( 'woocommerce', 'פיד גוגל מרצ\'נט', 'פיד גוגל מרצ\'נט', 'manage_woocommerce', SELLA_MF_SLUG, 'sella_mf_render_page' );
}
add_action( 'admin_menu', 'sella_mf_register_admin_page', 61 );

/**
 * A list of products with links to edit them.
 *
 * @param array $rows [id, name, reason?].
 */
function sella_mf_render_list( $rows ) {
	echo '<table class="widefat striped sella-mf-table"><tbody>';
	foreach ( $rows as $row ) {
		$id = wp_get_post_parent_id( $row[0] ) ? wp_get_post_parent_id( $row[0] ) : $row[0];
		echo '<tr><td><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $row[1] ) . '</a></td>';
		echo isset( $row[2] ) ? '<td>' . esc_html( $row[2] ) . '</td>' : '';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * The screen.
 */
function sella_mf_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$data = sella_mf_collect();
	$url  = sella_mf_feed_url();
	?>
	<div class="wrap sella-mf-admin">
		<h1>פיד גוגל מרצ'נט</h1>
		<p class="sella-mf-lead">פיד המוצרים ל-Google Merchant Center. גוגל מושך אותו לבד לפי לוח זמנים, ולכן מחירים, מבצעים ומלאי מתעדכנים שם בלי להעלות קבצים. הפיד נבנה מחדש בכל שינוי במוצר.</p>

		<section class="sella-mf-section">
			<h2>כתובת הפיד</h2>
			<p><input type="text" class="large-text code" dir="ltr" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();"></p>
			<p><a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">פתיחת הפיד</a></p>
			<p class="sella-mf-counts">
				<span><b><?php echo esc_html( number_format_i18n( count( $data['items'] ) ) ); ?></b> מוצרים בפיד</span>
				<span><b><?php echo esc_html( number_format_i18n( count( $data['skipped'] ) ) ); ?></b> לא נכללו</span>
				<span><b><?php echo esc_html( number_format_i18n( count( $data['no_gtin'] ) ) ); ?></b> בלי ברקוד תקין</span>
			</p>
		</section>

		<section class="sella-mf-section">
			<h2>חיבור ל-Merchant Center</h2>
			<ol>
				<li>ב-<a href="https://merchants.google.com/" target="_blank" rel="noopener">Google Merchant Center</a>: מוצרים ← הוספת מוצרים ← הוספת מקור מוצרים ← <b>הוספת מוצרים מקובץ</b>.</li>
				<li>לבחור <b>הזנת קישור לקובץ</b> ולהדביק את כתובת הפיד למעלה.</li>
				<li>מדינה: ישראל, שפה: עברית. תדירות: יומית (אפשר גם כל כמה שעות).</li>
				<li>משלוחים ומדיניות החזרה מוגדרים בהגדרות החשבון ב-Merchant Center, לא בפיד.</li>
			</ol>
			<p class="description">ברקוד הספר (שדה "ברקוד" בפרטי הספר) נשלח כ-GTIN. ספר בלי ברקוד תקין נשלח בלי GTIN, וגוגל עלול להציג אותו פחות. כדאי למלא ברקוד לספרים ברשימה למטה.</p>
		</section>

		<?php if ( $data['skipped'] ) : ?>
			<section class="sella-mf-section">
				<h2>לא נכללו בפיד</h2>
				<?php sella_mf_render_list( $data['skipped'] ); ?>
			</section>
		<?php endif; ?>

		<?php if ( $data['no_gtin'] ) : ?>
			<section class="sella-mf-section">
				<h2>בפיד, בלי ברקוד תקין</h2>
				<?php sella_mf_render_list( $data['no_gtin'] ); ?>
			</section>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Screen styles, inline: a handful of rules.
 *
 * @param string $hook Admin hook.
 */
function sella_mf_admin_styles( $hook ) {
	if ( false === strpos( $hook, SELLA_MF_SLUG ) ) {
		return;
	}
	wp_add_inline_style(
		'common',
		'.sella-mf-lead{max-width:860px;color:#50575e;font-size:14px;line-height:1.6}'
		. '.sella-mf-section{max-width:980px;margin:20px 0;padding:18px 20px 20px;border:1px solid #dcdcde;border-radius:8px;background:#fff}'
		. '.sella-mf-section h2{margin:0 0 10px;font-size:16px}.sella-mf-section ol{margin:0 20px 10px 0;line-height:1.7}'
		. '.sella-mf-counts{display:flex;flex-wrap:wrap;gap:6px 22px;color:#50575e}.sella-mf-counts b{color:#1d2327;font-size:20px}'
		. '.sella-mf-table{max-width:820px}'
	);
}
add_action( 'admin_enqueue_scripts', 'sella_mf_admin_styles' );
