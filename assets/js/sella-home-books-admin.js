(function ($) {
  'use strict';

  $(function () {
    var form = $('[data-hb-form]');
    var data = window.sellaHomeBooks;
    if (!form.length || !data) {
      return;
    }

    var cache = {};
    var dirty = false;

    /* The server refuses a save without this, so a broken script cannot post empty lists. */
    form.append('<input type="hidden" name="sella_home_books_js" value="1" />');

    function setDirty() {
      dirty = true;
      form.find('[data-hb-dirty]').prop('hidden', false);
    }

    $(window).on('beforeunload', function () {
      return dirty ? true : undefined;
    });
    form.on('submit', function () {
      dirty = false;
    });

    function books(count) {
      return count === 1 ? 'ספר אחד' : count + ' ספרים';
    }

    function meta(item) {
      var line = $('<span class="sella-hb-meta"></span>');
      if (item.author) {
        line.append($('<span class="sella-hb-meta__author"></span>').text(item.author));
      }
      if (item.price) {
        line.append($('<span class="sella-hb-meta__price"></span>').html(item.price));
      }
      return line;
    }

    function tags(item) {
      var wrap = $('<span class="sella-hb-tags"></span>');
      $.each(item.tags || [], function (i, tag) {
        wrap.append($('<span class="sella-hb-tag"></span>').addClass('is-' + tag.tone).text(tag.label));
      });
      return wrap;
    }

    function listItem(item, key) {
      var li = $('<li class="sella-hb-item"></li>').attr('data-id', item.id);
      li.append('<span class="sella-hb-item__handle" aria-hidden="true">⣿</span>');
      li.append('<span class="sella-hb-item__pos" aria-hidden="true"></span>');
      li.append($('<img class="sella-hb-item__thumb" alt="" loading="lazy" />').attr('src', item.thumb));

      var body = $('<span class="sella-hb-item__body"></span>');
      body.append($('<a class="sella-hb-item__title" target="_blank" rel="noopener"></a>').attr('href', item.edit).text(item.title));
      body.append(meta(item), tags(item));
      li.append(body);

      li.append(
        '<span class="sella-hb-item__actions">' +
          '<button type="button" class="sella-hb-btn" data-hb-move="top" title="לראש הרשימה" aria-label="לראש הרשימה"><span class="dashicons dashicons-upload"></span></button>' +
          '<button type="button" class="sella-hb-btn" data-hb-move="up" title="למעלה" aria-label="למעלה"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
          '<button type="button" class="sella-hb-btn" data-hb-move="down" title="למטה" aria-label="למטה"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
          '<button type="button" class="sella-hb-btn sella-hb-btn--remove" data-hb-remove title="הסרה" aria-label="הסרה"><span class="dashicons dashicons-no-alt"></span></button>' +
        '</span>'
      );
      li.append($('<input type="hidden" />').attr('name', 'sella_home_books[' + key + '][]').val(item.id));
      return li;
    }

    function flash(li) {
      li.removeClass('is-flash');
      void li[0].offsetWidth;
      li.addClass('is-flash');
      li[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    form.on('click', '[data-hb-copy]', function () {
      var button = $(this);
      var text = button.data('hb-copy');
      var done = function () {
        button.text('הועתק ✓');
        setTimeout(function () {
          button.text('העתקה');
        }, 1500);
      };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done);
      } else {
        var field = $('<textarea></textarea>').val(text).appendTo('body').trigger('select');
        document.execCommand('copy');
        field.remove();
        done();
      }
    });

    $('[data-hb-panel]').each(function () {
      var panel = $(this);
      var key = panel.data('hb-panel');
      var list = panel.find('[data-hb-list]');
      var searchBox = panel.find('.sella-hb-search');
      var input = panel.find('[data-hb-search]');
      var cat = panel.find('.sella-hb-search__cat');
      var popup = panel.find('[data-hb-popup]');
      var results = panel.find('[data-hb-results]');
      var addAll = panel.find('[data-hb-add-all]');
      var spinner = panel.find('[data-hb-spinner]');
      var found = [];
      var active = -1;
      var timer = null;
      var request = null;

      function refresh() {
        var count = list.children().length;
        panel.find('[data-hb-count]').text(count ? '(' + books(count) + ')' : '');
        panel.find('[data-hb-empty]').prop('hidden', count > 0);
        panel.find('[data-hb-clear]').prop('hidden', count === 0);
      }

      function inList(id) {
        return list.children('[data-id="' + id + '"]');
      }

      function query() {
        return { term: $.trim(input.val()), cat: parseInt(cat.val(), 10) || 0 };
      }

      function close() {
        popup.prop('hidden', true);
        results.empty();
        input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        found = [];
        active = -1;
      }

      function options() {
        return results.children('[role="option"]');
      }

      function highlight(index) {
        var all = options();
        if (!all.length) {
          return;
        }
        active = (index + all.length) % all.length;
        all.removeClass('is-active');
        var current = all.eq(active).addClass('is-active');
        input.attr('aria-activedescendant', current.attr('id'));
        current[0].scrollIntoView({ block: 'nearest' });
      }

      /* Mark which results are in the list, and what "add all" would add. */
      function syncState() {
        var missing = 0;
        options().each(function () {
          var option = $(this);
          var added = inList(found[option.data('index')].id).length > 0;
          option.toggleClass('is-added', added).attr('aria-selected', added ? 'true' : 'false');
          option.find('.sella-hb-result__state').text(added ? '✓ ברשימה' : '+ הוספה');
          option.attr('title', added ? 'לחיצה תסיר מהרשימה' : '');
          if (!added) {
            missing++;
          }
        });

        var q = query();
        addAll.prop('hidden', !q.cat || found.length < 2).prop('disabled', missing === 0);
        if (missing === 0) {
          addAll.text('הכל כבר ברשימה');
        } else {
          addAll.text((q.term ? 'הוספת כל התוצאות' : 'הוספת כל הקטגוריה') + ' (' + missing + ')');
        }
      }

      function render(items) {
        results.empty();
        found = items;
        active = -1;

        panel.find('[data-hb-found]').text(items.length ? books(items.length) : '');
        if (!items.length) {
          results.append('<li class="sella-hb-result is-none">לא נמצאו ספרים</li>');
        }
        $.each(items, function (i, item) {
          var option = $('<li class="sella-hb-result" role="option"></li>')
            .attr('id', 'sella-hb-option-' + key + '-' + i)
            .attr('data-index', i);
          option.append($('<img class="sella-hb-result__thumb" alt="" loading="lazy" />').attr('src', item.thumb));
          var body = $('<span class="sella-hb-result__body"></span>');
          body.append($('<strong class="sella-hb-result__title"></strong>').text(item.title), meta(item), tags(item));
          option.append(body, '<span class="sella-hb-result__state"></span>');
          results.append(option);
        });

        syncState();
        popup.prop('hidden', false);
        input.attr('aria-expanded', 'true');
        if (items.length) {
          highlight(0);
        }
      }

      function add(item) {
        var li = listItem(item, key);
        list.append(li);
        return li;
      }

      /* Add a result, or take it out if it is already in the list. The results stay open. */
      function toggle(index) {
        var item = found[index];
        if (!item) {
          return;
        }
        var existing = inList(item.id);
        if (existing.length) {
          existing.remove();
        } else {
          flash(add(item));
        }
        refresh();
        setDirty();
        syncState();
      }

      function addAllFound() {
        var last = null;
        $.each(found, function (i, item) {
          if (!inList(item.id).length) {
            last = add(item);
          }
        });
        if (last) {
          refresh();
          setDirty();
          syncState();
          flash(last);
        }
        input.trigger('focus');
      }

      function search() {
        var q = query();
        var cacheKey = q.cat + '|' + q.term;
        clearTimeout(timer);
        if (!q.term && !q.cat) {
          close();
          return;
        }
        if (cache[cacheKey]) {
          render(cache[cacheKey]);
          return;
        }
        if (request) {
          request.abort();
        }
        spinner.addClass('is-active');
        request = $.get(data.ajaxUrl, { action: 'sella_home_books_search', nonce: data.nonce, term: q.term, cat: q.cat })
          .done(function (response) {
            if (response && response.success) {
              cache[cacheKey] = response.data;
              var now = query();
              if (now.cat + '|' + now.term === cacheKey) {
                render(response.data);
              }
            }
          })
          .always(function () {
            spinner.removeClass('is-active');
            request = null;
          });
      }

      input.on('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 200);
      });

      cat.on('change', function () {
        search();
        input.trigger('focus');
      });

      input.on('keydown', function (event) {
        var open = !popup.prop('hidden');
        if (event.key === 'Enter') {
          event.preventDefault();
          if (open && found[active]) {
            var adding = !inList(found[active].id).length;
            toggle(active);
            /* Move on to the next book not yet in the list, for quick runs of Enter. */
            if (adding) {
              for (var step = 1; step < found.length; step++) {
                var next = (active + step) % found.length;
                if (!inList(found[next].id).length) {
                  highlight(next);
                  break;
                }
              }
            }
          } else if (!open) {
            search();
          }
        } else if (event.key === 'ArrowDown') {
          event.preventDefault();
          if (open) {
            highlight(active + 1);
          } else {
            search();
          }
        } else if (event.key === 'ArrowUp' && open) {
          event.preventDefault();
          highlight(active - 1);
        } else if (event.key === 'Escape' && open) {
          event.preventDefault();
          close();
        }
      });

      input.on('focus', function () {
        if (popup.prop('hidden')) {
          var q = query();
          if (cache[q.cat + '|' + q.term]) {
            render(cache[q.cat + '|' + q.term]);
          }
        }
      });

      /* mousedown, not click: it runs before the input loses focus. */
      results.on('mousedown', '[role="option"]', function (event) {
        event.preventDefault();
        var index = $(this).data('index');
        highlight(index);
        toggle(index);
      });
      results.on('mousemove', '[role="option"]', function () {
        var index = $(this).data('index');
        if (index !== active) {
          highlight(index);
        }
      });

      addAll.on('mousedown', function (event) {
        event.preventDefault();
      }).on('click', addAllFound);

      panel.find('[data-hb-close]').on('mousedown', function (event) {
        event.preventDefault();
      }).on('click', close);

      $(document).on('mousedown', function (event) {
        if (!popup.prop('hidden') && !$.contains(searchBox[0], event.target)) {
          close();
        }
      });

      list.on('click', '[data-hb-move]', function () {
        var li = $(this).closest('.sella-hb-item');
        var move = $(this).data('hb-move');
        if (move === 'top') {
          list.prepend(li);
        } else if (move === 'up') {
          li.prev().before(li);
        } else {
          li.next().after(li);
        }
        setDirty();
        flash(li);
      });

      list.on('click', '[data-hb-remove]', function () {
        $(this).closest('.sella-hb-item').remove();
        refresh();
        setDirty();
        syncState();
      });

      panel.find('[data-hb-clear]').on('click', function () {
        if (window.confirm('להסיר את כל הספרים מהרשימה? אחרי שמירה השורטקוד לא יציג כלום.')) {
          list.empty();
          refresh();
          setDirty();
          syncState();
        }
      });

      $.each(data.lists[key] || [], function (i, item) {
        add(item);
      });
      refresh();

      if ($.fn.sortable) {
        list.sortable({
          items: '> .sella-hb-item',
          cancel: 'button, a, input',
          axis: 'y',
          tolerance: 'pointer',
          placeholder: 'sella-hb-item sella-hb-item--placeholder',
          forcePlaceholderSize: true,
          update: setDirty
        });
      }
    });
  });
})(jQuery);
