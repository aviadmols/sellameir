<?php
/**
 * ActiveTrail: buyers into groups. Whoever buys a chosen product, or any
 * subscription, joins a chosen ActiveTrail group — so a campaign can be sent
 * to them, or a group of subscribers left out of one.
 *
 * The rules are rows on the ActiveTrail screen (פניות ולידים → אקטיב טרייל):
 * "bought X" → group. X is a product (any of its variations) or "any
 * subscription", which is an order line LETS marks with `_lets_subscription`.
 * They are kept in an option of their own and saved by a form of their own,
 * so saving the screen's main form never touches them.
 *
 * When an order turns paid (its status moves to processing or completed), the
 * buyer is sent at the end of the request through the same import as the
 * leads (sella_at_import()), with the same contact: name, mobile, the status
 * and the fields every contact gets. Each order is sent once; the result is
 * written as an order note. The Shopify import's quiet mode removes every
 * status-change hook, so the old orders it creates send nothing.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_ATB_OPTION', 'sella_activetrail_buyers' );
// On the order once it was handled: the groups it went into, or why not.
define( 'SELLA_ATB_DONE_META', '_sella_at_buyers' );
// The rule that matches any subscription line, beside "product:<id>".
define( 'SELLA_ATB_ANY_SUBSCRIPTION', 'subscription' );
// Empty rows the screen offers below the saved ones.
define( 'SELLA_ATB_BLANK_ROWS', 2 );

/**
 * ---------------------------------------------------------------------------
 * Rules
 * ---------------------------------------------------------------------------
 */

/**
 * The saved rules.
 *
 * @return array<int, array{what: string, group: int}>
 */
function sella_atb_rules() {
	$saved = get_option( SELLA_ATB_OPTION, array() );
	$rules = is_array( $saved ) && isset( $saved['rules'] ) && is_array( $saved['rules'] ) ? $saved['rules'] : array();
	return array_values( array_filter( $rules, 'sella_atb_rule_valid' ) );
}

/**
 * @param mixed $rule A rule.
 * @return bool
 */
function sella_atb_rule_valid( $rule ) {
	return is_array( $rule )
		&& isset( $rule['what'], $rule['group'] )
		&& (int) $rule['group'] > 0
		&& ( SELLA_ATB_ANY_SUBSCRIPTION === $rule['what'] || preg_match( '/^product:\d+$/', (string) $rule['what'] ) );
}

/**
 * What an order holds, as the rules name it: "product:<id>" for each product
 * (the parent of a variation) and "subscription" when a line is one.
 *
 * @param WC_Order $order Order.
 * @return string[]
 */
function sella_atb_order_keys( $order ) {
	$keys = array();
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$keys[] = 'product:' . (int) $item->get_product_id();
		if ( $item->get_meta( '_lets_subscription' ) ) {
			$keys[] = SELLA_ATB_ANY_SUBSCRIPTION;
		}
	}
	return array_values( array_unique( $keys ) );
}

/**
 * The groups an order's buyer joins.
 *
 * @param WC_Order $order Order.
 * @return int[]
 */
function sella_atb_order_groups( $order ) {
	$keys   = sella_atb_order_keys( $order );
	$groups = array();
	foreach ( sella_atb_rules() as $rule ) {
		if ( in_array( $rule['what'], $keys, true ) ) {
			$groups[] = (int) $rule['group'];
		}
	}
	return array_values( array_unique( $groups ) );
}

/**
 * ---------------------------------------------------------------------------
 * Sending
 * ---------------------------------------------------------------------------
 */

/**
 * The buyer as a lead row, so the contact is built like every other one
 * (sella_at_contact()). With no lead of their own, the address is their
 * external ID on ActiveTrail.
 *
 * @param WC_Order $order Order.
 * @return object
 */
function sella_atb_row( $order ) {
	$email = strtolower( (string) $order->get_billing_email() );
	return (object) array(
		'id'                => 0,
		'external_fallback' => $email,
		'type'              => 'purchase',
		'channel'           => 'purchase',
		'name'              => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		'email'             => $email,
		'phone'             => (string) $order->get_billing_phone(),
		'order_ref'         => (string) $order->get_order_number(),
		'message'           => '',
		'source'            => 'רכישה',
		'page_url'          => '',
		'fields'            => null,
		'created_at'        => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
	);
}

/**
 * Send an order's buyer to the groups its rules name, once.
 *
 * @param int  $order_id Order.
 * @param bool $force    Send again even if it was handled.
 * @return string What happened, as written on the order.
 */
function sella_atb_send_order( $order_id, $force = false ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ( ! $force && '' !== (string) $order->get_meta( SELLA_ATB_DONE_META ) ) ) {
		return '';
	}

	$groups = sella_atb_order_groups( $order );
	if ( ! $groups ) {
		return '';
	}

	$row = sella_atb_row( $order );
	if ( '' === sella_at_settings()['token'] ) {
		$note = 'error:אין טוקן';
	} elseif ( ! is_email( $row->email ) ) {
		$note = 'skip:אין מייל';
	} else {
		$contact = sella_at_contact( $row, array( 'map' => array() ) );
		$sent    = array();
		$failed  = array();
		foreach ( $groups as $group ) {
			$error = sella_at_import( $row, $contact, $group );
			if ( '' === $error ) {
				$sent[] = $group;
			} else {
				$failed[] = $group . ': ' . $error;
			}
		}
		$note = $failed ? 'error:' . ( $sent ? 'נכנס ל-' . implode( ',', $sent ) . '; ' : '' ) . implode( '; ', $failed ) : 'ok:' . implode( ',', $sent );
	}

	$order->update_meta_data( SELLA_ATB_DONE_META, mb_substr( $note, 0, 250 ) );
	$order->add_order_note( sella_atb_note_text( $note ) );
	$order->save();

	return $note;
}

/**
 * The order note for a result, with the groups by name.
 *
 * @param string $note ok:<ids> | error:<text> | skip:<text>.
 * @return string
 */
function sella_atb_note_text( $note ) {
	if ( 0 === strpos( $note, 'ok:' ) ) {
		$names  = sella_at_groups();
		$names  = is_array( $names ) ? $names : array();
		$labels = array_map(
			function ( $id ) use ( $names ) {
				return isset( $names[ (int) $id ] ) ? $names[ (int) $id ] . ' (' . (int) $id . ')' : (string) (int) $id;
			},
			explode( ',', substr( $note, 3 ) )
		);
		return 'אקטיב טרייל: הקונה נוסף לקבוצה ' . implode( ', ', $labels ) . '.';
	}
	return 'אקטיב טרייל: הקונה לא נוסף לקבוצה. ' . preg_replace( '/^(error|skip):/', '', $note );
}

/**
 * Orders waiting until the end of the request: the buyer (or LETS's call
 * that marked the order paid) has the answer first.
 *
 * @param int $order_id Order to add; 0 only reads the list.
 * @return int[]
 */
function sella_atb_queue( $order_id = 0 ) {
	static $ids = array();
	if ( $order_id && ! in_array( (int) $order_id, $ids, true ) ) {
		if ( ! $ids ) {
			add_action( 'shutdown', 'sella_atb_flush', 111 );
		}
		$ids[] = (int) $order_id;
	}
	return $ids;
}

/**
 * An order turned paid: queue it when a rule matches.
 *
 * @param int    $order_id Order.
 * @param string $from     Previous status.
 * @param string $to       New status.
 */
function sella_atb_on_status( $order_id, $from, $to ) {
	if ( ! in_array( $to, array( 'processing', 'completed' ), true ) || ! sella_atb_rules() ) {
		return;
	}
	sella_atb_queue( $order_id );
}
add_action( 'woocommerce_order_status_changed', 'sella_atb_on_status', 20, 3 );

/**
 * At the end of the request.
 */
function sella_atb_flush() {
	$ids = sella_atb_queue();
	if ( ! $ids ) {
		return;
	}
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	foreach ( $ids as $id ) {
		sella_atb_send_order( $id );
	}
}

/**
 * ---------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------------
 */

/**
 * Save the rules, and send an order again. Runs before the screen's own
 * handler, which answers every form of the page.
 */
function sella_atb_handle_admin_actions() {
	if ( ! is_admin() || empty( $_POST['sella_at_form'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$form = sanitize_key( wp_unslash( $_POST['sella_at_form'] ) );
	if ( ! in_array( $form, array( 'buyers', 'buyers_resend' ), true ) ) {
		return;
	}
	check_admin_referer( 'sella_at_manage' );

	$back = admin_url( 'admin.php?page=' . SELLA_AT_SLUG ) . '#sella-atb';

	if ( 'buyers' === $form ) {
		$rules = array();
		foreach ( isset( $_POST['buyers'] ) && is_array( $_POST['buyers'] ) ? wp_unslash( $_POST['buyers'] ) : array() as $entry ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field below.
			$rule = array(
				'what'  => isset( $entry['what'] ) ? sanitize_text_field( $entry['what'] ) : '',
				'group' => isset( $entry['group'] ) ? absint( $entry['group'] ) : 0,
			);
			if ( sella_atb_rule_valid( $rule ) && ! in_array( $rule, $rules, true ) ) {
				$rules[] = $rule;
			}
		}
		update_option( SELLA_ATB_OPTION, array( 'rules' => $rules ), false );
		set_transient( 'sella_atb_notice_' . get_current_user_id(), 'החוקים לרוכשים נשמרו.', MINUTE_IN_SECONDS );
	} else {
		$ref   = isset( $_POST['order_ref'] ) ? absint( wp_unslash( $_POST['order_ref'] ) ) : 0;
		$order = $ref ? wc_get_order( $ref ) : false;
		if ( ! $order ) {
			$message = 'לא נמצאה הזמנה ' . $ref . '.';
		} elseif ( ! sella_atb_order_groups( $order ) ) {
			$message = 'אין חוק שמתאים להזמנה ' . $ref . '.';
		} else {
			$message = 'הזמנה ' . $ref . ': ' . sella_atb_note_text( sella_atb_send_order( $ref, true ) );
		}
		set_transient( 'sella_atb_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	}

	wp_safe_redirect( $back );
	exit;
}
add_action( 'admin_init', 'sella_atb_handle_admin_actions', 9 );

/**
 * The products a rule can name, by title.
 *
 * @return array<int, string>
 */
function sella_atb_products() {
	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	$products = array();
	foreach ( $ids as $id ) {
		$products[ (int) $id ] = get_the_title( $id ) . ( 'private' === get_post_status( $id ) ? ' (פרטי)' : '' );
	}
	return $products;
}

/**
 * The box on the ActiveTrail screen, under the routing form.
 *
 * @param array $groups ActiveTrail groups, ID => name.
 */
function sella_atb_render_box( $groups ) {
	$rules    = sella_atb_rules();
	$products = sella_atb_products();
	$rows     = array_merge( $rules, array_fill( 0, SELLA_ATB_BLANK_ROWS, array( 'what' => '', 'group' => 0 ) ) );
	$notice   = get_transient( 'sella_atb_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'sella_atb_notice_' . get_current_user_id() );
	}
	?>
	<form method="post" class="sella-at-box sella-atb" id="sella-atb">
		<?php wp_nonce_field( 'sella_at_manage' ); ?>
		<input type="hidden" name="sella_at_form" value="buyers" />
		<h2>רוכשים לקבוצה</h2>
		<p class="description">
			מי שקונה מוצר שבוחרים כאן, או כל מנוי, נוסף לקבוצה באקטיב טרייל ברגע שההזמנה משולמת. כך אפשר לשלוח להם דיוור, או להחריג אותם מדיוור לפי הקבוצה.
			כל הזמנה נשלחת פעם אחת, והתוצאה נכתבת בהערות של ההזמנה. מוצר עם וריאציות תופס את כולן. כדי למחוק שורה בוחרים בה "—" ושומרים.
		</p>
		<?php if ( $notice ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! $groups ) : ?>
			<p class="description"><strong>הקבוצות לא נטענו מאקטיב טרייל.</strong> בודקים את הטוקן בחיבור ושומרים.</p>
		<?php endif; ?>

		<div class="sella-atb__rows">
			<?php foreach ( $rows as $i => $rule ) : ?>
				<div class="sella-atb__row">
					<label>
						<span>מי שקנה</span>
						<select name="buyers[<?php echo (int) $i; ?>][what]">
							<option value="">—</option>
							<option value="<?php echo esc_attr( SELLA_ATB_ANY_SUBSCRIPTION ); ?>" <?php selected( $rule['what'], SELLA_ATB_ANY_SUBSCRIPTION ); ?>>כל מנוי (כל הזמנה שיש בה מנוי)</option>
							<optgroup label="מוצר">
								<?php foreach ( $products as $id => $title ) : ?>
									<option value="<?php echo esc_attr( 'product:' . $id ); ?>" <?php selected( $rule['what'], 'product:' . $id ); ?>><?php echo esc_html( $title . ' #' . $id ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						</select>
					</label>
					<label>
						<span>נוסף לקבוצה</span>
						<select name="buyers[<?php echo (int) $i; ?>][group]">
							<option value="0">—</option>
							<?php foreach ( $groups as $id => $label ) : ?>
								<option value="<?php echo (int) $id; ?>" <?php selected( (int) $rule['group'], (int) $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( $rule['group'] && ! isset( $groups[ (int) $rule['group'] ] ) ) : ?>
								<option value="<?php echo (int) $rule['group']; ?>" selected><?php echo esc_html( 'קבוצה ' . (int) $rule['group'] ); ?></option>
							<?php endif; ?>
						</select>
					</label>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="description">צריך עוד שורות? שומרים, ושתי שורות ריקות חדשות מופיעות.</p>
		<?php submit_button( 'שמירת החוקים לרוכשים', 'primary', 'submit', false ); ?>
	</form>

	<form method="post" class="sella-at-box sella-atb-resend">
		<?php wp_nonce_field( 'sella_at_manage' ); ?>
		<input type="hidden" name="sella_at_form" value="buyers_resend" />
		<h2>שליחת הזמנה לפי החוקים</h2>
		<p class="description">שולח את הקונה של הזמנה קיימת לקבוצות שהחוקים קובעים לה, גם אם כבר נשלחה. טוב לבדיקה, או להזמנה ששולמה לפני שהחוק נוסף.</p>
		<p class="sella-at-inline">
			<input type="number" name="order_ref" min="1" class="small-text" dir="ltr" placeholder="מספר הזמנה" required />
			<button type="submit" class="button">שליחה</button>
		</p>
	</form>
	<?php
}
add_action( 'sella_at_after_settings', 'sella_atb_render_box' );
