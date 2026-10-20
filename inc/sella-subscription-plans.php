<?php
/**
 * Subscription plans table on the subscription page (/subscription/): the
 * "מה מקבלים המנויים?" column and a card per plan, edited from one admin screen.
 *
 * The table was hand-written HTML in an Elementor HTML widget. That widget's
 * output is now replaced with the table built from the saved settings, so the
 * page needs no change in Elementor; the [sella_subscription_plans] shortcode
 * prints the same table anywhere else. The markup and class names are the
 * ones the page's own Elementor CSS styles.
 *
 * Each benefit is one row: its full text in the desktop column, and a short
 * text next to the check mark on the cards, which is what mobile shows. A plan
 * card has a row for every benefit, in the same order, empty where the plan
 * does not include it, so the check marks line up with the column.
 *
 * Admin: מסלולי מנוי (?page=sella-subscription-plans).
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_PLANS_OPTION', 'sella_subscription_plans' );
define( 'SELLA_PLANS_SLUG', 'sella-subscription-plans' );
define( 'SELLA_PLANS_DEFAULT_CURRENCY', 'ש״ח' );
define( 'SELLA_PLANS_DEFAULT_PERIOD', 'בחודש' );
define( 'SELLA_PLANS_DEFAULT_BUTTON', 'בחירת מנוי זה!' );

/**
 * ---------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------------
 */

/**
 * The fields of one plan, empty.
 *
 * @return array<string, mixed>
 */
function sella_plans_plan_defaults() {
	return array(
		'name'       => '',
		'price'      => '',
		'currency'   => SELLA_PLANS_DEFAULT_CURRENCY,
		'period'     => SELLA_PLANS_DEFAULT_PERIOD,
		'product_id' => 0,
		'url'        => '',
		'button'     => '',
		'badge'      => '',
		'style'      => 'white',
		'features'   => array(),
	);
}

/**
 * The table as it was written by hand, the starting point of the settings.
 *
 * @return array<string, mixed>
 */
function sella_plans_defaults() {
	$plan = sella_plans_plan_defaults();

	return array(
		'title'      => 'מה מקבלים המנויים?',
		'check_icon' => content_url( '/uploads/2026/07/check-3.svg' ),
		'features'   => array(
			array(
				'id'    => 'f1',
				'text'  => '2 ספרי מופת מתורגמים בכל רבעון, עד הבית, לפני כולם',
				'short' => '2 ספרי מופת מתורגמים בכל רבעון',
			),
			array(
				'id'    => 'f2',
				'text'  => 'השילוח - המגזין הרעיוני הטוב בישראל',
				'short' => '',
			),
			array(
				'id'    => 'f3',
				'text'  => 'ספר ישראלי פורץ דרך בכל רבעון',
				'short' => '',
			),
			array(
				'id'    => 'f4',
				'text'  => 'שני ספרים חדשים מבית הוצאת "סלע מאיר" בכל משלוח',
				'short' => 'שני ספרים חדשים מבית הוצאת "סלע מאיר"',
			),
			array(
				'id'    => 'f5',
				'text'  => 'גישה לכל תכני שומר סף',
				'short' => '',
			),
		),
		'plans'      => array(
			array_merge(
				$plan,
				array(
					'name'       => 'מנוי שיבולת',
					'price'      => '39',
					'product_id' => 2666,
					'badge'      => 'הכי פופולארי!',
					'style'      => 'purple',
					'features'   => array( 'f1', 'f2', 'f3' ),
				)
			),
			array_merge(
				$plan,
				array(
					'name'       => 'מנוי שיבולת פלוס',
					'price'      => '69',
					'product_id' => 2675,
					'features'   => array( 'f1', 'f2', 'f3', 'f4' ),
				)
			),
			array_merge(
				$plan,
				array(
					'name'       => 'שומר סף ושיבולת פלוס',
					'price'      => '69',
					'product_id' => 2676,
					'features'   => array( 'f1', 'f2', 'f3', 'f4', 'f5' ),
				)
			),
		),
	);
}

/**
 * The saved table, or the hand-written one until the screen is first saved.
 *
 * @return array<string, mixed>
 */
function sella_plans_get() {
	$saved    = get_option( SELLA_PLANS_OPTION, null );
	$defaults = sella_plans_defaults();

	if ( ! is_array( $saved ) ) {
		return $defaults;
	}

	$table          = wp_parse_args( $saved, $defaults );
	$table['plans'] = array_map(
		function ( $plan ) {
			return wp_parse_args( is_array( $plan ) ? $plan : array(), sella_plans_plan_defaults() );
		},
		is_array( $table['plans'] ) ? $table['plans'] : array()
	);

	return $table;
}

/**
 * Read the table from the admin form.
 *
 * @param array $source Request data.
 * @return array<string, mixed>
 */
function sella_plans_sanitize( $source ) {
	$source   = wp_unslash( $source );
	$features = array();
	$ids      = array();

	foreach ( (array) ( $source['features'] ?? array() ) as $row ) {
		$text  = sanitize_text_field( $row['text'] ?? '' );
		$short = sanitize_text_field( $row['short'] ?? '' );
		if ( '' === $text && '' === $short ) {
			continue;
		}

		$id = sanitize_key( $row['id'] ?? '' );
		if ( '' === $id || isset( $ids[ $id ] ) ) {
			$id = 'f' . wp_generate_password( 8, false, false );
		}
		$ids[ $id ] = true;

		$features[] = array(
			'id'    => $id,
			'text'  => $text,
			'short' => $short,
		);
	}

	$plans = array();
	foreach ( (array) ( $source['plans'] ?? array() ) as $row ) {
		$name = sanitize_text_field( $row['name'] ?? '' );
		if ( '' === $name ) {
			continue;
		}

		$included = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) ( $row['features'] ?? array() ) ),
				function ( $id ) use ( $ids ) {
					return isset( $ids[ $id ] );
				}
			)
		);

		$plans[] = array(
			'name'       => $name,
			'price'      => sanitize_text_field( $row['price'] ?? '' ),
			'currency'   => sanitize_text_field( $row['currency'] ?? '' ),
			'period'     => sanitize_text_field( $row['period'] ?? '' ),
			'product_id' => absint( $row['product_id'] ?? 0 ),
			'url'        => esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) ),
			'button'     => sanitize_text_field( $row['button'] ?? '' ),
			'badge'      => sanitize_text_field( $row['badge'] ?? '' ),
			'style'      => 'purple' === ( $row['style'] ?? '' ) ? 'purple' : 'white',
			'features'   => $included,
		);
	}

	return array(
		'title'      => sanitize_text_field( $source['title'] ?? '' ),
		'check_icon' => esc_url_raw( trim( (string) ( $source['check_icon'] ?? '' ) ) ),
		'features'   => $features,
		'plans'      => $plans,
	);
}

/**
 * A plan's price as shown: the one typed in, or else its product's price,
 * whole numbers without ".00".
 *
 * @param array $plan Plan.
 * @return string
 */
function sella_plans_price( $plan ) {
	if ( '' !== $plan['price'] ) {
		return $plan['price'];
	}

	$product = $plan['product_id'] ? wc_get_product( $plan['product_id'] ) : null;
	if ( ! $product ) {
		return '';
	}

	$price = (float) wc_get_price_to_display( $product );
	return floor( $price ) === $price ? (string) (int) $price : number_format( $price, 2, '.', '' );
}

/**
 * Where a plan's button leads: its own link, or else to checkout with its
 * product in the cart.
 *
 * @param array $plan Plan.
 * @return string
 */
function sella_plans_link( $plan ) {
	if ( '' !== $plan['url'] ) {
		return $plan['url'];
	}
	if ( ! $plan['product_id'] ) {
		return '';
	}

	return add_query_arg(
		array(
			'add-to-cart' => $plan['product_id'],
			'quantity'    => 1,
		),
		wc_get_checkout_url()
	);
}

/**
 * ---------------------------------------------------------------------------
 * The table
 * ---------------------------------------------------------------------------
 */

/**
 * The table's HTML. Rows are joined without whitespace: the page CSS hides an
 * empty card row on mobile with :empty.
 *
 * @return string
 */
function sella_plans_render() {
	$table = sella_plans_get();
	if ( empty( $table['plans'] ) ) {
		return '';
	}

	$html  = '<div class="pricing-table-container">';
	$html .= '<div class="pricing-features-column">';
	$html .= '<div class="features-header"><h3 class="features-main-title">' . esc_html( $table['title'] ) . '</h3></div>';
	$html .= '<div class="features-list">';
	foreach ( $table['features'] as $feature ) {
		$html .= '<div class="feature-text-row"><span>' . esc_html( '' !== $feature['text'] ? $feature['text'] : $feature['short'] ) . '</span></div>';
	}
	$html .= '</div></div>';

	foreach ( $table['plans'] as $plan ) {
		$link   = sella_plans_link( $plan );
		$button = '' !== $plan['button'] ? $plan['button'] : SELLA_PLANS_DEFAULT_BUTTON;
		$class  = 'purple' === $plan['style'] ? 'card-transparent-purple highlighted' : 'card-transparent-white';

		$html .= '<div class="pricing-card ' . esc_attr( $class ) . '">';
		if ( '' !== $plan['badge'] ) {
			$html .= '<span class="badge">' . esc_html( $plan['badge'] ) . '</span>';
		}
		$html .= '<div class="card-header">';
		$html .= '<h3 class="card-title">' . esc_html( $plan['name'] ) . '</h3>';
		$html .= '<div class="price-wrapper">';
		$html .= '<span class="price-amount">' . esc_html( sella_plans_price( $plan ) ) . '</span>';
		if ( '' !== $plan['currency'] ) {
			$html .= '<span class="currency">' . esc_html( $plan['currency'] ) . '</span>';
		}
		if ( '' !== $plan['period'] ) {
			$html .= '<span class="period">' . esc_html( $plan['period'] ) . '</span>';
		}
		$html .= '</div>';
		if ( '' !== $link ) {
			$html .= '<a href="' . esc_url( $link ) . '" class="select-button">' . esc_html( $button ) . '</a>';
		}
		$html .= '</div>';

		$html .= '<div class="card-features">';
		foreach ( $table['features'] as $feature ) {
			if ( ! in_array( $feature['id'], (array) $plan['features'], true ) ) {
				$html .= '<div class="feature-row"></div>';
				continue;
			}
			$html .= '<div class="feature-row">';
			if ( '' !== $table['check_icon'] ) {
				$html .= '<img src="' . esc_url( $table['check_icon'] ) . '" class="check-icon" alt="כלול" decoding="async" />';
			}
			$html .= '<span class="mobile-feature-text">' . esc_html( '' !== $feature['short'] ? $feature['short'] : $feature['text'] ) . '</span>';
			$html .= '</div>';
		}
		$html .= '</div></div>';
	}

	$html .= '</div>';

	return $html;
}

add_shortcode( 'sella_subscription_plans', 'sella_plans_render' );

/**
 * Put the table in place of the Elementor HTML widget that held it by hand.
 *
 * @param string                 $content Widget output.
 * @param \Elementor\Widget_Base $widget  Widget.
 * @return string
 */
function sella_plans_replace_html_widget( $content, $widget ) {
	if ( ! is_object( $widget ) || 'html' !== $widget->get_name() || false === strpos( $content, 'pricing-table-container' ) ) {
		return $content;
	}

	$table = sella_plans_render();
	return '' !== $table ? $table : $content;
}
add_filter( 'elementor/widget/render_content', 'sella_plans_replace_html_widget', 10, 2 );

/**
 * ---------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------------
 */

/**
 * The screen, in the admin menu next to the upsell popups.
 */
function sella_plans_register_admin_page() {
	add_menu_page(
		'מסלולי מנוי',
		'מסלולי מנוי',
		'manage_woocommerce',
		SELLA_PLANS_SLUG,
		'sella_plans_render_admin_page',
		'dashicons-id-alt',
		58.2
	);
}
add_action( 'admin_menu', 'sella_plans_register_admin_page' );

/**
 * Save the screen.
 */
function sella_plans_handle_save() {
	if ( ! is_admin() || empty( $_POST['sella_plans_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	check_admin_referer( 'sella_plans_save' );

	// Saving an option from the admin clears the page cache (inc/sella-page-cache.php).
	update_option( SELLA_PLANS_OPTION, sella_plans_sanitize( $_POST ), false );

	// Elementor's element cache may hold the widget as it rendered before.
	delete_post_meta_by_key( '_elementor_element_cache' );

	wp_safe_redirect( add_query_arg( 'notice', 'saved', admin_url( 'admin.php?page=' . SELLA_PLANS_SLUG ) ) );
	exit;
}
add_action( 'admin_init', 'sella_plans_handle_save' );

/**
 * Admin assets: WooCommerce's product search, drag to reorder, the form script.
 *
 * @param string $hook Admin hook.
 */
function sella_plans_admin_assets( $hook ) {
	if ( false === strpos( $hook, SELLA_PLANS_SLUG ) ) {
		return;
	}

	$dir  = get_stylesheet_directory();
	$base = get_stylesheet_directory_uri();

	wp_enqueue_style( 'woocommerce_admin_styles' );
	wp_enqueue_style( 'sella-subscription-plans-admin', $base . '/assets/css/sella-subscription-plans-admin.css', array( 'woocommerce_admin_styles' ), (string) filemtime( $dir . '/assets/css/sella-subscription-plans-admin.css' ) );
	wp_enqueue_script( 'sella-subscription-plans-admin', $base . '/assets/js/sella-subscription-plans-admin.js', array( 'jquery', 'jquery-ui-sortable', 'wc-enhanced-select' ), (string) filemtime( $dir . '/assets/js/sella-subscription-plans-admin.js' ), true );
	wp_localize_script(
		'sella-subscription-plans-admin',
		'sellaPlansAdmin',
		array(
			'untitled'      => 'שורה בלי טקסט',
			'newPlan'       => 'מסלול חדש',
			'confirmRemove' => 'להסיר את המסלול? הוא יימחק כשתשמרו.',
		)
	);
}
add_action( 'admin_enqueue_scripts', 'sella_plans_admin_assets' );

/**
 * One benefit row of the form.
 *
 * @param string $index   Field index, or "__i__" in the template.
 * @param array  $feature Benefit.
 */
function sella_plans_admin_feature_row( $index, $feature ) {
	$name = 'features[' . $index . ']';
	?>
	<li class="sella-plans-feature">
		<span class="sella-plans-handle dashicons dashicons-move" title="גררו כדי לשנות את הסדר"></span>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $feature['id'] ); ?>" data-field="id" />
		<label class="sella-plans-feature__text">
			<span>הטקסט בעמודה (מחשב)</span>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[text]" value="<?php echo esc_attr( $feature['text'] ); ?>" data-field="text" />
		</label>
		<label class="sella-plans-feature__short">
			<span>טקסט קצר בכרטיס (נייד)</span>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[short]" value="<?php echo esc_attr( $feature['short'] ); ?>" placeholder="ריק = כמו הטקסט בעמודה" data-field="short" />
		</label>
		<button type="button" class="button-link sella-plans-remove" data-remove="feature" aria-label="הסרת השורה">&times;</button>
	</li>
	<?php
}

/**
 * One plan card of the form.
 *
 * @param string $index    Field index, or "__i__" in the template.
 * @param array  $plan     Plan.
 * @param array  $features All benefits, in order.
 */
function sella_plans_admin_plan_card( $index, $plan, $features ) {
	$name    = 'plans[' . $index . ']';
	$product = $plan['product_id'] ? wc_get_product( $plan['product_id'] ) : null;
	?>
	<div class="sella-plans-card">
		<div class="sella-plans-card__head" title="גררו כדי לשנות את הסדר">
			<span class="dashicons dashicons-move"></span>
			<strong class="sella-plans-card__title"><?php echo esc_html( '' !== $plan['name'] ? $plan['name'] : 'מסלול חדש' ); ?></strong>
			<button type="button" class="button-link sella-plans-remove" data-remove="plan">הסרה</button>
		</div>

		<label class="sella-plans-field">
			<span>שם המסלול</span>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $plan['name'] ); ?>" data-field="name" required />
		</label>

		<div class="sella-plans-field sella-plans-field--price">
			<label>
				<span>מחיר</span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[price]" value="<?php echo esc_attr( $plan['price'] ); ?>" inputmode="decimal" placeholder="מהמוצר" />
			</label>
			<label>
				<span>מטבע</span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[currency]" value="<?php echo esc_attr( $plan['currency'] ); ?>" />
			</label>
			<label>
				<span>תקופה</span>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[period]" value="<?php echo esc_attr( $plan['period'] ); ?>" />
			</label>
		</div>
		<p class="description">מחיר ריק = המחיר של המוצר בחנות.</p>

		<label class="sella-plans-field">
			<span>המוצר שנכנס לסל</span>
			<select class="wc-product-search" name="<?php echo esc_attr( $name ); ?>[product_id]" style="width:100%;" data-action="woocommerce_json_search_products_and_variations" data-placeholder="חפשו את מוצר המנוי" data-allow_clear="true">
				<?php if ( $product ) : ?>
					<option value="<?php echo esc_attr( (string) $product->get_id() ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
				<?php endif; ?>
			</select>
		</label>

		<label class="sella-plans-field">
			<span>טקסט הכפתור</span>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[button]" value="<?php echo esc_attr( $plan['button'] ); ?>" placeholder="<?php echo esc_attr( SELLA_PLANS_DEFAULT_BUTTON ); ?>" />
		</label>

		<label class="sella-plans-field">
			<span>קישור אחר לכפתור</span>
			<input type="url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $plan['url'] ); ?>" dir="ltr" placeholder="ריק = לתשלום עם המוצר בסל" />
		</label>

		<label class="sella-plans-field">
			<span>תגית מעל הכרטיס</span>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[badge]" value="<?php echo esc_attr( $plan['badge'] ); ?>" placeholder="למשל: הכי פופולארי!" />
		</label>

		<fieldset class="sella-plans-field sella-plans-style">
			<legend>עיצוב הכרטיס</legend>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[style]" value="white" <?php checked( 'purple' !== $plan['style'] ); ?> /> לבן</label>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[style]" value="purple" <?php checked( 'purple' === $plan['style'] ); ?> /> סגול מודגש, כפתור צהוב</label>
		</fieldset>

		<fieldset class="sella-plans-field">
			<legend>מה כלול במסלול</legend>
			<div class="sella-plans-includes" data-name="<?php echo esc_attr( $name ); ?>[features][]">
				<?php foreach ( $features as $feature ) : ?>
					<label class="sella-plans-include">
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[features][]" value="<?php echo esc_attr( $feature['id'] ); ?>" <?php checked( in_array( $feature['id'], (array) $plan['features'], true ) ); ?> />
						<span><?php echo esc_html( '' !== $feature['text'] ? $feature['text'] : $feature['short'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
	</div>
	<?php
}

/**
 * The screen.
 */
function sella_plans_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$table    = sella_plans_get();
	$page     = get_page_by_path( 'subscription' );
	$page_url = $page ? get_permalink( $page ) : home_url( '/subscription/' );
	$notice   = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap sella-plans-admin" dir="rtl">
		<h1 class="wp-heading-inline">מסלולי מנוי</h1>
		<a class="page-title-action" href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener">צפייה בעמוד המנויים</a>
		<hr class="wp-header-end" />

		<?php if ( 'saved' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>הטבלה נשמרה. היא כבר מעודכנת בעמוד.</p></div>
		<?php endif; ?>

		<p class="sella-plans-lead">
			הטבלה שמשווה בין המסלולים בעמוד המנויים. בכל שורה יש יתרון אחד, ובכל מסלול מסמנים אילו יתרונות כלולים בו.
			במחשב היתרונות מופיעים בעמודה בצד, ובכל כרטיס יש וי בשורה של יתרון שכלול בו. בנייד אין עמודה, וכל כרטיס מציג ליד הווי את הטקסט הקצר.
			אפשר להציג את הטבלה גם בעמוד אחר, עם הקוד <code>[sella_subscription_plans]</code> בווידג'ט "קוד קצר" של Elementor.
		</p>

		<form method="post" class="sella-plans-form">
			<?php wp_nonce_field( 'sella_plans_save' ); ?>
			<input type="hidden" name="sella_plans_action" value="save" />

			<section class="sella-plans-section">
				<h2>מה מקבלים המנויים</h2>
				<label class="sella-plans-field">
					<span>כותרת העמודה</span>
					<input type="text" name="title" value="<?php echo esc_attr( $table['title'] ); ?>" class="regular-text" />
				</label>
				<ul class="sella-plans-features">
					<?php
					foreach ( $table['features'] as $i => $feature ) {
						sella_plans_admin_feature_row( (string) $i, $feature );
					}
					?>
				</ul>
				<button type="button" class="button" data-add="feature">+ הוספת שורה</button>
			</section>

			<section class="sella-plans-section">
				<h2>המסלולים</h2>
				<p class="description">הסדר כאן הוא הסדר בעמוד, מימין לשמאל במחשב ומלמעלה למטה בנייד. גררו כרטיס בכותרת שלו כדי להזיז אותו.</p>
				<div class="sella-plans-cards">
					<?php
					foreach ( $table['plans'] as $i => $plan ) {
						sella_plans_admin_plan_card( (string) $i, $plan, $table['features'] );
					}
					?>
				</div>
				<button type="button" class="button" data-add="plan">+ הוספת מסלול</button>
			</section>

			<section class="sella-plans-section">
				<h2>אייקון הווי</h2>
				<label class="sella-plans-field">
					<span>כתובת התמונה</span>
					<input type="url" name="check_icon" value="<?php echo esc_attr( $table['check_icon'] ); ?>" class="large-text" dir="ltr" />
				</label>
			</section>

			<?php submit_button( 'שמירת הטבלה' ); ?>
		</form>

		<script type="text/html" id="tmpl-sella-plans-feature">
			<?php sella_plans_admin_feature_row( '__i__', array( 'id' => '', 'text' => '', 'short' => '' ) ); ?>
		</script>
		<script type="text/html" id="tmpl-sella-plans-plan">
			<?php sella_plans_admin_plan_card( '__i__', sella_plans_plan_defaults(), array() ); ?>
		</script>
	</div>
	<?php
}
