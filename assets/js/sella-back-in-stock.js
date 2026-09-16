(function () {
  'use strict';

  var data = window.sellaBackInStock || {};
  var labels = data.labels || {};
  var EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function widgets(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector || '.sella-bis'));
  }

  function isVisible(element) {
    return !!(element && (element.offsetWidth || element.offsetHeight || element.getClientRects().length));
  }

  function setMessage(widget, text, type) {
    var message = widget.querySelector('.sella-bis__message');
    if (!message) {
      return;
    }
    message.textContent = text || '';
    message.classList.toggle('is-error', type === 'error');
    message.classList.toggle('is-success', type === 'success');
  }

  function resetState(widget) {
    var form = widget.querySelector('.sella-bis__form');
    widget.classList.remove('is-subscribed');
    if (form) {
      form.hidden = false;
    }
    setMessage(widget, '', '');
  }

  function prefill(widget) {
    var input = widget.querySelector('.sella-bis__input');
    if (input && !input.value && data.userEmail) {
      input.value = data.userEmail;
    }
  }

  function submit(widget, form) {
    var input = form.querySelector('.sella-bis__input');
    var button = form.querySelector('.sella-bis__button');
    var email = input ? input.value.trim() : '';

    if (!button || button.disabled) {
      return;
    }
    if (!EMAIL_PATTERN.test(email)) {
      setMessage(widget, labels.invalidEmail, 'error');
      if (input) {
        input.focus();
      }
      return;
    }
    if (!window.fetch || !window.FormData) {
      setMessage(widget, labels.error, 'error');
      return;
    }

    // FormData carries the email and the honeypot field.
    var body = new FormData(form);
    body.append('action', 'sella_bis_subscribe');
    body.append('nonce', data.nonce || '');
    body.append('product_id', widget.getAttribute('data-product-id') || '');

    button.disabled = true;
    button.textContent = labels.sending;
    setMessage(widget, '', '');

    window.fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) {
        return response.json();
      })
      .then(function (response) {
        var text = response && response.data && response.data.message;
        if (response && response.success) {
          widget.classList.add('is-subscribed');
          form.hidden = true;
          setMessage(widget, text || labels.success, 'success');
          return;
        }
        setMessage(widget, text || labels.error, 'error');
      })
      .catch(function () {
        setMessage(widget, labels.error, 'error');
      })
      .then(function () {
        button.disabled = false;
        button.textContent = labels.button;
      });
  }

  // Variable products: follow the selected variation.
  function variationWidgets(form) {
    var parentId = form.getAttribute('data-product_id');
    return widgets('.sella-bis[data-mode="variation"]').filter(function (widget) {
      return widget.getAttribute('data-parent-id') === parentId;
    });
  }

  function useProduct(widget, productId) {
    productId = String(productId);
    if (widget.getAttribute('data-product-id') !== productId) {
      resetState(widget);
      widget.setAttribute('data-product-id', productId);
    }
  }

  function bindVariations() {
    if (!window.jQuery) {
      return;
    }

    var $body = window.jQuery(document.body);

    $body.on('found_variation', 'form.variations_form', function (event, variation) {
      var outOfStock = !!(variation && variation.variation_id && !variation.is_in_stock);
      variationWidgets(this).forEach(function (widget) {
        if (outOfStock) {
          useProduct(widget, variation.variation_id);
          widget.hidden = false;
        } else {
          useProduct(widget, widget.getAttribute('data-parent-id'));
          widget.hidden = true;
        }
      });
    });

    $body.on('reset_data', 'form.variations_form', function () {
      variationWidgets(this).forEach(function (widget) {
        useProduct(widget, widget.getAttribute('data-parent-id'));
        widget.hidden = widget.getAttribute('data-initial-visible') !== '1';
      });
    });
  }

  // Auto attach: move the footer copy next to the visible out-of-stock message.
  function findAnchor() {
    var candidates;
    try {
      candidates = Array.prototype.filter.call(document.querySelectorAll(data.anchors || ''), function (element) {
        return isVisible(element) && !element.closest('form') && !element.closest('.sella-bis');
      });
    } catch (error) {
      return null;
    }
    if (!candidates.length) {
      return null;
    }

    // The Elementor template repeats the message per breakpoint; prefer the one after the title.
    var title = document.querySelector('.product_title');
    if (title) {
      for (var i = 0; i < candidates.length; i++) {
        if (title.compareDocumentPosition(candidates[i]) & Node.DOCUMENT_POSITION_FOLLOWING) {
          return candidates[i];
        }
      }
    }
    return candidates[0];
  }

  function placeAutoWidget(widget) {
    var current = widget.previousElementSibling;
    if (widget.getAttribute('data-attached') === '1' && current && isVisible(current)) {
      return;
    }
    // Never move the form while the visitor is typing (mobile keyboards fire resize).
    if (widget.contains(document.activeElement)) {
      return;
    }

    var anchor = findAnchor();
    if (!anchor) {
      return;
    }

    anchor.insertAdjacentElement('afterend', widget);
    widget.setAttribute('data-attached', '1');
    widget.classList.add('sella-bis--no-title');
    widget.hidden = widget.getAttribute('data-initial-visible') !== '1';
  }

  function boot() {
    widgets().forEach(prefill);

    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.classList || !form.classList.contains('sella-bis__form')) {
        return;
      }
      event.preventDefault();
      submit(form.closest('.sella-bis'), form);
    });

    bindVariations();

    var autoWidgets = widgets('.sella-bis[data-auto-attach]');
    if (!autoWidgets.length) {
      return;
    }

    var timer = null;
    function place() {
      autoWidgets.forEach(placeAutoWidget);
    }

    place();
    window.addEventListener('load', place);
    window.addEventListener('resize', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(place, 200);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
