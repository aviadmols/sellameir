(function () {
  'use strict';

  /**
   * The shop archive filter bar ships its own AJAX search, which only filters
   * the grid. Hand that field over to the Smart Cart search overlay so the site
   * has a single search everywhere. The category buttons keep working as they are.
   */
  var FIELD_ID = 'sibolet-book-search';
  var OVERLAY_ID = 'sc-search';
  var OVERLAY_INPUT_ID = 'sc-search-input';

  function overlay() {
    return document.getElementById(OVERLAY_ID);
  }

  function isOpen() {
    var panel = overlay();
    return !!panel && !panel.hidden;
  }

  /**
   * Carry anything already typed in the archive field into the overlay — the
   * browser restores field values on back/forward navigation.
   */
  function handOverTerm(field) {
    var term = (field.value || '').trim();
    if (!term) {
      return;
    }
    var input = document.getElementById(OVERLAY_INPUT_ID);
    if (!input) {
      return;
    }
    input.value = term;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function enhance() {
    var field = document.getElementById(FIELD_ID);

    // Without the overlay on the page, leave the original search alone.
    if (!field || !overlay() || field.hasAttribute('data-sc-search-open')) {
      return;
    }

    // Smart Cart opens the overlay from a click anywhere inside [data-sc-search-open].
    field.setAttribute('data-sc-search-open', '');
    field.setAttribute('readonly', 'readonly');
    field.setAttribute('role', 'button');
    field.classList.add('sella-shop-search-trigger');

    field.addEventListener('click', function () {
      window.setTimeout(function () {
        handOverTerm(field);
      }, 120);
    });

    // Keyboard users reach the field by tab, which fires no click.
    field.addEventListener('focus', function () {
      if (!isOpen()) {
        field.click();
      }
    });

    field.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (!isOpen()) {
          field.click();
        }
      }
    });
  }

  /* The bar sits outside the grid the archive AJAX replaces, so one pass is enough. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance);
  } else {
    enhance();
  }
})();
