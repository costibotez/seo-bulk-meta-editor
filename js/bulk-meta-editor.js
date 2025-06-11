jQuery(document).ready(function($) {
    var changes = {};

    function filterRows() {
        var search = $('#search-box').val().toLowerCase();
        var category = $('#category-filter').val();
        var type = $('#post-type-filter').val();

        $('#meta_info_table tbody tr').each(function() {
            var $row = $(this);
            var title = ($row.data('title') || '').toString();
            var cats = ($row.data('categories') || '').toString();
            var postType = ($row.data('post-type') || '').toString();

            var match = true;

            if (search && title.indexOf(search) === -1) {
                match = false;
            }
            if (category && cats.indexOf(category) === -1) {
                match = false;
            }
            if (type && postType !== type) {
                match = false;
            }

            $row.toggle(match);
        });
    }

    $('#search-box').on('keyup', filterRows);
    $('#category-filter, #post-type-filter').on('change', filterRows);

    $('td.editable').on('click', function() {
        // Prevent clearing existing content if the cell is already being edited
        if ($(this).hasClass('cellEditing')) {
            return;
        }

        var originalContent = $(this).text();
        var postId = $(this).parent().data('post-id');
        var metaKey = $(this).data('meta-key');

        $(this).addClass('cellEditing');
        $(this).html('<textarea>' + originalContent + '</textarea>');
        $(this).children('textarea').focus().one('blur', function() {
            var newContent = $(this).val();
            var $parent = $(this).parent();
            $parent.text(newContent);
            $parent.removeClass('cellEditing');

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
        var $notification = $('#notification');
        $notification
            .text(message)
            .removeClass('success error')
            .addClass(type)
            .fadeIn(200, function() {
                var $self = $(this);
                setTimeout(function() {
                    $self.fadeOut(200);
                }, 3000);
            });
    }
});
