jQuery(document).ready(function($) {
    var changes = {};
    var changeHistory = [];
    var postsPerPage = parseInt(bulk_editor_vars.posts_per_page, 10);
    var offset = postsPerPage;

    function logChange(postId, metaKey, oldValue, newValue) {
        changeHistory.push({postId: postId, metaKey: metaKey, oldValue: oldValue, newValue: newValue});
        var i18n = bulk_editor_vars.i18n;
        var label = metaKey === '_yoast_wpseo_title' ? i18n.label_title :
                    metaKey === '_yoast_wpseo_metadesc' ? i18n.label_meta_description :
                    metaKey === '_yoast_wpseo_focuskw' ? i18n.label_keyword :
                    metaKey === '_yoast_wpseo_canonical' ? i18n.label_canonical_url :
                    metaKey === '_yoast_wpseo_opengraph-title' ? i18n.label_social_title :
                    metaKey;
        $('#history-log').append('<li>' + label + ' for post ' + postId + ' updated.</li>');
    }

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

    function updateCategoryFilter() {
        var type = $('#post-type-filter').val();
        if (type === 'post') {
            $('#category-filter').prop('disabled', false);
        } else {
            $('#category-filter').prop('disabled', true).val('');
        }
        filterRows();
    }

    $('#search-box').on('keyup', filterRows);
    $('#category-filter').on('change', filterRows);
    $('#post-type-filter').on('change', updateCategoryFilter);

    // Initialize state on page load
    updateCategoryFilter();

    $(document).on('click', 'td.editable', function() {
        // Prevent clearing existing content if the cell is already being edited
        if ($(this).hasClass('cellEditing')) {
            return;
        }

        var originalContent = $(this).text();
        var postId = $(this).parent().data('post-id');
        var metaKey = $(this).data('meta-key');

        $(this).addClass('cellEditing');

        var limit = null;
        if (metaKey === '_yoast_wpseo_title' || metaKey === '_yoast_wpseo_opengraph-title') {
            limit = 60;
        } else if (metaKey === '_yoast_wpseo_metadesc') {
            limit = 160;
        }

        var $textarea = $('<textarea>' + originalContent + '</textarea>');
        var $counter = $('<div class="char-counter"></div>');

        function updateCounter() {
            if (limit === null) {
                $counter.text('');
                return;
            }
            var remaining = limit - $textarea.val().length;
            $counter.removeClass('ok warning exceeded');
            if (remaining < 0) {
                $counter.addClass('exceeded');
                $counter.text(Math.abs(remaining) + ' over limit');
            } else {
                if (remaining < 10) {
                    $counter.addClass('warning');
                } else {
                    $counter.addClass('ok');
                }
                $counter.text(remaining + ' characters remaining');
            }
        }

        $textarea.on('input', updateCounter);
        updateCounter();

        $(this).html('');
        $(this).append($textarea).append($counter);

        $textarea.focus().one('blur', function() {
            var newContent = $(this).val();
            var $parent = $(this).parent();
            $parent.text(newContent);
            $parent.removeClass('cellEditing');

            if (!changes[postId]) {
                changes[postId] = {};
            }
            changes[postId][metaKey] = newContent;
            logChange(postId, metaKey, originalContent, newContent);
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
                        showNotification(bulk_editor_vars.i18n.meta_updated, 'success');
                    } else {
                        // Show error notification
                        showNotification(bulk_editor_vars.i18n.update_failed, 'error');
                    }
                }).fail(function(xhr, status, error) {
                    console.log('Failed to update meta info:', error);
                    showNotification(bulk_editor_vars.i18n.update_failed, 'error');
                });
            }
        }
    });

    $('#undo-btn').click(function() {
        var last = changeHistory.pop();
        if (!last) {
            showNotification(bulk_editor_vars.i18n.nothing_to_undo, 'error');
            return;
        }

        var selector = 'tr[data-post-id="' + last.postId + '"] td[data-meta-key="' + last.metaKey + '"]';
        var $cell = $(selector);
        $cell.text(last.oldValue);

        if (!changes[last.postId]) {
            changes[last.postId] = {};
        }
        changes[last.postId][last.metaKey] = last.oldValue;

        var data = {
            'action': 'save_meta_info',
            'post_id': last.postId,
            'meta_key': last.metaKey,
            'meta_value': last.oldValue
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                showNotification(bulk_editor_vars.i18n.change_reverted, 'success');
            } else {
                showNotification(bulk_editor_vars.i18n.revert_failed, 'error');
            }
        }).fail(function() {
            showNotification(bulk_editor_vars.i18n.revert_failed, 'error');
        });

        $('#history-log li').last().remove();
    });

    $('#load-more-btn').on('click', function() {
        var data = {
            'action': 'load_more_posts',
            'offset': offset
        };

        $.post(ajaxurl, data, function(response) {
            if (response) {
                $('#meta_info_table tbody').append(response);
                offset += postsPerPage;
                filterRows();
            } else {
                $('#load-more-btn').hide();
            }
        }).fail(function() {
            showNotification(bulk_editor_vars.i18n.load_failed, 'error');
        });
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
