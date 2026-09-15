(function () {
  'use strict';

  var data = window.sellaUpsellData || {};
  var popups = data.popups || [];
  var activePopup = null;
  var currentIndex = 0;
  var root = null;
  var overlay = null;
  var card = null;

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

    var key = 'sella-upsell-' + popup.id;
    var seen = storage.getItem(key);
    if (!seen) {
      return true;
    }
    if (popup.frequency === 'day') {
      return seen !== new Date().toISOString().slice(0, 10);
    }
    return false;
  }

  function markShown(popup) {
    var storage = storageFor(popup.frequency);
    if (storage) {
      storage.setItem('sella-upsell-' + popup.id, popup.frequency === 'day' ? new Date().toISOString().slice(0, 10) : '1');
    }
  }

  function build() {
    root = document.createElement('aside');
    root.className = 'sella-upsell-popup';
    root.setAttribute('dir', document.documentElement.getAttribute('dir') || 'rtl');
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML =
      '<div class="sella-upsell-popup__overlay" data-upsell-close></div>' +
      '<section class="sella-upsell-popup__panel" role="dialog" aria-modal="true" aria-label="הצעה מיוחדת">' +
        '<button type="button" class="sella-upsell-popup__close" data-upsell-close aria-label="סגירה">&times;</button>' +
        '<p class="sella-upsell-popup__eyebrow">מעניין לקרוא ביחד עם</p>' +
        '<h2 class="sella-upsell-popup__title"></h2>' +
        '<div class="sella-upsell-popup__viewport">' +
          '<div class="sella-upsell-popup__track"></div>' +
        '</div>' +
        '<div class="sella-upsell-popup__controls">' +
          '<button type="button" class="sella-upsell-popup__arrow" data-upsell-prev aria-label="הקודם">&#8594;</button>' +
          '<span class="sella-upsell-popup__counter"></span>' +
          '<button type="button" class="sella-upsell-popup__arrow" data-upsell-next aria-label="הבא">&#8592;</button>' +
        '</div>' +
      '</section>';
    document.body.appendChild(root);
    overlay = root.querySelector('.sella-upsell-popup__overlay');
    card = root.querySelector('.sella-upsell-popup__panel');

    root.addEventListener('click', function (event) {
      var close = event.target.closest('[data-upsell-close]');
      if (close) {
        closePopup();
        return;
      }
      var add = event.target.closest('[data-upsell-add]');
      if (add) {
        addToCart(add);
        return;
      }
      if (event.target.closest('[data-upsell-prev]')) {
        move(-1);
      }
      if (event.target.closest('[data-upsell-next]')) {
        move(1);
      }
    });
  }

  function render() {
    if (!activePopup) {
      return;
    }

    var title = root.querySelector('.sella-upsell-popup__title');
    var track = root.querySelector('.sella-upsell-popup__track');
    var counter = root.querySelector('.sella-upsell-popup__counter');
    var item = activePopup.items[currentIndex];
    title.textContent = activePopup.title;
    track.innerHTML = '';

    if (!item) {
      closePopup();
      return;
    }

    var slide = document.createElement('article');
    slide.className = 'sella-upsell-popup__slide';
    slide.innerHTML =
      '<img class="sella-upsell-popup__image" src="' + escapeHtml(item.image) + '" alt="" />' +
      '<h3 class="sella-upsell-popup__product-name">' + escapeHtml(item.name) + '</h3>' +
      '<p class="sella-upsell-popup__price">' + escapeHtml(item.price) + '</p>' +
      '<button type="button" class="sella-upsell-popup__add" data-upsell-add data-product-id="' + item.id + '">' + escapeHtml(data.labels.add) + '</button>';
    track.appendChild(slide);
    counter.textContent = (currentIndex + 1) + ' / ' + activePopup.items.length;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function move(direction) {
    if (!activePopup || activePopup.items.length < 2) {
      return;
    }
    currentIndex = (currentIndex + direction + activePopup.items.length) % activePopup.items.length;
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
    if (!popup || !popup.items.length) {
      return;
    }
    if (!root) {
      build();
    }
    activePopup = popup;
    currentIndex = 0;
    markShown(popup);
    render();
    root.classList.add('is-open');
    root.setAttribute('aria-hidden', 'false');
    document.body.classList.add('sella-upsell-is-open');
  }

  function closePopup() {
    if (!root) {
      return;
    }
    root.classList.remove('is-open');
    root.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('sella-upsell-is-open');
  }

  function addToCart(button) {
    var productId = button.getAttribute('data-product-id');
    if (!productId || button.disabled) {
      return;
    }
    button.disabled = true;
    button.textContent = 'מוסיף...';

    window.jQuery.ajax({
      url: data.ajaxUrl,
      type: 'POST',
      data: { product_id: productId, quantity: 1 },
    }).done(function (response) {
      if (response && response.error) {
        button.disabled = false;
        button.textContent = data.labels.add;
        return;
      }

      if (response && response.fragments) {
        window.jQuery.each(response.fragments, function (key, value) {
          window.jQuery(key).replaceWith(value);
        });
      }
      window.jQuery(document.body).trigger('added_to_cart', [response.fragments || {}, response.cart_hash || '', button]);
      removeFromAllPopups(productId);
      activePopup.items.splice(currentIndex, 1);
      if (!activePopup.items.length) {
        closePopup();
        return;
      }
      currentIndex = Math.min(currentIndex, activePopup.items.length - 1);
      render();
    }).fail(function () {
      button.disabled = false;
      button.textContent = data.labels.add;
    });
  }

  function nextEligible() {
    for (var i = 0; i < popups.length; i++) {
      if (canShow(popups[i])) {
        return popups[i];
      }
    }
    return null;
  }

  function showEligible() {
    var popup = nextEligible();
    if (popup) {
      openPopup(popup);
    }
  }

  function boot() {
    if (!popups.length) {
      return;
    }

    var delayed = [];
    for (var i = 0; i < popups.length; i++) {
      var popup = popups[i];
      if (popup.trigger === 'immediate') {
        showEligible();
        break;
      }
      if (popup.trigger === 'delay') {
        delayed.push(popup);
      }
    }
    if (delayed.length) {
      window.setTimeout(showEligible, Math.max(0, parseInt(delayed[0].delay, 10) || 0) * 1000);
    }

    window.addEventListener('scroll', function () {
      if ((window.scrollY + window.innerHeight) / document.documentElement.scrollHeight > 0.62) {
        for (var i = 0; i < popups.length; i++) {
          if (popups[i].trigger === 'scroll') {
            showEligible();
            break;
          }
        }
      }
    }, { passive: true });

    document.addEventListener('mouseleave', function (event) {
      if (event.clientY <= 0) {
        for (var i = 0; i < popups.length; i++) {
          if (popups[i].trigger === 'exit_intent') {
            showEligible();
            break;
          }
        }
      }
    });

    if (window.jQuery) {
      window.jQuery(document.body).on('added_to_cart', function (event, fragments, cartHash, button) {
        if (button && button.jquery) {
          button = button[0];
        }
        var productId = button && (button.value || button.getAttribute('data-product_id') || button.getAttribute('data-product-id'));
        removeFromAllPopups(productId);
        for (var i = 0; i < popups.length; i++) {
          if (popups[i].trigger === 'add_to_cart') {
            showEligible();
            break;
          }
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
