(function ($) {
  'use strict';

  function toggleScopeRows() {
    var scope = $('input[name="sella_badge_scope"]:checked').val();
    $('.sella-badge-scope-row').each(function () {
      var rowScope = $(this).data('scope');
      $(this).toggle(rowScope === scope);
    });
  }

  function updatePreview() {
    var $form = $('#sella_badge_label').closest('form');
    var bg = $form.find('#sella_badge_color').val() || '#c45c26';
    var fg = $form.find('#sella_badge_text_color').val() || '#ffffff';
    var text = $form.find('#sella_badge_label').val() || 'טקסט התגית';
    // Only the live preview in the form — never the chips in the list.
    $form.find('.sella-badge-preview--live').css({ background: bg, color: fg }).text(text);
  }

  $(function () {
    if (!$('.sella-badge-admin').length) {
      return;
    }

    $('.sella-color-field').wpColorPicker({
      change: function () {
        setTimeout(updatePreview, 10);
      },
      clear: function () {
        setTimeout(updatePreview, 10);
      },
    });

    $('input[name="sella_badge_scope"]').on('change', toggleScopeRows);
    $('#sella_badge_label').on('input keyup', updatePreview);
    toggleScopeRows();
    updatePreview();

    if ($.fn.selectWoo) {
      $('.sella-category-select').selectWoo({
        width: '100%',
        dir: 'rtl',
        placeholder: 'בחרו קטגוריות...',
        allowClear: true,
      });
    }
  });
})(jQuery);
