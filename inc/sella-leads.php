<?php
/**
 * Inquiries and leads: one admin list of everything visitors leave on the site.
 *
 * - The contact form, [sella_contact_form]: full name, email, mobile phone,
 *   an optional order number and the message. Each inquiry is also emailed to
 *   the address set on the admin screen.
 * - Newsletter signups: the HTML form in the newsletter popup (form.newsletter-form,
 *   caught by assets/js/sella-leads-capture.js) and every Elementor Pro form,
 *   such as the footer's.
 * - An address given in the popup before checkout (sella-email-gate.php), a
 *   back-in-stock request (sella-back-in-stock.php) and a request for a book's
 *   first chapter (sella-first-chapter.php), which call sella_leads_add().
 *
 * The pages are cached (sella-page-cache.php), so a form cannot carry a nonce
 * that would still be valid when the cached copy is served. Spam is kept out
 * with a hidden field bots fill in, a minimum time to fill the form and a
 * per-address rate limit.
 *
 * Admin: פניות ולידים (?page=sella-leads).
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_LEADS_DB_VERSION', '1' );
define( 'SELLA_LEADS_SLUG', 'sella-leads' );
define( 'SELLA_LEADS_SETTINGS', 'sella_leads_settings' );
define( 'SELLA_LEADS_PER_PAGE', 50 );
define( 'SELLA_CONTACT_THANKS', 'תודה על פנייתך. שירות הלקוחות שלנו ייצור איתך קשר בהקדם.' );

/**
 * ---------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------------
 */

/**
 * @return string
 */
function sella_leads_table() {
	global $wpdb;
	return $wpdb->prefix . 'sella_leads';
}

/**
 * Create or upgrade the table when the schema version changes.
 */
function sella_leads_maybe_install() {
	if ( SELLA_LEADS_DB_VERSION === get_option( 'sella_leads_db_version' ) ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table = sella_leads_table();

	// dbDelta is picky: one column per line and two spaces after PRIMARY KEY.
	dbDelta(
		"CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		type varchar(30) NOT NULL DEFAULT '',
		status varchar(20) NOT NULL DEFAULT 'new',
		name varchar(190) NOT NULL DEFAULT '',
		email varchar(190) NOT NULL DEFAULT '',
		phone varchar(40) NOT NULL DEFAULT '',
		order_ref varchar(40) NOT NULL DEFAULT '',
		message text NULL,
		source varchar(190) NOT NULL DEFAULT '',
		page_url varchar(255) NOT NULL DEFAULT '',
		fields longtext NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY type_status (type,status),
		KEY email (email),
		KEY created_at (created_at)
		) {$wpdb->get_charset_collate()};"
	);

	// Only store the version once the table really exists, so a failed run retries.
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		update_option( 'sella_leads_db_version', SELLA_LEADS_DB_VERSION );
	}
}
add_action( 'init', 'sella_leads_maybe_install', 5 );

/**
 * The kinds of lead, as the admin list names them.
 *
 * @return array<string, string>
 */
function sella_leads_types() {
	return array(
		'contact'        => 'צור קשר',
		'newsletter'     => 'ניוזלטר',
		'checkout_email' => 'מייל לפני תשלום',
		'back_in_stock'  => 'חזרה למלאי',
		'first_chapter'  => 'פרק ראשון',
		'form'           => 'טפסים אחרים',
	);
}

/**
 * Kinds kept once per address (and source): signing up twice is one signup.
 *
 * @return array<int, string>
 */
function sella_leads_single_types() {
	return array( 'newsletter', 'checkout_email', 'back_in_stock', 'first_chapter' );
}

/**
 * Screen settings.
 *
 * @return array{notify: bool, notify_email: string}
 */
function sella_leads_settings() {
	$saved = get_option( SELLA_LEADS_SETTINGS, array() );
	$saved = is_array( $saved ) ? $saved : array();

	return array(
		'notify'       => isset( $saved['notify'] ) ? (bool) $saved['notify'] : true,
		'notify_email' => ! empty( $saved['notify_email'] ) ? (string) $saved['notify_email'] : (string) get_option( 'admin_email' ),
	);
}

/**
 * Record a lead. Other modules call this.
 *
 * @param array $lead type, and any of name, email, phone, order_ref, message,
 *                    source, page_url, fields (array of label => value).
 * @return int|false The row ID, or false when it could not be saved.
 */
function sella_leads_add( $lead ) {
	global $wpdb;

	$types = sella_leads_types();
	$type  = isset( $lead['type'], $types[ $lead['type'] ] ) ? $lead['type'] : 'form';
	$email = isset( $lead['email'] ) ? strtolower( sanitize_email( $lead['email'] ) ) : '';
	$table = sella_leads_table();

	$row = array(
		'type'       => $type,
		'status'     => 'new',
		'name'       => mb_substr( sanitize_text_field( $lead['name'] ?? '' ), 0, 190 ),
		'email'      => mb_substr( $email, 0, 190 ),
		'phone'      => mb_substr( sanitize_text_field( $lead['phone'] ?? '' ), 0, 40 ),
		'order_ref'  => mb_substr( sanitize_text_field( $lead['order_ref'] ?? '' ), 0, 40 ),
		'message'    => sanitize_textarea_field( $lead['message'] ?? '' ),
		'source'     => mb_substr( sanitize_text_field( $lead['source'] ?? '' ), 0, 190 ),
		'page_url'   => mb_substr( esc_url_raw( $lead['page_url'] ?? '' ), 0, 255 ),
		'fields'     => ! empty( $lead['fields'] ) && is_array( $lead['fields'] ) ? wp_json_encode( $lead['fields'], JSON_UNESCAPED_UNICODE ) : null,
		'created_at' => current_time( 'mysql' ),
	);

	if ( '' !== $row['email'] && in_array( $type, sella_leads_single_types(), true ) ) {
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND email = %s AND source = %s LIMIT 1", $type, $row['email'], $row['source'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return (int) $existing;
		}
	}

	$saved = $wpdb->insert( $table, $row );
	return $saved ? (int) $wpdb->insert_id : false;
}

/**
 * The visitor's address, for the rate limit only; it is not stored.
 *
 * @return string
 */
function sella_leads_ip() {
	if ( class_exists( 'WC_Geolocation' ) ) {
		return (string) WC_Geolocation::get_ip_address();
	}
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Count one more submission from this address; false once it is over the limit.
 *
 * @param string $bucket Which form.
 * @param int    $max    Submissions allowed in the window.
 * @return bool
 */
function sella_leads_rate_ok( $bucket, $max ) {
	$key  = 'sella_leads_' . $bucket . '_' . md5( sella_leads_ip() );
	$hits = (int) get_transient( $key );
	if ( $hits >= $max ) {
		return false;
	}
	set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );
	return true;
}

/**
 * The page a form was sent from, if it is on this site.
 *
 * @param string $url Posted URL.
 * @return string
 */
function sella_leads_page_url( $url ) {
	$url = esc_url_raw( (string) $url );
	return '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ? $url : '';
}

/**
 * ---------------------------------------------------------------------------
 * Contact form
 * ---------------------------------------------------------------------------
 */

/**
 * An Israeli mobile number as 05XXXXXXXX, a foreign one as +XXXXXXXXX, or ''
 * when it is neither.
 *
 * @param string $phone Typed number.
 * @return string
 */
function sella_contact_normalize_phone( $phone ) {
	$phone = preg_replace( '/[\s\-\.\(\)\/]/', '', (string) $phone );

	if ( preg_match( '/^(?:\+|00)?972(5\d{8})$/', $phone, $m ) ) {
		return '0' . $m[1];
	}
	if ( preg_match( '/^05\d{8}$/', $phone ) ) {
		return $phone;
	}
	// Abroad: a country code and the number, 8 to 15 digits in all.
	if ( preg_match( '/^(?:\+|00)([1-9]\d{7,14})$/', $phone, $m ) && 0 !== strpos( $m[1], '972' ) ) {
		return '+' . $m[1];
	}
	return '';
}

/**
 * Check a submission. Messages are the ones the form shows under each field.
 *
 * @param array $input Posted fields.
 * @return array{0: array<string, string>, 1: array<string, string>} Clean values, errors by field.
 */
function sella_contact_validate( $input ) {
	$clean = array(
		'name'      => sanitize_text_field( $input['name'] ?? '' ),
		'email'     => sanitize_email( $input['email'] ?? '' ),
		'phone'     => sella_contact_normalize_phone( $input['phone'] ?? '' ),
		'order_ref' => trim( sanitize_text_field( $input['order_ref'] ?? '' ) ),
		'message'   => trim( sanitize_textarea_field( $input['message'] ?? '' ) ),
	);
	$errors = array();

	if ( mb_strlen( $clean['name'] ) < 2 ) {
		$errors['name'] = 'נא למלא שם מלא';
	}

	$typed_email = trim( (string) ( $input['email'] ?? '' ) );
	if ( '' === $typed_email ) {
		$errors['email'] = 'נא למלא כתובת אימייל';
	} elseif ( ! is_email( $clean['email'] ) || mb_strlen( $clean['email'] ) > 190 ) {
		$errors['email'] = 'כתובת האימייל לא תקינה';
	}

	if ( '' === trim( (string) ( $input['phone'] ?? '' ) ) ) {
		$errors['phone'] = 'נא למלא מספר טלפון נייד';
	} elseif ( '' === $clean['phone'] ) {
		$errors['phone'] = 'מספר הטלפון לא תקין. למשל: 050-1234567';
	}

	$clean['order_ref'] = ltrim( $clean['order_ref'], '#' );
	if ( '' !== $clean['order_ref'] && ! preg_match( '/^[A-Za-z0-9\-]{1,30}$/', $clean['order_ref'] ) ) {
		$errors['order_ref'] = 'מספר הזמנה מכיל ספרות (ואולי אותיות) בלבד';
	}

	if ( '' === $clean['message'] ) {
		$errors['message'] = 'נא לכתוב את תוכן הפנייה';
	} elseif ( mb_strlen( $clean['message'] ) > 5000 ) {
		$errors['message'] = 'ההודעה ארוכה מדי';
	}

	return array( $clean, $errors );
}

/**
 * AJAX: a contact form submission.
 */
function sella_contact_ajax_submit() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- cached pages; see the file header.
	$input = wp_unslash( $_POST );

	// Bots fill in every field and post at once. Pretend it went through.
	if ( ! empty( $input['website'] ) || (int) ( $input['elapsed'] ?? 0 ) < 2500 ) {
		wp_send_json_success( array( 'message' => SELLA_CONTACT_THANKS ) );
	}

	list( $clean, $errors ) = sella_contact_validate( $input );
	if ( $errors ) {
		wp_send_json_error(
			array(
				'message' => 'יש לתקן את השדות המסומנים.',
				'errors'  => $errors,
			),
			400
		);
	}

	if ( ! sella_leads_rate_ok( 'contact', 5 ) ) {
		wp_send_json_error( array( 'message' => 'נשלחו כבר כמה פניות מכאן. נסו שוב בעוד כמה דקות.' ), 429 );
	}

	$page_url = sella_leads_page_url( $input['page_url'] ?? '' );
	$id       = sella_leads_add(
		array_merge(
			$clean,
			array(
				'type'     => 'contact',
				'source'   => 'טופס צור קשר',
				'page_url' => $page_url,
			)
		)
	);

	if ( ! $id ) {
		wp_send_json_error( array( 'message' => 'לא הצלחנו לשלוח את הפנייה. נסו שוב בעוד רגע.' ), 500 );
	}

	sella_contact_notify( $clean, $page_url );
	// phpcs:enable

	wp_send_json_success( array( 'message' => SELLA_CONTACT_THANKS ) );
}
add_action( 'wp_ajax_sella_contact_submit', 'sella_contact_ajax_submit' );
add_action( 'wp_ajax_nopriv_sella_contact_submit', 'sella_contact_ajax_submit' );

/**
 * Email an inquiry to the shop, with Reply-To set to the customer.
 *
 * @param array  $lead     Clean fields.
 * @param string $page_url Where it was sent from.
 */
function sella_contact_notify( $lead, $page_url ) {
	$settings = sella_leads_settings();
	if ( ! $settings['notify'] || ! is_email( $settings['notify_email'] ) ) {
		return;
	}

	$lines = array(
		'שם: ' . $lead['name'],
		'אימייל: ' . $lead['email'],
		'טלפון: ' . $lead['phone'],
	);
	if ( '' !== $lead['order_ref'] ) {
		$lines[] = 'מספר הזמנה: ' . $lead['order_ref'];
	}
	$lines[] = '';
	$lines[] = $lead['message'];
	$lines[] = '';
	if ( '' !== $page_url ) {
		$lines[] = 'נשלח מ: ' . $page_url;
	}
	$lines[] = 'כל הפניות: ' . admin_url( 'admin.php?page=' . SELLA_LEADS_SLUG );

	$headers = array( 'Reply-To: ' . str_replace( array( "\r", "\n" ), '', $lead['name'] ) . ' <' . $lead['email'] . '>' );

	wp_mail( $settings['notify_email'], 'פנייה חדשה מהאתר: ' . $lead['name'], implode( "\n", $lines ), $headers );
}

/**
 * Load the form's CSS and JS.
 */
function sella_contact_enqueue() {
	$dir  = get_stylesheet_directory();
	$base = get_stylesheet_directory_uri();

	wp_enqueue_style( 'sella-contact', $base . '/assets/css/sella-contact.css', array(), (string) filemtime( $dir . '/assets/css/sella-contact.css' ) );
	wp_enqueue_script( 'sella-contact', $base . '/assets/js/sella-contact.js', array(), (string) filemtime( $dir . '/assets/js/sella-contact.js' ), true );
	wp_localize_script( 'sella-contact', 'sellaContact', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
}

/**
 * In the head when the page holds the form, so it is styled from the start
 * (Elementor keeps a page's widgets in _elementor_data, not in the content).
 */
function sella_contact_maybe_enqueue() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	if ( has_shortcode( $post->post_content, 'sella_contact_form' ) || false !== strpos( (string) get_post_meta( $post->ID, '_elementor_data', true ), 'sella_contact_form' ) ) {
		sella_contact_enqueue();
	}
}
add_action( 'wp_enqueue_scripts', 'sella_contact_maybe_enqueue', 30 );

/**
 * One field of the form.
 *
 * @param string $id       Element ID.
 * @param string $name     Field name.
 * @param string $label    Label.
 * @param bool   $required Whether it must be filled in.
 * @param string $control  The input or textarea, without id, name or aria.
 * @param string $hint     Text under the field.
 * @return string
 */
function sella_contact_field( $id, $name, $label, $required, $control, $hint = '' ) {
	$aria = $id . '-error' . ( '' !== $hint ? ' ' . $id . '-hint' : '' );
	$attr = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" aria-describedby="' . esc_attr( $aria ) . '"' . ( $required ? ' required aria-required="true"' : '' );

	return '<div class="sella-contact__field sella-contact__field--' . esc_attr( $name ) . '" data-field="' . esc_attr( $name ) . '">'
		. '<div class="sella-contact__box">'
		. '<label class="sella-contact__label" for="' . esc_attr( $id ) . '">' . esc_html( $label )
		. ( $required ? ' <span class="sella-contact__req" aria-hidden="true">*</span>' : ' <span class="sella-contact__optional">(לא חובה)</span>' )
		. '</label>'
		. preg_replace( '/^<(input|textarea)/', '<$1' . $attr, $control )
		. '</div>'
		. ( '' !== $hint ? '<p class="sella-contact__hint" id="' . esc_attr( $id ) . '-hint">' . esc_html( $hint ) . '</p>' : '' )
		. '<p class="sella-contact__error" id="' . esc_attr( $id ) . '-error" hidden></p>'
		. '</div>';
}

/**
 * [sella_contact_form]
 *
 * @return string
 */
function sella_contact_shortcode() {
	static $count = 0;
	++$count;
	$p = 'sella-contact-' . $count;

	sella_contact_enqueue();

	$html  = '<div class="sella-contact" data-sella-contact>';
	$html .= '<form class="sella-contact__form" novalidate>';
	$html .= '<div class="sella-contact__grid">';
	$html .= sella_contact_field( $p . '-name', 'name', 'שם מלא', true, '<input class="sella-contact__input" type="text" autocomplete="name" maxlength="190" />' );
	$html .= sella_contact_field( $p . '-email', 'email', 'אימייל', true, '<input class="sella-contact__input" type="email" autocomplete="email" inputmode="email" dir="ltr" maxlength="190" />' );
	$html .= sella_contact_field( $p . '-phone', 'phone', 'טלפון נייד', true, '<input class="sella-contact__input" type="tel" autocomplete="tel" inputmode="tel" dir="ltr" maxlength="20" />' );
	$html .= sella_contact_field( $p . '-order', 'order_ref', 'מספר הזמנה', false, '<input class="sella-contact__input" type="text" inputmode="numeric" dir="ltr" maxlength="30" />', 'אם הפנייה על הזמנה, כתבו את מספרה. הוא מופיע במייל אישור ההזמנה.' );
	$html .= sella_contact_field( $p . '-message', 'message', 'תוכן ההודעה', true, '<textarea class="sella-contact__input sella-contact__textarea" rows="6" maxlength="5000"></textarea>' );
	$html .= '</div>';
	// Hidden from people; bots fill it in.
	$html .= '<div class="sella-contact__trap" aria-hidden="true"><label>אתר אינטרנט<input type="text" name="website" tabindex="-1" autocomplete="off" /></label></div>';
	$html .= '<p class="sella-contact__status" role="alert" hidden></p>';
	$html .= '<button type="submit" class="sella-contact__submit">שלח</button>';
	$html .= '</form>';
	$html .= '<div class="sella-contact__done" role="status" tabindex="-1" hidden>';
	$html .= '<span class="sella-contact__done-icon" aria-hidden="true"></span>';
	$html .= '<p class="sella-contact__done-text">' . esc_html( SELLA_CONTACT_THANKS ) . '</p>';
	$html .= '</div>';
	$html .= '</div>';

	return $html;
}
add_shortcode( 'sella_contact_form', 'sella_contact_shortcode' );

/**
 * ---------------------------------------------------------------------------
 * Newsletter signups
 * ---------------------------------------------------------------------------
 */

/**
 * The script that sends the newsletter popup's address here; on every page,
 * since the popup can open on any of them.
 */
function sella_leads_capture_assets() {
	$file = get_stylesheet_directory() . '/assets/js/sella-leads-capture.js';
	wp_enqueue_script( 'sella-leads-capture', get_stylesheet_directory_uri() . '/assets/js/sella-leads-capture.js', array(), (string) filemtime( $file ), true );
	wp_localize_script(
		'sella-leads-capture',
		'sellaLeadsCapture',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'sending' => 'שולח...',
			'done'    => 'נרשמת בהצלחה!',
			'error'   => 'לא הצלחנו לרשום. נסו שוב.',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'sella_leads_capture_assets', 30 );

/**
 * AJAX: a newsletter signup from an HTML form.
 */
function sella_leads_ajax_subscribe() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- cached pages; see the file header.
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
		wp_send_json_error( array( 'message' => 'כתובת האימייל לא תקינה' ), 400 );
	}
	if ( ! sella_leads_rate_ok( 'newsletter', 10 ) ) {
		wp_send_json_error( array( 'message' => 'נסו שוב בעוד כמה דקות.' ), 429 );
	}

	$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
	$id     = sella_leads_add(
		array(
			'type'     => 'newsletter',
			'email'    => $email,
			'source'   => '' !== $source ? mb_substr( $source, 0, 60 ) : 'טופס ניוזלטר',
			'page_url' => sella_leads_page_url( isset( $_POST['page_url'] ) ? wp_unslash( $_POST['page_url'] ) : '' ),
		)
	);
	// phpcs:enable

	if ( ! $id ) {
		wp_send_json_error( array( 'message' => 'לא הצלחנו לרשום. נסו שוב.' ), 500 );
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_sella_leads_subscribe', 'sella_leads_ajax_subscribe' );
add_action( 'wp_ajax_nopriv_sella_leads_subscribe', 'sella_leads_ajax_subscribe' );

/**
 * Every Elementor Pro form submission, such as the footer newsletter. A form
 * with an email field and nothing longer than a name counts as a signup.
 *
 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Submission.
 */
function sella_leads_from_elementor_form( $record ) {
	if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
		return;
	}

	// A form another module records its own way, such as one connected to a first chapter.
	if ( apply_filters( 'sella_leads_skip_elementor_form', false, $record ) ) {
		return;
	}

	$lead   = array( 'fields' => array() );
	$simple = true;

	foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
		$type  = $field['type'] ?? '';
		$value = is_array( $field['value'] ?? '' ) ? implode( ', ', $field['value'] ) : (string) ( $field['value'] ?? '' );
		$title = '' !== ( $field['title'] ?? '' ) ? $field['title'] : $id;

		if ( in_array( $type, array( 'hidden', 'honeypot', 'recaptcha', 'recaptcha_v3', 'html', 'step' ), true ) || '' === $value ) {
			continue;
		}

		$lead['fields'][ $title ] = $value;

		if ( 'email' === $type && empty( $lead['email'] ) ) {
			$lead['email'] = $value;
		} elseif ( 'tel' === $type && empty( $lead['phone'] ) ) {
			$lead['phone'] = $value;
		} elseif ( 'textarea' === $type && empty( $lead['message'] ) ) {
			$lead['message'] = $value;
			$simple          = false;
		} elseif ( 'text' === $type && empty( $lead['name'] ) && ( 'name' === $id || false !== mb_strpos( $title, 'שם' ) ) ) {
			$lead['name'] = $value;
		} elseif ( 'acceptance' !== $type ) {
			$simple = false;
		}
	}

	$meta = (array) $record->get( 'meta' );

	$lead['type']     = $simple && ! empty( $lead['email'] ) ? 'newsletter' : 'form';
	$lead['source']   = (string) $record->get_form_settings( 'form_name' );
	$lead['page_url'] = isset( $meta['page_url']['value'] ) ? sella_leads_page_url( $meta['page_url']['value'] ) : '';

	sella_leads_add( $lead );
}
add_action( 'elementor_pro/forms/new_record', 'sella_leads_from_elementor_form' );

/**
 * ---------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------------
 */

/**
 * New leads, for the count on the menu item.
 *
 * @return int
 */
function sella_leads_new_count() {
	global $wpdb;
	$table = sella_leads_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new' AND type = 'contact'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * The screen, with the number of unanswered inquiries on the menu item.
 */
function sella_leads_register_admin_page() {
	$new   = get_option( 'sella_leads_db_version' ) ? sella_leads_new_count() : 0;
	$title = 'פניות ולידים' . ( $new ? ' <span class="awaiting-mod">' . (int) $new . '</span>' : '' );

	add_menu_page( 'פניות ולידים', $title, 'manage_woocommerce', SELLA_LEADS_SLUG, 'sella_leads_render_admin_page', 'dashicons-email-alt', 58.3 );
	// Its own first item, so the first-chapter screens under it come after the list.
	add_submenu_page( SELLA_LEADS_SLUG, 'פניות ולידים', 'פניות ולידים', 'manage_woocommerce', SELLA_LEADS_SLUG );
}
add_action( 'admin_menu', 'sella_leads_register_admin_page' );

/**
 * @param string $hook Admin hook.
 */
function sella_leads_admin_assets( $hook ) {
	if ( false === strpos( $hook, SELLA_LEADS_SLUG ) ) {
		return;
	}
	$file = get_stylesheet_directory() . '/assets/css/sella-leads-admin.css';
	wp_enqueue_style( 'sella-leads-admin', get_stylesheet_directory_uri() . '/assets/css/sella-leads-admin.css', array(), (string) filemtime( $file ) );
}
add_action( 'admin_enqueue_scripts', 'sella_leads_admin_assets' );

/**
 * The list's filters, read from the query string.
 *
 * @return array{type: string, status: string, q: string}
 */
function sella_leads_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$types  = sella_leads_types();
	$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	// phpcs:enable

	return array(
		'type'   => isset( $types[ $type ] ) ? $type : '',
		'status' => in_array( $status, array( 'new', 'handled' ), true ) ? $status : '',
		'q'      => $q,
	);
}

/**
 * The WHERE clause for a set of filters.
 *
 * @param array $filters Filters.
 * @return string
 */
function sella_leads_where( $filters ) {
	global $wpdb;
	$where = array( '1=1' );

	if ( '' !== $filters['type'] ) {
		$where[] = $wpdb->prepare( 'type = %s', $filters['type'] );
	}
	if ( '' !== $filters['status'] ) {
		$where[] = $wpdb->prepare( 'status = %s', $filters['status'] );
	}
	if ( '' !== $filters['q'] ) {
		$like    = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
		$where[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR order_ref LIKE %s OR message LIKE %s OR source LIKE %s)', $like, $like, $like, $like, $like, $like );
	}

	return implode( ' AND ', $where );
}

/**
 * Status changes, deletions and settings from the screen.
 */
function sella_leads_handle_admin_actions() {
	if ( ! is_admin() || empty( $_POST['sella_leads_form'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	check_admin_referer( 'sella_leads_manage' );
	global $wpdb;
	$table  = sella_leads_table();
	$back   = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . SELLA_LEADS_SLUG );
	$notice = '';

	if ( 'settings' === $_POST['sella_leads_form'] ) {
		$email = isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '';
		update_option(
			SELLA_LEADS_SETTINGS,
			array(
				'notify'       => ! empty( $_POST['notify'] ),
				'notify_email' => is_email( $email ) ? $email : '',
			),
			false
		);
		$notice = 'settings';
	} else {
		// One row's button ("toggle:12"), or the bulk action on the ticked rows.
		$row_action = isset( $_POST['row_action'] ) ? sanitize_text_field( wp_unslash( $_POST['row_action'] ) ) : '';
		if ( preg_match( '/^(toggle|delete):(\d+)$/', $row_action, $m ) ) {
			$action = $m[1];
			$ids    = array( (int) $m[2] );
		} else {
			$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
			$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		}

		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			if ( 'delete' === $action ) {
				$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$notice = 'deleted';
			} elseif ( 'toggle' === $action ) {
				$wpdb->query( "UPDATE {$table} SET status = IF(status = 'new', 'handled', 'new') WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$notice = 'updated';
			} elseif ( in_array( $action, array( 'handled', 'new' ), true ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$in})", $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$notice = 'updated';
			}
		}
	}

	wp_safe_redirect( add_query_arg( 'notice', $notice, remove_query_arg( 'notice', $back ) ) );
	exit;
}
add_action( 'admin_init', 'sella_leads_handle_admin_actions' );

/**
 * Download the filtered list as a CSV file Excel opens in Hebrew.
 */
function sella_leads_export() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_leads_export' );

	global $wpdb;
	$table = sella_leads_table();
	$where = sella_leads_where( sella_leads_filters() );
	$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$types = sella_leads_types();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="leads-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv( $out, array( 'תאריך', 'סוג', 'סטטוס', 'שם', 'אימייל', 'טלפון', 'מספר הזמנה', 'הודעה', 'מקור', 'עמוד', 'שדות נוספים' ) );
	foreach ( $rows as $row ) {
		$fields = $row['fields'] ? json_decode( $row['fields'], true ) : array();
		$extra  = array();
		foreach ( (array) $fields as $label => $value ) {
			$extra[] = $label . ': ' . $value;
		}
		$cells = array(
			$row['created_at'],
			$types[ $row['type'] ] ?? $row['type'],
			'new' === $row['status'] ? 'חדש' : 'טופל',
			$row['name'],
			$row['email'],
			$row['phone'],
			$row['order_ref'],
			$row['message'],
			$row['source'],
			$row['page_url'],
			implode( ' | ', $extra ),
		);
		// A cell starting with = + - @ would run as a formula in Excel.
		$cells = array_map(
			function ( $cell ) {
				return preg_match( '/^[=+\-@]/', (string) $cell ) ? "'" . $cell : $cell;
			},
			$cells
		);
		fputcsv( $out, $cells );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
add_action( 'admin_post_sella_leads_export', 'sella_leads_export' );

/**
 * A row's order number, linked to the order when there is one.
 *
 * @param string $ref Order number as typed.
 * @return string
 */
function sella_leads_order_cell( $ref ) {
	if ( '' === $ref ) {
		return '';
	}
	$order = ctype_digit( $ref ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $ref ) : null;
	if ( $order ) {
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $ref ) . '</a>';
	}
	return esc_html( $ref );
}

/**
 * The screen.
 */
function sella_leads_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	global $wpdb;
	$table    = sella_leads_table();
	$types    = sella_leads_types();
	$filters  = sella_leads_filters();
	$settings = sella_leads_settings();
	$base_url = admin_url( 'admin.php?page=' . SELLA_LEADS_SLUG );
	$where    = sella_leads_where( $filters );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$offset = ( $paged - 1 ) * SELLA_LEADS_PER_PAGE;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name and a WHERE built with prepare().
	$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", SELLA_LEADS_PER_PAGE, $offset ) );
	$counts = $wpdb->get_results( "SELECT type, COUNT(*) AS total, SUM(status = 'new') AS fresh FROM {$table} GROUP BY type", OBJECT_K );
	// phpcs:enable

	$all_total = 0;
	foreach ( $counts as $count ) {
		$all_total += (int) $count->total;
	}

	$notice  = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notices = array(
		'updated'  => 'הסטטוס עודכן.',
		'deleted'  => 'נמחק.',
		'settings' => 'ההגדרות נשמרו.',
	);
	$filtered_url = add_query_arg( array_filter( $filters ), $base_url );
	$export_url   = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'sella_leads_export' ), array_filter( $filters ) ), admin_url( 'admin-post.php' ) ), 'sella_leads_export' );
	$pages        = (int) ceil( $total / SELLA_LEADS_PER_PAGE );
	?>
	<div class="wrap sella-leads-admin" dir="rtl">
		<h1 class="wp-heading-inline">פניות ולידים</h1>
		<a class="page-title-action" href="<?php echo esc_url( $export_url ); ?>">ייצוא לאקסל (CSV)</a>
		<hr class="wp-header-end" />

		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
		<?php endif; ?>

		<p class="sella-leads-lead">
			כל מה שגולשים משאירים באתר: פניות מטופס צור קשר (<code>[sella_contact_form]</code>), הרשמות לניוזלטר מהפופאפ ומטופס הפוטר,
			כתובות מייל שהושארו לפני התשלום, בקשות לעדכון על ספר שחזר למלאי ובקשות לפרק ראשון. הייצוא כולל את הסינון והחיפוש שבחרתם.
		</p>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( add_query_arg( array_filter( array( 'q' => $filters['q'] ) ), $base_url ) ); ?>" class="<?php echo '' === $filters['type'] ? 'current' : ''; ?>">הכול <span class="count">(<?php echo (int) $all_total; ?>)</span></a></li>
			<?php foreach ( $types as $key => $label ) : ?>
				<?php
				$count = isset( $counts[ $key ] ) ? $counts[ $key ] : null;
				$fresh = $count ? (int) $count->fresh : 0;
				?>
				<li>| <a href="<?php echo esc_url( add_query_arg( array_filter( array( 'type' => $key, 'q' => $filters['q'] ) ), $base_url ) ); ?>" class="<?php echo $key === $filters['type'] ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) ( $count ? $count->total : 0 ); ?>)</span><?php if ( $fresh && 'contact' === $key ) : ?> <span class="sella-leads-fresh"><?php echo (int) $fresh; ?> חדשות</span><?php endif; ?></a></li>
			<?php endforeach; ?>
		</ul>

		<form method="get" class="sella-leads-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( SELLA_LEADS_SLUG ); ?>" />
			<?php if ( $filters['type'] ) : ?><input type="hidden" name="type" value="<?php echo esc_attr( $filters['type'] ); ?>" /><?php endif; ?>
			<select name="status">
				<option value="">כל הסטטוסים</option>
				<option value="new" <?php selected( $filters['status'], 'new' ); ?>>חדש</option>
				<option value="handled" <?php selected( $filters['status'], 'handled' ); ?>>טופל</option>
			</select>
			<input type="search" name="q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="שם, מייל, טלפון, הזמנה או טקסט" />
			<button type="submit" class="button">חיפוש</button>
		</form>

		<form method="post" class="sella-leads-list">
			<?php wp_nonce_field( 'sella_leads_manage' ); ?>
			<input type="hidden" name="sella_leads_form" value="list" />

			<div class="tablenav top">
				<select name="bulk_action">
					<option value="">פעולה על המסומנים</option>
					<option value="handled">סימון כטופל</option>
					<option value="new">סימון כחדש</option>
					<option value="delete">מחיקה</option>
				</select>
				<button type="submit" class="button" onclick="return this.form.bulk_action.value !== 'delete' || confirm('למחוק את המסומנים? אי אפשר לבטל.');">ביצוע</button>
				<span class="displaying-num"><?php echo (int) $total; ?> רשומות</span>
			</div>

			<table class="widefat striped sella-leads-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="for (const box of this.closest('table').querySelectorAll('tbody input[type=checkbox]')) box.checked = this.checked;" aria-label="סימון הכול" /></td>
						<th>תאריך</th>
						<th>סוג</th>
						<th>שם</th>
						<th>אימייל</th>
						<th>טלפון</th>
						<th>הזמנה</th>
						<th class="sella-leads-table__message">הודעה</th>
						<th>מקור</th>
						<th>סטטוס</th>
						<th><span class="screen-reader-text">פעולות</span></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="11" class="sella-leads-empty"><?php echo $filters['q'] || $filters['type'] || $filters['status'] ? 'אין רשומות שמתאימות לסינון.' : 'עדיין לא התקבלו פניות או הרשמות.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$fields = $row->fields ? json_decode( $row->fields, true ) : array();
					$text   = (string) $row->message;
					$extra  = array();
					foreach ( (array) $fields as $label => $value ) {
						if ( $value !== $row->email && $value !== $row->name && $value !== $row->phone && $value !== $text ) {
							$extra[ $label ] = $value;
						}
					}
					?>
					<tr class="<?php echo 'new' === $row->status ? 'is-new' : 'is-handled'; ?>">
						<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $row->id; ?>" aria-label="סימון" /></th>
						<td class="sella-leads-table__date"><?php echo esc_html( mysql2date( 'd.m.Y', $row->created_at ) ); ?><span><?php echo esc_html( mysql2date( 'H:i', $row->created_at ) ); ?></span></td>
						<td><span class="sella-leads-type sella-leads-type--<?php echo esc_attr( $row->type ); ?>"><?php echo esc_html( $types[ $row->type ] ?? $row->type ); ?></span></td>
						<td><?php echo esc_html( $row->name ); ?></td>
						<td><?php if ( $row->email ) : ?><a href="mailto:<?php echo esc_attr( $row->email ); ?>" dir="ltr"><?php echo esc_html( $row->email ); ?></a><?php endif; ?></td>
						<td><?php if ( $row->phone ) : ?><a href="tel:<?php echo esc_attr( $row->phone ); ?>" dir="ltr"><?php echo esc_html( $row->phone ); ?></a><?php endif; ?></td>
						<td dir="ltr"><?php echo sella_leads_order_cell( $row->order_ref ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></td>
						<td class="sella-leads-table__message">
							<?php if ( '' !== $text || $extra ) : ?>
								<?php if ( mb_strlen( $text ) > 90 || $extra ) : ?>
									<details>
										<summary><?php echo esc_html( '' !== $text ? mb_substr( $text, 0, 90 ) . ( mb_strlen( $text ) > 90 ? '…' : '' ) : 'שדות נוספים' ); ?></summary>
										<?php if ( mb_strlen( $text ) > 90 ) : ?><p class="sella-leads-text"><?php echo esc_html( $text ); ?></p><?php endif; ?>
										<?php if ( $extra ) : ?>
											<dl class="sella-leads-extra">
												<?php foreach ( $extra as $label => $value ) : ?>
													<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd>
												<?php endforeach; ?>
											</dl>
										<?php endif; ?>
									</details>
								<?php else : ?>
									<p class="sella-leads-text"><?php echo esc_html( $text ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="sella-leads-table__source">
							<?php echo esc_html( $row->source ); ?>
							<?php if ( $row->page_url ) : ?><a href="<?php echo esc_url( $row->page_url ); ?>" target="_blank" rel="noopener">עמוד</a><?php endif; ?>
						</td>
						<td>
							<button type="submit" name="row_action" value="toggle:<?php echo (int) $row->id; ?>" class="sella-leads-status <?php echo 'new' === $row->status ? 'is-new' : 'is-handled'; ?>" title="<?php echo 'new' === $row->status ? 'לחיצה תסמן כטופל' : 'לחיצה תחזיר לחדש'; ?>">
								<?php echo 'new' === $row->status ? 'חדש' : 'טופל'; ?>
							</button>
						</td>
						<td>
							<button type="submit" name="row_action" value="delete:<?php echo (int) $row->id; ?>" class="button-link button-link-delete" onclick="return confirm('למחוק את הרשומה? אי אפשר לבטל.');">מחיקה</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%', $filtered_url ),
									'format'    => '',
									'current'   => $paged,
									'total'     => $pages,
									'prev_text' => '&rarr;',
									'next_text' => '&larr;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</form>

		<form method="post" class="sella-leads-settings">
			<h2>התראה במייל על פנייה חדשה</h2>
			<?php wp_nonce_field( 'sella_leads_manage' ); ?>
			<input type="hidden" name="sella_leads_form" value="settings" />
			<p>
				<label><input type="checkbox" name="notify" value="1" <?php checked( $settings['notify'] ); ?> /> לשלוח מייל על כל פנייה מטופס צור קשר</label>
			</p>
			<p>
				<label for="sella_leads_notify_email">לכתובת</label>
				<input type="email" id="sella_leads_notify_email" name="notify_email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" class="regular-text" dir="ltr" />
			</p>
			<p class="description">אפשר לענות ללקוח ישירות מהמייל: כתובת התשובה היא שלו.</p>
			<?php submit_button( 'שמירה', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
