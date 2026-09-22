(function ($) {
  'use strict';

  var cfg = window.sellaOfferAdmin || {};

  $(function () {
    $('.sella-offer-admin').on('submit', '.sella-offer-delete', function () {
      return window.confirm(cfg.confirmDelete);
    });

    var $form = $('.sella-offer-edit');
    if (!$form.length) {
      return;
    }

    var $product = $('#sella_offer_product');
    var $price = $('#sella_offer_price');
    var $headline = $('#sella_offer_headline');
    var $text = $('#sella_offer_text');
    var $button = $('#sella_offer_button');
    var $rule = $('#sella_offer_rule');
    var $regular = $form.find('.sella-offer-regular');
    var $productError = $form.find('.sella-offer-product-error');
    var product = $form.data('product') || null;
    var preview = {};

    $form.find('[data-preview]').each(function () {
      preview[$(this).data('preview')] = $(this);
    });

    function formatPrice(value) {
      var number = parseFloat(value);
      if (isNaN(number)) {
        return '';
      }
      return (cfg.priceFormat || '%1$s%2$s')
        .replace('%1$s', cfg.currency || '')
        .replace('%2$s', number.toFixed(parseInt(cfg.decimals, 10) || 0));
    }

    function syncPreview() {
      var headline = $.trim($headline.val());
      var text = $.trim($text.val());
      var price = $price.val();
      var showWas = !!product && price !== '' && parseFloat(product.priceRaw) > parseFloat(price);

      preview.headline.text(headline).prop('hidden', !headline);
      preview.text.text(text).prop('hidden', !text);
      preview.button.text($.trim($button.val()) || cfg.defaultButton);
      preview.name.text(product ? product.name : cfg.noProduct);
      preview.image.attr('src', product ? product.image : cfg.placeholder);
      preview.now.text(formatPrice(price) || '—');
      preview.was.text(showWas ? product.price : '').prop('hidden', !showWas);
    }

    function syncProduct() {
      $regular.prop('hidden', !product).find('strong').text(product ? product.price : '');
      if (product && !product.simple) {
        $productError.text(cfg.notSimple).prop('hidden', false);
      }
      syncPreview();
    }

    function syncRule() {
      $form.find('.sella-offer-rule-match').prop('hidden', $rule.val() === 'any');
    }

    $product.on('change', function () {
      var id = $product.val();
      $productError.prop('hidden', true);

      if (!id) {
        product = null;
        syncProduct();
        return;
      }

      $.get(cfg.ajaxUrl, { action: 'sella_offer_product_info', nonce: cfg.nonce, product_id: id }).done(function (response) {
        product = response && response.success ? response.data : null;
        syncProduct();
      });
    });

    $form.on('input change', '#sella_offer_price, #sella_offer_headline, #sella_offer_text, #sella_offer_button', syncPreview);
    $rule.on('change', syncRule);

    // The book picker is a hidden <select>, so the browser cannot flag it itself.
    $form.on('submit', function (event) {
      if ($product.val()) {
        return;
      }
      event.preventDefault();
      $productError.text(cfg.chooseProduct).prop('hidden', false);
      $('html, body').animate({ scrollTop: $product.closest('.sella-offer-section').offset().top - 60 }, 200);
    });

    syncRule();
    syncProduct();
  });
})(jQuery);
