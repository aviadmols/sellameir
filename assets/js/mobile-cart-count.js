/**
 * Sella — mirror the Smart Cart item count onto the mobile header cart icon.
 *
 * The mobile header uses the sibolet-header-icons icon list (search / cart /
 * account) which is already wired to open the cart drawer. It has no item
 * count, so we mirror the live count from the Smart Cart trigger
 * (.sc-trigger-count, updated by the cart script) into a small badge:
 * white circle, black text.
 */
(function () {
  'use strict';

  function getSourceCount() {
    var el = document.querySelector('.sc-header-icons .sc-trigger-count, .sc-trigger-count');
    if (!el) {
      return null;
    }
    var n = parseInt(el.textContent.replace(/\D/g, ''), 10);
    return isNaN(n) ? null : n;
  }

  function getCartItems() {
    // Cart icon is the 2nd item in each sibolet icon list (see cart snippet).
    return document.querySelectorAll(
      '.sibolet-header-icons .elementor-icon-list-item:nth-child(2)'
    );
  }

  function render() {
    var count = getSourceCount();
    var items = getCartItems();

    for (var i = 0; i < items.length; i++) {
      var badge = items[i].querySelector('.sella-mobile-cart-count');

      if (!count) {
        if (badge) {
          badge.parentNode.removeChild(badge);
        }
        continue;
      }

      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'sella-mobile-cart-count';
        items[i].appendChild(badge);
      }
      badge.textContent = String(count);
    }
  }

  function boot() {
    render();

    // Follow live updates of the Smart Cart count.
    var source = document.querySelector('.sc-header-icons .sc-trigger-count, .sc-trigger-count');
    if (source && window.MutationObserver) {
      new MutationObserver(render).observe(source, {
        childList: true,
        characterData: true,
        subtree: true,
      });
    }

    // WooCommerce AJAX events as a fallback.
    if (window.jQuery) {
      window.jQuery(document.body).on(
        'added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded',
        function () {
          window.setTimeout(render, 100);
        }
      );
    }

    // The count element may render after DOM ready — retry briefly.
    var tries = 0;
    var timer = window.setInterval(function () {
      tries++;
      render();
      if (getSourceCount() !== null || tries >= 20) {
        window.clearInterval(timer);
      }
    }, 300);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
