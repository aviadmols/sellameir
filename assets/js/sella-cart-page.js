/**
 * Cart page (the WooCommerce cart block): the block's labels in the site's
 * own words. The block translates in the browser through wp.i18n, which
 * runs this filter on every string; the English source text is matched, so
 * the WooCommerce translation files never matter.
 */
(function () {
  'use strict';

  if (!window.wp || !window.wp.hooks || !window.wp.hooks.addFilter) {
    return;
  }

  var words = {
    'Proceed to Checkout': 'לתשלום',
    'Cart totals': 'סיכום הזמנה',
    'Add coupons': 'יש לך קוד קופון?',
    'Add a coupon': 'יש לך קוד קופון?',
    'Estimated total': 'סה״כ לתשלום',
    'Product': 'ספר',
    'Details': 'פרטים',
    'Total': 'סה״כ',
    'Remove item': 'הסרה מהסל',
    'Your cart is currently empty!': 'הסל שלך ריק',
    'Browse store': 'לחנות'
  };

  function translate(translation, text) {
    return Object.prototype.hasOwnProperty.call(words, text) ? words[text] : translation;
  }

  window.wp.hooks.addFilter('i18n.gettext_woocommerce', 'sella/cart-page', translate, 10);
  window.wp.hooks.addFilter('i18n.gettext_with_context_woocommerce', 'sella/cart-page', translate, 10);
})();
