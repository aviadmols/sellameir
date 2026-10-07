/**
 * An email address before paying.
 *
 * A guest who clicks through to the checkout, from the cart drawer's button or
 * anywhere else, stays where they are and the popup opens. Once they give an
 * address, or choose to go on without one, the browser goes on to the
 * checkout, where the address is already in the email field.
 *
 * Settings arrive in sellaEmailGate from sella-email-gate.php.
 */
(function () {
  'use strict';

  var settings = window.sellaEmailGate;
  var modal = document.querySelector('[data-eg-modal]');

  if (!settings || !modal) {
    return;
  }

  var root = modal.querySelector('[data-sella-eg]');
  var form = root.querySelector('[data-eg-form]');
  var emailInput = form.querySelector('input[name="email"]');
  var message = root.querySelector('[data-eg-message]');
  var busy = false;
  var returnFocus = null;
  var checkoutPath = new URL(settings.redirect, window.location.href).pathname.replace(/\/+$/, '');

  function say(text, isError) {
    message.textContent = text || '';
    message.classList.toggle('is-error', !!isError);
  }

  function setBusy(value) {
    busy = value;
    form.querySelectorAll('button').forEach(function (button) {
      button.disabled = value;
    });
    root.classList.toggle('is-busy', value);
  }

  /*
   * On to the checkout. "המשך לתשלום" needs the address, which goes on the
   * order the server opens for the cart; "המשך כאורח" goes on without one,
   * though an address already typed in is sent along.
   */
  function proceed(needEmail) {
    var email = emailInput.value.trim();
    var valid = !!email && emailInput.checkValidity();

    if (needEmail && !valid) {
      say(settings.strings.email, true);
      emailInput.focus();
      return;
    }

    var body = new FormData();
    body.append('nonce', settings.nonce);
    body.append('email', valid ? email : '');

    setBusy(true);
    say(settings.strings.continuing);

    fetch(settings.endpoint.replace('%%endpoint%%', 'sella_eg_continue'), {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    })
      .then(function (response) {
        return response.json().catch(function () {
          return { success: false };
        });
      })
      .then(function (result) {
        if (result && result.success) {
          window.location.href = settings.redirect;
          return;
        }

        setBusy(false);
        say((result && result.data && result.data.message) || settings.strings.error, true);
      })
      .catch(function () {
        setBusy(false);
        say(settings.strings.error, true);
      });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    if (!busy) {
      proceed(true);
    }
  });

  form.querySelector('[data-eg-guest]').addEventListener('click', function () {
    if (!busy) {
      proceed(false);
    }
  });

  function isCheckoutLink(link) {
    if (!link || !link.href || '_blank' === link.target) {
      return false;
    }

    try {
      var url = new URL(link.href, window.location.href);

      return url.origin === window.location.origin && url.pathname.replace(/\/+$/, '') === checkoutPath;
    } catch (error) {
      return false;
    }
  }

  function openModal() {
    returnFocus = document.activeElement;
    modal.hidden = false;
    document.documentElement.classList.add('sella-eg-open');

    // One frame later, so the opening transition has a start to run from.
    window.requestAnimationFrame(function () {
      modal.classList.add('is-open');
      emailInput.focus();
    });
  }

  function closeModal() {
    if (modal.hidden || busy) {
      return;
    }

    modal.classList.remove('is-open');
    document.documentElement.classList.remove('sella-eg-open');

    window.setTimeout(function () {
      if (!modal.classList.contains('is-open')) {
        modal.hidden = true;
      }
    }, 300);

    if (returnFocus && returnFocus.focus) {
      returnFocus.focus();
    }
  }

  /*
   * The page may be a stored copy, or older than the cart, and its nonce no
   * longer good: WooCommerce ties a guest's nonce to the cart session. Ask
   * for a fresh one, and learn whether the popup is still needed at all.
   */
  function refresh() {
    return fetch(settings.endpoint.replace('%%endpoint%%', 'sella_eg_state'), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (result) {
        if (result && result.success && result.data) {
          settings.nonce = result.data.nonce || settings.nonce;
          return false !== result.data.needed;
        }

        return true;
      })
      .catch(function () {
        return true;
      });
  }

  // Capture, so the popup opens before the cart drawer or anything else acts on the click.
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    var link = event.target.closest('a[href]');

    if (!isCheckoutLink(link)) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    settings.redirect = link.href;
    openModal();

    refresh().then(function (needed) {
      if (!needed) {
        window.location.href = settings.redirect;
      }
    });
  }, true);

  modal.addEventListener('click', function (event) {
    if (event.target.closest('[data-eg-close]')) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if ('Escape' === event.key && !modal.hidden) {
      closeModal();
    }
  });
})();
