<?php
/**
 * ActiveTrail: every signup and inquiry the site records (sella-leads.php)
 * goes on to the right group in the ActiveTrail account.
 *
 * Each lead carries a channel: the contact form, the newsletter popup, the
 * popup before checkout, a back-in-stock request, an Elementor form (by its
 * ID) or a first-chapter request by the page it was sent from. The screen
 * (פניות ולידים → אקטיב טרייל) lists the channels and, for each, the group
 * the contact joins and the fields it is given (ext1…, with placeholders
 * such as {book} and {page}). A channel without its own row falls back to
 * its kind (all first-chapter requests) and then to the default route.
 *
 * The contact is sent at the end of the request that recorded the lead,
 * after the visitor has their answer. The answer is kept on the lead row
 * (the "אקטיב טרייל" column of the list), and a lead can be sent again from
 * there.
 *
 * API: https://webapi.mymarketing.co.il/api/docs — the token goes in the
 * Authorization header as is; POST groups/{id}/members adds (or updates) a
 * contact in a group, POST contacts without a group.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_AT_SETTINGS', 'sella_activetrail_settings' );
define( 'SELLA_AT_SLUG', 'sella-activetrail' );
define( 'SELLA_AT_API', 'https://webapi.mymarketing.co.il/api/' );
define( 'SELLA_AT_GROUPS_CACHE', 'sella_at_groups' );

/**
 * ---------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------------
 */

/**
 * @return array
 */
function sella_at_defaults() {
	return array(
		'token'    => '',
		'enabled'  => false,
		// ApiStatusTypes of the contact in the group.
		'status'   => 'Subscribe',
		// Where the mobile number goes: sms, phone1 or both.
		'phone_to' => 'both',
		// Fields for every contact, "ext1=value" per line; placeholders allowed.
		'fields'   => '',
		// The route of a lead whose channel and kind have none: {groups, fields}.
		'default'  => array(
			'groups' => array(),
			'fields' => '',
		),
		// By channel: {groups, fields, off}.
		'routes'   => array(),
	);
}

/**
 * @return array
 */
function sella_at_settings() {
	$saved = get_option( SELLA_AT_SETTINGS, array() );
	return array_merge( sella_at_defaults(), is_array( $saved ) ? $saved : array() );
}

/**
 * Whether contacts are sent at all.
 *
 * @return bool
 */
function sella_at_on() {
	$settings = sella_at_settings();
	return ! empty( $settings['enabled'] ) && '' !== $settings['token'];
}

/**
 * The contact fields a route may fill.
 *
 * @return string[]
 */
function sella_at_field_names() {
	$names = array( 'first_name', 'last_name', 'sms', 'phone1', 'phone2', 'city', 'street', 'zip_code' );
	for ( $i = 1; $i <= 25; $i++ ) {
		$names[] = 'ext' . $i;
	}
	for ( $i = 1; $i <= 5; $i++ ) {
		$names[] = 'num' . $i;
		$names[] = 'date' . $i;
	}
	return $names;
}

/**
 * "ext1=value" lines (or ; separated) into field => template.
 *
 * @param string $text As typed.
 * @return array<string, string>
 */
function sella_at_parse_fields( $text ) {
	$fields  = array();
	$allowed = sella_at_field_names();
	foreach ( preg_split( '/[\r\n;]+/', (string) $text ) as $line ) {
		if ( false === strpos( $line, '=' ) ) {
			continue;
		}
		list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
		$key                 = strtolower( $key );
		if ( in_array( $key, $allowed, true ) && '' !== $value ) {
			$fields[ $key ] = $value;
		}
	}
	return $fields;
}

/**
 * ---------------------------------------------------------------------------
 * Channels
 * ---------------------------------------------------------------------------
 */

/**
 * A page's path, as a channel names it: decoded, with a trailing slash and
 * without the query string.
 *
 * @param string $url URL.
 * @return string '' when there is none.
 */
function sella_at_path( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	if ( '' === $path ) {
		return '';
	}
	return trailingslashit( '/' . ltrim( rawurldecode( $path ), '/' ) );
}

/**
 * The channel of a lead that was given none: its kind, and for a first
 * chapter the page it was asked from.
 *
 * @param string $type     Lead type.
 * @param string $page_url Page.
 * @return string
 */
function sella_at_lead_channel( $type, $page_url ) {
	if ( 'first_chapter' === $type && '' !== sella_at_path( $page_url ) ) {
		return 'first_chapter:' . sella_at_path( $page_url );
	}
	return (string) $type;
}

/**
 * The channels the screen lists, in order: the fixed ones, the Elementor
 * forms, the first-chapter pages, then anything else that has reached the
 * list. Each: label, kind (a lead type), page (URL or ''), note.
 *
 * @return array<string, array{label: string, kind: string, page: string, note: string}>
 */
function sella_at_channels() {
	global $wpdb;

	$channels = array(
		'contact'        => array(
			'label' => 'טופס צור קשר',
			'kind'  => 'contact',
			'page'  => '',
			'note'  => 'הטופס [sella_contact_form] בדף צור קשר',
		),
		'newsletter'     => array(
			'label' => 'פופאפ ניוזלטר',
			'kind'  => 'newsletter',
			'page'  => '',
			'note'  => 'וכל טופס HTML אחר של ניוזלטר באתר',
		),
		'checkout_email' => array(
			'label' => 'מייל לפני תשלום',
			'kind'  => 'checkout_email',
			'page'  => '',
			'note'  => 'הפופאפ שמבקש מייל לפני המעבר לתשלום',
		),
		'back_in_stock'  => array(
			'label' => 'עדכון כשהספר חוזר למלאי',
			'kind'  => 'back_in_stock',
			'page'  => '',
			'note'  => 'הטופס בדף של ספר שאזל; {book} הוא שם הספר',
		),
	);

	$forms = function_exists( 'sella_fc_elementor_forms' ) ? sella_fc_elementor_forms() : array();
	$lists = function_exists( 'sella_leads_settings' ) ? sella_leads_settings()['forms'] : array();
	$types = function_exists( 'sella_leads_types' ) ? sella_leads_types() : array();

	// Elementor forms that are not connected to a first chapter.
	foreach ( $forms as $form ) {
		if ( function_exists( 'sella_fc_connection' ) && null !== sella_fc_connection( $form['id'] ) ) {
			continue;
		}
		$list = $lists[ $form['id'] ] ?? 'auto';
		if ( 'skip' === $list ) {
			continue;
		}
		$channels[ 'form:' . $form['id'] ] = array(
			'label' => 'טופס Elementor: ' . ( '' !== $form['name'] ? $form['name'] : 'ללא שם' ),
			'kind'  => isset( $types[ $list ] ) ? $list : 'form',
			'page'  => (string) get_permalink( (int) $form['post_id'] ),
			'note'  => '' !== $form['post_title'] ? $form['post_title'] : '',
		);
	}

	// First chapter: the product pages with a chapter, and the pages with a connected form.
	if ( function_exists( 'sella_fc_settings' ) ) {
		$fc = sella_fc_settings();
		foreach ( (array) $fc['products'] as $row ) {
			$product = ! empty( $row['product'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row['product'] ) : null;
			if ( ! $product ) {
				continue;
			}
			$url = $product->get_permalink();
			$channels[ 'first_chapter:' . sella_at_path( $url ) ] = array(
				'label' => 'פרק ראשון: ' . wp_strip_all_tags( $product->get_name() ),
				'kind'  => 'first_chapter',
				'page'  => $url,
				'note'  => 'דף הספר',
			);
		}
		foreach ( $forms as $form ) {
			$connection = function_exists( 'sella_fc_connection' ) ? sella_fc_connection( $form['id'] ) : null;
			if ( ! $connection ) {
				continue;
			}
			$url = (string) get_permalink( (int) $form['post_id'] );
			$key = 'first_chapter:' . sella_at_path( $url );
			if ( '' === sella_at_path( $url ) || isset( $channels[ $key ] ) ) {
				continue;
			}
			$channels[ $key ] = array(
				'label' => 'פרק ראשון: ' . ( '' !== $form['name'] ? $form['name'] : $connection['label'] ),
				'kind'  => 'first_chapter',
				'page'  => $url,
				'note'  => '' !== $form['post_title'] ? $form['post_title'] : 'טופס Elementor',
			);
		}
	}

	// Channels that reached the list and are not above: pages that are gone, forms that were deleted.
	if ( function_exists( 'sella_leads_table' ) && get_option( 'sella_leads_db_version' ) ) {
		$table = sella_leads_table();
		$seen  = $wpdb->get_results( "SELECT channel, type, MAX(page_url) AS page_url, COUNT(*) AS total FROM {$table} WHERE channel <> '' GROUP BY channel, type ORDER BY total DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $seen as $row ) {
			if ( isset( $channels[ $row->channel ] ) || isset( $types[ $row->channel ] ) ) {
				continue;
			}
			$kind = isset( $types[ $row->type ] ) ? $row->type : 'form';
			if ( 0 === strpos( $row->channel, 'first_chapter:' ) ) {
				$label = 'פרק ראשון: ' . substr( $row->channel, strlen( 'first_chapter:' ) );
			} elseif ( 0 === strpos( $row->channel, 'form:' ) ) {
				$label = 'טופס Elementor שכבר לא באתר';
			} else {
				$label = $row->channel;
			}
			$channels[ $row->channel ] = array(
				'label' => $label,
				'kind'  => $kind,
				'page'  => (string) $row->page_url,
				'note'  => (int) $row->total . ' רשומות',
			);
		}
	}

	$channels['first_chapter'] = array(
		'label' => 'פרק ראשון: כל שאר הבקשות',
		'kind'  => 'first_chapter',
		'page'  => '',
		'note'  => 'בקשה מדף שאין לו שורה משלו למעלה',
	);

	return $channels;
}

/**
 * The route of a lead: its channel's, else its kind's, else the default.
 *
 * @param object $row Lead row (channel, type).
 * @return array{groups: int[], fields: string, via: string}|null Null when it is not to be sent.
 */
function sella_at_route( $row ) {
	$settings = sella_at_settings();
	$routes   = (array) $settings['routes'];
	$channel  = (string) ( $row->channel ?? '' );
	$kind     = (string) ( $row->type ?? '' );

	foreach ( array_unique( array_filter( array( $channel, $kind ) ) ) as $key ) {
		if ( ! isset( $routes[ $key ] ) ) {
			continue;
		}
		if ( ! empty( $routes[ $key ]['off'] ) ) {
			return null;
		}
		if ( ! empty( $routes[ $key ]['groups'] ) || '' !== (string) $routes[ $key ]['fields'] ) {
			return array(
				'groups' => array_map( 'intval', (array) $routes[ $key ]['groups'] ),
				'fields' => (string) $routes[ $key ]['fields'],
				'via'    => $key,
			);
		}
	}

	if ( empty( $settings['default']['groups'] ) ) {
		return null;
	}
	return array(
		'groups' => array_map( 'intval', (array) $settings['default']['groups'] ),
		'fields' => (string) ( $settings['default']['fields'] ?? '' ),
		'via'    => 'default',
	);
}

/**
 * ---------------------------------------------------------------------------
 * The contact
 * ---------------------------------------------------------------------------
 */

/**
 * The placeholders a field template may use, for a lead.
 *
 * @param object $row Lead row.
 * @return array<string, string>
 */
function sella_at_placeholders( $row ) {
	$name   = trim( (string) ( $row->name ?? '' ) );
	$parts  = preg_split( '/\s+/', $name, 2 );
	$fields = ! empty( $row->fields ) ? json_decode( (string) $row->fields, true ) : array();
	$types  = function_exists( 'sella_leads_types' ) ? sella_leads_types() : array();
	$kind   = (string) ( $row->type ?? '' );
	$source = (string) ( $row->source ?? '' );
	$page   = (string) ( $row->page_url ?? '' );
	$title  = '';

	if ( '' !== $page ) {
		$post_id = url_to_postid( $page );
		$title   = $post_id ? get_the_title( $post_id ) : '';
	}

	return array(
		'{name}'       => $name,
		'{first_name}' => $parts[0] ?? '',
		'{last_name}'  => $parts[1] ?? '',
		'{email}'      => (string) ( $row->email ?? '' ),
		'{phone}'      => (string) ( $row->phone ?? '' ),
		'{source}'     => $source,
		'{book}'       => in_array( $kind, array( 'first_chapter', 'back_in_stock' ), true ) ? $source : '',
		'{form}'       => is_array( $fields ) && isset( $fields['טופס'] ) ? (string) $fields['טופס'] : ( 'form' === substr( (string) ( $row->channel ?? '' ), 0, 4 ) ? $source : '' ),
		'{page}'       => '' !== $title ? $title : sella_at_path( $page ),
		'{page_url}'   => $page,
		'{type}'       => $types[ $kind ] ?? $kind,
		'{channel}'    => (string) ( $row->channel ?? '' ),
		'{message}'    => (string) ( $row->message ?? '' ),
		'{order}'      => (string) ( $row->order_ref ?? '' ),
		'{date}'       => ! empty( $row->created_at ) ? mysql2date( 'Y-m-d', $row->created_at ) : wp_date( 'Y-m-d' ),
	);
}

/**
 * The body ActiveTrail gets for a lead.
 *
 * @param object $row   Lead row.
 * @param array  $route Route.
 * @return array
 */
function sella_at_contact( $row, $route ) {
	$settings = sella_at_settings();
	$values   = sella_at_placeholders( $row );
	$contact  = array( 'email' => $values['{email}'] );

	if ( '' !== $values['{first_name}'] ) {
		$contact['first_name'] = $values['{first_name}'];
	}
	if ( '' !== $values['{last_name}'] ) {
		$contact['last_name'] = $values['{last_name}'];
	}

	$phone = preg_replace( '/[^\d+]/', '', $values['{phone}'] );
	if ( '' !== $phone ) {
		if ( in_array( $settings['phone_to'], array( 'sms', 'both' ), true ) ) {
			$contact['sms'] = $phone;
		}
		if ( in_array( $settings['phone_to'], array( 'phone1', 'both' ), true ) ) {
			$contact['phone1'] = $phone;
		}
	}

	$templates = array_merge( sella_at_parse_fields( $settings['fields'] ), sella_at_parse_fields( $route['fields'] ) );
	foreach ( $templates as $field => $template ) {
		$value = trim( strtr( $template, $values ) );
		if ( '' === $value ) {
			continue;
		}
		$contact[ $field ] = 0 === strpos( $field, 'num' ) ? (int) $value : mb_substr( $value, 0, 250 );
	}

	if ( '' !== $settings['status'] ) {
		$contact['status'] = $settings['status'];
		if ( isset( $contact['sms'] ) ) {
			$contact['sms_status'] = $settings['status'];
		}
	}

	return $contact;
}

/**
 * ---------------------------------------------------------------------------
 * API
 * ---------------------------------------------------------------------------
 */

/**
 * One call.
 *
 * @param string     $method GET or POST.
 * @param string     $path   After /api/.
 * @param array|null $body   JSON body.
 * @param string     $token  Token; the saved one when empty.
 * @return array{code: int, data: mixed, error: string}
 */
function sella_at_request( $method, $path, $body = null, $token = '' ) {
	$token = '' !== $token ? $token : sella_at_settings()['token'];
	if ( '' === $token ) {
		return array(
			'code'  => 0,
			'data'  => null,
			'error' => 'אין טוקן',
		);
	}

	$args = array(
		'method'      => $method,
		'timeout'     => 12,
		'redirection' => 2,
		'headers'     => array(
			'Authorization' => $token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json; charset=utf-8',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	$response = wp_remote_request( SELLA_AT_API . ltrim( $path, '/' ), $args );
	if ( is_wp_error( $response ) ) {
		return array(
			'code'  => 0,
			'data'  => null,
			'error' => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	$error = '';
	if ( $code < 200 || $code >= 300 ) {
		if ( is_array( $data ) ) {
			$error = (string) ( $data['message'] ?? $data['Message'] ?? $data['error'] ?? $data['ExceptionMessage'] ?? '' );
			// "Message" is itself JSON ({title, status, detail}) for the API's own errors.
			$inner = '' !== $error && '{' === $error[0] ? json_decode( $error, true ) : null;
			if ( is_array( $inner ) && ! empty( $inner['detail'] ) ) {
				$error = ( ! empty( $inner['title'] ) ? $inner['title'] . ': ' : '' ) . $inner['detail'];
			}
			if ( '' === $error && isset( $data['ModelState'] ) && is_array( $data['ModelState'] ) ) {
				$error = implode( ' ', array_map( 'implode', array_fill( 0, count( $data['ModelState'] ), ' ' ), array_values( $data['ModelState'] ) ) );
			}
		}
		if ( '' === $error ) {
			$error = '' !== $raw ? wp_strip_all_tags( $raw ) : 'תשובה ' . $code;
		}
		$error = mb_substr( trim( $error ), 0, 200 );
		if ( 401 === $code ) {
			$error = 'הטוקן לא התקבל (401)';
		}
	}

	return array(
		'code'  => $code,
		'data'  => $data,
		'error' => $error,
	);
}

/**
 * The account's groups, id => name. Cached for an hour.
 *
 * @param bool   $refresh Fetch again.
 * @param string $token   A token to try instead of the saved one.
 * @return array<int, string>|WP_Error
 */
function sella_at_groups( $refresh = false, $token = '' ) {
	if ( ! $refresh && '' === $token ) {
		$cached = get_transient( SELLA_AT_GROUPS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$groups = array();
	for ( $page = 1; $page <= 10; $page++ ) {
		$result = sella_at_request( 'GET', 'groups?Page=' . $page . '&Limit=200', null, $token );
		if ( '' !== $result['error'] ) {
			return new WP_Error( 'sella_at', $result['error'] );
		}
		if ( ! is_array( $result['data'] ) || ! $result['data'] ) {
			break;
		}
		foreach ( $result['data'] as $group ) {
			if ( isset( $group['id'], $group['name'] ) ) {
				$groups[ (int) $group['id'] ] = html_entity_decode( (string) $group['name'], ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( count( $result['data'] ) < 200 ) {
			break;
		}
	}

	natcasesort( $groups );
	if ( '' === $token ) {
		set_transient( SELLA_AT_GROUPS_CACHE, $groups, HOUR_IN_SECONDS );
	}
	return $groups;
}

/**
 * Send a lead to ActiveTrail and record the answer on its row.
 *
 * @param int|object $lead  Row ID or row.
 * @param bool       $force Send even when sending is off (a button on the screen).
 * @return array{ok: bool, note: string}
 */
function sella_at_send_lead( $lead, $force = false ) {
	global $wpdb;

	$row = is_object( $lead ) ? $lead : ( function_exists( 'sella_leads_row' ) ? sella_leads_row( (int) $lead ) : null );
	if ( ! $row ) {
		return array(
			'ok'   => false,
			'note' => 'error:אין רשומה',
		);
	}

	$save = function ( $note ) use ( $row, $wpdb ) {
		if ( ! empty( $row->id ) && function_exists( 'sella_leads_table' ) ) {
			$wpdb->update( sella_leads_table(), array( 'activetrail' => mb_substr( $note, 0, 255 ) ), array( 'id' => (int) $row->id ) );
		}
		return array(
			'ok'   => 0 === strpos( $note, 'ok' ),
			'note' => $note,
		);
	};

	if ( ! $force && ! sella_at_on() ) {
		return $save( 'off' );
	}
	if ( '' === sella_at_settings()['token'] ) {
		return $save( 'error:אין טוקן' );
	}
	if ( ! is_email( (string) ( $row->email ?? '' ) ) ) {
		return $save( 'skip:אין מייל' );
	}

	$route = sella_at_route( $row );
	if ( ! $route ) {
		return $save( 'skip:אין ניתוב' );
	}

	$contact = sella_at_contact( $row, $route );
	$sent    = array();

	if ( ! $route['groups'] ) {
		$result = sella_at_request( 'POST', 'contacts', $contact );
		if ( '' !== $result['error'] ) {
			return $save( 'error:' . $result['error'] );
		}
		return $save( 'ok:ללא קבוצה' );
	}

	foreach ( $route['groups'] as $group_id ) {
		$result = sella_at_request( 'POST', 'groups/' . (int) $group_id . '/members', $contact );
		if ( '' !== $result['error'] ) {
			return $save( 'error:' . ( $sent ? 'נכנס ל-' . implode( ',', $sent ) . '; ' : '' ) . 'קבוצה ' . (int) $group_id . ': ' . $result['error'] );
		}
		$sent[] = (int) $group_id;
	}

	return $save( 'ok:' . implode( ',', $sent ) );
}

/**
 * Leads waiting to be sent until the end of the request.
 *
 * @param int $lead_id Row to add; 0 only reads the list.
 * @return int[]
 */
function sella_at_queue( $lead_id = 0 ) {
	static $ids = array();
	if ( $lead_id ) {
		if ( ! $ids ) {
			add_action( 'shutdown', 'sella_at_flush', 110 );
		}
		$ids[] = (int) $lead_id;
	}
	return $ids;
}

/**
 * A new lead: queue it, and mark it so the list shows it is on its way.
 *
 * @param int   $lead_id Row.
 * @param array $row     Values as inserted.
 */
function sella_at_on_lead( $lead_id, $row ) {
	global $wpdb;
	if ( ! sella_at_on() ) {
		return;
	}
	if ( '' === (string) ( $row['email'] ?? '' ) ) {
		return;
	}
	$wpdb->update( sella_leads_table(), array( 'activetrail' => 'pending' ), array( 'id' => (int) $lead_id ) );
	sella_at_queue( $lead_id );
}
add_action( 'sella_leads_added', 'sella_at_on_lead', 10, 2 );

/**
 * At the end of the request: the visitor has the answer; now the contacts.
 */
function sella_at_flush() {
	$ids = sella_at_queue();
	if ( ! $ids ) {
		return;
	}
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	foreach ( $ids as $id ) {
		sella_at_send_lead( $id );
	}
}

/**
 * The cell in the leads list.
 *
 * @param object $row Lead row.
 * @return string HTML.
 */
function sella_at_status_cell( $row ) {
	$note = (string) ( $row->activetrail ?? '' );
	if ( '' === $note ) {
		return '<span class="sella-at-mark sella-at-mark--none" title="לא נשלח">—</span>';
	}

	list( $state, $detail ) = array_pad( explode( ':', $note, 2 ), 2, '' );
	$groups = array();
	if ( 'ok' === $state && preg_match( '/^[\d,]+$/', $detail ) ) {
		$names = get_transient( SELLA_AT_GROUPS_CACHE );
		foreach ( explode( ',', $detail ) as $id ) {
			$groups[] = is_array( $names ) && isset( $names[ (int) $id ] ) ? $names[ (int) $id ] : '#' . $id;
		}
		$detail = implode( ', ', $groups );
	}

	$labels = array(
		'ok'      => 'נשלח',
		'error'   => 'שגיאה',
		'skip'    => 'לא נותב',
		'off'     => 'כבוי',
		'pending' => 'בדרך',
	);
	$label  = $labels[ $state ] ?? $state;

	return '<span class="sella-at-mark sella-at-mark--' . esc_attr( $state ) . '" title="' . esc_attr( $detail ) . '">' . esc_html( $label ) . '</span>'
		. ( '' !== $detail ? '<span class="sella-at-detail">' . esc_html( mb_substr( $detail, 0, 60 ) ) . '</span>' : '' );
}

/**
 * ---------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------------
 */

/**
 * Under פניות ולידים, after the first-chapter items.
 */
function sella_at_register_admin_page() {
	if ( ! defined( 'SELLA_LEADS_SLUG' ) ) {
		return;
	}
	add_submenu_page( SELLA_LEADS_SLUG, 'אקטיב טרייל', 'אקטיב טרייל', 'manage_woocommerce', SELLA_AT_SLUG, 'sella_at_render_admin_page' );
}
add_action( 'admin_menu', 'sella_at_register_admin_page', 30 );

/**
 * @param string $hook Admin hook.
 */
function sella_at_admin_assets( $hook ) {
	if ( false === strpos( $hook, SELLA_AT_SLUG ) ) {
		return;
	}
	$file = get_stylesheet_directory() . '/assets/css/sella-leads-admin.css';
	wp_enqueue_style( 'sella-leads-admin', get_stylesheet_directory_uri() . '/assets/css/sella-leads-admin.css', array(), (string) filemtime( $file ) );
}
add_action( 'admin_enqueue_scripts', 'sella_at_admin_assets' );

/**
 * A posted route: the group select ('' inherit, 'off', or an ID), more IDs
 * and the fields.
 *
 * @param array $posted Unslashed.
 * @return array{groups: int[], fields: string, off: bool}
 */
function sella_at_sanitize_route( $posted ) {
	$choice = isset( $posted['group'] ) ? sanitize_text_field( $posted['group'] ) : '';
	$groups = array();
	if ( ctype_digit( $choice ) && (int) $choice > 0 ) {
		$groups[] = (int) $choice;
	}
	foreach ( preg_split( '/[\s,]+/', isset( $posted['more'] ) ? (string) $posted['more'] : '' ) as $more ) {
		if ( ctype_digit( $more ) && (int) $more > 0 ) {
			$groups[] = (int) $more;
		}
	}
	return array(
		'groups' => array_values( array_unique( $groups ) ),
		'fields' => isset( $posted['fields'] ) ? trim( sanitize_textarea_field( $posted['fields'] ) ) : '',
		'off'    => 'off' === $choice,
	);
}

/**
 * Save the screen, refresh the groups, or send a test contact.
 */
function sella_at_handle_admin_actions() {
	if ( ! is_admin() || empty( $_POST['sella_at_form'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'sella_at_manage' );

	$form   = sanitize_key( wp_unslash( $_POST['sella_at_form'] ) );
	$back   = admin_url( 'admin.php?page=' . SELLA_AT_SLUG );
	$notice = '';

	if ( 'settings' === $form ) {
		$posted   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field below.
		$current  = sella_at_settings();
		$token    = isset( $posted['token'] ) ? trim( sanitize_text_field( $posted['token'] ) ) : '';
		$status   = isset( $posted['status'] ) ? trim( sanitize_text_field( $posted['status'] ) ) : 'Subscribe';
		$phone_to = isset( $posted['phone_to'] ) && in_array( $posted['phone_to'], array( 'sms', 'phone1', 'both' ), true ) ? $posted['phone_to'] : 'both';

		$routes = array();
		foreach ( isset( $posted['routes'] ) && is_array( $posted['routes'] ) ? $posted['routes'] : array() as $entry ) {
			$channel = isset( $entry['channel'] ) ? trim( sanitize_text_field( $entry['channel'] ) ) : '';
			if ( '' === $channel ) {
				continue;
			}
			$route = sella_at_sanitize_route( $entry );
			if ( $route['off'] || $route['groups'] || '' !== $route['fields'] ) {
				$routes[ $channel ] = $route;
			}
		}

		$default = sella_at_sanitize_route( isset( $posted['default'] ) && is_array( $posted['default'] ) ? $posted['default'] : array() );

		update_option(
			SELLA_AT_SETTINGS,
			array(
				'token'    => $token,
				'enabled'  => ! empty( $posted['enabled'] ),
				'status'   => '' !== $status ? $status : 'Subscribe',
				'phone_to' => $phone_to,
				'fields'   => isset( $posted['fields'] ) ? trim( sanitize_textarea_field( $posted['fields'] ) ) : '',
				'default'  => array(
					'groups' => $default['groups'],
					'fields' => $default['fields'],
				),
				'routes'   => $routes,
			),
			false
		);
		if ( $token !== $current['token'] ) {
			delete_transient( SELLA_AT_GROUPS_CACHE );
		}
		$notice = 'saved';
	} elseif ( 'refresh' === $form ) {
		delete_transient( SELLA_AT_GROUPS_CACHE );
		$notice = 'refreshed';
	} elseif ( 'test' === $form ) {
		$email   = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$channel = isset( $_POST['test_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['test_channel'] ) ) : '';
		$kinds   = function_exists( 'sella_leads_types' ) ? sella_leads_types() : array();
		if ( ! is_email( $email ) ) {
			$notice = 'test_email';
		} else {
			$channels = sella_at_channels();
			$kind     = isset( $channels[ $channel ] ) ? $channels[ $channel ]['kind'] : ( isset( $kinds[ $channel ] ) ? $channel : 'form' );
			$row      = (object) array(
				'id'         => 0,
				'type'       => $kind,
				'channel'    => $channel,
				'name'       => 'בדיקה מהמסך',
				'email'      => $email,
				'phone'      => '',
				'order_ref'  => '',
				'message'    => '',
				'source'     => isset( $channels[ $channel ] ) ? $channels[ $channel ]['label'] : 'בדיקה',
				'page_url'   => isset( $channels[ $channel ] ) ? $channels[ $channel ]['page'] : '',
				'fields'     => null,
				'created_at' => current_time( 'mysql' ),
			);
			$result   = sella_at_send_lead( $row, true );
			set_transient( 'sella_at_test_' . get_current_user_id(), $result['note'], 5 * MINUTE_IN_SECONDS );
			$notice = 'tested';
		}
	}

	wp_safe_redirect( add_query_arg( 'notice', $notice, $back ) );
	exit;
}
add_action( 'admin_init', 'sella_at_handle_admin_actions' );

/**
 * The group select of a route.
 *
 * @param string              $name   Input name.
 * @param array               $route  Saved route, or empty.
 * @param array<int, string>  $groups Account groups.
 * @param string              $empty  Label of the empty choice.
 */
function sella_at_group_select( $name, $route, $groups, $empty ) {
	$chosen = ! empty( $route['off'] ) ? 'off' : ( ! empty( $route['groups'] ) ? (string) $route['groups'][0] : '' );
	?>
	<select name="<?php echo esc_attr( $name ); ?>[group]" class="sella-at-group">
		<option value=""><?php echo esc_html( $empty ); ?></option>
		<?php if ( 'default' !== $name ) : ?>
			<option value="off" <?php selected( $chosen, 'off' ); ?>>לא לשלוח</option>
		<?php endif; ?>
		<?php foreach ( $groups as $id => $label ) : ?>
			<option value="<?php echo (int) $id; ?>" <?php selected( $chosen, (string) $id ); ?>><?php echo esc_html( $label . ' (' . $id . ')' ); ?></option>
		<?php endforeach; ?>
		<?php if ( ctype_digit( $chosen ) && ! isset( $groups[ (int) $chosen ] ) ) : ?>
			<option value="<?php echo (int) $chosen; ?>" selected>קבוצה <?php echo (int) $chosen; ?></option>
		<?php endif; ?>
	</select>
	<?php
}

/**
 * The screen.
 */
function sella_at_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'אין הרשאה.' );
	}

	$settings = sella_at_settings();
	$channels = sella_at_channels();
	$types    = function_exists( 'sella_leads_types' ) ? sella_leads_types() : array();
	$groups   = '' !== $settings['token'] ? sella_at_groups() : array();
	$error    = is_wp_error( $groups ) ? $groups->get_error_message() : '';
	$groups   = is_wp_error( $groups ) ? array() : $groups;
	$leads    = admin_url( 'admin.php?page=' . ( defined( 'SELLA_LEADS_SLUG' ) ? SELLA_LEADS_SLUG : '' ) );

	$notice  = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notices = array(
		'saved'      => 'ההגדרות נשמרו.',
		'refreshed'  => 'רשימת הקבוצות נטענה מחדש.',
		'test_email' => 'כתובת המייל לבדיקה לא תקינה.',
	);
	$test    = 'tested' === $notice ? (string) get_transient( 'sella_at_test_' . get_current_user_id() ) : '';
	if ( 'tested' === $notice ) {
		delete_transient( 'sella_at_test_' . get_current_user_id() );
	}

	// Rows grouped by kind, the fixed four first.
	$order = array( 'contact', 'newsletter', 'checkout_email', 'back_in_stock', 'form', 'first_chapter' );
	?>
	<div class="wrap sella-leads-admin sella-at-admin" dir="rtl">
		<h1 class="wp-heading-inline">אקטיב טרייל</h1>
		<a class="page-title-action" href="<?php echo esc_url( $leads ); ?>">לרשימת הפניות והלידים</a>
		<hr class="wp-header-end" />

		<?php if ( isset( $notices[ $notice ] ) ) : ?>
			<div class="notice <?php echo 'test_email' === $notice ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
		<?php endif; ?>
		<?php if ( '' !== $test ) : ?>
			<div class="notice <?php echo 0 === strpos( $test, 'ok' ) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p>
				<?php echo 0 === strpos( $test, 'ok' ) ? 'הבדיקה נשלחה: ' : 'הבדיקה נכשלה: '; ?><code><?php echo esc_html( $test ); ?></code>
			</p></div>
		<?php endif; ?>
		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error"><p>לא הצלחנו לקרוא את הקבוצות מאקטיב טרייל: <?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<p class="sella-leads-lead">
			כל הרשמה ופנייה שנרשמת ב"פניות ולידים" נשלחת גם לאקטיב טרייל, לקבוצה שבוחרים כאן לפי הטופס שממנו הגיעה.
			בקשה לפרק ראשון מנותבת לפי הדף שממנו נשלחה. ערוץ בלי בחירה משלו הולך לשורה של הסוג שלו ("פרק ראשון: כל שאר הבקשות"), ואם גם שם אין, לברירת המחדל.
			התוצאה של כל שליחה מופיעה בעמודת "אקטיב טרייל" ברשימה, ומשם אפשר גם לשלוח שוב.
		</p>

		<form method="post" class="sella-leads-settings sella-at-settings">
			<?php wp_nonce_field( 'sella_at_manage' ); ?>
			<input type="hidden" name="sella_at_form" value="settings" />

			<h2>חיבור</h2>
			<p>
				<label for="sella_at_token">טוקן API</label><br />
				<input type="text" id="sella_at_token" name="token" value="<?php echo esc_attr( $settings['token'] ); ?>" class="large-text code" dir="ltr" autocomplete="off" spellcheck="false" />
				<span class="description">מאקטיב טרייל: הגדרות → API. אחרי שמירה הקבוצות של החשבון נטענות לרשימות שלמטה.</span>
			</p>
			<p>
				<label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> /> לשלוח כל הרשמה ופנייה חדשה לאקטיב טרייל</label>
				<?php if ( $groups ) : ?>
					<span class="sella-at-ok">מחובר: <?php echo count( $groups ); ?> קבוצות בחשבון</span>
				<?php endif; ?>
			</p>
			<p class="sella-at-inline">
				<label for="sella_at_status">סטטוס איש הקשר</label>
				<select id="sella_at_status" name="status">
					<?php foreach ( array( 'Subscribe' => 'רשום (Subscribe)', 'None' => 'כפי שאקטיב טרייל קובע (None)' ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					<?php if ( ! in_array( $settings['status'], array( 'Subscribe', 'None' ), true ) ) : ?>
						<option value="<?php echo esc_attr( $settings['status'] ); ?>" selected><?php echo esc_html( $settings['status'] ); ?></option>
					<?php endif; ?>
				</select>
				<label for="sella_at_phone_to">מספר הנייד נכנס ל</label>
				<select id="sella_at_phone_to" name="phone_to">
					<option value="both" <?php selected( $settings['phone_to'], 'both' ); ?>>SMS וגם טלפון</option>
					<option value="sms" <?php selected( $settings['phone_to'], 'sms' ); ?>>SMS בלבד</option>
					<option value="phone1" <?php selected( $settings['phone_to'], 'phone1' ); ?>>טלפון בלבד</option>
				</select>
			</p>

			<h2>ניתוב</h2>
			<p class="description">
				לכל ערוץ: הקבוצה שאיש הקשר נכנס אליה, קבוצות נוספות (מזהים מופרדים בפסיק), ושדות שממולאים ברשומה שלו.
				שדות כותבים שורה לכל שדה, למשל <code>ext1={book}</code> או <code>ext2=פרק ראשון</code>. השם, המייל והנייד נשלחים תמיד.
			</p>
			<p class="description sella-at-placeholders">
				ערכים שאפשר להכניס לשדות:
				<code>{name}</code> שם מלא · <code>{first_name}</code> · <code>{last_name}</code> · <code>{email}</code> · <code>{phone}</code> ·
				<code>{book}</code> שם הספר (פרק ראשון, חזרה למלאי) · <code>{form}</code> שם הטופס · <code>{page}</code> שם הדף · <code>{page_url}</code> כתובת הדף ·
				<code>{source}</code> המקור כמו ברשימה · <code>{type}</code> הסוג · <code>{date}</code> תאריך · <code>{message}</code> ההודעה · <code>{order}</code> מספר הזמנה
			</p>

			<table class="widefat striped sella-leads-forms sella-at-routes">
				<thead>
					<tr>
						<th>ערוץ</th>
						<th>דף</th>
						<th>קבוצה</th>
						<th>קבוצות נוספות</th>
						<th>שדות</th>
					</tr>
				</thead>
				<tbody>
					<tr class="sella-at-default">
						<td><strong>ברירת מחדל</strong><span class="sella-leads-forms__note">כל הרשמה שאין לה ניתוב משלה</span></td>
						<td></td>
						<td><?php sella_at_group_select( 'default', $settings['default'], $groups, 'לא לשלוח' ); ?></td>
						<td><input type="text" name="default[more]" value="<?php echo esc_attr( implode( ', ', array_slice( (array) ( $settings['default']['groups'] ?? array() ), 1 ) ) ); ?>" class="sella-at-more" dir="ltr" /></td>
						<td><textarea name="default[fields]" rows="1" class="sella-at-fields" dir="ltr"><?php echo esc_textarea( (string) ( $settings['default']['fields'] ?? '' ) ); ?></textarea></td>
					</tr>
					<?php foreach ( $order as $kind ) : ?>
						<?php
						$rows = array_filter(
							$channels,
							function ( $channel ) use ( $kind, $order ) {
								return $channel['kind'] === $kind || ( 'form' === $kind && ! in_array( $channel['kind'], $order, true ) );
							}
						);
						if ( ! $rows ) {
							continue;
						}
						?>
						<tr class="sella-at-kind"><th colspan="5"><?php echo esc_html( 'form' === $kind ? 'טפסי Elementor' : ( $types[ $kind ] ?? $kind ) ); ?></th></tr>
						<?php foreach ( $rows as $key => $channel ) : ?>
							<?php
							$id    = 'r' . md5( $key );
							$route = $settings['routes'][ $key ] ?? array();
							$more  = implode( ', ', array_slice( (array) ( $route['groups'] ?? array() ), 1 ) );
							?>
							<tr>
								<td>
									<input type="hidden" name="routes[<?php echo esc_attr( $id ); ?>][channel]" value="<?php echo esc_attr( $key ); ?>" />
									<?php echo esc_html( $channel['label'] ); ?>
									<?php if ( '' !== $channel['note'] ) : ?><span class="sella-leads-forms__note"><?php echo esc_html( $channel['note'] ); ?></span><?php endif; ?>
								</td>
								<td class="sella-leads-forms__fields">
									<?php if ( '' !== $channel['page'] ) : ?>
										<a href="<?php echo esc_url( $channel['page'] ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( sella_at_path( $channel['page'] ) ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php sella_at_group_select( 'routes[' . $id . ']', $route, $groups, in_array( $key, array_keys( $types ), true ) ? 'לפי ברירת המחדל' : 'לפי הסוג / ברירת המחדל' ); ?></td>
								<td><input type="text" name="routes[<?php echo esc_attr( $id ); ?>][more]" value="<?php echo esc_attr( $more ); ?>" class="sella-at-more" dir="ltr" placeholder="123, 456" /></td>
								<td><textarea name="routes[<?php echo esc_attr( $id ); ?>][fields]" rows="1" class="sella-at-fields" dir="ltr" placeholder="ext1={book}"><?php echo esc_textarea( (string) ( $route['fields'] ?? '' ) ); ?></textarea></td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3>שדות לכל איש קשר</h3>
			<p class="description">נוספים לכל שליחה, לפני השדות של הערוץ (הערוץ גובר כשיש אותו שדה בשניהם). למשל <code>ext3={type}</code> ו-<code>ext4={page}</code>.</p>
			<textarea name="fields" rows="3" class="large-text code sella-at-fields" dir="ltr"><?php echo esc_textarea( $settings['fields'] ); ?></textarea>

			<?php submit_button( 'שמירה', 'primary', 'submit', false ); ?>
		</form>

		<div class="sella-at-side">
			<form method="post" class="sella-leads-settings">
				<?php wp_nonce_field( 'sella_at_manage' ); ?>
				<input type="hidden" name="sella_at_form" value="test" />
				<h2>שליחת בדיקה</h2>
				<p class="description">שולח איש קשר בשם "בדיקה מהמסך" לקבוצה של הערוץ שבוחרים, לפי מה ששמור. כך רואים שהטוקן, הקבוצה והשדות נכונים.</p>
				<p class="sella-at-inline">
					<input type="email" name="test_email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" dir="ltr" placeholder="מייל לבדיקה" />
					<select name="test_channel">
						<?php foreach ( $channels as $key => $channel ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $channel['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button">שליחה</button>
				</p>
			</form>

			<form method="post" class="sella-leads-settings">
				<?php wp_nonce_field( 'sella_at_manage' ); ?>
				<input type="hidden" name="sella_at_form" value="refresh" />
				<h2>הקבוצות</h2>
				<p class="description">רשימת הקבוצות נשמרת לשעה. אחרי שפותחים קבוצה חדשה באקטיב טרייל, טוענים מחדש.</p>
				<button type="submit" class="button">טעינת הקבוצות מחדש</button>
			</form>
		</div>
	</div>
	<?php
}
