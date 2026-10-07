<?php
/**
 * "האם הספר מתאים לי?" on a book's page.
 *
 * A quiet row of three round buttons under the book's description: ChatGPT,
 * Claude and Gemini. Each opens the assistant in a new tab with a question
 * already written: the book, its author, a link back here, and what to look
 * at to say whether it suits this reader.
 *
 * The page is an Elementor template, so the row is added to the output of its
 * description widget rather than to a WooCommerce hook that may not run.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The question for one assistant.
 *
 * @param WC_Product $product Product.
 * @param string     $source  Where the visit will come from, for the link's utm_source.
 * @return string
 */
function sella_ai_consult_prompt( $product, $source ) {
	$author = get_post_meta( $product->get_id(), 'מחבר', true );

	if ( ! $author ) {
		$author = get_post_meta( $product->get_id(), '_author', true );
	}

	$link = add_query_arg(
		[
			'utm_source'   => $source,
			'utm_medium'   => 'LLMrecommend',
			'utm_campaign' => 'LLMrecommend',
		],
		get_permalink( $product->get_id() )
	);

	$lines = [
		'אני בהתלבטות אם לרכוש את הספר הזה מהוצאת סלע מאיר ורוצה להבין אם הוא באמת מתאים לי אישית.',
		'אשמח שתעזור לי לבדוק זאת לפי המידע שכבר קיים ברשת — וגם לפי מה שאתה כבר יודע עליי משיחות קודמות.',
		'',
		'שם הספר: ' . wp_strip_all_tags( $product->get_name() ),
	];

	if ( $author ) {
		$lines[] = 'שם הסופר או הסופרת: ' . wp_strip_all_tags( (string) $author );
	}

	return implode(
		"\n",
		array_merge(
			$lines,
			[
				'קישור לעמוד הספר: ' . $link,
				'',
				'כך אני מבקש שתבצע את הבדיקה:',
				'1. מצא מידע איכותי ומעמיק על הספר והסופר/ת — כולל סיכומים, ביקורות ודעות שונות של קוראים, מתוך מקורות מהימנים שמתמקדים בתוכן ולא במכירה.',
				'2. השתמש במידע שכבר יש לך עליי מהשיחות הקודמות, כדי להבין אם זהו סגנון הקריאה שמתאים לי באופן אישי.',
				'3. אם חסר לך פרט אחד מהותי כדי להעריך את ההתאמה — שאל אותי שאלה קצרה אחת בלבד, ואל תמשיך מעבר לכך.',
				'4. תן תשובה תמציתית ומאוזנת שתכלול:',
				'- למי הספר כן מתאים',
				'- למי פחות',
				'- מה כדאי לדעת מראש לפני שמחליטים אם לרכוש',
				'',
				'סיים תמיד במשפט מסכם קצר — כמו ייעוץ כן ואישי של מוכר מקצועי בחנות ספרים.',
				'ולסיום — צרף שוב את הקישור לעמוד הספר באתר סלע מאיר.',
			]
		)
	);
}

/**
 * The row for a product.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function sella_ai_consult_html( $product ) {
	$engines = [
		'gpt'    => [
			'name'   => 'ChatGPT',
			'url'    => 'https://chatgpt.com/',
			'args'   => [],
			'source' => 'chatgpt.com',
			'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9.205 8.658v-2.26c0-.19.072-.333.238-.428l4.543-2.616c.619-.357 1.356-.523 2.117-.523 2.854 0 4.662 2.212 4.662 4.566 0 .167 0 .357-.024.547l-4.71-2.759a.797.797 0 00-.856 0l-5.97 3.473zm10.609 8.8V12.06c0-.333-.143-.57-.429-.737l-5.97-3.473 1.95-1.118a.433.433 0 01.476 0l4.543 2.617c1.309.76 2.189 2.378 2.189 3.948 0 1.808-1.07 3.473-2.76 4.163zM7.802 12.703l-1.95-1.142c-.167-.095-.239-.238-.239-.428V5.899c0-2.545 1.95-4.472 4.591-4.472 1 0 1.927.333 2.712.928L8.23 5.067c-.285.166-.428.404-.428.737v6.898zM12 15.128l-2.795-1.57v-3.33L12 8.658l2.795 1.57v3.33L12 15.128zm1.796 7.23c-1 0-1.927-.332-2.712-.927l4.686-2.712c.285-.166.428-.404.428-.737v-6.898l1.974 1.142c.167.095.238.238.238.428v5.233c0 2.545-1.974 4.472-4.614 4.472zm-5.637-5.303l-4.544-2.617c-1.308-.761-2.188-2.378-2.188-3.948A4.482 4.482 0 014.21 6.327v5.423c0 .333.143.571.428.738l5.947 3.449-1.95 1.118a.432.432 0 01-.476 0zm-.262 3.9c-2.688 0-4.662-2.021-4.662-4.519 0-.19.024-.38.047-.57l4.686 2.71c.286.167.571.167.856 0l5.97-3.448v2.26c0 .19-.07.333-.237.428l-4.543 2.616c-.619.357-1.356.523-2.117.523zm5.899 2.83a5.947 5.947 0 005.827-4.756C22.287 18.339 24 15.84 24 13.296c0-1.665-.713-3.282-1.998-4.448.119-.5.19-.999.19-1.498 0-3.401-2.759-5.947-5.946-5.947-.642 0-1.26.095-1.88.31A5.962 5.962 0 0010.205 0a5.947 5.947 0 00-5.827 4.757C1.713 5.447 0 7.945 0 10.49c0 1.666.713 3.283 1.998 4.448-.119.5-.19 1-.19 1.499 0 3.401 2.759 5.946 5.946 5.946.642 0 1.26-.095 1.88-.309a5.96 5.96 0 004.162 1.713z"/></svg>',
		],
		'claude' => [
			'name'   => 'Claude',
			'url'    => 'https://claude.ai/new',
			'args'   => [],
			'source' => 'claude.ai',
			'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#D97757" d="M4.709 15.955l4.72-2.647.08-.23-.08-.128H9.2l-.79-.048-2.698-.073-2.339-.097-2.266-.122-.571-.121L0 11.784l.055-.352.48-.321.686.06 1.52.103 2.278.158 1.652.097 2.449.255h.389l.055-.157-.134-.098-.103-.097-2.358-1.596-2.552-1.688-1.336-.972-.724-.491-.364-.462-.158-1.008.656-.722.881.06.225.061.893.686 1.908 1.476 2.491 1.833.365.304.145-.103.019-.073-.164-.274-1.355-2.446-1.446-2.49-.644-1.032-.17-.619a2.97 2.97 0 01-.104-.729L6.283.134 6.696 0l.996.134.42.364.62 1.414 1.002 2.229 1.555 3.03.456.898.243.832.091.255h.158V9.01l.128-1.706.237-2.095.23-2.695.08-.76.376-.91.747-.492.584.28.48.685-.067.444-.286 1.851-.559 2.903-.364 1.942h.212l.243-.242.985-1.306 1.652-2.064.73-.82.85-.904.547-.431h1.033l.76 1.129-.34 1.166-1.064 1.347-.881 1.142-1.264 1.7-.79 1.36.073.11.188-.02 2.856-.606 1.543-.28 1.841-.315.833.388.091.395-.328.807-1.969.486-2.309.462-3.439.813-.042.03.049.061 1.549.146.662.036h1.622l3.02.225.79.522.474.638-.079.485-1.215.62-1.64-.389-3.829-.91-1.312-.329h-.182v.11l1.093 1.068 2.006 1.81 2.509 2.33.127.578-.322.455-.34-.049-2.205-1.657-.851-.747-1.926-1.62h-.128v.17l.444.649 2.345 3.521.122 1.08-.17.353-.608.213-.668-.122-1.374-1.925-1.415-2.167-1.143-1.943-.14.08-.674 7.254-.316.37-.729.28-.607-.461-.322-.747.322-1.476.389-1.924.315-1.53.286-1.9.17-.632-.012-.042-.14.018-1.434 1.967-2.18 2.945-1.726 1.845-.414.164-.717-.37.067-.662.401-.589 2.388-3.036 1.44-1.882.93-1.086-.006-.158h-.055L4.132 18.56l-1.13.146-.487-.456.061-.746.231-.243 1.908-1.312-.006.006z"/></svg>',
		],
		'gemini' => [
			'name'   => 'Gemini',
			'url'    => 'https://www.google.com/search',
			'args'   => [
				'udm' => '50',
				'aep' => '11',
			],
			'source' => 'gemini.google.com',
			'icon'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="sella-ai-gemini" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#4285F4"/><stop offset=".5" stop-color="#9B72CB"/><stop offset="1" stop-color="#D96570"/></linearGradient></defs><path fill="url(#sella-ai-gemini)" d="M12 0c.4 6.4 5.6 11.6 12 12-6.4.4-11.6 5.6-12 12-.4-6.4-5.6-11.6-12-12C6.4 11.6 11.6 6.4 12 0z"/></svg>',
		],
	];

	$links = '';

	foreach ( $engines as $key => $engine ) {
		$query = $engine['args'] + [ 'q' => sella_ai_consult_prompt( $product, $engine['source'] ) ];
		$href  = $engine['url'] . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		$links .= sprintf(
			'<a class="sella-ai__dot" data-engine="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer" title="%3$s" aria-label="%4$s">%5$s</a>',
			esc_attr( $key ),
			esc_url( $href ),
			esc_attr( $engine['name'] ),
			esc_attr( sprintf( 'התייעצות עם %s לגבי הספר - נפתח בכרטיסייה חדשה', $engine['name'] ) ),
			$engine['icon']
		);
	}

	return '<div class="sella-ai"><span class="sella-ai__label">האם הספר מתאים לי?</span><div class="sella-ai__row">' . $links . '</div></div>';
}

/**
 * Add the row under the description on a book's page.
 *
 * @param string                 $content Widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget.
 * @return string
 */
function sella_ai_consult_after_description( $content, $widget ) {
	if ( 'woocommerce-product-content' !== $widget->get_name() || ! is_product() ) {
		return $content;
	}

	$product = wc_get_product( get_queried_object_id() );

	return $product ? $content . sella_ai_consult_html( $product ) : $content;
}
add_filter( 'elementor/widget/render_content', 'sella_ai_consult_after_description', 20, 2 );
