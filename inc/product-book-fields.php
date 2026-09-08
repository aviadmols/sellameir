<?php
/**
 * Custom book product fields for WooCommerce.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cover type choices (single select).
 *
 * @return array<string, string>
 */
function sella_book_cover_types() {
	$types = array(
		''            => '— בחרו —',
		'matte'       => 'מט',
		'gloss'       => 'מבריק',
		'lamination'  => 'למינציה',
		'softcover'   => 'כריכה רכה',
		'hardcover'   => 'כריכה קשה',
		'other'       => 'אחר',
	);

	return apply_filters( 'sella_book_cover_types', $types );
}

/**
 * Register product data tab.
 *
 * @param array $tabs Tabs.
 * @return array
 */
function sella_book_product_data_tab( $tabs ) {
	$tabs['sella_book'] = array(
		'label'    => 'פרטי ספר',
		'target'   => 'sella_book_product_data',
		'class'    => array( 'show_if_simple', 'show_if_variable' ),
		'priority' => 21,
	);

	return $tabs;
}
add_filter( 'woocommerce_product_data_tabs', 'sella_book_product_data_tab' );

/**
 * Render product data panel.
 */
function sella_book_product_data_panel() {
	global $post;

	$product_id = $post ? (int) $post->ID : 0;

	$length       = get_post_meta( $product_id, '_sella_book_length_cm', true );
	$width        = get_post_meta( $product_id, '_sella_book_width_cm', true );
	$is_color     = get_post_meta( $product_id, '_sella_is_color', true );
	$color_pages  = get_post_meta( $product_id, '_sella_color_pages', true );
	$corrections  = get_post_meta( $product_id, '_sella_corrections', true );
	$barcode      = get_post_meta( $product_id, '_sella_barcode', true );
	$barcode_img  = (int) get_post_meta( $product_id, '_sella_barcode_image', true );
	$danacode     = get_post_meta( $product_id, '_sella_danacode', true );
	$cover_type   = get_post_meta( $product_id, '_sella_cover_type', true );
	$paper_weight = get_post_meta( $product_id, '_sella_paper_weight', true );
	$to_author    = get_post_meta( $product_id, '_sella_books_to_author', true );
	$taken        = get_post_meta( $product_id, '_sella_books_taken', true );
	$page_count   = get_post_meta( $product_id, '_sella_page_count', true );

	if ( ! is_array( $color_pages ) ) {
		$color_pages = array();
	}
	if ( ! is_array( $corrections ) ) {
		$corrections = array();
	}

	$barcode_url = $barcode_img ? wp_get_attachment_image_url( $barcode_img, 'medium' ) : '';

	wp_nonce_field( 'sella_book_fields_save', 'sella_book_fields_nonce' );
	?>
	<div id="sella_book_product_data" class="panel woocommerce_options_panel sella-book-panel" dir="rtl">
		<div class="options_group">
			<p class="form-field">
				<label>גודל ספר (ס״מ)</label>
				<span class="sella-inline-pair">
					<input type="number" step="0.1" min="0" name="_sella_book_length_cm" value="<?php echo esc_attr( $length ); ?>" placeholder="אורך" />
					<span>×</span>
					<input type="number" step="0.1" min="0" name="_sella_book_width_cm" value="<?php echo esc_attr( $width ); ?>" placeholder="רוחב" />
				</span>
			</p>

			<?php
			woocommerce_wp_select(
				array(
					'id'      => '_sella_is_color',
					'label'   => 'האם בצבע',
					'options' => array(
						''    => '— בחרו —',
						'yes' => 'כן',
						'no'  => 'לא',
					),
					'value'   => $is_color,
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => '_sella_page_count',
					'label'             => 'מספר עמודים של הספר',
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => '1',
						'min'  => '0',
					),
					'value'             => $page_count,
				)
			);
			?>
		</div>

		<div class="options_group">
			<p class="form-field sella-repeater-heading">
				<label>מספרי עמודים בצבע</label>
				<span class="description">הוסיפו שורות: מספר + עמודים</span>
			</p>
			<div class="sella-repeater" data-repeater="color_pages">
				<div class="sella-repeater-rows">
					<?php
					if ( empty( $color_pages ) ) {
						$color_pages = array( array( 'number' => '', 'pages' => '' ) );
					}
					foreach ( $color_pages as $i => $row ) :
						?>
						<div class="sella-repeater-row">
							<input type="text" name="_sella_color_pages[<?php echo (int) $i; ?>][number]" value="<?php echo esc_attr( $row['number'] ?? '' ); ?>" placeholder="מספר" />
							<input type="text" name="_sella_color_pages[<?php echo (int) $i; ?>][pages]" value="<?php echo esc_attr( $row['pages'] ?? '' ); ?>" placeholder="עמודים" />
							<button type="button" class="button sella-remove-row" aria-label="הסר שורה">&times;</button>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button sella-add-row">+ הוסף שורה</button>
				<template class="sella-row-template">
					<div class="sella-repeater-row">
						<input type="text" name="_sella_color_pages[__i__][number]" value="" placeholder="מספר" />
						<input type="text" name="_sella_color_pages[__i__][pages]" value="" placeholder="עמודים" />
						<button type="button" class="button sella-remove-row" aria-label="הסר שורה">&times;</button>
					</div>
				</template>
			</div>
		</div>

		<div class="options_group">
			<p class="form-field sella-repeater-heading">
				<label>תיקונים בקנה</label>
				<span class="description">הוסיפו שורות: מספר + מה התיקון</span>
			</p>
			<div class="sella-repeater" data-repeater="corrections">
				<div class="sella-repeater-rows">
					<?php
					if ( empty( $corrections ) ) {
						$corrections = array( array( 'number' => '', 'text' => '' ) );
					}
					foreach ( $corrections as $i => $row ) :
						?>
						<div class="sella-repeater-row">
							<input type="text" name="_sella_corrections[<?php echo (int) $i; ?>][number]" value="<?php echo esc_attr( $row['number'] ?? '' ); ?>" placeholder="מספר" />
							<input type="text" name="_sella_corrections[<?php echo (int) $i; ?>][text]" value="<?php echo esc_attr( $row['text'] ?? '' ); ?>" placeholder="מה התיקון" class="sella-wide" />
							<button type="button" class="button sella-remove-row" aria-label="הסר שורה">&times;</button>
						</div>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button sella-add-row">+ הוסף שורה</button>
				<template class="sella-row-template">
					<div class="sella-repeater-row">
						<input type="text" name="_sella_corrections[__i__][number]" value="" placeholder="מספר" />
						<input type="text" name="_sella_corrections[__i__][text]" value="" placeholder="מה התיקון" class="sella-wide" />
						<button type="button" class="button sella-remove-row" aria-label="הסר שורה">&times;</button>
					</div>
				</template>
			</div>
		</div>

		<div class="options_group">
			<?php
			woocommerce_wp_text_input(
				array(
					'id'    => '_sella_barcode',
					'label' => 'ברקוד (מספר)',
					'value' => $barcode,
				)
			);
			?>
			<p class="form-field sella-barcode-image-field">
				<label>תמונת ברקוד</label>
				<input type="hidden" id="_sella_barcode_image" name="_sella_barcode_image" value="<?php echo esc_attr( $barcode_img ? (string) $barcode_img : '' ); ?>" />
				<span class="sella-media-preview">
					<?php if ( $barcode_url ) : ?>
						<img src="<?php echo esc_url( $barcode_url ); ?>" alt="" />
					<?php endif; ?>
				</span>
				<button type="button" class="button sella-upload-image"><?php echo $barcode_img ? 'החלף תמונה' : 'העלה / בחר תמונה'; ?></button>
				<button type="button" class="button sella-remove-image" <?php echo $barcode_img ? '' : 'style="display:none;"'; ?>>הסר תמונה</button>
			</p>

			<?php
			woocommerce_wp_text_input(
				array(
					'id'    => '_sella_danacode',
					'label' => 'דאנאקוד',
					'value' => $danacode,
				)
			);

			woocommerce_wp_select(
				array(
					'id'      => '_sella_cover_type',
					'label'   => 'סוג כריכה',
					'options' => sella_book_cover_types(),
					'value'   => $cover_type,
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => '_sella_paper_weight',
					'label'             => 'משקל נייר (גרם)',
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => '1',
						'min'  => '0',
					),
					'value'             => $paper_weight,
				)
			);
			?>
		</div>

		<div class="options_group">
			<?php
			woocommerce_wp_text_input(
				array(
					'id'                => '_sella_books_to_author',
					'label'             => 'כמה ספרים מגיעים לסופר',
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => '1',
						'min'  => '0',
					),
					'value'             => $to_author,
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => '_sella_books_taken',
					'label'             => 'כמה נלקחו כבר',
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => '1',
						'min'  => '0',
					),
					'value'             => $taken,
				)
			);
			?>
		</div>
	</div>
	<?php
}
add_action( 'woocommerce_product_data_panels', 'sella_book_product_data_panel' );

/**
 * Enqueue admin assets on product edit screens.
 *
 * @param string $hook Hook.
 */
function sella_book_admin_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'product' !== $screen->post_type ) {
		return;
	}

	wp_enqueue_media();

	wp_enqueue_style(
		'sella-book-fields-admin',
		get_stylesheet_directory_uri() . '/assets/css/product-book-fields-admin.css',
		array(),
		HELLO_ELEMENTOR_CHILD_VERSION
	);

	wp_enqueue_script(
		'sella-book-fields-admin',
		get_stylesheet_directory_uri() . '/assets/js/product-book-fields-admin.js',
		array( 'jquery' ),
		HELLO_ELEMENTOR_CHILD_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'sella_book_admin_assets' );

/**
 * Save product fields.
 *
 * @param int $post_id Product ID.
 */
function sella_book_save_product_fields( $post_id ) {
	if ( ! isset( $_POST['sella_book_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sella_book_fields_nonce'] ) ), 'sella_book_fields_save' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_product', $post_id ) ) {
		return;
	}

	$product = wc_get_product( $post_id );
	if ( ! $product ) {
		return;
	}

	$simple_text = array(
		'_sella_book_length_cm',
		'_sella_book_width_cm',
		'_sella_is_color',
		'_sella_barcode',
		'_sella_danacode',
		'_sella_cover_type',
		'_sella_paper_weight',
		'_sella_books_to_author',
		'_sella_books_taken',
		'_sella_page_count',
	);

	foreach ( $simple_text as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$product->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
		} else {
			$product->update_meta_data( $key, '' );
		}
	}

	$barcode_image = isset( $_POST['_sella_barcode_image'] ) ? absint( wp_unslash( $_POST['_sella_barcode_image'] ) ) : 0;
	$product->update_meta_data( '_sella_barcode_image', $barcode_image ? $barcode_image : '' );

	$color_pages = array();
	if ( ! empty( $_POST['_sella_color_pages'] ) && is_array( $_POST['_sella_color_pages'] ) ) {
		foreach ( wp_unslash( $_POST['_sella_color_pages'] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$number = isset( $row['number'] ) ? sanitize_text_field( $row['number'] ) : '';
			$pages  = isset( $row['pages'] ) ? sanitize_text_field( $row['pages'] ) : '';
			if ( '' === $number && '' === $pages ) {
				continue;
			}
			$color_pages[] = array(
				'number' => $number,
				'pages'  => $pages,
			);
		}
	}
	$product->update_meta_data( '_sella_color_pages', $color_pages );

	$corrections = array();
	if ( ! empty( $_POST['_sella_corrections'] ) && is_array( $_POST['_sella_corrections'] ) ) {
		foreach ( wp_unslash( $_POST['_sella_corrections'] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$number = isset( $row['number'] ) ? sanitize_text_field( $row['number'] ) : '';
			$text   = isset( $row['text'] ) ? sanitize_text_field( $row['text'] ) : '';
			if ( '' === $number && '' === $text ) {
				continue;
			}
			$corrections[] = array(
				'number' => $number,
				'text'   => $text,
			);
		}
	}
	$product->update_meta_data( '_sella_corrections', $corrections );

	$product->save_meta_data();
}
add_action( 'woocommerce_process_product_meta', 'sella_book_save_product_fields' );

/**
 * Get all book meta for a product.
 *
 * @param int $product_id Product ID.
 * @return array<string, mixed>
 */
function sella_get_book_fields( $product_id ) {
	$cover_types = sella_book_cover_types();
	$cover_key   = get_post_meta( $product_id, '_sella_cover_type', true );
	$is_color    = get_post_meta( $product_id, '_sella_is_color', true );
	$barcode_img = (int) get_post_meta( $product_id, '_sella_barcode_image', true );

	$color_pages = get_post_meta( $product_id, '_sella_color_pages', true );
	$corrections = get_post_meta( $product_id, '_sella_corrections', true );

	return array(
		'length_cm'         => get_post_meta( $product_id, '_sella_book_length_cm', true ),
		'width_cm'          => get_post_meta( $product_id, '_sella_book_width_cm', true ),
		'is_color'          => $is_color,
		'is_color_label'    => 'yes' === $is_color ? 'כן' : ( 'no' === $is_color ? 'לא' : '' ),
		'color_pages'       => is_array( $color_pages ) ? $color_pages : array(),
		'corrections'       => is_array( $corrections ) ? $corrections : array(),
		'barcode'           => get_post_meta( $product_id, '_sella_barcode', true ),
		'barcode_image_id'  => $barcode_img,
		'barcode_image_url' => $barcode_img ? wp_get_attachment_image_url( $barcode_img, 'medium' ) : '',
		'danacode'          => get_post_meta( $product_id, '_sella_danacode', true ),
		'cover_type'        => $cover_key,
		'cover_type_label'  => isset( $cover_types[ $cover_key ] ) ? $cover_types[ $cover_key ] : '',
		'paper_weight'      => get_post_meta( $product_id, '_sella_paper_weight', true ),
		'books_to_author'   => get_post_meta( $product_id, '_sella_books_to_author', true ),
		'books_taken'       => get_post_meta( $product_id, '_sella_books_taken', true ),
		'page_count'        => get_post_meta( $product_id, '_sella_page_count', true ),
	);
}

/**
 * Show book details in the Additional information tab when filled.
 *
 * @param array      $attributes Attributes.
 * @param WC_Product $product    Product.
 * @return array
 */
function sella_book_display_attributes( $attributes, $product ) {
	$data = sella_get_book_fields( $product->get_id() );
	$extra = array();

	if ( $data['length_cm'] || $data['width_cm'] ) {
		$extra['גודל ספר (ס״מ)'] = trim( $data['length_cm'] . ' × ' . $data['width_cm'], ' ×' );
	}
	if ( $data['is_color_label'] ) {
		$extra['האם בצבע'] = $data['is_color_label'];
	}
	if ( $data['page_count'] !== '' && null !== $data['page_count'] ) {
		$extra['מספר עמודים'] = $data['page_count'];
	}
	if ( ! empty( $data['color_pages'] ) ) {
		$lines = array();
		foreach ( $data['color_pages'] as $row ) {
			$lines[] = trim( ( $row['number'] ?? '' ) . ( ! empty( $row['pages'] ) ? ' — ' . $row['pages'] : '' ) );
		}
		$extra['עמודים בצבע'] = implode( ', ', array_filter( $lines ) );
	}
	if ( ! empty( $data['corrections'] ) ) {
		$lines = array();
		foreach ( $data['corrections'] as $row ) {
			$lines[] = trim( ( $row['number'] ?? '' ) . ( ! empty( $row['text'] ) ? ' — ' . $row['text'] : '' ) );
		}
		$extra['תיקונים בקנה'] = implode( ', ', array_filter( $lines ) );
	}
	if ( $data['barcode'] ) {
		$extra['ברקוד'] = $data['barcode'];
	}
	if ( $data['danacode'] ) {
		$extra['דאנאקוד'] = $data['danacode'];
	}
	if ( $data['cover_type_label'] && '— בחרו —' !== $data['cover_type_label'] ) {
		$extra['סוג כריכה'] = $data['cover_type_label'];
	}
	if ( $data['paper_weight'] !== '' && null !== $data['paper_weight'] ) {
		$extra['משקל נייר (גרם)'] = $data['paper_weight'];
	}
	if ( $data['books_to_author'] !== '' && null !== $data['books_to_author'] ) {
		$extra['ספרים לסופר'] = $data['books_to_author'];
	}
	if ( $data['books_taken'] !== '' && null !== $data['books_taken'] ) {
		$extra['נלקחו כבר'] = $data['books_taken'];
	}

	foreach ( $extra as $label => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		$attributes[ 'sella_' . sanitize_title( $label ) ] = array(
			'label' => $label,
			'value' => wp_kses_post( $value ),
		);
	}

	return $attributes;
}
add_filter( 'woocommerce_display_product_attributes', 'sella_book_display_attributes', 10, 2 );

/**
 * Optionally show barcode image under additional information.
 */
function sella_book_barcode_image_after_attributes() {
	if ( ! is_product() ) {
		return;
	}

	global $product;
	if ( ! $product ) {
		return;
	}

	$data = sella_get_book_fields( $product->get_id() );
	if ( empty( $data['barcode_image_url'] ) ) {
		return;
	}

	echo '<div class="sella-barcode-image" style="margin-top:1em;">';
	echo '<p><strong>תמונת ברקוד</strong></p>';
	echo '<img src="' . esc_url( $data['barcode_image_url'] ) . '" alt="' . esc_attr( 'ברקוד ' . $data['barcode'] ) . '" style="max-width:220px;height:auto;" />';
	echo '</div>';
}
add_action( 'woocommerce_product_additional_information', 'sella_book_barcode_image_after_attributes', 20 );
