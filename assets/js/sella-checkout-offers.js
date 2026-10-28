(function () {
  'use strict';

  /**
   * Checkout offers: a centered popup with the books offered at a special
   * price (inc/sella-checkout-offers.php), one at a time in a slider or all
   * together, stacked or two to a row. The server already filtered the offers
   * by the cart; it checks again on every add.
   *
   * The popup may carry a countdown. The server keeps when it first opened for
   * this shopper; at zero the offers are gone, here and on the server.
   */
  var data = window.sellaCheckoutOffers || {};
  var offers = (data.offers || []).slice();
  var labels = data.labels || {};
  var timer = data.timer || null;
  var layout = data.layout === 'stack' || data.layout === 'grid' ? data.layout : 'slider';
  var slider = layout === 'slider';
  // Stacked, each offer carries the countdown at its own top, as a slide does.
  var timerOnCards = layout === 'stack';
  var SEEN_KEY = 'sella-checkout-offers-seen';

  var root = null;
  var nodes = {};
  var index = 0;
  var returnFocus = null;
  var hideTimer = null;
  var tickInterval = null;
  var timerEnd = 0;
  var expired = false;
  var touchX = null;
  var rtl = true;

  // A timer that already started counts from when this page was made.
  if (timer && timer.started) {
    timerEnd = Date.now() + timer.left * 1000;
  }

  /**
   * This popup comes first on checkout. The upsell popups (sella-upsell.js)
   * read the gate: while this one is still to open, or open, they wait for
   * GATE_EVENT. Set now, before either script boots on DOMContentLoaded.
   */
  var GATE_EVENT = 'sella-offers-done';
  var gate = window.sellaOffersGate = { busy: !!offers.length && !wasSeen() && !isTimeUp() };

  function releaseGate() {
    if (!gate.busy) {
      return;
    }
    gate.busy = false;
    document.dispatchEvent(new window.CustomEvent(GATE_EVENT));
  }

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
    root.className = 'sella-offers sella-offers--' + layout;
    root.hidden = true;
    root.innerHTML =
      // A click on the backdrop does not close the popup: only × and the
      // "no thanks" button do (and Escape, for the keyboard).
      '<div class="sella-offers__backdrop"></div>' +
      '<div class="sella-offers__dialog" role="dialog" aria-modal="true" tabindex="-1">' +
        '<button type="button" class="sella-offers__close" data-offers-close>&times;</button>' +
        '<p class="sella-offers__timer" role="timer" hidden>' +
          '<span class="sella-offers__timer-label"></span>' +
          '<span class="sella-offers__timer-clock" dir="ltr"></span>' +
        '</p>' +
        '<p class="sella-offers__title"></p>' +
        '<div class="sella-offers__cards"></div>' +
        '<button type="button" class="sella-offers__dismiss" data-offers-close></button>' +
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
      timer: root.querySelector('.sella-offers__timer'),
      title: root.querySelector('.sella-offers__title'),
      cards: root.querySelector('.sella-offers__cards'),
      dismiss: root.querySelector('.sella-offers__dismiss'),
      nav: root.querySelector('.sella-offers__nav'),
      prev: root.querySelector('[data-offers-prev]'),
      next: root.querySelector('[data-offers-next]'),
      dots: root.querySelector('.sella-offers__dots')
    };

    nodes.dialog.setAttribute('aria-label', labels.dialog || '');
    nodes.close.setAttribute('aria-label', labels.close || '');
    // A slide carries its own headline; a list of offers gets one over them all.
    nodes.title.textContent = labels.dialog || '';
    nodes.title.hidden = slider;
    nodes.dismiss.textContent = labels.dismiss || '';
    nodes.prev.setAttribute('aria-label', labels.previous || '');
    nodes.next.setAttribute('aria-label', labels.next || '');
    // Arrows point the way the slides move: in RTL "previous" is on the right.
    nodes.prev.textContent = rtl ? '→' : '←';
    nodes.next.textContent = rtl ? '←' : '→';

    offers.forEach(function (offer) {
      offer.card = buildCard(offer);
      nodes.cards.appendChild(offer.card.el);
    });

    root.addEventListener('click', function (event) {
      var target = event.target;
      var addButton = target.closest('[data-offers-add]');
      if (target.closest('[data-offers-close]')) {
        close();
      } else if (addButton) {
        add(offerOf(addButton));
      } else if (target.closest('[data-offers-prev]')) {
        go(-1);
      } else if (target.closest('[data-offers-next]')) {
        go(1);
      } else if (target.closest('[data-offers-dot]')) {
        goTo(parseInt(target.closest('[data-offers-dot]').getAttribute('data-offers-dot'), 10));
      }
    });

    root.addEventListener('keydown', onKeydown);

    if (!slider) {
      return;
    }

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

  /** One offer's card: a slide in the slider, an item in a list. */
  function buildCard(offer) {
    var el = document.createElement('div');
    el.className = 'sella-offers__slide';
    el.setAttribute('data-offer-id', String(offer.id));
    el.innerHTML =
      (timer && timerOnCards
        ? '<p class="sella-offers__timer sella-offers__timer--card" role="timer">' +
            '<span class="sella-offers__timer-label"></span>' +
            '<span class="sella-offers__timer-clock" dir="ltr"></span>' +
          '</p>'
        : '') +
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
      '<button type="button" class="sella-offers__add" data-offers-add></button>';

    var headline = el.querySelector('.sella-offers__headline');
    var cover = el.querySelector('.sella-offers__cover');
    var text = el.querySelector('.sella-offers__text');
    var was = el.querySelector('.sella-offers__price-was');

    headline.textContent = offer.headline || '';
    headline.hidden = !offer.headline;
    cover.src = offer.image;
    cover.alt = offer.name;
    el.querySelector('.sella-offers__name').textContent = offer.name;
    text.textContent = offer.text || '';
    text.hidden = !offer.text;
    el.querySelector('.sella-offers__price-now').textContent = offer.price || '';
    was.textContent = offer.regular || '';
    was.hidden = !offer.regular;

    var card = { el: el, add: el.querySelector('.sella-offers__add') };
    card.add.textContent = offer.button;
    return card;
  }

  function offerOf(el) {
    var id = el.closest('[data-offer-id]').getAttribute('data-offer-id');
    return offers.filter(function (offer) {
      return String(offer.id) === id;
    })[0];
  }

  function render(animate) {
    if (!offers.length) {
      close();
      return;
    }

    index = Math.min(index, offers.length - 1);
    offers.forEach(function (offer, i) {
      offer.card.el.hidden = slider && i !== index;
    });
    renderNav();

    if (animate && slider) {
      var el = offers[index].card.el;
      el.classList.remove('is-swapping');
      void el.offsetWidth;
      el.classList.add('is-swapping');
    }
  }

  function renderNav() {
    var total = offers.length;
    var focusDot = nodes.dots.contains(document.activeElement);
    nodes.nav.hidden = !slider || total < 2;
    nodes.dots.innerHTML = '';

    if (nodes.nav.hidden) {
      return;
    }

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

  function pad(number) {
    return number < 10 ? '0' + number : String(number);
  }

  function formatTime(left) {
    var hours = Math.floor(left / 3600);
    var minutes = Math.floor((left % 3600) / 60);
    return (hours ? hours + ':' + pad(minutes) : pad(minutes)) + ':' + pad(left % 60);
  }

  function isTimeUp() {
    return !!timerEnd && timerEnd <= Date.now();
  }

  /** Every countdown on show: the band over the dialog, or one per card. */
  function eachTimer(callback) {
    Array.prototype.forEach.call(root.querySelectorAll('.sella-offers__timer'), function (el) {
      callback(el, el.querySelector('.sella-offers__timer-label'), el.querySelector('.sella-offers__timer-clock'));
    });
  }

  function startTimer() {
    nodes.timer.hidden = !timer || timerOnCards;
    if (!timer || expired) {
      return;
    }

    eachTimer(function (el, label) {
      label.textContent = timer.label || '';
    });
    if (!timerEnd) {
      timerEnd = Date.now() + timer.left * 1000;
      startTimerOnServer();
    }

    tick();
    tickInterval = window.setInterval(tick, 1000);
  }

  function stopTimer() {
    window.clearInterval(tickInterval);
    tickInterval = null;
  }

  function tick() {
    var left = Math.max(0, Math.round((timerEnd - Date.now()) / 1000));
    eachTimer(function (el, label, clock) {
      clock.textContent = formatTime(left);
    });
    if (!left) {
      timeUp();
    }
  }

  /** The server keeps the start, so a reload or another visit does not restart the clock. */
  function startTimerOnServer() {
    window.fetch(data.timerUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: 'nonce=' + encodeURIComponent(data.nonce)
    }).catch(function () {
      /* The clock still runs on this page; the next page load starts it again. */
    });
  }

  /** Time is up: no more adding, and the popup closes for good. */
  function timeUp() {
    stopTimer();
    expired = true;
    eachTimer(function (el, label) {
      el.classList.add('is-over');
      label.textContent = labels.expired || '';
    });

    // A book already on its way to the cart may finish; the server allows it.
    offers.forEach(function (offer) {
      if (!offer.busy) {
        offer.card.add.disabled = true;
      }
    });

    window.setTimeout(close, 2500);
  }

  function resetButton(offer) {
    var button = offer.card.add;
    button.disabled = expired;
    button.className = 'sella-offers__add';
    button.textContent = offer.button;
  }

  function goTo(target) {
    if (!slider || isNaN(target) || target === index || !offers[target] || offers[index].busy) {
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
    if (!offers.length || isTimeUp()) {
      releaseGate();
      return;
    }
    if (!root) {
      build();
    }

    window.clearTimeout(hideTimer);
    index = 0;
    render(false);
    startTimer();
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
    stopTimer();
    hideTimer = window.setTimeout(function () {
      root.hidden = true;
      releaseGate();
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
    if (slider && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
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

  function add(offer) {
    if (!offer || offer.busy || expired) {
      return;
    }

    var button = offer.card.add;
    offer.busy = true;
    button.disabled = true;
    button.textContent = labels.adding || '';

    window.fetch(data.addUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: 'nonce=' + encodeURIComponent(data.nonce) + '&offer_id=' + encodeURIComponent(offer.id)
    }).then(function (response) {
      return response.json();
    }).then(function (response) {
      if (!response || !response.success) {
        // The server said no (the cart changed, the book ran out): drop the offer.
        failed(offer, response && response.data && response.data.message, true);
        return;
      }

      button.className = 'sella-offers__add is-added';
      button.textContent = labels.added || '';
      refreshCheckout();

      window.setTimeout(function () {
        removeOffer(offer);
      }, 1100);
    }).catch(function () {
      failed(offer);
    });
  }

  function failed(offer, message, drop) {
    var button = offer.card.add;
    button.className = 'sella-offers__add is-error';
    button.textContent = message || labels.error || '';

    window.setTimeout(function () {
      if (drop) {
        removeOffer(offer);
      } else {
        offer.busy = false;
        resetButton(offer);
      }
    }, 2400);
  }

  function removeOffer(offer) {
    var at = offers.indexOf(offer);
    if (at === -1) {
      return;
    }

    var current = at === index;
    var hadFocus = offer.card.el.contains(document.activeElement);

    offers.splice(at, 1);
    offer.card.el.parentNode.removeChild(offer.card.el);
    if (at < index) {
      index--;
    }
    // The focused button went with its card: keep focus inside the dialog.
    if (hadFocus && offers.length) {
      nodes.dialog.focus();
    }

    render(current);
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
        releaseGate();
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
