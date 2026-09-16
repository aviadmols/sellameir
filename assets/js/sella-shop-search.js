(function () {
  'use strict';

  /**
   * The shop archive filter bar ships its own AJAX search, which only filters
   * the grid. Hand that field over to the Smart Cart search: the shopper keeps
   * typing in the field they can see, and the results panel is docked inside the
   * filter bar instead of taking over the screen.
   */
  var FIELD_ID = 'sibolet-book-search';
  var OVERLAY_ID = 'sc-search';
  var OVERLAY_INPUT_ID = 'sc-search-input';
  var HOST_SELECTOR = '.sibolet-archive-filter-bar';
  var marker = null;

  function overlay() {
    return document.getElementById(OVERLAY_ID);
  }

  function isOpen() {
    var root = overlay();
    return !!root && !root.hidden;
  }

  function isDocked() {
    var root = overlay();
    return !!root && root.getAttribute('data-sella-docked') === '1';
  }

  /**
   * Move the whole search root into the filter bar. The plugin looks its parts
   * up from that root, so everything keeps working once it travels.
   */
  function dock() {
    var root = overlay();
    var host = document.querySelector(HOST_SELECTOR);
    if (!root || !host || isDocked()) {
      return;
    }

    marker = document.createComment('sc-search');
    root.parentNode.insertBefore(marker, root);
    host.appendChild(root);
    host.classList.add('sella-search-host');
    root.classList.add('sella-search-docked');
    root.setAttribute('data-sella-docked', '1');
    document.body.classList.add('sella-search-docked-open');
  }

  function undock() {
    var root = overlay();
    if (!root || !isDocked()) {
      return;
    }

    if (marker && marker.parentNode) {
      marker.parentNode.insertBefore(root, marker);
      marker.parentNode.removeChild(marker);
    }
    marker = null;
    root.classList.remove('sella-search-docked');
    root.removeAttribute('data-sella-docked');
    document.body.classList.remove('sella-search-docked-open');

    var host = document.querySelector('.sella-search-host');
    if (host) {
      host.classList.remove('sella-search-host');
    }
  }

  function closePanel() {
    var root = overlay();
    var close = root && root.querySelector('[data-sc-search-close]');
    if (close) {
      close.click();
    }
  }

  /** Feed the field's text to the panel, which owns the searching. */
  function pushTerm(field) {
    var input = document.getElementById(OVERLAY_INPUT_ID);
    if (!input) {
      return;
    }
    input.value = field.value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function openPanel(field) {
    dock();
    if (!isOpen()) {
      field.click();
    }
  }

  function watchClose(root) {
    if (!window.MutationObserver) {
      return;
    }
    // The plugin hides the root only after its closing transition has run.
    new window.MutationObserver(function () {
      if (root.hidden) {
        undock();
      }
    }).observe(root, { attributes: true, attributeFilter: ['hidden'] });
  }

  function enhance() {
    var field = document.getElementById(FIELD_ID);
    var root = overlay();

    // Without the search on the page, leave the original archive filter alone.
    if (!field || !root || !document.querySelector(HOST_SELECTOR) || field.hasAttribute('data-sc-search-open')) {
      return;
    }

    // Smart Cart opens the panel from a click anywhere inside [data-sc-search-open].
    field.setAttribute('data-sc-search-open', '');
    field.classList.add('sella-shop-search-trigger');

    // Dock before the plugin's own click handler runs, so the panel never
    // flashes full screen: pointerdown and focus both precede click.
    field.addEventListener('pointerdown', dock);
    field.addEventListener('focus', function () {
      openPanel(field);
    });

    // The archive's own filter listens for input on document. Keep it from
    // firing, so the grid is not re-queried behind the results panel.
    field.addEventListener('input', function (event) {
      event.stopPropagation();
      openPanel(field);
      pushTerm(field);
    });

    field.addEventListener('click', function () {
      window.setTimeout(function () {
        if (field.value.trim()) {
          pushTerm(field);
        }
      }, 120);
    });

    field.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) {
        closePanel();
      }
    });

    // A click anywhere outside the bar closes the panel, the way a dropdown does.
    document.addEventListener('click', function (event) {
      if (!isDocked() || !isOpen()) {
        return;
      }
      if (event.target.closest('.sella-search-host')) {
        return;
      }
      closePanel();
    });

    watchClose(root);
  }

  /* The bar sits outside the grid the archive AJAX replaces, so one pass is enough. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance);
  } else {
    enhance();
  }
})();
