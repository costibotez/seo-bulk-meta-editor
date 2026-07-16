<?php
/**
 * Draft & scheduled changes with an approval workflow.
 *
 * Editors (anyone with the plugin's edit capability) can submit meta changes
 * as drafts instead of saving them directly, optionally requesting a future
 * apply time. Administrators review the queue and approve or reject each
 * draft; approving either applies it immediately or schedules it. A cron
 * applies scheduled drafts when they fall due. Every applied draft goes
 * through the same validation and history logging as a direct save.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YBME_PENDING_CRON_HOOK', 'ybme_pending_cron');

function ybme_pending_table() {
    global $wpdb;
    return $wpdb->prefix . 'ybme_pending';
}

/* -------------------------------------------------------------------------
 * Schema (created during the shared upgrade routine)
 * ---------------------------------------------------------------------- */

add_action('ybme_install_tables', 'ybme_install_pending_table');
function ybme_install_pending_table() {
    global $wpdb;
    $table   = ybme_pending_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        meta_key varchar(191) NOT NULL,
        old_value longtext NULL,
        new_value longtext NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        scheduled_for datetime NULL,
        submitted_by bigint(20) unsigned NOT NULL DEFAULT 0,
        reviewed_by bigint(20) unsigned NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY scheduled_for (scheduled_for)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Count drafts still awaiting review (for the menu bubble).
function ybme_pending_count() {
    global $wpdb;
    $table = ybme_pending_table();
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
}

/* -------------------------------------------------------------------------
 * Submit drafts from the editor
 * ---------------------------------------------------------------------- */

add_action('ybme_editor_actions', 'ybme_pending_editor_button');
function ybme_pending_editor_button() {
    echo '<button type="button" id="ybme-submit-review" class="button" style="padding:10px 20px;margin-right:10px;">' . esc_html__('Submit for Review…', YBME_TEXT_DOMAIN) . '</button>';
    echo '<span id="ybme-schedule-wrap" style="display:none;">';
    echo '<label>' . esc_html__('Apply at:', YBME_TEXT_DOMAIN) . ' <input type="datetime-local" id="ybme-schedule-at" /></label> ';
    echo '<button type="button" id="ybme-submit-review-go" class="button button-primary">' . esc_html__('Submit', YBME_TEXT_DOMAIN) . '</button>';
    echo '</span>';
}

add_filter('ybme_editor_js_vars', 'ybme_pending_js_vars');
function ybme_pending_js_vars($vars) {
    $vars['i18n']['submit_none'] = __('No changes to submit', YBME_TEXT_DOMAIN);
    $vars['i18n']['submit_ok']   = __('%d change(s) submitted for review', YBME_TEXT_DOMAIN);
    $vars['i18n']['submit_fail'] = __('Failed to submit changes', YBME_TEXT_DOMAIN);
    return $vars;
}

add_action('wp_ajax_ybme_pending_submit', 'ybme_pending_submit');
function ybme_pending_submit() {
    check_ajax_referer(YBME_NONCE_ACTION, 'nonce');
    if (!current_user_can(YBME_CAPABILITY)) {
        wp_send_json_error();
    }
    if (!ybme_provider_active()) {
        wp_send_json_error(array('message' => __('No supported SEO plugin is active.', YBME_TEXT_DOMAIN)));
    }

    $raw     = isset($_POST['changes']) ? wp_unslash($_POST['changes']) : '';
    $changes = json_decode($raw, true);
    if (!is_array($changes) || empty($changes)) {
        wp_send_json_error(array('message' => __('No changes to submit.', YBME_TEXT_DOMAIN)));
    }

    // Interpret the optional datetime-local value as site local time.
    $scheduled = null;
    if (!empty($_POST['scheduled_for'])) {
        $input = sanitize_text_field(wp_unslash($_POST['scheduled_for']));
        $dt    = date_create($input, wp_timezone());
        if ($dt) {
            $scheduled = $dt->format('Y-m-d H:i:s');
        }
    }

    $allowed = ybme_allowed_meta_keys();
    $now     = current_time('mysql');
    $uid     = get_current_user_id();
    $table   = ybme_pending_table();
    global $wpdb;

    $queued  = 0;
    $skipped = 0;

    foreach ($changes as $post_id => $fields) {
        $post_id = intval($post_id);
        if ($post_id <= 0 || !is_array($fields)) {
            $skipped++;
            continue;
        }
        if (!current_user_can('edit_post', $post_id)) {
            $skipped++;
            continue;
        }
        foreach ($fields as $meta_key => $value) {
            if (!in_array($meta_key, $allowed, true)) {
                $skipped++;
                continue;
            }
            $old = get_post_meta($post_id, $meta_key, true);
            $new = ybme_sanitize_meta_value($meta_key, (string) $value);
            if ((string) $old === (string) $new) {
                continue; // Nothing actually changed.
            }
            $data = array(
                'post_id'      => $post_id,
                'meta_key'     => $meta_key,
                'old_value'    => $old,
                'new_value'    => $new,
                'status'       => 'pending',
                'submitted_by' => $uid,
                'created_at'   => $now,
                'updated_at'   => $now,
            );
            if ($scheduled !== null) {
                $data['scheduled_for'] = $scheduled;
            }
            $wpdb->insert($table, $data);
            $queued++;
        }
    }

    wp_send_json_success(array('queued' => $queued, 'skipped' => $skipped));
}

/* -------------------------------------------------------------------------
 * Applying a draft
 * ---------------------------------------------------------------------- */

/**
 * Write one draft's value to post meta, re-validating at apply time. Returns
 * true when applied. Re-checks the allow-list and that the ORIGINAL submitter
 * can still edit the post (there is no current user during cron).
 */
function ybme_pending_apply_row($row) {
    if (!in_array($row->meta_key, ybme_allowed_meta_keys(), true)) {
        return false;
    }
    if (!user_can((int) $row->submitted_by, 'edit_post', (int) $row->post_id)) {
        return false;
    }
    $value   = ybme_sanitize_meta_value($row->meta_key, $row->new_value);
    $current = get_post_meta($row->post_id, $row->meta_key, true);
    update_post_meta($row->post_id, $row->meta_key, wp_slash($value));
    ybme_log_change($row->post_id, $row->meta_key, $current, $value);
    return true;
}

/* -------------------------------------------------------------------------
 * Review actions (approve / reject / apply now / cancel)
 * ---------------------------------------------------------------------- */

add_action('admin_init', 'ybme_pending_handle_actions');
function ybme_pending_handle_actions() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'yoast-bulk-meta-editor-pending') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    $action = isset($_GET['ybme_p_action']) ? sanitize_key($_GET['ybme_p_action']) : '';
    if ($action === '') {
        return;
    }
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    check_admin_referer('ybme_pending_' . $id);

    global $wpdb;
    $table = ybme_pending_table();
    $base  = admin_url('admin.php?page=yoast-bulk-meta-editor-pending');
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    if (!$row) {
        wp_safe_redirect($base);
        exit;
    }
    $now = current_time('mysql');
    $me  = get_current_user_id();

    if ($action === 'approve') {
        if (!empty($row->scheduled_for) && $row->scheduled_for > $now) {
            // Future apply time: schedule it for the cron to pick up.
            $wpdb->update($table, array('status' => 'scheduled', 'reviewed_by' => $me, 'updated_at' => $now), array('id' => $id));
        } else {
            $applied = ybme_pending_apply_row($row);
            $wpdb->update($table, array('status' => $applied ? 'applied' : 'rejected', 'reviewed_by' => $me, 'updated_at' => $now), array('id' => $id));
        }
    } elseif ($action === 'apply') {
        $applied = ybme_pending_apply_row($row);
        $wpdb->update($table, array('status' => $applied ? 'applied' : 'rejected', 'reviewed_by' => $me, 'updated_at' => $now), array('id' => $id));
    } elseif ($action === 'reject' || $action === 'cancel') {
        $wpdb->update($table, array('status' => 'rejected', 'reviewed_by' => $me, 'updated_at' => $now), array('id' => $id));
    }

    wp_safe_redirect($base . '&ybme_p_status=' . $action);
    exit;
}

function ybme_pending_action_url($action, $id) {
    return wp_nonce_url(
        admin_url('admin.php?page=yoast-bulk-meta-editor-pending&ybme_p_action=' . $action . '&id=' . $id),
        'ybme_pending_' . $id
    );
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

// Priority 20 so the parent menu (registered at the default priority) exists.
add_action('admin_menu', 'ybme_pending_menu', 20);
function ybme_pending_menu() {
    $count = ybme_pending_count();
    $title = __('Pending Changes', YBME_TEXT_DOMAIN);
    $menu  = $title;
    if ($count > 0) {
        $menu .= ' <span class="awaiting-mod"><span class="pending-count">' . number_format_i18n($count) . '</span></span>';
    }
    add_submenu_page(
        'yoast-bulk-meta-editor',
        $title,
        $menu,
        'manage_options',
        'yoast-bulk-meta-editor-pending',
        'ybme_pending_page'
    );
}

function ybme_pending_page() {
    if (!current_user_can('manage_options')) {
        wp_die();
    }
    global $wpdb;
    $table = ybme_pending_table();

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Pending Changes', YBME_TEXT_DOMAIN) . '</h1>';

    $status = isset($_GET['ybme_p_status']) ? sanitize_key($_GET['ybme_p_status']) : '';
    if ($status !== '') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Done.', YBME_TEXT_DOMAIN) . '</p></div>';
    }

    // Active drafts: awaiting review or scheduled.
    $active = $wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('pending','scheduled') ORDER BY id DESC LIMIT 200");
    echo '<h2>' . esc_html__('Awaiting review / scheduled', YBME_TEXT_DOMAIN) . '</h2>';
    ybme_pending_render_table($active, true);

    // Recent resolved drafts.
    $recent = $wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('applied','rejected') ORDER BY id DESC LIMIT 50");
    echo '<h2 style="margin-top:30px;">' . esc_html__('Recently resolved', YBME_TEXT_DOMAIN) . '</h2>';
    ybme_pending_render_table($recent, false);

    echo '</div>';
}

function ybme_pending_render_table($rows, $actionable) {
    if (empty($rows)) {
        echo '<p>' . esc_html__('Nothing here.', YBME_TEXT_DOMAIN) . '</p>';
        return;
    }
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
    echo '<th>' . esc_html__('Post', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Field', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Old', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('New', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Submitted by', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Status', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Scheduled for', YBME_TEXT_DOMAIN) . '</th>';
    if ($actionable) {
        echo '<th>' . esc_html__('Actions', YBME_TEXT_DOMAIN) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $edit = get_edit_post_link($r->post_id);
        $user = get_userdata($r->submitted_by);
        $uname = $user ? $user->display_name : ('#' . intval($r->submitted_by));
        echo '<tr>';
        echo '<td>' . ($edit ? '<a href="' . esc_url($edit) . '">' . esc_html(get_the_title($r->post_id)) . '</a>' : esc_html(get_the_title($r->post_id))) . '</td>';
        echo '<td>' . esc_html(ybme_meta_key_label($r->meta_key)) . '</td>';
        echo '<td>' . esc_html($r->old_value) . '</td>';
        echo '<td>' . esc_html($r->new_value) . '</td>';
        echo '<td>' . esc_html($uname) . '</td>';
        echo '<td>' . esc_html($r->status) . '</td>';
        echo '<td>' . esc_html($r->scheduled_for ? $r->scheduled_for : '—') . '</td>';
        if ($actionable) {
            echo '<td>';
            if ($r->status === 'pending') {
                echo '<a class="button button-primary" href="' . esc_url(ybme_pending_action_url('approve', $r->id)) . '">' . esc_html__('Approve', YBME_TEXT_DOMAIN) . '</a> ';
                echo '<a class="button" href="' . esc_url(ybme_pending_action_url('reject', $r->id)) . '">' . esc_html__('Reject', YBME_TEXT_DOMAIN) . '</a>';
            } elseif ($r->status === 'scheduled') {
                echo '<a class="button" href="' . esc_url(ybme_pending_action_url('apply', $r->id)) . '">' . esc_html__('Apply now', YBME_TEXT_DOMAIN) . '</a> ';
                echo '<a class="button" href="' . esc_url(ybme_pending_action_url('cancel', $r->id)) . '">' . esc_html__('Cancel', YBME_TEXT_DOMAIN) . '</a>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/* -------------------------------------------------------------------------
 * Scheduled apply cron
 * ---------------------------------------------------------------------- */

add_action('init', 'ybme_pending_schedule_cron');
function ybme_pending_schedule_cron() {
    if (!wp_next_scheduled(YBME_PENDING_CRON_HOOK)) {
        wp_schedule_event(time() + 300, 'hourly', YBME_PENDING_CRON_HOOK);
    }
}

add_action(YBME_PENDING_CRON_HOOK, 'ybme_pending_run_scheduled');
function ybme_pending_run_scheduled() {
    global $wpdb;
    $table = ybme_pending_table();
    $now   = current_time('mysql');
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE status = 'scheduled' AND scheduled_for IS NOT NULL AND scheduled_for <= %s",
        $now
    ));
    foreach ($rows as $row) {
        $applied = ybme_pending_apply_row($row);
        $wpdb->update($table, array('status' => $applied ? 'applied' : 'rejected', 'updated_at' => $now), array('id' => $row->id));
    }
}

register_deactivation_hook(YBME_PATH . 'seo-bulk-meta-editor.php', 'ybme_pending_clear_cron');
function ybme_pending_clear_cron() {
    wp_clear_scheduled_hook(YBME_PENDING_CRON_HOOK);
}
