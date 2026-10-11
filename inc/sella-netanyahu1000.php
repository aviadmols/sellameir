<?php
/**
 * The "מהדורת האלף - נתניהו" page template (templates/netanyahu1000.php):
 * its ACF fields, product lookup, photos, and stylesheet and script.
 *
 * Every field falls back to the original selameir.com/netanyahu1000 content
 * while it is empty, and the page works without ACF installed.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SELLA_N1000_TEMPLATE', 'templates/netanyahu1000.php' );

/**
 * The original content, used for every empty field.
 *
 * @return array
 */
function sella_n1000_defaults() {
	return array(
		'n1000_header_over_hero' => 1,
		'n1000_title'            => 'ביבי: סיפור חיי',
		'n1000_subtitle'         => 'מהדורת האלף',
		'n1000_text'             => '<p>פחות מחודשיים לאחר השקתו, הפך הספר ביבי: סיפור חיי לציון דרך בעולם הספרות והשיח הישראלי. באוטוביוגרפיה אישית וסוחפת פורס אחד המנהיגים הגדולים של העם היהודי בעת החדשה את נופי הילדות, האישים, הרעיונות, הטרגדיות והרגעים הגדולים שעיצבו את תפיסת עולמו ואת דרכו.</p><p>מהדורת האלף היא סדרה מוגבלת ומהודרת של ביבי: סיפור חיי, הכוללת 1,000 עותקים ממוספרים ויחודיים. מזכרת חגיגית למדף הספרים שנוצרה בכדי להישמר לדורות.</p>',
		'n1000_button_text'      => 'לרכישת הספר',
		'n1000_show_quote'       => 1,
		'n1000_quote'            => '"בנימין נתניהו הוא המנהיג הפוליטי המצליח בתולדות ישראל. בעוד דוד בן גוריון, אביה המייסד של המדינה, הקים וקבע את דרכה של המדינה בשנותיה הראשונות, הרי שנתניהו העלה אותה בבגרותה על דרך חדשה… בדומה למחבר שלה ולישראל של ימינו, האוטוביוגרפיה "ביבי:סיפור חיי" היא חיבור מלוטש, מנומק, מרתק וקצבי."',
		'n1000_quote_source'     => 'the wall street journal',
	);
}

/**
 * The number of gallery photo fields.
 */
define( 'SELLA_N1000_GALLERY_SLOTS', 8 );

/**
 * The theme's photos, shipped in assets/images/netanyahu1000/, for the
 * portrait, background, gallery and quote-logo fields while they are empty.
 *
 * @return array
 */
function sella_n1000_default_images() {
	return array(
		'portrait'   => 'bibi-1000-book.png',
		'background' => 'map-bg.jpg',
		'gallery'    => array( 'book-1000-1.jpg', 'book-1000-2.jpg', 'book-1000-3.jpg', 'book-1000-5.jpg', 'book-1000-6.jpg' ),
		'quote_logo' => 'wsj-wide.png',
	);
}

/**
 * Register the template's fields with ACF.
 */
function sella_n1000_register_fields() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$d = sella_n1000_defaults();

	$fields = array(
		array(
			'key'   => 'field_n1000_tab_hero',
			'label' => 'Hero',
			'type'  => 'tab',
		),
		array(
			'key'           => 'field_n1000_header_over_hero',
			'name'          => 'n1000_header_over_hero',
			'label'         => 'ה-header מעל ה-hero',
			'instructions'  => 'התפריט צף מעל רקע ה-hero, כמו באתר המקור. כבוי: ה-hero מתחיל מתחת לתפריט.',
			'type'          => 'true_false',
			'ui'            => 1,
			'default_value' => 1,
		),
		array(
			'key'           => 'field_n1000_portrait',
			'name'          => 'n1000_portrait',
			'label'         => 'תמונה ראשית',
			'instructions'  => 'ריק: התמונה של נתניהו עם הספר. מומלץ PNG עם רקע שקוף.',
			'type'          => 'image',
			'return_format' => 'id',
			'preview_size'  => 'medium',
			'wrapper'       => array( 'width' => '50' ),
		),
		array(
			'key'           => 'field_n1000_background',
			'name'          => 'n1000_background',
			'label'         => 'תמונת רקע',
			'instructions'  => 'ריק: המפה. מוצגת בשקיפות 20% מאחורי ה-hero.',
			'type'          => 'image',
			'return_format' => 'id',
			'preview_size'  => 'medium',
			'wrapper'       => array( 'width' => '50' ),
		),
		array(
			'key'         => 'field_n1000_title',
			'name'        => 'n1000_title',
			'label'       => 'כותרת',
			'type'        => 'text',
			'placeholder' => $d['n1000_title'],
			'wrapper'     => array( 'width' => '50' ),
		),
		array(
			'key'         => 'field_n1000_subtitle',
			'name'        => 'n1000_subtitle',
			'label'       => 'כותרת משנה',
			'type'        => 'text',
			'placeholder' => $d['n1000_subtitle'],
			'wrapper'     => array( 'width' => '50' ),
		),
		array(
			'key'          => 'field_n1000_text',
			'name'         => 'n1000_text',
			'label'        => 'טקסט',
			'instructions' => 'ריק: שתי הפסקאות של העמוד המקורי.',
			'type'         => 'wysiwyg',
			'tabs'         => 'all',
			'toolbar'      => 'basic',
			'media_upload' => 0,
		),
		array(
			'key'         => 'field_n1000_button_text',
			'name'        => 'n1000_button_text',
			'label'       => 'טקסט הכפתור',
			'type'        => 'text',
			'placeholder' => $d['n1000_button_text'],
			'wrapper'     => array( 'width' => '33' ),
		),
		array(
			'key'           => 'field_n1000_product',
			'name'          => 'n1000_product',
			'label'         => 'מוצר',
			'instructions'  => 'הכפתור מוסיף אותו לסל ועובר לעמוד הסל. ריק: המוצר הראשון שיש בשמו "מהדורת האלף".',
			'type'          => 'post_object',
			'post_type'     => array( 'product' ),
			'return_format' => 'id',
			'allow_null'    => 1,
			'ui'            => 1,
			'wrapper'       => array( 'width' => '33' ),
		),
		array(
			'key'          => 'field_n1000_price_text',
			'name'         => 'n1000_price_text',
			'label'        => 'טקסט מחיר',
			'instructions' => 'ריק: המחיר של המוצר.',
			'type'         => 'text',
			'placeholder'  => '1,850 ש"ח',
			'wrapper'      => array( 'width' => '33' ),
		),
		array(
			'key'   => 'field_n1000_tab_gallery',
			'label' => 'גלריה',
			'type'  => 'tab',
		),
		array(
			'key'     => 'field_n1000_gallery_note',
			'label'   => '',
			'type'    => 'message',
			'message' => 'התמונות מוצגות בשורה, לפי הסדר מימין לשמאל, ונפתחות בהגדלה בלחיצה. מומלץ יחס 4:3. כשכל השדות ריקים מוצגות 5 התמונות של העמוד המקורי.',
		),
	);

	for ( $i = 1; $i <= SELLA_N1000_GALLERY_SLOTS; $i++ ) {
		$fields[] = array(
			'key'           => 'field_n1000_gallery_' . $i,
			'name'          => 'n1000_gallery_' . $i,
			'label'         => 'תמונה ' . $i,
			'type'          => 'image',
			'return_format' => 'id',
			'preview_size'  => 'thumbnail',
			'wrapper'       => array( 'width' => '25' ),
		);
	}

	$fields = array_merge(
		$fields,
		array(
			array(
				'key'   => 'field_n1000_tab_quote',
				'label' => 'ציטוט',
				'type'  => 'tab',
			),
			array(
				'key'           => 'field_n1000_show_quote',
				'name'          => 'n1000_show_quote',
				'label'         => 'הצגת הציטוט',
				'type'          => 'true_false',
				'ui'            => 1,
				'default_value' => 1,
			),
			array(
				'key'         => 'field_n1000_quote',
				'name'        => 'n1000_quote',
				'label'       => 'הציטוט',
				'type'        => 'textarea',
				'rows'        => 4,
				'new_lines'   => 'br',
				'placeholder' => $d['n1000_quote'],
			),
			array(
				'key'           => 'field_n1000_quote_logo',
				'name'          => 'n1000_quote_logo',
				'label'         => 'לוגו המקור',
				'instructions'  => 'ריק: הלוגו של וול סטריט ג\'ורנל. מוצג לבן ברוחב 200px.',
				'type'          => 'image',
				'return_format' => 'id',
				'preview_size'  => 'thumbnail',
				'wrapper'       => array( 'width' => '50' ),
			),
			array(
				'key'          => 'field_n1000_quote_source',
				'name'         => 'n1000_quote_source',
				'label'        => 'שם המקור',
				'instructions' => 'טקסט חלופי ללוגו, לקוראי מסך.',
				'type'         => 'text',
				'placeholder'  => $d['n1000_quote_source'],
				'wrapper'      => array( 'width' => '50' ),
			),
		)
	);

	acf_add_local_field_group(
		array(
			'key'            => 'group_sella_n1000',
			'title'          => 'מהדורת האלף - תוכן העמוד',
			'fields'         => $fields,
			'location'       => array(
				array(
					array(
						'param'    => 'page_template',
						'operator' => '==',
						'value'    => SELLA_N1000_TEMPLATE,
					),
				),
			),
			'position'       => 'acf_after_title',
			'hide_on_screen' => array( 'the_content' ),
		)
	);
}
add_action( 'acf/init', 'sella_n1000_register_fields' );

/**
 * Fill a never-saved field with the original content, so the edit screen
 * shows the real texts and the media-library photos instead of empty
 * fields. Once the page is saved the stored values take over.
 *
 * @param mixed $value   Stored value.
 * @param mixed $post_id ACF post ID.
 * @param array $field   Field.
 * @return mixed
 */
function sella_n1000_prefill_field( $value, $post_id, $field ) {
	$name = isset( $field['name'] ) ? (string) $field['name'] : '';
	if ( 0 !== strpos( $name, 'n1000_' ) || ! is_numeric( $post_id ) || metadata_exists( 'post', (int) $post_id, $name ) ) {
		return $value;
	}

	$defaults = sella_n1000_defaults();
	if ( isset( $defaults[ $name ] ) ) {
		return $defaults[ $name ];
	}

	$images = sella_n1000_default_images();
	$files  = array(
		'n1000_portrait'   => $images['portrait'],
		'n1000_background' => $images['background'],
		'n1000_quote_logo' => $images['quote_logo'],
	);
	foreach ( $images['gallery'] as $i => $file ) {
		$files[ 'n1000_gallery_' . ( $i + 1 ) ] = $file;
	}

	$id = isset( $files[ $name ] ) ? sella_n1000_media_id( $files[ $name ] ) : 0;

	return $id ? $id : $value;
}
add_filter( 'acf/load_value', 'sella_n1000_prefill_field', 10, 3 );

/**
 * A field's value, or its default while it is empty or ACF is missing.
 * A switched-off toggle is a value, not an empty field.
 *
 * @param string $name    Field name.
 * @param int    $page_id Page ID.
 * @return mixed
 */
function sella_n1000_field( $name, $page_id ) {
	$defaults = sella_n1000_defaults();
	$default  = isset( $defaults[ $name ] ) ? $defaults[ $name ] : '';

	if ( ! function_exists( 'get_field' ) || ! metadata_exists( 'post', $page_id, $name ) ) {
		return $default;
	}

	$value = get_field( $name, $page_id );

	if ( is_bool( $value ) ) {
		return $value;
	}

	return ( null === $value || '' === $value || false === $value ) ? $default : $value;
}

/**
 * An image field: its attachment ID, or the theme file it falls back to.
 *
 * @param string $name    Field name.
 * @param string $file    Fallback file in assets/images/netanyahu1000/.
 * @param int    $page_id Page ID.
 * @return int|string
 */
function sella_n1000_image( $name, $file, $page_id ) {
	$id = function_exists( 'get_field' ) ? absint( get_field( $name, $page_id ) ) : 0;

	return $id && wp_attachment_is_image( $id ) ? $id : $file;
}

/**
 * The gallery photos: the filled gallery fields, or the original five.
 *
 * @param int $page_id Page ID.
 * @return array Attachment IDs or theme file names.
 */
function sella_n1000_gallery( $page_id ) {
	$photos = array();

	if ( function_exists( 'get_field' ) ) {
		for ( $i = 1; $i <= SELLA_N1000_GALLERY_SLOTS; $i++ ) {
			$id = absint( get_field( 'n1000_gallery_' . $i, $page_id ) );
			if ( $id && wp_attachment_is_image( $id ) ) {
				$photos[] = $id;
			}
		}
	}

	if ( ! $photos ) {
		$photos = sella_n1000_default_images()['gallery'];
	}

	return $photos;
}

/**
 * The product the page's buy button adds to the cart: the "מוצר" field, the
 * older "sella_product_id" custom field, or the first product with
 * "מהדורת האלף" in its title.
 *
 * @param int $page_id Page ID.
 * @return WC_Product|null
 */
function sella_n1000_product( $page_id ) {
	$product_id = function_exists( 'get_field' ) ? absint( get_field( 'n1000_product', $page_id ) ) : 0;

	if ( ! $product_id ) {
		$product_id = absint( get_post_meta( $page_id, 'sella_product_id', true ) );
	}

	if ( ! $product_id ) {
		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				's'              => 'מהדורת האלף',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$product_id = $found ? (int) $found[0] : 0;
	}

	$product = $product_id ? wc_get_product( $product_id ) : null;

	return $product ? $product : null;
}

/**
 * The price line: the "טקסט מחיר" field, or the product's price with no
 * decimals when it is whole ("1,850 ש"ח").
 *
 * @param WC_Product|null $product Product.
 * @param int             $page_id Page ID.
 * @return string
 */
function sella_n1000_price_text( $product, $page_id ) {
	$text = function_exists( 'get_field' ) ? trim( (string) get_field( 'n1000_price_text', $page_id ) ) : '';
	if ( '' !== $text ) {
		return $text;
	}

	// Tax display can leave 1850.0000001 behind, so round before testing for a whole number.
	$price = $product ? round( (float) wc_get_price_to_display( $product ), 2 ) : 1850.0;

	return number_format( $price, floor( $price ) == $price ? 0 : 2 ) . ' ש"ח'; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
}

/**
 * The template's photos that are copied into the media library. The WSJ
 * logo goes in as a PNG (wsj-wide.png), since the media library refuses SVG;
 * the SVG stays the theme fallback.
 *
 * @return string[]
 */
function sella_n1000_media_files() {
	$images = sella_n1000_default_images();

	return array_merge( array( $images['portrait'], $images['background'], $images['quote_logo'] ), $images['gallery'] );
}

/**
 * Copy the photos into the media library, once, the first time an admin
 * opens the dashboard. The attachment IDs are kept in an option, file name
 * => ID; a file that fails is retried on the next dashboard visit.
 */
function sella_n1000_import_media() {
	if ( ! current_user_can( 'upload_files' ) || wp_doing_ajax() ) {
		return;
	}

	$ids     = (array) get_option( 'sella_n1000_media', array() );
	$missing = array_diff( sella_n1000_media_files(), array_keys( array_filter( $ids, 'wp_attachment_is_image' ) ) );
	if ( ! $missing ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$dir = get_stylesheet_directory() . '/assets/images/netanyahu1000/';
	foreach ( $missing as $file ) {
		$bits = is_readable( $dir . $file ) ? file_get_contents( $dir . $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bits ) {
			continue;
		}

		$upload = wp_upload_bits( 'netanyahu1000-' . $file, null, $bits );
		if ( ! empty( $upload['error'] ) ) {
			continue;
		}

		$id = wp_insert_attachment(
			array(
				'post_title'     => 'מהדורת האלף - ' . pathinfo( $file, PATHINFO_FILENAME ),
				'post_mime_type' => wp_check_filetype( $upload['file'] )['type'],
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			continue;
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		$ids[ $file ] = $id;
		update_option( 'sella_n1000_media', $ids, false );
	}
}
add_action( 'admin_init', 'sella_n1000_import_media' );

/**
 * The attachment ID for a photo: an ID passes through, a theme file name
 * gives its media-library copy, or 0 before the import ran.
 *
 * @param int|string $src Attachment ID or file name in assets/images/netanyahu1000/.
 * @return int
 */
function sella_n1000_media_id( $src ) {
	if ( is_int( $src ) ) {
		return $src;
	}

	$ids = (array) get_option( 'sella_n1000_media', array() );
	$id  = isset( $ids[ $src ] ) ? (int) $ids[ $src ] : 0;

	return $id && wp_attachment_is_image( $id ) ? $id : 0;
}

/**
 * src, srcset, sizes, width and height attributes for a photo.
 *
 * @param int|string $src   Attachment ID or file name in assets/images/netanyahu1000/.
 * @param string     $sizes The sizes attribute.
 * @return string Escaped attributes.
 */
function sella_n1000_img_attrs( $src, $sizes ) {
	$id = sella_n1000_media_id( $src );
	if ( ! $id ) {
		$path = get_stylesheet_directory() . '/assets/images/netanyahu1000/' . $src;
		$dims = is_readable( $path ) ? wp_getimagesize( $path ) : false;
		$wh   = $dims ? ' width="' . (int) $dims[0] . '" height="' . (int) $dims[1] . '"' : '';

		return 'src="' . esc_url( get_stylesheet_directory_uri() . '/assets/images/netanyahu1000/' . $src ) . '"' . $wh;
	}

	$full  = wp_get_attachment_image_src( $id, 'full' );
	$attrs = 'src="' . esc_url( $full[0] ) . '"';
	if ( ! empty( $full[1] ) ) {
		$attrs .= ' width="' . (int) $full[1] . '" height="' . (int) $full[2] . '"';
	}

	$srcset = wp_get_attachment_image_srcset( $id, 'full' );
	if ( $srcset ) {
		$attrs .= ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( $sizes ) . '"';
	}

	return $attrs;
}

/**
 * A photo's full-size URL, for the lightbox.
 *
 * @param int|string $src Attachment ID or file name in assets/images/netanyahu1000/.
 * @return string
 */
function sella_n1000_img_url( $src ) {
	$id = sella_n1000_media_id( $src );

	return $id ? wp_get_attachment_image_url( $id, 'full' ) : get_stylesheet_directory_uri() . '/assets/images/netanyahu1000/' . $src;
}

/**
 * Mark the body when the header floats over the hero.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function sella_n1000_body_class( $classes ) {
	if ( is_page_template( SELLA_N1000_TEMPLATE ) && sella_n1000_field( 'n1000_header_over_hero', get_queried_object_id() ) ) {
		$classes[] = 'sella-n1000-header-over';
	}

	return $classes;
}
add_filter( 'body_class', 'sella_n1000_body_class' );

/**
 * Load the template's stylesheet and script on pages that use it. They are versioned
 * by modification time, so a deploy reaches browsers that cached the old files.
 */
function sella_n1000_assets() {
	if ( ! is_page_template( SELLA_N1000_TEMPLATE ) ) {
		return;
	}

	wp_enqueue_style(
		'sella-netanyahu1000',
		get_stylesheet_directory_uri() . '/assets/css/sella-netanyahu1000.css',
		array( 'hello-elementor-child-style' ),
		(string) filemtime( get_stylesheet_directory() . '/assets/css/sella-netanyahu1000.css' )
	);

	wp_enqueue_script(
		'sella-netanyahu1000',
		get_stylesheet_directory_uri() . '/assets/js/sella-netanyahu1000.js',
		array(),
		(string) filemtime( get_stylesheet_directory() . '/assets/js/sella-netanyahu1000.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'sella_n1000_assets', 20 );
