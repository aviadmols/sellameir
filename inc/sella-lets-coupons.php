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
}
add_action( 'woocommerce_coupon_options', 'sella_lc_field' );

/**
 * Save the checkbox.
 *
 * @param int $coupon_id Coupon ID.
 */
function sella_lc_save( $coupon_id ) {
	update_post_meta( $coupon_id, SELLA_LC_META, isset( $_POST[ SELLA_LC_META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the coupon form nonce.
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
 * Whether the email belongs to an active LETS subscriber. The plugin's own
 * check when it has one (cached per email); else the same request to LETS.
 * No LETS, no answer: false.
 *
 * @param string $email Email.
 * @return bool
 */
function sella_lc_is_subscriber( $email ) {
	static $memo = array();

	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return false;
	}
	if ( isset( $memo[ $email ] ) ) {
		return $memo[ $email ];
	}

	if ( function_exists( 'lets_payplus_subscriber_discount_is_subscriber' ) ) {
		$memo[ $email ] = (bool) lets_payplus_subscriber_discount_is_subscriber( $email );
		return $memo[ $email ];
	}
	if ( ! function_exists( 'lets_payplus_signed_post' ) ) {
		$memo[ $email ] = false;
		return false;
	}

	$ref    = is_user_logged_in() ? (string) get_current_user_id() : $email;
	$key    = 'sella_lc_' . md5( $ref . '|' . $email );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		$memo[ $email ] = '1' === $cached;
		return $memo[ $email ];
	}

	$result = lets_payplus_signed_post(
		'/api/woocommerce/account/subscriber-status',
		array(
			'customer_ref' => $ref,
			'email'        => $email,
		)
	);
	if ( is_wp_error( $result ) ) {
		set_transient( $key, '0', 30 );
		$memo[ $email ] = false;
		return false;
	}
	$memo[ $email ] = ! empty( $result['active'] );
	set_transient( $key, $memo[ $email ] ? '1' : '0', 5 * MINUTE_IN_SECONDS );
	return $memo[ $email ];
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

	if ( ! sella_lc_is_subscriber( $email ) ) {
		throw new Exception(
			esc_html( '' === $email ? 'הקופון הזה למנויי LETS. הזינו את המייל שלכם בפרטי החיוב, והוא יופעל אם יש לכם מנוי פעיל.' : 'הקופון הזה למנויים פעילים של LETS בלבד, והמייל שהוזן אינו של מנוי פעיל.' ),
			100 // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		);
	}
	return $valid;
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
	$cart       = WC()->cart;
	$subscriber = sella_lc_is_subscriber( $email );
	$changed    = false;

	foreach ( $codes as $code ) {
		$applied = $cart->has_discount( $code );

		if ( ! $subscriber ) {
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

		$coupon = new WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
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
