/**
 * Sella — mobile header bridges for Smart Cart.
 *
 * 1) Mirror the Smart Cart item count onto the mobile header cart icon.
 * 2) Make the mobile search icon open Smart Cart search (not #search).
 * 3) Make the mobile cart icon open Smart Cart (not the old custom side cart).
 * 4) Prevent the "empty cart" flash when closing Smart Cart (loading overlay).
 */
(function () {
  'use strict';

  function getSourceCount() {
    var el = document.querySelector('.sc-header-icons .sc-trigger-count, .sc-trigger-count');
    if (!el) {
      return null;
    }
    var n = parseInt(el.textContent.replace(/\D/g, ''), 10);
    return isNaN(n) ? null : n;
  }

  function getCartItems() {
    // Cart icon is the 2nd item in each sibolet icon list (see cart snippet).
    return document.querySelectorAll(
      '.sibolet-header-icons .elementor-icon-list-item:nth-child(2)'
    );
  }

  function render() {
    var count = getSourceCount();
    var items = getCartItems();

    for (var i = 0; i < items.length; i++) {
      var badge = items[i].querySelector('.sella-mobile-cart-count');

      if (!count) {
        if (badge) {
          badge.parentNode.removeChild(badge);
        }
        continue;
      }

      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'sella-mobile-cart-count';
        items[i].appendChild(badge);
      }
      badge.textContent = String(count);
    }
  }

  function findSmartCartTrigger(selector) {
    var candidates = document.querySelectorAll(selector);
    for (var i = 0; i < candidates.length; i++) {
      if (!candidates[i].closest('.elementor-sticky__spacer')) {
        return candidates[i];
      }
    }
    return candidates[0] || null;
  }

  function closeOldFloatingSearch() {
    var boxes = document.querySelectorAll('.floating-search-box.active');
    for (var i = 0; i < boxes.length; i++) {
      boxes[i].classList.remove('active');
    }
  }

  function closeCustomSideCart() {
    var cart = document.getElementById('custom-side-cart');
    var overlay = document.getElementById('custom-side-cart-overlay');
    if (cart) {
      cart.classList.remove('open');
    }
    if (overlay) {
      overlay.classList.remove('open');
    }
    document.body.classList.remove('side-cart-active');
  }

  function stopEvent(e) {
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') {
      e.stopImmediatePropagation();
    }
  }

  function openSmartCartSearch(e) {
    var link = e.target && e.target.closest ? e.target.closest('a[href="#search"]') : null;
    if (!link) {
      return;
    }

    if (!link.closest('.sibolet-header-icons') && !link.closest('.elementor-element-503a3c9')) {
      return;
    }

    stopEvent(e);
    closeOldFloatingSearch();

    var trigger = findSmartCartTrigger('.sc-search-trigger');
    if (trigger) {
      trigger.click();
    }
  }

  function openSmartCartDrawer(e) {
    // Intercept the 2nd sibolet icon (cart) — otherwise the old custom side cart opens.
    var item =
      e.target && e.target.closest
        ? e.target.closest('.sibolet-header-icons .elementor-icon-list-item:nth-child(2)')
        : null;
    if (!item) {
      return;
    }

    stopEvent(e);
    closeCustomSideCart();

    var trigger = findSmartCartTrigger('.sc-open-cart');
    if (trigger) {
      trigger.click();
    }
  }

  /**
   * Smart Cart briefly shows a white loading overlay when closing, which looks
   * like an empty cart. Mark as closing and suppress the loader / empty state.
   */
  function preventEmptyFlashOnClose(e) {
    var closeBtn =
      e.target && e.target.closest
        ? e.target.closest('.sc-close, .sc-overlay')
        : null;
    if (!closeBtn) {
      return;
    }

    var root = document.querySelector('.sc-root');
    var drawer = document.querySelector('.sc-drawer');
    if (!root || !drawer) {
      return;
    }

    root.classList.add('sella-sc-closing');
    drawer.classList.remove('is-loading');

    var loader = drawer.querySelector('.sc-drawer__loader');
    if (loader) {
      loader.setAttribute('hidden', '');
    }

    window.setTimeout(function () {
      root.classList.remove('sella-sc-closing');
    }, 500);
  }

  function bootCartCount() {
    render();

    var source = document.querySelector('.sc-header-icons .sc-trigger-count, .sc-trigger-count');
    if (source && window.MutationObserver) {
      new MutationObserver(render).observe(source, {
        childList: true,
        characterData: true,
        subtree: true,
      });
    }

    if (window.jQuery) {
      window.jQuery(document.body).on(
        'added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded',
        function () {
          window.setTimeout(render, 100);
        }
      );
    }

    var tries = 0;
    var timer = window.setInterval(function () {
      tries++;
      render();
      if (getSourceCount() !== null || tries >= 20) {
        window.clearInterval(timer);
      }
    }, 300);
  }

  function bootBridges() {
    // Capture phase — run before old snippets / Smart Cart handlers where needed.
    document.addEventListener('click', openSmartCartSearch, true);
    document.addEventListener('click', openSmartCartDrawer, true);
    document.addEventListener('click', preventEmptyFlashOnClose, true);
  }

  function boot() {
    bootCartCount();
    bootBridges();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
