<?php
/**
 * An email address before paying.
 *
 * A guest who heads for the checkout, from the cart drawer's "לתשלום" button
 * or any other link to it, stays where they are and a popup asks for an email
 * address. Given one, an order is opened at once, pending payment, under that
 * address, and the checkout opens with the address already in its email
 * field. So a shopper who leaves before paying is still on record with their
 * cart and their address, and the abandoned-cart email can reach them
 * (sella-abandoned-cart.php).
 *
 * It is one order for the whole visit: as the cart changes the order follows,
 * and placing the order at the checkout fills in this same order rather than
 * opening a second.
 *
 * Nothing is verified, so no account is opened or logged into: an address
 * typed in proves nothing about who typed it. "המשך כאורח" goes on without
 * an address at all.
 *
 * Signed-in customers never see the popup; the shop knows their address.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cookie that remembers a guest's choice beside the cart session.
 */
const SELLA_EG_COOKIE = 'sella_guest';

/**
 * Whether the popup is switched on, in the abandoned-cart settings.
 *
 * @return bool
 */
function sella_eg_enabled() {
	return function_exists( 'sella_ac_settings' ) ? ! empty( sella_ac_settings()['gate'] ) : true;
}

/* -------------------------------------------------------------------------
 * The guest's address
 * ---------------------------------------------------------------------- */

/**
 * The address a guest gave in the popup, kept in their cart session.
 *
 * @return string
 */
function sella_eg_guest_email() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return '';
	}

	$email = (string) WC()->session->get( 'sella_guest_email', '' );

	if ( '' !== $email ) {
		return $email;
	}

	/*
	 * The checkout that opens straight after the popup can read the session
	 * before the popup's request has written it. The cookie set beside it has
	 * the address already.
	 */
	$email = (string) sella_eg_guest_cookie();

	if ( '' !== $email ) {
		if ( ! WC()->session->has_session() && ! headers_sent() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		WC()->session->set( 'sella_guest', true );
		WC()->session->set( 'sella_guest_email', $email );
	}

	return $email;
}

/**
 * The signature on a guest cookie for an address.
 *
 * @param string $email Address, or an empty string.
 * @return string
 */
function sella_eg_signature( $email ) {
	return substr( hash_hmac( 'sha256', 'guest|' . $email, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Remember a guest's choice in the browser as well as in the session: the
 * address and a signature, so it cannot be made up. An empty address is a
 * guest who gave none.
 *
 * @param string $email Address, or an empty string.
 * @return void
 */
function sella_eg_remember_guest( $email ) {
	if ( headers_sent() ) {
		return;
	}

	// Until the browser closes, like the cart session itself.
	wc_setcookie( SELLA_EG_COOKIE, rawurlencode( $email ) . '|' . sella_eg_signature( $email ), 0, is_ssl(), true );
}

/**
 * The address a guest cookie vouches for, or null when there is none or it
 * does not check out.
 *
 * @return string|null
 */
function sella_eg_guest_cookie() {
	if ( empty( $_COOKIE[ SELLA_EG_COOKIE ] ) ) {
		return null;
	}

	$parts = explode( '|', (string) wp_unslash( $_COOKIE[ SELLA_EG_COOKIE ] ), 2 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against its signature below.

	if ( 2 !== count( $parts ) ) {
		return null;
	}

	$email = rawurldecode( $parts[0] );

	if ( ( '' !== $email && ! is_email( $email ) ) || ! hash_equals( sella_eg_signature( $email ), $parts[1] ) ) {
		return null;
	}

	return $email;
}

/**
 * Whether the shopper already went through the popup, with an address or without.
 *
 * The session says so. The cookie says so too, for when the session lost it:
 * two requests at once each write the whole session, and the one that
 * finished later can write it back without the choice.
 *
 * @return bool
 */
function sella_eg_is_guest() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return false;
	}

	if ( (bool) WC()->session->get( 'sella_guest', false ) || '' !== sella_eg_guest_email() ) {
		return true;
	}

	$email = sella_eg_guest_cookie();

	if ( null === $email ) {
		return false;
	}

	if ( ! WC()->session->has_session() && ! headers_sent() ) {
		WC()->session->set_customer_session_cookie( true );
	}

	WC()->session->set( 'sella_guest', true );

	return true;
}

/* -------------------------------------------------------------------------
 * The popup
 * ---------------------------------------------------------------------- */

/**
 * Whether this page carries the popup: every page but the checkout itself,
 * for a guest who has not been through it.
 *
 * A page the page cache stores has no cart and nobody signed in, so a stored
 * copy always belongs to a guest who would need it; the script asks the
 * server before opening it anyway.
 *
 * @return bool
 */
function sella_eg_popup_applies() {
	if ( ! sella_eg_enabled() || is_user_logged_in() || sella_eg_is_guest() || is_admin() ) {
		return false;
	}

	return ! is_checkout();
}

/**
 * The popup, waiting at the bottom of the page.
 *
 * @return void
 */
function sella_eg_render_popup() {
	if ( ! sella_eg_popup_applies() ) {
		return;
	}
	?>
	<div class="sella-eg" data-eg-modal hidden>
		<div class="sella-eg__backdrop" data-eg-close></div>
		<div class="sella-eg__panel" role="dialog" aria-modal="true" aria-labelledby="sella-eg-title">
			<button type="button" class="sella-eg__close" data-eg-close aria-label="סגירה">&times;</button>
			<div class="sella-eg__card" data-sella-eg>
				<h2 class="sella-eg__title" id="sella-eg-title">רגע לפני התשלום</h2>
				<p class="sella-eg__lead">לאיזו כתובת מייל לשלוח את אישור ההזמנה והעדכונים עליה?</p>

				<form class="sella-eg__form" data-eg-form novalidate>
					<label class="sella-eg__label" for="sella-eg-email">כתובת מייל</label>
					<input class="sella-eg__input" type="email" id="sella-eg-email" name="email" autocomplete="email" inputmode="email" dir="ltr" required>
					<button type="submit" class="sella-eg__submit">המשך לתשלום</button>
					<button type="button" class="sella-eg__guest" data-eg-guest>המשך כאורח</button>
				</form>

				<p class="sella-eg__message" data-eg-message role="alert" aria-live="polite"></p>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'sella_eg_render_popup' );

/**
 * The popup's stylesheet and script, wherever it waits.
 *
 * @return void
 */
function sella_eg_assets() {
	if ( ! sella_eg_popup_applies() ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style( 'sella-email-gate', $uri . '/assets/css/sella-email-gate.css', [], HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_script( 'sella-email-gate', $uri . '/assets/js/sella-email-gate.js', [], HELLO_ELEMENTOR_CHILD_VERSION, true );

	wp_localize_script(
		'sella-email-gate',
		'sellaEmailGate',
		[
			'endpoint' => WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'nonce'    => wp_create_nonce( 'sella_eg' ),
			'redirect' => wc_get_checkout_url(),
			'strings'  => [
				'email'      => 'נא להזין כתובת מייל תקינה',
				'continuing' => 'ממשיכים לתשלום...',
				'error'      => 'משהו השתבש. נסו שוב בעוד רגע.',
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'sella_eg_assets', 30 );

/**
 * A fresh nonce for the popup, and whether it is still needed.
 *
 * The page the popup sits on may be a stored copy, or older than the cart:
 * WooCommerce ties a guest's nonce to their cart session, so one printed
 * before the first item went in no longer passes once it has.
 *
 * @return void
 */
function sella_eg_ajax_state() {
	nocache_headers();

	wp_send_json_success(
		[
			'nonce'  => wp_create_nonce( 'sella_eg' ),
			'needed' => sella_eg_enabled() && ! is_user_logged_in() && ! sella_eg_is_guest(),
		]
	);
}
add_action( 'wc_ajax_sella_eg_state', 'sella_eg_ajax_state' );

/**
 * Send the shopper to the checkout as a guest, with an address or without.
 *
 * With an address, it goes in the checkout's email field and an order is
 * opened at once, pending payment, under it.
 *
 * @return void
 */
function sella_eg_ajax_continue() {
	if ( ! check_ajax_referer( 'sella_eg', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'פג תוקף הדף. רעננו אותו ונסו שוב.' ], 403 );
	}

	if ( is_user_logged_in() ) {
		wp_send_json_success( [ 'redirect' => wc_get_checkout_url() ] );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	if ( '' !== $email && ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => 'נא להזין כתובת מייל תקינה' ], 400 );
	}

	if ( ! WC()->session ) {
		wp_send_json_error( [ 'message' => 'משהו השתבש. נסו שוב בעוד רגע.' ], 500 );
	}

	// A guest's session lives only in memory until it is given a cookie.
	WC()->session->set_customer_session_cookie( true );
	WC()->session->set( 'sella_guest', true );
	sella_eg_remember_guest( $email );

	if ( $email ) {
		WC()->session->set( 'sella_guest_email', $email );

		if ( WC()->customer ) {
			WC()->customer->set_billing_email( $email );
			WC()->customer->save();
		}

		sella_eg_pending_order( $email );
	}

	/*
	 * WooCommerce writes the session when the request shuts down, which can be
	 * after the browser has the answer and has gone on to the checkout; that
	 * page then still saw a stranger. Write it now.
	 */
	WC()->session->save_data();

	wp_send_json_success( [ 'redirect' => wc_get_checkout_url() ] );
}
add_action( 'wc_ajax_sella_eg_continue', 'sella_eg_ajax_continue' );

/* -------------------------------------------------------------------------
 * The order kept for the session
 *
 * WooCommerce takes up an order awaiting payment only while the cart's hash
 * matches the one stored on it, and opens a new order otherwise. So before
 * each update, and before the checkout places the order, the stored hash is
 * set to the cart's, and WooCommerce rebuilds the same order from the cart.
 * ---------------------------------------------------------------------- */

/**
 * The order kept for this session, while it can still be paid and changed.
 *
 * @return WC_Order|null
 */
function sella_eg_session_order() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return null;
	}

	$order = wc_get_order( (int) WC()->session->get( 'sella_pending_order', 0 ) );

	if ( ! $order || ! $order->get_meta( '_sella_pending_from_gate' ) || ! $order->has_status( [ 'pending', 'failed' ] ) ) {
		return null;
	}

	return $order;
}

/**
 * Write the cart into an order: WooCommerce's own checkout, run on the order
 * awaiting payment, with the hash set so it is taken up rather than replaced.
 *
 * A failure here costs only the record, so it never stops the shopper.
 *
 * @param array         $data  Fields for the order.
 * @param WC_Order|null $order The order to bring up to date, or null for a new one.
 * @return int Order ID, or 0.
 */
function sella_eg_write_order( $data, $order = null ) {
	if ( ! WC()->cart || WC()->cart->is_empty() ) {
		return 0;
	}

	try {
		WC()->cart->calculate_totals();

		if ( $order ) {
			$order->set_cart_hash( WC()->cart->get_cart_hash() );
			$order->save();
			WC()->session->set( 'order_awaiting_payment', $order->get_id() );
		}

		$order_id = WC()->checkout()->create_order( $data + [ 'payment_method' => '' ] );
	} catch ( Throwable $e ) {
		return 0;
	}

	return is_wp_error( $order_id ) ? 0 : (int) $order_id;
}

/**
 * Open the session's order under the shopper's address, or bring the one
 * already open up to date with it.
 *
 * @param string $email Address.
 * @return int Order ID, or 0.
 */
function sella_eg_pending_order( $email ) {
	$existing = sella_eg_session_order();

	$order_id = sella_eg_write_order(
		[
			'billing_email'  => $email,
			'payment_method' => $existing ? $existing->get_payment_method() : '',
		],
		$existing
	);

	$order = $order_id ? wc_get_order( $order_id ) : null;

	if ( ! $order ) {
		return 0;
	}

	if ( ! $existing ) {
		$order->update_meta_data( '_sella_pending_from_gate', 1 );
		$order->add_order_note( 'נפתחה כשהלקוח הזין מייל בחלון שלפני התשלום. ממתינה לתשלום: אם לא תושלם, הלקוח נטש את הקנייה.' );
		$order->save();
	}

	WC()->session->set( 'order_awaiting_payment', $order->get_id() );
	WC()->session->set( 'sella_pending_order', $order->get_id() );

	return $order->get_id();
}

/**
 * Note that the cart changed, for the session's order to follow once the
 * request is done. Adding three items is one update, not three.
 *
 * @return void
 */
function sella_eg_cart_changed() {
	$GLOBALS['sella_eg_cart_changed'] = true;
}
add_action( 'woocommerce_add_to_cart', 'sella_eg_cart_changed' );
add_action( 'woocommerce_cart_item_removed', 'sella_eg_cart_changed' );
add_action( 'woocommerce_cart_item_restored', 'sella_eg_cart_changed' );
add_action( 'woocommerce_after_cart_item_quantity_update', 'sella_eg_cart_changed' );
add_action( 'woocommerce_applied_coupon', 'sella_eg_cart_changed' );
add_action( 'woocommerce_removed_coupon', 'sella_eg_cart_changed' );

/**
 * Bring the session's order up to date with the cart.
 *
 * At shutdown, ahead of WooCommerce writing the session. An emptied cart
 * leaves the order as it was, the record of what was nearly bought.
 *
 * @return void
 */
function sella_eg_sync_order() {
	if ( empty( $GLOBALS['sella_eg_cart_changed'] ) || is_user_logged_in() ) {
		return;
	}

	$GLOBALS['sella_eg_cart_changed'] = false;

	$order = sella_eg_session_order();

	if ( $order ) {
		sella_eg_write_order(
			[
				'billing_email'  => $order->get_billing_email(),
				'payment_method' => $order->get_payment_method(),
			],
			$order
		);
	}
}
add_action( 'shutdown', 'sella_eg_sync_order', 5 );

/**
 * Before the checkout places the order, point it at the session's order, so
 * whatever changed since, WooCommerce fills in that order and opens no other.
 *
 * Validation runs after the checkout has worked out the cart again from the
 * posted shipping choice and just before the order is placed, so the hash
 * taken here is the one WooCommerce compares.
 *
 * @return void
 */
function sella_eg_resume_order() {
	$order = sella_eg_session_order();

	if ( ! $order || ! WC()->cart ) {
		return;
	}

	$order->set_cart_hash( WC()->cart->get_cart_hash() );
	$order->save();

	WC()->session->set( 'order_awaiting_payment', $order->get_id() );
}
add_action( 'woocommerce_after_checkout_validation', 'sella_eg_resume_order', 999 );

/**
 * Should the checkout have opened another order all the same, bin the
 * session's one, so the shop does not see the same purchase twice.
 *
 * @param int $order_id The order the checkout placed.
 * @return void
 */
function sella_eg_drop_pending_order( $order_id ) {
	$pending = sella_eg_session_order();

	if ( ! $pending || (int) $order_id === $pending->get_id() ) {
		return;
	}

	WC()->session->set( 'sella_pending_order', null );
	$pending->delete( false );
}
add_action( 'woocommerce_checkout_order_processed', 'sella_eg_drop_pending_order' );

/* -------------------------------------------------------------------------
 * The checkout
 * ---------------------------------------------------------------------- */

/**
 * The guest's address in the checkout's email field, if it is still empty.
 *
 * @param mixed  $value Field value.
 * @param string $input Field name.
 * @return mixed
 */
function sella_eg_checkout_value( $value, $input ) {
	if ( 'billing_email' === $input && ! $value && ! is_user_logged_in() ) {
		$guest = sella_eg_guest_email();

		return $guest ? $guest : $value;
	}

	return $value;
}
add_filter( 'woocommerce_checkout_get_value', 'sella_eg_checkout_value', 10, 2 );

/**
 * The order goes out with the guest's address if the field came back empty.
 *
 * @param array $data Posted data.
 * @return array
 */
function sella_eg_posted_email( $data ) {
	if ( ! is_user_logged_in() && empty( $data['billing_email'] ) && sella_eg_guest_email() ) {
		$data['billing_email'] = sella_eg_guest_email();
	}

	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'sella_eg_posted_email' );
