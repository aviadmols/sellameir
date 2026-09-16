<?php
/**
 * One-time tool: unlimited stock for every product that is not a draft.
 *
 * WooCommerce → מלאי בלתי מוגבל lists the products that still track stock or are
 * not "in stock" (a variable product is listed when any of its variations is),
 * and switches them to "don't track stock" + "in stock" in batches. Changes go
 * through the WooCommerce CRUD so lookup tables and caches stay in sync.
 *
 * Back-in-stock emails are not sent for this change; pending sign-ups stay pending.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_US_ADMIN_SLUG', 'sella-unlimited-stock' );
define( 'SELLA_US_BATCH_SIZE', 25 );

/**
 * Post statuses left untouched.
 *
 * @return array<int, string>
 */
function sella_us_skipped_statuses() {
	return array( 'draft', 'auto-draft', 'trash' );
}

/**
 * Admin page URL.
 *
 * @param array $args Extra query args.
 * @return string
 */
function sella_us_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => SELLA_US_ADMIN_SLUG ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Products that still track stock or are not in stock, by ascending ID.
 * A variation that needs the change yields its parent, which is updated as a whole.
 *
 * @param int $after_id Batch cursor: only IDs above this one.
 * @param int $limit    Max IDs (0 = all).
 * @return array<int, int>
 */
function sella_us_pending_product_ids( $after_id = 0, $limit = 0 ) {
	global $wpdb;

	$skipped      = sella_us_skipped_statuses();
	$placeholders = implode( ',', array_fill( 0, count( $skipped ), '%s' ) );
	$params       = array_merge( $skipped, $skipped, array( absint( $after_id ) ) );

	$sql = "SELECT product_id FROM (
			SELECT DISTINCT IF( p.post_type = 'product_variation', p.post_parent, p.ID ) AS product_id
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent
			LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock'
			LEFT JOIN {$wpdb->postmeta} ss ON ss.post_id = p.ID AND ss.meta_key = '_stock_status'
			WHERE (
				( p.post_type = 'product' AND p.post_status NOT IN ({$placeholders}) )
				OR ( p.post_type = 'product_variation' AND pp.post_type = 'product' AND pp.post_status NOT IN ({$placeholders}) )
			)
			AND ( ms.meta_value = 'yes' OR ss.meta_value IS NULL OR ss.meta_value <> 'instock' )
		) pending
		WHERE product_id > %d
		ORDER BY product_id ASC";

	if ( $limit ) {
		$sql     .= ' LIMIT %d';
		$params[] = absint( $limit );
	}

	return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders built above.
}

/**
 * Switch one product or variation to untracked, in-stock. Saves only when something changes.
 *
 * @param WC_Product $product Product or variation.
 */
function sella_us_make_unlimited( $product ) {
	if ( ! $product->get_manage_stock( 'edit' ) && 'instock' === $product->get_stock_status( 'edit' ) ) {
		return;
	}

	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$product->save();
}

/**
 * Update one batch of products.
 *
 * @param int $after_id Cursor: continue after this product ID.
 * @return array{count:int,last_id:int}
 */
function sella_us_run_batch( $after_id ) {
	// Otherwise every product that turns "in stock" emails its back-in-stock subscribers.
	remove_action( 'woocommerce_product_set_stock_status', 'sella_bis_on_stock_status_change', 10 );
	remove_action( 'woocommerce_variation_set_stock_status', 'sella_bis_on_stock_status_change', 10 );

	$ids = sella_us_pending_product_ids( $after_id, SELLA_US_BATCH_SIZE );

	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}

		if ( $product->is_type( 'variable' ) ) {
			// Stop parent-level stock first: a variation saved while the parent tracks
			// stock takes the parent's quantity and can drop back to "out of stock".
			if ( $product->get_manage_stock( 'edit' ) ) {
				$product->set_manage_stock( false );
				$product->save();
			}

			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation ) {
					sella_us_make_unlimited( $variation );
				}
			}
			// Saving variations re-syncs the parent's stock status, so read it fresh.
			$product = wc_get_product( $id );
		}

		sella_us_make_unlimited( $product );
	}

	return array(
		'count'   => count( $ids ),
		'last_id' => $ids ? (int) end( $ids ) : (int) $after_id,
	);
}

/**
 * Add the page below WooCommerce.
 */
function sella_us_register_admin_page() {
	add_submenu_page(
		'woocommerce',
		'מלאי בלתי מוגבל',
		'מלאי בלתי מוגבל',
		'manage_woocommerce',
		SELLA_US_ADMIN_SLUG,
		'sella_us_render_admin_page'
	);
}
add_action( 'admin_menu', 'sella_us_register_admin_page', 56 );

/**
 * Run a batch, then send the browser back to the page, which continues until done.
 * The cursor only moves forward, so a product that cannot change never loops.
 */
function sella_us_handle_run() {
	if ( ! is_admin() || empty( $_POST['sella_us_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	check_admin_referer( 'sella_us_run' );

	$after   = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
	$updated = isset( $_POST['updated'] ) ? absint( $_POST['updated'] ) : 0;
	$batch   = sella_us_run_batch( $after );

	$args = array( 'updated' => $updated + $batch['count'] );
	if ( SELLA_US_BATCH_SIZE === $batch['count'] ) {
		$args['after'] = $batch['last_id'];
	}

	wp_safe_redirect( sella_us_admin_url( $args ) );
	exit;
}
add_action( 'admin_init', 'sella_us_handle_run' );

/**
 * Render the page: product list and run button, or batch progress.
 */
function sella_us_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$after   = isset( $_GET['after'] ) ? absint( $_GET['after'] ) : 0;
	$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : null;
	$running = $after && sella_us_pending_product_ids( $after, 1 );
	$pending = $running ? array() : sella_us_pending_product_ids();
	$options = wc_get_product_stock_status_options();
	?>
	<div class="wrap" dir="rtl">
		<h1>מלאי בלתי מוגבל</h1>
		<p>כל מוצר שאינו טיוטה, כולל הווריאציות שלו, יעבור ל"ללא ניהול מלאי" ולסטטוס "במלאי". מיילים על חזרה למלאי לא יישלחו.</p>

		<?php if ( $running ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( sprintf( 'מעדכן... טופלו %d מוצרים עד עכשיו. נא לא לסגור את הדף.', (int) $updated ) ); ?></p></div>
			<form method="post" id="sella-us-continue">
				<?php wp_nonce_field( 'sella_us_run' ); ?>
				<input type="hidden" name="sella_us_action" value="run" />
				<input type="hidden" name="after" value="<?php echo esc_attr( (string) $after ); ?>" />
				<input type="hidden" name="updated" value="<?php echo esc_attr( (string) (int) $updated ); ?>" />
				<button type="submit" class="button">המשך</button>
			</form>
			<script>document.getElementById( 'sella-us-continue' ).submit();</script>
		<?php else : ?>
			<?php if ( null !== $updated ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( 'הסתיים: טופלו %d מוצרים.', $updated ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $pending ) ) : ?>
				<p><strong>כל המוצרים שאינם טיוטה כבר במלאי בלתי מוגבל.</strong></p>
			<?php else : ?>
				<?php if ( null !== $updated ) : ?>
					<div class="notice notice-warning"><p><?php echo esc_html( sprintf( '%d מוצרים לא השתנו, למשל מוצר מקובץ שהמלאי שלו נקבע לפי מוצרים אחרים.', count( $pending ) ) ); ?></p></div>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( 'sella_us_run' ); ?>
					<input type="hidden" name="sella_us_action" value="run" />
					<p>
						<button type="submit" class="button button-primary" onclick="return confirm('לעדכן את כל המוצרים ברשימה למלאי בלתי מוגבל?');">
							<?php echo esc_html( sprintf( 'עדכון %d מוצרים למלאי בלתי מוגבל', count( $pending ) ) ); ?>
						</button>
					</p>
				</form>

				<table class="widefat striped">
					<thead><tr><th>מוצר</th><th>סטטוס פרסום</th><th>מלאי כרגע</th></tr></thead>
					<tbody>
					<?php
					foreach ( $pending as $id ) :
						$product = wc_get_product( $id );
						if ( ! $product ) {
							continue;
						}

						$post_status = get_post_status_object( $product->get_status() );
						$stock       = $product->get_stock_status( 'edit' );
						$stock       = isset( $options[ $stock ] ) ? $options[ $stock ] : $stock;
						if ( $product->get_manage_stock( 'edit' ) ) {
							$stock .= sprintf( ' · %s יח׳', wc_stock_amount( $product->get_stock_quantity( 'edit' ) ) );
						}
						if ( $product->is_type( 'variable' ) ) {
							$stock .= ' · כולל וריאציות';
						}
						?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( wp_strip_all_tags( $product->get_name() ) ); ?></a></td>
							<td><?php echo esc_html( $post_status ? $post_status->label : $product->get_status() ); ?></td>
							<td><?php echo esc_html( $stock ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
