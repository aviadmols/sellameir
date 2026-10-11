<?php
/**
 * Template Name: מהדורת האלף - נתניהו
 *
 * A copy of selameir.com/netanyahu1000: the limited "1,000 edition" of
 * Bibi: My Story. The site header and footer stay as they are; only the
 * page body is replaced.
 *
 * The content is edited in the page's ACF fields (inc/sella-netanyahu1000.php),
 * and every empty field shows the original page's content. The buy button
 * adds the "מוצר" field's product to the cart.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sella_n1000_id       = get_the_ID();
$sella_n1000_images   = sella_n1000_default_images();
$sella_n1000_product  = sella_n1000_product( $sella_n1000_id );
$sella_n1000_button   = sella_n1000_field( 'n1000_button_text', $sella_n1000_id );
$sella_n1000_portrait = sella_n1000_image( 'n1000_portrait', $sella_n1000_images['portrait'], $sella_n1000_id );
$sella_n1000_quote_lg = sella_n1000_image( 'n1000_quote_logo', '', $sella_n1000_id );

/**
 * An image's alt text: the attachment's own, or the given fallback.
 *
 * @param int|string $src      Attachment ID or theme file name.
 * @param string     $fallback Alt text for the theme file.
 * @return string
 */
$sella_n1000_alt = static function ( $src, $fallback ) {
	$alt = is_int( $src ) ? trim( (string) get_post_meta( $src, '_wp_attachment_image_alt', true ) ) : '';

	return '' !== $alt ? $alt : $fallback;
};

get_header();
?>
<main id="content" class="sella-n1000">
	<section class="sella-n1000__hero">
		<div class="sella-n1000__hero-grid">
			<img class="sella-n1000__portrait" <?php echo sella_n1000_img_attrs( $sella_n1000_portrait, '(max-width: 910px) 300px, 577px' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> alt="<?php echo esc_attr( $sella_n1000_alt( $sella_n1000_portrait, 'Benjamin Netanyahu' ) ); ?>" fetchpriority="high">
			<div class="sella-n1000__intro">
				<h1 class="sella-n1000__title"><?php echo esc_html( sella_n1000_field( 'n1000_title', $sella_n1000_id ) ); ?></h1>
				<div class="sella-n1000__rule"></div>
				<h2 class="sella-n1000__subtitle"><?php echo esc_html( sella_n1000_field( 'n1000_subtitle', $sella_n1000_id ) ); ?></h2>
				<div class="sella-n1000__text">
					<?php echo wp_kses_post( sella_n1000_field( 'n1000_text', $sella_n1000_id ) ); ?>
				</div>
				<?php if ( $sella_n1000_product && $sella_n1000_product->is_purchasable() && $sella_n1000_product->is_in_stock() ) : ?>
					<form class="sella-n1000__buy" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
						<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $sella_n1000_product->get_id() ); ?>">
						<button type="submit" class="sella-n1000__button"><?php echo esc_html( $sella_n1000_button ); ?></button>
					</form>
				<?php else : ?>
					<a class="sella-n1000__button" href="<?php echo esc_url( $sella_n1000_product ? $sella_n1000_product->get_permalink() : wc_get_page_permalink( 'shop' ) ); ?>"><?php echo esc_html( $sella_n1000_button ); ?></a>
				<?php endif; ?>
				<p class="sella-n1000__price"><?php echo esc_html( sella_n1000_price_text( $sella_n1000_product, $sella_n1000_id ) ); ?></p>
			</div>
		</div>
		<img class="sella-n1000__map" <?php echo sella_n1000_img_attrs( sella_n1000_image( 'n1000_background', $sella_n1000_images['background'], $sella_n1000_id ), '100vw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> alt="" aria-hidden="true">
		<div class="sella-n1000__fade"></div>
	</section>

	<div class="sella-n1000__gallery">
		<?php foreach ( sella_n1000_gallery( $sella_n1000_id ) as $sella_n1000_photo ) : ?>
			<button type="button" class="sella-n1000__thumb" data-full="<?php echo esc_url( sella_n1000_img_url( $sella_n1000_photo ) ); ?>" aria-label="הגדלת התמונה">
				<img <?php echo sella_n1000_img_attrs( $sella_n1000_photo, '(max-width: 910px) 100vw, 217px' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> alt="<?php echo esc_attr( $sella_n1000_alt( $sella_n1000_photo, 'book' ) ); ?>" loading="lazy">
			</button>
		<?php endforeach; ?>
	</div>

	<?php if ( sella_n1000_field( 'n1000_show_quote', $sella_n1000_id ) ) : ?>
		<div class="sella-n1000__quote">
			<svg class="sella-n1000__quote-mark" width="190" height="168" viewBox="0 0 190 168" fill="none" aria-hidden="true"><path d="M112.406 0.837136L189.384 0.837143L189.384 55.8814C189.918 73.6455 187.922 91.3953 183.456 108.597C179.308 121.829 171.804 133.761 161.675 143.232C150.05 154.079 136.232 162.304 121.154 167.35L106.064 135.569C119.034 132.076 130.584 124.607 139.09 114.211C146.267 103.446 149.954 90.732 149.65 77.7975L112.39 77.7975L112.406 0.837136ZM6.86561 0.837127L83.8426 0.837134L83.8426 55.8814C84.3925 73.6936 82.3968 91.493 77.9152 108.741C73.7254 121.944 66.1726 133.831 55.999 143.232C44.3845 154.02 30.6263 162.238 15.6219 167.35L0.531393 135.569C13.5026 132.078 25.0528 124.609 33.5577 114.211C40.7344 103.446 44.4217 90.732 44.1178 77.7975L6.85734 77.7975L6.86561 0.837127Z" fill="white" fill-opacity="0.2"/></svg>
			<p><?php echo wp_kses_post( sella_n1000_field( 'n1000_quote', $sella_n1000_id ) ); ?></p>
			<div class="sella-n1000__rule sella-n1000__rule--short"></div>
			<?php if ( $sella_n1000_quote_lg ) : ?>
				<img class="sella-n1000__wsj" <?php echo sella_n1000_img_attrs( $sella_n1000_quote_lg, '200px' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> alt="<?php echo esc_attr( sella_n1000_field( 'n1000_quote_source', $sella_n1000_id ) ); ?>" loading="lazy">
			<?php else : ?>
				<img class="sella-n1000__wsj" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/images/netanyahu1000/wsj-wide.svg' ); ?>" alt="<?php echo esc_attr( sella_n1000_field( 'n1000_quote_source', $sella_n1000_id ) ); ?>" width="200" height="26" loading="lazy">
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="sella-n1000__lightbox" aria-hidden="true">
		<div class="sella-n1000__lightbox-card" role="dialog" aria-modal="true">
			<button type="button" class="sella-n1000__lightbox-close" aria-label="סגירה">&times;</button>
			<img src="" alt="">
		</div>
	</div>
</main>
<?php
get_footer();
