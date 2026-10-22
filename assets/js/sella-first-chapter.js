(function () {
  'use strict';

  /**
   * The first-chapter form ([sella_first_chapter], inc/sella-first-chapter.php):
   * checks the name and email as the server does, sends them with the chapter
   * and product, and swaps the form for the "on its way" note. The server
   * checks everything again and its messages win.
   */
  var cfg = window.sellaFirstChapter || {};

  var MESSAGES = {
    nameEmpty: 'נא למלא שם',
    emailEmpty: 'נא למלא כתובת אימייל',
    emailBad: 'כתובת האימייל לא תקינה',
    fix: 'יש לתקן את השדות המסומנים.',
    sending: 'שולח...',
    failed: 'לא הצלחנו לשלוח את הפרק. בדקו את החיבור ונסו שוב.'
  };

  function check(name, value) {
    value = value.trim();
    if (name === 'name') {
      return value.length < 2 ? MESSAGES.nameEmpty : '';
    }
    if (name === 'email') {
      if (!value) return MESSAGES.emailEmpty;
      return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value) ? '' : MESSAGES.emailBad;
    }
    return '';
  }

  function init(root) {
    var form = root.querySelector('.sella-fc__form');
    var done = root.querySelector('.sella-fc__done');
    var status = root.querySelector('.sella-fc__status');
    var submit = root.querySelector('.sella-fc__submit');
    var submitText = submit.textContent;
    var started = Date.now();
    var tried = false;

    function field(name) {
      return form.querySelector('[data-field="' + name + '"]');
    }

    function showError(name, message) {
      var wrap = field(name);
      if (!wrap) return;
      var input = wrap.querySelector('input');
      var error = wrap.querySelector('.sella-fc__error');
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
      ['name', 'email'].forEach(function (name) {
        var input = field(name).querySelector('input');
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

      var email = field('email').querySelector('input').value.trim();
      var body = new FormData(form);
      body.append('action', 'sella_first_chapter');
      body.append('chapter', root.getAttribute('data-chapter') || '');
      body.append('product', root.getAttribute('data-product') || '');
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
            done.querySelector('.sella-fc__done-email').textContent = email;
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
            if (!first && field(name)) first = field(name).querySelector('input');
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
    Array.prototype.forEach.call(document.querySelectorAll('[data-sella-fc]'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
