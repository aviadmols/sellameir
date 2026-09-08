(function ($) {
  'use strict';

  function nextIndex($rows) {
    var max = -1;
    $rows.find('.sella-repeater-row').each(function () {
      $(this)
        .find('input[name]')
        .each(function () {
          var match = $(this)
            .attr('name')
            .match(/\[(\d+)\]/);
          if (match) {
            max = Math.max(max, parseInt(match[1], 10));
          }
        });
    });
    return max + 1;
  }

  $(document).on('click', '.sella-add-row', function (e) {
    e.preventDefault();
    var $repeater = $(this).closest('.sella-repeater');
    var $rows = $repeater.find('.sella-repeater-rows');
    var template = $repeater.find('.sella-row-template').html();
    if (!template) {
      return;
    }
    var html = template.replace(/__i__/g, String(nextIndex($rows)));
    $rows.append(html);
  });

  $(document).on('click', '.sella-remove-row', function (e) {
    e.preventDefault();
    var $rows = $(this).closest('.sella-repeater-rows');
    var $row = $(this).closest('.sella-repeater-row');
    if ($rows.find('.sella-repeater-row').length <= 1) {
      $row.find('input').val('');
      return;
    }
    $row.remove();
  });

  $(document).on('click', '.sella-upload-image', function (e) {
    e.preventDefault();
    var $field = $(this).closest('.sella-barcode-image-field');

    var frame = wp.media({
      title: 'בחירת תמונת ברקוד',
      button: { text: 'השתמש בתמונה' },
      multiple: false,
    });

    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      $field.find('#_sella_barcode_image').val(attachment.id);
      var url =
        (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) ||
        attachment.url;
      $field.find('.sella-media-preview').html('<img src="' + url + '" alt="" />');
      $field.find('.sella-upload-image').text('החלף תמונה');
      $field.find('.sella-remove-image').show();
    });

    frame.open();
  });

  $(document).on('click', '.sella-remove-image', function (e) {
    e.preventDefault();
    var $field = $(this).closest('.sella-barcode-image-field');
    $field.find('#_sella_barcode_image').val('');
    $field.find('.sella-media-preview').empty();
    $field.find('.sella-upload-image').text('העלה / בחר תמונה');
    $(this).hide();
  });
})(jQuery);
