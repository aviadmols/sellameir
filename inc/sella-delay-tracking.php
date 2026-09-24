<?php
/**
 * Load tracking scripts after the page, not with it.
 *
 * GTM, gtag (GA4 / Google Ads), the Meta Pixel and UserWay download their
 * libraries 3 seconds after the page has loaded, or at the visitor's first
 * scroll, tap, click or key press, whichever comes first.
 *
 * Only the download is held back. The queue functions the snippets define
 * (dataLayer, gtag, fbq) still exist from the start, so events fired before
 * the download are queued and sent once the library arrives.
 *
 * Cart, checkout, thank-you and my-account pages load tracking at once, so a
 * purchase conversion is never lost to a visitor who leaves quickly.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long after the page's load event to start the downloads.
 */
define( 'SELLA_DELAY_TRACKING_MS', 3000 );

/**
 * Whether tracking downloads are delayed on the current page.
 *
 * @return bool
 */
function sella_delay_tracking_applies() {
	if ( is_admin() || is_feed() || is_preview() || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false;
	}

	if ( function_exists( 'is_woocommerce' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		return false;
	}

	return true;
}

/**
 * Print the loader first in <head> and start rewriting the page.
 *
 * Runs after the page cache starts its buffer (sella_pc_start_buffer), so this
 * buffer sits inside it and the cache stores the rewritten page.
 */
function sella_delay_tracking_start() {
	if ( ! sella_delay_tracking_applies() ) {
		return;
	}
	add_action( 'wp_head', 'sella_delay_tracking_loader', PHP_INT_MIN );
	ob_start( 'sella_delay_tracking_rewrite' );
}
add_action( 'template_redirect', 'sella_delay_tracking_start', PHP_INT_MAX );

/**
 * Loader: collects held-back downloads and runs them on the trigger.
 */
function sella_delay_tracking_loader() {
	?>
<script id="sella-delay-tracking">
(function (w) {
	var queue = [];
	var done = false;
	// Scroll is heard on the window only (no capture): sliders on the page
	// scroll their own boxes from code, and those must not count as a visitor.
	var events = [['pointerdown', true], ['touchstart', true], ['keydown', true], ['wheel', true], ['scroll', false]];

	function run() {
		if (done) {
			return;
		}
		done = true;
		events.forEach(function (event) {
			w.removeEventListener(event[0], run, event[1]);
		});
		queue.forEach(function (load) {
			try {
				load();
			} catch (e) {}
		});
		queue = [];
	}

	w.sellaDelayLoad = function (load) {
		if (done) {
			load();
		} else {
			queue.push(load);
		}
	};

	events.forEach(function (event) {
		w.addEventListener(event[0], run, { capture: event[1], passive: true });
	});
	w.addEventListener('load', function () {
		setTimeout(run, <?php echo (int) SELLA_DELAY_TRACKING_MS; ?>);
	});
})(window);
</script>
	<?php
}

/**
 * Output-buffer callback: hold back the tracking downloads in the page.
 *
 * @param string $html Page HTML.
 * @return string
 */
function sella_delay_tracking_rewrite( $html ) {
	if ( false === stripos( $html, '</html>' ) ) {
		return $html;
	}

	$rewritten = preg_replace_callback( '#<script\b([^>]*)>(.*?)</script>#is', 'sella_delay_tracking_script', $html );

	// A regex failure (backtrack limit on a huge page) leaves the page as it was.
	return is_string( $rewritten ) ? $rewritten : $html;
}

/**
 * Rewrite one <script> element when it downloads a tracking library.
 *
 * @param array $match Full tag, attributes, contents.
 * @return string
 */
function sella_delay_tracking_script( $match ) {
	$attrs = $match[1];
	$code  = $match[2];

	// <script async src="https://www.googletagmanager.com/gtag/js?id=...">
	if ( preg_match( '#\bsrc=["\'](https://www\.googletagmanager\.com/gtag/js\?[^"\']+)["\']#i', $attrs, $src ) ) {
		return '<script>window.sellaDelayLoad(function(){var s=document.createElement("script");s.async=true;s.src='
			. wp_json_encode( html_entity_decode( $src[1], ENT_QUOTES ) )
			. ';document.head.appendChild(s);});</script>';
	}

	if ( '' === trim( $code ) || ! preg_match( '#googletagmanager\.com/gtm\.js|connect\.facebook\.net|cdn\.userway\.org#', $code ) ) {
		return $match[0];
	}

	// The snippets build a <script> element and insert it; hold back only the insert.
	$code = preg_replace(
		'#(\w+\.parentNode\.insertBefore\(\s*\w+\s*,\s*\w+\s*\)|document\.(?:body|head)\.appendChild\(\s*\w+\s*\))#',
		'window.sellaDelayLoad(function(){$1})',
		$code
	);

	return '<script' . $attrs . '>' . $code . '</script>';
}
