<?php
/**
 * A discount for a cart left behind.
 *
 * A cart whose shopper we know by email, left for two hours without an
 * order, gets one email with what is in it. A cart worth 500 or more also
 * gets a 5% code that works only on those products, only on an order of 500
 * or more, only for that address, once, for 24 hours. A smaller cart gets
 * the reminder alone. The
 * email's button brings the cart back as it was,
 * with the code already applied, and opens the checkout with the address in
 * its email field.
 *
 * How it knows the shopper: a popup asks for an email on the way to the
 * checkout (sella-email-gate.php), so a signed-in customer's address, the
 * guest's address, or the billing address typed at checkout.
 *
 * Nobody gets more than one such email in fourteen days, an address that
 * ordered since leaving the cart gets none, and the link at the foot of the
 * email stops them for good.
 *
 * The admin page under the Upsell popups menu sets the figures, shows every
 * email sent and the email itself, and counts who clicked and who bought:
 * a paid order from the same address within fourteen days of the email,
 * and whether it used the code.
 *
 * It starts switched off, so the email can be looked at before any goes out.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised when the table changes.
 */
const SELLA_AC_DB_VERSION = '1';

/**
 * Option holding the settings.
 */
const SELLA_AC_OPTION = 'sella_abandoned_cart';

/**
 * Option holding the hashed addresses that asked for no more of these.
 */
const SELLA_AC_STOPPED = 'sella_abandoned_cart_stopped';

/**
 * Cron hook that sends what is due.
 */
const SELLA_AC_CRON = 'sella_abandoned_cart_send';

/**
 * Days after an email in which an order counts as bought after it, and in
 * which the same address gets no second email.
 */
const SELLA_AC_WINDOW_DAYS = 14;

/**
 * The settings with their defaults.
 *
 * @return array
 */
function sella_ac_settings() {
	$stored = get_option( SELLA_AC_OPTION, [] );

	return wp_parse_args(
		is_array( $stored ) ? $stored : [],
		[
			// The email popup on the way to the checkout (sella-email-gate.php).
			'gate'      => 1,
			'enabled'   => 0,
			'min_total' => 500,
			'percent'   => 5,
			'delay'     => 2,
			// How long a code stays good. In hours: a day pushes the shopper to finish now.
			'hours'     => 24,
			'subject'   => 'שכחתם משהו בעגלה? 5% הנחה מחכים לכם',
			'subject_plain' => 'שכחתם משהו בעגלה?',
		]
	);
}

/**
 * The table's name.
 *
 * @return string
 */
function sella_ac_table() {
	global $wpdb;

	return $wpdb->prefix . 'sella_abandoned_carts';
}

/**
 * Whether the table is there.
 *
 * @return bool
 */
function sella_ac_ready() {
	return SELLA_AC_DB_VERSION === get_option( 'sella_ac_db_version' );
}

/**
 * Create or update the table.
 *
 * Status: open (waiting), sent, recovered (bought after the email), ordered
 * (bought before it was due), skipped, failed.
 *
 * @return void
 */
function sella_ac_install() {
	if ( sella_ac_ready() ) {
		return;
	}

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = sella_ac_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_key varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL,
			name varchar(120) NOT NULL DEFAULT '',
			items longtext NOT NULL,
			total decimal(12,2) NOT NULL DEFAULT 0,
			status varchar(10) NOT NULL DEFAULT 'open',
			note varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			sent_at datetime DEFAULT NULL,
			token varchar(32) NOT NULL DEFAULT '',
			coupon varchar(40) NOT NULL DEFAULT '',
			email_html longtext NULL,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			clicked_at datetime DEFAULT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_total decimal(12,2) NOT NULL DEFAULT 0,
			used_coupon tinyint(1) NOT NULL DEFAULT 0,
			recovered_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY session_status (session_key,status),
			KEY status_updated (status,updated_at),
			KEY email (email),
			KEY token (token)
		) {$charset};"
	);

	update_option( 'sella_ac_db_version', SELLA_AC_DB_VERSION );
}
add_action( 'admin_init', 'sella_ac_install' );

/* -------------------------------------------------------------------------
 * Keeping the cart
 * ---------------------------------------------------------------------- */

/**
 * The shopper's email, when we know it.
 *
 * @return string
 */
function sella_ac_current_email() {
	$email = WC()->customer ? (string) WC()->customer->get_billing_email() : '';

	if ( ! $email && is_user_logged_in() ) {
		$email = (string) wp_get_current_user()->user_email;
	}

	if ( ! $email && function_exists( 'sella_eg_guest_email' ) ) {
		$email = sella_eg_guest_email();
	}

	return is_email( $email ) ? strtolower( $email ) : '';
}

/**
 * The cart's lines as they can be put back: product, variation, quantity,
 * the line's own data (a length, a cut), and what the email shows of it.
 *
 * @param WC_Cart $cart Cart.
 * @return array
 */
function sella_ac_cart_items( $cart ) {
	$items = [];
	$skip  = [ 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ];

	foreach ( $cart->get_cart() as $line ) {
		$product = $line['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$extra = array_diff_key( $line, array_flip( $skip ) );
		$image = wp_get_attachment_image_url( $product->get_image_id() ? $product->get_image_id() : (int) get_post_thumbnail_id( $line['product_id'] ), 'woocommerce_thumbnail' );

		$items[] = [
			'product_id'   => (int) $line['product_id'],
			'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
			'variation'    => (array) ( $line['variation'] ?? [] ),
			'quantity'     => (float) $line['quantity'],
			'extra'        => $extra,
			'name'         => wp_strip_all_tags( $product->get_name() ),
			'total'        => round( (float) $line['line_total'] + (float) $line['line_tax'], 2 ),
			'image'        => $image ? $image : '',
		];
	}

	return $items;
}

/**
 * Keep the cart of a shopper we know by email, each time it changes.
 *
 * @param WC_Cart $cart Cart.
 * @return void
 */
function sella_ac_capture( $cart ) {
	if ( ! sella_ac_ready() || ! $cart instanceof WC_Cart || ! WC()->session || $cart->is_empty() || ( is_admin() && ! wp_doing_ajax() ) ) {
		return;
	}

	// A cart brought back by the email is that email's; it is not left again.
	if ( WC()->session->get( 'sella_ac_restored' ) ) {
		return;
	}

	$email = sella_ac_current_email();

	if ( ! $email ) {
		return;
	}

	$total = round( (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax(), 2 );
	$hash  = md5( $email . '|' . $cart->get_cart_hash() . '|' . $total );

	if ( WC()->session->get( 'sella_ac_hash' ) === $hash ) {
		return;
	}

	WC()->session->set( 'sella_ac_hash', $hash );

	global $wpdb;

	$table   = sella_ac_table();
	$session = (string) WC()->session->get_customer_id();
	$now     = current_time( 'mysql', true );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_key = %s AND status = 'open' ORDER BY id DESC LIMIT 1", $session ) );

	// An empty total, such as free samples only: nothing to remind of.
	if ( $total <= 0 ) {
		if ( $open ) {
			$wpdb->delete( $table, [ 'id' => $open ] );
		}

		return;
	}

	$name = WC()->customer ? trim( (string) WC()->customer->get_billing_first_name() ) : '';

	if ( ! $name && is_user_logged_in() ) {
		$name = (string) wp_get_current_user()->first_name;
	}

	$row = [
		'user_id'    => get_current_user_id(),
		'email'      => $email,
		'name'       => mb_substr( $name, 0, 120 ),
		'items'      => wp_json_encode( sella_ac_cart_items( $cart ), JSON_UNESCAPED_UNICODE ),
		'total'      => $total,
		'updated_at' => $now,
	];

	if ( $open ) {
		$wpdb->update( $table, $row, [ 'id' => $open ] );
	} else {
		$wpdb->insert( $table, $row + [ 'session_key' => $session, 'created_at' => $now, 'status' => 'open' ] );
	}
	// phpcs:enable
}
add_action( 'woocommerce_after_calculate_totals', 'sella_ac_capture', 99 );

/**
 * An emptied cart, most often an order just placed, has nothing to bring back.
 *
 * @return void
 */
function sella_ac_forget() {
	if ( ! sella_ac_ready() || ! WC()->session ) {
		return;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( sella_ac_table(), [ 'session_key' => (string) WC()->session->get_customer_id(), 'status' => 'open' ] );
	WC()->session->set( 'sella_ac_hash', null );
	WC()->session->set( 'sella_ac_restored', null );
}
add_action( 'woocommerce_cart_emptied', 'sella_ac_forget' );

/* -------------------------------------------------------------------------
 * Sending
 * ---------------------------------------------------------------------- */

/**
 * Every fifteen minutes.
 *
 * @param array $schedules Schedules.
 * @return array
 */
function sella_ac_schedules( $schedules ) {
	$schedules['sella_quarter_hour'] = [
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => 'Every 15 minutes',
	];

	return $schedules;
}
add_filter( 'cron_schedules', 'sella_ac_schedules' );

/**
 * Keep the sending on the schedule.
 *
 * @return void
 */
function sella_ac_schedule() {
	if ( ! wp_next_scheduled( SELLA_AC_CRON ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'sella_quarter_hour', SELLA_AC_CRON );
	}
}
add_action( 'init', 'sella_ac_schedule' );

/**
 * A hash of an address, for the list of those who asked for no more.
 *
 * @param string $email Email.
 * @return string
 */
function sella_ac_email_hash( $email ) {
	return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
}

/**
 * Whether an address asked for no more of these emails.
 *
 * @param string $email Email.
 * @return bool
 */
function sella_ac_stopped( $email ) {
	return in_array( sella_ac_email_hash( $email ), (array) get_option( SELLA_AC_STOPPED, [] ), true );
}

/**
 * Whether the shopper ordered since leaving the cart.
 *
 * An order still waiting for payment does not count: the step before paying
 * opens one under the guest's address the moment it is given
 * (sella-email-gate.php), and that order is the very cart left behind.
 *
 * @param object $row Cart row.
 * @return bool
 */
function sella_ac_ordered_since( $row ) {
	$statuses = array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ] );
	$since    = strtotime( $row->updated_at . ' UTC' ) - HOUR_IN_SECONDS;
	$args     = [
		'limit'        => 1,
		'return'       => 'ids',
		'status'       => $statuses,
		'date_created' => '>' . $since,
	];

	if ( wc_get_orders( $args + [ 'billing_email' => $row->email ] ) ) {
		return true;
	}

	return $row->user_id && wc_get_orders( $args + [ 'customer_id' => (int) $row->user_id ] );
}

/**
 * Update a row.
 *
 * @param int   $id   Row id.
 * @param array $data Columns.
 * @return void
 */
function sella_ac_update( $id, $data ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( sella_ac_table(), $data, [ 'id' => (int) $id ] );
}

/**
 * How long a code stays good, in seconds.
 *
 * @param array $settings Settings.
 * @return int
 */
function sella_ac_code_seconds( $settings ) {
	return (int) round( max( 1, min( 720, (float) $settings['hours'] ) ) * HOUR_IN_SECONDS );
}

/**
 * A code of its own for one cart: the percentage off the products that were
 * in it, for that address, once, until it expires.
 *
 * @param object $row      Cart row.
 * @param array  $settings Settings.
 * @return string The code, or an empty string when it could not be made.
 */
function sella_ac_make_coupon( $row, $settings ) {
	$items = json_decode( (string) $row->items, true );
	$ids   = [];

	foreach ( (array) $items as $item ) {
		$ids[] = (int) $item['product_id'];

		if ( ! empty( $item['variation_id'] ) ) {
			$ids[] = (int) $item['variation_id'];
		}
	}

	do {
		$code = 'BACK' . strtoupper( wp_generate_password( 6, false, false ) );
	} while ( wc_get_coupon_id_by_code( $code ) );

	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_description( sprintf( 'עגלה נטושה #%d, %s', $row->id, $row->email ) );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( (float) $settings['percent'] );
	$coupon->set_product_ids( array_values( array_unique( array_filter( $ids ) ) ) );
	$coupon->set_email_restrictions( [ $row->email ] );
	$coupon->set_usage_limit( 1 );
	$coupon->set_usage_limit_per_user( 1 );
	$coupon->set_individual_use( true );
	$coupon->set_date_expires( time() + sella_ac_code_seconds( $settings ) );
	// Only on an order of the same size or more: the discount pays for a large order, not a small one.
	$coupon->set_minimum_amount( (float) $settings['min_total'] );

	return $coupon->save() ? $code : '';
}

/**
 * Send what is due: carts left long enough, worth enough, whose shopper has
 * not ordered since, has not had one of these lately, and has not said stop.
 *
 * @return void
 */
function sella_ac_send_due() {
	$settings = sella_ac_settings();

	if ( ! sella_ac_ready() || empty( $settings['enabled'] ) || ! function_exists( 'WC' ) ) {
		return;
	}

	global $wpdb;

	$table  = sella_ac_table();
	$before = gmdate( 'Y-m-d H:i:s', time() - (float) $settings['delay'] * HOUR_IN_SECONDS );
	// A cart left more than three days before is past saving; it is not written to now.
	$after = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$due = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'open' AND updated_at <= %s AND updated_at >= %s ORDER BY updated_at ASC LIMIT 20",
			$before,
			$after
		)
	);

	foreach ( $due as $row ) {
		if ( sella_ac_stopped( $row->email ) ) {
			sella_ac_update( $row->id, [ 'status' => 'skipped', 'note' => 'ביקש לא לקבל מיילים כאלה' ] );
			continue;
		}

		$recent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE email = %s AND sent_at >= %s",
				$row->email,
				gmdate( 'Y-m-d H:i:s', time() - SELLA_AC_WINDOW_DAYS * DAY_IN_SECONDS )
			)
		);

		if ( $recent ) {
			sella_ac_update( $row->id, [ 'status' => 'skipped', 'note' => 'קיבל מייל כזה ב-14 הימים האחרונים' ] );
			continue;
		}

		if ( sella_ac_ordered_since( $row ) ) {
			sella_ac_update( $row->id, [ 'status' => 'ordered', 'note' => 'הזמין לפני שהמייל נשלח' ] );
			continue;
		}

		sella_ac_send( $row, $settings );
	}
	// phpcs:enable
}
add_action( SELLA_AC_CRON, 'sella_ac_send_due' );

/**
 * Send one cart's email, and keep what was sent.
 *
 * @param object $row      Cart row.
 * @param array  $settings Settings.
 * @return bool
 */
function sella_ac_send( $row, $settings ) {
	$deserves = (float) $row->total >= (float) $settings['min_total'];
	$code     = $deserves ? sella_ac_make_coupon( $row, $settings ) : '';

	if ( $deserves && ! $code ) {
		sella_ac_update( $row->id, [ 'status' => 'failed', 'note' => 'קוד ההנחה לא נוצר' ] );
		return false;
	}

	$token = wp_generate_password( 24, false, false );
	$html  = sella_ac_email_html(
		$row,
		$code,
		$settings,
		add_query_arg( 'sella_cart', $token, home_url( '/' ) ),
		add_query_arg( 'sella_cart_stop', $token, home_url( '/' ) )
	);

	$error = '';
	$catch = static function ( $wp_error ) use ( &$error ) {
		$error = $wp_error instanceof WP_Error ? $wp_error->get_error_message() : 'unknown';
	};

	add_action( 'wp_mail_failed', $catch );
	$sent = wp_mail( $row->email, (string) ( $code ? $settings['subject'] : $settings['subject_plain'] ), $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
	remove_action( 'wp_mail_failed', $catch );

	sella_ac_update(
		$row->id,
		[
			'status'     => $sent ? 'sent' : 'failed',
			'note'       => $sent ? '' : mb_substr( 'השליחה נכשלה: ' . $error, 0, 190 ),
			'sent_at'    => current_time( 'mysql', true ),
			'token'      => $token,
			'coupon'     => $code,
			'email_html' => $html,
		]
	);

	return (bool) $sent;
}

/**
 * The email: the products left, the code when the cart earned one, and the
 * button that brings the cart back, with the code on it.
 *
 * @param object $row      Cart row.
 * @param string $code     Discount code, or an empty string for the reminder alone.
 * @param array  $settings Settings.
 * @param string $link     The way back.
 * @param string $stop     The way out.
 * @return string Whole HTML, styled as the shop's other emails.
 */
function sella_ac_email_html( $row, $code, $settings, $link, $stop ) {
	$items   = (array) json_decode( (string) $row->items, true );
	$percent = (float) $settings['percent'];
	$expires = wp_date( 'j.n.Y בשעה H:i', time() + sella_ac_code_seconds( $settings ) );
	$hello   = $row->name ? sprintf( 'שלום %s,', $row->name ) : 'שלום,';

	ob_start();
	?>
	<p><?php echo esc_html( $hello ); ?></p>
	<?php if ( $code ) : ?>
		<p>ראינו שהשארתם בעגלה כמה מוצרים ולא השלמתם את ההזמנה. שמרנו לכם אותם, ובשביל ההזמנה הזו בדיוק צירפנו הנחה של <?php echo esc_html( wc_format_localized_decimal( $percent ) ); ?>%.</p>
	<?php else : ?>
		<p>ראינו שהשארתם בעגלה כמה מוצרים ולא השלמתם את ההזמנה. שמרנו לכם אותם, והם מחכים לכם בדיוק כמו שהשארתם.</p>
	<?php endif; ?>

	<table class="td" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse:collapse;margin:18px 0 6px">
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td style="padding:10px 0;border-bottom:1px solid #efefef;width:64px;vertical-align:middle">
					<?php if ( ! empty( $item['image'] ) ) : ?>
						<img src="<?php echo esc_url( $item['image'] ); ?>" alt="" width="56" height="56" style="border:1px solid #e5e5e5;border-radius:8px;display:block;height:56px;width:56px;object-fit:cover">
					<?php endif; ?>
				</td>
				<td style="padding:10px 12px;border-bottom:1px solid #efefef;vertical-align:middle">
					<strong style="color:#161616"><?php echo esc_html( $item['name'] ); ?></strong>
					<?php if ( ! empty( $item['label'] ) ) : ?>
						<br><span style="color:#6d6a65;font-size:13px"><?php echo esc_html( $item['label'] ); ?></span>
					<?php endif; ?>
					<br><span style="color:#6d6a65;font-size:13px">כמות: <?php echo esc_html( wc_stock_amount( $item['quantity'] ) ); ?></span>
				</td>
				<td style="padding:10px 0;border-bottom:1px solid #efefef;vertical-align:middle;white-space:nowrap;text-align:left">
					<?php echo wp_kses_post( wc_price( $item['total'] ) ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<td></td>
			<td style="padding:12px;color:#161616;font-weight:700">סה״כ בעגלה</td>
			<td style="padding:12px 0;color:#161616;font-weight:700;white-space:nowrap;text-align:left"><?php echo wp_kses_post( wc_price( $row->total ) ); ?></td>
		</tr>
	</table>

	<?php if ( $code ) : ?>
		<div style="border:1px dashed #746fed;border-radius:10px;margin:18px 0;padding:16px;text-align:center">
			<p style="margin:0 0 6px;color:#3d3a36">קוד ההנחה שלכם, <?php echo esc_html( wc_format_localized_decimal( $percent ) ); ?>% על המוצרים האלה:</p>
			<p style="margin:0;color:#161616;font-size:22px;font-weight:700;letter-spacing:2px" dir="ltr"><?php echo esc_html( $code ); ?></p>
			<p style="margin:6px 0 0;color:#6d6a65;font-size:13px">בתוקף עד <?php echo esc_html( $expires ); ?>, לשימוש אחד, בהזמנה של <?php echo wp_kses_post( wc_price( (float) $settings['min_total'], [ 'decimals' => 0 ] ) ); ?> ומעלה</p>
		</div>
	<?php endif; ?>

	<p style="text-align:center;margin:22px 0">
		<a href="<?php echo esc_url( $link ); ?>" style="background:#161616;border-radius:8px;color:#ffffff;display:inline-block;font-weight:700;padding:14px 28px;text-decoration:none"><?php echo $code ? 'להשלמת ההזמנה עם ההנחה' : 'להשלמת ההזמנה'; ?></a>
	</p>
	<p style="text-align:center;color:#6d6a65;font-size:13px"><?php echo $code ? 'הכפתור מחזיר את העגלה כמו שהשארתם אותה ומעביר לתשלום, עם ההנחה כבר מוזנת.' : 'הכפתור מחזיר את העגלה כמו שהשארתם אותה ומעביר לתשלום.'; ?></p>

	<p style="text-align:center;color:#8a8781;font-size:12px;margin-top:24px">
		לא רוצים לקבל תזכורות כאלה? <a href="<?php echo esc_url( $stop ); ?>" style="color:#8a8781">להסרה</a>
	</p>
	<?php
	$content = (string) ob_get_clean();

	if ( ! class_exists( 'WC_Email' ) ) {
		return $content;
	}

	$email = new WC_Email();

	return $email->style_inline( WC()->mailer()->wrap_message( 'העגלה שלכם מחכה', $content ) );
}

/* -------------------------------------------------------------------------
 * The way back, and the way out
 * ---------------------------------------------------------------------- */

/**
 * The email's row for a link's token.
 *
 * @param string $token Token.
 * @return object|null
 */
function sella_ac_row_by_token( $token ) {
	if ( ! preg_match( '/^[A-Za-z0-9]{24}$/', $token ) || ! sella_ac_ready() ) {
		return null;
	}

	global $wpdb;

	$table = sella_ac_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ) );
}

/**
 * Bring the cart back with the code on it, count the click, and open the checkout.
 *
 * @return void
 */
function sella_ac_restore() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the key.
	if ( isset( $_GET['sella_cart_stop'] ) ) {
		sella_ac_stop( sanitize_text_field( wp_unslash( $_GET['sella_cart_stop'] ) ) );
		return;
	}

	if ( ! isset( $_GET['sella_cart'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$row = sella_ac_row_by_token( sanitize_text_field( wp_unslash( $_GET['sella_cart'] ) ) );
	// phpcs:enable

	if ( ! $row ) {
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	sella_ac_update(
		$row->id,
		[
			'clicks'     => (int) $row->clicks + 1,
			'clicked_at' => $row->clicked_at ? $row->clicked_at : current_time( 'mysql', true ),
		]
	);

	if ( WC()->session && ! WC()->session->has_session() ) {
		WC()->session->set_customer_session_cookie( true );
	}

	// The cart as it was left, in place of whatever is in it now.
	WC()->cart->empty_cart();

	foreach ( (array) json_decode( (string) $row->items, true ) as $item ) {
		$product = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );

		if ( $product && $product->is_purchasable() ) {
			WC()->cart->add_to_cart( (int) $item['product_id'], (float) $item['quantity'], (int) $item['variation_id'], (array) $item['variation'], (array) $item['extra'] );
		}
	}

	/*
	 * Straight on to the checkout under the address the email went to: past
	 * the email popup, with the address in the email field. The link came to
	 * that inbox, which is all the step asks to know.
	 */
	if ( ! is_user_logged_in() && WC()->session ) {
		WC()->session->set( 'sella_guest', true );
		WC()->session->set( 'sella_guest_email', $row->email );

		if ( function_exists( 'sella_eg_remember_guest' ) ) {
			sella_eg_remember_guest( $row->email );
		}
	}

	// The code is for this address only, so the address goes in before the code.
	if ( WC()->customer && ( ! is_user_logged_in() || ! WC()->customer->get_billing_email() ) ) {
		WC()->customer->set_billing_email( $row->email );
		WC()->customer->save();
	}

	wc_clear_notices();

	if ( $row->coupon && ! WC()->cart->has_discount( $row->coupon ) ) {
		WC()->cart->apply_coupon( $row->coupon );
	}

	WC()->session->set( 'sella_ac_restored', (int) $row->id );
	WC()->session->save_data();
	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}
add_action( 'template_redirect', 'sella_ac_restore', 1 );

/**
 * No more of these emails to the address a link was sent to.
 *
 * @param string $token Token.
 * @return void
 */
function sella_ac_stop( $token ) {
	$row = sella_ac_row_by_token( $token );

	if ( $row ) {
		$stopped   = (array) get_option( SELLA_AC_STOPPED, [] );
		$stopped[] = sella_ac_email_hash( $row->email );

		update_option( SELLA_AC_STOPPED, array_values( array_unique( $stopped ) ), false );
	}

	wp_die(
		'הוסרתם מתזכורות העגלה. לא נשלח לכתובת הזו מיילים כאלה יותר.',
		'הוסרתם',
		[
			'response'  => 200,
			'link_url'  => home_url( '/' ),
			'link_text' => 'לחנות',
		]
	);
}

/* -------------------------------------------------------------------------
 * Who bought
 * ---------------------------------------------------------------------- */

/**
 * A paid order from an address emailed in the last fourteen days counts as
 * bought after the email, with or without the code.
 *
 * @param int $order_id Order id.
 * @return void
 */
function sella_ac_order_paid( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || ! sella_ac_ready() ) {
		return;
	}

	global $wpdb;

	$table = sella_ac_table();
	$email = strtolower( (string) $order->get_billing_email() );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d LIMIT 1", $order->get_id() ) ) ) {
		return;
	}

	$codes = array_map( 'strtolower', $order->get_coupon_codes() );
	$row   = null;

	// The email whose code the order used, else the latest one sent to its address.
	if ( $codes ) {
		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$row          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'sent' AND LOWER(coupon) IN ({$placeholders}) LIMIT 1", $codes ) );
	}

	if ( ! $row && $email ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'sent' AND email = %s AND sent_at >= %s ORDER BY sent_at DESC LIMIT 1",
				$email,
				gmdate( 'Y-m-d H:i:s', time() - SELLA_AC_WINDOW_DAYS * DAY_IN_SECONDS )
			)
		);
	}
	// phpcs:enable

	if ( ! $row ) {
		return;
	}

	sella_ac_update(
		$row->id,
		[
			'status'       => 'recovered',
			'order_id'     => $order->get_id(),
			'order_total'  => (float) $order->get_total(),
			'used_coupon'  => in_array( strtolower( $row->coupon ), $codes, true ) ? 1 : 0,
			'recovered_at' => current_time( 'mysql', true ),
		]
	);
}
add_action( 'woocommerce_order_status_processing', 'sella_ac_order_paid' );
add_action( 'woocommerce_order_status_completed', 'sella_ac_order_paid' );

/**
 * A cart waiting for its email is closed the moment the same address, or the
 * same account, places an order, from whatever device, so nothing goes out
 * and the waiting list stays true. The check before sending stays as well.
 *
 * @param int $order_id Order id.
 * @return void
 */
function sella_ac_close_open_carts( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || ! sella_ac_ready() ) {
		return;
	}

	global $wpdb;

	$table = sella_ac_table();
	$email = strtolower( (string) $order->get_billing_email() );
	$user  = (int) $order->get_customer_id();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'ordered', note = %s WHERE status = 'open' AND ( email = %s OR ( %d > 0 AND user_id = %d ) )",
			sprintf( 'הזמין (#%s) לפני שהמייל נשלח', $order->get_order_number() ),
			$email,
			$user,
			$user
		)
	);
}
add_action( 'woocommerce_order_status_processing', 'sella_ac_close_open_carts' );
add_action( 'woocommerce_order_status_completed', 'sella_ac_close_open_carts' );
add_action( 'woocommerce_order_status_on-hold', 'sella_ac_close_open_carts' );

/* -------------------------------------------------------------------------
 * The admin page
 * ---------------------------------------------------------------------- */

/**
 * Register the page under the Upsell popups menu.
 *
 * @return void
 */
function sella_ac_menu() {
	add_submenu_page( 'sella-upsell-popups', 'עגלות נטושות', 'עגלות נטושות', 'manage_woocommerce', 'sella-abandoned-carts', 'sella_ac_page' );
}
add_action( 'admin_menu', 'sella_ac_menu', 12 );

/**
 * Show an email as it was sent, or a sample of it.
 *
 * @return void
 */
function sella_ac_view_email() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	check_admin_referer( 'sella_ac_view' );

	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

	global $wpdb;

	$table = sella_ac_table();

	if ( $id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$html = (string) $wpdb->get_var( $wpdb->prepare( "SELECT email_html FROM {$table} WHERE id = %d", $id ) );
	} else {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$plain = ! empty( $_GET['plain'] );
		$html  = sella_ac_email_html( sella_ac_sample_row(), $plain ? '' : 'BACKSAMPLE', sella_ac_settings(), home_url( '/' ), home_url( '/' ) );
	}

	header( 'Content-Type: text/html; charset=UTF-8' );
	echo $html ? $html : 'המייל לא נשמר.'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the email the site itself built.
	exit;
}
add_action( 'admin_post_sella_ac_view', 'sella_ac_view_email' );

/**
 * A cart to show the email with: the latest one waiting, or two products.
 *
 * @return object
 */
function sella_ac_sample_row() {
	global $wpdb;

	$table = sella_ac_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = sella_ac_ready() ? $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" ) : null;

	if ( $row ) {
		return $row;
	}

	$items = [];
	$total = 0;

	foreach ( wc_get_products( [ 'limit' => 2, 'status' => 'publish', 'orderby' => 'rand', 'type' => 'simple' ] ) as $product ) {
		$price   = (float) wc_get_price_to_display( $product ) * 2;
		$total  += $price;
		$items[] = [
			'name'     => $product->get_name(),
			'label'    => '',
			'quantity' => 2,
			'total'    => $price,
			'image'    => (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
		];
	}

	return (object) [
		'id'    => 0,
		'name'  => 'ישראל',
		'items' => wp_json_encode( $items ),
		'total' => $total,
	];
}

/**
 * Save the settings.
 *
 * @param array $source Posted data.
 * @return void
 */
function sella_ac_save( $source ) {
	$number = static function ( $key, $min, $max ) use ( $source ) {
		return isset( $source[ $key ] ) ? max( $min, min( $max, (float) wp_unslash( $source[ $key ] ) ) ) : $min;
	};

	update_option(
		SELLA_AC_OPTION,
		[
			'gate'      => empty( $source['ac_gate'] ) ? 0 : 1,
			'enabled'   => empty( $source['ac_enabled'] ) ? 0 : 1,
			'min_total' => $number( 'ac_min_total', 0, 1000000 ),
			'percent'   => $number( 'ac_percent', 1, 50 ),
			'delay'     => $number( 'ac_delay', 0.5, 72 ),
			'hours'     => $number( 'ac_hours', 1, 720 ),
			'subject'   => isset( $source['ac_subject'] ) && '' !== trim( (string) $source['ac_subject'] ) ? sanitize_text_field( wp_unslash( $source['ac_subject'] ) ) : sella_ac_settings()['subject'],
			'subject_plain' => isset( $source['ac_subject_plain'] ) && '' !== trim( (string) $source['ac_subject_plain'] ) ? sanitize_text_field( wp_unslash( $source['ac_subject_plain'] ) ) : sella_ac_settings()['subject_plain'],
		],
		false
	);
}

/**
 * The figures for the last days.
 *
 * @param int $days Days.
 * @return array
 */
function sella_ac_totals( $days ) {
	global $wpdb;

	$table = sella_ac_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				SUM(sent_at IS NOT NULL AND status IN ('sent','recovered')) AS sent,
				SUM(clicked_at IS NOT NULL) AS clicked,
				SUM(clicks) AS clicks,
				SUM(status = 'recovered') AS bought,
				SUM(status = 'recovered' AND used_coupon = 1) AS with_code,
				SUM(CASE WHEN status = 'recovered' THEN order_total ELSE 0 END) AS revenue
			FROM {$table} WHERE sent_at >= %s",
			gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
		),
		ARRAY_A
	);

	return array_map( 'floatval', (array) $row );
}

/**
 * Render the page.
 *
 * @return void
 */
function sella_ac_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$saved = false;

	if ( isset( $_POST['sella_ac_save'] ) && check_admin_referer( 'sella_ac_save' ) ) {
		sella_ac_save( $_POST );
		$saved = true;
	}

	$settings = sella_ac_settings();
	$ready    = sella_ac_ready();
	$view     = static function ( $id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=sella_ac_view&id=' . (int) $id ), 'sella_ac_view' );
	};
	$when     = static function ( $value ) {
		return $value ? wp_date( 'j.n.Y H:i', strtotime( $value . ' UTC' ) ) : '—';
	};
	$labels   = [
		'sent'      => 'נשלח',
		'recovered' => 'רכש',
		'failed'    => 'נכשל',
		'skipped'   => 'דולג',
		'ordered'   => 'הזמין לבד',
	];
	?>
	<div class="wrap sella-ac">
		<h1>עגלות נטושות</h1>
		<p class="description">
			עגלה של לקוח שהכתובת שלו ידועה, שלא הפכה להזמנה תוך <?php echo esc_html( number_format_i18n( (float) $settings['delay'], 1 ) ); ?> שעות, מקבלת מייל תזכורת אחד.
			עגלה בשווי <?php echo esc_html( number_format_i18n( (float) $settings['min_total'] ) ); ?> ₪ ומעלה מקבלת בו גם קוד אישי של <?php echo esc_html( number_format_i18n( (float) $settings['percent'] ) ); ?>% הנחה על המוצרים שבה, שעובד רק בהזמנה של <?php echo esc_html( number_format_i18n( (float) $settings['min_total'] ) ); ?> ₪ ומעלה.
			הכפתור במייל מחזיר את העגלה ומעביר לתשלום, עם הקוד כבר מוזן.
			לקוח לא יקבל יותר ממייל אחד כזה ב-14 יום, ולא יקבל בכלל אם הזמין בינתיים או ביקש להסיר.
		</p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success inline"><p>ההגדרות נשמרו.</p></div>
		<?php endif; ?>

		<?php if ( empty( $settings['enabled'] ) ) : ?>
			<div class="notice notice-warning inline"><p>המנגנון כבוי. בדקו איך המייל נראה, ואז סמנו "לשלוח" ושמרו.</p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'sella_ac_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">פופאפ מייל</th>
					<td>
						<label><input type="checkbox" id="ac_gate" name="ac_gate" value="1" <?php checked( ! empty( $settings['gate'] ) ); ?>> לבקש מייל מאורחים לפני המעבר לתשלום</label>
						<p class="description">המייל נכנס לשדה המייל בדף התשלום, ונפתחת הזמנה ממתינה לתשלום עם העגלה. בלי המייל אין לאן לשלוח את מייל העגלה הנטושה לאורחים.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">מצב</th>
					<td><label><input type="checkbox" id="ac_enabled" name="ac_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> לשלוח מיילים לעגלות נטושות</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="ac_min_total">סכום מינימלי לקוד הנחה</label></th>
					<td>
						<input type="number" class="small-text" min="0" step="1" id="ac_min_total" name="ac_min_total" value="<?php echo esc_attr( (string) $settings['min_total'] ); ?>"> ₪, כולל מע״מ ולפני משלוח
						<p class="description">עגלה מתחת לסכום מקבלת תזכורת בלי קוד. הקוד עצמו עובד רק בהזמנה מהסכום הזה ומעלה.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ac_percent">הנחה</label></th>
					<td><input type="number" class="small-text" min="1" max="50" step="0.5" id="ac_percent" name="ac_percent" value="<?php echo esc_attr( (string) $settings['percent'] ); ?>"> %</td>
				</tr>
				<tr>
					<th scope="row"><label for="ac_delay">לשלוח אחרי</label></th>
					<td><input type="number" class="small-text" min="0.5" max="72" step="0.5" id="ac_delay" name="ac_delay" value="<?php echo esc_attr( (string) $settings['delay'] ); ?>"> שעות בלי שינוי בעגלה ובלי הזמנה</td>
				</tr>
				<tr>
					<th scope="row"><label for="ac_hours">תוקף הקוד</label></th>
					<td><input type="number" class="small-text" min="1" max="720" step="1" id="ac_hours" name="ac_hours" value="<?php echo esc_attr( (string) $settings['hours'] ); ?>"> שעות מרגע שליחת המייל</td>
				</tr>
				<tr>
					<th scope="row"><label for="ac_subject">נושא המייל עם קוד</label></th>
					<td><input type="text" class="regular-text" id="ac_subject" name="ac_subject" value="<?php echo esc_attr( $settings['subject'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ac_subject_plain">נושא המייל בלי קוד</label></th>
					<td><input type="text" class="regular-text" id="ac_subject_plain" name="ac_subject_plain" value="<?php echo esc_attr( $settings['subject_plain'] ); ?>"></td>
				</tr>
			</table>
			<p>
				<?php submit_button( 'שמירה', 'primary', 'sella_ac_save', false ); ?>
				<a class="button" href="<?php echo esc_url( $view( 0 ) ); ?>" target="_blank" rel="noopener">איך המייל עם הקוד נראה</a>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'plain', 1, $view( 0 ) ) ); ?>" target="_blank" rel="noopener">איך המייל בלי הקוד נראה</a>
			</p>
		</form>

		<?php if ( ! $ready ) : ?>
			<div class="notice notice-warning inline"><p>הטבלה עוד לא נוצרה. רעננו את העמוד.</p></div>
		</div>
			<?php
			return;
		endif;

		$totals = sella_ac_totals( 30 );

		global $wpdb;

		$table = sella_ac_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$log     = $wpdb->get_results( "SELECT id, email, name, total, status, note, sent_at, coupon, clicks, clicked_at, order_id, order_total, used_coupon FROM {$table} WHERE status NOT IN ('open') ORDER BY COALESCE(sent_at, updated_at) DESC LIMIT 100" );
		$waiting = $wpdb->get_results( "SELECT email, total, updated_at FROM {$table} WHERE status = 'open' ORDER BY updated_at DESC LIMIT 30" );
		// phpcs:enable
		?>

		<h2>30 הימים האחרונים</h2>
		<div class="sella-ac__tiles">
			<div class="sella-ac__tile"><span>מיילים שנשלחו</span><strong><?php echo esc_html( number_format_i18n( $totals['sent'] ) ); ?></strong></div>
			<div class="sella-ac__tile"><span>לחצו על המייל</span><strong><?php echo esc_html( number_format_i18n( $totals['clicked'] ) ); ?></strong><em><?php echo esc_html( $totals['sent'] ? number_format_i18n( 100 * $totals['clicked'] / $totals['sent'] ) . '% · ' : '' ); ?><?php echo esc_html( number_format_i18n( $totals['clicks'] ) ); ?> לחיצות</em></div>
			<div class="sella-ac__tile"><span>רכשו אחרי המייל</span><strong><?php echo esc_html( number_format_i18n( $totals['bought'] ) ); ?></strong><em><?php echo esc_html( number_format_i18n( $totals['with_code'] ) ); ?> עם הקוד</em></div>
			<div class="sella-ac__tile"><span>סכום ההזמנות</span><strong><?php echo wp_kses_post( wc_price( $totals['revenue'] ) ); ?></strong></div>
		</div>

		<h2>המיילים</h2>
		<table class="widefat striped">
			<thead><tr><th>נשלח</th><th>לקוח</th><th>עגלה</th><th>קוד</th><th>מצב</th><th>לחיצות</th><th>הזמנה</th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $when( $row->sent_at ) ); ?></td>
						<td><?php echo esc_html( trim( $row->name . ' ' . $row->email ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $row->total ) ); ?></td>
						<td dir="ltr"><?php echo esc_html( $row->coupon ? $row->coupon : '—' ); ?></td>
						<td>
							<?php echo esc_html( $labels[ $row->status ] ?? $row->status ); ?>
							<?php if ( $row->note ) : ?>
								<br><span class="description"><?php echo esc_html( $row->note ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $row->clicks ? esc_html( number_format_i18n( (int) $row->clicks ) . ' · ' . $when( $row->clicked_at ) ) : '—'; ?></td>
						<td>
							<?php if ( $row->order_id ) : ?>
								<?php $order = wc_get_order( (int) $row->order_id ); ?>
								<a href="<?php echo esc_url( $order ? $order->get_edit_order_url() : '' ); ?>">#<?php echo esc_html( $order ? $order->get_order_number() : (string) $row->order_id ); ?></a>
								· <?php echo wp_kses_post( wc_price( $row->order_total ) ); ?>
								<?php echo $row->coupon ? ( $row->used_coupon ? ' · עם הקוד' : ' · בלי הקוד' ) : ''; ?>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $row->sent_at ) : ?>
								<a href="<?php echo esc_url( $view( $row->id ) ); ?>" target="_blank" rel="noopener">צפייה במייל</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $log ) : ?>
					<tr><td colspan="8">עוד לא נשלחו מיילים.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<h2>עגלות שמחכות</h2>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th>לקוח</th><th>עגלה</th><th>שינוי אחרון</th><th>המייל ייצא בערך</th></tr></thead>
			<tbody>
				<?php foreach ( $waiting as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->email ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $row->total ) ); ?></td>
						<td><?php echo esc_html( $when( $row->updated_at ) ); ?></td>
						<td><?php echo esc_html( empty( $settings['enabled'] ) ? 'המנגנון כבוי' : wp_date( 'j.n.Y H:i', strtotime( $row->updated_at . ' UTC' ) + (int) ( (float) $settings['delay'] * HOUR_IN_SECONDS ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $waiting ) : ?>
					<tr><td colspan="4">אין עגלות שמחכות.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<style>
		.sella-ac h2 { margin-top: 28px; }
		.sella-ac__tiles { display: flex; flex-wrap: wrap; gap: 16px; }
		.sella-ac__tile { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; display: flex; flex-direction: column; gap: 4px; min-width: 190px; padding: 14px 18px; }
		.sella-ac__tile span { color: #50575e; font-size: 13px; font-weight: 600; }
		.sella-ac__tile strong { font-size: 28px; font-variant-numeric: tabular-nums; line-height: 1.2; }
		.sella-ac__tile em { color: #50575e; font-style: normal; }
	</style>
	<?php
}
