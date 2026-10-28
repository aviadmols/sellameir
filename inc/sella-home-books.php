<?php
/**
 * Homepage book sliders: pick the books and their order (מוצרים > ספרים בדף הבית).
 *
 * Two sections on the homepage:
 * - "bestsellers": the "רבי המכר" slider, our [random_three_books] shortcode
 *   (home-books-cart.php).
 * - "breakthrough": the "ספרים פורצי דרך" marquee, rendered by a WPCode snippet
 *   whose shortcode we do not control. Its output is recognized by the
 *   .books-marquee-section wrapper and replaced with the same markup holding
 *   the chosen books.
 *
 * An empty list leaves its section as it was (random books, or the snippet's own).
 * The lists are one option, so saving it from the admin purges the page cache.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_HOME_BOOKS_OPTION', 'sella_home_books' );

/**
 * The homepage sections that take a book list.
 *
 * @return array<string, array<string, string>>
 */
function sella_home_books_sections() {
	return array(
		'bestsellers'  => array(
			'title'       => 'רבי מכר',
			'description' => 'הסליידר "רבי המכר שמעצבים שיחה ישראלית חדשה". כשהרשימה ריקה מוצגים 12 ספרים אקראיים.',
		),
		'breakthrough' => array(
			'title'       => 'ספרים פורצי דרך',
			'description' => 'הפס הנע "ספרים פורצי דרך במחירים מיוחדים". כשהרשימה ריקה מוצגים הספרים שהוגדרו בו עד היום.',
		),
	);
}

/**
 * Stored product IDs of one section, in order.
 *
 * @param string $key Section key.
 * @return int[]
 */
function sella_home_books_ids( $key ) {
	$lists = get_option( SELLA_HOME_BOOKS_OPTION, array() );
	$ids   = is_array( $lists ) && isset( $lists[ $key ] ) && is_array( $lists[ $key ] ) ? $lists[ $key ] : array();

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * Published products of one section, in order. Drafts, private and deleted
 * products are skipped so the homepage never links to a missing page.
 *
 * @param string $key Section key.
 * @return WC_Product[]
 */
function sella_home_books_products( $key ) {
	$ids = sella_home_books_ids( $key );
	if ( ! $ids ) {
		return array();
	}

	_prime_post_caches( $ids, false, true );

	$products = array();
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( $product && 'publish' === $product->get_status() ) {
			$products[] = $product;
		}
	}
	return $products;
}

/*
 * ------------------------------------------------------------------
 * Front end: the "ספרים פורצי דרך" marquee
 * ------------------------------------------------------------------
 */

/**
 * The marquee with the chosen books, in the snippet's markup: the books twice,
 * so the CSS loop (.books-marquee-track, 30s) runs without a gap.
 *
 * @return string Empty when no books were chosen.
 */
function sella_home_books_marquee_html() {
	$products = sella_home_books_products( 'breakthrough' );
	if ( ! $products ) {
		return '';
	}

	$cards = '';
	$copy  = '';
	foreach ( $products as $product ) {
		$cards .= sella_home_book_card_html( $product, 'book-card-item', false );
		$copy  .= sella_home_book_card_html( $product, 'book-card-item', false, false );
	}

	return '
    <div class="books-marquee-section">
        <div class="books-marquee-track">
            <div class="books-marquee-group">' . $cards . '
            </div>
            <div class="books-marquee-group" aria-hidden="true">' . $copy . '
            </div>
        </div>
    </div>';
}

/**
 * Swap the snippet's marquee for the chosen books.
 *
 * @param string|false $output Shortcode output.
 * @return string|false
 */
function sella_home_books_replace_marquee( $output ) {
	if ( ! is_string( $output ) || false === strpos( $output, 'books-marquee-section' ) ) {
		return $output;
	}

	$html = sella_home_books_marquee_html();
	return '' !== $html ? $html : $output;
}
add_filter( 'do_shortcode_tag', 'sella_home_books_replace_marquee' );

/*
 * ------------------------------------------------------------------
 * Admin screen
 * ------------------------------------------------------------------
 */

/**
 * "ספרים בדף הבית" under the Products menu.
 */
function sella_home_books_register_admin_page() {
	add_submenu_page(
		'edit.php?post_type=product',
		'ספרים בדף הבית',
		'ספרים בדף הבית',
		'manage_woocommerce',
		'sella-home-books',
		'sella_home_books_render_admin_page'
	);
}
add_action( 'admin_menu', 'sella_home_books_register_admin_page', 20 );

/**
 * What the screen shows for one book, in search results and in the lists.
 *
 * @param WC_Product $product Product.
 * @return array<string, mixed>
 */
function sella_home_books_item( $product ) {
	$id    = $product->get_id();
	$thumb = get_the_post_thumbnail_url( $id, 'thumbnail' );
	$tags  = array();

	if ( 'publish' !== $product->get_status() ) {
		$tags[] = array(
			'label' => 'לא מפורסם, לא יוצג',
			'tone'  => 'bad',
		);
	}
	if ( ! $product->is_in_stock() ) {
		$tags[] = array(
			'label' => 'אזל מהמלאי',
			'tone'  => 'warn',
		);
	}
	if ( 'hidden' === $product->get_catalog_visibility() ) {
		$tags[] = array(
			'label' => 'מוסתר בחנות',
			'tone'  => 'warn',
		);
	}
	if ( $product->is_on_sale() ) {
		$tags[] = array(
			'label' => 'במבצע',
			'tone'  => 'good',
		);
	}

	return array(
		'id'     => $id,
		'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
		'author' => sella_home_book_author( $id ),
		'price'  => $product->get_price_html(),
		'thumb'  => $thumb ? $thumb : wc_placeholder_img_src( 'thumbnail' ),
		'edit'   => (string) get_edit_post_link( $id, 'raw' ),
		'tags'   => $tags,
	);
}

/**
 * Product IDs matching a search: an ID, then title, SKU and content matches
 * (WooCommerce's own product search), then the author field.
 *
 * @param string $term  Search text.
 * @param int    $limit Most results.
 * @return int[]
 */
function sella_home_books_search_ids( $term, $limit = 20 ) {
	global $wpdb;

	$ids = array();
	if ( ctype_digit( $term ) && 'product' === get_post_type( (int) $term ) ) {
		$ids[] = (int) $term;
	}

	$ids = array_merge( $ids, WC_Data_Store::load( 'product' )->search_products( $term, '', false, false, $limit ) );

	$author_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			AND pm.meta_key IN (%s, %s) AND pm.meta_value LIKE %s
			LIMIT %d",
			'מחבר',
			'_author',
			'%' . $wpdb->esc_like( $term ) . '%',
			$limit
		)
	);
	$ids = array_merge( $ids, array_map( 'intval', $author_ids ) );

	return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, $limit );
}

/**
 * AJAX: search books for the screen.
 */
function sella_home_books_ajax_search() {
	check_ajax_referer( 'sella_home_books', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( null, 403 );
	}

	$term = isset( $_GET['term'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['term'] ) ) ) : '';
	if ( '' === $term ) {
		wp_send_json_success( array() );
	}

	$items = array();
	foreach ( sella_home_books_search_ids( $term ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			$items[] = sella_home_books_item( $product );
		}
	}

	// Titles that start with the search come first, then other title matches.
	$rank = static function ( $item ) use ( $term ) {
		$pos = mb_stripos( $item['title'], $term );
		return false === $pos ? 2 : ( 0 === $pos ? 0 : 1 );
	};
	usort(
		$items,
		static function ( $a, $b ) use ( $rank ) {
			return $rank( $a ) - $rank( $b );
		}
	);

	wp_send_json_success( $items );
}
add_action( 'wp_ajax_sella_home_books_search', 'sella_home_books_ajax_search' );

/**
 * Save the lists.
 */
function sella_home_books_handle_save() {
	if ( empty( $_POST['sella_home_books_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sella_home_books_save' ) ) {
		return;
	}
	// The script builds the lists; a form posted without it would wipe them.
	if ( empty( $_POST['sella_home_books_js'] ) ) {
		return;
	}

	$posted = isset( $_POST['sella_home_books'] ) && is_array( $_POST['sella_home_books'] ) ? wp_unslash( $_POST['sella_home_books'] ) : array();
	$lists  = array();
	foreach ( array_keys( sella_home_books_sections() ) as $key ) {
		$ids           = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : array();
		$lists[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	// Autoloaded: the homepage reads it on every uncached view.
	update_option( SELLA_HOME_BOOKS_OPTION, $lists, true );

	wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=sella-home-books&saved=1' ) );
	exit;
}
add_action( 'admin_init', 'sella_home_books_handle_save' );

/**
 * Render the screen. The lists are drawn by the script from sellaHomeBooks.
 */
function sella_home_books_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	?>
	<div class="wrap sella-hb" dir="rtl">
		<h1>ספרים בדף הבית</h1>
		<p class="sella-hb__intro">בוחרים אילו ספרים יוצגו בכל אזור בדף הבית ובאיזה סדר. מקלידים בתיבת החיפוש שם ספר, מחבר, מק״ט או מספר מוצר, ובוחרים מהרשימה (אפשר עם החצים ו-Enter). את הסדר משנים בגרירה או בכפתורי החצים.</p>

		<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success is-dismissible"><p>הסדר נשמר. דף הבית מתעדכן מיד.</p></div>
		<?php endif; ?>

		<form method="post" data-hb-form>
			<?php wp_nonce_field( 'sella_home_books_save' ); ?>
			<input type="hidden" name="sella_home_books_action" value="save" />

			<div class="sella-hb__panels">
				<?php foreach ( sella_home_books_sections() as $key => $section ) : ?>
					<section class="sella-hb-panel" data-hb-panel="<?php echo esc_attr( $key ); ?>">
						<header class="sella-hb-panel__head">
							<h2><?php echo esc_html( $section['title'] ); ?> <span class="sella-hb-panel__count" data-hb-count></span></h2>
							<p class="description"><?php echo esc_html( $section['description'] ); ?></p>
						</header>

						<div class="sella-hb-search">
							<label class="screen-reader-text" for="sella-hb-search-<?php echo esc_attr( $key ); ?>">חיפוש ספר להוספה</label>
							<input type="search" id="sella-hb-search-<?php echo esc_attr( $key ); ?>" class="sella-hb-search__input" data-hb-search placeholder="חיפוש ספר להוספה…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="sella-hb-results-<?php echo esc_attr( $key ); ?>" />
							<span class="spinner" data-hb-spinner></span>
							<ul class="sella-hb-search__results" id="sella-hb-results-<?php echo esc_attr( $key ); ?>" role="listbox" data-hb-results hidden></ul>
						</div>

						<ol class="sella-hb-list" data-hb-list></ol>
						<p class="sella-hb-empty" data-hb-empty hidden>הרשימה ריקה, האזור מוצג כמו היום.</p>

						<p class="sella-hb-panel__foot">
							<button type="button" class="button-link sella-hb-clear" data-hb-clear>ניקוי הרשימה</button>
						</p>
					</section>
				<?php endforeach; ?>
			</div>

			<p class="submit sella-hb__submit">
				<button type="submit" class="button button-primary button-large">שמירת הסדר</button>
				<span class="sella-hb__dirty" data-hb-dirty hidden>יש שינויים שלא נשמרו</span>
				<a class="sella-hb__view" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">צפייה בדף הבית ↗</a>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Admin assets, with the stored lists for the script to draw.
 *
 * @param string $hook Admin hook.
 */
function sella_home_books_admin_assets( $hook ) {
	if ( false === strpos( $hook, 'sella-home-books' ) ) {
		return;
	}

	$lists = array();
	foreach ( array_keys( sella_home_books_sections() ) as $key ) {
		$lists[ $key ] = array();
		foreach ( sella_home_books_ids( $key ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$lists[ $key ][] = sella_home_books_item( $product );
			}
		}
	}

	$base = get_stylesheet_directory_uri();
	wp_enqueue_style( 'sella-home-books-admin', $base . '/assets/css/sella-home-books-admin.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-home-books-admin', $base . '/assets/js/sella-home-books-admin.js', array( 'jquery', 'jquery-ui-sortable' ), HELLO_ELEMENTOR_CHILD_VERSION, true );
	wp_localize_script(
		'sella-home-books-admin',
		'sellaHomeBooks',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sella_home_books' ),
			'lists'   => $lists,
		)
	);
}
add_action( 'admin_enqueue_scripts', 'sella_home_books_admin_assets' );
