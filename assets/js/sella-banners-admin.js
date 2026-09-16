(function ($) {
  'use strict';

  $(function () {
    var rows = $('[data-banner-rows]');
    if (!rows.length) {
      return;
    }

    /* Row names carry an index; the template row uses __i__ until it is added. */
    function nextIndex() {
      var highest = -1;
      rows.find('[data-banner-row] input[name^="sella_banner"]').each(function () {
        var match = /sella_banner\[(\d+)\]/.exec(this.name);
        if (match) {
          highest = Math.max(highest, parseInt(match[1], 10));
        }
      });
      return highest + 1;
    }

    $('[data-banner-add]').on('click', function () {
      var markup = $('#tmpl-sella-banner-row').html().split('__i__').join(String(nextIndex()));
      rows.append(markup);
    });

    rows.on('click', '.sella-banner-row__remove', function () {
      $(this).closest('[data-banner-row]').remove();
    });

    rows.on('click', '.sella-banner-pick__button', function () {
      var pick = $(this).closest('.sella-banner-pick');
      var input = pick.find('input[type="hidden"]');
      var preview = pick.find('.sella-banner-pick__preview');

      var frame = window.wp.media({
        title: 'בחירת באנר',
        button: { text: 'שימוש בתמונה' },
        library: { type: 'image' },
        multiple: false
      });

      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        var sizes = attachment.sizes || {};
        var thumb = (sizes.medium_large || sizes.large || sizes.medium || sizes.full || {}).url || attachment.url;

        input.val(attachment.id);
        preview.removeClass('is-empty').html($('<img>').attr('src', thumb));
        pick.find('.sella-banner-pick__clear').prop('hidden', false);
      });

      frame.open();
    });

    rows.on('click', '.sella-banner-pick__clear', function () {
      var pick = $(this).closest('.sella-banner-pick');
      pick.find('input[type="hidden"]').val('');
      pick.find('.sella-banner-pick__preview').addClass('is-empty').empty();
      $(this).prop('hidden', true);
    });

    if ($.fn.sortable) {
      rows.sortable({
        handle: '.sella-banner-row__handle',
        items: '> [data-banner-row]',
        axis: 'y',
        tolerance: 'pointer'
      });
    }
  });
})(jQuery);
