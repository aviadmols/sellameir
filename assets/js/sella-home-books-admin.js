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

    $('[data-hb-panel]').each(function () {
      var panel = $(this);
      var key = panel.data('hb-panel');
      var list = panel.find('[data-hb-list]');
      var input = panel.find('[data-hb-search]');
      var results = panel.find('[data-hb-results]');
      var spinner = panel.find('[data-hb-spinner]');
      var found = [];
      var active = -1;
      var timer = null;
      var request = null;

      function refresh() {
        var count = list.children().length;
        panel.find('[data-hb-count]').text(count === 1 ? '(ספר אחד)' : count ? '(' + count + ' ספרים)' : '');
        panel.find('[data-hb-empty]').prop('hidden', count > 0);
        panel.find('[data-hb-clear]').prop('hidden', count === 0);
      }

      function inList(id) {
        return list.children('[data-id="' + id + '"]');
      }

      function close() {
        results.prop('hidden', true).empty();
        input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        found = [];
        active = -1;
      }

      function highlight(index) {
        var options = results.children('[role="option"]');
        if (!options.length) {
          return;
        }
        active = (index + options.length) % options.length;
        options.removeClass('is-active').attr('aria-selected', 'false');
        var current = options.eq(active).addClass('is-active').attr('aria-selected', 'true');
        input.attr('aria-activedescendant', current.attr('id'));
        current[0].scrollIntoView({ block: 'nearest' });
      }

      function render(items) {
        results.empty();
        found = items;
        active = -1;

        if (!items.length) {
          results.append('<li class="sella-hb-result is-none">לא נמצאו ספרים</li>');
        }
        $.each(items, function (i, item) {
          var added = inList(item.id).length > 0;
          var option = $('<li class="sella-hb-result" role="option" aria-selected="false"></li>')
            .attr('id', 'sella-hb-option-' + key + '-' + i)
            .attr('data-index', i)
            .toggleClass('is-added', added);
          option.append($('<img class="sella-hb-result__thumb" alt="" />').attr('src', item.thumb));
          var body = $('<span class="sella-hb-result__body"></span>');
          body.append($('<strong class="sella-hb-result__title"></strong>').text(item.title), meta(item), tags(item));
          option.append(body);
          option.append($('<span class="sella-hb-result__state"></span>').text(added ? '✓ ברשימה' : '+ הוספה'));
          results.append(option);
        });

        results.prop('hidden', false);
        input.attr('aria-expanded', 'true');
        if (items.length) {
          highlight(0);
        }
      }

      function add(item) {
        var existing = inList(item.id);
        if (existing.length) {
          flash(existing);
        } else {
          var li = listItem(item, key);
          list.append(li);
          refresh();
          setDirty();
          flash(li);
        }
        input.val('');
        close();
        input.trigger('focus');
      }

      function search(term) {
        if (cache[term]) {
          render(cache[term]);
          return;
        }
        if (request) {
          request.abort();
        }
        spinner.addClass('is-active');
        request = $.get(data.ajaxUrl, { action: 'sella_home_books_search', nonce: data.nonce, term: term })
          .done(function (response) {
            if (response && response.success) {
              cache[term] = response.data;
              if ($.trim(input.val()) === term) {
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
        var term = $.trim(input.val());
        clearTimeout(timer);
        if (term.length < 2 && !/^\d+$/.test(term)) {
          close();
          return;
        }
        timer = setTimeout(function () {
          search(term);
        }, 220);
      });

      input.on('keydown', function (event) {
        var open = !results.prop('hidden');
        if (event.key === 'Enter') {
          event.preventDefault();
          if (open && found[active]) {
            add(found[active]);
          }
        } else if (event.key === 'ArrowDown' && open) {
          event.preventDefault();
          highlight(active + 1);
        } else if (event.key === 'ArrowUp' && open) {
          event.preventDefault();
          highlight(active - 1);
        } else if (event.key === 'Escape' && open) {
          event.preventDefault();
          close();
        }
      });

      input.on('focus', function () {
        var term = $.trim(input.val());
        if (term && cache[term]) {
          render(cache[term]);
        }
      });

      /* mousedown, not click: it runs before the input loses focus. */
      results.on('mousedown', '[role="option"]', function (event) {
        event.preventDefault();
        add(found[$(this).data('index')]);
      });
      results.on('mousemove', '[role="option"]', function () {
        var index = $(this).data('index');
        if (index !== active) {
          highlight(index);
        }
      });

      $(document).on('mousedown', function (event) {
        if (!$(event.target).closest(panel.find('.sella-hb-search')).length) {
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
      });

      panel.find('[data-hb-clear]').on('click', function () {
        if (window.confirm('להסיר את כל הספרים מהרשימה? אחרי שמירה האזור יחזור להיות מוצג כמו היום.')) {
          list.empty();
          refresh();
          setDirty();
        }
      });

      $.each(data.lists[key] || [], function (i, item) {
        list.append(listItem(item, key));
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
