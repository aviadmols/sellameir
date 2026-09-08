(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function buildBadgesHtml(badges) {
    var html = '<div class="sella-product-badges">';
    for (var i = 0; i < badges.length; i++) {
      var badge = badges[i];
      html +=
        '<span class="sella-product-badge" style="background-color:' +
        escapeHtml(badge.color) +
        ';color:' +
        escapeHtml(badge.text_color) +
        ';">' +
        escapeHtml(badge.text) +
        '</span>';
    }
    html += '</div>';
    return html;
  }

  function getProductId(card) {
    var btn = card.querySelector('[data-product_id]');
    if (btn && btn.getAttribute('data-product_id')) {
      return String(btn.getAttribute('data-product_id'));
    }

    if (card.getAttribute('data-product_id')) {
      return String(card.getAttribute('data-product_id'));
    }

    return '';
  }

  function injectIntoCard(card, map) {
    if (!card || card.querySelector('.sella-product-badges')) {
      return;
    }

    var id = getProductId(card);
    if (!id || !map[id] || !map[id].length) {
      return;
    }

    // Mount on the card itself — never inside .book-3d / perspective,
    // otherwise the badge sits behind the cover and only shows on hover.
    var target = card;
    var style = window.getComputedStyle(target);
    if (style.position === 'static') {
      target.style.position = 'relative';
    }

    target.insertAdjacentHTML('afterbegin', buildBadgesHtml(map[id]));
  }

  function applySingleProduct(map) {
    if (!document.body.classList.contains('single-product')) {
      return;
    }

    var match = document.body.className.match(/postid-(\d+)/);
    if (!match) {
      return;
    }

    var id = match[1];
    if (!map[id] || !map[id].length) {
      return;
    }

    if (document.querySelector('.sella-product-badges')) {
      return;
    }

    var host =
      document.querySelector('.book-card-item') ||
      document.querySelector('.book-3d-container') ||
      document.querySelector('.single-book-3d') ||
      document.querySelector('.book-3d');

    if (!host) {
      return;
    }

    // Prefer a non-3D wrapper if available.
    var mount = host;
    if (host.classList.contains('book-3d') || host.classList.contains('single-book-3d')) {
      mount = host.parentElement || host;
    }

    var style = window.getComputedStyle(mount);
    if (style.position === 'static') {
      mount.style.position = 'relative';
    }

    mount.insertAdjacentHTML('afterbegin', buildBadgesHtml(map[id]));
  }

  function applyBadges(root) {
    if (!window.sellaProductBadges || !window.sellaProductBadges.map) {
      return;
    }

    var map = window.sellaProductBadges.map;
    var scope = root && root.querySelectorAll ? root : document;
    var cards = scope.querySelectorAll('.book-card-item');

    for (var i = 0; i < cards.length; i++) {
      injectIntoCard(cards[i], map);
    }

    if (scope === document || (root && root === document.body)) {
      applySingleProduct(map);
    }
  }

  function boot() {
    applyBadges(document);

    if (typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var nodes = mutations[i].addedNodes;
          for (var j = 0; j < nodes.length; j++) {
            var node = nodes[j];
            if (node.nodeType !== 1) {
              continue;
            }
            if (node.matches && node.matches('.book-card-item')) {
              injectIntoCard(node, window.sellaProductBadges.map);
            } else if (node.querySelectorAll) {
              applyBadges(node);
            }
          }
        }
      });

      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  document.addEventListener('elementor/frontend/init', function () {
    applyBadges(document);
  });
})();
