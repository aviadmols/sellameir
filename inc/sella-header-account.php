<?php
/**
 * Header: a "כניסה למנויים" text button in place of the person icon on
 * desktop, and a "לחנות" button in its place on mobile, where the menu that
 * opens carries the subscriber links instead.
 *
 * Desktop draws the icon from a Smart Cart shortcode, mobile from an Elementor
 * icon-list widget linking to My Account; both are rewritten on output. The
 * account labels carry both texts and CSS picks one from body.logged-in, so
 * cached HTML (Elementor's element cache, a page cache) never shows the wrong
 * one.
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
 * becomes a button to the shop.
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
			$attrs = preg_replace( '#\bhref="[^"]*"#', 'href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '"', $match[1] );
			$attrs = false !== strpos( $attrs, 'class="' )
				? str_replace( 'class="', 'class="sella-account-link sella-shop-link ', $attrs )
				: $attrs . ' class="sella-account-link sella-shop-link"';
			return '<a' . $attrs . '><span class="sella-account-label">לחנות</span></a>';
		},
		$content
	);
}
add_filter( 'elementor/widget/render_content', 'sella_header_account_icon_list', 20, 2 );

/**
 * The menu that opens from the mobile header (Elementor popup 1641): the
 * subscriber links, which the header button no longer offers there. Only this
 * widget, since the same WordPress menu is the desktop header's navigation.
 *
 * @param string                 $content Widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget.
 * @return string
 */
function sella_header_mobile_menu_links( $content, $widget ) {
	if ( 'nav-menu' !== $widget->get_name() || '647d9de' !== $widget->get_id() ) {
		return $content;
	}

	$items = sprintf(
		'<li class="menu-item sella-menu-account"><a href="%1$s" class="elementor-item">%2$s</a></li>'
		. '<li class="menu-item sella-menu-join"><a href="%3$s" class="elementor-item">הצטרפות למנויים</a></li>',
		esc_url( wc_get_page_permalink( 'myaccount' ) ),
		sella_header_account_labels(),
		esc_url( home_url( '/subscription/' ) )
	);

	// Both lists: the visible one and Elementor's dropdown copy.
	return preg_replace( '#(<ul\b[^>]*\belementor-nav-menu\b[^>]*>.*?)(</ul>)#s', '$1' . $items . '$2', $content );
}
add_filter( 'elementor/widget/render_content', 'sella_header_mobile_menu_links', 20, 2 );
