(function ($) {
  'use strict';

  $(function () {
    function syncOptionOrder(select) {
      var ids = [];
      $(select).next('.select2').find('.select2-selection__rendered > li[data-select2-id]').each(function () {
        ids.push($(this).attr('data-select2-id'));
      });

      ids.forEach(function (id) {
        var option = $(select).find('option[data-select2-id="' + id + '"]');
        if (option.length) {
          $(select).append(option);
        }
      });
      $(select).trigger('change.select2');
    }

    $('.sella-upsell-admin .wc-product-search').each(function () {
      var select = this;
      $(select).selectWoo({
        minimumInputLength: 2,
        allowClear: true,
        width: 'resolve',
      });

      $(select).on('select2:select select2:unselect', function () {
        window.setTimeout(function () {
          $(select).next('.select2').find('.select2-selection__rendered').sortable({
            containment: 'parent',
            items: '> li.select2-selection__choice',
            update: function () {
              syncOptionOrder(select);
            },
          });
        }, 0);
      });

      $(select).trigger('select2:select');
    });

    function toggleScopeProducts() {
      var scope = $('#sella_upsell_scope').val();
      $('.sella-upsell-scope-products').prop('hidden', scope !== 'products');
    }

    $('#sella_upsell_scope').on('change', toggleScopeProducts);
    toggleScopeProducts();
  });
})(jQuery);
