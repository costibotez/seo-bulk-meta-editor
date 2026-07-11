jQuery(document).ready(function($) {
    var vars = window.ybme_history_vars || {};
    var i18n = vars.i18n || {};

    function showNotification(message, type) {
        var $n = $('#notification');
        if (!$n.length) {
            $n = $('<div id="notification" class="toast"></div>').appendTo('body');
        }
        $n.text(message)
          .removeClass('success error')
          .addClass(type)
          .fadeIn(200, function() {
              var $self = $(this);
              setTimeout(function() { $self.fadeOut(200); }, 3000);
          });
    }

    $(document).on('click', '.ybme-revert', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        if (!window.confirm(i18n.confirm)) {
            return;
        }
        $btn.prop('disabled', true);

        $.post(ajaxurl, {
            action: 'ybme_revert_change',
            nonce: vars.nonce,
            id: id
        }, function(response) {
            if (response && response.success) {
                showNotification(i18n.reverted, 'success');
                $btn.closest('tr').css('opacity', 0.5);
            } else {
                showNotification(i18n.revert_failed, 'error');
                $btn.prop('disabled', false);
            }
        }).fail(function() {
            showNotification(i18n.revert_failed, 'error');
            $btn.prop('disabled', false);
        });
    });
});
