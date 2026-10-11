<?php
/**
 * Template Name: מהדורת האלף - נתניהו
 *
 * A copy of selameir.com/netanyahu1000: the limited "1,000 edition" of
 * Bibi: My Story. The site header and footer stay as they are; only the
 * page body is replaced.
 *
 * The buy button adds the book to the cart. The product is the page's
 * "sella_product_id" custom field, or, without one, the first product whose
 * title has "מהדורת האלף" in it.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sella_n1000_img     = get_stylesheet_directory_uri() . '/assets/images/netanyahu1000/';
$sella_n1000_product = sella_n1000_product( get_the_ID() );
$sella_n1000_price   = $sella_n1000_product ? (float) wc_get_price_to_display( $sella_n1000_product ) : 1850;
$sella_n1000_gallery = array( 'book-1000-1.jpg', 'book-1000-2.jpg', 'book-1000-3.jpg', 'book-1000-5.jpg', 'book-1000-6.jpg' );

get_header();
?>
<main id="content" class="sella-n1000">
	<section class="sella-n1000__hero">
		<div class="sella-n1000__hero-grid">
			<img class="sella-n1000__portrait" <?php echo sella_n1000_img_attrs( 'bibi-1000-book.png', '(max-width: 910px) 300px, 577px' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> alt="Benjamin Netanyahu" width="1066" height="1600" fetchpriority="high">
			<div class="sella-n1000__intro">
				<h1 class="sella-n1000__title">ביבי: סיפור חיי</h1>
				<div class="sella-n1000__rule"></div>
				<h2 class="sella-n1000__subtitle">מהדורת האלף</h2>
				<div class="sella-n1000__text">
					<p>פחות מחודשיים לאחר השקתו, הפך הספר ביבי: סיפור חיי לציון דרך בעולם הספרות והשיח הישראלי. באוטוביוגרפיה אישית וסוחפת פורס אחד המנהיגים הגדולים של העם היהודי בעת החדשה את נופי הילדות, האישים, הרעיונות, הטרגדיות והרגעים הגדולים שעיצבו את תפיסת עולמו ואת דרכו.</p>
					<p>מהדורת האלף היא סדרה מוגבלת ומהודרת של ביבי: סיפור חיי, הכוללת 1,000 עותקים ממוספרים ויחודיים. מזכרת חגיגית למדף הספרים שנוצרה בכדי להישמר לדורות.</p>
				</div>
				<?php if ( $sella_n1000_product && $sella_n1000_product->is_purchasable() && $sella_n1000_product->is_in_stock() ) : ?>
					<form class="sella-n1000__buy" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
						<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $sella_n1000_product->get_id() ); ?>">
						<button type="submit" class="sella-n1000__button">לרכישת הספר</button>
					</form>
				<?php else : ?>
					<a class="sella-n1000__button" href="<?php echo esc_url( $sella_n1000_product ? $sella_n1000_product->get_permalink() : wc_get_page_permalink( 'shop' ) ); ?>">לרכישת הספר</a>
				<?php endif; ?>
				<p class="sella-n1000__price"><?php echo esc_html( number_format( $sella_n1000_price, floor( $sella_n1000_price ) === $sella_n1000_price ? 0 : 2 ) ); ?> ש"ח</p>
			</div>
		</div>
		<img class="sella-n1000__map" <?php echo sella_n1000_img_attrs( 'map-bg.jpg', '100vw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> alt="" aria-hidden="true">
		<div class="sella-n1000__fade"></div>
	</section>

	<div class="sella-n1000__gallery">
		<?php foreach ( $sella_n1000_gallery as $sella_n1000_file ) : ?>
			<button type="button" class="sella-n1000__thumb" data-full="<?php echo esc_url( sella_n1000_img_url( $sella_n1000_file ) ); ?>" aria-label="הגדלת התמונה">
				<img <?php echo sella_n1000_img_attrs( $sella_n1000_file, '(max-width: 910px) 100vw, 217px' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> alt="book" width="800" height="602" loading="lazy">
			</button>
		<?php endforeach; ?>
	</div>

	<div class="sella-n1000__quote">
		<svg class="sella-n1000__quote-mark" width="190" height="168" viewBox="0 0 190 168" fill="none" aria-hidden="true"><path d="M112.406 0.837136L189.384 0.837143L189.384 55.8814C189.918 73.6455 187.922 91.3953 183.456 108.597C179.308 121.829 171.804 133.761 161.675 143.232C150.05 154.079 136.232 162.304 121.154 167.35L106.064 135.569C119.034 132.076 130.584 124.607 139.09 114.211C146.267 103.446 149.954 90.732 149.65 77.7975L112.39 77.7975L112.406 0.837136ZM6.86561 0.837127L83.8426 0.837134L83.8426 55.8814C84.3925 73.6936 82.3968 91.493 77.9152 108.741C73.7254 121.944 66.1726 133.831 55.999 143.232C44.3845 154.02 30.6263 162.238 15.6219 167.35L0.531393 135.569C13.5026 132.078 25.0528 124.609 33.5577 114.211C40.7344 103.446 44.4217 90.732 44.1178 77.7975L6.85734 77.7975L6.86561 0.837127Z" fill="white" fill-opacity="0.2"/></svg>
		<p>"בנימין נתניהו הוא המנהיג הפוליטי המצליח בתולדות ישראל. בעוד דוד בן גוריון, אביה המייסד של המדינה, הקים וקבע את דרכה של המדינה בשנותיה הראשונות, הרי שנתניהו העלה אותה בבגרותה על דרך חדשה… בדומה למחבר שלה ולישראל של ימינו, האוטוביוגרפיה "ביבי:סיפור חיי" היא חיבור מלוטש, מנומק, מרתק וקצבי."</p>
		<div class="sella-n1000__rule sella-n1000__rule--short"></div>
		<img class="sella-n1000__wsj" src="<?php echo esc_url( $sella_n1000_img . 'wsj-wide.svg' ); ?>" alt="the wall street journal" width="200" height="26" loading="lazy">
	</div>

	<div class="sella-n1000__lightbox" aria-hidden="true">
		<div class="sella-n1000__lightbox-card" role="dialog" aria-modal="true">
			<button type="button" class="sella-n1000__lightbox-close" aria-label="סגירה">&times;</button>
			<img src="" alt="">
		</div>
	</div>
</main>
<?php
get_footer();
