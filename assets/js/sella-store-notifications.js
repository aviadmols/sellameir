(function () {
  'use strict';

  var selector = '.woocommerce-notices-wrapper .woocommerce-message, .woocommerce-notices-wrapper .woocommerce-info, .woocommerce-notices-wrapper .woocommerce-error';

  function dismiss(notice) {
    if (!notice || notice.classList.contains('is-dismissing')) {
      return;
    }
    notice.classList.add('is-dismissing');
    window.setTimeout(function () {
      if (notice.parentNode) {
        notice.parentNode.removeChild(notice);
      }
    }, 350);
  }

  function enhance(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var notices = scope.querySelectorAll(selector);

    for (var i = 0; i < notices.length; i++) {
      var notice = notices[i];
      if (notice.classList.contains('sella-notification-ready')) {
        continue;
      }

      notice.classList.add('sella-notification-ready');
      notice.setAttribute('tabindex', '-1');

      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'sella-notification-close';
      close.setAttribute('aria-label', 'סגירת הודעה');
      close.textContent = '\u00d7';
      close.addEventListener('click', function (event) {
        dismiss(event.currentTarget.parentNode);
      });
      notice.appendChild(close);

      var timeout = notice.classList.contains('woocommerce-error') ? 8000 : 5500;
      window.setTimeout(function (currentNotice) {
        return function () {
          dismiss(currentNotice);
        };
      }(notice), timeout);
    }
  }

  function boot() {
    enhance(document);
    if (window.MutationObserver) {
      new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          for (var j = 0; j < mutations[i].addedNodes.length; j++) {
            var node = mutations[i].addedNodes[j];
            if (node.nodeType === 1) {
              enhance(node);
            }
          }
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
