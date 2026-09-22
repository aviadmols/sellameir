(function () {
  'use strict';

  /**
   * Checkout offers: a centered popup with one book per slide, each at the
   * special price set in the admin (inc/sella-checkout-offers.php). The server
   * already filtered the offers by the cart; it checks again on every add.
   */
  var data = window.sellaCheckoutOffers || {};
  var offers = (data.offers || []).slice();
  var labels = data.labels || {};
  var SEEN_KEY = 'sella-checkout-offers-seen';

  var root = null;
  var nodes = {};
  var index = 0;
  var busy = false;
  var returnFocus = null;
  var hideTimer = null;
  var touchX = null;
  var rtl = true;

  function wasSeen() {
    if (data.frequency !== 'session') {
      return false;
    }
    try {
      return window.sessionStorage.getItem(SEEN_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function markSeen() {
    try {
      window.sessionStorage.setItem(SEEN_KEY, '1');
    } catch (e) {
      /* Private browsing: the popup may show again on the next visit. */
    }
  }

  function build() {
    rtl = window.getComputedStyle(document.body).direction === 'rtl';

    root = document.createElement('div');
    root.className = 'sella-offers';
    root.hidden = true;
    root.innerHTML =
      '<div class="sella-offers__backdrop" data-offers-close></div>' +
      '<div class="sella-offers__dialog" role="dialog" aria-modal="true" tabindex="-1">' +
        '<button type="button" class="sella-offers__close" data-offers-close>&times;</button>' +
        '<div class="sella-offers__slide">' +
          '<p class="sella-offers__headline"></p>' +
          '<div class="sella-offers__body">' +
            '<img class="sella-offers__cover" src="" alt="" />' +
            '<div class="sella-offers__info">' +
              '<h3 class="sella-offers__name"></h3>' +
              '<p class="sella-offers__text"></p>' +
              '<p class="sella-offers__price">' +
                '<span class="sella-offers__price-now"></span>' +
                '<del class="sella-offers__price-was"></del>' +
              '</p>' +
            '</div>' +
          '</div>' +
          '<button type="button" class="sella-offers__add" data-offers-add></button>' +
          '<button type="button" class="sella-offers__dismiss" data-offers-close></button>' +
        '</div>' +
        '<div class="sella-offers__nav">' +
          '<button type="button" class="sella-offers__arrow" data-offers-prev></button>' +
          '<div class="sella-offers__dots"></div>' +
          '<button type="button" class="sella-offers__arrow" data-offers-next></button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);

    nodes = {
      dialog: root.querySelector('.sella-offers__dialog'),
      close: root.querySelector('.sella-offers__close'),
      slide: root.querySelector('.sella-offers__slide'),
      headline: root.querySelector('.sella-offers__headline'),
      cover: root.querySelector('.sella-offers__cover'),
      name: root.querySelector('.sella-offers__name'),
      text: root.querySelector('.sella-offers__text'),
      now: root.querySelector('.sella-offers__price-now'),
      was: root.querySelector('.sella-offers__price-was'),
      add: root.querySelector('.sella-offers__add'),
      dismiss: root.querySelector('.sella-offers__dismiss'),
      nav: root.querySelector('.sella-offers__nav'),
      prev: root.querySelector('[data-offers-prev]'),
      next: root.querySelector('[data-offers-next]'),
      dots: root.querySelector('.sella-offers__dots')
    };

    nodes.dialog.setAttribute('aria-label', labels.dialog || '');
    nodes.close.setAttribute('aria-label', labels.close || '');
    nodes.dismiss.textContent = labels.dismiss || '';
    nodes.prev.setAttribute('aria-label', labels.previous || '');
    nodes.next.setAttribute('aria-label', labels.next || '');
    // Arrows point the way the slides move: in RTL "previous" is on the right.
    nodes.prev.textContent = rtl ? '→' : '←';
    nodes.next.textContent = rtl ? '←' : '→';

    root.addEventListener('click', function (event) {
      var target = event.target;
      if (target.closest('[data-offers-close]')) {
        close();
      } else if (target.closest('[data-offers-add]')) {
        add();
      } else if (target.closest('[data-offers-prev]')) {
        go(-1);
      } else if (target.closest('[data-offers-next]')) {
        go(1);
      } else if (target.closest('[data-offers-dot]')) {
        goTo(parseInt(target.closest('[data-offers-dot]').getAttribute('data-offers-dot'), 10));
      }
    });

    root.addEventListener('keydown', onKeydown);

    nodes.dialog.addEventListener('touchstart', function (event) {
      touchX = event.touches[0].clientX;
    }, { passive: true });

    nodes.dialog.addEventListener('touchend', function (event) {
      if (touchX === null) {
        return;
      }
      var dx = event.changedTouches[0].clientX - touchX;
      touchX = null;
      if (Math.abs(dx) < 40) {
        return;
      }
      // The next slide waits on the reading side: swipe right for it in RTL.
      go((dx > 0) === rtl ? 1 : -1);
    });
  }

  function render(animate) {
    var offer = offers[index];
    if (!offer) {
      close();
      return;
    }

    nodes.headline.textContent = offer.headline || '';
    nodes.headline.hidden = !offer.headline;
    nodes.cover.src = offer.image;
    nodes.cover.alt = offer.name;
    nodes.name.textContent = offer.name;
    nodes.text.textContent = offer.text || '';
    nodes.text.hidden = !offer.text;
    nodes.now.textContent = offer.price || '';
    nodes.was.textContent = offer.regular || '';
    nodes.was.hidden = !offer.regular;

    resetButton();
    renderNav();

    if (animate) {
      nodes.slide.classList.remove('is-swapping');
      void nodes.slide.offsetWidth;
      nodes.slide.classList.add('is-swapping');
    }
  }

  function renderNav() {
    var total = offers.length;
    var focusDot = nodes.dots.contains(document.activeElement);
    nodes.nav.hidden = total < 2;
    nodes.dots.innerHTML = '';

    for (var i = 0; i < total; i++) {
      var dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'sella-offers__dot' + (i === index ? ' is-active' : '');
      dot.setAttribute('data-offers-dot', String(i));
      dot.setAttribute('aria-label', (labels.slide || '%1$s / %2$s').replace('%1$s', i + 1).replace('%2$s', total));
      if (i === index) {
        dot.setAttribute('aria-current', 'true');
      }
      nodes.dots.appendChild(dot);
    }

    // The dots were rebuilt: keep a keyboard user on the one now active.
    if (focusDot && nodes.dots.children[index]) {
      nodes.dots.children[index].focus();
    }
  }

  function resetButton() {
    var offer = offers[index];
    nodes.add.disabled = false;
    nodes.add.className = 'sella-offers__add';
    nodes.add.textContent = offer ? offer.button : '';
  }

  function goTo(target) {
    if (busy || isNaN(target) || target === index || !offers[target]) {
      return;
    }
    index = target;
    render(true);
  }

  function go(step) {
    if (offers.length < 2) {
      return;
    }
    goTo((index + step + offers.length) % offers.length);
  }

  function isTyping() {
    var el = document.activeElement;
    return !!el && /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName);
  }

  function open() {
    if (!offers.length) {
      return;
    }
    if (!root) {
      build();
    }

    window.clearTimeout(hideTimer);
    index = 0;
    render(false);
    markSeen();

    returnFocus = document.activeElement;
    root.hidden = false;
    document.documentElement.classList.add('sella-offers-lock');

    /* Let the browser paint the hidden state before transitioning in. */
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        root.classList.add('is-open');
        nodes.dialog.focus();
      });
    });
  }

  function close() {
    if (!root || root.hidden) {
      return;
    }

    root.classList.remove('is-open');
    document.documentElement.classList.remove('sella-offers-lock');
    hideTimer = window.setTimeout(function () {
      root.hidden = true;
    }, 300);

    if (returnFocus && returnFocus !== document.body && typeof returnFocus.focus === 'function') {
      returnFocus.focus();
    }
  }

  function onKeydown(event) {
    if (event.key === 'Escape') {
      close();
      return;
    }
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      go(event.key === (rtl ? 'ArrowLeft' : 'ArrowRight') ? 1 : -1);
      return;
    }
    if (event.key === 'Tab') {
      keepFocusInside(event);
    }
  }

  function keepFocusInside(event) {
    var buttons = Array.prototype.filter.call(nodes.dialog.querySelectorAll('button'), function (el) {
      return !el.disabled && el.offsetParent !== null;
    });
    if (!buttons.length) {
      return;
    }

    var first = buttons[0];
    var last = buttons[buttons.length - 1];

    if (event.shiftKey && (document.activeElement === first || document.activeElement === nodes.dialog)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function add() {
    var offer = offers[index];
    if (!offer || busy) {
      return;
    }

    busy = true;
    nodes.add.disabled = true;
    nodes.add.textContent = labels.adding || '';

    window.fetch(data.addUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: 'nonce=' + encodeURIComponent(data.nonce) + '&offer_id=' + encodeURIComponent(offer.id)
    }).then(function (response) {
      return response.json();
    }).then(function (response) {
      if (!response || !response.success) {
        // The server said no (the cart changed, the book ran out): drop the slide.
        failed(response && response.data && response.data.message, offer);
        return;
      }

      nodes.add.className = 'sella-offers__add is-added';
      nodes.add.textContent = labels.added || '';
      refreshCheckout();

      window.setTimeout(function () {
        removeOffer(offer);
      }, 1100);
    }).catch(function () {
      failed();
    });
  }

  function failed(message, offer) {
    nodes.add.className = 'sella-offers__add is-error';
    nodes.add.textContent = message || labels.error || '';

    window.setTimeout(function () {
      if (offer) {
        removeOffer(offer);
      } else {
        busy = false;
        resetButton();
      }
    }, 2400);
  }

  function removeOffer(offer) {
    busy = false;

    var at = offers.indexOf(offer);
    if (at !== -1) {
      offers.splice(at, 1);
    }
    if (!offers.length) {
      close();
      return;
    }

    index = Math.min(index, offers.length - 1);
    render(true);
  }

  /** Show the new line and totals in the order summary. */
  function refreshCheckout() {
    var $ = window.jQuery;

    if ($) {
      $(document.body).trigger('wc_fragment_refresh');
      if (document.querySelector('form.checkout')) {
        $(document.body).trigger('update_checkout');
        return;
      }
    }

    var wpData = window.wp && window.wp.data;
    var store = wpData && typeof wpData.dispatch === 'function' ? wpData.dispatch('wc/store/cart') : null;
    if (store && typeof store.invalidateResolutionForStore === 'function') {
      store.invalidateResolutionForStore();
      return;
    }

    window.setTimeout(function () {
      window.location.reload();
    }, 1200);
  }

  function boot() {
    if (!offers.length || wasSeen()) {
      return;
    }

    window.setTimeout(function () {
      // Never cut into a field the shopper is filling in; the next visit will try again.
      if (isTyping()) {
        return;
      }
      open();
    }, Math.max(0, parseInt(data.delay, 10) || 0) * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
