jQuery(document).ready(function($) {
    // Make the table sortable
    $("#meta_info_table").tablesorter();

    // Edit cell on click
    $('td.editable').on('click', function() {
        var originalContent = $(this).text();
        $(this).addClass("cellEditing");
        $(this).html("<input type='text' value='" + originalContent + "' />");
        $(this).children().first().focus();

        $(this).children().first().keypress(function(e) {
            if (e.which == 13) { // Enter key
                var newContent = $(this).val();
                $(this).parent().text(newContent);
                $(this).parent().removeClass("cellEditing");

                var data = {
                    'action': 'meta_info_update',
                    'post_id': $(this).parent().parent().data('post-id'),
                    'meta_key': $(this).parent().data('meta-key'),
                    'new_value': newContent
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        // Show success notification
                        showNotification('Meta info updated successfully', 'success');
                    } else {
                        // Show error notification
                        showNotification('Failed to update meta info', 'error');
                    }
                }).fail(function(xhr, status, error) {
                    console.log('Failed to update meta info:', error);
                    showNotification('Failed to update meta info', 'error');
                });
            }
        });

        $(this).children().first().blur(function() {
            $(this).parent().text(originalContent);
            $(this).parent().removeClass("cellEditing");
        });
    });

    // Function to show the notification
    function showNotification(message, type) {
        var notification = $('#notification');
        notification.text(message);
        notification.removeClass('success error');
        notification.addClass(type);
        notification.fadeIn();
        setTimeout(function() {
            notification.fadeOut();
        }, 3000);
    }
});
