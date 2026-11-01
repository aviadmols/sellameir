/**
 * The ActiveTrail screen (inc/sella-activetrail.php): the cards' map rows
 * are added and removed here, a fixed-text source shows its text box, the
 * card's summary follows the chosen group, the cards open and close
 * together, and the filter shows the forms of one page or product.
 */
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function toggleText(select) {
    var row = select.closest('.sella-at-map-row');
    var text = row ? row.querySelector('.sella-at-text') : null;
    if (!text) {
      return;
    }
    if (select.value === 'text') {
      text.hidden = false;
      text.focus();
    } else {
      text.hidden = true;
    }
  }

  // The rows that send something: a row without a field goes nowhere.
  function mappedRows(card) {
    var selects = card.querySelectorAll('.sella-at-map__table tbody .sella-at-field');
    var count = 0;
    for (var i = 0; i < selects.length; i++) {
      if (selects[i].value !== '') {
        count++;
      }
    }
    return count;
  }

  // The books on the first-chapter card that have a group of their own.
  function bookGroups(card) {
    var selects = card.querySelectorAll('.sella-at-book-group');
    var count = 0;
    for (var i = 0; i < selects.length; i++) {
      if (selects[i].value !== '') {
        count++;
      }
    }
    return count;
  }

  function updateSummary(card) {
    var group = card.querySelector('.sella-at-group');
    var state = card.querySelector('.sella-at-card__state');
    var fields = card.querySelector('.sella-at-card__fields');
    var rows = mappedRows(card);
    var books = bookGroups(card);
    var byBook = '';
    if (books) {
      byBook = books === 1 ? 'ספר אחד עם קבוצה משלו' : books + ' ספרים עם קבוצה משלהם';
    }

    if (fields) {
      fields.textContent = rows ? rows + ' שדות' : '';
    }
    if (!group || !state) {
      return;
    }

    var option = group.options[group.selectedIndex];
    var more = card.querySelector('.sella-at-more');
    card.classList.toggle('is-off', group.value === 'off');
    card.classList.toggle('is-active', group.value !== 'off' && (group.value !== '' || rows > 0 || books > 0));

    if (group.value === '') {
      state.textContent = byBook || state.getAttribute('data-empty') || '';
    } else if (group.value === 'off') {
      state.textContent = option.textContent;
    } else {
      state.textContent = option.textContent + (more && more.value.trim() !== '' ? ' +' : '') + (byBook ? ' · ' + byBook : '');
    }
  }

  function addRow(map) {
    var template = map.querySelector('.sella-at-map__template');
    var body = map.querySelector('.sella-at-map__table tbody');
    if (!template || !body) {
      return;
    }
    var html = template.innerHTML.replace(/__i__/g, 'n' + Date.now().toString(36) + Math.floor(Math.random() * 1000));
    var holder = document.createElement('div');
    holder.innerHTML = html;
    var row = holder.querySelector('.sella-at-map-row');
    if (row) {
      body.appendChild(row);
      var src = row.querySelector('.sella-at-src');
      if (src) {
        src.focus();
      }
    }
  }

  function applyFilter(screen) {
    var select = screen.querySelector('.sella-at-filter__place');
    var show = screen.querySelector('.sella-at-filter__show');
    var place = select ? select.value : '';
    var unused = show ? show.checked : false;

    var cards = screen.querySelectorAll('.sella-at-card');
    for (var i = 0; i < cards.length; i++) {
      var places = (cards[i].getAttribute('data-places') || '').split(' ');
      if (place !== '') {
        cards[i].hidden = places.indexOf(place) === -1;
      } else {
        cards[i].hidden = cards[i].hasAttribute('data-unused') && !unused;
      }
    }

    var sections = screen.querySelectorAll('.sella-at-cards');
    for (var s = 0; s < sections.length; s++) {
      var visible = sections[s].querySelectorAll('.sella-at-card:not([hidden])').length;
      var count = sections[s].querySelector('.sella-at-section span');
      sections[s].hidden = visible === 0;
      if (count) {
        count.textContent = '(' + visible + ')';
      }
    }
  }

  // PHP drops what is empty anyway. Not posting it keeps a screen with many
  // rows under the server's max_input_vars, past which a save would lose cards.
  function trimForm(form) {
    var cards = form.querySelectorAll('.sella-at-card');
    for (var i = 0; i < cards.length; i++) {
      var group = cards[i].querySelector('.sella-at-group');
      var more = cards[i].querySelector('.sella-at-more');
      var empty = mappedRows(cards[i]) === 0 && bookGroups(cards[i]) === 0 && (!group || group.value === '') && (!more || more.value.trim() === '');
      var controls = cards[i].querySelectorAll(empty ? 'input, select' : '.sella-at-map-row input, .sella-at-map-row select');
      for (var c = 0; c < controls.length; c++) {
        var row = controls[c].closest('.sella-at-map-row');
        var field = row ? row.querySelector('.sella-at-field') : null;
        if (empty || (field && field.value === '')) {
          controls[c].disabled = true;
        }
      }
    }
  }

  onReady(function () {
    var screen = document.querySelector('.sella-at-admin');
    if (!screen) {
      return;
    }

    applyFilter(screen);

    screen.addEventListener('click', function (event) {
      var add = event.target.closest('.sella-at-map-add');
      if (add) {
        event.preventDefault();
        addRow(add.closest('.sella-at-map'));
        updateSummary(add.closest('.sella-at-card'));
        return;
      }

      var remove = event.target.closest('.sella-at-map-remove');
      if (remove) {
        event.preventDefault();
        var card = remove.closest('.sella-at-card');
        var row = remove.closest('.sella-at-map-row');
        if (row) {
          row.parentNode.removeChild(row);
        }
        updateSummary(card);
        return;
      }

      var all = event.target.closest('[data-sella-at]');
      if (all) {
        event.preventDefault();
        var open = all.getAttribute('data-sella-at') === 'open';
        var cards = screen.querySelectorAll('.sella-at-card');
        for (var i = 0; i < cards.length; i++) {
          cards[i].open = open;
        }
      }
    });

    screen.addEventListener('change', function (event) {
      if (event.target.classList.contains('sella-at-src')) {
        toggleText(event.target);
      }
      if (event.target.classList.contains('sella-at-group') || event.target.classList.contains('sella-at-more') || event.target.classList.contains('sella-at-field') || event.target.classList.contains('sella-at-book-group')) {
        updateSummary(event.target.closest('.sella-at-card'));
      }
      if (event.target.closest('.sella-at-filter')) {
        applyFilter(screen);
      }
    });

    // The browser asks before leaving a changed, unsaved screen.
    var dirty = false;
    function markDirty(event) {
      if (event.target.closest('.sella-at-settings') && !event.target.closest('.sella-at-filter')) {
        dirty = true;
      }
    }
    screen.addEventListener('input', markDirty);
    screen.addEventListener('change', markDirty);
    var form = screen.querySelector('.sella-at-settings');
    if (form) {
      form.addEventListener('submit', function () {
        dirty = false;
        trimForm(form);
      });
    }
    window.addEventListener('beforeunload', function (event) {
      if (dirty) {
        event.preventDefault();
        event.returnValue = '';
      }
    });
  });
})();
