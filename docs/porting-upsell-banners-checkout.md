# העברת מנגנוני Upsell, באנרים ועיצוב Checkout לאתר אחר

מסמך העברה (handoff) עבור מפתח/סוכן שמיישם את אותם שלושה מנגנונים באתר וורדפרס אחר.
המקור: תבנית בת `hello-theme-child-master` של האתר sellameir (Hello Elementor + Elementor Pro + WooCommerce, RTL).

---

## 0. דרישות מוקדמות

| דרישה | נחוץ ל | הערה |
|---|---|---|
| WooCommerce | הכל | כל המודולים נטענים רק אם `class_exists('WooCommerce')` |
| jQuery | אדמין של Upsell, צ'קאאוט | הפרונט של הפופאפ והבאנרים הוא vanilla JS |
| Elementor Pro | עיצוב הצ'קאאוט בלבד | ה-CSS מכוון ל-widget "Checkout" של Elementor Pro. ראו סעיף 3.4 להתאמה לצ'קאאוט קלאסי |
| `manage_woocommerce` | מסכי הניהול | ה-capability שמגן על שני מסכי הניהול |

כל הקוד יושב בתבנית **בת** ונטען מ-`functions.php`:

```php
function sella_load_woocommerce_modules() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	require_once get_stylesheet_directory() . '/inc/sella-upsell-popups.php';
	require_once get_stylesheet_directory() . '/inc/sella-shop-banners.php';
}
add_action( 'after_setup_theme', 'sella_load_woocommerce_modules', 20 );
```

קבועי גרסה: כל ה-enqueue משתמשים ב-`HELLO_ELEMENTOR_CHILD_VERSION`. **מעלים את המספר בכל דיפלוי** אחרת דפדפנים מגישים CSS/JS מהקאש.

---

## 1. רשימת הקבצים

### מנגנון פופאפ Upsell
| נתיב | תוכן |
|---|---|
| `inc/sella-upsell-popups.php` | הלב. CPT, מסך ניהול, שמירה, בניית רשימת הספרים, חוקיות סל, endpoints, תיוג הזמנה (1082 שורות) |
| `assets/js/sella-upsell.js` | הפופאפ בפרונט: טריגרים, תדירות, סליידר, הוספה לסל, רענון (505 שורות) |
| `assets/css/sella-upsell.css` | עיצוב הכרטיס הצף |
| `assets/js/sella-upsell-admin.js` | בחירת מוצרים (selectWoo), גרירה לשינוי סדר, הצגה מותנית של שדות |
| `assets/css/sella-upsell-admin.css` | פריסת מסך הניהול |

### הצעות בדף התשלום (פופאפ במרכז המסך, מחיר מיוחד)
| נתיב | תוכן |
|---|---|
| `inc/sella-checkout-offers.php` | CPT, מסך ניהול עם תצוגה מקדימה, חוקיות סל, endpoint הוספה, אכיפת המחיר בסל, תיוג שורת הזמנה (ראו 4א) |
| `assets/js/sella-checkout-offers.js` | הפופאפ: סליידר, חיצים/נקודות/החלקה, הוספה ורענון הצ'קאאוט |
| `assets/css/sella-checkout-offers.css` | עיצוב הפופאפ — נטען גם באדמין לתצוגה המקדימה |
| `assets/js/sella-checkout-offers-admin.js` | תצוגה מקדימה חיה, שליפת מחיר רגיל, הצגה מותנית של שדות התנאי |
| `assets/css/sella-checkout-offers-admin.css` | פריסת מסך הניהול |

### מנגנון באנרים
| נתיב | תוכן |
|---|---|
| `inc/sella-shop-banners.php` | options, מסך ניהול, הדפסת הסליידר, enqueue (301 שורות) |
| `assets/js/sella-banners.js` | סליידר: מיקום בדף, חיצים, נקודות, autoplay, RTL |
| `assets/css/sella-banners.css` | עיצוב הסליידר |
| `assets/js/sella-banners-admin.js` | wp.media picker, repeater, sortable |
| `assets/css/sella-banners-admin.css` | פריסת מסך הניהול |

### עיצוב הצ'קאאוט
| נתיב | תוכן |
|---|---|
| `style.css` שורות **190–805** | כל עיצוב הצ'קאאוט: שדות, floating labels, פירוט הזמנה, טוגל מובייל, מדיה קווארי |
| `style.css` שורות **1115–1137** | מחיר בסרגל הסטיקי של דף מוצר (אופציונלי) |
| `assets/js/sella-checkout-fields.js` | floating labels + טוגל סיכום ההזמנה במובייל (146 שורות) |
| `functions.php` | שני פילטרים + enqueue (ראו 4.3) |
| `inc/sella-customer-fields.php` | טלפון חובה + צ'קבוקס אישור תוכן שיווקי (ראו 4.3) |

---

## 2. מנגנון ה-Upsell

### 2.1 ארכיטקטורה
- **אחסון:** CPT פרטי `sella_upsell` (`public => false`, `show_ui => false`). לא משתמשים במסך העריכה של וורדפרס אלא במסך ניהול משלנו (`add_menu_page` → `sella-upsell-popups`), שמטפל ב-save/delete דרך `admin_init`.
- **בניית הנתונים:** `sella_upsell_get_active( $context = null )` מחזירה רק פופאפים שעברו את כל הסינונים, עם רשימת ספרים מוכנה (id, שם, מחיר, מחיר מקורי, תמונה, קישור). התוצאה עוברת ל-JS ב-`wp_localize_script` תחת `sellaUpsellData`.
- **תקרה:** `SELLA_UPSELL_MAX_ITEMS = 30`. הרשימה נטענת inline בכל עמוד — בלי התקרה, קטגוריה עם 170 ספרים מייצרת 170 קריאות `wc_get_product` ועשרות KB בכל טעינה.

### 2.2 מפתחות ה-meta
```
_sella_upsell_enabled              '1' / '0'
_sella_upsell_products             array<int>   מוצרים שנבחרו ידנית (הסדר = סדר הסליידר)
_sella_upsell_product_categories   array<int>   term_id
_sella_upsell_product_tags         array<int>   term_id
_sella_upsell_trigger              immediate | delay | scroll | exit_intent | add_to_cart
_sella_upsell_delay                int שניות
_sella_upsell_frequency            every_visit | session | day | once
_sella_upsell_scope                all | product | shop | cart | checkout | products | pages
_sella_upsell_scope_products       array<int>
_sella_upsell_scope_pages          array<int>
_sella_upsell_audience             all | logged_in | logged_out
_sella_upsell_cart_rule            any | contains | not_contains | not_empty | empty
_sella_upsell_cart_products        array<int>   למי שהתנאי מתייחס אליו
_sella_upsell_cart_categories      array<int>
_sella_upsell_cart_min_total       float        0 = ללא
_sella_upsell_cart_max_total       float        0 = ללא
_sella_upsell_cart_cross_sells     '1' / '0'    הוספת Cross-sells של מה שבסל
```

### 2.3 חוקיות לפי הסל
`sella_upsell_cart_snapshot()` מחזירה `['products' => ids, 'terms' => category term ids, 'total' => float]`.
`sella_upsell_cart_rule_passes( $popup_id, $snapshot )` מכריעה. הסכום נלקח מ-`get_displayed_subtotal()` (כולל מע"מ אם התצוגה כוללת), עם נפילה חזרה ל-`get_subtotal()`.

> **קריטי:** התנאים מחושבים בשרת בזמן טעינת העמוד. אחרי הוספה לסל ב-AJAX הם מתיישנים. לכן קיים endpoint שני, `wc_ajax_sella_upsell_refresh`, שמחשב מחדש את הרשימה. מכיוון של-AJAX אין הקשר עמוד (`is_product()` וכו' לא עובדים שם), הדפדפן שולח חזרה את ההקשר שנשמר ב-`sellaUpsellData.context` (`scope` + `object_id`), ו-`sella_upsell_get_active()` מקבלת אותו כפרמטר.

### 2.4 Endpoints
שניהם על `WC_AJAX` (לא admin-ajax) עם nonce `sella_upsell_add`:
```php
add_action( 'wc_ajax_sella_upsell_add', 'sella_upsell_ajax_add_to_cart' );
add_action( 'wc_ajax_sella_upsell_refresh', 'sella_upsell_ajax_refresh' );
// כתובת: WC_AJAX::get_endpoint( 'sella_upsell_add' )
```

### 2.5 תיוג מקור ההזמנה
1. בהוספה מהפופאפ: `sella_upsell_remember_source()` שומר ב-WC session תחת `sella_upsell_source` מפה של `product_id => [popup_id, title, time]`.
2. ביצירת ההזמנה: `woocommerce_checkout_create_order_line_item` מוסיף לשורה meta בשם `_sella_upsell_source` (+ `_sella_upsell_popup_id`).
   **הקידומת בקו תחתון היא מה שמסתיר את זה מהלקוח** — ווקומרס לא מציג meta שמתחיל ב-`_`.
3. `woocommerce_checkout_order_processed` (+ `woocommerce_store_api_checkout_order_processed` לבלוקים) מוסיף `_sella_upsell_used = yes` על ההזמנה, כותב הערת הזמנה פרטית, ומנקה את הסשן.
4. `woocommerce_after_order_itemmeta` מצייר תגית במסך ההזמנה בניהול.

### 2.6 התנהגות הפרונט
- ספר שנוסף לסל יורד מכל הפופאפים ומיד מוצגת ההצעה הבאה. הרשימה נשמרת ב-`sessionStorage` תחת `sella-upsell-added`, כדי שלא יחזור גם אחרי רענון או אחרי רענון רשימה מהשרת.
- אחרי הוספה: על דף הסל הקלאסי מתבצע reload, בצ'קאאוט קלאסי `update_checkout`, ובבלוקים `wp.data.dispatch('wc/store/cart').invalidateResolutionForStore()`. ראו סעיף 4.4.

### 2.7 מה להתאים באתר אחר
- טקסטים בעברית קשיחים בקוד (תוויות מסך הניהול, "הוספה לסל", "נוסף לסל!").
- צבע המותג `--sella-upsell-accent: #746fed` ב-`assets/css/sella-upsell.css`.
- מיקום הכרטיס: `inset-inline-start` + `inset-block-end` ב-`.sella-upsell`. אצלנו הפינה השמאלית-תחתונה תפוסה (ווידג'ט + התראות), לכן הכרטיס בימין. **בדקו מה תפוס באתר היעד.**
- הכלל `body:has(.sella-sticky-product-cart.is-visible)` מרים את הכרטיס מעל סרגל סטיקי — רלוונטי רק אם קיים סרגל כזה.

---

## 3. מנגנון הבאנרים

### 3.1 אחסון
שני options (לא CPT):
```
sella_shop_banners           array של [image_id, mobile_id, link, alt, enabled]
sella_shop_banners_settings  ['autoplay' => int שניות, 0 = כבוי]
```

### 3.2 מסך הניהול
`add_submenu_page( 'sella-upsell-popups', ... 'sella-shop-banners' )` — תת-תפריט של מסך ה-Upsell. **אם מעבירים רק את הבאנרים, יש להחליף ל-`add_menu_page` או להורה אחר.**
הבחירה מספריית המדיה דורשת `wp_enqueue_media()` ב-`admin_enqueue_scripts`. שורה חדשה נוצרת משכפול תבנית `<script type="text/html" id="tmpl-sella-banner-row">` והחלפת `__i__` באינדקס הבא.

### 3.3 הצגה בפרונט
המערך מודפס ב-`wp_footer` (עדיפות 5) כשהוא `hidden`, וה-JS מעביר אותו למקום הנכון:

```js
// assets/js/sella-banners.js
var TARGET = '.books-grid-section, .store-archive-section';
```

> **זו השורה הראשונה לשנות באתר אחר.** בחרו את ה-selector של ה-section שמעליו הבאנרים צריכים לשבת. הסיבה להעברה ב-JS: האזור נבנה ב-Elementor ואין לו hook של PHP.

ה-enqueue מוגבל ל-`is_shop() || is_product_taxonomy()` — התאימו אם הבאנרים צריכים להופיע במקום אחר.

### 3.4 גדלים
- דסקטופ: **2000×352** (יחס ≈ 5.7:1).
- מובייל: שדה נפרד `mobile_id` לכל באנר. בלעדיו, רצועה של 2000×352 יורדת בטלפון לגובה ~64px והטקסט בתוכה לא קריא. מומלץ ~1000×700.
- ה-markup משתמש ב-`<picture>` עם `<source media="(max-width: 767px)">`, ותמיד עם `width`/`height` על ה-`img` כדי למנוע קפיצת פריסה.
- במובייל החיצים מוסתרים (הם מכסים באנר נמוך); נשארות החלקה ונקודות.

---

## 4. עיצוב הצ'קאאוט

### 4.1 מה כלול
1. **Floating labels** — הלייבל יושב בתוך השדה ועולה ומתכווץ בהקלדה. ה-JS מוסיף `.sella-float-field` לכל `.form-row` ומתחזק `.is-filled` לפי הערך; ה-CSS עושה את התנועה. מטפל גם ב-autofill של הדפדפן (דרך `animationstart` על keyframes ריק) וב-back/forward (`pageshow`).
2. **פירוט הזמנה שטוח** — בלי הצללות, כותרות שקטות, קווים דקים. בנוי להיראות כמו דף הסל.
3. **כמות כבאדג' על העטיפה** במקום "× 2" מתחת לשם.
4. **סיכום הזמנה מתקפל במובייל** — כפתור עם שם, חץ וסכום, פותח וסוגר את `#order_review`.
5. **טלפון כשדה חובה** — בצ'קאאוט, בכתובות ובפרטי החשבון.
6. **צ'קבוקס אישור תוכן שיווקי** — בצ'קאאוט ובפרטי החשבון, לא מסומן כברירת מחדל. נשמר ב-user meta `sella_marketing_consent` (`1`/`0`) ועל ההזמנה ב-`_sella_marketing_consent` (`yes`/`no`), ומוצג באדמין בהזמנה ובפרופיל המשתמש.

### 4.2 CSS
העתיקו מ-`style.css` את שורות **190–805**. הבלוק פותח במשתנים:
```css
.woocommerce-checkout form.checkout {
  --sella-field-height: 60px;
  --sella-field-bg: #f7f7f9;
  --sella-field-border: #e7e7ee;
  --sella-field-label: #7b7f8a;
  --sella-accent: #746fed;   /* צבע המותג — לשנות */
  --sella-error: #d7263d;
}
```

### 4.3 PHP (ב-`functions.php`)
```php
sella_checkout_product_thumbnail()    // woocommerce_cart_item_name — תמונה + באדג' כמות
sella_checkout_hide_inline_quantity() // woocommerce_checkout_cart_item_quantity → ''
// + enqueue של sella-checkout-fields.js כש-is_checkout() && ! is_order_received_page()
```

טלפון החובה ואישור התוכן השיווקי יושבים בקובץ נפרד — להעתיק את `inc/sella-customer-fields.php` כמו שהוא ולטעון אותו עם `require_once`. דורש WooCommerce 8.7 ומעלה בשביל ה-hook `woocommerce_edit_account_form_fields` (בגרסה ישנה יותר השדות פשוט לא יופיעו בפרטי החשבון, והשמירה תמשיך לעבוד).

### 4.4 ההנחות על המבנה — לבדוק לפני העברה
ה-CSS וה-JS מכוונים ל-markup של widget הצ'קאאוט של Elementor Pro:

```
.e-checkout__container
└─ .e-checkout__column.e-checkout__column-end
   └─ .e-checkout__column-inner
      ├─ .e-checkout__order_review        ← יש עליו box-shadow שמורידים
      │  ├─ h3#order_review_heading
      │  └─ div#order_review              ← מכיל רק את הטבלה
      └─ .e-checkout__order_review-2
         └─ #payment                      ← כפתור התשלום, אח נפרד
```

> **אזהרה חשובה:** בצ'קאאוט קלאסי (בלי Elementor) `#payment` יושב **בתוך** `#order_review`. הכלל שמקפל את הסיכום במובייל (`max-height: 0` על `#order_review`) יסתיר במקרה כזה גם את כפתור התשלום. באתר יעד כזה יש לעטוף את הטבלה ב-container משלכם ולקפל אותו, או לשנות את ה-selector.

הסרת ההצללות מכוונת לרשימת wrappers ספציפית — עדכנו אותה לפי האתר:
```css
.woocommerce-checkout [class*="e-checkout__order_review"],
.woocommerce-checkout .e-coupon-box,
.woocommerce-checkout .woocommerce-checkout-payment,
.woocommerce-checkout .col-1, .col-2,
.woocommerce-checkout .woocommerce-billing-fields,
.woocommerce-checkout .woocommerce-additional-fields,
.woocommerce-checkout .woocommerce-terms-and-conditions,
.woocommerce-checkout #payment { box-shadow: none !important; }
```

---

## 4א. הצעות בדף התשלום

- **אחסון:** CPT פרטי `sella_checkout_offer`, כל ההגדרות במערך אחד ב-meta `_sella_checkout_offer` (ספר, מחיר, כותרת, טקסט, טקסט כפתור, תנאי, סכום מינימום/מקסימום, פעיל). הסדר בסליידר = `menu_order`. הגדרות הפופאפ (השהיה, תדירות) ב-option `sella_checkout_offers_settings`.
- **מסך ניהול:** תת-תפריט `sella-checkout-offers` תחת `sella-upsell-popups`.
- **המחיר המיוחד נאכף בשרת:** השורה שנוספה מהפופאפ נושאת cart item data בשם `sella_checkout_offer`. ב-`woocommerce_before_calculate_totals` (וגם ב-`woocommerce_cart_loaded_from_session`, בשביל המיני-סל) המחיר מוחל רק אם ההצעה עדיין פעילה והתנאי עדיין מתקיים; אחרת חוזר המחיר הרגיל. כמות קבועה 1.
- **שורות מהצעה לא נספרות בתנאים** — אחרת הצעה יכולה לפתוח את עצמה או הצעה אחרת.
- **ההוספה** עוברת דרך `wc_ajax_sella_offer_add`, שבודק מחדש תנאי, מלאי ושהספר לא כבר בסל.
- **בהזמנה:** meta מוסתר `_sella_checkout_offer_id` על השורה + תגית במסך ההזמנה באדמין.

---

## 5. מלכודות שכבר נפתרו — אל תחזרו עליהן

1. **מחירים משובשים.** `wp_strip_all_tags( $product->get_price_html() )` משאיר את טקסט הקורא-מסך של ווקומרס ("המחיר המקורי היה…") ואת הישות `&#8362;` כטקסט גולמי. בונים מחיר עם `wc_price()` + `html_entity_decode(..., ENT_QUOTES, 'UTF-8')`, שולחים מחיר נוכחי ומחיר מקורי בנפרד, ומרנדרים עם `textContent`.
2. **תמונות חתוכות.** `woocommerce_thumbnail` הוא חיתוך ריבועי קשיח — הוא קוצץ עטיפות ספרים. לעטיפה מלאה: גודל `medium`, ובלי `aspect-ratio`/`object-fit` ב-CSS.
3. **AJAX של סל.** להשתמש ב-`WC_AJAX::get_endpoint()` ולא ב-admin-ajax: הבקשה רצה בהקשר פרונט (`is_admin()` שקר), עם סל וסשן טעונים, ו-`woocommerce_mini_cart()` עובד.
4. **רענון אחרי הוספה לסל, לפי סוג העמוד:**
   - דף סל קלאסי — טבלת הסל מרונדרת רק בשרת. fragments לא יוסיפו שורה חדשה, צריך reload.
   - צ'קאאוט קלאסי — `jQuery(document.body).trigger('update_checkout')` מרנדר מחדש את כל סיכום ההזמנה, כולל שורה חדשה.
   - בלוקים — `wp.data.dispatch('wc/store/cart').invalidateResolutionForStore()`.
5. **`querySelector('a, b, c')` מחזיר לפי סדר ה-DOM, לא לפי סדר ה-selectors.** אם צריך עדיפות, מריצים לולאה על מערך selectors. (כך נמצא מחיר המוצר הנכון ולא של מוצר קשור.)
6. **בתבניות Elementor אין `.summary`.** כל selector שמסתמך על ה-markup הקלאסי של WooCommerce עלול להחזיר null. לבדוק מול ה-DOM האמיתי.
7. **RTL — ארבע מלכודות:**
   - `inset-inline-*` על אלמנט `fixed`/`absolute` נפתר מול **כיוון ה-containing block**, לא מול ה-`dir` של האלמנט עצמו.
   - חץ (chevron) שנבנה מ-borders חייב `border-right` + `border-bottom` **פיזיים**. זוג לוגי מתהפך ב-RTL ומייצר חץ שמצביע הצידה.
   - מונה "1 / 2" מתהפך ל-"2 / 1". צריך `direction: ltr` על האלמנט.
   - בסליידר: `scrollLeft` נמדד מהימין ב-RTL. לא לחשב `offsetLeft` מוחלט — לגלול בדלתא יחסית: `track.scrollBy({ left: slide.getBoundingClientRect().left - track.getBoundingClientRect().left })`. עובד זהה בשני הכיוונים.
8. **טבלה שהופכת ל-blocks במובייל:** אם מוסיפים `display:block` ל-`table, tbody, tr, td` ושוכחים את **`tfoot`**, שורות הסיכום לא נפרסות לרוחב מלא.
9. **`th` של WooCommerce ממורכז** בחלק מהתבניות — להוסיף `text-align: start` במפורש.
10. **פאנלים של Elementor נושאים box-shadow משלהם** — נדרש `box-shadow: none !important` על wrapper ספציפי, לא על `*`.
11. **קאש נכסים:** אחרי כל שינוי CSS/JS להעלות את קבוע הגרסה שב-enqueue.

---

## 6. סדר עבודה מומלץ

1. להעתיק את שני קבצי ה-`inc/` ואת חמשת קבצי ה-assets של Upsell + באנרים, ולחבר ב-`functions.php`.
2. להריץ `php -l` על קבצי ה-PHP.
3. **להחליף את ה-`TARGET` בקובץ `assets/js/sella-banners.js`** ל-selector של האתר החדש.
4. לבדוק במסכי הניהול: יצירת פופאפ, יצירת באנר.
5. רק אז לגעת בצ'קאאוט: קודם לפתוח את דף התשלום ולמפות את ה-DOM האמיתי (Elementor או קלאסי), ואז להעתיק את בלוק ה-CSS ולהתאים selectors.
6. לבדוק בדסקטופ ובמובייל, ובמיוחד: הוספה לסל מהפופאפ בתוך דף הסל ובתוך הצ'קאאוט.
