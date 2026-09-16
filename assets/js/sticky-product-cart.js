(function () {
  'use strict';

  function boot() {
    var originalButton = document.querySelector(
      '.single_add_to_cart_button, button[name="add-to-cart"], a.add_to_cart_button'
    );

    if (!originalButton || document.querySelector('.sella-sticky-product-cart')) {
      return;
    }

    var bar = document.createElement('div');
    bar.className = 'sella-sticky-product-cart';
    bar.setAttribute('dir', document.documentElement.getAttribute('dir') || 'rtl');
    bar.innerHTML =
      '<div class="sella-sticky-product-cart__inner">' +
        '<div class="sella-sticky-product-cart__details">' +
          '<strong class="sella-sticky-product-cart__title"></strong>' +
          '<span class="sella-sticky-product-cart__price"></span>' +
        '</div>' +
        '<button type="button" class="sella-sticky-product-cart__button"></button>' +
      '</div>';

    var title = bar.querySelector('.sella-sticky-product-cart__title');
    var price = bar.querySelector('.sella-sticky-product-cart__price');
    var stickyButton = bar.querySelector('.sella-sticky-product-cart__button');
    var productTitle = document.querySelector('.product_title');

    title.textContent = productTitle ? productTitle.textContent.trim() : '';
    stickyButton.textContent = originalButton.textContent.trim() || 'הוספה לסל';

    /**
     * The product template is built in Elementor, so there is no .summary
     * wrapper — look through the places the price widget actually renders,
     * most specific first (a comma list would return whichever comes first
     * in the document, which can be a related product).
     */
    function findPrice() {
      var selectors = [
        '.woocommerce-variation-price .price',
        '.elementor-widget-woocommerce-product-price .price',
        '.summary .price',
        '.entry-summary .price',
        '.product .price'
      ];

      for (var i = 0; i < selectors.length; i++) {
        var found = document.querySelector(selectors[i]);
        if (found && found.offsetParent !== null) {
          return found;
        }
      }
      return null;
    }

    function syncPrice() {
      var source = findPrice();
      if (!source) {
        price.innerHTML = '';
        return;
      }

      // Keep <del>/<ins> so the sale price reads as a sale, but drop
      // WooCommerce's screen-reader sentences.
      var clone = source.cloneNode(true);
      Array.prototype.forEach.call(clone.querySelectorAll('.screen-reader-text'), function (node) {
        node.parentNode.removeChild(node);
      });
      price.innerHTML = clone.innerHTML.trim();
    }

    syncPrice();

    function syncButton() {
      stickyButton.disabled = originalButton.disabled;
      stickyButton.classList.toggle('disabled', originalButton.disabled);
      stickyButton.textContent = originalButton.textContent.trim() || 'הוספה לסל';
    }

    stickyButton.addEventListener('click', function () {
      if (!stickyButton.disabled) {
        originalButton.click();
      }
    });

    document.body.appendChild(bar);
    document.body.classList.add('sella-has-sticky-product-cart');
    syncButton();

    function syncVisibility() {
      bar.classList.toggle('is-visible', window.scrollY > 80);
    }

    window.addEventListener('scroll', syncVisibility, { passive: true });
    syncVisibility();

    if (window.MutationObserver) {
      new MutationObserver(syncButton).observe(originalButton, {
        attributes: true,
        childList: true,
        characterData: true,
        subtree: true,
      });
    }

    if (window.jQuery) {
      window.jQuery(document.body).on('found_variation reset_data hide_variation show_variation', function () {
        syncButton();
        syncPrice();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();