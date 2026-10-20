(function ($) {
  'use strict';

  /**
   * Subscription plans screen (inc/sella-subscription-plans.php): add, remove
   * and drag benefit rows and plan cards. Each plan's "what is included" list
   * follows the benefit rows as they are typed, added, removed or reordered.
   */
  var cfg = window.sellaPlansAdmin || {};

  $(function () {
    var $form = $('.sella-plans-form');
    if (!$form.length) {
      return;
    }

    var $features = $form.find('.sella-plans-features');
    var $plans = $form.find('.sella-plans-cards');
    var counter = Date.now();

    /* A new row or card, with a field index no other one has. */
    function fromTemplate(id) {
      var html = $('#' + id).html().replace(/__i__/g, 'n' + counter++);
      return $($.parseHTML($.trim(html)));
    }

    function featureList() {
      return $features.children('.sella-plans-feature').map(function () {
        var $row = $(this);
        return {
          id: $row.find('[data-field="id"]').val(),
          text: $.trim($row.find('[data-field="text"]').val()) || $.trim($row.find('[data-field="short"]').val())
        };
      }).get();
    }

    /* Rebuild every plan's checkboxes from the benefit rows, keeping what was ticked. */
    function syncIncludes() {
      var list = featureList();

      $plans.find('.sella-plans-includes').each(function () {
        var $box = $(this);
        var name = $box.data('name');
        var checked = {};

        $box.find('input:checked').each(function () {
          checked[this.value] = true;
        });

        $box.empty();
        list.forEach(function (feature) {
          var $label = $('<label class="sella-plans-include"></label>');
          $('<input type="checkbox" />')
            .attr({ name: name, value: feature.id })
            .prop('checked', !!checked[feature.id])
            .appendTo($label);
          $('<span></span>').text(feature.text || cfg.untitled || '').appendTo($label);
          $box.append($label);
        });
      });
    }

    $form.on('click', '[data-add="feature"]', function () {
      var $row = fromTemplate('tmpl-sella-plans-feature');
      $row.find('[data-field="id"]').val('n' + (counter++).toString(36));
      $features.append($row);
      syncIncludes();
      $row.find('[data-field="text"]').trigger('focus');
    });

    $form.on('click', '[data-add="plan"]', function () {
      var $card = fromTemplate('tmpl-sella-plans-plan');
      $plans.append($card);
      syncIncludes();
      // WooCommerce turns new product fields into its search box on this event.
      $(document.body).trigger('wc-enhanced-select-init');
      $card.find('[data-field="name"]').trigger('focus');
    });

    $form.on('click', '[data-remove="feature"]', function () {
      $(this).closest('.sella-plans-feature').remove();
      syncIncludes();
    });

    $form.on('click', '[data-remove="plan"]', function () {
      if (window.confirm(cfg.confirmRemove || '')) {
        $(this).closest('.sella-plans-card').remove();
      }
    });

    $features.on('input', '[data-field="text"], [data-field="short"]', syncIncludes);

    $plans.on('input', '[data-field="name"]', function () {
      $(this).closest('.sella-plans-card').find('.sella-plans-card__title')
        .text($.trim(this.value) || cfg.newPlan || '');
    });

    $features.sortable({
      handle: '.sella-plans-handle',
      axis: 'y',
      update: syncIncludes
    });

    $plans.sortable({
      handle: '.sella-plans-card__head',
      cancel: 'button',
      tolerance: 'pointer',
      placeholder: 'sella-plans-card sella-plans-card--placeholder'
    });
  });
})(jQuery);
