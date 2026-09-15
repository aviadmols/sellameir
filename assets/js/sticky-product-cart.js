(function () {
  'use strict';

  function boot() {
    var form = document.querySelector('form.cart');
    var originalButton = form && form.querySelector('.single_add_to_cart_button');

    if (!form || !originalButton || document.querySelector('.sella-sticky-product-cart')) {
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
    var productPrice = document.querySelector('.summary .price');

    title.textContent = productTitle ? productTitle.textContent.trim() : '';
    if (productPrice) {
      price.innerHTML = productPrice.innerHTML;
    }
    stickyButton.textContent = originalButton.textContent.trim() || 'הוספה לסל';

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

    if (window.MutationObserver) {
      new MutationObserver(syncButton).observe(originalButton, {
        attributes: true,
        childList: true,
        characterData: true,
        subtree: true,
      });
    }

    if (window.jQuery) {
      window.jQuery(document.body).on('found_variation reset_data hide_variation show_variation', syncButton);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();