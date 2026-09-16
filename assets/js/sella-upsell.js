(function () {
  'use strict';

  var data = window.sellaUpsellData || {};
  var popups = data.popups || [];
  var labels = data.labels || {};
  var activePopup = null;
  var currentIndex = 0;
  var root = null;
  var nodes = {};
  var hideTimer = null;

  function storageFor(frequency) {
    if (frequency === 'session') {
      return window.sessionStorage;
    }
    if (frequency === 'every_visit') {
      return null;
    }
    return window.localStorage;
  }

  function canShow(popup) {
    if (popup.frequency === 'every_visit') {
      return true;
    }

    var storage = storageFor(popup.frequency);
    if (!storage) {
      return true;
    }

    try {
      var seen = storage.getItem('sella-upsell-' + popup.id);
      if (!seen) {
        return true;
      }
      if (popup.frequency === 'day') {
        return seen !== new Date().toISOString().slice(0, 10);
      }
      return false;
    } catch (e) {
      return true;
    }
  }

  function markShown(popup) {
    var storage = storageFor(popup.frequency);
    if (!storage) {
      return;
    }
    try {
      storage.setItem('sella-upsell-' + popup.id, popup.frequency === 'day' ? new Date().toISOString().slice(0, 10) : '1');
    } catch (e) {
      /* Private browsing: show it again next time. */
    }
  }

  function build() {
    root = document.createElement('aside');
    root.className = 'sella-upsell';
    root.setAttribute('dir', document.documentElement.getAttribute('dir') || 'rtl');
    root.hidden = true;
    root.innerHTML =
      '<div class="sella-upsell__card" role="dialog" aria-label="הצעה מיוחדת">' +
        '<button type="button" class="sella-upsell__close" data-upsell-close aria-label="' + (labels.close || 'סגירה') + '">&times;</button>' +
        '<div class="sella-upsell__content">' +
          '<div class="sella-upsell__text">' +
            '<span class="sella-upsell__tag"></span>' +
            '<h3 class="sella-upsell__name"></h3>' +
            '<p class="sella-upsell__price">' +
              '<span class="sella-upsell__price-now"></span>' +
              '<del class="sella-upsell__price-was"></del>' +
            '</p>' +
            '<button type="button" class="sella-upsell__btn" data-upsell-add></button>' +
          '</div>' +
          '<a class="sella-upsell__cover" href="#" tabindex="-1" aria-hidden="true">' +
            '<img class="sella-upsell__cover-img" src="" alt="" loading="lazy" />' +
          '</a>' +
        '</div>' +
        '<div class="sella-upsell__nav">' +
          '<button type="button" class="sella-upsell__arrow" data-upsell-prev aria-label="' + (labels.previous || 'הקודם') + '">&#8594;</button>' +
          '<span class="sella-upsell__counter"></span>' +
          '<button type="button" class="sella-upsell__arrow" data-upsell-next aria-label="' + (labels.next || 'הבא') + '">&#8592;</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);

    nodes = {
      card: root.querySelector('.sella-upsell__card'),
      tag: root.querySelector('.sella-upsell__tag'),
      name: root.querySelector('.sella-upsell__name'),
      priceNow: root.querySelector('.sella-upsell__price-now'),
      priceWas: root.querySelector('.sella-upsell__price-was'),
      button: root.querySelector('.sella-upsell__btn'),
      cover: root.querySelector('.sella-upsell__cover'),
      coverImg: root.querySelector('.sella-upsell__cover-img'),
      nav: root.querySelector('.sella-upsell__nav'),
      counter: root.querySelector('.sella-upsell__counter')
    };

    root.addEventListener('click', function (event) {
      if (event.target.closest('[data-upsell-close]')) {
        closePopup();
        return;
      }
      if (event.target.closest('[data-upsell-add]')) {
        addToCart(event.target.closest('[data-upsell-add]'));
        return;
      }
      if (event.target.closest('[data-upsell-prev]')) {
        move(-1);
        return;
      }
      if (event.target.closest('[data-upsell-next]')) {
        move(1);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && root.classList.contains('is-open')) {
        closePopup();
      }
    });
  }

  function render() {
    if (!activePopup) {
      return;
    }

    var item = activePopup.items[currentIndex];
    if (!item) {
      closePopup();
      return;
    }

    var total = activePopup.items.length;

    nodes.tag.textContent = activePopup.title || '';
    nodes.tag.hidden = !activePopup.title;
    nodes.name.textContent = item.name;
    nodes.priceNow.textContent = item.price || '';
    nodes.priceWas.textContent = item.regular || '';
    nodes.priceWas.hidden = !item.regular;
    nodes.coverImg.src = item.image;
    nodes.coverImg.alt = item.name;
    nodes.cover.href = item.url || '#';
    nodes.counter.textContent = total > 1 ? currentIndex + 1 + ' / ' + total : '';
    nodes.nav.hidden = total < 2;

    resetButton();
  }

  function resetButton() {
    nodes.button.disabled = false;
    nodes.button.className = 'sella-upsell__btn';
    nodes.button.textContent = labels.add || 'הוספה לסל';
  }

  function move(direction) {
    if (!activePopup || activePopup.items.length < 2) {
      return;
    }
    currentIndex = (currentIndex + direction + activePopup.items.length) % activePopup.items.length;
    nodes.card.classList.remove('is-swapping');
    void nodes.card.offsetWidth;
    nodes.card.classList.add('is-swapping');
    render();
  }

  function removeFromAllPopups(productId) {
    productId = String(productId || '');
    if (!productId) {
      return;
    }
    for (var i = 0; i < popups.length; i++) {
      popups[i].items = popups[i].items.filter(function (item) {
        return String(item.id) !== productId;
      });
    }
  }

  function openPopup(popup) {
    if (!popup || !popup.items.length || (root && root.classList.contains('is-open'))) {
      return;
    }
    if (!root) {
      build();
    }
    window.clearTimeout(hideTimer);
    activePopup = popup;
    currentIndex = 0;
    markShown(popup);
    render();
    root.hidden = false;
    /* Let the browser paint the hidden state before transitioning in. */
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        root.classList.add('is-open');
      });
    });
  }

  function closePopup() {
    if (!root) {
      return;
    }
    root.classList.remove('is-open');
    hideTimer = window.setTimeout(function () {
      root.hidden = true;
    }, 400);
  }

  function addToCart(button) {
    var item = activePopup && activePopup.items[currentIndex];
    if (!item || button.disabled) {
      return;
    }

    button.disabled = true;
    button.textContent = labels.adding || 'מוסיף...';

    var request = window.fetch(data.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: 'action=sella_upsell_add&nonce=' + encodeURIComponent(data.nonce) + '&product_id=' + encodeURIComponent(item.id) + '&quantity=1'
    }).then(function (response) {
      return response.json();
    });

    request.then(function (response) {
      if (!response || !response.success) {
        failed(button, response && response.data && response.data.message);
        return;
      }

      button.className = 'sella-upsell__btn is-added';
      button.textContent = labels.added || 'נוסף לסל!';

      var reloading = syncStore(response.data, button);
      removeFromAllPopups(item.id);

      window.setTimeout(function () {
        if (reloading) {
          return;
        }
        if (!activePopup.items.length) {
          closePopup();
          return;
        }
        currentIndex = Math.min(currentIndex, activePopup.items.length - 1);
        render();
      }, 900);
    }).catch(function () {
      failed(button);
    });
  }

  function failed(button, message) {
    button.disabled = false;
    button.className = 'sella-upsell__btn is-error';
    button.textContent = message || labels.error || 'לא הצלחנו להוסיף לסל';
    window.setTimeout(resetButton, 2600);
  }

  /**
   * Push the new cart into whatever the current page is showing.
   * Returns true when the page is about to reload.
   */
  function syncStore(payload, button) {
    var $ = window.jQuery;
    payload = payload || {};

    if ($ && payload.fragments) {
      $.each(payload.fragments, function (key, value) {
        try {
          $(key).replaceWith(value);
        } catch (e) {
          /* A theme may not render this fragment. */
        }
      });
    }

    if ($) {
      $(document.body).trigger('added_to_cart', [payload.fragments || {}, payload.cartHash || '', $(button)]);
      $(document.body).trigger('wc_fragment_refresh');
    }

    var store = window.wp && window.wp.data && typeof window.wp.data.dispatch === 'function' ? window.wp.data.dispatch('wc/store/cart') : null;
    var isBlock = !!document.querySelector('.wc-block-cart, .wc-block-checkout');

    if (isBlock && store && typeof store.invalidateResolutionForStore === 'function') {
      store.invalidateResolutionForStore();
      return false;
    }

    /* Classic checkout re-renders the whole order review, new line included. */
    if (data.isCheckout && $ && document.querySelector('form.checkout')) {
      $(document.body).trigger('update_checkout');
      return false;
    }

    /* The classic cart table is server rendered only, so reload to show the new row. */
    if (data.isCart || data.isCheckout) {
      window.setTimeout(function () {
        window.location.reload();
      }, 900);
      return true;
    }

    return false;
  }

  function nextEligible() {
    for (var i = 0; i < popups.length; i++) {
      if (popups[i].items.length && canShow(popups[i])) {
        return popups[i];
      }
    }
    return null;
  }

  function showEligible() {
    openPopup(nextEligible());
  }

  function hasTrigger(name) {
    for (var i = 0; i < popups.length; i++) {
      if (popups[i].trigger === name) {
        return true;
      }
    }
    return false;
  }

  function boot() {
    if (!popups.length) {
      return;
    }

    if (hasTrigger('immediate')) {
      showEligible();
    }

    for (var i = 0; i < popups.length; i++) {
      if (popups[i].trigger === 'delay') {
        window.setTimeout(showEligible, Math.max(0, parseInt(popups[i].delay, 10) || 0) * 1000);
        break;
      }
    }

    if (hasTrigger('scroll')) {
      window.addEventListener('scroll', function () {
        if ((window.scrollY + window.innerHeight) / document.documentElement.scrollHeight > 0.62) {
          showEligible();
        }
      }, { passive: true });
    }

    if (hasTrigger('exit_intent')) {
      document.addEventListener('mouseleave', function (event) {
        if (event.clientY <= 0) {
          showEligible();
        }
      });
    }

    if (window.jQuery) {
      window.jQuery(document.body).on('added_to_cart', function (event, fragments, cartHash, button) {
        if (button && button.jquery) {
          button = button[0];
        }
        if (button && button.closest && button.closest('.sella-upsell')) {
          return;
        }
        var productId = button && (button.value || button.getAttribute('data-product_id') || button.getAttribute('data-product-id'));
        removeFromAllPopups(productId);
        if (hasTrigger('add_to_cart')) {
          showEligible();
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
