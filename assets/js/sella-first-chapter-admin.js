(function ($) {
  'use strict';

  /**
   * First-chapter admin (inc/sella-first-chapter.php):
   * - the chapter screen: pick the file from the media library;
   * - the settings screen: product rows, and one card per connected Elementor
   *   form, whose field dropdowns follow the form chosen in it.
   */
  var cfg = window.sellaFcAdmin || {};
  var counter = Date.now();

  /* ---------------------------------------------------------------------
   * Chapter screen: the file
   * ------------------------------------------------------------------- */

  function initFilePicker() {
    var $box = $('.sella-fc-file');
    if (!$box.length || !window.wp || !wp.media) {
      return;
    }

    var $id = $box.find('[data-fc-file-id]');
    var $current = $box.find('.sella-fc-file__current');
    var $name = $box.find('[data-fc-file-name]');
    var frame = null;

    $box.on('click', '[data-fc-file-pick]', function () {
      if (!frame) {
        frame = wp.media({
          title: cfg.pickTitle,
          button: { text: cfg.pickButton },
          multiple: false
        });
        frame.on('select', function () {
          var file = frame.state().get('selection').first().toJSON();
          $id.val(file.id);
          $name.text(file.filename || file.title || '').attr('href', file.url);
          $current.prop('hidden', false);
        });
      }
      frame.open();
    });

    $box.on('click', '[data-fc-file-remove]', function () {
      $id.val('');
      $name.text('').attr('href', '');
      $current.prop('hidden', true);
    });
  }

  /* ---------------------------------------------------------------------
   * Settings screen: products
   * ------------------------------------------------------------------- */

  function initProducts($form) {
    var $map = $form.find('.sella-fc-map');

    $form.on('click', '[data-add="product"]', function () {
      var html = $('#tmpl-sella-fc-product').html().replace(/__i__/g, 'n' + counter++);
      $map.append($($.parseHTML($.trim(html))));
      // WooCommerce turns new product fields into its search box on this event.
      $(document.body).trigger('wc-enhanced-select-init');
    });

    $form.on('click', '[data-remove="product"]', function () {
      $(this).closest('.sella-fc-map__row').remove();
    });
  }

  /* ---------------------------------------------------------------------
   * Settings screen: Elementor forms
   * ------------------------------------------------------------------- */

  var forms = cfg.forms || [];
  var chapters = cfg.chapters || [];

  function findForm(id) {
    for (var i = 0; i < forms.length; i++) {
      if (forms[i].id === id) return forms[i];
    }
    return null;
  }

  function formLabel(form) {
    return (form.name || 'טופס בלי שם') + ' · ' + (form.post_title || ('עמוד ' + form.post_id));
  }

  function option(value, text, selected) {
    return $('<option></option>').val(value).text(text).prop('selected', !!selected);
  }

  function chapterSelect(name, selected, emptyText) {
    var $select = $('<select></select>').attr('name', name);
    $select.append(option('', emptyText));
    chapters.forEach(function (chapter) {
      $select.append(option(String(chapter.id), chapter.title, String(chapter.id) === String(selected)));
    });
    return $select;
  }

  /* A guess for a field a new connection has not set yet. */
  function guess(fields, role) {
    var found = '';
    fields.some(function (field) {
      var id = field.id.toLowerCase();
      var hit = (role === 'email' && (field.type === 'email' || id === 'email')) ||
        (role === 'phone' && (field.type === 'tel' || id === 'phone' || id === 'tel')) ||
        (role === 'name' && field.type === 'text' && (id === 'name' || field.label.indexOf('שם') !== -1));
      if (hit) found = field.id;
      return hit;
    });
    return found;
  }

  function fieldSelect(name, fields, selected, emptyText, filter) {
    var $select = $('<select></select>').attr('name', name);
    $select.append(option('', emptyText));
    fields.forEach(function (field) {
      if (filter && !filter(field)) return;
      $select.append(option(field.id, field.label + ' (' + field.id + ')', field.id === selected));
    });
    return $select;
  }

  function labelled(text, $control, note) {
    var $label = $('<label class="sella-fc-field"></label>');
    $('<span></span>').text(text).appendTo($label);
    $label.append($control);
    if (note) $('<small class="description"></small>').text(note).appendTo($label);
    return $label;
  }

  /* The answers of the choosing field, each with its chapter. */
  function renderChoices($wrap, name, field, choices) {
    $wrap.empty();
    if (!field) return;

    if (!field.options.length) {
      $('<p class="description"></p>').text('לשדה הזה אין תשובות קבועות. בוחרים שדה של בחירה (כפתורי רדיו, רשימה או תיבות סימון).').appendTo($wrap);
      return;
    }

    var $table = $('<table class="sella-fc-choices"><thead><tr><th>התשובה בטופס</th><th>הפרק שנשלח</th></tr></thead><tbody></tbody></table>');
    field.options.forEach(function (opt) {
      var $row = $('<tr></tr>');
      var $answer = $('<td></td>').text(opt.label);
      if (opt.value !== opt.label) {
        $('<code></code>').text(opt.value).appendTo($answer);
      }
      $('<input type="hidden" />').attr('name', name + '[choice_values][]').val(opt.value).appendTo($answer);
      $row.append($answer);
      $('<td></td>').append(chapterSelect(name + '[choice_chapters][]', (choices || {})[opt.value], '— לפי ברירת המחדל למטה —')).appendTo($row);
      $table.find('tbody').append($row);
    });
    $wrap.append($table);
  }

  function renderBody($card, connection) {
    var name = $card.data('name');
    var formId = $card.find('[data-role="form"]').val();
    var form = findForm(formId);
    var $body = $card.find('.sella-fc-conn__body').empty();

    if (!formId) return;

    // A connected form that is no longer on the site: keep its settings.
    var fields = form ? form.fields : [connection.name, connection.email, connection.phone, connection.choice]
      .filter(Boolean)
      .map(function (id) { return { id: id, label: id, type: '', options: [] }; });
    var fresh = !connection.form || connection.form !== formId;
    var pick = function (role) {
      return fresh ? guess(fields, role) : (connection[role] || '');
    };

    $('<input type="hidden" />').attr('name', name + '[label]').val(form ? formLabel(form) : (connection.label || formId)).appendTo($body);

    if (!form) {
      $('<p class="sella-fc-warn"></p>').text('הטופס הזה לא נמצא באתר (אולי נמחק או הוחלף). ההגדרות שלו נשמרות, אבל כדאי לחבר את הטופס החדש.').appendTo($body);
    }

    var $grid = $('<div class="sella-fc-conn__grid"></div>').appendTo($body);
    var $email = fieldSelect(name + '[email]', fields, pick('email'), 'בחרו שדה');
    $email.prop('required', true);
    $grid.append(labelled('אימייל (חובה)', $email));
    $grid.append(labelled('שם', fieldSelect(name + '[name]', fields, pick('name'), 'אין')));
    $grid.append(labelled('טלפון', fieldSelect(name + '[phone]', fields, pick('phone'), 'אין')));

    var choice = fresh ? '' : (connection.choice || '');
    var $choice = fieldSelect(name + '[choice]', fields, choice, 'לא לפי שדה', function (field) {
      return field.options.length || field.id === choice;
    });
    $body.append(labelled('הפרק נקבע לפי התשובה לשדה', $choice, 'למשל "תחום עניין": לכל תשובה בוחרים פרק.'));

    var $choices = $('<div class="sella-fc-conn__choices"></div>').appendTo($body);
    var refreshChoices = function () {
      var id = $choice.val();
      var field = null;
      fields.forEach(function (f) { if (f.id === id) field = f; });
      renderChoices($choices, name, field, fresh ? {} : connection.choices);
    };
    $choice.on('change', refreshChoices);
    refreshChoices();

    var fallback = fresh ? 'product' : (connection.fallback || '');
    var $fallback = chapterSelect(name + '[fallback]', fallback, 'לא לשלוח');
    $fallback.find('option').first().after(option('product', 'הפרק של המוצר שבדף (לפי השיוך למעלה)', fallback === 'product'));
    $body.append(labelled('ברירת המחדל', $fallback, 'כשהטופס לא בוחר פרק לפי שדה, או שהתשובה לא שויכה לפרק.'));
  }

  function addCard($list, connection) {
    var name = 'forms[n' + counter++ + ']';
    var $card = $('<div class="sella-fc-conn"></div>').data('name', name);
    var $head = $('<div class="sella-fc-conn__head"></div>').appendTo($card);
    var $form = $('<select data-role="form"></select>').attr('name', name + '[form]');

    $form.append(option('', 'בחרו טופס'));
    forms.forEach(function (form) {
      $form.append(option(form.id, formLabel(form), form.id === connection.form));
    });
    if (connection.form && !findForm(connection.form)) {
      $form.append(option(connection.form, (connection.label || connection.form) + ' (לא נמצא)', true));
    }

    $head.append(labelled('טופס', $form));
    $('<button type="button" class="button-link sella-fc-remove" data-remove="form"></button>').text('ניתוק').appendTo($head);
    $('<div class="sella-fc-conn__body"></div>').appendTo($card);

    $form.on('change', function () {
      renderBody($card, connection);
    });

    $list.append($card);
    renderBody($card, connection);
    return $card;
  }

  function initConnections($form) {
    var $list = $form.find('[data-conns]');
    if (!$list.length) return;

    (cfg.connections || []).forEach(function (connection) {
      addCard($list, connection);
    });

    if (!forms.length) {
      $('<p class="description"></p>').text('לא נמצאו באתר טפסים של Elementor Pro.').insertAfter($list);
    }

    $form.on('click', '[data-add="form"]', function () {
      addCard($list, {}).find('[data-role="form"]').trigger('focus');
    });

    $form.on('click', '[data-remove="form"]', function () {
      $(this).closest('.sella-fc-conn').remove();
    });
  }

  $(function () {
    initFilePicker();

    var $form = $('.sella-fc-form');
    if ($form.length) {
      initProducts($form);
      initConnections($form);
    }
  });
})(jQuery);
