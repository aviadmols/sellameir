<?php
/**
 * ActiveTrail: every signup and inquiry the site records (sella-leads.php)
 * goes on to the right group in the ActiveTrail account.
 *
 * Each lead carries a channel: the contact form, the newsletter popup, the
 * popup before checkout, a back-in-stock request, an Elementor form (by its
 * ID) or a first-chapter request by the page it was sent from. The screen
 * (פניות ולידים → אקטיב טרייל) shows a card per channel with the group the
 * contact joins and a field map: what the site knows about the lead (name,
 * book, page, the form's own fields, fixed text) into which ActiveTrail
 * field (first name, SMS, ext1…). A channel without its own card falls
 * back to its kind and then to the default.
 *
 * First chapters are one card, for every book and form: it is mapped once,
 * and the book's name and the chapter's link follow what each request got.
 * Each book can have a group of its own there, under the same map.
 *
 * Each card knows where its form is shown: a published page or product, or
 * through a template, by its display conditions or the pages that embed it.
 * The screen filters by those places. A form that is shown nowhere and sent
 * no lead lately is not in use and stays out of sight until asked for.
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
define( 'SELLA_AT_FIELDS_CACHE', 'sella_at_fields' );
// A form that sent a lead this recently is in use, wherever it is shown.
define( 'SELLA_AT_RECENT_DAYS', 90 );

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
		// Fields for every contact: {map}.
		'global'   => array( 'map' => array() ),
		// The route of a lead whose channel and kind have none: {groups, map}.
		'default'  => array(
			'groups' => array(),
			'map'    => array(),
		),
		// By channel: {groups, map, off}.
		'routes'   => array(),
	);
}

/**
 * @return array
 */
function sella_at_settings() {
	$saved = get_option( SELLA_AT_SETTINGS, array() );
	$saved = is_array( $saved ) ? $saved : array();

	// The first version kept "ext1={book}" lines in 'fields'.
	if ( isset( $saved['fields'] ) && ! isset( $saved['global'] ) ) {
		$saved['global'] = array( 'map' => sella_at_map_from_lines( $saved['fields'] ) );
	}

	return array_merge( sella_at_defaults(), $saved );
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
 * The ActiveTrail fields a map may fill: API key => label. The labels come
 * from the account when they were fetched (the names given to ext1… there).
 *
 * @return array<string, string>
 */
function sella_at_target_fields() {
	$fields = array(
		'email'      => 'אימייל',
		'first_name' => 'שם פרטי',
		'last_name'  => 'שם משפחה',
		'sms'        => 'נייד (SMS)',
		'phone1'     => 'טלפון',
		'phone2'     => 'טלפון נוסף',
		'street'     => 'רחוב',
		'city'       => 'עיר',
		'zip_code'   => 'מיקוד',
		'fax'        => 'פקס',
	);
	for ( $i = 1; $i <= 25; $i++ ) {
		$fields[ 'ext' . $i ] = 'שדה נוסף ' . $i;
	}
	for ( $i = 1; $i <= 5; $i++ ) {
		$fields[ 'num' . $i ] = 'שדה מספרי ' . $i;
	}
	for ( $i = 1; $i <= 5; $i++ ) {
		$fields[ 'date' . $i ] = 'שדה תאריך ' . $i;
	}

	$account = get_transient( SELLA_AT_FIELDS_CACHE );
	if ( is_array( $account ) ) {
		foreach ( $account as $key => $label ) {
			if ( isset( $fields[ $key ] ) && '' !== $label ) {
				$fields[ $key ] = $label;
			}
		}
	}
	return $fields;
}

/**
 * The account's names for its fields, API key => name. Cached with the groups.
 *
 * @param string $token A token to try instead of the saved one.
 * @return array<string, string>
 */
function sella_at_fetch_fields( $token = '' ) {
	$result = sella_at_request( 'GET', 'account/contactFields?FieldsType=1', null, $token );
	$names  = array();
	if ( '' !== $result['error'] || ! is_array( $result['data'] ) ) {
		return $names;
	}
	$special = array(
		'firstname' => 'first_name',
		'lastname'  => 'last_name',
		'zipcode'   => 'zip_code',
	);
	foreach ( $result['data'] as $field ) {
		if ( empty( $field['field_name'] ) || empty( $field['is_active'] ) ) {
			continue;
		}
		$key = strtolower( (string) $field['field_name'] );
		$key = $special[ $key ] ?? $key;
		if ( isset( $field['field_display_name'] ) ) {
			$names[ $key ] = html_entity_decode( (string) $field['field_display_name'], ENT_QUOTES, 'UTF-8' );
		}
	}
	return $names;
}

/**
 * "ext1=value" lines into map entries, for settings saved by the first version.
 *
 * @param string $text Lines.
 * @return array<int, array{src: string, field: string, text: string}>
 */
function sella_at_map_from_lines( $text ) {
	$map     = array();
	$allowed = sella_at_target_fields();
	foreach ( preg_split( '/[\r\n;]+/', (string) $text ) as $line ) {
		if ( false === strpos( $line, '=' ) ) {
			continue;
		}
		list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
		$key                 = strtolower( $key );
		if ( isset( $allowed[ $key ] ) && '' !== $value ) {
			$map[] = array(
				'src'   => 'text',
				'field' => $key,
				'text'  => $value,
			);
		}
	}
	return $map;
}

/**
 * A route's map, whichever version saved it.
 *
 * @param array $route Route or settings part.
 * @return array<int, array{src: string, field: string, text: string}>
 */
function sella_at_route_map( $route ) {
	if ( isset( $route['map'] ) && is_array( $route['map'] ) ) {
		return $route['map'];
	}
	return isset( $route['fields'] ) ? sella_at_map_from_lines( $route['fields'] ) : array();
}

/**
 * ---------------------------------------------------------------------------
 * Where the forms are
 * ---------------------------------------------------------------------------
 */

/**
 * The templates an Elementor page shows: the template widget, the
 * [elementor-template] shortcode, and popups opened by a link or a dynamic tag.
 *
 * @param string $data A post's _elementor_data, as stored.
 * @return int[]
 */
function sella_at_referenced_templates( $data ) {
	$ids = array();

	if ( preg_match_all( '/"template_id":"?(\d+)/', $data, $found ) ) {
		$ids = array_merge( $ids, $found[1] );
	}
	if ( preg_match_all( '/elementor-template\s+id=\\\\*["\']?(\d+)/', $data, $found ) ) {
		$ids = array_merge( $ids, $found[1] );
	}
	if ( preg_match_all( '/%22popup%22%3A%22(\d+)/', $data, $found ) ) {
		$ids = array_merge( $ids, $found[1] );
	}
	// A popup's link: base64 of {"id":"123","toggle":false}, URL-encoded.
	if ( preg_match_all( '/popup%3Aopen%26settings%3D([A-Za-z0-9%_\-]+)/', $data, $found ) ) {
		foreach ( $found[1] as $encoded ) {
			$settings = json_decode( (string) base64_decode( rawurldecode( $encoded ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( ! empty( $settings['id'] ) ) {
				$ids[] = $settings['id'];
			}
		}
	}

	return array_values( array_unique( array_map( 'intval', $ids ) ) );
}

/**
 * The published posts that show each template.
 *
 * @return array<int, int[]> Template ID => post IDs.
 */
function sella_at_template_hosts() {
	global $wpdb;
	static $hosts = null;

	if ( null !== $hosts ) {
		return $hosts;
	}

	$hosts = array();
	$rows  = $wpdb->get_results(
		"SELECT p.ID, m.meta_value FROM {$wpdb->postmeta} m
		INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
		WHERE m.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND p.post_status = 'publish'
		AND ( m.meta_value LIKE '%template_id%' OR m.meta_value LIKE '%elementor-template%' OR m.meta_value LIKE '%popup%' )"
	);
	foreach ( (array) $rows as $row ) {
		foreach ( sella_at_referenced_templates( (string) $row->meta_value ) as $template ) {
			$hosts[ $template ][] = (int) $row->ID;
		}
	}
	return $hosts;
}

/**
 * Where a Theme Builder display condition shows its template.
 *
 * @param string $condition Such as include/general or include/singular/page/12.
 * @return string A place, or '' for an exclusion.
 */
function sella_at_condition_place( $condition ) {
	$condition = (string) $condition;
	if ( 0 !== strpos( $condition, 'include/' ) ) {
		return '';
	}
	if ( preg_match( '#^include/singular/[a-z_]+/(\d+)$#', $condition, $found ) ) {
		return 'post:' . (int) $found[1];
	}
	return false !== strpos( $condition, 'product' ) ? 'all_products' : 'site';
}

/**
 * Where a post's content is shown. A place is post:ID, all_products or site.
 *
 * @param int   $post_id Post.
 * @param array $visited Templates already followed, since they can embed each other.
 * @return string[] Empty when it is shown nowhere.
 */
function sella_at_post_places( $post_id, $visited = array() ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'publish' !== $post->post_status || isset( $visited[ $post->ID ] ) ) {
		return array();
	}
	if ( 'elementor_library' !== $post->post_type ) {
		return array( 'post:' . $post->ID );
	}

	$visited[ $post->ID ] = true;

	$places = array_map( 'sella_at_condition_place', (array) get_post_meta( $post->ID, '_elementor_conditions', true ) );
	$hosts  = sella_at_template_hosts();
	foreach ( $hosts[ $post->ID ] ?? array() as $host ) {
		$places = array_merge( $places, sella_at_post_places( $host, $visited ) );
	}
	return array_values( array_unique( array_filter( $places ) ) );
}

/**
 * Where an Elementor form is shown, through any of its copies.
 *
 * @param array $form A form of sella_fc_elementor_forms().
 * @return string[]
 */
function sella_at_form_places( $form ) {
	$places = array();
	foreach ( (array) ( $form['posts'] ?? array( $form['post_id'] ) ) as $post_id ) {
		$places = array_merge( $places, sella_at_post_places( (int) $post_id ) );
	}
	return array_values( array_unique( $places ) );
}

/**
 * The pages each channel's leads came from lately.
 *
 * @return array<string, string[]> Channel => page URLs ('' for a lead without one).
 */
function sella_at_recent_pages() {
	global $wpdb;

	if ( ! function_exists( 'sella_leads_table' ) || ! get_option( 'sella_leads_db_version' ) ) {
		return array();
	}

	$table = sella_leads_table();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT channel, page_url FROM {$table} WHERE channel <> '' AND created_at > %s GROUP BY channel, page_url LIMIT 500", wp_date( 'Y-m-d H:i:s', time() - SELLA_AT_RECENT_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$pages = array();
	foreach ( (array) $rows as $row ) {
		$pages[ $row->channel ][] = (string) $row->page_url;
	}
	return $pages;
}

/**
 * The places behind a few page URLs; the whole site when none is a known page.
 *
 * @param string[] $urls Page URLs.
 * @return string[]
 */
function sella_at_url_places( $urls ) {
	$places = array();
	foreach ( array_slice( $urls, 0, 5 ) as $url ) {
		$post_id = '' !== $url ? url_to_postid( $url ) : 0;
		if ( $post_id ) {
			$places[] = 'post:' . $post_id;
		}
	}
	return $places ? array_values( array_unique( $places ) ) : array( 'site' );
}

/**
 * Where a shortcode of the theme is shown.
 *
 * @param string $tag Shortcode.
 * @return string[]
 */
function sella_at_shortcode_places( $tag ) {
	global $wpdb;

	$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
	$ids  = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
			WHERE p.post_type <> 'revision' AND p.post_status = 'publish' AND ( p.post_content LIKE %s OR m.meta_value LIKE %s )",
			$like,
			$like
		)
	);

	$places = array();
	foreach ( (array) $ids as $post_id ) {
		$places = array_merge( $places, sella_at_post_places( (int) $post_id ) );
	}
	return array_values( array_unique( $places ) );
}

/**
 * A place, in words.
 *
 * @param string $place post:ID, all_products or site.
 * @return string
 */
function sella_at_place_label( $place ) {
	if ( 'site' === $place ) {
		return 'כל האתר';
	}
	if ( 'all_products' === $place ) {
		return 'כל המוצרים';
	}
	return wp_strip_all_tags( (string) get_the_title( (int) substr( $place, 5 ) ) );
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
 * A form's field that gets no row in a map: the interest question, which is
 * not sent on to ActiveTrail.
 *
 * @param string $label Field label.
 * @return bool
 */
function sella_at_field_left_out( $label ) {
	return false !== mb_strpos( (string) $label, 'תחום עניין' );
}

/**
 * The books a first-chapter card can give a group: the chapters that can be
 * sent, by the product's name, and any that already have a group saved.
 *
 * @param int[] $saved Chapter IDs with a saved group.
 * @return array<int, string> Chapter ID => name.
 */
function sella_at_books( $saved = array() ) {
	$books = array();
	if ( function_exists( 'sella_fc_chapter_choices' ) ) {
		foreach ( array_keys( sella_fc_chapter_choices() ) as $chapter_id ) {
			if ( sella_fc_chapter_ok( $chapter_id ) || in_array( (int) $chapter_id, array_map( 'intval', $saved ), true ) ) {
				$books[ (int) $chapter_id ] = sella_fc_product_name( (int) $chapter_id );
			}
		}
	}
	natcasesort( $books );
	return $books;
}

/**
 * The channels the screen lists, in order: the fixed ones, the Elementor
 * forms, anything else that has reached the list, then the one card of all
 * first chapters. Each:
 * - label, kind (a lead type), page (URL or ''), note;
 * - fields: labels of the form's own fields, when known;
 * - own: what else the form asks or holds, as sources (see sella_at_sources);
 * - person: whether the name and phone may be known, beyond the address;
 * - places: where it is shown; used: false when it is not in use.
 *
 * @return array<string, array{label: string, kind: string, page: string, note: string, fields: string[], own: string[], person: bool, places: string[], used: bool}>
 */
function sella_at_channels() {
	global $wpdb;

	$channel = function ( $values ) {
		return array_merge(
			array(
				'label'  => '',
				'kind'   => 'form',
				'page'   => '',
				'note'   => '',
				'fields' => array(),
				'own'    => array(),
				'person' => true,
				'places' => array( 'site' ),
				'used'   => true,
			),
			$values
		);
	};

	$recent   = sella_at_recent_pages();
	$checkout = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
	$contact  = sella_at_shortcode_places( 'sella_contact_form' );
	$reader   = array( '{name}', '{email}', '{phone}' );

	$channels = array(
		'contact'        => $channel(
			array(
				'label'  => 'טופס צור קשר',
				'kind'   => 'contact',
				'note'   => 'הטופס [sella_contact_form]',
				'own'    => array( '{name}', '{email}', '{phone}', '{order}', '{message}' ),
				'places' => $contact ? $contact : array( 'site' ),
			)
		),
		'newsletter'     => $channel(
			array(
				'label'  => 'פופאפ ניוזלטר',
				'kind'   => 'newsletter',
				'note'   => 'וכל טופס HTML אחר של ניוזלטר באתר',
				'own'    => array( '{email}' ),
				'person' => false,
			)
		),
		'checkout_email' => $channel(
			array(
				'label'  => 'מייל לפני תשלום',
				'kind'   => 'checkout_email',
				'note'   => 'הפופאפ שמבקש מייל לפני המעבר לתשלום',
				'own'    => array( '{email}' ),
				'person' => false,
				'places' => $checkout > 0 ? array( 'post:' . $checkout ) : array( 'site' ),
			)
		),
		'back_in_stock'  => $channel(
			array(
				'label'  => 'עדכון כשהספר חוזר למלאי',
				'kind'   => 'back_in_stock',
				'note'   => 'הטופס בדף של ספר שאזל; "שם הספר" הוא הספר שביקשו',
				'own'    => array( '{email}', '{book}' ),
				'person' => false,
				'places' => array( 'all_products' ),
			)
		),
	);

	$forms = function_exists( 'sella_fc_elementor_forms' ) ? sella_fc_elementor_forms() : array();
	$lists = function_exists( 'sella_leads_settings' ) ? sella_leads_settings()['forms'] : array();
	$types = function_exists( 'sella_leads_types' ) ? sella_leads_types() : array();

	$form_fields = function ( $form ) {
		$labels = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$label = (string) ( $field['label'] ?? '' );
			if ( '' === $label ) {
				$label = (string) ( $field['id'] ?? '' );
			}
			if ( 'interest' === ( $field['id'] ?? '' ) || sella_at_field_left_out( $label ) ) {
				continue;
			}
			if ( '' !== $label && ! in_array( $field['type'] ?? '', array( 'honeypot', 'recaptcha', 'recaptcha_v3', 'html', 'step' ), true ) ) {
				$labels[] = sanitize_text_field( $label );
			}
		}
		return array_values( array_unique( $labels ) );
	};

	$form_note = function ( $form, $places ) {
		if ( ! $places ) {
			return 'לא מוצג באף דף' . ( '' !== $form['post_title'] ? '; נמצא ב: ' . $form['post_title'] : '' );
		}
		return 'טופס Elementor' . ( 'elementor_library' === get_post_type( (int) $form['post_id'] ) && '' !== $form['post_title'] ? ' דרך התבנית ' . $form['post_title'] : '' );
	};

	// What a first-chapter form asks beyond the name, address and phone, which
	// the card has rows for: they hold on every request, whichever form sent it.
	// Nor the field that picks the chapter, which the book and link already follow.
	$chapter_fields = function ( $form, $skip_ids ) use ( $form_fields ) {
		$form['fields'] = array_filter(
			(array) ( $form['fields'] ?? array() ),
			function ( $field ) use ( $skip_ids ) {
				return ! in_array( $field['id'] ?? '', $skip_ids, true ) && ! in_array( $field['type'] ?? '', array( 'email', 'tel' ), true );
			}
		);
		return $form_fields( $form );
	};

	$book_fields = array();
	$book_places = array();

	// Elementor forms that are not connected to a first chapter.
	foreach ( $forms as $form ) {
		if ( function_exists( 'sella_fc_connection' ) && null !== sella_fc_connection( $form['id'] ) ) {
			continue;
		}
		$list = $lists[ $form['id'] ] ?? 'auto';
		if ( 'skip' === $list ) {
			continue;
		}
		$places = sella_at_form_places( $form );
		if ( ! $places && isset( $recent[ 'form:' . $form['id'] ] ) ) {
			$places = sella_at_url_places( $recent[ 'form:' . $form['id'] ] );
		}

		// Set to the first-chapter list on the leads screen: it goes through that card.
		if ( 'first_chapter' === $list ) {
			$name_field = array();
			foreach ( (array) $form['fields'] as $field ) {
				if ( 'text' === $field['type'] && ( 'name' === $field['id'] || false !== mb_strpos( $field['label'], 'שם' ) ) ) {
					$name_field[] = $field['id'];
					break;
				}
			}
			$book_fields = array_merge( $book_fields, $chapter_fields( $form, $name_field ) );
			$book_places = array_merge( $book_places, $places );
			continue;
		}

		$channels[ 'form:' . $form['id'] ] = $channel(
			array(
				'label'  => '' !== $form['name'] ? $form['name'] : 'טופס Elementor ללא שם',
				'kind'   => isset( $types[ $list ] ) ? $list : 'form',
				'note'   => $form_note( $form, $places ),
				'fields' => $form_fields( $form ),
				'places' => $places,
				'used'   => (bool) $places,
			)
		);
	}

	// First chapters: the product pages with a chapter, and the connected forms.
	if ( function_exists( 'sella_fc_settings' ) ) {
		foreach ( (array) sella_fc_settings()['products'] as $row ) {
			$product = ! empty( $row['product'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row['product'] ) : null;
			if ( $product && 'publish' === $product->get_status() && sella_fc_chapter_ok( $row['chapter'] ) ) {
				$book_places[] = 'post:' . $product->get_id();
			}
		}
		foreach ( $forms as $form ) {
			$connection = function_exists( 'sella_fc_connection' ) ? sella_fc_connection( $form['id'] ) : null;
			if ( $connection ) {
				$book_fields = array_merge( $book_fields, $chapter_fields( $form, array( $connection['name'], $connection['email'], $connection['phone'], $connection['choice'] ) ) );
				$book_places = array_merge( $book_places, sella_at_form_places( $form ) );
			}
		}
	}
	foreach ( $recent as $key => $urls ) {
		if ( 0 === strpos( $key, 'first_chapter:' ) ) {
			$book_places = array_merge( $book_places, array_diff( sella_at_url_places( $urls ), array( 'site' ) ) );
		}
	}

	// Channels that reached the list and are not above: forms that were deleted.
	if ( function_exists( 'sella_leads_table' ) && get_option( 'sella_leads_db_version' ) ) {
		$table = sella_leads_table();
		$seen  = $wpdb->get_results( "SELECT channel, type, MAX(page_url) AS page_url, COUNT(*) AS total FROM {$table} WHERE channel <> '' GROUP BY channel, type ORDER BY total DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $seen as $row ) {
			if ( isset( $channels[ $row->channel ] ) || isset( $types[ $row->channel ] ) || 'first_chapter' === $row->type ) {
				continue;
			}
			$channels[ $row->channel ] = $channel(
				array(
					'label'  => 0 === strpos( $row->channel, 'form:' ) ? 'טופס Elementor שכבר לא באתר' : $row->channel,
					'kind'   => isset( $types[ $row->type ] ) ? $row->type : 'form',
					'page'   => (string) $row->page_url,
					'note'   => (int) $row->total . ' רשומות ברשימה',
					'places' => isset( $recent[ $row->channel ] ) ? sella_at_url_places( $recent[ $row->channel ] ) : array(),
					'used'   => isset( $recent[ $row->channel ] ),
				)
			);
		}
	}

	$book_places = array_values( array_unique( $book_places ) );

	$channels['first_chapter'] = $channel(
		array(
			'label'  => 'פרק ראשון: כל הספרים',
			'kind'   => 'first_chapter',
			'note'   => 'כל בקשה לפרק ראשון, מכל ספר ומכל טופס, עוברת כאן',
			'fields' => array_values( array_unique( $book_fields ) ),
			'own'    => array_merge( $reader, array( '{book}', '{chapter_link}' ) ),
			'places' => $book_places ? $book_places : array( 'site' ),
		)
	);

	// The labels of the extra fields the channel's leads carried, for the map.
	if ( function_exists( 'sella_leads_table' ) && get_option( 'sella_leads_db_version' ) ) {
		$table = sella_leads_table();
		foreach ( $channels as $key => &$entry ) {
			if ( 'first_chapter' === $key ) {
				continue;
			}
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT fields FROM {$table} WHERE channel = %s AND fields IS NOT NULL ORDER BY id DESC LIMIT 20", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $json ) {
				$fields = json_decode( (string) $json, true );
				foreach ( is_array( $fields ) ? array_keys( $fields ) : array() as $label ) {
					$label = sanitize_text_field( (string) $label );
					// "טופס" is the form's name, which has a source of its own.
					if ( 'טופס' !== $label && ! sella_at_field_left_out( $label ) && ! in_array( $label, $entry['fields'], true ) ) {
						$entry['fields'][] = $label;
					}
				}
			}
		}
		unset( $entry );
	}

	foreach ( $channels as $key => &$entry ) {
		// A form with fields of its own lists its name, address and phone among them.
		if ( $entry['fields'] && 'first_chapter' !== $key ) {
			$entry['own'] = array_values( array_diff( $entry['own'], $reader ) );
		}
		if ( '' === $entry['page'] ) {
			foreach ( $entry['places'] as $place ) {
				if ( 0 === strpos( $place, 'post:' ) ) {
					$entry['page'] = (string) get_permalink( (int) substr( $place, 5 ) );
					break;
				}
			}
		}
	}
	unset( $entry );

	return $channels;
}

/**
 * The route of a lead: its channel's, else its kind's, else the default. A
 * first chapter goes by the card of all first chapters first; a route saved
 * for one of its pages, from before that card, counts only until it is set.
 * On that card, a book with a group of its own goes there instead of to the
 * card's group. A route with a map and no group joins the default's groups,
 * as its select says.
 *
 * @param object $row Lead row (channel, type).
 * @return array{groups: int[], map: array, via: string}|null Null when it is not to be sent.
 */
function sella_at_route( $row ) {
	$settings = sella_at_settings();
	$routes   = (array) $settings['routes'];
	$channel  = (string) ( $row->channel ?? '' );
	$kind     = (string) ( $row->type ?? '' );

	foreach ( array_unique( array_filter( 'first_chapter' === $kind ? array( $kind, $channel ) : array( $channel, $kind ) ) ) as $key ) {
		if ( ! isset( $routes[ $key ] ) ) {
			continue;
		}
		if ( ! empty( $routes[ $key ]['off'] ) ) {
			return null;
		}
		$map    = sella_at_route_map( $routes[ $key ] );
		$groups = array_map( 'intval', (array) ( $routes[ $key ]['groups'] ?? array() ) );
		if ( 'first_chapter' === $key && ! empty( $routes[ $key ]['books'] ) && function_exists( 'sella_fc_lead_chapter' ) ) {
			$book = (int) ( $routes[ $key ]['books'][ sella_fc_lead_chapter( $row )['id'] ] ?? 0 );
			if ( $book ) {
				$groups = array( $book );
			}
		}
		if ( $groups || $map ) {
			return array(
				'groups' => $groups ? $groups : array_map( 'intval', (array) $settings['default']['groups'] ),
				'map'    => $map,
				'via'    => $key,
			);
		}
	}

	if ( empty( $settings['default']['groups'] ) ) {
		return null;
	}
	return array(
		'groups' => array_map( 'intval', (array) $settings['default']['groups'] ),
		'map'    => sella_at_route_map( $settings['default'] ),
		'via'    => 'default',
	);
}

/**
 * ---------------------------------------------------------------------------
 * The contact
 * ---------------------------------------------------------------------------
 */

/**
 * Everything the site can know about a lead: token => label, in groups.
 *
 * @return array<string, array<string, string>> Group label => (token => label).
 */
function sella_at_all_sources() {
	return array(
		'פרטי הפונה'  => array(
			'{name}'       => 'שם מלא',
			'{first_name}' => 'שם פרטי (המילה הראשונה)',
			'{last_name}'  => 'שם משפחה (שאר השם)',
			'{email}'      => 'אימייל',
			'{phone}'      => 'נייד',
		),
		'הספר והפרק'  => array(
			'{book}'         => 'שם הספר (שם המוצר)',
			'{chapter_link}' => 'קישור לפרק הראשון',
			'{chapter_file}' => 'קישור ישיר לקובץ הפרק (בלי ספירת קליקים)',
		),
		'מאיפה הגיע'  => array(
			'{form}'     => 'שם הטופס',
			'{page}'     => 'שם הדף',
			'{page_url}' => 'כתובת הדף',
			'{source}'   => 'המקור כמו ברשימה',
			'{type}'     => 'סוג ההרשמה',
			'{date}'     => 'תאריך',
		),
		'תוכן הפנייה' => array(
			'{message}' => 'ההודעה',
			'{order}'   => 'מספר הזמנה',
		),
		'קבוע'        => array( 'text' => 'טקסט קבוע' ),
	);
}

/**
 * The map's sources for a card. For a channel, "שדות הטופס" comes first and
 * holds what that form asks (its own fields as {field:label}); the card gives
 * each of them a row. The rest is what can be added on top. Without a channel
 * (the default and every contact), everything.
 *
 * @param array|null $channel A channel of sella_at_channels().
 * @return array<string, array<string, string>> Group label => (token => label).
 */
function sella_at_sources( $channel = null ) {
	$all = sella_at_all_sources();
	if ( null === $channel ) {
		return $all;
	}

	$labels = array_merge( ...array_values( $all ) );
	$reader = array_intersect( $channel['own'], array( '{name}', '{email}', '{phone}' ) );
	$own    = array();
	foreach ( $reader as $token ) {
		$own[ $token ] = $labels[ $token ];
	}
	foreach ( $channel['fields'] as $label ) {
		$own[ '{field:' . $label . '}' ] = $label;
	}
	foreach ( array_diff( $channel['own'], $reader ) as $token ) {
		$own[ $token ] = $labels[ $token ];
	}

	$sources = array( 'שדות הטופס' => $own );
	if ( $channel['person'] ) {
		$sources['פרטי הפונה'] = array_diff_key( $all['פרטי הפונה'], $own );
	}
	if ( isset( $own['{chapter_link}'] ) ) {
		$sources['הספר והפרק'] = array_diff_key( $all['הספר והפרק'], $own );
	}
	$sources['מאיפה הגיע'] = $all['מאיפה הגיע'];
	$sources['קבוע']       = $all['קבוע'];

	return array_filter( $sources );
}

/**
 * The placeholders a map may use, for a lead.
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

	$chapter = 'first_chapter' === $kind && function_exists( 'sella_fc_lead_chapter' ) ? sella_fc_lead_chapter( $row ) : array();

	return array(
		'{name}'         => $name,
		'{first_name}'   => $parts[0] ?? '',
		'{last_name}'    => $parts[1] ?? '',
		'{email}'        => (string) ( $row->email ?? '' ),
		'{phone}'        => (string) ( $row->phone ?? '' ),
		'{source}'       => $source,
		'{book}'         => 'first_chapter' === $kind ? ( $chapter['book'] ?? $source ) : ( 'back_in_stock' === $kind ? $source : '' ),
		'{chapter_link}' => $chapter['link'] ?? '',
		'{chapter_file}' => $chapter['file'] ?? '',
		'{form}'         => is_array( $fields ) && isset( $fields['טופס'] ) ? (string) $fields['טופס'] : ( 'form' === substr( (string) ( $row->channel ?? '' ), 0, 4 ) ? $source : '' ),
		'{page}'         => '' !== $title ? $title : sella_at_path( $page ),
		'{page_url}'     => $page,
		'{type}'         => $types[ $kind ] ?? $kind,
		'{channel}'      => (string) ( $row->channel ?? '' ),
		'{message}'      => (string) ( $row->message ?? '' ),
		'{order}'        => (string) ( $row->order_ref ?? '' ),
		'{date}'         => ! empty( $row->created_at ) ? mysql2date( 'Y-m-d', $row->created_at ) : wp_date( 'Y-m-d' ),
	);
}

/**
 * One map entry's value for a lead.
 *
 * @param array  $entry  src, field, text.
 * @param array  $values Placeholders.
 * @param array  $fields The lead's own fields, label => value.
 * @return string
 */
function sella_at_map_value( $entry, $values, $fields ) {
	$src = (string) ( $entry['src'] ?? '' );
	if ( 'text' === $src ) {
		return trim( strtr( (string) ( $entry['text'] ?? '' ), $values ) );
	}
	if ( 0 === strpos( $src, '{field:' ) ) {
		// The saved label went through sanitize_text_field; the lead's as the form gave it.
		$label = substr( $src, 7, -1 );
		foreach ( $fields as $key => $value ) {
			if ( sanitize_text_field( (string) $key ) === $label ) {
				return trim( (string) $value );
			}
		}
		return '';
	}
	return isset( $values[ $src ] ) ? trim( (string) $values[ $src ] ) : '';
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
	$fields   = ! empty( $row->fields ) ? json_decode( (string) $row->fields, true ) : array();
	$fields   = is_array( $fields ) ? $fields : array();
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

	// The map of every contact first, the route's after it (it wins on the same field).
	$entries = array_merge( sella_at_route_map( $settings['global'] ), (array) ( $route['map'] ?? array() ) );
	$allowed = sella_at_target_fields();
	foreach ( $entries as $entry ) {
		$field = (string) ( $entry['field'] ?? '' );
		if ( ! isset( $allowed[ $field ] ) ) {
			continue;
		}
		$value = sella_at_map_value( $entry, $values, $fields );
		if ( '' === $value ) {
			continue;
		}
		// The contact is found by its address: only another address may replace it.
		if ( 'email' === $field && ! is_email( $value ) ) {
			continue;
		}
		$contact[ $field ] = 0 === strpos( $field, 'num' ) ? (int) preg_replace( '/[^\d\-]/', '', $value ) : mb_substr( $value, 0, 250 );
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
 * The account's groups, id => name. Cached for an hour, with the account's
 * field names.
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
		set_transient( SELLA_AT_FIELDS_CACHE, sella_at_fetch_fields(), HOUR_IN_SECONDS );
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
	$css = get_stylesheet_directory() . '/assets/css/sella-leads-admin.css';
	$js  = get_stylesheet_directory() . '/assets/js/sella-activetrail-admin.js';
	wp_enqueue_style( 'sella-leads-admin', get_stylesheet_directory_uri() . '/assets/css/sella-leads-admin.css', array(), (string) filemtime( $css ) );
	wp_enqueue_script( 'sella-activetrail-admin', get_stylesheet_directory_uri() . '/assets/js/sella-activetrail-admin.js', array(), (string) filemtime( $js ), true );
}
add_action( 'admin_enqueue_scripts', 'sella_at_admin_assets' );

/**
 * A posted map: rows of src, field, text.
 *
 * @param mixed $posted Unslashed rows.
 * @return array<int, array{src: string, field: string, text: string}>
 */
function sella_at_sanitize_map( $posted ) {
	$map     = array();
	$allowed = sella_at_target_fields();
	foreach ( is_array( $posted ) ? $posted : array() as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$src   = isset( $entry['src'] ) ? trim( sanitize_text_field( $entry['src'] ) ) : '';
		$field = isset( $entry['field'] ) ? sanitize_key( $entry['field'] ) : '';
		$text  = isset( $entry['text'] ) ? trim( sanitize_text_field( $entry['text'] ) ) : '';
		if ( ! isset( $allowed[ $field ] ) || '' === $src ) {
			continue;
		}
		if ( 'text' !== $src && ! preg_match( '/^\{[a-z_]+\}$/', $src ) && ! preg_match( '/^\{field:.+\}$/u', $src ) ) {
			continue;
		}
		if ( 'text' === $src && '' === $text ) {
			continue;
		}
		// The address always goes to the email field. Kept as a row, it would make an empty card a route.
		if ( '{email}' === $src && 'email' === $field ) {
			continue;
		}
		$map[] = array(
			'src'   => $src,
			'field' => $field,
			'text'  => 'text' === $src ? $text : '',
		);
	}
	return $map;
}

/**
 * A posted route: the group select ('' inherit, 'off', or an ID), more IDs,
 * the map, and on the first-chapter card a group by chapter.
 *
 * @param array $posted Unslashed.
 * @return array{groups: int[], map: array, off: bool, books: array<int, int>}
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
	$books = array();
	foreach ( isset( $posted['books'] ) && is_array( $posted['books'] ) ? $posted['books'] : array() as $chapter_id => $group ) {
		if ( absint( $chapter_id ) && absint( $group ) ) {
			$books[ absint( $chapter_id ) ] = absint( $group );
		}
	}
	return array(
		'groups' => array_values( array_unique( $groups ) ),
		'map'    => sella_at_sanitize_map( $posted['map'] ?? array() ),
		'off'    => 'off' === $choice,
		'books'  => $books,
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

		// The screen has no card for these (a form since removed), so they stay as they are.
		$routes = array_diff_key( (array) $current['routes'], sella_at_channels() );
		foreach ( isset( $posted['routes'] ) && is_array( $posted['routes'] ) ? $posted['routes'] : array() as $entry ) {
			$channel = isset( $entry['channel'] ) ? trim( sanitize_text_field( $entry['channel'] ) ) : '';
			if ( '' === $channel ) {
				continue;
			}
			$route = sella_at_sanitize_route( $entry );
			if ( $route['off'] || $route['groups'] || $route['map'] || $route['books'] ) {
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
				'global'   => array( 'map' => sella_at_sanitize_map( $posted['global']['map'] ?? array() ) ),
				'default'  => array(
					'groups' => $default['groups'],
					'map'    => $default['map'],
				),
				'routes'   => $routes,
			),
			false
		);
		if ( $token !== $current['token'] ) {
			delete_transient( SELLA_AT_GROUPS_CACHE );
			delete_transient( SELLA_AT_FIELDS_CACHE );
		}
		$notice = 'saved';
		$place  = isset( $posted['place'] ) ? sanitize_text_field( $posted['place'] ) : '';
		if ( '' !== $place ) {
			$back = add_query_arg( 'place', rawurlencode( $place ), $back );
		}
	} elseif ( 'refresh' === $form ) {
		delete_transient( SELLA_AT_GROUPS_CACHE );
		delete_transient( SELLA_AT_FIELDS_CACHE );
		$notice = 'refreshed';
	} elseif ( 'test' === $form ) {
		$email   = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$channel = isset( $_POST['test_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['test_channel'] ) ) : '';
		$kinds   = function_exists( 'sella_leads_types' ) ? sella_leads_types() : array();
		$book    = preg_match( '/^first_chapter:book:(\d+)$/', $channel, $found ) ? (int) $found[1] : 0;
		$channel = $book ? 'first_chapter' : $channel;
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
			// A test sends no chapter, but a card that maps the link should show one.
			if ( 'first_chapter' === $kind && defined( 'SELLA_FC_CPT' ) ) {
				$any             = get_posts( array( 'post_type' => SELLA_FC_CPT, 'numberposts' => 1, 'fields' => 'ids' ) );
				$row->chapter_id = $book ? $book : (int) ( $any[0] ?? 0 );
			}
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
 * The select of ActiveTrail fields in a map's row.
 *
 * @param string $name    Input name.
 * @param string $field   Chosen field.
 * @param array  $targets ActiveTrail fields.
 * @param string $empty   The first option's text; '' for a select without an empty option.
 */
function sella_at_target_select( $name, $field, $targets, $empty ) {
	?>
	<select name="<?php echo esc_attr( $name ); ?>" class="sella-at-field" aria-label="לאיזה שדה באקטיב טרייל">
		<?php if ( '' !== $empty ) : ?>
			<option value=""><?php echo esc_html( $empty ); ?></option>
		<?php endif; ?>
		<?php foreach ( $targets as $key => $label ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $field, $key ); ?>><?php echo esc_html( $label . ' (' . $key . ')' ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * A row for one of the form's own fields: it is always there, and goes
 * nowhere until a field is chosen for it. The address is the exception: it is
 * always sent, so its row starts on the email field and has no "don't send".
 *
 * @param string $prefix  Input name prefix, up to [map].
 * @param string $index   Row index.
 * @param string $token   The source.
 * @param string $label   What it is called on the form.
 * @param string $field   Chosen ActiveTrail field, or ''.
 * @param array  $targets ActiveTrail fields.
 */
function sella_at_map_fixed_row( $prefix, $index, $token, $label, $field, $targets ) {
	$name  = $prefix . '[map][' . $index . ']';
	$email = '{email}' === $token;
	?>
	<tr class="sella-at-map-row sella-at-map-row--fixed<?php echo $email ? ' sella-at-map-row--email' : ''; ?>">
		<td class="sella-at-map__src">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[src]" value="<?php echo esc_attr( $token ); ?>" />
			<span class="sella-at-map__label"><?php echo esc_html( $label ); ?></span>
		</td>
		<td class="sella-at-map__arrow" aria-hidden="true">←</td>
		<td class="sella-at-map__field"><?php sella_at_target_select( $name . '[field]', $email && '' === $field ? 'email' : $field, $targets, $email ? '' : 'לא לשלוח' ); ?></td>
		<td class="sella-at-map__remove"></td>
	</tr>
	<?php
}

/**
 * One row of a map.
 *
 * @param string $prefix  Input name prefix, up to [map].
 * @param string $index   Row index.
 * @param array  $entry   src, field, text (empty for a blank row).
 * @param array  $sources Source groups.
 * @param array  $targets ActiveTrail fields.
 */
function sella_at_map_row( $prefix, $index, $entry, $sources, $targets ) {
	$src   = (string) ( $entry['src'] ?? '' );
	$field = (string) ( $entry['field'] ?? '' );
	$text  = (string) ( $entry['text'] ?? '' );
	$name  = $prefix . '[map][' . $index . ']';
	$known = false;
	foreach ( $sources as $group ) {
		$known = $known || isset( $group[ $src ] );
	}
	?>
	<tr class="sella-at-map-row">
		<td class="sella-at-map__src">
			<select name="<?php echo esc_attr( $name ); ?>[src]" class="sella-at-src" aria-label="מה לשלוח">
				<?php foreach ( $sources as $group_label => $group ) : ?>
					<optgroup label="<?php echo esc_attr( $group_label ); ?>">
						<?php foreach ( $group as $token => $label ) : ?>
							<option value="<?php echo esc_attr( $token ); ?>" <?php selected( $src, $token ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
				<?php if ( '' !== $src && ! $known ) : ?>
					<?php $labels = array_merge( ...array_values( sella_at_all_sources() ) ); ?>
					<option value="<?php echo esc_attr( $src ); ?>" selected><?php echo esc_html( 0 === strpos( $src, '{field:' ) ? substr( $src, 7, -1 ) : ( $labels[ $src ] ?? $src ) ); ?></option>
				<?php endif; ?>
			</select>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[text]" value="<?php echo esc_attr( $text ); ?>" class="sella-at-text" placeholder="הטקסט שיישלח" <?php echo 'text' === $src ? '' : 'hidden'; ?> />
		</td>
		<td class="sella-at-map__arrow" aria-hidden="true">←</td>
		<td class="sella-at-map__field"><?php sella_at_target_select( $name . '[field]', $field, $targets, 'בחירת שדה באקטיב טרייל' ); ?></td>
		<td class="sella-at-map__remove"><button type="button" class="button-link sella-at-map-remove" aria-label="הסרת השורה">✕</button></td>
	</tr>
	<?php
}

/**
 * The first-chapter card's groups: a select for each book.
 *
 * @param string $prefix Input name prefix.
 * @param array  $books  Chapter ID => name.
 * @param array  $saved  Chapter ID => group.
 * @param array  $groups The account's groups.
 */
function sella_at_book_groups( $prefix, $books, $saved, $groups ) {
	?>
	<div class="sella-at-books">
		<p class="sella-at-map__lead">קבוצה לכל ספר: מי שביקש את הפרק של הספר נכנס לקבוצה שלו, עם אותו מיפוי שדות. ספר בלי בחירה נכנס לקבוצה שלמעלה.</p>
		<table class="sella-at-map__table">
			<tbody>
				<?php foreach ( $books as $chapter_id => $book ) : ?>
					<?php $chosen = (int) ( $saved[ $chapter_id ] ?? 0 ); ?>
					<tr>
						<td class="sella-at-map__src"><span class="sella-at-map__label"><?php echo esc_html( $book ); ?></span></td>
						<td class="sella-at-map__arrow" aria-hidden="true">←</td>
						<td class="sella-at-map__field">
							<select name="<?php echo esc_attr( $prefix . '[books][' . $chapter_id . ']' ); ?>" class="sella-at-book-group" aria-label="<?php echo esc_attr( 'הקבוצה של ' . $book ); ?>">
								<option value="">הקבוצה שלמעלה</option>
								<?php foreach ( $groups as $id => $label ) : ?>
									<option value="<?php echo (int) $id; ?>" <?php selected( $chosen, $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								<?php if ( $chosen && ! isset( $groups[ $chosen ] ) ) : ?>
									<option value="<?php echo (int) $chosen; ?>" selected>קבוצה <?php echo (int) $chosen; ?></option>
								<?php endif; ?>
							</select>
						</td>
						<td class="sella-at-map__remove"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * A card: a channel, the default, or the fields of every contact.
 *
 * @param array $args name (input prefix), title, meta (HTML), route, groups,
 *                    sources, targets, kind (CSS), with_group (bool),
 *                    channel (hidden input value or ''), empty_label,
 *                    fixed (token => label, a row each), places (for the
 *                    filter), unused (bool), books (chapter ID => name, a
 *                    group for each).
 */
function sella_at_card( $args ) {
	$route   = $args['route'];
	$map     = sella_at_route_map( $route );
	$targets = $args['targets'];
	$groups  = $args['groups'];
	$name    = $args['name'];
	$chosen  = ! empty( $route['off'] ) ? 'off' : ( ! empty( $route['groups'] ) ? (string) $route['groups'][0] : '' );
	$more    = implode( ', ', array_slice( (array) ( $route['groups'] ?? array() ), 1 ) );
	$saved   = array_filter( (array) ( $route['books'] ?? array() ) );
	$by_book = '';
	if ( $saved ) {
		$by_book = 1 === count( $saved ) ? 'ספר אחד עם קבוצה משלו' : count( $saved ) . ' ספרים עם קבוצה משלהם';
	}
	$active  = 'off' !== $chosen && ( '' !== $chosen || $map || $saved );

	if ( ! $args['with_group'] ) {
		$state = '';
	} elseif ( 'off' === $chosen ) {
		$state = 'לא לשלוח';
	} elseif ( '' !== $chosen ) {
		$state = isset( $groups[ (int) $chosen ] ) ? $groups[ (int) $chosen ] : 'קבוצה ' . $chosen;
		if ( '' !== $more ) {
			$state .= ' +';
		}
		$state .= '' !== $by_book ? ' · ' . $by_book : '';
	} else {
		$state = '' !== $by_book ? $by_book : $args['empty_label'];
	}
	?>
	<details class="sella-at-card sella-at-card--<?php echo esc_attr( $args['kind'] ); ?><?php echo $active ? ' is-active' : ''; ?><?php echo 'off' === $chosen ? ' is-off' : ''; ?>" data-places="<?php echo esc_attr( implode( ' ', $args['places'] ?? array() ) ); ?>" <?php echo ! empty( $args['unused'] ) ? 'data-unused hidden' : ''; ?> <?php echo ! empty( $args['open'] ) ? 'open' : ''; ?>>
		<summary>
			<span class="sella-at-card__title"><?php echo esc_html( $args['title'] ); ?></span>
			<span class="sella-at-card__meta"><?php echo wp_kses_post( $args['meta'] ); ?></span>
			<span class="sella-at-card__state" data-empty="<?php echo esc_attr( $args['empty_label'] ); ?>"><?php echo esc_html( $state ); ?></span>
			<span class="sella-at-card__fields"><?php echo $map ? (int) count( $map ) . ' שדות' : ''; ?></span>
		</summary>
		<div class="sella-at-card__body">
			<?php if ( '' !== $args['channel'] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[channel]" value="<?php echo esc_attr( $args['channel'] ); ?>" />
			<?php endif; ?>

			<?php if ( $args['with_group'] ) : ?>
				<div class="sella-at-card__group">
					<label>
						<span>קבוצה באקטיב טרייל</span>
						<select name="<?php echo esc_attr( $name ); ?>[group]" class="sella-at-group">
							<option value=""><?php echo esc_html( $args['empty_label'] ); ?></option>
							<?php if ( 'default' !== $name ) : ?>
								<option value="off" <?php selected( $chosen, 'off' ); ?>>לא לשלוח</option>
							<?php endif; ?>
							<?php foreach ( $groups as $id => $label ) : ?>
								<option value="<?php echo (int) $id; ?>" <?php selected( $chosen, (string) $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( ctype_digit( $chosen ) && ! isset( $groups[ (int) $chosen ] ) ) : ?>
								<option value="<?php echo (int) $chosen; ?>" selected>קבוצה <?php echo (int) $chosen; ?></option>
							<?php endif; ?>
						</select>
					</label>
					<label>
						<span>קבוצות נוספות (מזהים, בפסיק)</span>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[more]" value="<?php echo esc_attr( $more ); ?>" class="sella-at-more" dir="ltr" placeholder="123, 456" />
					</label>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $args['books'] ) ) : ?>
				<?php sella_at_book_groups( $name, $args['books'], $saved, $groups ); ?>
			<?php endif; ?>

			<div class="sella-at-map" data-prefix="<?php echo esc_attr( $name ); ?>">
				<p class="sella-at-map__lead">
					<?php
					if ( ! $args['with_group'] ) {
						echo 'נשלחים עם כל איש קשר, מכל הערוצים:';
					} elseif ( ! empty( $args['fixed'] ) ) {
						echo 'כל שדה בטופס בשורה משלו: בוחרים לאיזה שדה באקטיב טרייל הוא נכנס. השם, המייל והנייד נשלחים תמיד גם בלי בחירה.';
					} else {
						echo 'השם, המייל והנייד נשלחים תמיד. כאן מוסיפים מה עוד ייכנס לרשומה של איש הקשר:';
					}
					?>
				</p>
				<table class="sella-at-map__table">
					<thead>
						<tr><th>מה לשלוח</th><th></th><th>לאיזה שדה באקטיב טרייל</th><th></th></tr>
					</thead>
					<tbody>
						<?php
						$rest  = $map;
						$fixed = 0;
						foreach ( (array) ( $args['fixed'] ?? array() ) as $token => $label ) {
							$field = '';
							foreach ( $rest as $index => $entry ) {
								if ( $token === ( $entry['src'] ?? '' ) ) {
									$field = (string) ( $entry['field'] ?? '' );
									unset( $rest[ $index ] );
									break;
								}
							}
							sella_at_map_fixed_row( $name, 'f' . $fixed++, (string) $token, $label, $field, $targets );
						}
						foreach ( $rest as $index => $entry ) {
							sella_at_map_row( $name, (string) $index, $entry, $args['sources'], $targets );
						}
						?>
					</tbody>
				</table>
				<template class="sella-at-map__template">
					<table><tbody><?php sella_at_map_row( $name, '__i__', array(), $args['sources'], $targets ); ?></tbody></table>
				</template>
				<button type="button" class="button sella-at-map-add">+ הוספת שדה</button>
			</div>
		</div>
	</details>
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
	$targets  = sella_at_target_fields();
	$leads    = admin_url( 'admin.php?page=' . ( defined( 'SELLA_LEADS_SLUG' ) ? SELLA_LEADS_SLUG : '' ) );

	$notice  = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notices = array(
		'saved'      => 'ההגדרות נשמרו.',
		'refreshed'  => 'הקבוצות והשדות נטענו מחדש מאקטיב טרייל.',
		'test_email' => 'כתובת המייל לבדיקה לא תקינה.',
	);
	$test    = 'tested' === $notice ? (string) get_transient( 'sella_at_test_' . get_current_user_id() ) : '';
	if ( 'tested' === $notice ) {
		delete_transient( 'sella_at_test_' . get_current_user_id() );
	}

	$sections = array(
		'contact'        => 'צור קשר',
		'newsletter'     => 'ניוזלטר',
		'checkout_email' => 'מייל לפני תשלום',
		'back_in_stock'  => 'חזרה למלאי',
		'form'           => 'טפסי Elementor',
		'first_chapter'  => 'פרק ראשון',
	);

	// The filter offers only the places that have a form in use.
	$counts = array();
	$unused = 0;
	foreach ( $channels as $channel ) {
		$unused += $channel['used'] ? 0 : 1;
		foreach ( $channel['used'] ? $channel['places'] : array() as $place ) {
			$counts[ $place ] = ( $counts[ $place ] ?? 0 ) + 1;
		}
	}
	$filter = array(
		'כללי'   => array(),
		'דפים'   => array(),
		'מוצרים' => array(),
	);
	foreach ( $counts as $place => $count ) {
		if ( 0 !== strpos( $place, 'post:' ) ) {
			$group = 'כללי';
		} else {
			$group = 'product' === get_post_type( (int) substr( $place, 5 ) ) ? 'מוצרים' : 'דפים';
		}
		$filter[ $group ][ $place ] = sella_at_place_label( $place ) . ' (' . $count . ')';
	}
	$filter = array_filter( $filter );
	foreach ( $filter as &$options ) {
		natcasesort( $options );
	}
	unset( $options );
	$place = isset( $_GET['place'] ) ? sanitize_text_field( wp_unslash( $_GET['place'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$books = sella_at_books( array_keys( (array) ( $settings['routes']['first_chapter']['books'] ?? array() ) ) );
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
			כל הרשמה ופנייה שנרשמת ב"פניות ולידים" נשלחת גם לאקטיב טרייל. לכל טופס כרטיס: פותחים אותו, בוחרים את הקבוצה שאיש הקשר נכנס אליה,
			ומנתבים מה שהאתר יודע עליו (שם, ספר, דף, שדות הטופס) לשדות של אקטיב טרייל. כל הבקשות לפרק ראשון, מכל ספר ומכל טופס, עוברות בכרטיס אחד שממפים פעם אחת, ובו אפשר לתת לכל ספר קבוצה משלו.
			טופס בלי בחירה משלו הולך לכרטיס של הסוג שלו, ואם גם שם אין, לברירת המחדל. התוצאה של כל שליחה מופיעה ברשימה בעמודת "אקטיב טרייל".
			מוצגים רק טפסים שבשימוש: בדף או מוצר מפורסמים, בתבנית שמוצגת באתר, או כאלה ששלחו פנייה ב-<?php echo (int) SELLA_AT_RECENT_DAYS; ?> הימים האחרונים.
		</p>

		<form method="post" class="sella-at-settings">
			<?php wp_nonce_field( 'sella_at_manage' ); ?>
			<input type="hidden" name="sella_at_form" value="settings" />

			<div class="sella-at-box sella-at-connection">
				<h2>חיבור</h2>
				<div class="sella-at-connection__grid">
					<label class="sella-at-connection__token">
						<span>טוקן API</span>
						<input type="text" name="token" value="<?php echo esc_attr( $settings['token'] ); ?>" class="large-text code" dir="ltr" autocomplete="off" spellcheck="false" />
						<span class="description">באקטיב טרייל: הגדרות → API apps. אחרי שמירה הקבוצות והשדות של החשבון נטענים לכאן.</span>
					</label>
					<label>
						<span>סטטוס איש הקשר</span>
						<select name="status">
							<?php foreach ( array( 'Subscribe' => 'רשום (Subscribe)', 'None' => 'כפי שאקטיב טרייל קובע (None)' ) as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( ! in_array( $settings['status'], array( 'Subscribe', 'None' ), true ) ) : ?>
								<option value="<?php echo esc_attr( $settings['status'] ); ?>" selected><?php echo esc_html( $settings['status'] ); ?></option>
							<?php endif; ?>
						</select>
					</label>
					<label>
						<span>מספר הנייד נכנס ל</span>
						<select name="phone_to">
							<option value="both" <?php selected( $settings['phone_to'], 'both' ); ?>>SMS וגם טלפון</option>
							<option value="sms" <?php selected( $settings['phone_to'], 'sms' ); ?>>SMS בלבד</option>
							<option value="phone1" <?php selected( $settings['phone_to'], 'phone1' ); ?>>טלפון בלבד</option>
						</select>
					</label>
				</div>
				<p class="sella-at-connection__toggle">
					<label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> /> <strong>לשלוח כל הרשמה ופנייה חדשה לאקטיב טרייל</strong></label>
					<?php if ( $groups ) : ?>
						<span class="sella-at-ok">מחובר · <?php echo count( $groups ); ?> קבוצות בחשבון</span>
					<?php elseif ( '' === $settings['token'] ) : ?>
						<span class="description">מדביקים את הטוקן ושומרים כדי לראות את הקבוצות.</span>
					<?php endif; ?>
				</p>
			</div>

			<div class="sella-at-toolbar">
				<h2>ניתוב לפי טופס</h2>
				<span class="sella-at-toolbar__actions">
					<button type="button" class="button-link" data-sella-at="open">פתיחת הכול</button>
					<button type="button" class="button-link" data-sella-at="close">סגירת הכול</button>
				</span>
				<?php submit_button( 'שמירת ההגדרות', 'primary', 'submit', false ); ?>
			</div>

			<div class="sella-at-filter">
				<label>
					<span>טפסים לפי דף או מוצר</span>
					<select name="place" class="sella-at-filter__place">
						<option value="">כל הטפסים שבשימוש</option>
						<?php foreach ( $filter as $group => $options ) : ?>
							<optgroup label="<?php echo esc_attr( $group ); ?>">
								<?php foreach ( $options as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $place, $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ( $unused ) : ?>
					<label class="sella-at-filter__unused"><input type="checkbox" class="sella-at-filter__show" /> להציג גם <?php echo (int) $unused; ?> טפסים שלא בשימוש</label>
				<?php endif; ?>
			</div>

			<?php
			sella_at_card(
				array(
					'name'        => 'default',
					'title'       => 'ברירת מחדל',
					'meta'        => 'כל הרשמה שאין לה ניתוב משלה',
					'route'       => $settings['default'],
					'groups'      => $groups,
					'sources'     => sella_at_sources(),
					'targets'     => $targets,
					'kind'        => 'default',
					'with_group'  => true,
					'channel'     => '',
					'empty_label' => 'לא לשלוח',
					'open'        => empty( $settings['default']['groups'] ) && ! $settings['routes'],
				)
			);
			sella_at_card(
				array(
					'name'        => 'global',
					'title'       => 'שדות לכל איש קשר',
					'meta'        => 'נוספים לכל שליחה, לפני השדות של הטופס (הטופס גובר באותו שדה)',
					'route'       => $settings['global'],
					'groups'      => $groups,
					'sources'     => sella_at_sources(),
					'targets'     => $targets,
					'kind'        => 'global',
					'with_group'  => false,
					'channel'     => '',
					'empty_label' => '',
				)
			);

			foreach ( $sections as $kind => $heading ) :
				$rows = array_filter(
					$channels,
					function ( $channel ) use ( $kind, $sections ) {
						return $channel['kind'] === $kind || ( 'form' === $kind && ! isset( $sections[ $channel['kind'] ] ) );
					}
				);
				if ( ! $rows ) {
					continue;
				}
				$in_use = count( array_filter( array_column( $rows, 'used' ) ) );
				?>
				<div class="sella-at-cards" <?php echo $in_use ? '' : 'hidden'; ?>>
				<h3 class="sella-at-section"><?php echo esc_html( $heading ); ?> <span>(<?php echo (int) $in_use; ?>)</span></h3>
				<?php
				foreach ( $rows as $key => $channel ) {
					$meta = '';
					foreach ( array_slice( $channel['places'], 0, 2 ) as $where ) {
						if ( 0 === strpos( $where, 'post:' ) ) {
							$meta .= '<a href="' . esc_url( (string) get_permalink( (int) substr( $where, 5 ) ) ) . '" target="_blank" rel="noopener">' . esc_html( sella_at_place_label( $where ) ) . '</a> ';
						} else {
							$meta .= '<strong>' . esc_html( sella_at_place_label( $where ) ) . '</strong> ';
						}
					}
					if ( count( $channel['places'] ) > 2 ) {
						$meta .= '<span>ועוד ' . ( count( $channel['places'] ) - 2 ) . '</span> ';
					}
					if ( '' !== $channel['note'] ) {
						$meta .= '<span>' . esc_html( $channel['note'] ) . '</span>';
					}
					$sources = sella_at_sources( $channel );
					sella_at_card(
						array(
							'name'        => 'routes[r' . md5( $key ) . ']',
							'title'       => $channel['label'],
							'meta'        => $meta,
							'route'       => $settings['routes'][ $key ] ?? array(),
							'groups'      => $groups,
							'sources'     => $sources,
							'fixed'       => $sources['שדות הטופס'] ?? array(),
							'targets'     => $targets,
							'kind'        => $channel['kind'],
							'with_group'  => true,
							'channel'     => $key,
							'empty_label' => 'לפי ברירת המחדל',
							'places'      => $channel['used'] ? $channel['places'] : array(),
							'unused'      => ! $channel['used'],
							'books'       => 'first_chapter' === $key ? $books : array(),
						)
					);
				}
				?>
				</div>
				<?php
			endforeach;
			?>

			<div class="sella-at-toolbar sella-at-toolbar--bottom">
				<?php submit_button( 'שמירת ההגדרות', 'primary', 'submit', false ); ?>
			</div>
		</form>

		<div class="sella-at-side">
			<form method="post" class="sella-at-box">
				<?php wp_nonce_field( 'sella_at_manage' ); ?>
				<input type="hidden" name="sella_at_form" value="test" />
				<h2>שליחת בדיקה</h2>
				<p class="description">שולח איש קשר בשם "בדיקה מהמסך" לפי מה ששמור לטופס שבוחרים. כך רואים שהטוקן, הקבוצה והשדות נכונים.</p>
				<p class="sella-at-inline">
					<input type="email" name="test_email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" dir="ltr" placeholder="מייל לבדיקה" />
					<select name="test_channel">
						<?php foreach ( array_filter( $channels, function ( $channel ) { return $channel['used']; } ) as $key => $channel ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $channel['label'] ); ?></option>
						<?php endforeach; ?>
						<?php foreach ( $books as $chapter_id => $book ) : ?>
							<option value="<?php echo esc_attr( 'first_chapter:book:' . $chapter_id ); ?>"><?php echo esc_html( 'פרק ראשון: ' . $book ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button">שליחה</button>
				</p>
			</form>

			<form method="post" class="sella-at-box">
				<?php wp_nonce_field( 'sella_at_manage' ); ?>
				<input type="hidden" name="sella_at_form" value="refresh" />
				<h2>הקבוצות והשדות</h2>
				<p class="description">נשמרים לשעה. אחרי שפותחים קבוצה חדשה או משנים שם של שדה באקטיב טרייל, טוענים מחדש.</p>
				<button type="submit" class="button">טעינה מחדש מאקטיב טרייל</button>
			</form>
		</div>
	</div>
	<?php
}
