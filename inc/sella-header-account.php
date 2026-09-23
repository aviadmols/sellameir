<?php
/**
 * Header: a "כניסה למנויים" text button in place of the person icon.
 *
 * Desktop draws the icon from a Smart Cart shortcode, mobile from an Elementor
 * icon-list widget linking to My Account; both are rewritten on output. The
 * markup carries both labels and CSS picks one from body.logged-in, so cached
 * HTML (Elementor's element cache, a page cache) never shows the wrong one.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The button's labels: guests see "כניסה למנויים", members "האזור האישי".
 *
 * @return string
 */
function sella_header_account_labels() {
	return '<span class="sella-account-label sella-account-label--guest">כניסה למנויים</span>'
		. '<span class="sella-account-label sella-account-label--member">האזור האישי</span>';
}

/**
 * Smart Cart header icons: replace the account icon with the labels.
 *
 * @param string $output Shortcode output.
 * @return string
 */
function sella_header_account_button( $output ) {
	if ( ! is_string( $output ) || false === strpos( $output, 'sc-account-trigger' ) ) {
		return $output;
	}

	return preg_replace_callback(
		'#<a([^>]*\bsc-account-trigger\b[^>]*)>.*?</a>#s',
		function ( $match ) {
			$attrs = preg_replace( '#\saria-label="[^"]*"#', '', $match[1] );
			return '<a' . $attrs . '>' . sella_header_account_labels() . '</a>';
		},
		$output,
		1
	);
}
add_filter( 'do_shortcode_tag', 'sella_header_account_button', 20 );

/**
 * Elementor icon lists (the mobile header): an icon-only My Account link
 * becomes the same button.
 *
 * @param string                 $content Widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget.
 * @return string
 */
function sella_header_account_icon_list( $content, $widget ) {
	if ( 'icon-list' !== $widget->get_name() || false === strpos( $content, 'my-account' ) ) {
		return $content;
	}

	return preg_replace_callback(
		'#<a([^>]*\bhref="[^"]*/my-account/?"[^>]*)>(.*?)</a>#s',
		function ( $match ) {
			// A link with its own text is a real list item, not the header icon.
			if ( '' !== trim( wp_strip_all_tags( $match[2] ) ) ) {
				return $match[0];
			}
			$attrs = false !== strpos( $match[1], 'class="' )
				? str_replace( 'class="', 'class="sella-account-link ', $match[1] )
				: $match[1] . ' class="sella-account-link"';
			return '<a' . $attrs . '>' . sella_header_account_labels() . '</a>';
		},
		$content
	);
}
add_filter( 'elementor/widget/render_content', 'sella_header_account_icon_list', 20, 2 );
