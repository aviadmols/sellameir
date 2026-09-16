<?php
/**
 * Banner slider above the shop grid, managed next to the upsell popups.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_BANNERS_OPTION', 'sella_shop_banners' );
define( 'SELLA_BANNERS_SETTINGS', 'sella_shop_banners_settings' );

/**
 * Stored banners, cleaned up.
 *
 * @param bool $only_enabled Skip switched-off banners.
 * @return array<int, array<string, mixed>>
 */
function sella_banners_get( $only_enabled = false ) {
	$banners = get_option( SELLA_BANNERS_OPTION, array() );
	$banners = is_array( $banners ) ? $banners : array();
	$clean   = array();

	foreach ( $banners as $banner ) {
		$image_id = absint( $banner['image_id'] ?? 0 );
		if ( ! $image_id ) {
			continue;
		}
		if ( $only_enabled && empty( $banner['enabled'] ) ) {
			continue;
		}

		$clean[] = array(
			'image_id'  => $image_id,
			'mobile_id' => absint( $banner['mobile_id'] ?? 0 ),
			'link'      => esc_url_raw( $banner['link'] ?? '' ),
			'alt'       => sanitize_text_field( $banner['alt'] ?? '' ),
			'enabled'   => ! empty( $banner['enabled'] ),
		);
	}

	return $clean;
}

/**
 * Slider settings.
 *
 * @return array<string, int>
 */
function sella_banners_settings() {
	$settings = get_option( SELLA_BANNERS_SETTINGS, array() );
	$settings = is_array( $settings ) ? $settings : array();

	return array(
		'autoplay' => isset( $settings['autoplay'] ) ? max( 0, min( 30, absint( $settings['autoplay'] ) ) ) : 6,
	);
}

/**
 * Banner management screen, under the upsell menu.
 */
function sella_banners_register_admin_page() {
	add_submenu_page(
		'sella-upsell-popups',
		'באנרים בדף החנות',
		'באנרים בדף החנות',
		'manage_woocommerce',
		'sella-shop-banners',
		'sella_banners_render_admin_page'
	);
}
add_action( 'admin_menu', 'sella_banners_register_admin_page', 20 );

/**
 * Save the banner list.
 */
function sella_banners_handle_admin_actions() {
	if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) || empty( $_POST['sella_banners_action'] ) ) {
		return;
	}

	if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sella_banners_manage' ) ) {
		return;
	}

	$rows    = isset( $_POST['sella_banner'] ) && is_array( $_POST['sella_banner'] ) ? wp_unslash( $_POST['sella_banner'] ) : array();
	$banners = array();

	foreach ( $rows as $row ) {
		$image_id = absint( $row['image_id'] ?? 0 );
		if ( ! $image_id ) {
			continue;
		}

		$banners[] = array(
			'image_id'  => $image_id,
			'mobile_id' => absint( $row['mobile_id'] ?? 0 ),
			'link'      => esc_url_raw( $row['link'] ?? '' ),
			'alt'       => sanitize_text_field( $row['alt'] ?? '' ),
			'enabled'   => ! empty( $row['enabled'] ),
		);
	}

	update_option( SELLA_BANNERS_OPTION, $banners, false );
	update_option(
		SELLA_BANNERS_SETTINGS,
		array( 'autoplay' => max( 0, min( 30, absint( $_POST['sella_banners_autoplay'] ?? 6 ) ) ) ),
		false
	);

	wp_safe_redirect( admin_url( 'admin.php?page=sella-shop-banners&saved=1' ) );
	exit;
}
add_action( 'admin_init', 'sella_banners_handle_admin_actions' );

/**
 * One editable banner row.
 *
 * @param array  $banner Banner data.
 * @param string $index  Row index, or __i__ for the template row.
 */
function sella_banners_render_row( $banner, $index ) {
	$image_id  = absint( $banner['image_id'] ?? 0 );
	$mobile_id = absint( $banner['mobile_id'] ?? 0 );
	$image     = $image_id ? wp_get_attachment_image_url( $image_id, 'medium_large' ) : '';
	$mobile    = $mobile_id ? wp_get_attachment_image_url( $mobile_id, 'medium' ) : '';
	?>
	<div class="sella-banner-row" data-banner-row>
		<div class="sella-banner-row__handle" title="גררו כדי לשנות סדר">⣿</div>

		<div class="sella-banner-row__media">
			<div class="sella-banner-pick" data-target="image">
				<span class="sella-banner-pick__label">באנר לדסקטופ</span>
				<div class="sella-banner-pick__preview <?php echo $image ? '' : 'is-empty'; ?>">
					<?php if ( $image ) : ?><img src="<?php echo esc_url( $image ); ?>" alt="" /><?php endif; ?>
				</div>
				<input type="hidden" name="sella_banner[<?php echo esc_attr( $index ); ?>][image_id]" value="<?php echo esc_attr( (string) $image_id ); ?>" />
				<button type="button" class="button sella-banner-pick__button">בחירת תמונה</button>
				<button type="button" class="button-link sella-banner-pick__clear" <?php echo $image ? '' : 'hidden'; ?>>הסרה</button>
			</div>

			<div class="sella-banner-pick" data-target="mobile">
				<span class="sella-banner-pick__label">באנר למובייל (אופציונלי)</span>
				<div class="sella-banner-pick__preview <?php echo $mobile ? '' : 'is-empty'; ?>">
					<?php if ( $mobile ) : ?><img src="<?php echo esc_url( $mobile ); ?>" alt="" /><?php endif; ?>
				</div>
				<input type="hidden" name="sella_banner[<?php echo esc_attr( $index ); ?>][mobile_id]" value="<?php echo esc_attr( (string) $mobile_id ); ?>" />
				<button type="button" class="button sella-banner-pick__button">בחירת תמונה</button>
				<button type="button" class="button-link sella-banner-pick__clear" <?php echo $mobile ? '' : 'hidden'; ?>>הסרה</button>
			</div>
		</div>

		<div class="sella-banner-row__fields">
			<label>קישור בלחיצה
				<input type="url" class="regular-text" name="sella_banner[<?php echo esc_attr( $index ); ?>][link]" value="<?php echo esc_attr( $banner['link'] ?? '' ); ?>" placeholder="https://sellameir.ussl.co/product/..." />
			</label>
			<label>טקסט חלופי (נגישות)
				<input type="text" class="regular-text" name="sella_banner[<?php echo esc_attr( $index ); ?>][alt]" value="<?php echo esc_attr( $banner['alt'] ?? '' ); ?>" placeholder="למשל: מבצע ספרי מופת" />
			</label>
			<label class="sella-banner-row__toggle">
				<input type="checkbox" name="sella_banner[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $banner['enabled'] ) ); ?> /> באנר פעיל
			</label>
			<button type="button" class="button-link sella-banner-row__remove">מחיקת הבאנר</button>
		</div>
	</div>
	<?php
}

/**
 * Render the management screen.
 */
function sella_banners_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$banners  = sella_banners_get();
	$settings = sella_banners_settings();
	?>
	<div class="wrap sella-banners-admin" dir="rtl">
		<h1>באנרים בדף החנות</h1>
		<p>הבאנרים מוצגים כסליידר בראש דף החנות, מעל רשימת הספרים. גודל מומלץ לדסקטופ: 2000×352 פיקסלים. למובייל אפשר להעלות גרסה מרובעת יותר (למשל 1000×700) כדי שהטקסט יישאר קריא.</p>

		<?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>הבאנרים נשמרו.</p></div><?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'sella_banners_manage' ); ?>
			<input type="hidden" name="sella_banners_action" value="save" />

			<div class="sella-banner-rows" data-banner-rows>
				<?php foreach ( $banners as $index => $banner ) : ?>
					<?php sella_banners_render_row( $banner, (string) $index ); ?>
				<?php endforeach; ?>
			</div>

			<p><button type="button" class="button button-secondary" data-banner-add>הוספת באנר</button></p>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="sella_banners_autoplay">מעבר אוטומטי</label></th>
					<td>
						<input type="number" min="0" max="30" id="sella_banners_autoplay" name="sella_banners_autoplay" value="<?php echo esc_attr( (string) $settings['autoplay'] ); ?>" style="width:90px;" /> שניות
						<p class="description">0 = בלי מעבר אוטומטי. הסליידר תמיד ניתן להחלקה ביד ובחיצים.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'שמירת באנרים' ); ?>
		</form>

		<script type="text/html" id="tmpl-sella-banner-row">
			<?php sella_banners_render_row( array(), '__i__' ); ?>
		</script>
	</div>
	<?php
}

/**
 * Admin assets for the banner screen.
 *
 * @param string $hook Admin hook.
 */
function sella_banners_admin_assets( $hook ) {
	if ( false === strpos( $hook, 'sella-shop-banners' ) ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_style( 'sella-banners-admin', get_stylesheet_directory_uri() . '/assets/css/sella-banners-admin.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-banners-admin', get_stylesheet_directory_uri() . '/assets/js/sella-banners-admin.js', array( 'jquery', 'jquery-ui-sortable' ), HELLO_ELEMENTOR_CHILD_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'sella_banners_admin_assets' );

/**
 * Print the slider markup, hidden, for the script to place above the grid.
 */
function sella_banners_render_frontend() {
	$banners = sella_banners_get( true );
	if ( empty( $banners ) ) {
		return;
	}

	$settings = sella_banners_settings();
	?>
	<div class="sella-banners" data-sella-banners data-autoplay="<?php echo esc_attr( (string) $settings['autoplay'] ); ?>" hidden>
		<div class="sella-banners__viewport">
			<div class="sella-banners__track">
				<?php
				foreach ( $banners as $position => $banner ) {
					$image = wp_get_attachment_image_src( $banner['image_id'], 'full' );
					if ( ! $image ) {
						continue;
					}
					$mobile  = $banner['mobile_id'] ? wp_get_attachment_image_src( $banner['mobile_id'], 'full' ) : null;
					$alt     = $banner['alt'] ? $banner['alt'] : get_post_meta( $banner['image_id'], '_wp_attachment_image_alt', true );
					$tag     = $banner['link'] ? 'a' : 'div';
					$href    = $banner['link'] ? ' href="' . esc_url( $banner['link'] ) . '"' : '';
					$loading = $position ? ' loading="lazy"' : '';
					?>
					<<?php echo esc_html( $tag ) . $href; ?> class="sella-banners__slide">
						<picture>
							<?php if ( $mobile ) : ?>
								<source media="(max-width: 767px)" srcset="<?php echo esc_url( $mobile[0] ); ?>" width="<?php echo esc_attr( (string) $mobile[1] ); ?>" height="<?php echo esc_attr( (string) $mobile[2] ); ?>" />
							<?php endif; ?>
							<img src="<?php echo esc_url( $image[0] ); ?>" width="<?php echo esc_attr( (string) $image[1] ); ?>" height="<?php echo esc_attr( (string) $image[2] ); ?>" alt="<?php echo esc_attr( $alt ); ?>"<?php echo $loading; // phpcs:ignore ?> />
						</picture>
					</<?php echo esc_html( $tag ); ?>>
					<?php
				}
				?>
			</div>
		</div>

		<?php if ( count( $banners ) > 1 ) : ?>
			<button type="button" class="sella-banners__arrow sella-banners__arrow--prev" data-banner-prev aria-label="הקודם">&#8594;</button>
			<button type="button" class="sella-banners__arrow sella-banners__arrow--next" data-banner-next aria-label="הבא">&#8592;</button>
			<div class="sella-banners__dots" data-banner-dots></div>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'wp_footer', 'sella_banners_render_frontend', 5 );

/**
 * Frontend assets, on the shop and product archives only.
 */
function sella_banners_frontend_assets() {
	if ( ! function_exists( 'is_shop' ) || ( ! is_shop() && ! is_product_taxonomy() ) ) {
		return;
	}
	if ( empty( sella_banners_get( true ) ) ) {
		return;
	}

	wp_enqueue_style( 'sella-banners', get_stylesheet_directory_uri() . '/assets/css/sella-banners.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-banners', get_stylesheet_directory_uri() . '/assets/js/sella-banners.js', array(), HELLO_ELEMENTOR_CHILD_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'sella_banners_frontend_assets', 30 );
