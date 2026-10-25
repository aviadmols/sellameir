<?php
/**
 * Footer contact links: the phone opens WhatsApp, the email opens a new
 * message in Gmail.
 *
 * The footer is an Elementor template whose text widget links the phone with
 * tel: and the address with mailto:. Its widgets' HTML is rewritten as it is
 * rendered, only inside the footer document, so the same links elsewhere on
 * the site are left alone and nothing changes in Elementor.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Elementor is rendering the site footer right now.
 *
 * @return bool
 */
function sella_fl_in_footer() {
	if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) ) {
		return false;
	}
	$document = \Elementor\Plugin::$instance->documents->get_current();
	return $document && 'footer' === $document->get_name();
}

/**
 * WhatsApp link for an Israeli phone number: 054-2969899 → wa.me/972542969899.
 *
 * @param string $phone Number as written in the tel: link.
 * @return string '' when it is not a number.
 */
function sella_fl_whatsapp_url( $phone ) {
	$digits = preg_replace( '/\D/', '', $phone );
	if ( strlen( $digits ) < 9 ) {
		return '';
	}
	if ( 0 === strpos( $digits, '0' ) ) {
		$digits = '972' . substr( $digits, 1 );
	}
	return 'https://wa.me/' . $digits;
}

/**
 * Gmail "new message" link for a mailto: address, keeping a subject.
 *
 * @param string $mailto What follows mailto:.
 * @return string
 */
function sella_fl_gmail_url( $mailto ) {
	$parts = explode( '?', html_entity_decode( $mailto ), 2 );
	$args  = array(
		'view' => 'cm',
		'fs'   => '1',
		'to'   => rawurldecode( trim( $parts[0] ) ),
	);
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
		if ( ! empty( $query['subject'] ) ) {
			$args['su'] = $query['subject'];
		}
	}
	return 'https://mail.google.com/mail/?' . http_build_query( $args, '', '&' );
}

/**
 * Rewrite tel: and mailto: links in a footer widget. Both now open in a new
 * tab, since they leave the site.
 *
 * @param string $html Widget HTML.
 * @return string
 */
function sella_fl_rewrite( $html ) {
	if ( false === stripos( $html, 'tel:' ) && false === stripos( $html, 'mailto:' ) ) {
		return $html;
	}

	return preg_replace_callback(
		'#<a\b([^>]*?)\bhref=(["\'])\s*(tel|mailto):([^"\']*)\2([^>]*)>#i',
		function ( $m ) {
			$url = 'tel' === strtolower( $m[3] ) ? sella_fl_whatsapp_url( $m[4] ) : sella_fl_gmail_url( $m[4] );
			if ( '' === $url ) {
				return $m[0];
			}
			$attrs = preg_replace( '#\s(target|rel)=(["\'])[^"\']*\2#i', '', $m[1] . $m[5] );
			return '<a' . $attrs . ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener">';
		},
		$html
	);
}

/**
 * Footer widgets only.
 *
 * @param string $content Widget HTML.
 * @return string
 */
function sella_fl_widget_content( $content ) {
	return sella_fl_in_footer() ? sella_fl_rewrite( $content ) : $content;
}
add_filter( 'elementor/widget/render_content', 'sella_fl_widget_content', 20 );
