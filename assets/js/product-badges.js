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

    var link = card.querySelector('a.book-link-wrapper[href*="/product/"], a[href*="/product/"]');
    if (!link) {
      return '';
    }

    // Fallback: data attribute if present on card.
    if (card.getAttribute('data-product_id')) {
      return String(card.getAttribute('data-product_id'));
    }

    return '';
  }

  function injectIntoTarget(target, badges) {
    if (!target || target.querySelector('.sella-product-badges')) {
      return;
    }

    var style = window.getComputedStyle(target);
    if (style.position === 'static') {
      target.style.position = 'relative';
    }

    target.insertAdjacentHTML('beforeend', buildBadgesHtml(badges));
  }

  function injectIntoCard(card, map) {
    if (!card || card.querySelector('.sella-product-badges')) {
      return;
    }

    var id = getProductId(card);
    if (!id || !map[id] || !map[id].length) {
      return;
    }

    var target =
      card.querySelector('.book-3d-container') ||
      card.querySelector('.book-3d') ||
      card.querySelector('.book-link-wrapper') ||
      card;

    injectIntoTarget(target, map[id]);
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

    var target =
      document.querySelector('.book-3d-container') ||
      document.querySelector('.single-book-3d') ||
      document.querySelector('.book-3d');

    injectIntoTarget(target, map[id]);
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
