(function () {
  'use strict';

  /**
   * Sorting for the shop grid (inc/sella-shop-sort.php). The grid holds every
   * book of the current category, and the archive snippet replaces its content
   * over AJAX when a category is picked, so the order is applied to whatever
   * is there, each time it changes.
   */
  var GRID_ID = 'sibolet-archive-grid';
  var BAR_SELECTOR = '.sibolet-archive-filter-bar';
  var STORE_KEY = 'sellaShopSort';
  var SORTED_CLASS = 'sella-sorted';
  /* The "מיון לפי" select is hidden for now: the books are always newest
     first. true brings it back, with the shopper's choice remembered. */
  var SHOW_CONTROL = false;

  var ORDERS = [
    { value: 'date-desc', label: 'החדשים ביותר' },
    { value: 'date-asc', label: 'הישנים ביותר' },
    { value: 'title-asc', label: 'א–ת' },
    { value: 'title-desc', label: 'ת–א' }
  ];
  /* On the search page the books come in by relevance (sella-shop-search.js). */
  var RELEVANCE = { value: 'relevance', label: 'הכי רלוונטיים' };

  var config = window.sellaShopSort || {};
  var rank = {};
  (config.newest || []).forEach(function (id, index) {
    rank[id] = index;
  });

  var collator = window.Intl && window.Intl.Collator
    ? new window.Intl.Collator('he', { numeric: true, sensitivity: 'base' })
    : null;

  function compareText(a, b) {
    return collator ? collator.compare(a, b) : a.localeCompare(b, 'he');
  }

  function isSearch() {
    var params = new URLSearchParams(window.location.search);
    return params.get('post_type') === 'product' && !!(params.get('s') || '').trim();
  }

  function stored() {
    try {
      return window.sessionStorage.getItem(STORE_KEY);
    } catch (err) {
      return null;
    }
  }

  function store(value) {
    try {
      window.sessionStorage.setItem(STORE_KEY, value);
    } catch (err) {
      /* Private mode: the choice lasts for this page only. */
    }
  }

  function entry(card, index) {
    var button = card.querySelector('[data-product_id]');
    var title = card.querySelector('.book-title');
    var id = button ? parseInt(button.getAttribute('data-product_id'), 10) : 0;
    return {
      card: card,
      index: index,
      rank: rank.hasOwnProperty(id) ? rank[id] : Infinity,
      title: title ? title.textContent.trim() : ''
    };
  }

  /* Books missing from the date list (or without a title) go last, in their own order. */
  var comparators = {
    'date-desc': function (a, b) {
      return a.rank === b.rank ? 0 : a.rank === Infinity ? 1 : b.rank === Infinity ? -1 : a.rank - b.rank;
    },
    'date-asc': function (a, b) {
      return a.rank === b.rank ? 0 : a.rank === Infinity ? 1 : b.rank === Infinity ? -1 : b.rank - a.rank;
    },
    'title-asc': function (a, b) {
      return !a.title || !b.title ? !a.title - !b.title : compareText(a.title, b.title);
    },
    'title-desc': function (a, b) {
      return !a.title || !b.title ? !a.title - !b.title : compareText(b.title, a.title);
    }
  };

  function sortGrid(grid, order) {
    var compare = comparators[order];
    if (!compare) {
      return;
    }

    var entries = Array.prototype.filter.call(grid.children, function (child) {
      return child.classList.contains('book-card-item');
    }).map(entry);
    if (entries.length < 2) {
      return;
    }

    entries.sort(function (a, b) {
      return compare(a, b) || a.index - b.index;
    });

    var fragment = document.createDocumentFragment();
    entries.forEach(function (item) {
      fragment.appendChild(item.card);
    });
    grid.appendChild(fragment);
  }

  function control(options, current, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'sella-shop-sort';

    var label = document.createElement('label');
    label.setAttribute('for', 'sella-shop-sort-select');
    label.textContent = 'מיון לפי';

    var select = document.createElement('select');
    select.id = 'sella-shop-sort-select';
    options.forEach(function (option) {
      var item = document.createElement('option');
      item.value = option.value;
      item.textContent = option.label;
      select.appendChild(item);
    });
    select.value = current;
    select.addEventListener('change', function () {
      onChange(select.value);
    });

    wrap.appendChild(label);
    wrap.appendChild(select);
    return wrap;
  }

  function boot() {
    var grid = document.getElementById(GRID_ID);
    if (!grid) {
      return;
    }

    var search = isSearch();
    var options = search ? [RELEVANCE].concat(ORDERS) : ORDERS;
    var saved = SHOW_CONTROL ? stored() : null;
    var order = search ? RELEVANCE.value : (comparators[saved] ? saved : 'date-desc');
    var observer = window.MutationObserver ? new window.MutationObserver(apply) : null;

    /* Our own moves would trigger the observer again, so it rests while they happen. */
    function apply() {
      if (observer) {
        observer.disconnect();
      }
      sortGrid(grid, order);
      grid.classList.add(SORTED_CLASS);
      if (observer) {
        observer.observe(grid, { childList: true });
      }
    }

    var bar = SHOW_CONTROL ? document.querySelector(BAR_SELECTOR) : null;
    if (bar) {
      bar.appendChild(control(options, order, function (value) {
        order = value;
        if (comparators[value]) {
          store(value);
        }
        apply();
      }));
    }

    apply();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
