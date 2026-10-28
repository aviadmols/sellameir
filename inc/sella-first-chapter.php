<?php
/**
 * First chapters: a book's first chapter, emailed to whoever asks for it.
 *
 * - פרקים ראשונים (post type sella_chapter): each chapter is a book's name, its
 *   file (uploaded to the media library or linked from elsewhere) and a cover
 *   for the email.
 * - [sella_first_chapter]: a name and email form for the product page. It shows
 *   only on the products given a chapter on the settings screen, and on those
 *   it is added under the description by itself (once, even when the template
 *   has the shortcode too).
 * - Elementor Pro forms connected on the settings screen: which field holds the
 *   name, the email and the phone, and which chapter to send: by the answer to
 *   one of the form's fields, the chapter of the product page the form is on,
 *   or a fixed one.
 *
 * Each request is a lead of the kind "פרק ראשון" (sella-leads.php) and a row in
 * the sent log, which records the email, the webhook call and the clicks on the
 * chapter's link. That link goes through the site (/?sella_chapter=<token>,
 * which the page cache skips) to count the clicks, then on to the file.
 *
 * The webhook gets the reader's details and the link as JSON. It is called
 * after the visitor has the answer, so a slow endpoint does not hold them up.
 *
 * Admin, under פניות ולידים: פרקים ראשונים, פרקים שנשלחו, הגדרות פרק ראשון.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_FC_CPT', 'sella_chapter' );
define( 'SELLA_FC_DB_VERSION', '1' );
define( 'SELLA_FC_SETTINGS', 'sella_first_chapter_settings' );
define( 'SELLA_FC_SENT_SLUG', 'sella-chapters-sent' );
define( 'SELLA_FC_SETTINGS_SLUG', 'sella-chapters-settings' );
define( 'SELLA_FC_PER_PAGE', 50 );

/**
 * ---------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------------
 */

/**
 * @return string
 */
function sella_fc_table() {
	global $wpdb;
	return $wpdb->prefix . 'sella_chapter_log';
}

/**
 * Create or upgrade the sent log when the schema version changes.
 */
function sella_fc_maybe_install() {
	if ( SELLA_FC_DB_VERSION === get_option( 'sella_fc_db_version' ) ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table = sella_fc_table();

	// dbDelta is picky: one column per line and two spaces after PRIMARY KEY.
	dbDelta(
		"CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		chapter_id bigint(20) unsigned NOT NULL DEFAULT 0,
		product_id bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(190) NOT NULL DEFAULT '',
		email varchar(190) NOT NULL DEFAULT '',
		phone varchar(40) NOT NULL DEFAULT '',
		source varchar(20) NOT NULL DEFAULT '',
		form_name varchar(190) NOT NULL DEFAULT '',
		page_url varchar(255) NOT NULL DEFAULT '',
		fields longtext NULL,
		token char(32) NOT NULL DEFAULT '',
		mail_status varchar(20) NOT NULL DEFAULT '',
		webhook_status varchar(20) NOT NULL DEFAULT '',
		webhook_code smallint(5) unsigned NOT NULL DEFAULT 0,
		webhook_error varchar(255) NOT NULL DEFAULT '',
		clicks int(10) unsigned NOT NULL DEFAULT 0,
		clicked_at datetime NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		KEY email_chapter (email,chapter_id),
		KEY chapter_id (chapter_id),
		KEY created_at (created_at)
		) {$wpdb->get_charset_collate()};"
	);

	// Only store the version once the table really exists, so a failed run retries.
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		update_option( 'sella_fc_db_version', SELLA_FC_DB_VERSION );
	}
}
add_action( 'init', 'sella_fc_maybe_install', 5 );

/**
 * One row of the sent log.
 *
 * @param int $id Row ID.
 * @return object|null
 */
function sella_fc_log_row( $id ) {
	global $wpdb;
	$table = sella_fc_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * @param int   $id     Row ID.
 * @param array $values Columns to change.
 */
function sella_fc_log_update( $id, $values ) {
	global $wpdb;
	$wpdb->update( sella_fc_table(), $values, array( 'id' => (int) $id ) );
}

/**
 * ---------------------------------------------------------------------------
 * Chapters
 * ---------------------------------------------------------------------------
 */

/**
 * The post type. Its menu item sits under פניות ולידים (see the admin part).
 */
function sella_fc_register_cpt() {
	register_post_type(
		SELLA_FC_CPT,
		array(
			'labels'               => array(
				'name'                  => 'פרקים ראשונים',
				'singular_name'         => 'פרק ראשון',
				'add_new'               => 'הוספת פרק',
				'add_new_item'          => 'הוספת פרק ראשון',
				'edit_item'             => 'עריכת פרק ראשון',
				'new_item'              => 'פרק ראשון חדש',
				'search_items'          => 'חיפוש פרקים',
				'not_found'             => 'עדיין לא הועלו פרקים.',
				'not_found_in_trash'    => 'אין פרקים בפח.',
				'all_items'             => 'פרקים ראשונים',
				'featured_image'        => 'עטיפת הספר',
				'set_featured_image'    => 'בחירת עטיפה',
				'remove_featured_image' => 'הסרת העטיפה',
				'use_featured_image'    => 'שימוש כעטיפה',
			),
			'public'               => false,
			'show_ui'              => true,
			'show_in_menu'         => false,
			'supports'             => array( 'title', 'thumbnail' ),
			'rewrite'              => false,
			'query_var'            => false,
			'show_in_rest'         => false,
			'register_meta_box_cb' => 'sella_fc_meta_boxes',
		)
	);
}
add_action( 'init', 'sella_fc_register_cpt' );

/**
 * Where the chapter is read: the uploaded file, or the link typed instead.
 *
 * @param int $chapter_id Chapter.
 * @return string
 */
function sella_fc_file_url( $chapter_id ) {
	$file = (int) get_post_meta( $chapter_id, '_sella_chapter_file', true );
	$url  = $file ? wp_get_attachment_url( $file ) : '';
	return $url ? $url : (string) get_post_meta( $chapter_id, '_sella_chapter_url', true );
}

/**
 * A published chapter with a file: one that can be sent.
 *
 * @param int $chapter_id Chapter.
 * @return bool
 */
function sella_fc_chapter_ok( $chapter_id ) {
	$post = $chapter_id ? get_post( (int) $chapter_id ) : null;
	return $post && SELLA_FC_CPT === $post->post_type && 'publish' === $post->post_status && '' !== sella_fc_file_url( $post->ID );
}

/**
 * The book's name, as the chapter is titled.
 *
 * @param int $chapter_id Chapter.
 * @return string
 */
function sella_fc_book( $chapter_id ) {
	$post = get_post( (int) $chapter_id );
	return $post ? $post->post_title : '';
}

/**
 * Every chapter for the admin's dropdowns, with a note on those that cannot
 * be sent yet.
 *
 * @return array<int, string>
 */
function sella_fc_chapter_choices() {
	$posts = get_posts(
		array(
			'post_type'   => SELLA_FC_CPT,
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	$choices = array();
	foreach ( $posts as $post ) {
		$note = '';
		if ( 'publish' !== $post->post_status ) {
			$note = ' (לא פורסם)';
		} elseif ( '' === sella_fc_file_url( $post->ID ) ) {
			$note = ' (אין קובץ)';
		}
		$choices[ $post->ID ] = ( '' !== $post->post_title ? $post->post_title : '(בלי שם)' ) . $note;
	}
	return $choices;
}

/**
 * ---------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------------
 */

/**
 * @return array
 */
function sella_fc_defaults() {
	return array(
		// Rows of {product, chapter}.
		'products'   => array(),
		// Elementor forms: {form, label, name, email, phone, choice, choices {value: chapter}, fallback}.
		'forms'      => array(),
		'mail'       => true,
		'subject'    => 'הפרק הראשון של {book}',
		'heading'    => 'הפרק הראשון מחכה לך',
		'body'       => "היי {name},\n\nתודה שביקשת את הפרק הראשון של {book}. הוא מחכה לך בכפתור שכאן.\n\nקריאה מהנה!",
		'button'     => 'לקריאת הפרק הראשון',
		'webhook'    => '',
		'webhook_on' => false,
	);
}

/**
 * @return array
 */
function sella_fc_settings() {
	$saved = get_option( SELLA_FC_SETTINGS, array() );
	return array_merge( sella_fc_defaults(), is_array( $saved ) ? $saved : array() );
}

/**
 * Clean the settings screen's fields.
 *
 * @param array $source Posted fields, unslashed.
 * @return array
 */
function sella_fc_sanitize( $source ) {
	$defaults = sella_fc_defaults();
	$clean    = array(
		'products'   => array(),
		'forms'      => array(),
		'mail'       => ! empty( $source['mail'] ),
		'subject'    => sanitize_text_field( $source['subject'] ?? '' ),
		'heading'    => sanitize_text_field( $source['heading'] ?? '' ),
		'body'       => sanitize_textarea_field( $source['body'] ?? '' ),
		'button'     => sanitize_text_field( $source['button'] ?? '' ),
		'webhook'    => esc_url_raw( trim( (string) ( $source['webhook'] ?? '' ) ), array( 'http', 'https' ) ),
		'webhook_on' => ! empty( $source['webhook_on'] ),
	);

	foreach ( array( 'subject', 'heading', 'body', 'button' ) as $key ) {
		if ( '' === trim( $clean[ $key ] ) ) {
			$clean[ $key ] = $defaults[ $key ];
		}
	}

	// One chapter per product; the first row wins.
	$seen = array();
	foreach ( (array) ( $source['products'] ?? array() ) as $row ) {
		$product = absint( $row['product'] ?? 0 );
		$chapter = absint( $row['chapter'] ?? 0 );
		if ( $product && $chapter && ! isset( $seen[ $product ] ) ) {
			$seen[ $product ]    = true;
			$clean['products'][] = array(
				'product' => $product,
				'chapter' => $chapter,
			);
		}
	}

	foreach ( (array) ( $source['forms'] ?? array() ) as $row ) {
		$form = sanitize_text_field( $row['form'] ?? '' );
		if ( '' === $form ) {
			continue;
		}

		// The answers to the choosing field come as two lists, side by side,
		// since an answer may hold characters a field name cannot.
		$choices  = array();
		$values   = (array) ( $row['choice_values'] ?? array() );
		$chapters = (array) ( $row['choice_chapters'] ?? array() );
		foreach ( $values as $i => $value ) {
			$chapter = absint( $chapters[ $i ] ?? 0 );
			$value   = sanitize_text_field( $value );
			if ( $chapter && '' !== $value ) {
				$choices[ $value ] = $chapter;
			}
		}

		$fallback = sanitize_key( $row['fallback'] ?? '' );

		$clean['forms'][] = array(
			'form'     => $form,
			'label'    => sanitize_text_field( $row['label'] ?? '' ),
			'name'     => sanitize_text_field( $row['name'] ?? '' ),
			'email'    => sanitize_text_field( $row['email'] ?? '' ),
			'phone'    => sanitize_text_field( $row['phone'] ?? '' ),
			'choice'   => sanitize_text_field( $row['choice'] ?? '' ),
			'choices'  => $choices,
			'fallback' => 'product' === $fallback ? 'product' : ( absint( $fallback ) ? (string) absint( $fallback ) : '' ),
		);
	}

	return $clean;
}

/**
 * The chapter given to a product on the settings screen, when it can be sent.
 *
 * @param int $product_id Product.
 * @return int Chapter ID, or 0.
 */
function sella_fc_product_chapter( $product_id ) {
	$product_id = (int) $product_id;
	if ( ! $product_id ) {
		return 0;
	}
	foreach ( sella_fc_settings()['products'] as $row ) {
		if ( (int) $row['product'] === $product_id ) {
			return sella_fc_chapter_ok( $row['chapter'] ) ? (int) $row['chapter'] : 0;
		}
	}
	return 0;
}

/**
 * ---------------------------------------------------------------------------
 * Sending
 * ---------------------------------------------------------------------------
 */

/**
 * The link in the email, through the site so clicks are counted.
 *
 * @param string $token Row token.
 * @return string
 */
function sella_fc_link( $token ) {
	return add_query_arg( 'sella_chapter', rawurlencode( $token ), home_url( '/' ) );
}

/**
 * Send a chapter and record it: in the leads list, in the sent log, by email
 * and to the webhook.
 *
 * @param array $request chapter_id, email, and any of product_id, name, phone,
 *                       source (shortcode|elementor), form_name, page_url,
 *                       fields (label => value).
 * @return int|false The log row, or false when nothing was sent.
 */
function sella_fc_deliver( $request ) {
	global $wpdb;

	$chapter_id = (int) ( $request['chapter_id'] ?? 0 );
	$email      = strtolower( sanitize_email( $request['email'] ?? '' ) );
	if ( ! is_email( $email ) || ! sella_fc_chapter_ok( $chapter_id ) ) {
		return false;
	}

	$table = sella_fc_table();

	// The same chapter to the same address a few minutes ago: once is enough.
	$recent = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND chapter_id = %d AND created_at > %s ORDER BY id DESC LIMIT 1", $email, $chapter_id, wp_date( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $recent ) {
		return (int) $recent;
	}

	// Anyone can type any address; a few chapters a day is all one inbox gets.
	$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND created_at > %s", $email, wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $today >= 5 ) {
		return false;
	}

	$name      = mb_substr( sanitize_text_field( $request['name'] ?? '' ), 0, 190 );
	$phone     = mb_substr( sanitize_text_field( $request['phone'] ?? '' ), 0, 40 );
	$form_name = mb_substr( sanitize_text_field( $request['form_name'] ?? '' ), 0, 190 );
	$page_url  = mb_substr( esc_url_raw( $request['page_url'] ?? '' ), 0, 255 );
	$fields    = ! empty( $request['fields'] ) && is_array( $request['fields'] ) ? $request['fields'] : array();
	$book      = sella_fc_book( $chapter_id );

	if ( function_exists( 'sella_leads_add' ) ) {
		sella_leads_add(
			array(
				'type'     => 'first_chapter',
				'name'     => $name,
				'email'    => $email,
				'phone'    => $phone,
				'source'   => $book,
				'page_url' => $page_url,
				'fields'   => array_merge( '' !== $form_name ? array( 'טופס' => $form_name ) : array(), $fields ),
			)
		);
	}

	$saved = $wpdb->insert(
		$table,
		array(
			'chapter_id' => $chapter_id,
			'product_id' => (int) ( $request['product_id'] ?? 0 ),
			'name'       => $name,
			'email'      => $email,
			'phone'      => $phone,
			'source'     => sanitize_key( $request['source'] ?? '' ),
			'form_name'  => $form_name,
			'page_url'   => $page_url,
			'fields'     => $fields ? wp_json_encode( $fields, JSON_UNESCAPED_UNICODE ) : null,
			'token'      => wp_generate_password( 32, false ),
			'created_at' => current_time( 'mysql' ),
		)
	);
	if ( ! $saved ) {
		return false;
	}

	$log_id = (int) $wpdb->insert_id;
	sella_fc_send_log_mail( $log_id );
	sella_fc_queue_webhook( $log_id );

	return $log_id;
}

/**
 * The chapter's email, through the store's email template.
 *
 * @param string $to         Address.
 * @param string $name       Reader's name; may be empty.
 * @param int    $chapter_id Chapter.
 * @param string $link       The link to the chapter.
 * @return bool
 */
function sella_fc_mail( $to, $name, $chapter_id, $link ) {
	if ( ! function_exists( 'WC' ) || ! is_email( $to ) || '' === $link ) {
		return false;
	}

	$settings = sella_fc_settings();
	$book     = sella_fc_book( $chapter_id );
	$fill     = function ( $text ) use ( $name, $book ) {
		$text = str_replace( array( '{name}', '{book}' ), array( $name, $book ), (string) $text );
		// Without a name, "היי ," becomes "היי,".
		return trim( preg_replace( '/[ \t]+([,.!?])/u', '$1', $text ) );
	};

	// The text is plain; {link} in it becomes the link itself.
	$parts = array_map( 'esc_html', explode( '{link}', $fill( $settings['body'] ) ) );
	$body  = wpautop( implode( '<a href="' . esc_url( $link ) . '" style="color:#746fed;">' . esc_html( $link ) . '</a>', $parts ) );
	$cover = get_the_post_thumbnail_url( $chapter_id, 'medium' );

	ob_start();
	?>
	<div dir="rtl" style="direction:rtl;text-align:right;">
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:8px 0 24px;">
			<?php if ( $cover ) : ?>
				<tr>
					<td align="center" style="padding:0 0 20px;text-align:center;">
						<a href="<?php echo esc_url( $link ); ?>"><img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( $book ); ?>" width="180" style="display:block;width:180px;max-width:100%;height:auto;margin:0 auto;border:0;border-radius:7px;" /></a>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<td align="center" style="text-align:center;">
					<a href="<?php echo esc_url( $link ); ?>" style="display:inline-block;padding:13px 36px;border-radius:50px;background-color:#6560e0;color:#ffffff;font-size:17px;font-weight:700;text-decoration:none;"><?php echo esc_html( $fill( $settings['button'] ) ); ?></a>
				</td>
			</tr>
		</table>
		<p style="margin:24px 0 0;color:#8a8a8a;font-size:12px;line-height:1.5;">
			<?php echo esc_html( sprintf( 'קיבלת את המייל כי ביקשת באתר %s את הפרק הראשון של %s.', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $book ) ); ?>
		</p>
	</div>
	<?php
	$mailer  = WC()->mailer();
	$message = $mailer->wrap_message( $fill( $settings['heading'] ), (string) ob_get_clean() );

	return (bool) $mailer->send( $to, $fill( $settings['subject'] ), $message );
}

/**
 * Email the chapter of a log row and record how it went.
 *
 * @param int $log_id Row.
 * @return bool
 */
function sella_fc_send_log_mail( $log_id ) {
	$row = sella_fc_log_row( $log_id );
	if ( ! $row ) {
		return false;
	}
	if ( ! sella_fc_settings()['mail'] ) {
		sella_fc_log_update( $row->id, array( 'mail_status' => 'off' ) );
		return false;
	}

	$sent = sella_fc_mail( $row->email, $row->name, (int) $row->chapter_id, sella_fc_link( $row->token ) );
	sella_fc_log_update( $row->id, array( 'mail_status' => $sent ? 'sent' : 'failed' ) );
	return $sent;
}

/**
 * The link in the email: count the click, then on to the chapter.
 */
function sella_fc_handle_link() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link from an email.
	if ( ! isset( $_GET['sella_chapter'] ) || ! is_string( $_GET['sella_chapter'] ) ) {
		return;
	}

	global $wpdb;
	$table = sella_fc_table();
	$token = preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['sella_chapter'] ) );
	// phpcs:enable
	$row = 32 === strlen( $token ) ? $wpdb->get_row( $wpdb->prepare( "SELECT id, chapter_id FROM {$table} WHERE token = %s", $token ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$url = $row ? sella_fc_file_url( (int) $row->chapter_id ) : '';

	if ( '' === $url ) {
		wp_die( 'הקישור אינו תקף, או שהפרק כבר לא זמין.', 'הקישור אינו תקף', array( 'response' => 404, 'link_url' => home_url( '/' ), 'link_text' => 'לאתר' ) );
	}

	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET clicks = clicks + 1, clicked_at = COALESCE(clicked_at, %s) WHERE id = %d", current_time( 'mysql' ), (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	nocache_headers();
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the file may be on another host.
	exit;
}
add_action( 'template_redirect', 'sella_fc_handle_link', 1 );

/**
 * ---------------------------------------------------------------------------
 * Webhook
 * ---------------------------------------------------------------------------
 */

/**
 * What the webhook gets for a log row.
 *
 * @param object $row Log row.
 * @return array
 */
function sella_fc_webhook_payload( $row ) {
	$product = $row->product_id && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row->product_id ) : null;
	$fields  = $row->fields ? json_decode( $row->fields, true ) : array();

	return array(
		'event'      => 'first_chapter_requested',
		'id'         => (int) $row->id,
		'date'       => mysql2date( 'c', $row->created_at, false ),
		'name'       => (string) $row->name,
		'email'      => (string) $row->email,
		'phone'      => (string) $row->phone,
		'chapter'    => array(
			'id'       => (int) $row->chapter_id,
			'book'     => sella_fc_book( (int) $row->chapter_id ),
			'link'     => sella_fc_link( $row->token ),
			'file_url' => sella_fc_file_url( (int) $row->chapter_id ),
		),
		'product'    => $product ? array(
			'id'   => $product->get_id(),
			'name' => wp_strip_all_tags( $product->get_name() ),
			'url'  => $product->get_permalink(),
		) : null,
		'source'     => (string) $row->source,
		'form_name'  => (string) $row->form_name,
		'page_url'   => (string) $row->page_url,
		'fields'     => is_array( $fields ) && $fields ? $fields : new stdClass(),
		'email_sent' => 'sent' === $row->mail_status,
		'site'       => home_url( '/' ),
	);
}

/**
 * POST a payload as JSON.
 *
 * @param string $url     Webhook.
 * @param array  $payload Body.
 * @return array{code: int, error: string}
 */
function sella_fc_post_webhook( $url, $payload ) {
	$response = wp_remote_post(
		$url,
		array(
			'timeout'     => 10,
			'redirection' => 3,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'data_format' => 'body',
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'code'  => 0,
			'error' => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	return array(
		'code'  => $code,
		'error' => $code >= 200 && $code < 300 ? '' : wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ),
	);
}

/**
 * Call the webhook for a log row and record the answer.
 *
 * @param int $log_id Row.
 * @return bool
 */
function sella_fc_send_webhook( $log_id ) {
	$row      = sella_fc_log_row( $log_id );
	$settings = sella_fc_settings();
	if ( ! $row ) {
		return false;
	}
	if ( ! $settings['webhook_on'] || '' === $settings['webhook'] ) {
		sella_fc_log_update( $row->id, array( 'webhook_status' => 'off' ) );
		return false;
	}

	$result = sella_fc_post_webhook( $settings['webhook'], sella_fc_webhook_payload( $row ) );
	$ok     = $result['code'] >= 200 && $result['code'] < 300;

	sella_fc_log_update(
		$row->id,
		array(
			'webhook_status' => $ok ? 'ok' : 'failed',
			'webhook_code'   => $result['code'],
			'webhook_error'  => mb_substr( $result['error'], 0, 255 ),
		)
	);
	return $ok;
}

/**
 * Rows waiting for the webhook until the end of the request.
 *
 * @param int $log_id Row to add; 0 only reads the list.
 * @return int[]
 */
function sella_fc_webhook_queue( $log_id = 0 ) {
	static $ids = array();
	if ( $log_id ) {
		if ( ! $ids ) {
			add_action( 'shutdown', 'sella_fc_flush_webhooks', 100 );
		}
		$ids[] = (int) $log_id;
	}
	return $ids;
}

/**
 * Mark a row for the webhook, or as having none.
 *
 * @param int $log_id Row.
 */
function sella_fc_queue_webhook( $log_id ) {
	$settings = sella_fc_settings();
	if ( ! $settings['webhook_on'] || '' === $settings['webhook'] ) {
		sella_fc_log_update( $log_id, array( 'webhook_status' => 'off' ) );
		return;
	}
	sella_fc_log_update( $log_id, array( 'webhook_status' => 'pending' ) );
	sella_fc_webhook_queue( $log_id );
}

/**
 * At the end of the request: hand the visitor their answer, then call the
 * webhook. WordPress has flushed its output buffers by now (priority 1).
 */
function sella_fc_flush_webhooks() {
	$ids = sella_fc_webhook_queue();
	if ( ! $ids ) {
		return;
	}
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	foreach ( $ids as $id ) {
		sella_fc_send_webhook( $id );
	}
}

/**
 * ---------------------------------------------------------------------------
 * The form on the product page
 * ---------------------------------------------------------------------------
 */

/**
 * The product whose page this is, if any.
 *
 * @return int
 */
function sella_fc_current_product_id() {
	if ( function_exists( 'is_product' ) && is_product() ) {
		return (int) get_queried_object_id();
	}
	global $product;
	return $product instanceof WC_Product ? $product->get_id() : 0;
}

/**
 * Inside Elementor's editor, where the form shows even without a chapter so
 * the template can be designed.
 *
 * @return bool
 */
function sella_fc_in_elementor_editor() {
	return class_exists( '\Elementor\Plugin' )
		&& isset( \Elementor\Plugin::$instance->preview )
		&& \Elementor\Plugin::$instance->preview->is_preview_mode();
}

/**
 * Load the form's CSS and JS.
 */
function sella_fc_enqueue() {
	$dir  = get_stylesheet_directory();
	$base = get_stylesheet_directory_uri();

	wp_enqueue_style( 'sella-first-chapter', $base . '/assets/css/sella-first-chapter.css', array(), (string) filemtime( $dir . '/assets/css/sella-first-chapter.css' ) );
	wp_enqueue_script( 'sella-first-chapter', $base . '/assets/js/sella-first-chapter.js', array(), (string) filemtime( $dir . '/assets/js/sella-first-chapter.js' ), true );
	wp_localize_script( 'sella-first-chapter', 'sellaFirstChapter', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
}

/**
 * In the head when the page will show the form, so it is styled from the
 * start: a product with a chapter (the shortcode is in the product template),
 * or a page that has the shortcode itself.
 */
function sella_fc_maybe_enqueue() {
	if ( function_exists( 'is_product' ) && is_product() && sella_fc_product_chapter( get_queried_object_id() ) ) {
		sella_fc_enqueue();
		return;
	}
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( $post && ( has_shortcode( $post->post_content, 'sella_first_chapter' ) || false !== strpos( (string) get_post_meta( $post->ID, '_elementor_data', true ), 'sella_first_chapter' ) ) ) {
		sella_fc_enqueue();
	}
}
add_action( 'wp_enqueue_scripts', 'sella_fc_maybe_enqueue', 30 );

/**
 * [sella_first_chapter]
 *
 * Shows only on a product given a chapter on the settings screen, unless
 * chapter="ID" names one. Also: product="ID", title, text (where {book} is the
 * book's name) and button.
 *
 * @param array|string $atts Attributes.
 * @return string
 */
function sella_fc_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'chapter' => '',
			'product' => '',
			'title'   => 'קבלו פרק ראשון לדוגמה, ישירות למייל',
			'text'    => 'השאירו שם ומייל ונשלח אליכם את הפרק הראשון של {book}.',
			'button'  => 'שלחו לי את הפרק',
		),
		$atts,
		'sella_first_chapter'
	);

	$product_id = absint( $atts['product'] ) ? absint( $atts['product'] ) : sella_fc_current_product_id();
	$chapter_id = absint( $atts['chapter'] ) ? absint( $atts['chapter'] ) : sella_fc_product_chapter( $product_id );

	// A product's own form once per page: under the description
	// (sella_fc_after_description) or from the shortcode in the template.
	static $shown = array();
	if ( ! absint( $atts['chapter'] ) && $chapter_id ) {
		if ( isset( $shown[ $product_id ] ) ) {
			return '';
		}
		$shown[ $product_id ] = true;
	}

	if ( ! sella_fc_chapter_ok( $chapter_id ) ) {
		if ( ! sella_fc_in_elementor_editor() ) {
			return '';
		}
		// In the editor: any chapter, to show what the form looks like.
		$any        = get_posts( array( 'post_type' => SELLA_FC_CPT, 'numberposts' => 1, 'fields' => 'ids' ) );
		$chapter_id = $any ? (int) $any[0] : 0;
	}

	static $count = 0;
	++$count;
	$p = 'sella-fc-' . $count;

	sella_fc_enqueue();

	$book = $chapter_id ? sella_fc_book( $chapter_id ) : 'הספר';
	$text = str_replace( '{book}', $book, $atts['text'] );

	ob_start();
	?>
	<div class="sella-fc" data-sella-fc data-chapter="<?php echo (int) $chapter_id; ?>" data-product="<?php echo (int) $product_id; ?>">
		<span class="sella-fc__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.8 6.6 12 13l8.2-6.4"/></svg>
		</span>
		<h3 class="sella-fc__title"><?php echo esc_html( $atts['title'] ); ?></h3>
		<form class="sella-fc__form" novalidate>
			<?php if ( '' !== trim( $text ) ) : ?>
				<p class="sella-fc__text"><?php echo esc_html( $text ); ?></p>
			<?php endif; ?>
			<div class="sella-fc__fields">
				<div class="sella-fc__field" data-field="name">
					<label class="sella-fc__label" for="<?php echo esc_attr( $p ); ?>-name">שם</label>
					<input class="sella-fc__input" type="text" id="<?php echo esc_attr( $p ); ?>-name" name="name" autocomplete="name" maxlength="190" required aria-required="true" aria-describedby="<?php echo esc_attr( $p ); ?>-name-error" />
					<p class="sella-fc__error" id="<?php echo esc_attr( $p ); ?>-name-error" hidden></p>
				</div>
				<div class="sella-fc__field" data-field="email">
					<label class="sella-fc__label" for="<?php echo esc_attr( $p ); ?>-email">אימייל</label>
					<input class="sella-fc__input" type="email" id="<?php echo esc_attr( $p ); ?>-email" name="email" autocomplete="email" inputmode="email" dir="ltr" maxlength="190" required aria-required="true" aria-describedby="<?php echo esc_attr( $p ); ?>-email-error" />
					<p class="sella-fc__error" id="<?php echo esc_attr( $p ); ?>-email-error" hidden></p>
				</div>
			</div>
			<?php // Hidden from people; bots fill it in. ?>
			<div class="sella-fc__trap" aria-hidden="true"><label>אתר אינטרנט<input type="text" name="website" tabindex="-1" autocomplete="off" /></label></div>
			<p class="sella-fc__status" role="alert" hidden></p>
			<button type="submit" class="sella-fc__submit"><?php echo esc_html( $atts['button'] ); ?></button>
		</form>
		<div class="sella-fc__done" role="status" tabindex="-1" hidden>
			<p class="sella-fc__done-title">הפרק בדרך אליכם!</p>
			<p class="sella-fc__done-text">שלחנו אותו אל <bdi class="sella-fc__done-email" dir="ltr"></bdi>. אם הוא לא מגיע בעוד כמה דקות, כדאי להציץ בתיקיית הספאם.</p>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'sella_first_chapter', 'sella_fc_shortcode' );

/**
 * The form under the description of a book that has a chapter, so the
 * product template needs no shortcode. After the "האם הספר מתאים לי?" row
 * (sella-ai-consult.php, priority 20).
 *
 * @param string                 $content Widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget.
 * @return string
 */
function sella_fc_after_description( $content, $widget ) {
	if ( 'woocommerce-product-content' !== $widget->get_name() || ! function_exists( 'is_product' ) || ! is_product() ) {
		return $content;
	}
	if ( ! sella_fc_product_chapter( get_queried_object_id() ) ) {
		return $content;
	}
	return $content . sella_fc_shortcode( array( 'product' => get_queried_object_id() ) );
}
add_filter( 'elementor/widget/render_content', 'sella_fc_after_description', 30, 2 );

/**
 * AJAX: a request from the form.
 */
function sella_fc_ajax_request() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- cached pages; see sella-leads.php.
	$input = wp_unslash( $_POST );
	// phpcs:enable

	// Bots fill in every field and post at once. Pretend it went through.
	if ( ! empty( $input['website'] ) || (int) ( $input['elapsed'] ?? 0 ) < 2000 ) {
		wp_send_json_success();
	}

	$name   = trim( sanitize_text_field( $input['name'] ?? '' ) );
	$email  = sanitize_email( $input['email'] ?? '' );
	$errors = array();

	if ( mb_strlen( $name ) < 2 ) {
		$errors['name'] = 'נא למלא שם';
	}
	if ( '' === trim( (string) ( $input['email'] ?? '' ) ) ) {
		$errors['email'] = 'נא למלא כתובת אימייל';
	} elseif ( ! is_email( $email ) || mb_strlen( $email ) > 190 ) {
		$errors['email'] = 'כתובת האימייל לא תקינה';
	}
	if ( $errors ) {
		wp_send_json_error(
			array(
				'message' => 'יש לתקן את השדות המסומנים.',
				'errors'  => $errors,
			),
			400
		);
	}

	$chapter_id = absint( $input['chapter'] ?? 0 );
	$product_id = absint( $input['product'] ?? 0 );
	if ( ! sella_fc_chapter_ok( $chapter_id ) ) {
		wp_send_json_error( array( 'message' => 'הפרק הזה לא זמין כרגע.' ), 404 );
	}
	if ( ! sella_leads_rate_ok( 'first_chapter', 6 ) ) {
		wp_send_json_error( array( 'message' => 'נשלחו כבר כמה בקשות מכאן. נסו שוב בעוד כמה דקות.' ), 429 );
	}

	$log_id = sella_fc_deliver(
		array(
			'chapter_id' => $chapter_id,
			'product_id' => $product_id && 'product' === get_post_type( $product_id ) ? $product_id : 0,
			'name'       => $name,
			'email'      => $email,
			'source'     => 'shortcode',
			'form_name'  => 'טופס פרק ראשון',
			'page_url'   => sella_leads_page_url( $input['page_url'] ?? '' ),
		)
	);
	$row = $log_id ? sella_fc_log_row( $log_id ) : null;

	// The email is the only way the chapter goes out and it failed.
	if ( ! $row || ( 'failed' === $row->mail_status && 'off' === $row->webhook_status ) ) {
		wp_send_json_error( array( 'message' => 'לא הצלחנו לשלוח את הפרק. נסו שוב בעוד כמה דקות.' ), 500 );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_sella_first_chapter', 'sella_fc_ajax_request' );
add_action( 'wp_ajax_nopriv_sella_first_chapter', 'sella_fc_ajax_request' );

/**
 * ---------------------------------------------------------------------------
 * Elementor forms
 * ---------------------------------------------------------------------------
 */

/**
 * Every Elementor Pro form on the site, with its fields, for the settings
 * screen. Read from each page's and template's _elementor_data.
 *
 * @return array[] id, name, post_id, post_title, fields [{id, label, type, options [{label, value}]}].
 */
function sella_fc_elementor_forms() {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, m.meta_value FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = '_elementor_data' AND m.meta_value LIKE %s
			AND p.post_type <> 'revision' AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'future')
			ORDER BY p.post_title",
			'%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%'
		)
	);

	$forms = array();
	foreach ( $rows as $row ) {
		$data = json_decode( $row->meta_value, true );
		if ( is_array( $data ) ) {
			sella_fc_collect_forms( $data, $row, $forms );
		}
	}
	return array_values( $forms );
}

/**
 * Walk Elementor's element tree and collect its form widgets.
 *
 * @param array  $elements Elements.
 * @param object $post     ID and post_title.
 * @param array  $forms    Forms found, by element ID.
 */
function sella_fc_collect_forms( $elements, $post, &$forms ) {
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}

		if ( 'form' === ( $element['widgetType'] ?? '' ) && ! empty( $element['id'] ) && ! isset( $forms[ $element['id'] ] ) ) {
			$settings = (array) ( $element['settings'] ?? array() );
			$fields   = array();

			foreach ( (array) ( $settings['form_fields'] ?? array() ) as $field ) {
				$type = (string) ( $field['field_type'] ?? 'text' );
				$id   = (string) ( $field['custom_id'] ?? '' );
				if ( '' === $id || in_array( $type, array( 'step', 'html', 'honeypot', 'recaptcha', 'recaptcha_v3' ), true ) ) {
					continue;
				}

				// One answer per line, "label|value" when they differ.
				$options = array();
				foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $field['field_options'] ?? '' ) ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$parts     = explode( '|', $line, 2 );
						$options[] = array(
							'label' => trim( $parts[0] ),
							'value' => trim( $parts[1] ?? $parts[0] ),
						);
					}
				}

				$label    = trim( wp_strip_all_tags( (string) ( $field['field_label'] ?? '' ) ) );
				$fields[] = array(
					'id'      => $id,
					'label'   => '' !== $label ? $label : $id,
					'type'    => $type,
					'options' => $options,
				);
			}

			$forms[ $element['id'] ] = array(
				'id'         => (string) $element['id'],
				'name'       => (string) ( $settings['form_name'] ?? '' ),
				'post_id'    => (int) $post->ID,
				'post_title' => (string) $post->post_title,
				'fields'     => $fields,
			);
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			sella_fc_collect_forms( $element['elements'], $post, $forms );
		}
	}
}

/**
 * The connection set for an Elementor form, by the form's element ID.
 *
 * @param string $form_id Element ID.
 * @return array|null
 */
function sella_fc_connection( $form_id ) {
	if ( '' === (string) $form_id ) {
		return null;
	}
	foreach ( sella_fc_settings()['forms'] as $connection ) {
		if ( (string) $form_id === $connection['form'] ) {
			return $connection;
		}
	}
	return null;
}

/**
 * @param object $record Elementor's Form_Record.
 * @return string
 */
function sella_fc_record_form_id( $record ) {
	return is_object( $record ) && method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'id' ) : '';
}

/**
 * Which chapter a connected form's submission gets: the one given to the
 * answer of the choosing field, else the fallback (the product page's chapter
 * or a fixed one).
 *
 * @param array $connection Connection.
 * @param array $fields     Submitted fields, by ID.
 * @param int   $product_id Product page the form was on, or 0.
 * @return int
 */
function sella_fc_connection_chapter( $connection, $fields, $product_id ) {
	$choice = $connection['choice'];
	if ( '' !== $choice && isset( $fields[ $choice ] ) ) {
		$answers = $fields[ $choice ]['raw_value'] ?? ( $fields[ $choice ]['value'] ?? '' );
		foreach ( (array) $answers as $answer ) {
			$chapter = (int) ( $connection['choices'][ trim( (string) $answer ) ] ?? 0 );
			if ( sella_fc_chapter_ok( $chapter ) ) {
				return $chapter;
			}
		}
	}

	if ( 'product' === $connection['fallback'] ) {
		return sella_fc_product_chapter( $product_id );
	}
	$chapter = (int) $connection['fallback'];
	return sella_fc_chapter_ok( $chapter ) ? $chapter : 0;
}

/**
 * A connected Elementor form was sent: send its chapter.
 *
 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Submission.
 */
function sella_fc_from_elementor_form( $record ) {
	if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
		return;
	}
	$connection = sella_fc_connection( sella_fc_record_form_id( $record ) );
	if ( ! $connection ) {
		return;
	}

	$fields = (array) $record->get( 'fields' );
	$value  = function ( $id ) use ( $fields ) {
		if ( '' === $id || ! isset( $fields[ $id ] ) ) {
			return '';
		}
		$value = $fields[ $id ]['value'] ?? '';
		return trim( is_array( $value ) ? implode( ', ', $value ) : (string) $value );
	};

	$email = $value( $connection['email'] );
	if ( ! is_email( $email ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Elementor checked the submission.
	$queried  = isset( $_POST['queried_id'] ) ? absint( wp_unslash( $_POST['queried_id'] ) ) : 0;
	$referrer = isset( $_POST['referrer'] ) ? sanitize_text_field( wp_unslash( $_POST['referrer'] ) ) : (string) wp_get_raw_referer();
	// phpcs:enable
	$product_id = $queried && 'product' === get_post_type( $queried ) ? $queried : 0;

	$chapter_id = sella_fc_connection_chapter( $connection, $fields, $product_id );
	if ( ! $chapter_id ) {
		return;
	}

	// Whatever else the form asked, for the log and the webhook.
	$extra  = array();
	$mapped = array( $connection['name'], $connection['email'], $connection['phone'] );
	foreach ( $fields as $id => $field ) {
		$type = $field['type'] ?? '';
		if ( in_array( (string) $id, $mapped, true ) || in_array( $type, array( 'honeypot', 'recaptcha', 'recaptcha_v3', 'html', 'step' ), true ) ) {
			continue;
		}
		$text = $value( (string) $id );
		if ( '' !== $text ) {
			$extra[ '' !== ( $field['title'] ?? '' ) ? $field['title'] : $id ] = $text;
		}
	}

	$form_name = (string) $record->get_form_settings( 'form_name' );

	sella_fc_deliver(
		array(
			'chapter_id' => $chapter_id,
			'product_id' => $product_id,
			'name'       => $value( $connection['name'] ),
			'email'      => $email,
			'phone'      => $value( $connection['phone'] ),
			'source'     => 'elementor',
			'form_name'  => '' !== $form_name ? $form_name : $connection['label'],
			'page_url'   => sella_leads_page_url( $referrer ),
			'fields'     => $extra,
		)
	);
}
add_action( 'elementor_pro/forms/new_record', 'sella_fc_from_elementor_form' );

/**
 * A connected form is recorded as a first-chapter lead above, not again as a
 * newsletter signup or "other form".
 *
 * @param bool   $skip   Skip it.
 * @param object $record Submission.
 * @return bool
 */
function sella_fc_skip_elementor_lead( $skip, $record ) {
	return $skip || null !== sella_fc_connection( sella_fc_record_form_id( $record ) );
}
add_filter( 'sella_leads_skip_elementor_form', 'sella_fc_skip_elementor_lead', 10, 2 );

/**
 * ---------------------------------------------------------------------------
 * Admin: menu and chapter screens
 * ---------------------------------------------------------------------------
 */

/**
 * The chapters, the sent log and the settings, under פניות ולידים.
 */
function sella_fc_register_admin_pages() {
	add_submenu_page( SELLA_LEADS_SLUG, 'פרקים ראשונים', 'פרקים ראשונים', 'edit_posts', 'edit.php?post_type=' . SELLA_FC_CPT );
	add_submenu_page( SELLA_LEADS_SLUG, 'פרקים ראשונים שנשלחו', 'פרקים שנשלחו', 'manage_woocommerce', SELLA_FC_SENT_SLUG, 'sella_fc_render_sent_page' );
	add_submenu_page( SELLA_LEADS_SLUG, 'הגדרות פרק ראשון', 'הגדרות פרק ראשון', 'manage_woocommerce', SELLA_FC_SETTINGS_SLUG, 'sella_fc_render_settings_page' );
}
add_action( 'admin_menu', 'sella_fc_register_admin_pages', 20 );

/**
 * Keep פניות ולידים open while editing a chapter.
 *
 * @param string $parent_file Menu item.
 * @return string
 */
function sella_fc_admin_parent_file( $parent_file ) {
	$screen = get_current_screen();
	return $screen && SELLA_FC_CPT === $screen->post_type ? SELLA_LEADS_SLUG : $parent_file;
}
add_filter( 'parent_file', 'sella_fc_admin_parent_file' );

/**
 * @param string|null $submenu_file Submenu item.
 * @return string|null
 */
function sella_fc_admin_submenu_file( $submenu_file ) {
	$screen = get_current_screen();
	return $screen && SELLA_FC_CPT === $screen->post_type ? 'edit.php?post_type=' . SELLA_FC_CPT : $submenu_file;
}
add_filter( 'submenu_file', 'sella_fc_admin_submenu_file' );

/**
 * @param string  $text Placeholder.
 * @param WP_Post $post Post.
 * @return string
 */
function sella_fc_title_placeholder( $text, $post ) {
	return SELLA_FC_CPT === $post->post_type ? 'שם הספר' : $text;
}
add_filter( 'enter_title_here', 'sella_fc_title_placeholder', 10, 2 );

/**
 * Meta boxes on the chapter screen.
 */
function sella_fc_meta_boxes() {
	add_meta_box( 'sella-fc-file', 'קובץ הפרק', 'sella_fc_render_file_box', SELLA_FC_CPT, 'normal', 'high' );
	add_meta_box( 'sella-fc-usage', 'איפה הפרק נשלח', 'sella_fc_render_usage_box', SELLA_FC_CPT, 'side' );
}

/**
 * The file: from the media library, or a link to it elsewhere.
 *
 * @param WP_Post $post Chapter.
 */
function sella_fc_render_file_box( $post ) {
	$file_id  = (int) get_post_meta( $post->ID, '_sella_chapter_file', true );
	$file_url = $file_id ? wp_get_attachment_url( $file_id ) : '';
	$url      = (string) get_post_meta( $post->ID, '_sella_chapter_url', true );
	wp_nonce_field( 'sella_fc_chapter_save', 'sella_fc_chapter_nonce' );
	?>
	<div class="sella-fc-file" dir="rtl">
		<p class="description">שם הספר (הכותרת למעלה) מופיע בטופס, בנושא המייל ובגוף שלו. העטיפה, אם בוחרים אחת בצד, מופיעה במייל מעל הכפתור.</p>
		<input type="hidden" name="sella_chapter_file" value="<?php echo (int) $file_id; ?>" data-fc-file-id />
		<p class="sella-fc-file__current" <?php echo $file_url ? '' : 'hidden'; ?>>
			<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
			<a href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener" data-fc-file-name><?php echo esc_html( $file_url ? wp_basename( $file_url ) : '' ); ?></a>
			<button type="button" class="button-link button-link-delete" data-fc-file-remove>הסרה</button>
		</p>
		<p>
			<button type="button" class="button" data-fc-file-pick>העלאה או בחירה מספריית המדיה</button>
		</p>
		<p>
			<label for="sella_chapter_url"><strong>או קישור לקובץ</strong> (Google Drive, Dropbox וכדומה)</label><br />
			<input type="url" id="sella_chapter_url" name="sella_chapter_url" value="<?php echo esc_attr( $url ); ?>" class="large-text" dir="ltr" placeholder="https://" />
			<span class="description">כשיש קובץ מספריית המדיה, הוא נשלח והקישור הזה לא.</span>
		</p>
	</div>
	<?php
}

/**
 * Where the chapter is used, and how many times it went out.
 *
 * @param WP_Post $post Chapter.
 */
function sella_fc_render_usage_box( $post ) {
	global $wpdb;
	$settings = sella_fc_settings();
	$products = array();
	foreach ( $settings['products'] as $row ) {
		if ( (int) $row['chapter'] === $post->ID ) {
			$products[] = (int) $row['product'];
		}
	}
	$forms = array();
	foreach ( $settings['forms'] as $connection ) {
		if ( (string) $post->ID === $connection['fallback'] || in_array( $post->ID, array_map( 'intval', $connection['choices'] ), true ) ) {
			$forms[] = '' !== $connection['label'] ? $connection['label'] : $connection['form'];
		}
	}
	$table = sella_fc_table();
	$sent  = get_option( 'sella_fc_db_version' ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE chapter_id = %d", $post->ID ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	?>
	<div dir="rtl">
		<p><strong>מוצרים:</strong>
			<?php if ( $products ) : ?>
				<?php
				echo implode(
					', ',
					array_map(
						function ( $id ) {
							return '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
						},
						$products
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				?>
			<?php else : ?>
				אין
			<?php endif; ?>
		</p>
		<p><strong>טפסי Elementor:</strong> <?php echo $forms ? esc_html( implode( ', ', $forms ) ) : 'אין'; ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SELLA_FC_SETTINGS_SLUG ) ); ?>">שיוך למוצרים ולטפסים</a></p>
		<p><strong>נשלח:</strong> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SELLA_FC_SENT_SLUG . '&chapter=' . $post->ID ) ); ?>"><?php echo (int) $sent; ?> פעמים</a></p>
		<p><strong>טופס לפרק הזה בכל עמוד:</strong><br /><code>[sella_first_chapter chapter="<?php echo (int) $post->ID; ?>"]</code></p>
	</div>
	<?php
}

/**
 * Save the file fields.
 *
 * @param int $post_id Chapter.
 */
function sella_fc_save_chapter( $post_id ) {
	if ( ! isset( $_POST['sella_fc_chapter_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sella_fc_chapter_nonce'] ) ), 'sella_fc_chapter_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$file = isset( $_POST['sella_chapter_file'] ) ? absint( $_POST['sella_chapter_file'] ) : 0;
	$url  = isset( $_POST['sella_chapter_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['sella_chapter_url'] ) ), array( 'http', 'https' ) ) : '';

	if ( $file && 'attachment' === get_post_type( $file ) ) {
		update_post_meta( $post_id, '_sella_chapter_file', $file );
	} else {
		delete_post_meta( $post_id, '_sella_chapter_file' );
	}
	if ( '' !== $url ) {
		update_post_meta( $post_id, '_sella_chapter_url', $url );
	} else {
		delete_post_meta( $post_id, '_sella_chapter_url' );
	}
}
add_action( 'save_post_' . SELLA_FC_CPT, 'sella_fc_save_chapter' );

/**
 * Columns of the chapter list.
 *
 * @param array $columns Columns.
 * @return array
 */
function sella_fc_list_columns( $columns ) {
	$date = $columns['date'] ?? null;
	unset( $columns['date'] );
	$columns['sella_fc_file']     = 'קובץ';
	$columns['sella_fc_products'] = 'מוצרים';
	$columns['sella_fc_sent']     = 'נשלח';
	if ( $date ) {
		$columns['date'] = $date;
	}
	return $columns;
}
add_filter( 'manage_' . SELLA_FC_CPT . '_posts_columns', 'sella_fc_list_columns' );

/**
 * @param string $column  Column.
 * @param int    $post_id Chapter.
 */
function sella_fc_list_column( $column, $post_id ) {
	static $sent = null;

	if ( 'sella_fc_file' === $column ) {
		$url = sella_fc_file_url( $post_id );
		echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" dir="ltr">' . esc_html( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) . '</a>' : '<span style="color:#b32d2e;">אין קובץ</span>';
	} elseif ( 'sella_fc_products' === $column ) {
		$names = array();
		foreach ( sella_fc_settings()['products'] as $row ) {
			if ( (int) $row['chapter'] === (int) $post_id ) {
				$names[] = get_the_title( (int) $row['product'] );
			}
		}
		echo $names ? esc_html( implode( ', ', $names ) ) : '—';
	} elseif ( 'sella_fc_sent' === $column ) {
		if ( null === $sent ) {
			global $wpdb;
			$table = sella_fc_table();
			$sent  = get_option( 'sella_fc_db_version' ) ? $wpdb->get_results( "SELECT chapter_id, COUNT(*) AS total FROM {$table} GROUP BY chapter_id", OBJECT_K ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$total = isset( $sent[ $post_id ] ) ? (int) $sent[ $post_id ]->total : 0;
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . SELLA_FC_SENT_SLUG . '&chapter=' . $post_id ) ) . '">' . (int) $total . '</a>';
	}
}
add_action( 'manage_' . SELLA_FC_CPT . '_posts_custom_column', 'sella_fc_list_column', 10, 2 );

/**
 * Admin assets: the file picker on the chapter screen; product search and
 * the Elementor form cards on the settings screen; the table styles on the log.
 *
 * @param string $hook Admin hook.
 */
function sella_fc_admin_assets( $hook ) {
	$screen  = get_current_screen();
	$chapter = $screen && SELLA_FC_CPT === $screen->post_type && 'post' === $screen->base;
	$page    = false !== strpos( $hook, SELLA_FC_SETTINGS_SLUG ) ? 'settings' : ( false !== strpos( $hook, SELLA_FC_SENT_SLUG ) ? 'sent' : '' );
	if ( ! $chapter && '' === $page ) {
		return;
	}

	$dir  = get_stylesheet_directory();
	$base = get_stylesheet_directory_uri();
	$deps = array( 'jquery' );

	if ( $chapter ) {
		wp_enqueue_media();
	}
	if ( 'settings' === $page ) {
		wp_enqueue_style( 'woocommerce_admin_styles' );
		$deps[] = 'wc-enhanced-select';
	}
	if ( 'sent' === $page ) {
		wp_enqueue_style( 'sella-leads-admin', $base . '/assets/css/sella-leads-admin.css', array(), (string) filemtime( $dir . '/assets/css/sella-leads-admin.css' ) );
	}

	wp_enqueue_style( 'sella-first-chapter-admin', $base . '/assets/css/sella-first-chapter-admin.css', array(), (string) filemtime( $dir . '/assets/css/sella-first-chapter-admin.css' ) );
	wp_enqueue_script( 'sella-first-chapter-admin', $base . '/assets/js/sella-first-chapter-admin.js', $deps, (string) filemtime( $dir . '/assets/js/sella-first-chapter-admin.js' ), true );

	$data = array(
		'pickTitle'  => 'קובץ הפרק הראשון',
		'pickButton' => 'בחירת הקובץ',
	);
	if ( 'settings' === $page ) {
		$chapters = array();
		foreach ( sella_fc_chapter_choices() as $id => $title ) {
			$chapters[] = array(
				'id'    => $id,
				'title' => $title,
			);
		}
		$data['forms']       = sella_fc_elementor_forms();
		$data['chapters']    = $chapters;
		$data['connections'] = sella_fc_settings()['forms'];
	}
	wp_add_inline_script( 'sella-first-chapter-admin', 'window.sellaFcAdmin = ' . wp_json_encode( $data ) . ';', 'before' );
}
add_action( 'admin_enqueue_scripts', 'sella_fc_admin_assets' );

/**
 * ---------------------------------------------------------------------------
 * Admin: settings screen
 * ---------------------------------------------------------------------------
 */

/**
 * Save the settings screen, and the two test buttons on it.
 */
function sella_fc_handle_settings() {
	if ( ! is_admin() || empty( $_POST['sella_fc_form'] ) || 'settings' !== $_POST['sella_fc_form'] || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'sella_fc_settings' );

	// Saving an option from the admin clears the page cache (inc/sella-page-cache.php).
	update_option( SELLA_FC_SETTINGS, sella_fc_sanitize( wp_unslash( $_POST ) ), false );

	$args = array( 'notice' => 'saved' );
	$test = isset( $_POST['sella_fc_test'] ) ? sanitize_key( wp_unslash( $_POST['sella_fc_test'] ) ) : '';

	if ( 'mail' === $test ) {
		$user    = wp_get_current_user();
		$chapter = 0;
		foreach ( array_keys( sella_fc_chapter_choices() ) as $id ) {
			if ( sella_fc_chapter_ok( $id ) ) {
				$chapter = $id;
				break;
			}
		}
		$args['notice'] = ! $chapter ? 'mail_no_chapter' : ( sella_fc_mail( $user->user_email, $user->first_name ? $user->first_name : $user->display_name, $chapter, sella_fc_file_url( $chapter ) ) ? 'mail_ok' : 'mail_failed' );
	} elseif ( 'webhook' === $test ) {
		$settings = sella_fc_settings();
		if ( '' === $settings['webhook'] ) {
			$args['notice'] = 'webhook_empty';
		} else {
			$result         = sella_fc_post_webhook( $settings['webhook'], sella_fc_sample_payload() );
			$args['notice'] = $result['code'] >= 200 && $result['code'] < 300 ? 'webhook_ok' : 'webhook_failed';
			$args['code']   = $result['code'];
		}
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . SELLA_FC_SETTINGS_SLUG ) ) );
	exit;
}
add_action( 'admin_init', 'sella_fc_handle_settings' );

/**
 * A made-up request, for the test button and the example on the screen.
 *
 * @return array
 */
function sella_fc_sample_payload() {
	$chapter = 0;
	foreach ( array_keys( sella_fc_chapter_choices() ) as $id ) {
		$chapter = $id;
		break;
	}
	return array(
		'event'      => 'first_chapter_requested',
		'test'       => true,
		'id'         => 0,
		'date'       => wp_date( 'c' ),
		'name'       => 'ישראל ישראלי',
		'email'      => 'reader@example.com',
		'phone'      => '0501234567',
		'chapter'    => array(
			'id'       => $chapter,
			'book'     => $chapter ? sella_fc_book( $chapter ) : 'שם הספר',
			'link'     => sella_fc_link( str_repeat( 'x', 32 ) ),
			'file_url' => $chapter ? sella_fc_file_url( $chapter ) : '',
		),
		'product'    => array(
			'id'   => 0,
			'name' => 'שם המוצר',
			'url'  => home_url( '/product/example/' ),
		),
		'source'     => 'shortcode',
		'form_name'  => 'טופס פרק ראשון',
		'page_url'   => home_url( '/product/example/' ),
		'fields'     => new stdClass(),
		'email_sent' => true,
		'site'       => home_url( '/' ),
	);
}

/**
 * One product row: the product, and the chapter it sends.
 *
 * @param string $index    Field index, or "__i__" in the template.
 * @param array  $row      product, chapter.
 * @param array  $chapters Chapter choices.
 */
function sella_fc_admin_product_row( $index, $row, $chapters ) {
	$name    = 'products[' . $index . ']';
	$product = ! empty( $row['product'] ) ? wc_get_product( (int) $row['product'] ) : null;
	?>
	<div class="sella-fc-map__row">
		<select class="wc-product-search" name="<?php echo esc_attr( $name ); ?>[product]" style="width:100%;" data-action="woocommerce_json_search_products" data-placeholder="חפשו ספר" data-allow_clear="true">
			<?php if ( $product ) : ?>
				<option value="<?php echo esc_attr( (string) $product->get_id() ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
			<?php endif; ?>
		</select>
		<span class="sella-fc-map__arrow" aria-hidden="true">&larr;</span>
		<select name="<?php echo esc_attr( $name ); ?>[chapter]">
			<option value="">בחרו פרק</option>
			<?php foreach ( $chapters as $id => $title ) : ?>
				<option value="<?php echo (int) $id; ?>" <?php selected( (int) ( $row['chapter'] ?? 0 ), $id ); ?>><?php echo esc_html( $title ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="button" class="button-link sella-fc-remove" data-remove="product" aria-label="הסרת השורה">&times;</button>
	</div>
	<?php
}

/**
 * The settings screen.
 */
function sella_fc_render_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$settings = sella_fc_settings();
	$chapters = sella_fc_chapter_choices();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
	$code   = isset( $_GET['code'] ) ? absint( $_GET['code'] ) : 0;
	// phpcs:enable
	$notices = array(
		'saved'           => array( 'success', 'ההגדרות נשמרו.' ),
		'mail_ok'         => array( 'success', 'ההגדרות נשמרו, ומייל לדוגמה נשלח אל ' . wp_get_current_user()->user_email . '.' ),
		'mail_failed'     => array( 'error', 'ההגדרות נשמרו, אבל המייל לדוגמה לא נשלח. כדאי לבדוק את הגדרות שליחת המיילים של האתר.' ),
		'mail_no_chapter' => array( 'warning', 'ההגדרות נשמרו. למייל לדוגמה צריך פרק אחד לפחות שפורסם ויש לו קובץ.' ),
		'webhook_ok'      => array( 'success', 'ההגדרות נשמרו, ובדיקת ה-webhook התקבלה (' . $code . ').' ),
		'webhook_failed'  => array( 'error', 'ההגדרות נשמרו, אבל ה-webhook ' . ( $code ? 'החזיר שגיאה ' . $code : 'לא ענה' ) . '.' ),
		'webhook_empty'   => array( 'warning', 'ההגדרות נשמרו. כדי לבדוק צריך להזין כתובת webhook.' ),
	);
	$example = wp_json_encode( sella_fc_sample_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	?>
	<div class="wrap sella-fc-admin" dir="rtl">
		<h1 class="wp-heading-inline">הגדרות פרק ראשון</h1>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . SELLA_FC_CPT ) ); ?>">הוספת פרק</a>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SELLA_FC_SENT_SLUG ) ); ?>">פרקים שנשלחו</a>
		<hr class="wp-header-end" />

		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! $chapters ) : ?>
			<div class="notice notice-warning"><p>עדיין אין פרקים. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . SELLA_FC_CPT ) ); ?>">מעלים פרק ראשון</a>, ואז משייכים אותו כאן למוצרים ולטפסים.</p></div>
		<?php endif; ?>

		<p class="sella-fc-lead">
			מי שמשאיר שם ומייל מקבל את הפרק הראשון במייל. כל בקשה נשמרת ב<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SELLA_FC_SENT_SLUG ) ); ?>">פרקים שנשלחו</a>
			ונוספת ל<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SELLA_LEADS_SLUG . '&type=first_chapter' ) ); ?>">פניות ולידים</a> בסוג "פרק ראשון".
		</p>

		<form method="post" class="sella-fc-form">
			<?php wp_nonce_field( 'sella_fc_settings' ); ?>
			<input type="hidden" name="sella_fc_form" value="settings" />

			<section class="sella-fc-section">
				<h2>פרק ראשון בדף המוצר</h2>
				<p class="description">
					הקוד <code>[sella_first_chapter]</code> בתבנית דף המוצר (בווידג'ט "קוד קצר" של Elementor) מציג טופס שם ומייל
					רק במוצרים שברשימה הזאת, ושולח את הפרק שבחרתם להם. במוצר שאינו ברשימה הטופס לא מופיע.
				</p>
				<div class="sella-fc-map">
					<?php
					foreach ( $settings['products'] as $i => $row ) {
						sella_fc_admin_product_row( (string) $i, $row, $chapters );
					}
					?>
				</div>
				<button type="button" class="button" data-add="product">+ הוספת מוצר</button>
				<details class="sella-fc-help">
					<summary>שינוי הטקסטים של הטופס</summary>
					<p>אפשר לשנות כל טקסט בקוד עצמו, למשל:</p>
					<p><code dir="ltr">[sella_first_chapter title="רוצים לטעום?" text="נשלח אליכם את הפרק הראשון של {book}." button="שלחו לי"]</code></p>
					<p><code>{book}</code> הוא שם הספר. <code>chapter="מספר"</code> מציג טופס לפרק מסוים בכל עמוד, גם לא בדף מוצר; המספר מופיע במסך הפרק.</p>
				</details>
			</section>

			<section class="sella-fc-section">
				<h2>טפסי Elementor</h2>
				<p class="description">
					טופס שמחברים כאן שולח פרק ראשון בכל פעם שממלאים אותו. בוחרים אילו שדות בטופס הם השם, המייל והטלפון, ואיזה פרק לשלוח:
					לפי התשובה לאחד השדות (למשל תחום עניין), הפרק של דף המוצר שבו הטופס נמצא, או פרק קבוע.
					הטופס ממשיך לעבוד כרגיל ב-Elementor, כולל הודעת התודה שלו.
				</p>
				<div class="sella-fc-conns" data-conns></div>
				<button type="button" class="button" data-add="form">+ חיבור טופס</button>
			</section>

			<section class="sella-fc-section">
				<h2>המייל עם הפרק</h2>
				<p>
					<label><input type="checkbox" name="mail" value="1" <?php checked( $settings['mail'] ); ?> /> לשלוח את הפרק במייל מהאתר</label>
					<span class="description">(אפשר לכבות אם ה-webhook שולח אותו, למשל ממערכת דיוור)</span>
				</p>
				<label class="sella-fc-field">
					<span>נושא</span>
					<input type="text" name="subject" value="<?php echo esc_attr( $settings['subject'] ); ?>" class="large-text" />
				</label>
				<label class="sella-fc-field">
					<span>כותרת בראש המייל</span>
					<input type="text" name="heading" value="<?php echo esc_attr( $settings['heading'] ); ?>" class="large-text" />
				</label>
				<label class="sella-fc-field">
					<span>הטקסט</span>
					<textarea name="body" rows="7" class="large-text"><?php echo esc_textarea( $settings['body'] ); ?></textarea>
				</label>
				<label class="sella-fc-field">
					<span>הכפתור לפרק</span>
					<input type="text" name="button" value="<?php echo esc_attr( $settings['button'] ); ?>" class="regular-text" />
				</label>
				<p class="description">
					<code>{name}</code> השם שהושאר, <code>{book}</code> שם הספר, <code>{link}</code> הקישור לפרק (אם רוצים אותו גם בטקסט). מתחת לטקסט באים עטיפת הספר והכפתור.
				</p>
				<button type="submit" name="sella_fc_test" value="mail" class="button">שמירה ושליחת מייל לדוגמה אליי</button>
			</section>

			<section class="sella-fc-section">
				<h2>Webhook</h2>
				<p>
					<label><input type="checkbox" name="webhook_on" value="1" <?php checked( $settings['webhook_on'] ); ?> /> לשלוח כל בקשה לכתובת webhook</label>
				</p>
				<label class="sella-fc-field">
					<span>כתובת</span>
					<input type="url" name="webhook" value="<?php echo esc_attr( $settings['webhook'] ); ?>" class="large-text" dir="ltr" placeholder="https://hook.eu1.make.com/..." />
				</label>
				<p class="description">
					בכל בקשה נשלח לכתובת POST עם JSON: פרטי מי שנרשם, שם הספר, הקישור לפרק, המוצר והטופס.
					אם הכתובת לא עונה, אפשר לשלוח שוב מ<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SELLA_FC_SENT_SLUG ) ); ?>">פרקים שנשלחו</a>.
				</p>
				<details class="sella-fc-help">
					<summary>דוגמה למה שנשלח</summary>
					<pre dir="ltr"><?php echo esc_html( (string) $example ); ?></pre>
					<p><code>chapter.link</code> הוא הקישור שבמייל, שסופר לחיצות. <code>chapter.file_url</code> הוא הקובץ עצמו.</p>
				</details>
				<button type="submit" name="sella_fc_test" value="webhook" class="button">שמירה ושליחת בדיקה ל-webhook</button>
			</section>

			<?php submit_button( 'שמירה' ); ?>
		</form>

		<script type="text/html" id="tmpl-sella-fc-product">
			<?php sella_fc_admin_product_row( '__i__', array(), $chapters ); ?>
		</script>
	</div>
	<?php
}

/**
 * ---------------------------------------------------------------------------
 * Admin: the sent log
 * ---------------------------------------------------------------------------
 */

/**
 * The log's filters, read from the query string.
 *
 * @return array{chapter: int, q: string}
 */
function sella_fc_sent_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$filters = array(
		'chapter' => isset( $_GET['chapter'] ) ? absint( $_GET['chapter'] ) : 0,
		'q'       => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
	);
	// phpcs:enable
	return $filters;
}

/**
 * @param array $filters Filters.
 * @return string
 */
function sella_fc_sent_where( $filters ) {
	global $wpdb;
	$where = array( '1=1' );
	if ( $filters['chapter'] ) {
		$where[] = $wpdb->prepare( 'chapter_id = %d', $filters['chapter'] );
	}
	if ( '' !== $filters['q'] ) {
		$like    = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
		$where[] = $wpdb->prepare( '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR form_name LIKE %s)', $like, $like, $like, $like );
	}
	return implode( ' AND ', $where );
}

/**
 * Resending and deleting from the log.
 */
function sella_fc_handle_sent_actions() {
	if ( ! is_admin() || empty( $_POST['sella_fc_form'] ) || 'sent' !== $_POST['sella_fc_form'] || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'sella_fc_sent' );

	global $wpdb;
	$table  = sella_fc_table();
	$back   = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . SELLA_FC_SENT_SLUG );
	$notice = '';

	// One row's button ("mail:12"), or the bulk action on the ticked rows.
	$row_action = isset( $_POST['row_action'] ) ? sanitize_text_field( wp_unslash( $_POST['row_action'] ) ) : '';
	if ( preg_match( '/^(mail|webhook|delete):(\d+)$/', $row_action, $m ) ) {
		$action = $m[1];
		$ids    = array( (int) $m[2] );
	} else {
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
	}

	if ( $ids && 'delete' === $action ) {
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$notice = 'deleted';
	} elseif ( $ids && 'mail' === $action ) {
		if ( ! sella_fc_settings()['mail'] ) {
			$notice = 'mail_off';
		} else {
			$ok = 0;
			foreach ( $ids as $id ) {
				$ok += sella_fc_send_log_mail( $id ) ? 1 : 0;
			}
			$notice = count( $ids ) === $ok ? 'mailed' : 'mail_failed';
		}
	} elseif ( $ids && 'webhook' === $action ) {
		$settings = sella_fc_settings();
		if ( ! $settings['webhook_on'] || '' === $settings['webhook'] ) {
			$notice = 'webhook_off';
		} else {
			$ok = 0;
			foreach ( $ids as $id ) {
				$ok += sella_fc_send_webhook( $id ) ? 1 : 0;
			}
			$notice = count( $ids ) === $ok ? 'webhooked' : 'webhook_failed';
		}
	}

	wp_safe_redirect( add_query_arg( 'notice', $notice, remove_query_arg( 'notice', $back ) ) );
	exit;
}
add_action( 'admin_init', 'sella_fc_handle_sent_actions' );

/**
 * Where a request came from, as the log shows it.
 *
 * @param object $row Log row.
 * @return string
 */
function sella_fc_source_label( $row ) {
	if ( 'elementor' === $row->source ) {
		return 'Elementor: ' . ( '' !== $row->form_name ? $row->form_name : 'טופס' );
	}
	return 'shortcode' === $row->source ? 'טופס בעמוד' : (string) $row->form_name;
}

/**
 * @param string $status Mail status.
 * @return string
 */
function sella_fc_mail_label( $status ) {
	$labels = array(
		'sent'   => 'נשלח',
		'failed' => 'נכשל',
		'off'    => 'כבוי',
	);
	return $labels[ $status ] ?? '—';
}

/**
 * @param object $row Log row.
 * @return string
 */
function sella_fc_webhook_label( $row ) {
	$labels = array(
		'ok'      => 'התקבל',
		'failed'  => 'נכשל',
		'pending' => 'ממתין',
		'off'     => '—',
	);
	$label = $labels[ $row->webhook_status ] ?? '—';
	return in_array( $row->webhook_status, array( 'ok', 'failed' ), true ) && $row->webhook_code ? $label . ' (' . $row->webhook_code . ')' : $label;
}

/**
 * Download the filtered log as a CSV file Excel opens in Hebrew.
 */
function sella_fc_export() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'sella_fc_export' );

	global $wpdb;
	$table = sella_fc_table();
	$where = sella_fc_sent_where( sella_fc_sent_filters() );
	$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="first-chapters-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv( $out, array( 'תאריך', 'שם', 'אימייל', 'טלפון', 'ספר', 'מוצר', 'מקור', 'עמוד', 'מייל', 'לחיצות על הקישור', 'Webhook', 'שדות נוספים' ) );
	foreach ( $rows as $row ) {
		$fields = $row->fields ? json_decode( $row->fields, true ) : array();
		$extra  = array();
		foreach ( (array) $fields as $label => $value ) {
			$extra[] = $label . ': ' . $value;
		}
		$cells = array(
			$row->created_at,
			$row->name,
			$row->email,
			$row->phone,
			sella_fc_book( (int) $row->chapter_id ),
			$row->product_id ? get_the_title( (int) $row->product_id ) : '',
			sella_fc_source_label( $row ),
			$row->page_url,
			sella_fc_mail_label( $row->mail_status ),
			(int) $row->clicks,
			sella_fc_webhook_label( $row ),
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
add_action( 'admin_post_sella_fc_export', 'sella_fc_export' );

/**
 * The log screen.
 */
function sella_fc_render_sent_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	global $wpdb;
	$table    = sella_fc_table();
	$filters  = sella_fc_sent_filters();
	$where    = sella_fc_sent_where( $filters );
	$base_url = admin_url( 'admin.php?page=' . SELLA_FC_SENT_SLUG );
	$chapters = sella_fc_chapter_choices();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$offset = ( $paged - 1 ) * SELLA_FC_PER_PAGE;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name and a WHERE built with prepare().
	$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", SELLA_FC_PER_PAGE, $offset ) );
	$clicks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where} AND clicks > 0" );
	// phpcs:enable

	$notice  = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notices = array(
		'deleted'        => array( 'success', 'נמחק.' ),
		'mailed'         => array( 'success', 'המייל נשלח שוב.' ),
		'mail_failed'    => array( 'error', 'המייל לא נשלח. כדאי לבדוק את הגדרות שליחת המיילים של האתר.' ),
		'mail_off'       => array( 'warning', 'שליחת המייל כבויה בהגדרות.' ),
		'webhooked'      => array( 'success', 'ה-webhook קיבל את הבקשה.' ),
		'webhook_failed' => array( 'error', 'ה-webhook לא קיבל את הבקשה. הפרטים בעמודה Webhook.' ),
		'webhook_off'    => array( 'warning', 'ה-webhook כבוי בהגדרות או שאין לו כתובת.' ),
	);
	$filtered_url = add_query_arg( array_filter( $filters ), $base_url );
	$export_url   = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'sella_fc_export' ), array_filter( $filters ) ), admin_url( 'admin-post.php' ) ), 'sella_fc_export' );
	$pages        = (int) ceil( $total / SELLA_FC_PER_PAGE );
	?>
	<div class="wrap sella-leads-admin sella-fc-admin" dir="rtl">
		<h1 class="wp-heading-inline">פרקים ראשונים שנשלחו</h1>
		<a class="page-title-action" href="<?php echo esc_url( $export_url ); ?>">ייצוא לאקסל (CSV)</a>
		<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SELLA_FC_SETTINGS_SLUG ) ); ?>">הגדרות</a>
		<hr class="wp-header-end" />

		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
		<?php endif; ?>

		<p class="sella-leads-lead">
			כל מי שביקש פרק ראשון, מהטופס בדף המוצר או מטופס Elementor מחובר. "לחיצות" סופרות כניסות לפרק מהקישור שבמייל
			(תוכנות סינון דואר בודקות לפעמים קישורים בעצמן, כך שזה סימן ולא מדידה מדויקת).
			<?php if ( $total ) : ?>
				<strong><?php echo (int) $clicks; ?> מתוך <?php echo (int) $total; ?></strong> פתחו את הקישור.
			<?php endif; ?>
		</p>

		<form method="get" class="sella-leads-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( SELLA_FC_SENT_SLUG ); ?>" />
			<select name="chapter">
				<option value="">כל הספרים</option>
				<?php foreach ( $chapters as $id => $title ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( $filters['chapter'], $id ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="שם, מייל, טלפון או טופס" />
			<button type="submit" class="button">חיפוש</button>
		</form>

		<form method="post" class="sella-leads-list">
			<?php wp_nonce_field( 'sella_fc_sent' ); ?>
			<input type="hidden" name="sella_fc_form" value="sent" />

			<div class="tablenav top">
				<select name="bulk_action">
					<option value="">פעולה על המסומנים</option>
					<option value="mail">שליחת המייל שוב</option>
					<option value="webhook">שליחה שוב ל-webhook</option>
					<option value="delete">מחיקה</option>
				</select>
				<button type="submit" class="button" onclick="return this.form.bulk_action.value !== 'delete' || confirm('למחוק את המסומנים? אי אפשר לבטל.');">ביצוע</button>
				<span class="displaying-num"><?php echo (int) $total; ?> רשומות</span>
			</div>

			<table class="widefat striped sella-leads-table sella-fc-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="for (const box of this.closest('table').querySelectorAll('tbody input[type=checkbox]')) box.checked = this.checked;" aria-label="סימון הכול" /></td>
						<th>תאריך</th>
						<th>שם</th>
						<th>אימייל</th>
						<th>טלפון</th>
						<th>ספר</th>
						<th>מקור</th>
						<th>מייל</th>
						<th>לחיצות</th>
						<th>Webhook</th>
						<th><span class="screen-reader-text">פעולות</span></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="11" class="sella-leads-empty"><?php echo $filters['q'] || $filters['chapter'] ? 'אין רשומות שמתאימות לסינון.' : 'עדיין לא נשלחו פרקים.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$fields  = $row->fields ? json_decode( $row->fields, true ) : array();
					$chapter = get_post( (int) $row->chapter_id );
					?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $row->id; ?>" aria-label="סימון" /></th>
						<td class="sella-leads-table__date"><?php echo esc_html( mysql2date( 'd.m.Y', $row->created_at ) ); ?><span><?php echo esc_html( mysql2date( 'H:i', $row->created_at ) ); ?></span></td>
						<td>
							<?php echo esc_html( $row->name ); ?>
							<?php if ( $fields ) : ?>
								<details>
									<summary>שדות נוספים</summary>
									<dl class="sella-leads-extra">
										<?php foreach ( (array) $fields as $label => $value ) : ?>
											<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd>
										<?php endforeach; ?>
									</dl>
								</details>
							<?php endif; ?>
						</td>
						<td><a href="mailto:<?php echo esc_attr( $row->email ); ?>" dir="ltr"><?php echo esc_html( $row->email ); ?></a></td>
						<td><?php if ( $row->phone ) : ?><a href="tel:<?php echo esc_attr( $row->phone ); ?>" dir="ltr"><?php echo esc_html( $row->phone ); ?></a><?php endif; ?></td>
						<td>
							<?php if ( $chapter ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $chapter->ID ) ); ?>"><?php echo esc_html( $chapter->post_title ); ?></a>
							<?php else : ?>
								<span class="sella-fc-muted">(הפרק נמחק)</span>
							<?php endif; ?>
						</td>
						<td class="sella-leads-table__source">
							<?php echo esc_html( sella_fc_source_label( $row ) ); ?>
							<?php if ( $row->product_id ) : ?><a href="<?php echo esc_url( (string) get_permalink( (int) $row->product_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( (int) $row->product_id ) ); ?></a><?php elseif ( $row->page_url ) : ?><a href="<?php echo esc_url( $row->page_url ); ?>" target="_blank" rel="noopener">עמוד</a><?php endif; ?>
						</td>
						<td><span class="sella-fc-badge sella-fc-badge--<?php echo esc_attr( $row->mail_status ? $row->mail_status : 'none' ); ?>"><?php echo esc_html( sella_fc_mail_label( $row->mail_status ) ); ?></span></td>
						<td title="<?php echo $row->clicked_at ? esc_attr( 'פעם ראשונה: ' . mysql2date( 'd.m.Y H:i', $row->clicked_at ) ) : ''; ?>"><?php echo (int) $row->clicks; ?></td>
						<td><span class="sella-fc-badge sella-fc-badge--<?php echo esc_attr( $row->webhook_status ? $row->webhook_status : 'none' ); ?>" title="<?php echo esc_attr( $row->webhook_error ); ?>"><?php echo esc_html( sella_fc_webhook_label( $row ) ); ?></span></td>
						<td class="sella-fc-actions">
							<button type="submit" name="row_action" value="mail:<?php echo (int) $row->id; ?>" class="button-link">שליחת המייל שוב</button>
							<?php if ( 'off' !== $row->webhook_status && '' !== $row->webhook_status ) : ?>
								<button type="submit" name="row_action" value="webhook:<?php echo (int) $row->id; ?>" class="button-link">ל-webhook שוב</button>
							<?php endif; ?>
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
	</div>
	<?php
}
