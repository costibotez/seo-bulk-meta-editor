jQuery(document).ready(function($) {
    var changes = {};

    $('td.editable').click(function() {
        var originalContent = $(this).text();
        var postId = $(this).parent().data('post-id');
        var metaKey = $(this).data('meta-key');

        $(this).addClass("cellEditing");
        $(this).html("<input type='text' value='" + originalContent + "' />");
        $(this).children().first().focus();

        $(this).children().first().blur(function() {
            var newContent = $(this).val();
            $(this).parent().text(newContent);
            $(this).parent().removeClass("cellEditing");

            if (!changes[postId]) {
                changes[postId] = {};
            }
            changes[postId][metaKey] = newContent;
        });
    });

    $('#save-btn').click(function() {
        for (var postId in changes) {
            for (var metaKey in changes[postId]) {
                var newContent = changes[postId][metaKey];

                var data = {
                    'action': 'save_meta_info',
                    'post_id': postId,
                    'meta_key': metaKey,
                    'meta_value': newContent
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
        }
    });

    // Function to show the notification
    function showNotification(message, type) {
        alert(message);
    }
});
