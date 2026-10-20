(function () {
  'use strict';

  /**
   * The contact form ([sella_contact_form], inc/sella-leads.php): checks the
   * fields as the server does, sends them, and swaps the form for the thanks.
   * The server checks everything again and its messages win.
   */
  var cfg = window.sellaContact || {};

  var MESSAGES = {
    nameEmpty: 'נא למלא שם מלא',
    emailEmpty: 'נא למלא כתובת אימייל',
    emailBad: 'כתובת האימייל לא תקינה',
    phoneEmpty: 'נא למלא מספר טלפון נייד',
    phoneBad: 'מספר הטלפון לא תקין. למשל: 050-1234567',
    orderBad: 'מספר הזמנה מכיל ספרות (ואולי אותיות) בלבד',
    messageEmpty: 'נא לכתוב את תוכן הפנייה',
    fix: 'יש לתקן את השדות המסומנים.',
    sending: 'שולח...',
    failed: 'לא הצלחנו לשלוח את הפנייה. בדקו את החיבור ונסו שוב.'
  };

  /* The same numbers sella_contact_normalize_phone() accepts. */
  function phoneOk(value) {
    var phone = value.replace(/[\s\-.()/]/g, '');
    return /^(?:(?:\+|00)?972|0)5\d{8}$/.test(phone) ||
      (/^(?:\+|00)[1-9]\d{7,14}$/.test(phone) && !/^(?:\+|00)972/.test(phone));
  }

  function check(name, value) {
    value = value.trim();
    switch (name) {
      case 'name':
        return value.length < 2 ? MESSAGES.nameEmpty : '';
      case 'email':
        if (!value) return MESSAGES.emailEmpty;
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value) ? '' : MESSAGES.emailBad;
      case 'phone':
        if (!value) return MESSAGES.phoneEmpty;
        return phoneOk(value) ? '' : MESSAGES.phoneBad;
      case 'order_ref':
        return value && !/^#?[A-Za-z0-9-]{1,30}$/.test(value) ? MESSAGES.orderBad : '';
      case 'message':
        return value ? '' : MESSAGES.messageEmpty;
    }
    return '';
  }

  function init(root) {
    var form = root.querySelector('.sella-contact__form');
    var done = root.querySelector('.sella-contact__done');
    var status = root.querySelector('.sella-contact__status');
    var submit = root.querySelector('.sella-contact__submit');
    var submitText = submit.textContent;
    var started = Date.now();
    var tried = false;

    function field(name) {
      return form.querySelector('[data-field="' + name + '"]');
    }

    function showError(name, message) {
      var wrap = field(name);
      if (!wrap) return;
      var input = wrap.querySelector('input, textarea');
      var error = wrap.querySelector('.sella-contact__error');
      wrap.classList.toggle('is-invalid', !!message);
      input.setAttribute('aria-invalid', message ? 'true' : 'false');
      error.textContent = message || '';
      error.hidden = !message;
    }

    function showStatus(message) {
      status.textContent = message || '';
      status.hidden = !message;
    }

    function validateAll() {
      var first = null;
      ['name', 'email', 'phone', 'order_ref', 'message'].forEach(function (name) {
        var input = field(name).querySelector('input, textarea');
        var message = check(name, input.value);
        showError(name, message);
        if (message && !first) first = input;
      });
      return first;
    }

    // After the first try, a field's message follows what is typed in it.
    form.addEventListener('input', function (event) {
      var wrap = event.target.closest('[data-field]');
      if (tried && wrap) {
        showError(wrap.getAttribute('data-field'), check(wrap.getAttribute('data-field'), event.target.value));
        // Everything fixed: the note over the button goes too.
        if (!form.querySelector('.is-invalid')) {
          showStatus('');
        }
      }
    });

    form.addEventListener('focusout', function (event) {
      var wrap = event.target.closest('[data-field]');
      if (wrap && event.target.value.trim()) {
        showError(wrap.getAttribute('data-field'), check(wrap.getAttribute('data-field'), event.target.value));
      }
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      tried = true;
      showStatus('');

      var invalid = validateAll();
      if (invalid) {
        showStatus(MESSAGES.fix);
        invalid.focus();
        return;
      }

      var body = new FormData(form);
      body.append('action', 'sella_contact_submit');
      body.append('elapsed', String(Date.now() - started));
      body.append('page_url', window.location.href);

      submit.disabled = true;
      submit.textContent = MESSAGES.sending;
      form.setAttribute('aria-busy', 'true');

      window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (response) {
          return response.json();
        })
        .then(function (response) {
          if (response && response.success) {
            form.hidden = true;
            done.hidden = false;
            done.focus();
            return;
          }

          var data = (response && response.data) || {};
          var errors = data.errors || {};
          var first = null;
          Object.keys(errors).forEach(function (name) {
            showError(name, errors[name]);
            if (!first && field(name)) first = field(name).querySelector('input, textarea');
          });
          showStatus(data.message || MESSAGES.failed);
          if (first) first.focus();
        })
        .catch(function () {
          showStatus(MESSAGES.failed);
        })
        .then(function () {
          submit.disabled = false;
          submit.textContent = submitText;
          form.removeAttribute('aria-busy');
        });
    });
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-sella-contact]'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
