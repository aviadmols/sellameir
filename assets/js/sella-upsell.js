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
            '<p class="sella-upsell__timer" hidden>' +
              '<span class="sella-upsell__timer-label"></span>' +
              '<span class="sella-upsell__timer-clock" dir="ltr"></span>' +
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
      counter: root.querySelector('.sella-upsell__counter'),
      timer: root.querySelector('.sella-upsell__timer'),
      timerLabel: root.querySelector('.sella-upsell__timer-label'),
      timerClock: root.querySelector('.sella-upsell__timer-clock')
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

  /**
   * Books already added stay out of every popup for the rest of the visit,
   * even after a reload or a server refresh.
   */
  var ADDED_KEY = 'sella-upsell-added';
  var added = loadAdded();

  function loadAdded() {
    var map = {};
    try {
      var stored = JSON.parse(window.sessionStorage.getItem(ADDED_KEY) || '[]');
      for (var i = 0; i < stored.length; i++) {
        map[String(stored[i])] = true;
      }
    } catch (e) {
      /* Private browsing: the list simply starts empty. */
    }
    return map;
  }

  function markAdded(productId) {
    added[String(productId)] = true;
    try {
      window.sessionStorage.setItem(ADDED_KEY, JSON.stringify(Object.keys(added)));
    } catch (e) {
      /* Nothing to do; the in-memory list still holds for this page. */
    }
  }

  function pruneAdded() {
    for (var i = 0; i < popups.length; i++) {
      popups[i].items = popups[i].items.filter(function (item) {
        return !added[String(item.id)];
      });
    }
  }

  function removeFromAllPopups(productId) {
    productId = String(productId || '');
    if (!productId) {
      return;
    }
    markAdded(productId);
    pruneAdded();
  }

  /**
   * Display-only countdown. The end time is kept for the visit, so moving
   * between pages does not restart it; at zero it quietly starts over.
   */
  var timerInterval = null;

  function timerEnd(popup) {
    var key = 'sella-upsell-timer-' + popup.id;
    var length = popup.timer.minutes * 60000;
    var end = 0;
    try {
      end = parseInt(window.sessionStorage.getItem(key), 10) || 0;
    } catch (e) {
      /* Private browsing: count from now. */
    }
    if (end <= Date.now() || end > Date.now() + length) {
      end = Date.now() + length;
      try {
        window.sessionStorage.setItem(key, String(end));
      } catch (e) {
        /* Nothing to do; the in-memory end time still works for this page. */
      }
    }
    return end;
  }

  function pad(number) {
    return number < 10 ? '0' + number : String(number);
  }

  function tickTimer() {
    if (!activePopup || !activePopup.timer) {
      stopTimer();
      return;
    }
    var left = Math.max(0, Math.round((timerEnd(activePopup) - Date.now()) / 1000));
    var hours = Math.floor(left / 3600);
    var minutes = Math.floor((left % 3600) / 60);
    var seconds = left % 60;
    nodes.timerClock.textContent = (hours ? hours + ':' + pad(minutes) : pad(minutes)) + ':' + pad(seconds);
  }

  function startTimer() {
    stopTimer();
    var timer = activePopup && activePopup.timer;
    nodes.timer.hidden = !timer;
    if (!timer) {
      return;
    }
    nodes.timerLabel.textContent = timer.label || '';
    tickTimer();
    timerInterval = window.setInterval(tickTimer, 1000);
  }

  function stopTimer() {
    window.clearInterval(timerInterval);
    timerInterval = null;
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
    startTimer();
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
    stopTimer();
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
      body: 'action=sella_upsell_add&nonce=' + encodeURIComponent(data.nonce) +
        '&product_id=' + encodeURIComponent(item.id) +
        '&popup_id=' + encodeURIComponent(activePopup.id) +
        '&quantity=1'
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

      // Out of the slider for good — the shopper already has it.
      removeFromAllPopups(item.id);

      window.setTimeout(function () {
        if (reloading) {
          return;
        }
        if (!activePopup.items.length) {
          closePopup();
          return;
        }
        // Removing the item shifted the next one into this slot.
        currentIndex = Math.min(currentIndex, activePopup.items.length - 1);
        nodes.card.classList.remove('is-swapping');
        void nodes.card.offsetWidth;
        nodes.card.classList.add('is-swapping');
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

  /**
   * Cart rules are decided on the server, so after the cart changes the popup
   * list is stale. Ask for a fresh one before deciding what to show.
   */
  function refreshPopups(done) {
    if (!data.refreshUrl) {
      if (done) {
        done();
      }
      return;
    }

    var context = data.context || {};

    window.fetch(data.refreshUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: 'nonce=' + encodeURIComponent(data.nonce) +
        '&scope=' + encodeURIComponent(context.scope || 'all') +
        '&object_id=' + encodeURIComponent(context.object_id || 0)
    }).then(function (response) {
      return response.json();
    }).then(function (response) {
      if (response && response.success && response.data && response.data.popups) {
        popups = response.data.popups;
        pruneAdded();
        resyncOpen();
      }
      if (done) {
        done();
      }
    }).catch(function () {
      if (done) {
        done();
      }
    });
  }

  /** A refresh hands back new popup objects; point an open popup at its new self. */
  function resyncOpen() {
    if (!activePopup || !root || root.hidden) {
      return;
    }

    for (var i = 0; i < popups.length; i++) {
      if (popups[i].id === activePopup.id) {
        activePopup = popups[i];
        if (!activePopup.items.length) {
          closePopup();
          return;
        }
        currentIndex = Math.min(currentIndex, activePopup.items.length - 1);
        render();
        return;
      }
    }
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
    // Books added earlier in this visit never come back into the slider.
    pruneAdded();

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

        // The cart just changed, so re-run the cart rules before showing anything.
        refreshPopups(function () {
          if (hasTrigger('add_to_cart')) {
            showEligible();
          }
        });
      });

      window.jQuery(document.body).on('updated_cart_totals wc_cart_emptied', function () {
        refreshPopups();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
