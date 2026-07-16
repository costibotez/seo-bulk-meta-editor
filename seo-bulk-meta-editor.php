<?php
/**
 * Plugin Name: Yoast SEO Bulk Meta Editor
 * Description: Display & edit all meta titles, descriptions, and keywords from all posts, pages, and custom post types into one dashboard. Works with Yoast SEO, Rank Math and SEOPress. Includes a live SERP preview, per-row SEO health scoring, a site-wide audit, Google Search Console metrics, AI meta generation, an approval workflow, bulk find & replace and a full change-history/audit log.
 * Version: 1.12.0
 * Plugin URI: https://nomad-developer.co.uk
 * Author: Nomad Developer
 * Author URI:  https://nomad-developer.co.uk
 * Text Domain: seo-bulk-meta-editor
 * Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

// Default number of rows shown in the table when no setting is saved.
define('YBME_POSTS_PER_PAGE', 20);

define('YBME_TEXT_DOMAIN', 'seo-bulk-meta-editor');

// Plugin + database schema version.
define('YBME_VERSION', '1.12.0');

define('YBME_CAPABILITY', 'manage_ybme_meta');

// Shared nonce action used to protect every AJAX write.
define('YBME_NONCE_ACTION', 'ybme_meta_action');

define('YBME_PATH', plugin_dir_path(__FILE__));

// Optional feature modules.
require_once YBME_PATH . 'includes/gsc.php';
require_once YBME_PATH . 'includes/pending.php';
require_once YBME_PATH . 'includes/ai.php';

function ybme_load_textdomain() {
    load_plugin_textdomain(YBME_TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'ybme_load_textdomain');

// Check if WPML is active
function ybme_wpml_active() {
    return defined('ICL_SITEPRESS_VERSION');
}

function ybme_get_wpml_languages() {
    if (!ybme_wpml_active()) {
        return array();
    }
    $langs = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
    return is_array($langs) ? $langs : array();
}

/* -------------------------------------------------------------------------
 * SEO plugin providers
 *
 * The editor, audit and find & replace all read and write post meta. Each
 * supported SEO plugin stores the same logical fields under different meta
 * keys, so the whole plugin operates on a per-provider key map instead of
 * hard-coded Yoast keys.
 *
 * Only post-meta based plugins fit this model. All in One SEO (AIOSEO v4)
 * stores its data in a custom table, not post meta, so it is intentionally
 * not offered here.
 * ---------------------------------------------------------------------- */

/**
 * Registry of supported SEO plugins. Each entry maps the plugin's own meta
 * keys onto the five logical fields the editor understands.
 */
function ybme_providers() {
    return array(
        'yoast' => array(
            'label'  => 'Yoast SEO',
            'fields' => array(
                'meta_title'       => '_yoast_wpseo_title',
                'meta_description' => '_yoast_wpseo_metadesc',
                'keyword'          => '_yoast_wpseo_focuskw',
                'canonical_url'    => '_yoast_wpseo_canonical',
                'social_title'     => '_yoast_wpseo_opengraph-title',
            ),
        ),
        'rankmath' => array(
            'label'  => 'Rank Math',
            'fields' => array(
                'meta_title'       => 'rank_math_title',
                'meta_description' => 'rank_math_description',
                'keyword'          => 'rank_math_focus_keyword',
                'canonical_url'    => 'rank_math_canonical_url',
                'social_title'     => 'rank_math_facebook_title',
            ),
        ),
        'seopress' => array(
            'label'  => 'SEOPress',
            'fields' => array(
                'meta_title'       => '_seopress_titles_title',
                'meta_description' => '_seopress_titles_desc',
                'keyword'          => '_seopress_analysis_target_kw',
                'canonical_url'    => '_seopress_robots_canonical',
                'social_title'     => '_seopress_social_fb_title',
            ),
        ),
    );
}

// Priority order used when more than one supported plugin is active.
function ybme_provider_priority() {
    return array('yoast', 'rankmath', 'seopress');
}

/**
 * Is a given provider's plugin loaded right now?
 */
function ybme_provider_is_available($id) {
    switch ($id) {
        case 'yoast':
            return defined('WPSEO_VERSION') || class_exists('WPSEO_Options');
        case 'rankmath':
            return defined('RANK_MATH_VERSION') || class_exists('RankMath');
        case 'seopress':
            return defined('SEOPRESS_VERSION') || function_exists('seopress_init');
    }
    return false;
}

/**
 * The id of the active provider: an explicit, still-available choice from
 * settings, otherwise the first available plugin in priority order. Empty
 * string when no supported SEO plugin is active.
 */
function ybme_active_provider_id() {
    $providers = ybme_providers();

    $saved = get_option('ybme_provider', '');
    if ($saved && isset($providers[$saved]) && ybme_provider_is_available($saved)) {
        return $saved;
    }
    foreach (ybme_provider_priority() as $id) {
        if (ybme_provider_is_available($id)) {
            return $id;
        }
    }
    return '';
}

function ybme_provider_active() {
    return ybme_active_provider_id() !== '';
}

/**
 * The logical-field => meta-key map for the active provider. Falls back to
 * Yoast's keys so nothing fatals when no provider is active.
 */
function ybme_provider_field_keys() {
    $id        = ybme_active_provider_id();
    $providers = ybme_providers();
    if ($id && isset($providers[$id])) {
        return $providers[$id]['fields'];
    }
    return $providers['yoast']['fields'];
}

/**
 * Reverse lookup: which logical field does a meta key belong to? Checks every
 * provider so history rows written under a previously-active plugin still
 * resolve. Empty string when unknown.
 */
function ybme_field_for_key($meta_key) {
    foreach (ybme_providers() as $provider) {
        $field = array_search($meta_key, $provider['fields'], true);
        if ($field !== false) {
            return $field;
        }
    }
    return '';
}

function ybme_get_available_columns() {
    $keys = ybme_provider_field_keys();
    $cols = array(
        'seo_score'        => array('label' => __('SEO', YBME_TEXT_DOMAIN)),
        'title'            => array('label' => __('Title', YBME_TEXT_DOMAIN)),
        'post_type'        => array('label' => __('Post Type', YBME_TEXT_DOMAIN)),
        'meta_title'       => array('label' => __('Meta Title', YBME_TEXT_DOMAIN), 'meta_key' => $keys['meta_title']),
        'meta_description' => array('label' => __('Meta Description', YBME_TEXT_DOMAIN), 'meta_key' => $keys['meta_description']),
        'keyword'          => array('label' => __('Keyword', YBME_TEXT_DOMAIN), 'meta_key' => $keys['keyword']),
        'canonical_url'    => array('label' => __('Canonical URL', YBME_TEXT_DOMAIN), 'meta_key' => $keys['canonical_url']),
        'social_title'     => array('label' => __('Social Title', YBME_TEXT_DOMAIN), 'meta_key' => $keys['social_title']),
    );
    if (ybme_wpml_active()) {
        $cols = array_merge(array('language_flag' => array('label' => __('Language', YBME_TEXT_DOMAIN))), $cols);
    }
    /**
     * Filter the available editor columns. Feature modules (e.g. Search
     * Console) add read-only columns here. Columns without a 'meta_key' are
     * never writable and are ignored by the save allow-list.
     */
    return apply_filters('ybme_available_columns', $cols);
}

function ybme_get_enabled_columns() {
    $defaults = array('seo_score','title','post_type','meta_title','meta_description','keyword');
    if (ybme_wpml_active()) {
        array_unshift($defaults, 'language_flag');
    }
    $saved = get_option('ybme_enabled_columns');
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_unique(array_merge($defaults, $saved));
}

/**
 * The complete set of meta keys this plugin is allowed to write for the active
 * SEO provider. Any AJAX handler that saves meta MUST validate against this
 * list so a crafted request can never target arbitrary post meta (e.g.
 * _wp_page_template).
 */
function ybme_allowed_meta_keys() {
    $keys = array();
    foreach (ybme_get_available_columns() as $col) {
        if (!empty($col['meta_key'])) {
            $keys[] = $col['meta_key'];
        }
    }
    return $keys;
}

/**
 * Sanitize a meta value according to the logical field it belongs to, so the
 * right rule applies regardless of which provider's key it is.
 */
function ybme_sanitize_meta_value($meta_key, $value) {
    $field = ybme_field_for_key($meta_key);
    if ($field === 'canonical_url') {
        return esc_url_raw($value);
    }
    if ($field === 'meta_description') {
        return sanitize_textarea_field($value);
    }
    return sanitize_text_field($value);
}

/**
 * Human readable label for a meta key (used in the history log).
 */
function ybme_meta_key_label($meta_key) {
    foreach (ybme_get_available_columns() as $col) {
        if (isset($col['meta_key']) && $col['meta_key'] === $meta_key) {
            return $col['label'];
        }
    }
    return $meta_key;
}

// Rows to show per batch, clamped even if an oversized value was stored before
// the save-time sanitizer existed.
function ybme_get_rows_per_page() {
    return ybme_sanitize_rows_per_page(get_option('ybme_rows_per_page', YBME_POSTS_PER_PAGE));
}

function ybme_is_pro() {
    $key = trim(get_option('ybme_license_key'));
    return !empty($key);
}

/* -------------------------------------------------------------------------
 * Site-wide SEO audit
 *
 * Scans every published post of the selected post types and aggregates the
 * same signals the per-row score dot uses (missing / too long / too short
 * title & description, missing keyword) plus cross-post duplicate detection.
 * The result is cached in a transient so the page is cheap to reopen.
 * ---------------------------------------------------------------------- */

define('YBME_AUDIT_TRANSIENT', 'ybme_audit_cache');

// Post types the audit scans (same selection the editor uses).
function ybme_audit_post_types() {
    $types = get_option('post_types', array('post', 'page'));
    if (!is_array($types) || empty($types)) {
        $types = array('post', 'page');
    }
    return $types;
}

/**
 * Walk all published posts and build the audit statistics array.
 *
 * Length thresholds and the good/warn/bad classification intentionally mirror
 * scoreFields() in js/bulk-meta-editor.js so the dashboard and the editor's
 * score dots always agree.
 */
function ybme_run_audit() {
    $ids = get_posts(array(
        'post_type'        => ybme_audit_post_types(),
        'post_status'      => 'publish',
        'numberposts'      => -1,
        'fields'           => 'ids',
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => true,
    ));

    $stats = array(
        'generated'       => current_time('mysql'),
        'total'           => count($ids),
        'missing_title'   => 0,
        'missing_desc'    => 0,
        'missing_keyword' => 0,
        'title_long'      => 0,
        'title_short'     => 0,
        'desc_long'       => 0,
        'desc_short'      => 0,
        'good'            => 0,
        'warn'            => 0,
        'bad'             => 0,
        'by_type'         => array(),
        'dupe_titles'     => array(),
        'dupe_descs'      => array(),
    );

    // Maps a normalized value to the post IDs that share it, plus the first
    // original spelling seen (for display).
    $title_ids = array();
    $desc_ids  = array();
    $title_txt = array();
    $desc_txt  = array();

    // Read the active provider's keys once rather than per post.
    $fk        = ybme_provider_field_keys();
    $key_title = $fk['meta_title'];
    $key_desc  = $fk['meta_description'];
    $key_kw    = $fk['keyword'];

    foreach (array_chunk($ids, 500) as $chunk) {
        // Prime post + meta caches for the whole chunk in two queries rather
        // than one per get_post_type()/get_post_meta() call.
        _prime_post_caches($chunk, false, true);

        foreach ($chunk as $pid) {
            $type = get_post_type($pid);
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = array(
                    'total'           => 0,
                    'missing_title'   => 0,
                    'missing_desc'    => 0,
                    'missing_keyword' => 0,
                );
            }
            $stats['by_type'][$type]['total']++;

            $title = trim((string) get_post_meta($pid, $key_title, true));
            $desc  = trim((string) get_post_meta($pid, $key_desc, true));
            $kw    = trim((string) get_post_meta($pid, $key_kw, true));

            $critical  = false;
            $has_issue = false;

            if ($title === '') {
                $stats['missing_title']++;
                $stats['by_type'][$type]['missing_title']++;
                $critical = true;
            } else {
                $len = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
                if ($len > 60) { $stats['title_long']++; $has_issue = true; }
                elseif ($len < 30) { $stats['title_short']++; $has_issue = true; }
                $key = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
                if (!isset($title_ids[$key])) { $title_ids[$key] = array(); $title_txt[$key] = $title; }
                $title_ids[$key][] = $pid;
            }

            if ($desc === '') {
                $stats['missing_desc']++;
                $stats['by_type'][$type]['missing_desc']++;
                $critical = true;
            } else {
                $len = function_exists('mb_strlen') ? mb_strlen($desc) : strlen($desc);
                if ($len > 160) { $stats['desc_long']++; $has_issue = true; }
                elseif ($len < 120) { $stats['desc_short']++; $has_issue = true; }
                $key = function_exists('mb_strtolower') ? mb_strtolower($desc) : strtolower($desc);
                if (!isset($desc_ids[$key])) { $desc_ids[$key] = array(); $desc_txt[$key] = $desc; }
                $desc_ids[$key][] = $pid;
            }

            if ($kw === '') {
                $stats['missing_keyword']++;
                $stats['by_type'][$type]['missing_keyword']++;
                $has_issue = true;
            }

            if ($critical) {
                $stats['bad']++;
            } elseif ($has_issue) {
                $stats['warn']++;
            } else {
                $stats['good']++;
            }
        }
    }

    // Reduce the value maps to duplicate groups (2+ posts sharing a value).
    $stats['dupe_titles'] = ybme_audit_dupes($title_ids, $title_txt);
    $stats['dupe_descs']  = ybme_audit_dupes($desc_ids, $desc_txt);

    return $stats;
}

/**
 * Turn a value => [post_ids] map into a capped, sorted list of duplicate groups.
 */
function ybme_audit_dupes($id_map, $txt_map) {
    $groups = array();
    foreach ($id_map as $key => $ids) {
        if (count($ids) > 1) {
            $groups[] = array(
                'value' => $txt_map[$key],
                'count' => count($ids),
                'ids'   => array_slice($ids, 0, 25),
            );
        }
    }
    usort($groups, function ($a, $b) {
        return $b['count'] - $a['count'];
    });
    return array_slice($groups, 0, 50);
}

/**
 * Return the cached audit, computing (and caching) it when missing or forced.
 */
function ybme_get_audit_data($force = false) {
    if (!$force) {
        $cached = get_transient(YBME_AUDIT_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $data = ybme_run_audit();
    set_transient(YBME_AUDIT_TRANSIENT, $data, 10 * MINUTE_IN_SECONDS);
    return $data;
}

// Drop the cache whenever a meta value changes so the audit never goes stale.
function ybme_clear_audit_cache() {
    delete_transient(YBME_AUDIT_TRANSIENT);
}

/**
 * Render the audit dashboard.
 */
function ybme_audit_page() {
    if (!current_user_can(YBME_CAPABILITY)) { wp_die(); }

    $force = false;
    if (isset($_GET['ybme_refresh'])) {
        check_admin_referer('ybme_audit_refresh');
        $force = true;
    }

    $d          = ybme_get_audit_data($force);
    $editor_url = admin_url('admin.php?page=yoast-bulk-meta-editor');
    $refresh    = wp_nonce_url(admin_url('admin.php?page=yoast-bulk-meta-editor-audit&ybme_refresh=1'), 'ybme_audit_refresh');

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('SEO Audit', YBME_TEXT_DOMAIN) . '</h1>';

    if (!ybme_provider_active()) {
        echo '<div class="notice notice-error inline"><p>' . esc_html__('No supported SEO plugin is active. Audit results may be incomplete.', YBME_TEXT_DOMAIN) . '</p></div>';
    }

    echo '<p class="description">' . sprintf(
        /* translators: 1: number of posts, 2: date/time the audit was generated */
        esc_html__('Scanned %1$d published items. Generated %2$s.', YBME_TEXT_DOMAIN),
        intval($d['total']),
        esc_html($d['generated'])
    ) . ' <a href="' . esc_url($refresh) . '" class="button button-small">' . esc_html__('Refresh', YBME_TEXT_DOMAIN) . '</a></p>';

    // Score distribution cards.
    $problems = intval($d['warn']) + intval($d['bad']);
    echo '<div class="ybme-audit-cards">';
    echo '<div class="ybme-audit-card"><span class="ybme-audit-num">' . intval($d['total']) . '</span><span class="ybme-audit-cap">' . esc_html__('Total', YBME_TEXT_DOMAIN) . '</span></div>';
    echo '<a class="ybme-audit-card is-good" href="' . esc_url($editor_url . '&ybme_seo=good') . '"><span class="ybme-audit-num">' . intval($d['good']) . '</span><span class="ybme-audit-cap">' . esc_html__('Good', YBME_TEXT_DOMAIN) . '</span></a>';
    echo '<a class="ybme-audit-card is-warn" href="' . esc_url($editor_url . '&ybme_seo=problems') . '"><span class="ybme-audit-num">' . intval($d['warn']) . '</span><span class="ybme-audit-cap">' . esc_html__('Needs work', YBME_TEXT_DOMAIN) . '</span></a>';
    echo '<a class="ybme-audit-card is-bad" href="' . esc_url($editor_url . '&ybme_seo=bad') . '"><span class="ybme-audit-num">' . intval($d['bad']) . '</span><span class="ybme-audit-cap">' . esc_html__('Critical', YBME_TEXT_DOMAIN) . '</span></a>';
    echo '</div>';

    // Issue breakdown.
    echo '<h2>' . esc_html__('Issues', YBME_TEXT_DOMAIN) . '</h2>';
    echo '<table class="wp-list-table widefat fixed striped"><tbody>';
    $issue_rows = array(
        __('Missing meta title', YBME_TEXT_DOMAIN)           => $d['missing_title'],
        __('Missing meta description', YBME_TEXT_DOMAIN)     => $d['missing_desc'],
        __('Missing focus keyword', YBME_TEXT_DOMAIN)        => $d['missing_keyword'],
        __('Title too long (over 60)', YBME_TEXT_DOMAIN)     => $d['title_long'],
        __('Title too short (under 30)', YBME_TEXT_DOMAIN)   => $d['title_short'],
        __('Description too long (over 160)', YBME_TEXT_DOMAIN)   => $d['desc_long'],
        __('Description too short (under 120)', YBME_TEXT_DOMAIN) => $d['desc_short'],
    );
    foreach ($issue_rows as $label => $count) {
        echo '<tr><td>' . esc_html($label) . '</td><td style="width:80px;text-align:right;"><strong>' . intval($count) . '</strong></td></tr>';
    }
    echo '</tbody></table>';

    // Breakdown by post type.
    if (!empty($d['by_type'])) {
        echo '<h2>' . esc_html__('By post type', YBME_TEXT_DOMAIN) . '</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Post type', YBME_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Total', YBME_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('No title', YBME_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('No description', YBME_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('No keyword', YBME_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($d['by_type'] as $type => $t) {
            $obj  = get_post_type_object($type);
            $name = ($obj && isset($obj->labels->name)) ? $obj->labels->name : $type;
            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . intval($t['total']) . '</td>';
            echo '<td>' . intval($t['missing_title']) . '</td>';
            echo '<td>' . intval($t['missing_desc']) . '</td>';
            echo '<td>' . intval($t['missing_keyword']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    ybme_audit_dupe_table(__('Duplicate meta titles', YBME_TEXT_DOMAIN), $d['dupe_titles']);
    ybme_audit_dupe_table(__('Duplicate meta descriptions', YBME_TEXT_DOMAIN), $d['dupe_descs']);

    echo '</div>';
}

/**
 * Render one duplicate-group table (titles or descriptions).
 */
function ybme_audit_dupe_table($heading, $groups) {
    echo '<h2>' . esc_html($heading) . '</h2>';
    if (empty($groups)) {
        echo '<p>' . esc_html__('No duplicates found.', YBME_TEXT_DOMAIN) . '</p>';
        return;
    }
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
    echo '<th>' . esc_html__('Value', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th style="width:60px;">' . esc_html__('Count', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Posts', YBME_TEXT_DOMAIN) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($groups as $g) {
        echo '<tr>';
        echo '<td>' . esc_html($g['value']) . '</td>';
        echo '<td><strong>' . intval($g['count']) . '</strong></td>';
        echo '<td>';
        $links = array();
        foreach ($g['ids'] as $pid) {
            $edit = get_edit_post_link($pid);
            $t    = get_the_title($pid);
            $links[] = $edit
                ? '<a href="' . esc_url($edit) . '">' . esc_html($t) . '</a>'
                : esc_html($t);
        }
        echo implode(', ', $links); // Each link is individually escaped above.
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

/* -------------------------------------------------------------------------
 * Change history / audit log
 * ---------------------------------------------------------------------- */

function ybme_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'ybme_history';
}

function ybme_install_history_table() {
    global $wpdb;
    $table   = ybme_history_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        meta_key varchar(191) NOT NULL,
        old_value longtext NULL,
        new_value longtext NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY post_id (post_id),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Let feature modules create their own tables during the same upgrade.
    do_action('ybme_install_tables');

    update_option('ybme_db_version', YBME_VERSION);
}

// Make sure the table exists / is up to date even after a manual plugin update.
add_action('plugins_loaded', function () {
    if (get_option('ybme_db_version') !== YBME_VERSION) {
        ybme_install_history_table();
    }
});

/**
 * Record a single meta change in the audit log.
 */
function ybme_log_change($post_id, $meta_key, $old_value, $new_value) {
    if ((string) $old_value === (string) $new_value) {
        return; // Nothing actually changed.
    }
    // A meta value changed, so any cached audit is now stale.
    ybme_clear_audit_cache();
    global $wpdb;
    $wpdb->insert(
        ybme_history_table(),
        array(
            'post_id'    => $post_id,
            'meta_key'   => $meta_key,
            'old_value'  => $old_value,
            'new_value'  => $new_value,
            'user_id'    => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%s', '%s', '%s', '%d', '%s')
    );
}

/* -------------------------------------------------------------------------
 * Activation / capabilities
 * ---------------------------------------------------------------------- */

register_activation_hook(__FILE__, 'ybme_activate');
register_deactivation_hook(__FILE__, 'ybme_deactivate');

/**
 * Back-compat shim: true when Yoast specifically is available. Prefer
 * ybme_provider_active() for "can we read/write meta at all".
 */
function ybme_yoast_active() {
    return ybme_provider_is_available('yoast');
}

/**
 * Warn (but don't fatal) when no supported SEO plugin is active. Without one,
 * every meta key this plugin writes would be orphaned.
 */
add_action('admin_notices', 'ybme_provider_missing_notice');
function ybme_provider_missing_notice() {
    if (ybme_provider_active() || !current_user_can(YBME_CAPABILITY)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'yoast-bulk-meta-editor') === false) {
        return;
    }
    echo '<div class="notice notice-error"><p>' . esc_html__('Bulk Meta Editor needs a supported SEO plugin (Yoast SEO, Rank Math or SEOPress) to be active. Editing is disabled until one is enabled.', YBME_TEXT_DOMAIN) . '</p></div>';
}

function check_for_yoast_seo()
{
    if (!ybme_provider_active() && current_user_can('activate_plugins')) {
        // Stop activation redirect and show error.
        wp_die(sprintf(
            /* translators: %s: plugins admin url */
            __('Sorry, but this plugin requires a supported SEO plugin (Yoast SEO, Rank Math or SEOPress) to be installed and active. <br><a href="%s">&laquo; Return to Plugins</a>', YBME_TEXT_DOMAIN),
            admin_url('plugins.php')
        ));
    }
}
function ybme_apply_role_capabilities($roles) {
    global $wp_roles;
    if (!is_array($roles)) {
        $roles = array("administrator");
    }
    foreach ($wp_roles->roles as $role => $info) {
        $obj = get_role($role);
        if (!$obj) {
            continue;
        }
        if (in_array($role, $roles, true)) {
            $obj->add_cap(YBME_CAPABILITY);
        } else {
            $obj->remove_cap(YBME_CAPABILITY);
        }
    }
}

function ybme_activate() {
    check_for_yoast_seo();
    $roles = get_option("ybme_roles", array("administrator"));
    ybme_apply_role_capabilities($roles);
    ybme_install_history_table();
}

function ybme_deactivate() {
    ybme_apply_role_capabilities(array());
}

add_action("update_option_ybme_roles", function($old, $new) { ybme_apply_role_capabilities($new); }, 10, 2);

// Switching provider changes which meta keys the audit reads, so drop its cache.
add_action("update_option_ybme_provider", 'ybme_clear_audit_cache');

// Hook into the admin menu and settings registration at the top level. These
// were previously coupled (settings were registered inside the admin_menu
// callback), which only worked because admin_menu happens to fire before
// admin_init. Register each on its own hook.
add_action('admin_menu', 'yoast_bulk_meta_editor_create_menu');
add_action('admin_init', 'register_yoast_bulk_meta_editor_settings');

// Create new top-level menu
function yoast_bulk_meta_editor_create_menu() {
    // Create new top-level menu
    add_menu_page(__('Yoast Bulk Meta Editor', YBME_TEXT_DOMAIN), __('Yoast Bulk Meta Editor', YBME_TEXT_DOMAIN), YBME_CAPABILITY, 'yoast-bulk-meta-editor', 'yoast_bulk_meta_editor_page' );

    // Site-wide SEO audit dashboard.
    add_submenu_page('yoast-bulk-meta-editor', __('SEO Audit', YBME_TEXT_DOMAIN), __('Audit', YBME_TEXT_DOMAIN), YBME_CAPABILITY, 'yoast-bulk-meta-editor-audit', 'ybme_audit_page');

    // History / audit log
    add_submenu_page('yoast-bulk-meta-editor', __('Change History', YBME_TEXT_DOMAIN), __('History', YBME_TEXT_DOMAIN), YBME_CAPABILITY, 'yoast-bulk-meta-editor-history', 'ybme_history_page');

    // Create submenu for settings
    add_submenu_page('yoast-bulk-meta-editor', __('Yoast Bulk Meta Editor Settings', YBME_TEXT_DOMAIN), __('Settings', YBME_TEXT_DOMAIN), 'manage_options', 'yoast-bulk-meta-editor-settings', 'yoast_bulk_meta_editor_settings_page');
}

/**
 * Render a single table row for a post. Shared by the initial page render and
 * the "Load More" AJAX handler so the markup can never drift between the two.
 *
 * Returns an empty string when the post is filtered out by the WPML language
 * selection.
 */
function ybme_render_meta_row($post, $enabled_columns, $lang_flags = array(), $selected_langs = array()) {
    $post_id    = $post->ID;
    $page_title = get_the_title($post_id);

    $post_lang = '';
    if (ybme_wpml_active()) {
        $info = apply_filters('wpml_post_language_details', null, $post_id);
        if (isset($info['language_code'])) {
            $post_lang = $info['language_code'];
        }
        if (!empty($selected_langs) && $post_lang !== '' && !in_array($post_lang, $selected_langs, true)) {
            return '';
        }
    }

    $edit_link  = get_edit_post_link($post_id);
    $permalink  = get_permalink($post_id);
    $post_type  = get_post_type($post_id);
    $fk         = ybme_provider_field_keys();
    $meta_title = get_post_meta($post_id, $fk['meta_title'], true);
    $meta_desc  = get_post_meta($post_id, $fk['meta_description'], true);
    $keyword    = get_post_meta($post_id, $fk['keyword'], true);
    $canonical  = get_post_meta($post_id, $fk['canonical_url'], true);
    $social     = get_post_meta($post_id, $fk['social_title'], true);

    $cat_slugs = wp_get_post_terms($post_id, 'category', array('fields' => 'slugs'));
    if (is_wp_error($cat_slugs)) {
        $cat_slugs = array();
    }

    // Row-level data attributes. The meta values are stored here (in addition
    // to any visible cell) so the SEO score and SERP preview keep working even
    // when a column is hidden.
    $row  = '<tr data-post-id="' . esc_attr($post_id) . '"';
    $row .= ' data-title="' . esc_attr(strtolower($page_title)) . '"';
    $row .= ' data-name="' . esc_attr($page_title) . '"';
    $row .= ' data-permalink="' . esc_attr($permalink) . '"';
    $row .= ' data-categories="' . esc_attr(implode(',', $cat_slugs)) . '"';
    $row .= ' data-post-type="' . esc_attr($post_type) . '"';
    $row .= ' data-meta-title="' . esc_attr($meta_title) . '"';
    $row .= ' data-meta-desc="' . esc_attr($meta_desc) . '"';
    $row .= ' data-keyword="' . esc_attr($keyword) . '"';
    if ($post_lang) {
        $row .= ' data-language="' . esc_attr($post_lang) . '"';
    }
    $row .= '>';

    // Only render columns that currently exist, so the body can never drift
    // from the header (e.g. a GSC column left enabled after disconnecting).
    $available = ybme_get_available_columns();

    foreach ($enabled_columns as $col) {
        if (!isset($available[$col])) {
            continue;
        }
        switch ($col) {
            case 'seo_score':
                $row .= '<td class="ybme-seo-score"><span class="ybme-score-dot" aria-hidden="true"></span></td>';
                break;
            case 'language_flag':
                $flag = isset($lang_flags[$post_lang]) ? $lang_flags[$post_lang] : '';
                $row .= '<td>' . ($flag ? '<img src="' . esc_url($flag) . '" alt="" style="width:18px;" />' : esc_html($post_lang)) . '</td>';
                break;
            case 'title':
                $row .= '<td><a href="' . esc_url($edit_link) . '">' . esc_html($page_title) . '</a></td>';
                break;
            case 'post_type':
                $row .= '<td>' . esc_html(ucfirst($post_type)) . '</td>';
                break;
            case 'meta_title':
                $row .= '<td class="editable" data-meta-key="' . esc_attr($fk['meta_title']) . '">' . esc_html($meta_title) . '</td>';
                break;
            case 'meta_description':
                $row .= '<td class="editable" data-meta-key="' . esc_attr($fk['meta_description']) . '">' . esc_html($meta_desc) . '</td>';
                break;
            case 'keyword':
                $row .= '<td class="editable" data-meta-key="' . esc_attr($fk['keyword']) . '">' . esc_html($keyword) . '</td>';
                break;
            case 'canonical_url':
                $row .= '<td class="editable" data-meta-key="' . esc_attr($fk['canonical_url']) . '">' . esc_html($canonical) . '</td>';
                break;
            case 'social_title':
                $row .= '<td class="editable" data-meta-key="' . esc_attr($fk['social_title']) . '">' . esc_html($social) . '</td>';
                break;
            default:
                /**
                 * Render a cell for a column this core switch doesn't know
                 * about. Handlers must return a complete, escaped <td>. Used by
                 * feature modules such as Search Console.
                 */
                $row .= apply_filters('ybme_render_column_cell', '<td></td>', $col, $post_id, $permalink);
                break;
        }
    }
    $row .= '</tr>';
    return $row;
}

// Page content
function yoast_bulk_meta_editor_page()
{
    if (!current_user_can(YBME_CAPABILITY)) { wp_die(); }
    $posts_per_page = ybme_get_rows_per_page();
    $enabled_columns = ybme_get_enabled_columns();
    $selected_langs = array();
    $lang_flags = array();
    if (ybme_wpml_active()) {
        $selected_langs = get_option('ybme_languages', array());
        $langs = ybme_get_wpml_languages();
        foreach ($langs as $code => $lang) {
            $lang_flags[$code] = $lang['country_flag_url'];
        }
    }
    // Get public post types and filter based on settings
    $all_public_types = get_post_types(array('public' => true), 'names');
    $selected_types = get_option('post_types', ['post', 'page']);
    $post_types = array();
    foreach ($selected_types as $type) {
        if (in_array($type, $all_public_types, true)) {
            $post_types[] = $type;
        }
    }
    // Only include categories that contain posts
    $categories = get_categories(array('hide_empty' => true));

    echo '<h1 style="text-align: center;padding: 30px 0">' . esc_html__('Yoast Bulk Meta Editor', YBME_TEXT_DOMAIN) . '</h1>';

    echo '<div class="filter-controls">';
    echo '<input type="text" id="search-box" placeholder="' . esc_attr__('Search title...', YBME_TEXT_DOMAIN) . '" />';
    echo '<select id="post-type-filter"><option value="">' . esc_html__('All Post Types', YBME_TEXT_DOMAIN) . '</option>';
    foreach ($post_types as $type) {
        echo '<option value="' . esc_attr($type) . '">' . esc_html(ucfirst($type)) . '</option>';
    }
    echo '</select>';
    echo '<select id="category-filter" disabled="disabled"><option value="">' . esc_html__('All Categories', YBME_TEXT_DOMAIN) . '</option>';
    foreach ($categories as $cat) {
        echo '<option value="' . esc_attr($cat->slug) . '">' . esc_html($cat->name) . '</option>';
    }
    echo '</select>';
    echo '<select id="seo-filter">';
    echo '<option value="">' . esc_html__('All SEO scores', YBME_TEXT_DOMAIN) . '</option>';
    echo '<option value="problems">' . esc_html__('Only problems', YBME_TEXT_DOMAIN) . '</option>';
    echo '<option value="bad">' . esc_html__('Critical only', YBME_TEXT_DOMAIN) . '</option>';
    echo '<option value="good">' . esc_html__('Good only', YBME_TEXT_DOMAIN) . '</option>';
    echo '</select>';
    echo '</div>';

    // Bulk find & replace panel.
    echo '<div class="ybme-fr-panel">';
    echo '<button type="button" id="ybme-fr-toggle" class="button">' . esc_html__('Bulk Find & Replace', YBME_TEXT_DOMAIN) . '</button>';
    echo '<div id="ybme-fr-body" style="display:none;">';
    echo '<div class="ybme-fr-row">';
    echo '<select id="ybme-fr-field">';
    echo '<option value="meta_title">' . esc_html__('Meta Title', YBME_TEXT_DOMAIN) . '</option>';
    echo '<option value="meta_description">' . esc_html__('Meta Description', YBME_TEXT_DOMAIN) . '</option>';
    echo '<option value="keyword">' . esc_html__('Keyword', YBME_TEXT_DOMAIN) . '</option>';
    echo '</select>';
    echo '<input type="text" id="ybme-fr-find" placeholder="' . esc_attr__('Find…', YBME_TEXT_DOMAIN) . '" />';
    echo '<input type="text" id="ybme-fr-replace" placeholder="' . esc_attr__('Replace with…', YBME_TEXT_DOMAIN) . '" />';
    echo '</div>';
    echo '<div class="ybme-fr-row">';
    echo '<label><input type="checkbox" id="ybme-fr-case" /> ' . esc_html__('Case sensitive', YBME_TEXT_DOMAIN) . '</label>';
    echo '<label><input type="checkbox" id="ybme-fr-regex" /> ' . esc_html__('Regular expression', YBME_TEXT_DOMAIN) . '</label>';
    echo '<button type="button" id="ybme-fr-preview" class="button">' . esc_html__('Preview matches', YBME_TEXT_DOMAIN) . '</button>';
    echo '<button type="button" id="ybme-fr-apply" class="button button-primary" disabled>' . esc_html__('Apply to all', YBME_TEXT_DOMAIN) . '</button>';
    echo '</div>';
    echo '<p class="description">' . esc_html__('Template variables in the replacement are expanded per post: %%title%%, %%sitename%%, %%sep%%.', YBME_TEXT_DOMAIN) . '</p>';
    echo '<div id="ybme-fr-results"></div>';
    echo '</div>';
    echo '</div>';

    // Live SERP preview panel.
    echo '<div id="ybme-serp-preview" class="ybme-serp" style="display:none;">';
    echo '<div class="ybme-serp-head">';
    echo '<span class="ybme-serp-label">' . esc_html__('Google preview', YBME_TEXT_DOMAIN) . '</span>';
    echo '<span class="ybme-serp-toggle">';
    echo '<button type="button" class="ybme-serp-device is-active" data-device="desktop">' . esc_html__('Desktop', YBME_TEXT_DOMAIN) . '</button>';
    echo '<button type="button" class="ybme-serp-device" data-device="mobile">' . esc_html__('Mobile', YBME_TEXT_DOMAIN) . '</button>';
    echo '</span>';
    echo '</div>';
    echo '<div class="ybme-serp-card">';
    echo '<div class="ybme-serp-url"></div>';
    echo '<div class="ybme-serp-title"></div>';
    echo '<div class="ybme-serp-desc"></div>';
    echo '</div>';
    echo '</div>';

    echo '<div id="notification" class="toast" style="display: none; text-align: center; padding: 10px;"></div>';
    echo '<table id="meta_info_table" class="wp-list-table widefat fixed striped posts">';

    // Fetch first batch of posts
    $args = array(
        'numberposts' => $posts_per_page,
        'offset'      => 0,
        'post_type'   => get_option('post_types', ['post', 'page']), // Use the selected post types
        'post_status' => 'publish',
        'orderby'     => 'post_type',
        'order'       => 'ASC',
    );
    if (ybme_wpml_active()) {
        $args['suppress_filters'] = false;
        $args['lang'] = '';
    }
    $all_posts = get_posts($args);

    $available_cols = ybme_get_available_columns();
    echo '<thead><tr>';
    foreach ($enabled_columns as $col) {
        if (isset($available_cols[$col])) {
            echo '<th>' . esc_html($available_cols[$col]['label']) . '</th>';
        }
    }
    echo '</tr></thead><tbody>';
    foreach ($all_posts as $post) {
        echo ybme_render_meta_row($post, $enabled_columns, $lang_flags, $selected_langs);
    }
    echo '</tbody>';
    echo '</table>';
    echo '<button id="load-more-btn" style="margin-top:20px;">' . esc_html__('Load More', YBME_TEXT_DOMAIN) . '</button>';
    wp_reset_postdata();

    echo '<div style="text-align: center; margin-top: 20px;">';
    echo '<button id="save-btn" style="background-color: #4CAF50; color: white; padding: 10px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer;">' . esc_html__('Save Changes', YBME_TEXT_DOMAIN) . '</button>';
    echo '<button id="undo-btn" style="background-color: #777; color: white; padding: 10px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer;">' . esc_html__('Undo Last Change', YBME_TEXT_DOMAIN) . '</button>';
    /**
     * Inject extra editor action controls (e.g. the "Submit for Review"
     * button from the pending-changes module).
     */
    do_action('ybme_editor_actions');
    echo '<a href="https://www.buymeacoffee.com/costinbotez" target="_blank" rel="noopener noreferrer" style="background-color: #FF813F; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">' . esc_html__('Support the plugin 🙌', YBME_TEXT_DOMAIN) . '</a>';
    echo '</div>';
    echo '<ul id="history-log" style="margin-top: 20px;"></ul>';
}

/**
 * Change history / audit log admin page.
 */
function ybme_history_page() {
    if (!current_user_can(YBME_CAPABILITY)) { wp_die(); }
    global $wpdb;
    $table = ybme_history_table();
    // Table name is built from $wpdb->prefix (trusted), not user input.
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200");

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Change History', YBME_TEXT_DOMAIN) . '</h1>';

    if (empty($rows)) {
        echo '<p>' . esc_html__('No changes have been recorded yet.', YBME_TEXT_DOMAIN) . '</p></div>';
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('When', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Post', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Field', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('Old value', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('New value', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th>' . esc_html__('User', YBME_TEXT_DOMAIN) . '</th>';
    echo '<th></th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $user  = get_userdata($r->user_id);
        $uname = $user ? $user->display_name : ('#' . intval($r->user_id));
        $edit  = get_edit_post_link($r->post_id);
        echo '<tr data-id="' . esc_attr($r->id) . '">';
        echo '<td>' . esc_html($r->created_at) . '</td>';
        echo '<td>' . ($edit ? '<a href="' . esc_url($edit) . '">' . esc_html(get_the_title($r->post_id)) . '</a>' : esc_html(get_the_title($r->post_id))) . '</td>';
        echo '<td>' . esc_html(ybme_meta_key_label($r->meta_key)) . '</td>';
        echo '<td>' . esc_html($r->old_value) . '</td>';
        echo '<td>' . esc_html($r->new_value) . '</td>';
        echo '<td>' . esc_html($uname) . '</td>';
        echo '<td><button class="button ybme-revert" data-id="' . esc_attr($r->id) . '">' . esc_html__('Revert', YBME_TEXT_DOMAIN) . '</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<div id="notification" class="toast" style="display:none;"></div>';
    echo '</div>';
}

/* -------------------------------------------------------------------------
 * Settings sanitization
 *
 * Every registered option runs through one of these so a crafted options.php
 * POST can never store an unexpected post type, column, role, key or value.
 * ---------------------------------------------------------------------- */

// Keep only values that are real, public post types.
function ybme_sanitize_post_types($value) {
    $public = get_post_types(array('public' => true), 'names');
    $clean  = array();
    if (is_array($value)) {
        foreach ($value as $type) {
            $type = sanitize_key($type);
            if (in_array($type, $public, true)) {
                $clean[] = $type;
            }
        }
    }
    // Never let the editor end up with zero post types to query.
    return empty($clean) ? array('post', 'page') : array_values(array_unique($clean));
}

// Keep only columns the plugin actually knows how to render.
function ybme_sanitize_columns($value) {
    $available = array_keys(ybme_get_available_columns());
    $clean     = array();
    if (is_array($value)) {
        foreach ($value as $col) {
            $col = sanitize_key($col);
            if (in_array($col, $available, true)) {
                $clean[] = $col;
            }
        }
    }
    return array_values(array_unique($clean));
}

// Keep only roles that exist on this site.
function ybme_sanitize_roles($value) {
    $editable = array_keys(get_editable_roles());
    $clean    = array();
    if (is_array($value)) {
        foreach ($value as $role) {
            $role = sanitize_key($role);
            if (in_array($role, $editable, true)) {
                $clean[] = $role;
            }
        }
    }
    // Administrators must always retain access.
    if (!in_array('administrator', $clean, true)) {
        $clean[] = 'administrator';
    }
    return array_values(array_unique($clean));
}

// Accept only a known provider id, or '' for auto-detect.
function ybme_sanitize_provider($value) {
    $value = sanitize_key($value);
    return isset(ybme_providers()[$value]) ? $value : '';
}

// Clamp rows-per-page to a sane range so the editor can't be made to query
// thousands of posts in a single request.
function ybme_sanitize_rows_per_page($value) {
    $value = intval($value);
    if ($value < 1) {
        $value = YBME_POSTS_PER_PAGE;
    }
    if ($value > 200) {
        $value = 200;
    }
    return $value;
}

// Keep only language codes WPML actually reports.
function ybme_sanitize_languages($value) {
    $known = array_keys(ybme_get_wpml_languages());
    $clean = array();
    if (is_array($value)) {
        foreach ($value as $code) {
            $code = sanitize_key($code);
            if (in_array($code, $known, true)) {
                $clean[] = $code;
            }
        }
    }
    return array_values(array_unique($clean));
}

// Register our settings
function register_yoast_bulk_meta_editor_settings() {
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_provider', array('sanitize_callback' => 'ybme_sanitize_provider'));
    register_setting('yoast-bulk-meta-editor-settings-group', 'post_types', array('sanitize_callback' => 'ybme_sanitize_post_types'));
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_enabled_columns', array('sanitize_callback' => 'ybme_sanitize_columns'));
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_license_key', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_roles', array('sanitize_callback' => 'ybme_sanitize_roles'));
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_rows_per_page', array('sanitize_callback' => 'ybme_sanitize_rows_per_page'));
    if (ybme_wpml_active()) {
        register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_languages', array('sanitize_callback' => 'ybme_sanitize_languages'));
    }
}

// Create settings page
function yoast_bulk_meta_editor_settings_page() {

    // Get all public post types
    $all_post_types = get_post_types(array('public' => true), 'names');
    // Get selected post types
    $selected_post_types = get_option('post_types', ['post', 'page']);
    $all_columns = ybme_get_available_columns();
    $selected_columns = ybme_get_enabled_columns();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Yoast Bulk Meta Editor Settings', YBME_TEXT_DOMAIN); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields('yoast-bulk-meta-editor-settings-group'); ?>
            <?php do_settings_sections('yoast-bulk-meta-editor-settings-group'); ?>

            <h2><?php echo esc_html__('SEO plugin:', YBME_TEXT_DOMAIN); ?></h2>
            <?php
                $providers        = ybme_providers();
                $saved_provider   = get_option('ybme_provider', '');
                $active_provider  = ybme_active_provider_id();
                $active_label     = $active_provider ? $providers[$active_provider]['label'] : __('none detected', YBME_TEXT_DOMAIN);
            ?>
            <p class="description">
                <?php echo esc_html(sprintf(
                    /* translators: %s: name of the detected SEO plugin */
                    __('Currently editing: %s', YBME_TEXT_DOMAIN),
                    $active_label
                )); ?>
            </p>
            <select name="ybme_provider">
                <option value="" <?php selected($saved_provider, ''); ?>><?php echo esc_html__('Auto-detect', YBME_TEXT_DOMAIN); ?></option>
                <?php foreach ($providers as $pid => $p) :
                    $avail = ybme_provider_is_available($pid); ?>
                    <option value="<?php echo esc_attr($pid); ?>" <?php selected($saved_provider, $pid); disabled(!$avail); ?>>
                        <?php echo esc_html($p['label']); ?><?php echo $avail ? '' : ' ' . esc_html__('(not active)', YBME_TEXT_DOMAIN); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php echo esc_html__('Yoast SEO, Rank Math and SEOPress are supported. (All in One SEO stores its data differently and is not yet supported.)', YBME_TEXT_DOMAIN); ?></p>

            <h2 style="margin-top:20px;"><?php echo esc_html__('Select post types:', YBME_TEXT_DOMAIN); ?></h2>

            <?php
                foreach ($all_post_types as $post_type) {
                    echo '<input type="checkbox" id="' . esc_attr($post_type) . '" name="post_types[]" value="' . esc_attr($post_type) . '"' . (in_array($post_type, $selected_post_types) ? ' checked' : '') . '>';
                    echo '<label for="' . esc_attr($post_type) . '">' . esc_html(ucfirst($post_type)) . '</label><br>';
                }
            ?>

            <h2 style="margin-top:20px;"><?php echo esc_html__('Select columns:', YBME_TEXT_DOMAIN); ?></h2>
            <?php
                foreach ($all_columns as $key => $info) {
                    echo '<input type="checkbox" id="col_' . esc_attr($key) . '" name="ybme_enabled_columns[]" value="' . esc_attr($key) . '"' . (in_array($key, $selected_columns) ? ' checked' : '') . '>';
                    echo '<label for="col_' . esc_attr($key) . '">' . esc_html($info['label']) . '</label><br>';
                }
            ?>

            <?php if (ybme_wpml_active()) : ?>
                <?php $langs = ybme_get_wpml_languages(); $sel_langs = get_option('ybme_languages', array()); ?>
                <h2 style="margin-top:20px;">
                    <?php echo esc_html__('Select languages:', YBME_TEXT_DOMAIN); ?>
                </h2>
                <p><?php echo esc_html(sprintf(__('%d languages detected', YBME_TEXT_DOMAIN), count($langs))); ?></p>
                <?php foreach ($langs as $code => $lang) {
                    echo '<input type="checkbox" name="ybme_languages[]" value="' . esc_attr($code) . '"' . (in_array($code, $sel_langs) ? ' checked' : '') . '>';
                    echo '<label>' . esc_html($lang['native_name']) . '</label><br>';
                } ?>
            <?php endif; ?>

            <h2 style="margin-top:20px;"><?php echo esc_html__('Rows per page:', YBME_TEXT_DOMAIN); ?></h2>
            <?php
                $rows = ybme_get_rows_per_page();
                echo '<input type="number" min="1" max="200" style="width:60px;" name="ybme_rows_per_page" value="' . esc_attr($rows) . '" />';
            ?>

            <h2 style="margin-top:20px;"><?php echo esc_html__('Allowed Roles:', YBME_TEXT_DOMAIN); ?></h2>
            <?php
                $all_roles = get_editable_roles();
                $selected_roles = get_option('ybme_roles', array('administrator'));
                foreach ($all_roles as $role_slug => $details) {
                    echo '<input type="checkbox" id="role_' . esc_attr($role_slug) . '" name="ybme_roles[]" value="' . esc_attr($role_slug) . '"' . (in_array($role_slug, $selected_roles) ? ' checked' : '') . '>';
                    echo '<label for="role_' . esc_attr($role_slug) . '">' . esc_html($details['name']) . '</label><br>';
                }
            ?>
            <h2 style="margin-top:20px;"><?php echo esc_html__('License Key (PRO):', YBME_TEXT_DOMAIN); ?></h2>
            <?php
                $license = esc_attr(get_option('ybme_license_key', ''));
                echo '<input type="text" style="width:300px;" name="ybme_license_key" value="' . $license . '" placeholder="' . esc_attr__('Enter license key', YBME_TEXT_DOMAIN) . '" />';
            ?>
            <div class="ybme-banner">
                <h2><?php echo esc_html__('Bulk-edit metadata in seconds', YBME_TEXT_DOMAIN); ?></h2>
                <ul>
                    <li><?php echo esc_html__('Offline backups you can trust', YBME_TEXT_DOMAIN); ?></li>
                    <li><?php echo esc_html__('Boost your WP workflow', YBME_TEXT_DOMAIN); ?></li>
                </ul>
            </div>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}


add_action('admin_enqueue_scripts', 'enqueue_admin_scripts');
function enqueue_admin_scripts($hook)
{
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

    // Main editor page.
    if ($page === 'yoast-bulk-meta-editor') {
        // Enqueue jQuery UI sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue tablesorter
        wp_enqueue_script('jquery-tablesorter', plugins_url('/js/jquery.tablesorter.min.js', __FILE__), array('jquery'), '1.0', true);

        // Enqueue our custom script
        wp_enqueue_script('bulk-meta-editor', plugins_url('/js/bulk-meta-editor.js', __FILE__), array('jquery', 'jquery-tablesorter', 'jquery-ui-sortable'), YBME_VERSION, true);
        $rows = ybme_get_rows_per_page();
        /**
         * Filter the data localized to the editor script so feature modules
         * can add their own flags and i18n strings.
         */
        $editor_vars = apply_filters('ybme_editor_js_vars', array(
            'posts_per_page' => $rows,
            'nonce'          => wp_create_nonce(YBME_NONCE_ACTION),
            'field_keys'     => ybme_provider_field_keys(),
            'i18n' => array(
                'meta_updated'    => __('Meta info updated successfully', YBME_TEXT_DOMAIN),
                'update_failed'   => __('Failed to update meta info', YBME_TEXT_DOMAIN),
                'save_none'       => __('No changes to save', YBME_TEXT_DOMAIN),
                'save_summary'    => __('%d change(s) saved', YBME_TEXT_DOMAIN),
                'save_partial'    => __('%1$d saved, %2$d skipped (no permission)', YBME_TEXT_DOMAIN),
                'unsaved_warning' => __('You have unsaved changes. Leave this page and discard them?', YBME_TEXT_DOMAIN),
                'nothing_to_undo' => __('Nothing to undo', YBME_TEXT_DOMAIN),
                'change_reverted' => __('Change reverted', YBME_TEXT_DOMAIN),
                'revert_failed'   => __('Failed to revert change', YBME_TEXT_DOMAIN),
                'load_failed'     => __('Failed to load more posts', YBME_TEXT_DOMAIN),
                'label_title'     => __('Title', YBME_TEXT_DOMAIN),
                'label_meta_description' => __('Meta Description', YBME_TEXT_DOMAIN),
                'label_keyword'   => __('Keyword', YBME_TEXT_DOMAIN),
                'label_canonical_url' => __('Canonical URL', YBME_TEXT_DOMAIN),
                'label_social_title' => __('Social Title', YBME_TEXT_DOMAIN),
                'fr_no_matches'   => __('No matching posts found.', YBME_TEXT_DOMAIN),
                'fr_matches'      => __('%d post(s) will be updated. Showing a sample below:', YBME_TEXT_DOMAIN),
                'fr_applied'      => __('%d post(s) updated.', YBME_TEXT_DOMAIN),
                'fr_confirm'      => __('Apply this replacement to all matching posts? This cannot be undone in bulk.', YBME_TEXT_DOMAIN),
                'fr_error'        => __('Find & replace failed.', YBME_TEXT_DOMAIN),
                'fr_enter_find'   => __('Enter text to find first.', YBME_TEXT_DOMAIN),
                'score_missing_title' => __('Missing meta title', YBME_TEXT_DOMAIN),
                'score_title_long'    => __('Meta title is too long', YBME_TEXT_DOMAIN),
                'score_title_short'   => __('Meta title is short', YBME_TEXT_DOMAIN),
                'score_missing_desc'  => __('Missing meta description', YBME_TEXT_DOMAIN),
                'score_desc_long'     => __('Meta description is too long', YBME_TEXT_DOMAIN),
                'score_desc_short'    => __('Meta description is short', YBME_TEXT_DOMAIN),
                'score_no_keyword'    => __('No focus keyword', YBME_TEXT_DOMAIN),
                'score_good'          => __('Looks good', YBME_TEXT_DOMAIN),
            ),
        ));
        wp_localize_script('bulk-meta-editor', 'bulk_editor_vars', $editor_vars);

        // Enqueue our custom styles
        wp_enqueue_style('bulk-meta-editor', plugins_url('/css/style.css', __FILE__), array(), YBME_VERSION, 'all');
        return;
    }

    // Audit dashboard (styles only).
    if ($page === 'yoast-bulk-meta-editor-audit') {
        wp_enqueue_style('bulk-meta-editor', plugins_url('/css/style.css', __FILE__), array(), YBME_VERSION, 'all');
        return;
    }

    // History page.
    if ($page === 'yoast-bulk-meta-editor-history') {
        wp_enqueue_script('ybme-history', plugins_url('/js/history.js', __FILE__), array('jquery'), YBME_VERSION, true);
        wp_localize_script('ybme-history', 'ybme_history_vars', array(
            'nonce' => wp_create_nonce(YBME_NONCE_ACTION),
            'i18n'  => array(
                'confirm'       => __('Revert this field to its previous value?', YBME_TEXT_DOMAIN),
                'reverted'      => __('Change reverted', YBME_TEXT_DOMAIN),
                'revert_failed' => __('Failed to revert change', YBME_TEXT_DOMAIN),
            ),
        ));
        wp_enqueue_style('bulk-meta-editor', plugins_url('/css/style.css', __FILE__), array(), YBME_VERSION, 'all');
    }
}

add_action('wp_ajax_save_meta_info', 'save_meta_info');
function save_meta_info()
{
    check_ajax_referer(YBME_NONCE_ACTION, 'nonce');
    if (!current_user_can(YBME_CAPABILITY)) { wp_send_json_error(); }
    if (!ybme_provider_active()) { wp_send_json_error(array('message' => __('No supported SEO plugin is active.', YBME_TEXT_DOMAIN))); }

    $post_id  = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $meta_key = isset($_POST['meta_key']) ? sanitize_text_field(wp_unslash($_POST['meta_key'])) : '';
    $raw      = isset($_POST['meta_value']) ? wp_unslash($_POST['meta_value']) : '';

    if ($post_id <= 0 || !in_array($meta_key, ybme_allowed_meta_keys(), true)) {
        wp_send_json_error();
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error();
    }

    $value = ybme_sanitize_meta_value($meta_key, $raw);
    $old   = get_post_meta($post_id, $meta_key, true);
    update_post_meta($post_id, $meta_key, wp_slash($value));
    ybme_log_change($post_id, $meta_key, $old, $value);

    wp_send_json_success(array('value' => $value));
}

add_action('wp_ajax_ybme_save_meta_batch', 'ybme_save_meta_batch');
/**
 * Save many meta changes in a single request. The client sends a JSON map of
 * { post_id: { meta_key: value, ... }, ... }. Each field is validated against
 * the allow-list and per-post edit permission exactly like the single save.
 */
function ybme_save_meta_batch()
{
    check_ajax_referer(YBME_NONCE_ACTION, 'nonce');
    if (!current_user_can(YBME_CAPABILITY)) { wp_send_json_error(); }
    if (!ybme_provider_active()) { wp_send_json_error(array('message' => __('No supported SEO plugin is active.', YBME_TEXT_DOMAIN))); }

    // $_POST is slash-escaped by WordPress; unslash before decoding the JSON.
    $raw     = isset($_POST['changes']) ? wp_unslash($_POST['changes']) : '';
    $changes = json_decode($raw, true);
    if (!is_array($changes) || empty($changes)) {
        wp_send_json_error(array('message' => __('No changes to save.', YBME_TEXT_DOMAIN)));
    }

    $allowed = ybme_allowed_meta_keys();
    $saved   = 0;
    $skipped = 0;
    $results = array();

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
            $value = ybme_sanitize_meta_value($meta_key, (string) $value);
            $old   = get_post_meta($post_id, $meta_key, true);
            update_post_meta($post_id, $meta_key, wp_slash($value));
            ybme_log_change($post_id, $meta_key, $old, $value);
            $saved++;
            $results[] = array('post_id' => $post_id, 'meta_key' => $meta_key, 'value' => $value);
        }
    }

    wp_send_json_success(array('saved' => $saved, 'skipped' => $skipped, 'results' => $results));
}

add_action('wp_ajax_load_more_posts', 'yoast_bulk_meta_editor_load_more_posts');
function yoast_bulk_meta_editor_load_more_posts()
{
    check_ajax_referer(YBME_NONCE_ACTION, 'nonce');
    if (!current_user_can(YBME_CAPABILITY)) { wp_die(); }
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $enabled_columns = ybme_get_enabled_columns();
    $rows = ybme_get_rows_per_page();
    $args = array(
        'numberposts' => $rows,
        'offset'      => $offset,
        'post_type'   => get_option('post_types', ['post', 'page']),
        'post_status' => 'publish',
        'orderby'     => 'post_type',
        'order'       => 'ASC',
    );
    $selected_langs = array();
    $lang_flags = array();
    if (ybme_wpml_active()) {
        $args['suppress_filters'] = false;
        $args['lang'] = '';
        $selected_langs = get_option('ybme_languages', array());
        $langs = ybme_get_wpml_languages();
        foreach ($langs as $code => $lang) {
            $lang_flags[$code] = $lang['country_flag_url'];
        }
    }
    $posts = get_posts($args);
    foreach ($posts as $post) {
        echo ybme_render_meta_row($post, $enabled_columns, $lang_flags, $selected_langs);
    }
    wp_reset_postdata();
    wp_die();
}

/* -------------------------------------------------------------------------
 * Bulk find & replace
 * ---------------------------------------------------------------------- */

function ybme_fr_field_to_key($field) {
    $keys = ybme_provider_field_keys();
    $map  = array(
        'meta_title'       => $keys['meta_title'],
        'meta_description' => $keys['meta_description'],
        'keyword'          => $keys['keyword'],
    );
    return isset($map[$field]) ? $map[$field] : '';
}

/**
 * Expand Yoast-style template variables in a replacement string.
 */
function ybme_expand_template($text, $post) {
    $repl = array(
        '%%title%%'    => get_the_title($post->ID),
        '%%sitename%%' => get_bloginfo('name'),
        '%%sep%%'      => '-',
    );
    return strtr($text, $repl);
}

/**
 * Apply a find/replace to a single string.
 */
function ybme_apply_replace($subject, $find, $replace, $case_sensitive, $regex) {
    if ($regex) {
        $pattern = '~' . str_replace('~', '\~', $find) . '~' . ($case_sensitive ? '' : 'i');
        $result  = @preg_replace($pattern, $replace, $subject);
        return ($result === null) ? $subject : $result;
    }
    if ($case_sensitive) {
        return str_replace($find, $replace, $subject);
    }
    return str_ireplace($find, $replace, $subject);
}

add_action('wp_ajax_ybme_find_replace_preview', function () { ybme_find_replace_handler(false); });
add_action('wp_ajax_ybme_find_replace_apply', function () { ybme_find_replace_handler(true); });

function ybme_find_replace_handler($apply) {
    check_ajax_referer(YBME_NONCE_ACTION, 'nonce');
    if (!current_user_can(YBME_CAPABILITY)) {
        wp_send_json_error(array('message' => __('Permission denied.', YBME_TEXT_DOMAIN)));
    }
    if ($apply && !ybme_provider_active()) {
        wp_send_json_error(array('message' => __('No supported SEO plugin is active.', YBME_TEXT_DOMAIN)));
    }

    $field    = isset($_POST['field']) ? sanitize_text_field(wp_unslash($_POST['field'])) : '';
    $meta_key = ybme_fr_field_to_key($field);
    if (!$meta_key) {
        wp_send_json_error(array('message' => __('Invalid field.', YBME_TEXT_DOMAIN)));
    }

    $find    = isset($_POST['find']) ? wp_unslash($_POST['find']) : '';
    $replace = isset($_POST['replace']) ? wp_unslash($_POST['replace']) : '';
    $case    = !empty($_POST['case_sensitive']);
    $regex   = !empty($_POST['regex']);

    if ($find === '') {
        wp_send_json_error(array('message' => __('Enter text to find.', YBME_TEXT_DOMAIN)));
    }

    // Cap pattern length to limit exposure to catastrophic-backtracking (ReDoS)
    // patterns run across every post.
    if (strlen($find) > 1000 || strlen($replace) > 2000) {
        wp_send_json_error(array('message' => __('Find or replace text is too long.', YBME_TEXT_DOMAIN)));
    }

    if ($regex) {
        $pattern = '~' . str_replace('~', '\~', $find) . '~' . ($case ? '' : 'i');
        if (@preg_match($pattern, '') === false) {
            wp_send_json_error(array('message' => __('Invalid regular expression.', YBME_TEXT_DOMAIN)));
        }
    }

    $post_types = get_option('post_types', array('post', 'page'));
    $query = new WP_Query(array(
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array(
            array('key' => $meta_key, 'compare' => 'EXISTS'),
        ),
    ));

    $affected = 0;
    $samples  = array();

    foreach ($query->posts as $pid) {
        $val = get_post_meta($pid, $meta_key, true);
        if ($val === '') {
            continue;
        }
        $post            = get_post($pid);
        $expanded        = ybme_expand_template($replace, $post);
        $new             = ybme_apply_replace($val, $find, $expanded, $case, $regex);
        if ($new === $val) {
            continue;
        }
        $affected++;
        if (count($samples) < 20) {
            $samples[] = array(
                'post_id' => $pid,
                'title'   => get_the_title($pid),
                'old'     => $val,
                'new'     => $new,
            );
        }
        if ($apply) {
            if (!current_user_can('edit_post', $pid)) {
                continue;
            }
            $final = ybme_sanitize_meta_value($meta_key, $new);
            update_post_meta($pid, $meta_key, wp_slash($final));
            ybme_log_change($pid, $meta_key, $val, $final);
        }
    }

    wp_send_json_success(array(
        'affected' => $affected,
        'samples'  => $samples,
        'applied'  => (bool) $apply,
    ));
}

/* -------------------------------------------------------------------------
 * Revert a logged change (from the History page)
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_ybme_revert_change', 'ybme_revert_change');
function ybme_revert_change() {
    check_ajax_referer(YBME_NONCE_ACTION, 'nonce');
    if (!current_user_can(YBME_CAPABILITY)) {
        wp_send_json_error();
    }
    if (!ybme_provider_active()) {
        wp_send_json_error(array('message' => __('No supported SEO plugin is active.', YBME_TEXT_DOMAIN)));
    }
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        wp_send_json_error();
    }

    global $wpdb;
    $table = ybme_history_table();
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    if (!$row) {
        wp_send_json_error();
    }
    if (!in_array($row->meta_key, ybme_allowed_meta_keys(), true)) {
        wp_send_json_error();
    }
    if (!current_user_can('edit_post', $row->post_id)) {
        wp_send_json_error();
    }

    $current = get_post_meta($row->post_id, $row->meta_key, true);
    $value   = ybme_sanitize_meta_value($row->meta_key, $row->old_value);
    update_post_meta($row->post_id, $row->meta_key, wp_slash($value));
    ybme_log_change($row->post_id, $row->meta_key, $current, $value);

    wp_send_json_success();
}
