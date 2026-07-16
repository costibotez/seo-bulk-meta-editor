<?php
/**
 * Uninstall cleanup for Yoast SEO Bulk Meta Editor.
 *
 * Runs only when the user deletes the plugin from the Plugins screen. Removes
 * the plugin's own options, custom capability and history table. Post meta
 * belongs to Yoast, not to us, so it is intentionally left untouched.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Options this plugin creates.
$ybme_options = array(
    'post_types',
    'ybme_provider',
    'ybme_enabled_columns',
    'ybme_license_key',
    'ybme_roles',
    'ybme_rows_per_page',
    'ybme_languages',
    'ybme_db_version',
    'ybme_gsc_client_id',
    'ybme_gsc_client_secret',
    'ybme_gsc_tokens',
    'ybme_gsc_site',
    'ybme_ai_provider',
    'ybme_ai_key',
    'ybme_ai_model',
);
foreach ($ybme_options as $ybme_option) {
    delete_option($ybme_option);
}

// Remove cached transients.
delete_transient('ybme_audit_cache');
delete_transient('ybme_gsc_data');

// Clear scheduled crons.
wp_clear_scheduled_hook('ybme_gsc_refresh_cron');
wp_clear_scheduled_hook('ybme_pending_cron');

// Remove the custom capability from every role.
if (function_exists('wp_roles')) {
    foreach (wp_roles()->roles as $ybme_role => $ybme_info) {
        $ybme_obj = get_role($ybme_role);
        if ($ybme_obj) {
            $ybme_obj->remove_cap('manage_ybme_meta');
        }
    }
}

// Drop the plugin's custom tables.
global $wpdb;
foreach (array('ybme_history', 'ybme_pending') as $ybme_suffix) {
    $ybme_table = $wpdb->prefix . $ybme_suffix;
    $wpdb->query("DROP TABLE IF EXISTS {$ybme_table}");
}
