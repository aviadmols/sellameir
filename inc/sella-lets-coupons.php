<?php
/**
 * Coupons that apply themselves for LETS subscribers.
 *
 * A checkbox on the coupon's General tab, "הפעלה אוטומטית למנויי LETS". At
 * the checkout, once the email typed in the billing details belongs to an
 * active LETS subscriber, every such coupon that fits the cart is applied by
 * itself; when the email changes to someone else's, it is taken off again.
 *
 * The coupon is for subscribers only: typed by hand, it is accepted only for
 * a subscriber's email, and the order is checked again when it is placed.
 * Its other settings (amount, products, minimum, expiry, usage limits) work
 * as on any coupon.
 *
 * "סוג מנוי" narrows it to subscribers of chosen plans: the subscription
 * products of the plans table (and any other subscription product). LETS
 * tells which products a subscriber's running plans were sold as; a plan
 * sold as a variation counts for its product. Left empty, any active
 * subscription counts. A LETS server too old to tell the products gives a
 * narrowed coupon to nobody.
 *
 * "Active subscriber" is LETS's answer (the lets-payplus-woocommerce plugin,
 * POST account/subscriber-status: a recurring plan, paid and running; paused,
 * failing and installment plans do not count), cached per email for five
 * minutes by the plugin. If LETS cannot be asked, the coupon is not given.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_LC_META', '_sella_lets_auto' );
define( 'SELLA_LC_PLANS_META', '_sella_lets_plans' );
define( 'SELLA_LC_CACHE', 'sella_lets_auto_coupons' );

/*
 * ------------------------------------------------------------------
 * The coupon editor
 * ------------------------------------------------------------------
 */

/**
 * The checkbox, on the General tab.
 *
 * @param int $coupon_id Coupon ID.
 */
function sella_lc_field( $coupon_id ) {
	woocommerce_wp_checkbox(
		array(
			'id'          => SELLA_LC_META,
			'label'       => 'הפעלה אוטומטית למנויי LETS',
			'description' => 'הקופון יופעל מעצמו בקופה כשהמייל שהוזן שייך למנוי פעיל ב-LETS. מי שאינו מנוי לא יוכל להשתמש בו.',
			'value'       => 'yes' === get_post_meta( $coupon_id, SELLA_LC_META, true ) ? 'yes' : 'no',
		)
	);

	$chosen = sella_lc_coupon_plans( $coupon_id );
	?>
	<p class="form-field">
		<label for="sella_lets_plans">סוג מנוי</label>
		<select id="sella_lets_plans" name="<?php echo esc_attr( SELLA_LC_PLANS_META ); ?>[]" class="wc-enhanced-select" multiple="multiple" style="width:50%" data-placeholder="כל מנוי פעיל">
			<?php foreach ( sella_lc_plan_options() as $product_id => $label ) : ?>
				<option value="<?php echo (int) $product_id; ?>" <?php selected( in_array( (int) $product_id, $chosen, true ) ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php echo wc_help_tip( 'הקופון יופעל רק למנויים פעילים של סוגי המנוי שנבחרו. בלי בחירה: לכל מנוי פעיל.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes the tip. ?>
	</p>
	<?php
}
add_action( 'woocommerce_coupon_options', 'sella_lc_field' );

/**
 * The plans a coupon can be narrowed to: product ID => name. The plans
 * table's plans first, by the name shown there, then other subscription
 * products.
 *
 * @return array<int, string>
 */
function sella_lc_plan_options() {
	$options = array();
	if ( function_exists( 'sella_plans_get' ) ) {
		foreach ( (array) sella_plans_get()['plans'] as $plan ) {
			$product_id = isset( $plan['product_id'] ) ? (int) $plan['product_id'] : 0;
			if ( $product_id && ! isset( $options[ $product_id ] ) ) {
				$product_name           = get_the_title( $product_id );
				$name                   = isset( $plan['name'] ) ? (string) $plan['name'] : '';
				$options[ $product_id ] = trim( $name . ( $product_name && $product_name !== $name ? ' – ' . $product_name : '' ) . ' (#' . $product_id . ')' );
			}
		}
	}
	if ( function_exists( 'sella_hs_ids' ) ) {
		foreach ( sella_hs_ids() as $product_id ) {
			if ( ! isset( $options[ $product_id ] ) && 'product' === get_post_type( $product_id ) && 'trash' !== get_post_status( $product_id ) ) {
				$options[ $product_id ] = get_the_title( $product_id ) . ' (#' . $product_id . ')';
			}
		}
	}
	return $options;
}

/**
 * The plans a coupon is narrowed to (empty: any subscription).
 *
 * @param int $coupon_id Coupon ID.
 * @return int[]
 */
function sella_lc_coupon_plans( $coupon_id ) {
	return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $coupon_id, SELLA_LC_PLANS_META, true ) ) ) );
}

/**
 * Save the checkbox and the plans.
 *
 * @param int $coupon_id Coupon ID.
 */
function sella_lc_save( $coupon_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the coupon form nonce.
	update_post_meta( $coupon_id, SELLA_LC_META, isset( $_POST[ SELLA_LC_META ] ) ? 'yes' : 'no' );
	$plans = isset( $_POST[ SELLA_LC_PLANS_META ] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST[ SELLA_LC_PLANS_META ] ) ) ) ) : array();
	// phpcs:enable
	update_post_meta( $coupon_id, SELLA_LC_PLANS_META, $plans );
	delete_transient( SELLA_LC_CACHE );
}
add_action( 'woocommerce_coupon_options_save', 'sella_lc_save' );
add_action( 'deleted_post', 'sella_lc_forget' );
add_action( 'trashed_post', 'sella_lc_forget' );
add_action( 'untrashed_post', 'sella_lc_forget' );
add_action( 'transition_post_status', 'sella_lc_forget_on_status', 10, 3 );

/**
 * A coupon went away: work the list out again.
 *
 * @param int $post_id Post ID.
 */
function sella_lc_forget( $post_id = 0 ) {
	// After a delete the post is gone and its type reads false.
	if ( ! $post_id || in_array( get_post_type( $post_id ), array( 'shop_coupon', false ), true ) ) {
		delete_transient( SELLA_LC_CACHE );
	}
}

/**
 * A coupon was published, scheduled or unpublished.
 *
 * @param string  $new  New status.
 * @param string  $old  Old status.
 * @param WP_Post $post Post.
 */
function sella_lc_forget_on_status( $new, $old, $post ) {
	if ( $new !== $old && 'shop_coupon' === $post->post_type ) {
		delete_transient( SELLA_LC_CACHE );
	}
}

/**
 * Coupons list: "מנויי LETS" next to the code.
 *
 * @param string[] $states States.
 * @param WP_Post  $post   Post.
 * @return string[]
 */
function sella_lc_post_state( $states, $post ) {
	if ( 'shop_coupon' === $post->post_type && 'yes' === get_post_meta( $post->ID, SELLA_LC_META, true ) ) {
		$states['sella_lets_auto'] = 'אוטומטי למנויי LETS';
	}
	return $states;
}
add_filter( 'display_post_states', 'sella_lc_post_state', 10, 2 );

/*
 * ------------------------------------------------------------------
 * Who is a subscriber
 * ------------------------------------------------------------------
 */

/**
 * The published coupons with the box checked, by code.
 *
 * @return string[]
 */
function sella_lc_codes() {
	$cached = get_transient( SELLA_LC_CACHE );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$titles = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.post_title FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes' WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish'",
			SELLA_LC_META
		)
	);
	$codes = array_values( array_unique( array_filter( array_map( 'wc_format_coupon_code', (array) $titles ) ) ) );
	set_transient( SELLA_LC_CACHE, $codes, DAY_IN_SECONDS );
	return $codes;
}

/**
 * Whether a coupon is one of these.
 *
 * @param WC_Coupon $coupon Coupon.
 * @return bool
 */
function sella_lc_is_auto( $coupon ) {
	return $coupon instanceof WC_Coupon && $coupon->get_id() && 'yes' === $coupon->get_meta( SELLA_LC_META );
}

/**
 * The email to judge: what the shopper typed at the checkout, else what
 * WooCommerce knows about them.
 *
 * @return string Lower-cased email, or ''.
 */
function sella_lc_email() {
	$email = '';
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified its own request.
	if ( ! empty( $_POST['billing_email'] ) ) {
		$email = sanitize_email( wp_unslash( $_POST['billing_email'] ) );
	} elseif ( ! empty( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
		$posted = array();
		parse_str( wp_unslash( $_POST['post_data'] ), $posted ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the one field is sanitized below.
		$email = isset( $posted['billing_email'] ) && is_string( $posted['billing_email'] ) ? sanitize_email( $posted['billing_email'] ) : '';
	}
	// phpcs:enable
	if ( '' === $email && function_exists( 'WC' ) && WC()->customer ) {
		$email = sanitize_email( (string) WC()->customer->get_billing_email() );
	}
	if ( '' === $email && is_user_logged_in() ) {
		$email = sanitize_email( (string) wp_get_current_user()->user_email );
	}
	$email = strtolower( trim( $email ) );
	return is_email( $email ) ? $email : '';
}

/**
 * What LETS says about the email: whether it is an active subscriber, and
 * the store products its running plans were sold as (null when LETS did not
 * say: an older server, or only the plugin's yes/no check at hand). Cached
 * per email for five minutes, a failed call for thirty seconds. No LETS, no
 * answer: not a subscriber.
 *
 * @param string $email Email.
 * @return array{active:bool,products:int[]|null}
 */
function sella_lc_status( $email ) {
	static $memo = array();

	$none  = array(
		'active'   => false,
		'products' => array(),
	);
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return $none;
	}
	if ( isset( $memo[ $email ] ) ) {
		return $memo[ $email ];
	}

	if ( ! function_exists( 'lets_payplus_signed_post' ) ) {
		$memo[ $email ] = function_exists( 'lets_payplus_subscriber_discount_is_subscriber' ) && lets_payplus_subscriber_discount_is_subscriber( $email )
			? array(
				'active'   => true,
				'products' => null,
			)
			: $none;
		return $memo[ $email ];
	}

	$ref    = is_user_logged_in() ? (string) get_current_user_id() : $email;
	$key    = 'sella_lc2_' . md5( $ref . '|' . $email );
	$cached = get_transient( $key );
	if ( is_array( $cached ) && isset( $cached['active'] ) ) {
		$memo[ $email ] = $cached;
		return $cached;
	}

	$result = lets_payplus_signed_post(
		'/api/woocommerce/account/subscriber-status',
		array(
			'customer_ref' => $ref,
			'email'        => $email,
		)
	);
	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		set_transient( $key, $none, 30 );
		$memo[ $email ] = $none;
		return $none;
	}

	$status = array(
		'active'   => ! empty( $result['active'] ),
		'products' => isset( $result['product_ids'] ) && is_array( $result['product_ids'] ) ? array_values( array_filter( array_map( 'intval', $result['product_ids'] ) ) ) : null,
	);
	set_transient( $key, $status, 5 * MINUTE_IN_SECONDS );
	$memo[ $email ] = $status;
	return $status;
}

/**
 * Whether the email belongs to an active LETS subscriber.
 *
 * @param string $email Email.
 * @return bool
 */
function sella_lc_is_subscriber( $email ) {
	return sella_lc_status( $email )['active'];
}

/**
 * Whether the email may have this coupon: an active subscriber, and, when
 * the coupon is narrowed to plans, of one of them.
 *
 * @param WC_Coupon $coupon Coupon.
 * @param string    $email  Email.
 * @return bool
 */
function sella_lc_qualifies( $coupon, $email ) {
	$status = sella_lc_status( $email );
	if ( ! $status['active'] ) {
		return false;
	}
	$plans = sella_lc_coupon_plans( $coupon->get_id() );
	if ( ! $plans ) {
		return true;
	}
	return is_array( $status['products'] ) && array() !== array_intersect( $plans, $status['products'] );
}

/**
 * The names of the plans a coupon is narrowed to, for the shopper.
 *
 * @param WC_Coupon $coupon Coupon.
 * @return string
 */
function sella_lc_plan_names( $coupon ) {
	$names = array();
	foreach ( sella_lc_coupon_plans( $coupon->get_id() ) as $product_id ) {
		$name = '';
		if ( function_exists( 'sella_plans_get' ) ) {
			foreach ( (array) sella_plans_get()['plans'] as $plan ) {
				if ( isset( $plan['product_id'], $plan['name'] ) && (int) $plan['product_id'] === $product_id ) {
					$name = (string) $plan['name'];
					break;
				}
			}
		}
		$names[] = '' !== $name ? $name : get_the_title( $product_id );
	}
	return implode( ', ', array_unique( array_filter( $names ) ) );
}

/*
 * ------------------------------------------------------------------
 * Subscribers only
 * ------------------------------------------------------------------
 */

/**
 * Typed by hand, or kept on a cart, the coupon is valid only for a
 * subscriber's email. On an order made in the admin, the order's email.
 *
 * @param bool         $valid     Valid so far.
 * @param WC_Coupon    $coupon    Coupon.
 * @param WC_Discounts $discounts Discounts.
 * @return bool
 * @throws Exception The reason, shown to the shopper.
 */
function sella_lc_validate( $valid, $coupon, $discounts = null ) {
	if ( ! $valid || ! sella_lc_is_auto( $coupon ) ) {
		return $valid;
	}
	$object = $discounts instanceof WC_Discounts ? $discounts->get_object() : null;
	$email  = $object instanceof WC_Order ? strtolower( (string) $object->get_billing_email() ) : sella_lc_email();

	if ( sella_lc_qualifies( $coupon, $email ) ) {
		return $valid;
	}
	if ( '' === $email ) {
		$message = 'הקופון הזה למנויי LETS. הזינו את המייל שלכם בפרטי החיוב, והוא יופעל אם יש לכם מנוי פעיל.';
	} elseif ( sella_lc_is_subscriber( $email ) && sella_lc_coupon_plans( $coupon->get_id() ) ) {
		$names   = sella_lc_plan_names( $coupon );
		$message = '' !== $names ? 'הקופון הזה מיועד רק למנויים פעילים מסוג: ' . $names . '.' : 'הקופון הזה מיועד לסוג מנוי אחר.';
	} else {
		$message = 'הקופון הזה למנויים פעילים של LETS בלבד, והמייל שהוזן אינו של מנוי פעיל.';
	}
	throw new Exception( esc_html( $message ), 100 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
add_filter( 'woocommerce_coupon_is_valid', 'sella_lc_validate', 20, 3 );

/*
 * ------------------------------------------------------------------
 * Applied by itself
 * ------------------------------------------------------------------
 */

/**
 * Put the coupons on the cart for a subscriber's email, and take them off
 * for anyone else's.
 *
 * @param string $email   Email ('' when none is known yet).
 * @param bool   $notices Tell the shopper what changed.
 */
function sella_lc_sync( $email, $notices = true ) {
	$codes = sella_lc_codes();
	if ( ! $codes || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}
	$cart    = WC()->cart;
	$changed = false;

	foreach ( $codes as $code ) {
		$applied = $cart->has_discount( $code );
		$coupon  = new WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			continue;
		}

		if ( ! sella_lc_qualifies( $coupon, $email ) ) {
			if ( $applied ) {
				$cart->remove_coupon( $code );
				$changed = true;
				if ( $notices ) {
					wc_add_notice( sprintf( 'הקופון %s הוסר: הוא למנויים פעילים של LETS בלבד.', esc_html( strtoupper( $code ) ) ), 'notice' );
				}
			}
			continue;
		}
		if ( $applied ) {
			continue;
		}
		// An individual-use coupon would push out the shopper's own code; and
		// one of theirs that is individual-use leaves no room for this one.
		$others = $cart->get_applied_coupons();
		if ( $coupon->get_individual_use() && $others ) {
			continue;
		}
		foreach ( $others as $other ) {
			if ( ( new WC_Coupon( $other ) )->get_individual_use() ) {
				continue 2;
			}
		}
		// Silent when it does not fit this cart (products, minimum, expiry).
		$check = ( new WC_Discounts( $cart ) )->is_coupon_valid( $coupon );
		if ( is_wp_error( $check ) || ! $check ) {
			continue;
		}
		if ( ! $notices ) {
			add_filter( 'woocommerce_coupon_message', '__return_empty_string' );
		}
		$cart->apply_coupon( $code );
		if ( ! $notices ) {
			remove_filter( 'woocommerce_coupon_message', '__return_empty_string' );
		}
		$changed = true;
	}

	if ( $changed ) {
		$cart->calculate_totals();
	}
}

/**
 * The success message of an auto coupon says why it is there.
 *
 * @param string    $message Message.
 * @param int       $code    Message code.
 * @param WC_Coupon $coupon  Coupon.
 * @return string
 */
function sella_lc_message( $message, $code, $coupon ) {
	if ( '' !== $message && WC_Coupon::WC_COUPON_SUCCESS === $code && sella_lc_is_auto( $coupon ) ) {
		return sprintf( 'זיהינו מנוי פעיל ב-LETS: הקופון %s הופעל.', strtoupper( $coupon->get_code() ) );
	}
	return $message;
}
add_filter( 'woocommerce_coupon_message', 'sella_lc_message', 10, 3 );

/**
 * The checkout's totals refresh, as the email field changes. The email is
 * kept on the shopper's session, so the cart drawer and the next visit
 * still know it.
 *
 * @param string $post_data The checkout form, URL-encoded.
 */
function sella_lc_on_update_review( $post_data ) {
	if ( ! sella_lc_codes() ) {
		return;
	}
	$posted = array();
	parse_str( (string) $post_data, $posted );
	$email = isset( $posted['billing_email'] ) && is_string( $posted['billing_email'] ) ? strtolower( trim( sanitize_email( $posted['billing_email'] ) ) ) : '';
	$email = is_email( $email ) ? $email : '';
	if ( '' !== $email && WC()->customer ) {
		WC()->customer->set_billing_email( $email );
	}
	sella_lc_sync( $email );
}
add_action( 'woocommerce_checkout_update_order_review', 'sella_lc_on_update_review' );

/**
 * Placing the order: the email in the form decides, even if the totals
 * never refreshed after it was typed.
 */
function sella_lc_on_checkout_process() {
	if ( sella_lc_codes() ) {
		sella_lc_sync( sella_lc_email(), false );
	}
}
add_action( 'woocommerce_checkout_process', 'sella_lc_on_checkout_process', 5 );

/**
 * Opening the checkout with an email already known (a logged-in shopper, or
 * one who typed it before): applied before the page shows. Nothing is taken
 * off here, the form's own refresh decides that.
 */
function sella_lc_on_checkout_page() {
	if ( wp_doing_ajax() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() || ! sella_lc_codes() ) {
		return;
	}
	$email = sella_lc_email();
	if ( '' !== $email && sella_lc_is_subscriber( $email ) ) {
		sella_lc_sync( $email );
	}
}
add_action( 'template_redirect', 'sella_lc_on_checkout_page', 20 );

/**
 * The classic checkout refreshes its totals on address fields only; the
 * email is made one of them.
 */
function sella_lc_checkout_script() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() || ! sella_lc_codes() ) {
		return;
	}
	wp_add_inline_script(
		'wc-checkout',
		'jQuery(function($){var last=$("#billing_email").val();$(document.body).on("change","#billing_email",function(){var v=$(this).val();if(v!==last){last=v;$(document.body).trigger("update_checkout");}});});'
	);
}
add_action( 'wp_enqueue_scripts', 'sella_lc_checkout_script', 30 );
