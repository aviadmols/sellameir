(function () {
  'use strict';

  var rowSelector = 'form.checkout #customer_details .form-row';
  var controlSelector = 'input.input-text:not([type="hidden"]), select, textarea';

  function labelText(label) {
    var first = label.childNodes[0];
    return first ? first.textContent.replace(/ /g, ' ').trim() : '';
  }

  function isAutofilled(control) {
    try {
      return control.matches(':-webkit-autofill');
    } catch (error) {
      return false;
    }
  }

  function sync(row) {
    var control = row.querySelector(controlSelector);
    if (!control) {
      return;
    }
    var filled = control.tagName === 'SELECT' || control.value !== '' || isAutofilled(control);
    row.classList.toggle('is-filled', filled);
  }

  function syncAll() {
    var rows = document.querySelectorAll(rowSelector + '.sella-float-field');
    for (var i = 0; i < rows.length; i++) {
      sync(rows[i]);
    }
  }

  function enhance() {
    var rows = document.querySelectorAll(rowSelector);

    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var label = row.querySelector('label[for]');
      var control = row.querySelector(controlSelector);

      if (!label || !control || row.classList.contains('sella-float-field')) {
        continue;
      }

      row.classList.add('sella-float-field');
      if (control.tagName === 'TEXTAREA') {
        row.classList.add('sella-float-field--textarea');
      }

      // A placeholder that repeats the label would show twice once the label floats.
      if (control.getAttribute('placeholder') && control.getAttribute('placeholder').trim() === labelText(label)) {
        control.removeAttribute('placeholder');
      }

      sync(row);
    }
  }

  function onFieldEvent(event) {
    var row = event.target.closest ? event.target.closest(rowSelector) : null;
    if (row && row.classList.contains('sella-float-field')) {
      sync(row);
    }
    // The address autocomplete clears dependent fields without firing events.
    if (event.type !== 'input') {
      window.setTimeout(syncAll, 0);
    }
  }

  /**
   * Mobile order summary: a toggle bar that opens the review table, like Shopify's.
   * The bar lives outside #order_review, which WooCommerce refreshes on its own.
   */
  function orderSummary() {
    var panel = document.querySelector('.e-checkout__order_review');
    var review = panel && panel.querySelector('#order_review');
    if (!panel || !review) {
      return null;
    }

    var toggle = panel.querySelector('.sella-order-toggle');
    if (!toggle) {
      var heading = panel.querySelector('#order_review_heading');
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'sella-order-toggle';
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-controls', 'order_review');
      toggle.innerHTML =
        '<span class="sella-order-toggle__label">' +
          '<span class="sella-order-toggle__text">' + (heading ? heading.textContent.trim() : 'פירוט ההזמנה') + '</span>' +
          '<span class="sella-order-toggle__chevron" aria-hidden="true"></span>' +
        '</span>' +
        '<span class="sella-order-toggle__total"></span>';
      panel.insertBefore(toggle, review);

      toggle.addEventListener('click', function () {
        var open = !panel.classList.contains('is-open');
        panel.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    var total = review.querySelector('.order-total td .amount, .order-total td');
    var label = toggle.querySelector('.sella-order-toggle__total');
    if (total && label) {
      label.textContent = total.textContent.trim();
    }

    return toggle;
  }

  /**
   * Shipping row: the heading on its own line, the methods stacked under it.
   * WooCommerce prints the heading in a <th> beside a narrow <td>, and the
   * method labels overflowed that cell. The review table is re-rendered on
   * every checkout refresh, so this runs again from the updated_checkout hook.
   */
  function shippingBlock() {
    var rows = document.querySelectorAll('.woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals');

    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var th = row.querySelector('th');
      var td = row.querySelector('td');

      if (!th || !td || row.classList.contains('sella-shipping-row')) {
        continue;
      }

      var heading = document.createElement('span');
      heading.className = 'sella-shipping-heading';
      heading.textContent = th.textContent.trim();

      td.colSpan = 2;
      td.insertBefore(heading, td.firstChild);
      row.removeChild(th);
      row.classList.add('sella-shipping-row');
    }
  }

  /**
   * Field errors: keep only the message under the field. WooCommerce prints each
   * one twice, as an inline message and again in a list at the top of the form,
   * then scrolls to that list. Drop the list items that already show inline,
   * drop the list when nothing is left, and scroll to the first invalid field.
   * Errors that belong to no field (payment, stock) stay in the list.
   *
   * checkout_error fires before WooCommerce adds the inline messages, so an item
   * is dropped when its field exists (WooCommerce's own test for writing one),
   * and the scroll waits a tick for the messages to be in place.
   */
  function fieldErrorsInline() {
    var groups = document.querySelectorAll('form.checkout .woocommerce-NoticeGroup-checkout');
    var first = null;

    for (var i = 0; i < groups.length; i++) {
      var items = groups[i].querySelectorAll('li[data-id]');

      for (var j = 0; j < items.length; j++) {
        var field = document.getElementById(items[j].getAttribute('data-id'));
        if (field) {
          first = first || field;
          items[j].parentNode.removeChild(items[j]);
        }
      }

      if (!groups[i].querySelector('li')) {
        groups[i].parentNode.removeChild(groups[i]);
      }
    }

    // Something is still listed at the top: WooCommerce's scroll to it stands.
    if (!first || document.querySelector('form.checkout .woocommerce-NoticeGroup-checkout')) {
      return;
    }

    window.jQuery('html, body').stop(true);
    window.setTimeout(function () {
      var row = (first.closest && first.closest('.form-row')) || first;
      var top = row.getBoundingClientRect().top + window.pageYOffset - 120;
      window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });

      try {
        first.focus({ preventScroll: true });
      } catch (error) {
        first.focus();
      }
    }, 0);
  }

  function boot() {
    enhance();
    orderSummary();
    shippingBlock();

    document.addEventListener('input', onFieldEvent);
    document.addEventListener('change', onFieldEvent);
    document.addEventListener('focusout', onFieldEvent);
    document.addEventListener('animationstart', function (event) {
      if (event.animationName === 'sella-autofill-start') {
        onFieldEvent(event);
      }
    });

    // Browsers restore typed values on back/forward navigation after DOMContentLoaded.
    window.addEventListener('pageshow', syncAll);

    if (window.jQuery) {
      window.jQuery(document.body).on('updated_checkout country_to_state_changed', function () {
        enhance();
        syncAll();
        orderSummary();
        shippingBlock();
      });
      window.jQuery(document.body).on('checkout_error', fieldErrorsInline);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
