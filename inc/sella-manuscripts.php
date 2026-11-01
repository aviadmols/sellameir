<?php
/**
 * The manuscript submission page: /הגשת-כתבי-יד/.
 *
 * The first time an admin opens the dashboard after this ships, the page is
 * made from the text below, as ordinary block content, so it is edited in the
 * page editor from then on. A page whose address is taken is left as it is, and
 * a page deleted later is not made again.
 *
 * The design is in assets/css/sella-manuscripts.css, by the class names here.
 * The heading is the page's own, so the theme's title above it is hidden.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_MANUSCRIPTS_OPTION', 'sella_manuscripts_page' );
define( 'SELLA_MANUSCRIPTS_SLUG', 'הגשת-כתבי-יד' );
define( 'SELLA_MANUSCRIPTS_EMAIL', 'desk@sellameir.com' );

/**
 * A new message to the address, in Gmail, as the footer's email link opens.
 *
 * @return string
 */
function sella_manuscripts_mail_url() {
	return sella_fl_gmail_url( SELLA_MANUSCRIPTS_EMAIL . '?subject=' . rawurlencode( 'הגשת כתב־יד' ) );
}

/**
 * A paragraph block.
 *
 * @param string $class Class name.
 * @param string $html  Its text, as HTML.
 * @return string
 */
function sella_manuscripts_paragraph( $class, $html ) {
	return '<!-- wp:paragraph {"className":"' . $class . '"} -->' . "\n" . '<p class="' . $class . '">' . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
}

/**
 * A heading block.
 *
 * @param int    $level Heading level.
 * @param string $class Class name.
 * @param string $html  Its text, as HTML.
 * @return string
 */
function sella_manuscripts_heading( $level, $class, $html ) {
	return '<!-- wp:heading {"level":' . $level . ',"className":"' . $class . '"} -->' . "\n" . '<h' . $level . ' class="wp-block-heading ' . $class . '">' . $html . '</h' . $level . ">\n<!-- /wp:heading -->\n\n";
}

/**
 * A group block around other blocks.
 *
 * @param string $tag   Element: div or section.
 * @param string $class Class name.
 * @param string $inner The blocks inside.
 * @return string
 */
function sella_manuscripts_group( $tag, $class, $inner ) {
	$attrs = 'section' === $tag ? '"tagName":"section",' : '';
	return '<!-- wp:group {' . $attrs . '"className":"' . $class . '","layout":{"type":"default"}} -->' . "\n"
		. '<' . $tag . ' class="wp-block-group ' . $class . '">' . $inner . '</' . $tag . ">\n<!-- /wp:group -->\n\n";
}

/**
 * The page's content.
 *
 * @return string
 */
function sella_manuscripts_content() {
	$url  = esc_url( sella_manuscripts_mail_url() );
	$link = 'href="' . $url . '" target="_blank" rel="noopener"';

	$items = array(
		'תקציר של הספר, שאורכו לא יעלה על עמוד.',
		'פירוט על נושא הספר.',
		'שלושה פרקים מתוך הספר: פרק ראשון, אחרון ופרק נוסף שאותו יבחר הכותב.',
	);
	$list = '';
	foreach ( $items as $item ) {
		$list .= "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->\n\n";
	}

	$hero = sella_manuscripts_heading( 1, 'sella-ms__title', 'הגשת<br><strong>כתבי־יד</strong>' )
		. sella_manuscripts_paragraph( 'sella-ms__lead', 'הוצאת סלע מאיר שמחה לקבל כתבי־יד, לעיין בהם ולשקול את הוצאתם לאור.' );

	$about = sella_manuscripts_paragraph( 'sella-ms__note', 'שימו לב שאנו הוצאה לאור בעיקר של <strong>ספרי הגות ועיון</strong>, ועל כן, לא רלוונטי להגיש ספרי שירה ופרוזה.' )
		. sella_manuscripts_paragraph( 'sella-ms__note', 'כל כתב יד נבדק בקפידה רבה בשני מישורים: <strong>איכות כתב היד</strong> והתאמתו <strong>לרוח ההוצאה</strong>.' );

	$step = sella_manuscripts_paragraph( 'sella-ms__intro', 'אם ברצונכם לשלוח לנו כתב־יד, אנא קראו בעיון את ההנחיות הבאות:' )
		. sella_manuscripts_heading( 2, 'sella-ms__step', 'שלב ראשון – פרטים וקבצים בדוא"ל' )
		. sella_manuscripts_paragraph( 'sella-ms__to', 'יש לשלוח דוא"ל לכתובת:' )
		. sella_manuscripts_paragraph( 'sella-ms__email', '<a ' . $link . ' dir="ltr">' . SELLA_MANUSCRIPTS_EMAIL . '</a>' )
		. sella_manuscripts_paragraph( 'sella-ms__to', 'עם הפרטים הבאים:' )
		. '<!-- wp:list {"ordered":true,"className":"sella-ms__items"} -->' . "\n" . '<ol class="wp-block-list sella-ms__items">' . $list . "</ol>\n<!-- /wp:list -->\n\n"
		. sella_manuscripts_paragraph( 'sella-ms__full', 'ניתן לצרף גם את כתב היד המלא.' );

	$more = sella_manuscripts_paragraph( 'sella-ms__extra', 'ניתן לצרף גם תיאור קצר של כתב היד ושל המחבר ופרטים ביוגרפיים חשובים.' )
		. sella_manuscripts_paragraph( 'sella-ms__extra', 'במקרה של מחברים שספריהם כבר ראו אור, ניתן לצרף כל חומר רלוונטי, דוגמת שם הספרים הקודמים, באיזו הוצאה ראו אור, מתי, ביקורות ופרסומים על הספר וכד\'.' );

	$end = sella_manuscripts_paragraph( 'sella-ms__next', 'משם הספר יעבור ללקטור שלנו להמשך התהליך.' )
		. sella_manuscripts_paragraph( 'sella-ms__luck', 'בהצלחה!' )
		. sella_manuscripts_paragraph( 'sella-ms__action', '<a class="sella-ms__button" ' . $link . '>שליחת כתב־יד בדוא"ל</a>' );

	return sella_manuscripts_group(
		'div',
		'sella-ms',
		sella_manuscripts_group( 'section', 'sella-ms__hero', $hero )
			. sella_manuscripts_group( 'section', 'sella-ms__about', $about )
			. sella_manuscripts_group( 'section', 'sella-ms__steps', $step )
			. sella_manuscripts_group( 'section', 'sella-ms__more', $more )
			. sella_manuscripts_group( 'section', 'sella-ms__end', $end )
	);
}

/**
 * Make the page, once.
 *
 * The option keeps the page's ID, or 0 for an address already used by someone
 * else's published page, which is left alone.
 */
function sella_manuscripts_first_run() {
	if ( false !== get_option( SELLA_MANUSCRIPTS_OPTION ) || ! current_user_can( 'publish_pages' ) || wp_doing_ajax() ) {
		return;
	}

	$existing = get_page_by_path( SELLA_MANUSCRIPTS_SLUG, OBJECT, 'page' );
	if ( $existing && 'publish' === $existing->post_status ) {
		update_option( SELLA_MANUSCRIPTS_OPTION, 0, false );
		return;
	}

	$post = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'הגשת כתבי־יד',
		'post_name'    => SELLA_MANUSCRIPTS_SLUG,
		'post_content' => sella_manuscripts_content(),
	);
	$id   = $existing
		? wp_update_post( wp_slash( array( 'ID' => (int) $existing->ID ) + $post ), true )
		: wp_insert_post( wp_slash( $post ), true );

	if ( ! is_wp_error( $id ) ) {
		update_option( SELLA_MANUSCRIPTS_OPTION, (int) $id, false );
	}
}
add_action( 'admin_init', 'sella_manuscripts_first_run' );

/**
 * Whether this is the page.
 *
 * @return bool
 */
function sella_manuscripts_is_page() {
	$id = (int) get_option( SELLA_MANUSCRIPTS_OPTION );
	return $id && is_page( $id );
}

/**
 * The page's CSS.
 */
function sella_manuscripts_enqueue() {
	if ( ! sella_manuscripts_is_page() ) {
		return;
	}
	wp_enqueue_style( 'sella-manuscripts', get_stylesheet_directory_uri() . '/assets/css/sella-manuscripts.css', array(), (string) filemtime( get_stylesheet_directory() . '/assets/css/sella-manuscripts.css' ) );
}
add_action( 'wp_enqueue_scripts', 'sella_manuscripts_enqueue', 30 );

/**
 * The heading is in the page, so the theme's title is hidden.
 *
 * @param bool $show Whether Hello Elementor prints the page title.
 * @return bool
 */
function sella_manuscripts_page_title( $show ) {
	return sella_manuscripts_is_page() ? false : $show;
}
add_filter( 'hello_elementor_page_title', 'sella_manuscripts_page_title' );
