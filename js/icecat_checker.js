(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.icecatChecker = {
    attach: function (context, settings) {
      // Gestione messaggi di stato
      const statusMessages = $('.status-message');
      if (statusMessages.length) {
        console.log('Import status:', statusMessages.text());
      }

      $('.check-icecat-btn', context).once('icecat-check').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $row = $button.closest('tr');
        const ean = $row.find('.ean-field').val();

        if (!ean) {
          alert('EAN non valido');
          return;
        }

        $button.prop('disabled', true)
               .html('<span class="spinner">⌛</span> Verifica...');

        $.ajax({
          url: '/admin/vap/import/check-icecat',
          method: 'GET',
          data: { ean: ean },
          success: function(response) {
            if (response.error) {
              console.error('Icecat error:', response.error);
              $button.html('❌ Errore')
                     .addClass('error');
              Drupal.behaviors.icecatChecker.showMessage(response.error, 'error');
            } else {
              const msg = `Prodotto trovato:\nNome: ${response.nome}\nMarca: ${response.brand}`;
              console.log('Icecat success:', msg);
              $button.html('✓ Verificato')
                     .addClass('success');
              Drupal.behaviors.icecatChecker.showMessage(msg, 'success');
            }
          },
          error: function(xhr, status, error) {
            console.error('Ajax error:', {xhr, status, error});
            $button.html('❌ Errore')
                   .addClass('error');
            Drupal.behaviors.icecatChecker.showMessage('Errore di comunicazione', 'error');
          },
          complete: function() {
            setTimeout(function() {
              $button.prop('disabled', false)
                     .removeClass('error success')
                     .html('Verifica su Icecat');
            }, 3000);
          }
        });
      });
    },

    showMessage: function(message, type) {
      const $messages = $('.messages');
      if (!$messages.length) {
        $('<div class="messages"></div>').insertBefore('.preview-table');
      }
      
      $('<div class="message ' + type + '">')
        .text(message)
        .prependTo('.messages')
        .delay(5000)
        .fadeOut(500, function() { $(this).remove(); });
    }
  };
})(jQuery, Drupal);
