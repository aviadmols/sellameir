/**
 * Sella — show Smart Cart search + cart icons in the mobile header.
 *
 * The desktop header section (.elementor-element-54b8c33) holds the
 * .sc-header-icons block but is hidden on mobile (elementor-hidden-mobile).
 * The mobile header section (.elementor-element-503a3c9) has no icons.
 *
 * We MOVE (not clone) the original .sc-header-icons element into the mobile
 * header when the viewport is narrow, and move it back on desktop. Moving the
 * original element keeps the live cart count and Smart Cart click handlers
 * working, since those are delegated at the document level.
 */
(function () {
  'use strict';

  var MOBILE_QUERY = '(max-width: 767px)';
  var mql = window.matchMedia(MOBILE_QUERY);

  var placeholder = null; // marks the original position of the icons block
  var iconsEl = null;

  function findOriginalIcons() {
    // Prefer the block inside the original desktop section — never a sticky
    // spacer clone (.elementor-sticky__spacer).
    var candidates = document.querySelectorAll(
      '.elementor-element-54b8c33 .sc-header-icons, .sc-header-icons'
    );
    for (var i = 0; i < candidates.length; i++) {
      if (!candidates[i].closest('.elementor-sticky__spacer')) {
        return candidates[i];
      }
    }
    return null;
  }

  function findMobileTarget() {
    // Icons column in the mobile header (next to the hamburger).
    var column = document.querySelector(
      '.elementor-element-503a3c9 .elementor-element-bd991ad'
    );
    if (column) {
      var widgetWrap = column.querySelector(
        '.elementor-widget-wrap, .e-con-inner'
      );
      return widgetWrap || column;
    }
    // Fallback: mobile header section itself.
    return document.querySelector('.elementor-element-503a3c9');
  }

  function moveToMobile() {
    if (!iconsEl) {
      iconsEl = findOriginalIcons();
    }
    if (!iconsEl || iconsEl.closest('.sella-mobile-icons-slot')) {
      return;
    }

    var target = findMobileTarget();
    if (!target) {
      return;
    }

    if (!placeholder) {
      placeholder = document.createElement('span');
      placeholder.className = 'sella-header-icons-placeholder';
      placeholder.style.display = 'none';
    }
    if (iconsEl.parentNode) {
      iconsEl.parentNode.insertBefore(placeholder, iconsEl);
    }

    var slot = target.querySelector(':scope > .sella-mobile-icons-slot');
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'sella-mobile-icons-slot';
      target.insertBefore(slot, target.firstChild);
    }

    slot.appendChild(iconsEl);
    document.body.classList.add('sella-mobile-icons-active');
  }

  function moveBack() {
    if (!iconsEl || !placeholder || !placeholder.parentNode) {
      return;
    }
    if (!iconsEl.closest('.sella-mobile-icons-slot')) {
      return;
    }

    placeholder.parentNode.insertBefore(iconsEl, placeholder);
    placeholder.parentNode.removeChild(placeholder);
    document.body.classList.remove('sella-mobile-icons-active');
  }

  function apply() {
    if (mql.matches) {
      moveToMobile();
    } else {
      moveBack();
    }
  }

  function onChange() {
    apply();
  }

  if (typeof mql.addEventListener === 'function') {
    mql.addEventListener('change', onChange);
  } else if (typeof mql.addListener === 'function') {
    mql.addListener(onChange);
  }
  window.addEventListener('orientationchange', function () {
    window.setTimeout(apply, 100);
  });

  function boot() {
    apply();
    // Smart Cart may render its icons after DOM ready — retry briefly.
    if (mql.matches && !document.body.classList.contains('sella-mobile-icons-active')) {
      var tries = 0;
      var timer = window.setInterval(function () {
        tries++;
        apply();
        if (
          document.body.classList.contains('sella-mobile-icons-active') ||
          tries >= 20
        ) {
          window.clearInterval(timer);
        }
      }, 250);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
