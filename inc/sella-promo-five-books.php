<?php
/**
 * "5 ספרים ב-200": the hidden category of the books in the deal.
 *
 * The first time an admin opens the dashboard after this ships, the hidden
 * category "מוצרים משתתפים במבצע 5 ספרים ב 200" is created and every book is
 * put in it, except subscriptions and products tagged "באנדל". A notice says
 * what was done; its button runs it again, to add books added since (books
 * already in the category, or taken out of it by hand, are left as they are
 * only in that the run adds and never removes).
 *
 * The category is hidden (sella-hidden-categories.php): rules and coupons
 * can use it, shoppers never see it.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_P5_NAME', 'מוצרים משתתפים במבצע 5 ספרים ב 200' );
define( 'SELLA_P5_SLUG', 'promo-5-books-200' );
define( 'SELLA_P5_BUNDLE_TAG', 'באנדל' );
define( 'SELLA_P5_DONE', 'sella_p5_done' );
define( 'SELLA_P5_RESULT', 'sella_p5_result' );

/**
 * Why a product stays out of the deal, or '' when it is a book that joins.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function sella_p5_exclusion( $product ) {
	if ( in_array( $product->get_type(), array( 'subscription', 'variable-subscription' ), true ) ) {
		return 'subscription';
	}
	// The Shibolet subscriptions are ordinary products named for the plan.
	if ( preg_match( '/מנוי|מינוי|שיבולת פלוס/u', $product->get_name() ) ) {
		return 'subscription';
	}
	if ( has_term( SELLA_P5_BUNDLE_TAG, 'product_tag', $product->get_id() ) ) {
		return 'bundle';
	}
	return '';
}

/**
 * The deal's category, made (and hidden) when missing.
 *
 * @return int|WP_Error Term ID.
 */
function sella_p5_category() {
	$term = get_term_by( 'slug', SELLA_P5_SLUG, 'product_cat' );
	if ( ! $term ) {
		$made = wp_insert_term( SELLA_P5_NAME, 'product_cat', array( 'slug' => SELLA_P5_SLUG ) );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$term_id = (int) $made['term_id'];
	} else {
		$term_id = (int) $term->term_id;
	}

	if ( function_exists( 'sella_hc_ids' ) && ! in_array( $term_id, sella_hc_ids(), true ) ) {
		update_option( SELLA_HC_OPTION, array_values( array_unique( array_merge( sella_hc_ids(), array( $term_id ) ) ) ), true );
	}
	return $term_id;
}

/**
 * Put every book in the deal's category.
 *
 * @return array|WP_Error Counts and the names left out.
 */
function sella_p5_sync() {
	$term_id = sella_p5_category();
	if ( is_wp_error( $term_id ) ) {
		return $term_id;
	}

	$ids = wc_get_products(
		array(
			'status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'limit'  => -1,
			'return' => 'ids',
		)
	);

	$result = array(
		'added'   => 0,
		'already' => 0,
		'out'     => array(
			'subscription' => array(),
			'bundle'       => array(),
		),
	);

	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		$why = sella_p5_exclusion( $product );
		if ( '' !== $why ) {
			$result['out'][ $why ][] = $product->get_name();
			continue;
		}
		if ( has_term( $term_id, 'product_cat', $id ) ) {
			++$result['already'];
			continue;
		}
		wp_set_object_terms( $id, $term_id, 'product_cat', true );
		wc_delete_product_transients( $id );
		++$result['added'];
	}

	update_option( SELLA_P5_RESULT, $result, false );
	return $result;
}

/**
 * Run once after the theme ships.
 */
function sella_p5_first_run() {
	if ( get_option( SELLA_P5_DONE ) || ! current_user_can( 'manage_woocommerce' ) || wp_doing_ajax() ) {
		return;
	}
	$result = sella_p5_sync();
	if ( ! is_wp_error( $result ) ) {
		update_option( SELLA_P5_DONE, gmdate( 'c' ), false );
		update_option( 'sella_p5_notice', 1, false );
	}
}
add_action( 'admin_init', 'sella_p5_first_run' );

/**
 * "Run again" from the notice.
 */
function sella_p5_handle_sync() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_p5_sync' );
	sella_p5_sync();
	update_option( 'sella_p5_notice', 1, false );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_sella_p5_sync', 'sella_p5_handle_sync' );

/**
 * Hide the notice.
 */
function sella_p5_handle_dismiss() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_p5_dismiss' );
	delete_option( 'sella_p5_notice' );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_sella_p5_dismiss', 'sella_p5_handle_dismiss' );

/**
 * What the last run did, until dismissed.
 */
function sella_p5_notice() {
	if ( ! get_option( 'sella_p5_notice' ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$r = get_option( SELLA_P5_RESULT );
	if ( ! is_array( $r ) ) {
		return;
	}
	$term = get_term_by( 'slug', SELLA_P5_SLUG, 'product_cat' );
	$list = function ( $names ) {
		return $names ? esc_html( implode( ', ', $names ) ) : 'אין';
	};
	$url  = function ( $action ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
	};
	?>
	<div class="notice notice-success">
		<p><strong>מבצע 5 ספרים ב-200:</strong> הקטגוריה הנסתרת "<?php echo esc_html( SELLA_P5_NAME ); ?>" מוכנה.
		שויכו אליה <?php echo esc_html( number_format_i18n( $r['added'] ) ); ?> ספרים<?php echo $r['already'] ? ' (ועוד ' . esc_html( number_format_i18n( $r['already'] ) ) . ' שכבר היו בה)' : ''; ?>.</p>
		<p>לא שויכו: מנויים (<?php echo esc_html( count( $r['out']['subscription'] ) ); ?>): <?php echo $list( $r['out']['subscription'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>.<br>
		באנדלים (<?php echo esc_html( count( $r['out']['bundle'] ) ); ?>): <?php echo $list( $r['out']['bundle'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>.</p>
		<p>
			<?php if ( $term ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&product_cat=' . $term->slug ) ); ?>">הספרים בקטגוריה</a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $url( 'sella_p5_sync' ) ); ?>">להוסיף ספרים חדשים</a>
			<a href="<?php echo esc_url( $url( 'sella_p5_dismiss' ) ); ?>">הסתרת ההודעה</a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'sella_p5_notice' );
