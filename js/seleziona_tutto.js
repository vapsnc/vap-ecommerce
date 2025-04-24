(function (Drupal, $) {
  Drupal.behaviors.vapImportSelectAll = {
    attach: function (context, settings) {
      $('#select-all-checkbox', context).once('select-all').on('change', function() {
        $('.import-checkbox', context).prop('checked', this.checked);
      });

      // Se uno dei checkbox viene cambiato, aggiorna il "select all" di conseguenza
      $('.import-checkbox', context).once('individual-check').on('change', function() {
        var allChecked = $('.import-checkbox', context).length === $('.import-checkbox:checked', context).length;
        $('#select-all-checkbox', context).prop('checked', allChecked);
      });
    }
  };
})(Drupal, jQuery);
