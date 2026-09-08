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
    var bg = $form.find('#sella_badge_color').val() || '#D7263D';
    var fg = $form.find('#sella_badge_text_color').val() || '#ffffff';
    var text = $form.find('#sella_badge_label').val() || 'טקסט התגית';
    $form.find('.sella-badge-preview--live').css({ background: bg, color: fg }).text(text);
  }

  function selectSwatch($btn) {
    var $form = $btn.closest('form');
    var color = $btn.data('color');
    var text = $btn.data('text');

    $form.find('#sella_badge_color').val(color);
    $form.find('#sella_badge_text_color').val(text);
    $form.find('.sella-badge-swatch').removeClass('is-selected').attr('aria-pressed', 'false');
    $btn.addClass('is-selected').attr('aria-pressed', 'true');
    $form.find('.sella-badge-swatch-label').text($btn.attr('title') || color);
    updatePreview();
  }

  $(function () {
    if (!$('.sella-badge-admin').length) {
      return;
    }

    $(document).on('click', '.sella-badge-swatch', function (e) {
      e.preventDefault();
      selectSwatch($(this));
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
