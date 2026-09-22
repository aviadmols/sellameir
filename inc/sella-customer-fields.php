<?php
/**
 * Customer contact fields: a required phone and opt-in to marketing content.
 *
 * Phone — required wherever the customer edits it:
 * - Checkout and My Account > Addresses: WooCommerce's "Phone field" setting is
 *   forced to "required" (see sella_require_phone_setting()).
 * - My Account > Account details gets its own phone field, stored as billing_phone.
 *
 * Marketing consent — an unchecked-by-default checkbox on checkout and on
 * Account details. Kept as user meta for customers with an account and on each
 * order, and shown to admins on the order screen and the user profile.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_MARKETING_CONSENT_META', 'sella_marketing_consent' );
define( 'SELLA_MARKETING_CONSENT_LABEL', 'אני מאשר/ת לקבל תוכן שיווקי, עדכונים ומבצעים' );

/**
 * The phone number is needed for delivery updates, so it is not optional.
 *
 * Forced through WooCommerce's own "Phone field" setting rather than by
 * editing the billing fields: the setting also feeds the country locale,
 * which WooCommerce's address script re-applies in the browser on load and
 * would otherwise flip the field back to optional. It covers the shipping
 * phone too, when the order ships to a different address.
 *
 * @return string
 */
function sella_require_phone_setting() {
	return 'required';
}
add_filter( 'pre_option_woocommerce_checkout_phone_field', 'sella_require_phone_setting' );

/**
 * Whether a customer has opted in to marketing content.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function sella_has_marketing_consent( $user_id ) {
	return (bool) get_user_meta( $user_id, SELLA_MARKETING_CONSENT_META, true );
}

/**
 * Checkout: the opt-in checkbox at the end of the billing fields.
 *
 * It sits in the billing form rather than next to the order button because
 * WooCommerce re-renders the payment box on every checkout update, which would
 * clear a ticked box. Logged-in customers see their saved choice.
 *
 * @param array $fields Checkout fields.
 * @return array
 */
function sella_checkout_marketing_consent_field( $fields ) {
	$fields['billing'][ SELLA_MARKETING_CONSENT_META ] = array(
		'type'     => 'checkbox',
		'label'    => SELLA_MARKETING_CONSENT_LABEL,
		'required' => false,
		'class'    => array( 'form-row-wide', 'sella-marketing-consent' ),
		'priority' => 1000,
	);
	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'sella_checkout_marketing_consent_field', 20 );

/**
 * Checkout: record the choice on the order, guest checkouts included.
 *
 * @param WC_Order $order Order being created.
 * @param array    $data  Posted checkout data.
 */
function sella_checkout_save_order_marketing_consent( $order, $data ) {
	if ( array_key_exists( SELLA_MARKETING_CONSENT_META, $data ) ) {
		$order->update_meta_data( '_' . SELLA_MARKETING_CONSENT_META, empty( $data[ SELLA_MARKETING_CONSENT_META ] ) ? 'no' : 'yes' );
	}
}
add_action( 'woocommerce_checkout_create_order', 'sella_checkout_save_order_marketing_consent', 10, 2 );

/**
 * Checkout: keep the choice on the customer's account, so unticking it opts out.
 *
 * @param WC_Customer $customer Customer being saved.
 * @param array       $data     Posted checkout data.
 */
function sella_checkout_save_customer_marketing_consent( $customer, $data ) {
	if ( array_key_exists( SELLA_MARKETING_CONSENT_META, $data ) ) {
		$customer->update_meta_data( SELLA_MARKETING_CONSENT_META, empty( $data[ SELLA_MARKETING_CONSENT_META ] ) ? '0' : '1' );
	}
}
add_action( 'woocommerce_checkout_update_customer', 'sella_checkout_save_customer_marketing_consent', 10, 2 );

/**
 * Account details: phone and opt-in, right after the email field.
 */
function sella_account_details_fields() {
	$user_id = get_current_user_id();
	?>
	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
		<label for="account_phone">טלפון&nbsp;<span class="required" aria-hidden="true">*</span></label>
		<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="account_phone" id="account_phone" autocomplete="tel" value="<?php echo esc_attr( get_user_meta( $user_id, 'billing_phone', true ) ); ?>" aria-required="true" />
	</p>
	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide sella-marketing-consent">
		<?php // Sent when the box is unticked, so saving the form can also opt out. ?>
		<input type="hidden" name="<?php echo esc_attr( SELLA_MARKETING_CONSENT_META ); ?>" value="0" />
		<label class="checkbox">
			<input type="checkbox" class="input-checkbox" name="<?php echo esc_attr( SELLA_MARKETING_CONSENT_META ); ?>" value="1" <?php checked( sella_has_marketing_consent( $user_id ) ); ?> />
			<span><?php echo esc_html( SELLA_MARKETING_CONSENT_LABEL ); ?></span>
		</label>
	</p>
	<?php
}
add_action( 'woocommerce_edit_account_form_fields', 'sella_account_details_fields' );

/**
 * Account details: the phone may not be left empty.
 *
 * Checks only when the form carried the field, so a template without the hook
 * above can still be saved. WooCommerce verifies the nonce before this runs.
 *
 * @param WP_Error $errors Validation errors.
 */
function sella_validate_account_details( $errors ) {
	if ( ! isset( $_POST['account_phone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}

	$phone = wc_clean( wp_unslash( $_POST['account_phone'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( '' === $phone ) {
		$errors->add( 'account_phone', '<strong>טלפון</strong> הוא שדה חובה.' );
	} elseif ( ! WC_Validation::is_phone( $phone ) ) {
		$errors->add( 'account_phone', 'מספר הטלפון אינו תקין.' );
	}
}
add_action( 'woocommerce_save_account_details_errors', 'sella_validate_account_details' );

/**
 * Account details: save the phone (to the billing address) and the opt-in.
 *
 * @param int $user_id User ID.
 */
function sella_save_account_details( $user_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	if ( ! isset( $_POST['account_phone'] ) && ! isset( $_POST[ SELLA_MARKETING_CONSENT_META ] ) ) {
		return;
	}

	$customer = new WC_Customer( $user_id );

	if ( isset( $_POST['account_phone'] ) ) {
		$customer->set_billing_phone( wc_clean( wp_unslash( $_POST['account_phone'] ) ) );
	}
	if ( isset( $_POST[ SELLA_MARKETING_CONSENT_META ] ) ) {
		$customer->update_meta_data( SELLA_MARKETING_CONSENT_META, '1' === $_POST[ SELLA_MARKETING_CONSENT_META ] ? '1' : '0' );
	}
	// phpcs:enable

	$customer->save();
}
add_action( 'woocommerce_save_account_details', 'sella_save_account_details' );

/**
 * Admin order screen: show the choice under the billing address.
 *
 * @param WC_Order $order Order.
 */
function sella_admin_order_marketing_consent( $order ) {
	$consent = $order->get_meta( '_' . SELLA_MARKETING_CONSENT_META );
	if ( '' === $consent ) {
		return; // Placed before the checkbox existed.
	}

	printf(
		'<p><strong>תוכן שיווקי:</strong> %s</p>',
		'yes' === $consent ? 'אישר/ה' : 'לא אישר/ה'
	);
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'sella_admin_order_marketing_consent' );

/**
 * User profile: show and edit the opt-in in the customer billing section.
 *
 * @param array $fields Customer meta fields, by section.
 * @return array
 */
function sella_profile_marketing_consent_field( $fields ) {
	if ( isset( $fields['billing']['fields'] ) ) {
		$fields['billing']['fields'][ SELLA_MARKETING_CONSENT_META ] = array(
			'label'       => 'תוכן שיווקי',
			'description' => 'הלקוח/ה אישר/ה לקבל תוכן שיווקי',
			'type'        => 'checkbox',
			'class'       => '',
		);
	}
	return $fields;
}
add_filter( 'woocommerce_customer_meta_fields', 'sella_profile_marketing_consent_field' );
