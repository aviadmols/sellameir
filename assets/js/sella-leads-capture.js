(function () {
  'use strict';

  /**
   * The newsletter popup's form (form.newsletter-form, an HTML widget in an
   * Elementor popup) only changed its button's text. Send the address to the
   * leads list (inc/sella-leads.php) instead, and say so when it is saved.
   */
  var cfg = window.sellaLeadsCapture || {};

  // In the capture phase, before the form's own onsubmit, which then never runs.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || !form.matches('form.newsletter-form')) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    var input = form.querySelector('input[type="email"]');
    var button = form.querySelector('button[type="submit"], .newsletter-submit-btn');
    if (!input || !button || form.getAttribute('data-sending') === '1') {
      return;
    }

    var label = button.getAttribute('data-label') || button.textContent;
    button.setAttribute('data-label', label);

    var body = new FormData();
    body.append('action', 'sella_leads_subscribe');
    body.append('email', input.value.trim());
    body.append('source', form.getAttribute('data-source') || (form.closest('[data-elementor-type="popup"]') ? 'פופאפ ניוזלטר' : 'טופס ניוזלטר'));
    body.append('page_url', window.location.href);

    form.setAttribute('data-sending', '1');
    button.disabled = true;
    button.textContent = cfg.sending || '';

    window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) {
        return response.json();
      })
      .then(function (response) {
        if (!response || !response.success) {
          var refused = new Error((response && response.data && response.data.message) || '');
          refused.fromServer = true;
          throw refused;
        }
        button.textContent = cfg.done || '';
        input.disabled = true;
      })
      .catch(function (error) {
        // The server's own words, never the browser's (English) network error.
        button.textContent = (error && error.fromServer && error.message) || cfg.error || '';
        window.setTimeout(function () {
          button.textContent = label;
          button.disabled = false;
          form.removeAttribute('data-sending');
        }, 2600);
      });
  }, true);
})();
