/**
 * Books for subscribers only (inc/sella-members-only.php): on their cards,
 * the shop grid plugin's add-to-cart button becomes the plain "להצטרפות"
 * link to the subscription page, and the card leads there too. The button
 * loses its add-to-cart classes, so neither the cart drawer nor the
 * tracking treat a click as an add to cart.
 */
(function () {
  'use strict';

  var data = window.sellaMembersOnly;
  if (!data || !data.ids || !data.ids.length) {
    return;
  }

  var ids = {};
  for (var i = 0; i < data.ids.length; i++) {
    ids[String(data.ids[i])] = true;
  }

  function fixCard(card) {
    var button = card.querySelector('[data-product_id]');
    var id = button ? String(button.getAttribute('data-product_id')) : String(card.getAttribute('data-product_id') || '');
    if (!id || !ids[id] || card.getAttribute('data-sella-members') === '1') {
      return;
    }
    card.setAttribute('data-sella-members', '1');

    var link = card.querySelector('.book-link-wrapper');
    if (link) {
      link.setAttribute('href', data.url);
    }

    var price = card.querySelector('.book-price');
    if (price) {
      price.parentNode.removeChild(price);
    }

    if (!button || button.classList.contains('sella-members-btn')) {
      return;
    }

    var fresh = document.createElement('a');
    fresh.href = data.url;
    fresh.className = 'sella-members-btn';
    fresh.setAttribute('data-product_id', id);
    fresh.setAttribute('aria-label', data.text + ' למנויים: ' + data.label);
    if (button.getAttribute('tabindex')) {
      fresh.setAttribute('tabindex', button.getAttribute('tabindex'));
    }
    fresh.textContent = data.text;
    button.parentNode.replaceChild(fresh, button);
  }

  function apply(root) {
    var scope = root && root.querySelectorAll ? root : document;
    if (scope !== document && scope.matches && scope.matches('.book-card-item')) {
      fixCard(scope);
    }
    var cards = scope.querySelectorAll('.book-card-item');
    for (var j = 0; j < cards.length; j++) {
      fixCard(cards[j]);
    }
  }

  function boot() {
    apply(document);

    if (typeof MutationObserver === 'undefined') {
      return;
    }
    var observer = new MutationObserver(function (mutations) {
      for (var m = 0; m < mutations.length; m++) {
        var nodes = mutations[m].addedNodes;
        for (var n = 0; n < nodes.length; n++) {
          if (nodes[n].nodeType === 1) {
            apply(nodes[n]);
          }
        }
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
