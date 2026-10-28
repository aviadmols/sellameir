<?php
/**
 * The short terms and privacy pages: /terms-of-use/ and /privacy-policy/.
 *
 * The first time an admin opens the dashboard after this ships, the two pages
 * are made from the texts below, as ordinary block content, so they are edited
 * in the page editor from then on. A page whose address is taken is left as it
 * is (and keeps its theme title), and a page deleted later is not made again. The older, longer pages
 * (/terms/ and /privacy/) are not touched.
 *
 * The pages show as a white card (style.css, .sella-legal) whose heading is
 * the page's own, so the theme's title above it is hidden.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_LEGAL_OPTION', 'sella_legal_pages' );

/**
 * The pages, privacy first so the terms can link to it.
 *
 * Each section has a heading and either a paragraph ('text') or a numbered
 * list ('items'). {privacy} is replaced with the privacy page's address.
 *
 * @return array<string, array>
 */
function sella_legal_pages() {
	return array(
		'privacy' => array(
			'slug'     => 'privacy-policy',
			'title'    => 'מדיניות פרטיות',
			'heading'  => 'מדיניות פרטיות – אתר סלע מאיר',
			'intro'    => '',
			'sections' => array(
				array(
					'heading' => '1. כללי',
					'text'    => 'סלע מאיר מחויבת לשמירה על פרטיות המשתמשים באתר. להלן הסבר על אופן השימוש במידע שנאסף במסגרת השימוש באתר.',
				),
				array(
					'heading' => '2. מידע שנאסף',
					'items'   => array(
						'מידע אישי שמתקבל בעת רכישה או הרשמה – כגון שם, כתובת, דוא"ל וטלפון.',
						'מידע טכני – כתובת IP, סוג הדפדפן, דפי האתר שנצפו ועוד, לצרכי אבטחה ושיפור השירות.',
					),
				),
				array(
					'heading' => '3. שימוש במידע',
					'items'   => array(
						'אנו משתמשים במידע לצורך תפעול האתר, טיפול בהזמנות, שירות לקוחות, ושליחת עדכונים או מבצעים – בכפוף להסכמה.',
						'לא נעביר מידע אישי לגורמים שלישיים אלא לצורך אספקת השירותים או אם נידרש לכך לפי חוק.',
					),
				),
				array(
					'heading' => '4. אבטחת מידע',
					'items'   => array(
						'האתר מיישם אמצעי אבטחה מקובלים לצורך שמירה על סודיות המידע.',
						'עם זאת, אין באבטחה כדי להבטיח חסינות מוחלטת, והמשתמש מצהיר כי הוא מודע לכך.',
					),
				),
				array(
					'heading' => '5. עוגיות (Cookies)',
					'text'    => 'האתר עשוי להשתמש בקובצי עוגיות לצורך שיפור חווית המשתמש, הפקת נתונים סטטיסטיים והתאמת תוכן פרסומי.',
				),
				array(
					'heading' => '6. פנייה בנושא פרטיות',
					'text'    => 'לכל שאלה או בקשה בנוגע לפרטיות ניתן לפנות לכתובת: <a href="mailto:desk@sellameir.com">desk@sellameir.com</a>.',
				),
			),
		),
		'terms'   => array(
			'slug'     => 'terms-of-use',
			'title'    => 'תקנון',
			'heading'  => 'ברוכים הבאים לאתר סלע מאיר (להלן: "האתר")',
			'intro'    => 'השימוש באתר ובשירותים המוצעים בו מהווה הסכמה לתנאים המפורטים להלן. אנא קראו את התנאים בעיון.',
			'sections' => array(
				array(
					'heading' => '1. כללי',
					'items'   => array(
						'האתר משמש כחנות מקוונת לרכישת ספרים, מוצרים נלווים ותכנים נוספים.',
						'השימוש באתר כפוף לתנאי שימוש אלה ול<a href="{privacy}">מדיניות הפרטיות</a> המהווים חלק בלתי נפרד מהם.',
						'הנהלת האתר רשאית לעדכן תנאים אלו מעת לעת.',
					),
				),
				array(
					'heading' => '2. רכישת מוצרים',
					'items'   => array(
						'רכישה באתר מותנית במסירת פרטים נכונים ומלאים בעת ההזמנה.',
						'לאחר ביצוע הזמנה תקבלו אישור בדוא"ל. ביצוע ההזמנה מהווה הצעה לרכישת המוצר, והאישור מהווה קבלת ההזמנה על ידי האתר.',
						'משלוחים יתבצעו לפי זמני האספקה שיפורסמו באתר.',
					),
				),
				array(
					'heading' => '3. ביטולים והחזרות',
					'items'   => array(
						'ניתן לבטל עסקה בהתאם לחוק הגנת הצרכן.',
						'ביטול ייעשה בהודעה בכתב לשירות הלקוחות ובליווי פרטי ההזמנה.',
						'במקרים של החזרת מוצרים, עליהם להיות באריזתם המקורית וללא שימוש.',
					),
				),
				array(
					'heading' => '4. זכויות יוצרים',
					'items'   => array(
						'כל זכויות היוצרים באתר, כולל טקסטים, תמונות, עיצובים ותכנים אחרים – שייכות לסלע מאיר או לצדדים שלישיים שקיבלו רשות לפרסמם.',
						'אין להעתיק, לשכפל, להפיץ או לעשות שימוש מסחרי אחר בתכנים ללא אישור מראש ובכתב.',
					),
				),
				array(
					'heading' => '5. אחריות',
					'items'   => array(
						'המידע והתכנים באתר ניתנים כפי שהם (AS-IS).',
						'האתר לא יהיה אחראי לכל נזק ישיר או עקיף שייגרם משימוש באתר או מהסתמכות על תכניו.',
						'האתר שומר לעצמו את הזכות להפסיק את פעילותו או לשנות את השירותים בכל עת.',
					),
				),
			),
		),
	);
}

/**
 * A page's content as blocks, inside the card group.
 *
 * @param array  $page        One of sella_legal_pages().
 * @param string $privacy_url The privacy page's address.
 * @return string
 */
function sella_legal_content( $page, $privacy_url ) {
	$paragraph = function ( $html ) {
		return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
	};

	$out  = "<!-- wp:group {\"className\":\"sella-legal\",\"layout\":{\"type\":\"default\"}} -->\n<div class=\"wp-block-group sella-legal\">";
	$out .= "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">" . esc_html( $page['heading'] ) . "</h1>\n<!-- /wp:heading -->\n\n";
	if ( '' !== $page['intro'] ) {
		$out .= $paragraph( esc_html( $page['intro'] ) );
	}

	foreach ( $page['sections'] as $section ) {
		$out .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $section['heading'] ) . "</h2>\n<!-- /wp:heading -->\n\n";
		if ( isset( $section['text'] ) ) {
			$out .= $paragraph( $section['text'] );
			continue;
		}
		$out .= "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">";
		foreach ( $section['items'] as $item ) {
			$out .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->\n\n";
		}
		$out .= "</ol>\n<!-- /wp:list -->\n\n";
	}

	$out .= "</div>\n<!-- /wp:group -->";
	return str_replace( '{privacy}', esc_url( $privacy_url ), $out );
}

/**
 * Make the pages, once each.
 *
 * The option keeps each page's ID: the page made here (or taken over), or 0
 * for an address already used by someone else's published page, which is
 * left alone. A page is made until it has an entry, so a later run makes
 * the one an earlier run could not.
 *
 * An unpublished page on the address (WordPress's own draft privacy page,
 * which every install has at /privacy-policy/) becomes this one: its text is
 * replaced and it is published.
 */
function sella_legal_first_run() {
	$ids = get_option( SELLA_LEGAL_OPTION );
	$ids = is_array( $ids ) ? $ids : array();
	if ( isset( $ids['privacy'], $ids['terms'] ) || ! current_user_can( 'publish_pages' ) || wp_doing_ajax() ) {
		return;
	}

	$privacy_url = home_url( '/privacy-policy/' );
	foreach ( sella_legal_pages() as $key => $page ) {
		if ( isset( $ids[ $key ] ) ) {
			if ( 'privacy' === $key && $ids[ $key ] ) {
				$privacy_url = get_permalink( $ids[ $key ] );
			}
			continue;
		}

		$post     = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $page['title'],
			'post_name'    => $page['slug'],
			'post_content' => sella_legal_content( $page, $privacy_url ),
		);
		$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
		if ( $existing && 'publish' === $existing->post_status ) {
			$ids[ $key ] = 0; // Someone else's page: not ours to change.
			$id          = (int) $existing->ID;
		} else {
			$id = $existing
				? wp_update_post( array( 'ID' => (int) $existing->ID ) + $post, true )
				: wp_insert_post( $post, true );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			$ids[ $key ] = (int) $id;
		}
		if ( 'privacy' === $key ) {
			$privacy_url = get_permalink( $id );
		}
	}

	update_option( SELLA_LEGAL_OPTION, $ids, false );
}
add_action( 'admin_init', 'sella_legal_first_run' );

/**
 * Whether this is one of the two pages.
 *
 * @return bool
 */
function sella_legal_is_page() {
	$ids = get_option( SELLA_LEGAL_OPTION );
	$ids = is_array( $ids ) ? array_filter( array_map( 'intval', $ids ) ) : array();
	return $ids && is_page( array_values( $ids ) );
}

/**
 * The heading is in the card, so the theme's title is hidden.
 *
 * @param bool $show Whether Hello Elementor prints the page title.
 * @return bool
 */
function sella_legal_page_title( $show ) {
	return sella_legal_is_page() ? false : $show;
}
add_filter( 'hello_elementor_page_title', 'sella_legal_page_title' );
