/**
 * The ActiveTrail screen (inc/sella-activetrail.php): the cards' map rows
 * are added and removed here, a fixed-text source shows its text box, the
 * card's summary follows the chosen group, and the cards open and close
 * together.
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

  function updateSummary(card) {
    var group = card.querySelector('.sella-at-group');
    var state = card.querySelector('.sella-at-card__state');
    var fields = card.querySelector('.sella-at-card__fields');
    var rows = card.querySelectorAll('.sella-at-map__table tbody .sella-at-map-row').length;

    if (fields) {
      fields.textContent = rows ? rows + ' שדות' : '';
    }
    if (!group || !state) {
      return;
    }

    var option = group.options[group.selectedIndex];
    var more = card.querySelector('.sella-at-more');
    card.classList.toggle('is-off', group.value === 'off');
    card.classList.toggle('is-active', group.value !== 'off' && (group.value !== '' || rows > 0));

    if (group.value === '') {
      state.textContent = state.getAttribute('data-empty') || '';
    } else {
      state.textContent = option.textContent + (more && more.value.trim() !== '' ? ' +' : '');
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

  onReady(function () {
    var screen = document.querySelector('.sella-at-admin');
    if (!screen) {
      return;
    }

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
      if (event.target.classList.contains('sella-at-group') || event.target.classList.contains('sella-at-more')) {
        updateSummary(event.target.closest('.sella-at-card'));
      }
    });

    // The browser asks before leaving a changed, unsaved screen.
    var dirty = false;
    screen.addEventListener('input', function (event) {
      if (event.target.closest('.sella-at-settings')) {
        dirty = true;
      }
    });
    screen.addEventListener('change', function (event) {
      if (event.target.closest('.sella-at-settings')) {
        dirty = true;
      }
    });
    var form = screen.querySelector('.sella-at-settings');
    if (form) {
      form.addEventListener('submit', function () {
        dirty = false;
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
