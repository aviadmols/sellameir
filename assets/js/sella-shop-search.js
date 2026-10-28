(function () {
  'use strict';

  /**
   * The shop archive filter bar ships its own AJAX search, which only filters
   * the grid. Its field opens the LETS search instead: lets-search.js hooks
   * every input[data-lets-search] and opens its window on focus, carrying
   * what was typed. The field is marked as soon as this script runs (in the
   * footer, ahead of the deferred LETS script looking for its fields).
   */
  var FIELD_ID = 'sibolet-book-search';

  function handToLets() {
    var field = document.getElementById(FIELD_ID);
    if (!field || field.hasAttribute('data-lets-search')) {
      return;
    }
    field.setAttribute('data-lets-search', '');
    field.classList.add('sella-shop-search-trigger');

    // The archive's own filter listens for input on document. Keep it from
    // firing, so the grid is not re-queried while LETS searches.
    field.addEventListener('input', function (event) {
      event.stopPropagation();
    });
  }

  handToLets();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', handToLets);
  }

  /**
   * "הצג את כל N המוצרים" at the foot of the panel opens the shop search page
   * (?s=term&post_type=product). The archive grid there ignores the term and
   * lists every book, so filter it here with the panel's own index and the
   * panel's own matching: the page shows the same N books, in the same order.
   */
  var GRID_ID = 'sibolet-archive-grid';
  var FILTERED_CLASS = 'sella-search-filtered';

  /* Smart Cart's normalizeText, which also built the haystacks in the index. */
  var NIKUD_RE = /[֑-ׇ]/g;
  var PUNCT_RE;
  try {
    PUNCT_RE = new RegExp('[^\\p{L}\\p{N}]+', 'gu');
  } catch (err) {
    PUNCT_RE = /[^0-9a-zא-ת]+/g;
  }

  function normalizeText(text) {
    return String(text === null || text === undefined ? '' : text)
      .toLowerCase()
      .replace(NIKUD_RE, '')
      .replace(PUNCT_RE, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  /* Smart Cart's scoreProduct: title matches first, then the popular books. */
  function scoreRow(row, term, tokens, popular) {
    var name = row[7] || '';
    var score;

    if (name === term) score = 1000;
    else if (name.indexOf(term) === 0) score = 700;
    else if (name.indexOf(' ' + term) !== -1) score = 550;
    else if (name.indexOf(term) !== -1) score = 400;
    else if (name.indexOf(tokens[0]) === 0) score = 300;
    else if (name.indexOf(tokens[0]) !== -1) score = 200;
    else score = 100;

    var rank = popular.indexOf(row[0]);
    if (rank !== -1) score += popular.length - rank;

    return score - Math.min(30, name.length / 6);
  }

  /** The book URLs that match, best first. */
  function matchUrls(index, rawTerm) {
    var term = normalizeText(rawTerm);
    var tokens = term.split(' ');
    var popular = index.pop || [];
    var rows = index.p || [];
    var matches = [];

    for (var i = 0; i < rows.length; i++) {
      var haystack = rows[i][6] || '';
      var ok = true;
      for (var t = 0; t < tokens.length; t++) {
        if (haystack.indexOf(tokens[t]) === -1) {
          ok = false;
          break;
        }
      }
      if (ok) {
        matches.push({ url: rows[i][2], score: scoreRow(rows[i], term, tokens, popular) });
      }
    }

    matches.sort(function (a, b) {
      return b.score - a.score;
    });

    return matches.map(function (match) {
      return pathOf(match.url);
    });
  }

  /* The grid and the index may encode a Hebrew slug differently. */
  function pathOf(url) {
    var a = document.createElement('a');
    a.href = url;
    try {
      return decodeURIComponent(a.pathname).replace(/\/+$/, '');
    } catch (err) {
      return a.pathname.replace(/\/+$/, '');
    }
  }

  function searchTerm() {
    var params = new URLSearchParams(window.location.search);
    return params.get('post_type') === 'product' ? (params.get('s') || '').trim() : '';
  }

  function showMatches(grid, paths, term) {
    var cards = {};
    Array.prototype.forEach.call(grid.querySelectorAll('.book-card-item'), function (card) {
      var link = card.querySelector('a[href]');
      if (link) {
        cards[pathOf(link.href)] = card;
      }
      card.parentNode.removeChild(card);
    });

    paths.forEach(function (path) {
      if (cards[path]) {
        grid.appendChild(cards[path]);
      }
    });

    if (!grid.querySelector('.book-card-item')) {
      var empty = document.createElement('p');
      empty.className = 'sella-search-empty';
      empty.textContent = 'לא מצאנו ספרים עבור "' + term + '".';
      grid.appendChild(empty);
    }
  }

  function filterSearchResults() {
    var grid = document.getElementById(GRID_ID);
    if (!grid) {
      return;
    }

    var term = searchTerm();
    var config = window.SmartCart || {};
    // Whatever happens below, the grid shows (style.css hides it until then).
    var reveal = function () {
      grid.classList.add(FILTERED_CLASS);
    };

    if (!term || !config.searchIndexUrl || !window.fetch) {
      reveal();
      return;
    }

    // The term stays in the field: a click there opens the panel on it again.
    var field = document.getElementById(FIELD_ID);
    if (field && !field.value) {
      field.value = term;
    }

    window.fetch(config.searchIndexUrl, { credentials: 'omit' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('index http ' + response.status);
        }
        return response.json();
      })
      .then(function (index) {
        if (index && index.p && index.p.length) {
          showMatches(grid, matchUrls(index, term), term);
        }
      })
      .catch(function () {
        /* No index: the grid stays as the archive printed it. */
      })
      .then(reveal);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', filterSearchResults);
  } else {
    filterSearchResults();
  }
})();
