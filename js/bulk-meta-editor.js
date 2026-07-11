jQuery(document).ready(function($) {
    var changes = {};
    var changeHistory = [];
    var postsPerPage = parseInt(bulk_editor_vars.posts_per_page, 10);
    var offset = postsPerPage;
    var i18n = bulk_editor_vars.i18n;
    var nonce = bulk_editor_vars.nonce;
    var serpDevice = 'desktop';
    var $activeRow = null;

    /* --------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    function labelForKey(metaKey) {
        return metaKey === '_yoast_wpseo_title' ? i18n.label_title :
               metaKey === '_yoast_wpseo_metadesc' ? i18n.label_meta_description :
               metaKey === '_yoast_wpseo_focuskw' ? i18n.label_keyword :
               metaKey === '_yoast_wpseo_canonical' ? i18n.label_canonical_url :
               metaKey === '_yoast_wpseo_opengraph-title' ? i18n.label_social_title :
               metaKey;
    }

    function logChange(postId, metaKey, oldValue, newValue) {
        changeHistory.push({postId: postId, metaKey: metaKey, oldValue: oldValue, newValue: newValue});
        $('#history-log').append('<li>' + labelForKey(metaKey) + ' for post ' + postId + ' updated.</li>');
    }

    // Read the current value of a field for a row, preferring a visible/edited
    // cell but falling back to the row data attribute when the column is hidden.
    function rowValue($row, metaKey, dataAttr) {
        var $cell = $row.find('td.editable[data-meta-key="' + metaKey + '"]');
        if ($cell.length) {
            return $.trim($cell.text());
        }
        var v = $row.data(dataAttr);
        return v === undefined || v === null ? '' : ('' + v).trim();
    }

    function rowFields($row) {
        return {
            title:   rowValue($row, '_yoast_wpseo_title', 'meta-title'),
            desc:    rowValue($row, '_yoast_wpseo_metadesc', 'meta-desc'),
            keyword: rowValue($row, '_yoast_wpseo_focuskw', 'keyword')
        };
    }

    /* --------------------------------------------------------------------
     * SEO health score
     * ----------------------------------------------------------------- */

    function scoreFields(f) {
        var issues = [];
        var critical = false;

        if (!f.title) {
            issues.push(i18n.score_missing_title);
            critical = true;
        } else if (f.title.length > 60) {
            issues.push(i18n.score_title_long);
        } else if (f.title.length < 30) {
            issues.push(i18n.score_title_short);
        }

        if (!f.desc) {
            issues.push(i18n.score_missing_desc);
            critical = true;
        } else if (f.desc.length > 160) {
            issues.push(i18n.score_desc_long);
        } else if (f.desc.length < 120) {
            issues.push(i18n.score_desc_short);
        }

        if (!f.keyword) {
            issues.push(i18n.score_no_keyword);
        }

        var level = 'good';
        if (critical) {
            level = 'bad';
        } else if (issues.length) {
            level = 'warn';
        }
        return {level: level, issues: issues};
    }

    function scoreRow($row) {
        var $dot = $row.find('.ybme-score-dot');
        if (!$dot.length) {
            // Column hidden - still stamp the row so filtering works.
            var res0 = scoreFields(rowFields($row));
            $row.attr('data-seo-level', res0.level);
            return;
        }
        var res = scoreFields(rowFields($row));
        $dot.removeClass('is-good is-warn is-bad').addClass('is-' + res.level);
        $dot.attr('title', res.issues.length ? res.issues.join('\n') : i18n.score_good);
        $row.attr('data-seo-level', res.level);
    }

    function scoreAll() {
        $('#meta_info_table tbody tr').each(function() {
            scoreRow($(this));
        });
    }

    /* --------------------------------------------------------------------
     * SERP preview
     * ----------------------------------------------------------------- */

    function truncate(str, max) {
        if (str.length <= max) { return str; }
        return str.slice(0, max - 1).replace(/\s+\S*$/, '') + '…';
    }

    function renderSerp($row, overrides) {
        if (!$row || !$row.length) { return; }
        $activeRow = $row;
        var f = rowFields($row);
        overrides = overrides || {};
        var title = (overrides.title !== undefined ? overrides.title : f.title) || ($row.data('name') || '');
        var desc = overrides.desc !== undefined ? overrides.desc : f.desc;
        var url = ($row.data('permalink') || '').toString();

        var titleMax = serpDevice === 'mobile' ? 60 : 60;
        var descMax = serpDevice === 'mobile' ? 130 : 160;

        var $panel = $('#ybme-serp-preview');
        $panel.removeClass('is-mobile is-desktop').addClass('is-' + serpDevice);
        $panel.find('.ybme-serp-url').text(url);
        $panel.find('.ybme-serp-title').text(truncate(title, titleMax));
        $panel.find('.ybme-serp-desc').text(desc ? truncate(desc, descMax) : '');
        $panel.show();
    }

    $('.ybme-serp-device').on('click', function() {
        serpDevice = $(this).data('device');
        $('.ybme-serp-device').removeClass('is-active');
        $(this).addClass('is-active');
        if ($activeRow) { renderSerp($activeRow); }
    });

    /* --------------------------------------------------------------------
     * Filtering (search / type / category / SEO score)
     * ----------------------------------------------------------------- */

    function filterRows() {
        var search = $('#search-box').val().toLowerCase();
        var category = $('#category-filter').val();
        var type = $('#post-type-filter').val();
        var seo = $('#seo-filter').val();

        $('#meta_info_table tbody tr').each(function() {
            var $row = $(this);
            var title = ($row.data('title') || '').toString();
            var cats = ($row.data('categories') || '').toString();
            var postType = ($row.data('post-type') || '').toString();
            var level = ($row.attr('data-seo-level') || '').toString();

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
            if (seo === 'problems' && level === 'good') {
                match = false;
            }
            if (seo === 'bad' && level !== 'bad') {
                match = false;
            }
            if (seo === 'good' && level !== 'good') {
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
    $('#seo-filter').on('change', filterRows);
    $('#post-type-filter').on('change', updateCategoryFilter);

    // Initialize state on page load
    scoreAll();
    updateCategoryFilter();

    /* --------------------------------------------------------------------
     * Inline editing
     * ----------------------------------------------------------------- */

    $(document).on('click', 'td.editable', function() {
        // Prevent clearing existing content if the cell is already being edited
        if ($(this).hasClass('cellEditing')) {
            return;
        }

        var $cell = $(this);
        var originalContent = $cell.text();
        var $row = $cell.parent();
        var postId = $row.data('post-id');
        var metaKey = $cell.data('meta-key');

        $cell.addClass('cellEditing');
        renderSerp($row);

        var limit = null;
        if (metaKey === '_yoast_wpseo_title' || metaKey === '_yoast_wpseo_opengraph-title') {
            limit = 60;
        } else if (metaKey === '_yoast_wpseo_metadesc') {
            limit = 160;
        }

        var $textarea = $('<textarea></textarea>').val(originalContent);
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

        $textarea.on('input', function() {
            updateCounter();
            // Live SERP preview while typing title / description.
            if (metaKey === '_yoast_wpseo_title') {
                renderSerp($row, {title: $textarea.val()});
            } else if (metaKey === '_yoast_wpseo_metadesc') {
                renderSerp($row, {desc: $textarea.val()});
            }
        });
        updateCounter();

        $cell.html('');
        $cell.append($textarea).append($counter);

        $textarea.focus().one('blur', function() {
            var newContent = $(this).val();
            $cell.text(newContent);
            $cell.removeClass('cellEditing');

            // Keep the row data attribute in sync for scoring / preview.
            if (metaKey === '_yoast_wpseo_title') { $row.attr('data-meta-title', newContent); }
            if (metaKey === '_yoast_wpseo_metadesc') { $row.attr('data-meta-desc', newContent); }
            if (metaKey === '_yoast_wpseo_focuskw') { $row.attr('data-keyword', newContent); }

            if (!changes[postId]) {
                changes[postId] = {};
            }
            changes[postId][metaKey] = newContent;
            logChange(postId, metaKey, originalContent, newContent);
            scoreRow($row);
            renderSerp($row);
            filterRows();
        });
    });

    /* --------------------------------------------------------------------
     * Save / undo
     * ----------------------------------------------------------------- */

    $('#save-btn').click(function() {
        for (var postId in changes) {
            for (var metaKey in changes[postId]) {
                var newContent = changes[postId][metaKey];

                var data = {
                    'action': 'save_meta_info',
                    'nonce': nonce,
                    'post_id': postId,
                    'meta_key': metaKey,
                    'meta_value': newContent
                };

                $.post(ajaxurl, data, function(response) {
                    if (response && response.success) {
                        showNotification(i18n.meta_updated, 'success');
                    } else {
                        showNotification(i18n.update_failed, 'error');
                    }
                }).fail(function(xhr, status, error) {
                    showNotification(i18n.update_failed, 'error');
                });
            }
        }
    });

    $('#undo-btn').click(function() {
        var last = changeHistory.pop();
        if (!last) {
            showNotification(i18n.nothing_to_undo, 'error');
            return;
        }

        var selector = 'tr[data-post-id="' + last.postId + '"] td[data-meta-key="' + last.metaKey + '"]';
        var $cell = $(selector);
        $cell.text(last.oldValue);
        var $row = $cell.parent();

        if (!changes[last.postId]) {
            changes[last.postId] = {};
        }
        changes[last.postId][last.metaKey] = last.oldValue;

        if (last.metaKey === '_yoast_wpseo_title') { $row.attr('data-meta-title', last.oldValue); }
        if (last.metaKey === '_yoast_wpseo_metadesc') { $row.attr('data-meta-desc', last.oldValue); }
        if (last.metaKey === '_yoast_wpseo_focuskw') { $row.attr('data-keyword', last.oldValue); }

        var data = {
            'action': 'save_meta_info',
            'nonce': nonce,
            'post_id': last.postId,
            'meta_key': last.metaKey,
            'meta_value': last.oldValue
        };

        $.post(ajaxurl, data, function(response) {
            if (response && response.success) {
                showNotification(i18n.change_reverted, 'success');
            } else {
                showNotification(i18n.revert_failed, 'error');
            }
        }).fail(function() {
            showNotification(i18n.revert_failed, 'error');
        });

        scoreRow($row);
        $('#history-log li').last().remove();
    });

    /* --------------------------------------------------------------------
     * Load more
     * ----------------------------------------------------------------- */

    $('#load-more-btn').on('click', function() {
        var data = {
            'action': 'load_more_posts',
            'nonce': nonce,
            'offset': offset
        };

        $.post(ajaxurl, data, function(response) {
            if (response && $.trim(response)) {
                $('#meta_info_table tbody').append(response);
                offset += postsPerPage;
                scoreAll();
                filterRows();
            } else {
                $('#load-more-btn').hide();
            }
        }).fail(function() {
            showNotification(i18n.load_failed, 'error');
        });
    });

    /* --------------------------------------------------------------------
     * Bulk find & replace
     * ----------------------------------------------------------------- */

    $('#ybme-fr-toggle').on('click', function() {
        $('#ybme-fr-body').slideToggle(150);
    });

    function frPayload(action) {
        return {
            'action': action,
            'nonce': nonce,
            'field': $('#ybme-fr-field').val(),
            'find': $('#ybme-fr-find').val(),
            'replace': $('#ybme-fr-replace').val(),
            'case_sensitive': $('#ybme-fr-case').is(':checked') ? 1 : 0,
            'regex': $('#ybme-fr-regex').is(':checked') ? 1 : 0
        };
    }

    function renderFrSamples(resp) {
        var $out = $('#ybme-fr-results');
        if (!resp || !resp.affected) {
            $out.html('<p>' + i18n.fr_no_matches + '</p>');
            $('#ybme-fr-apply').prop('disabled', true);
            return;
        }
        var html = '<p>' + i18n.fr_matches.replace('%d', resp.affected) + '</p>';
        html += '<table class="ybme-fr-samples"><thead><tr><th></th><th>Before</th><th>After</th></tr></thead><tbody>';
        $.each(resp.samples, function(i, s) {
            html += '<tr><td>' + escapeHtml(s.title) + '</td><td>' + escapeHtml(s.old) + '</td><td>' + escapeHtml(s.new) + '</td></tr>';
        });
        html += '</tbody></table>';
        $out.html(html);
        $('#ybme-fr-apply').prop('disabled', false);
    }

    $('#ybme-fr-preview').on('click', function() {
        if (!$.trim($('#ybme-fr-find').val())) {
            showNotification(i18n.fr_enter_find, 'error');
            return;
        }
        $('#ybme-fr-results').html('<p>…</p>');
        $.post(ajaxurl, frPayload('ybme_find_replace_preview'), function(resp) {
            if (resp && resp.success) {
                renderFrSamples(resp.data);
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : i18n.fr_error;
                $('#ybme-fr-results').html('<p class="ybme-fr-error">' + escapeHtml(msg) + '</p>');
                $('#ybme-fr-apply').prop('disabled', true);
            }
        }).fail(function() {
            $('#ybme-fr-results').html('<p class="ybme-fr-error">' + i18n.fr_error + '</p>');
        });
    });

    $('#ybme-fr-apply').on('click', function() {
        if (!window.confirm(i18n.fr_confirm)) {
            return;
        }
        var $btn = $(this).prop('disabled', true);
        $.post(ajaxurl, frPayload('ybme_find_replace_apply'), function(resp) {
            if (resp && resp.success) {
                showNotification(i18n.fr_applied.replace('%d', resp.data.affected), 'success');
                $('#ybme-fr-results').html('<p>' + i18n.fr_applied.replace('%d', resp.data.affected) + '</p>');
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : i18n.fr_error;
                showNotification(msg, 'error');
                $btn.prop('disabled', false);
            }
        }).fail(function() {
            showNotification(i18n.fr_error, 'error');
            $btn.prop('disabled', false);
        });
    });

    /* --------------------------------------------------------------------
     * Notifications
     * ----------------------------------------------------------------- */

    function escapeHtml(str) {
        return $('<div>').text(str === undefined || str === null ? '' : str).html();
    }

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
