<?php
/**
 * Google Search Console integration.
 *
 * Connects a Search Console property over OAuth2 (no bundled Google client
 * library — just wp_remote_* calls) and surfaces clicks / impressions / CTR /
 * average position per URL as optional, read-only columns in the editor so
 * users can prioritise the pages that get impressions but few clicks.
 *
 * Credentials and tokens live in options. They are sensitive: the client
 * secret and refresh token grant read access to the site's Search Console
 * data, so this page is gated on `manage_options`.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YBME_GSC_SCOPE', 'https://www.googleapis.com/auth/webmasters.readonly');
define('YBME_GSC_AUTH_ENDPOINT', 'https://accounts.google.com/o/oauth2/v2/auth');
define('YBME_GSC_TOKEN_ENDPOINT', 'https://oauth2.googleapis.com/token');
define('YBME_GSC_API_BASE', 'https://www.googleapis.com/webmasters/v3');
define('YBME_GSC_DATA_TRANSIENT', 'ybme_gsc_data');
define('YBME_GSC_CRON_HOOK', 'ybme_gsc_refresh_cron');

/* -------------------------------------------------------------------------
 * State helpers
 * ---------------------------------------------------------------------- */

function ybme_gsc_client_id() {
    return trim((string) get_option('ybme_gsc_client_id', ''));
}

function ybme_gsc_client_secret() {
    return trim((string) get_option('ybme_gsc_client_secret', ''));
}

// OAuth credentials have been entered.
function ybme_gsc_configured() {
    return ybme_gsc_client_id() !== '' && ybme_gsc_client_secret() !== '';
}

function ybme_gsc_get_tokens() {
    $t = get_option('ybme_gsc_tokens', array());
    return is_array($t) ? $t : array();
}

function ybme_gsc_save_tokens($tokens) {
    update_option('ybme_gsc_tokens', $tokens, false);
}

function ybme_gsc_delete_tokens() {
    delete_option('ybme_gsc_tokens');
    delete_option('ybme_gsc_site');
    delete_transient(YBME_GSC_DATA_TRANSIENT);
}

// A refresh token has been stored, i.e. the property is connected.
function ybme_gsc_connected() {
    $t = ybme_gsc_get_tokens();
    return !empty($t['refresh_token']);
}

function ybme_gsc_selected_site() {
    return (string) get_option('ybme_gsc_site', '');
}

// The exact redirect URI that must be registered in the Google Cloud console.
function ybme_gsc_redirect_uri() {
    return admin_url('admin.php?page=yoast-bulk-meta-editor-gsc');
}

/* -------------------------------------------------------------------------
 * OAuth2
 * ---------------------------------------------------------------------- */

function ybme_gsc_auth_url() {
    $args = array(
        'client_id'     => ybme_gsc_client_id(),
        'redirect_uri'  => ybme_gsc_redirect_uri(),
        'response_type' => 'code',
        'scope'         => YBME_GSC_SCOPE,
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => wp_create_nonce('ybme_gsc_oauth'),
    );
    return YBME_GSC_AUTH_ENDPOINT . '?' . http_build_query($args);
}

/**
 * Exchange an authorization code for tokens. Returns true on success.
 */
function ybme_gsc_exchange_code($code) {
    $resp = wp_remote_post(YBME_GSC_TOKEN_ENDPOINT, array(
        'timeout' => 20,
        'body'    => array(
            'code'          => $code,
            'client_id'     => ybme_gsc_client_id(),
            'client_secret' => ybme_gsc_client_secret(),
            'redirect_uri'  => ybme_gsc_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ),
    ));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || empty($data['access_token'])) {
        return false;
    }
    // Google only returns the refresh token on the first consent; keep any
    // existing one if this response omits it.
    $existing = ybme_gsc_get_tokens();
    ybme_gsc_save_tokens(array(
        'access_token'  => $data['access_token'],
        'refresh_token' => !empty($data['refresh_token']) ? $data['refresh_token'] : (isset($existing['refresh_token']) ? $existing['refresh_token'] : ''),
        'expires_at'    => time() + (isset($data['expires_in']) ? intval($data['expires_in']) : 3600),
    ));
    return true;
}

/**
 * A valid access token, refreshing it if expired. Empty string on failure.
 */
function ybme_gsc_access_token() {
    $t = ybme_gsc_get_tokens();
    if (empty($t['refresh_token'])) {
        return '';
    }
    if (!empty($t['access_token']) && isset($t['expires_at']) && $t['expires_at'] > (time() + 60)) {
        return $t['access_token'];
    }

    $resp = wp_remote_post(YBME_GSC_TOKEN_ENDPOINT, array(
        'timeout' => 20,
        'body'    => array(
            'client_id'     => ybme_gsc_client_id(),
            'client_secret' => ybme_gsc_client_secret(),
            'refresh_token' => $t['refresh_token'],
            'grant_type'    => 'refresh_token',
        ),
    ));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return '';
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || empty($data['access_token'])) {
        return '';
    }
    $t['access_token'] = $data['access_token'];
    $t['expires_at']   = time() + (isset($data['expires_in']) ? intval($data['expires_in']) : 3600);
    ybme_gsc_save_tokens($t);
    return $t['access_token'];
}

/* -------------------------------------------------------------------------
 * API calls
 * ---------------------------------------------------------------------- */

function ybme_gsc_api_get($path) {
    $token = ybme_gsc_access_token();
    if ($token === '') {
        return null;
    }
    $resp = wp_remote_get(YBME_GSC_API_BASE . $path, array(
        'timeout' => 20,
        'headers' => array('Authorization' => 'Bearer ' . $token),
    ));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return null;
    }
    return json_decode(wp_remote_retrieve_body($resp), true);
}

function ybme_gsc_api_post($path, $body) {
    $token = ybme_gsc_access_token();
    if ($token === '') {
        return null;
    }
    $resp = wp_remote_post(YBME_GSC_API_BASE . $path, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return null;
    }
    return json_decode(wp_remote_retrieve_body($resp), true);
}

// The Search Console properties this account can read.
function ybme_gsc_list_sites() {
    $data = ybme_gsc_api_get('/sites');
    if (!is_array($data) || empty($data['siteEntry'])) {
        return array();
    }
    $sites = array();
    foreach ($data['siteEntry'] as $entry) {
        if (!empty($entry['siteUrl'])) {
            $sites[] = $entry['siteUrl'];
        }
    }
    return $sites;
}

/* -------------------------------------------------------------------------
 * Metrics
 * ---------------------------------------------------------------------- */

/**
 * Normalise a URL for matching GSC rows against post permalinks: drop the
 * scheme, lowercase the host (but not the path), and trim a trailing slash.
 */
function ybme_gsc_normalize_url($url) {
    $url = preg_replace('#^https?://#i', '', trim((string) $url));
    $url = rtrim($url, '/');
    $slash = strpos($url, '/');
    if ($slash === false) {
        return strtolower($url);
    }
    return strtolower(substr($url, 0, $slash)) . substr($url, $slash);
}

/**
 * Query Search Console for per-page metrics over the last ~28 days and cache
 * a normalized-URL => metrics map. Returns the map (possibly empty).
 */
function ybme_gsc_fetch_metrics($force = false) {
    if (!$force) {
        $cached = get_transient(YBME_GSC_DATA_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $site = ybme_gsc_selected_site();
    if ($site === '' || !ybme_gsc_connected()) {
        return array();
    }

    // GSC data lags a couple of days; end the window three days back.
    $end   = gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS);
    $start = gmdate('Y-m-d', time() - 31 * DAY_IN_SECONDS);

    $data = ybme_gsc_api_post('/sites/' . rawurlencode($site) . '/searchAnalytics/query', array(
        'startDate'  => $start,
        'endDate'    => $end,
        'dimensions' => array('page'),
        'rowLimit'   => 25000,
    ));

    $map = array();
    if (is_array($data) && !empty($data['rows'])) {
        foreach ($data['rows'] as $r) {
            if (empty($r['keys'][0])) {
                continue;
            }
            $map[ybme_gsc_normalize_url($r['keys'][0])] = array(
                'clicks'      => isset($r['clicks']) ? (int) round($r['clicks']) : 0,
                'impressions' => isset($r['impressions']) ? (int) round($r['impressions']) : 0,
                'ctr'         => isset($r['ctr']) ? (float) $r['ctr'] : 0.0,
                'position'    => isset($r['position']) ? (float) $r['position'] : 0.0,
            );
        }
    }

    set_transient(YBME_GSC_DATA_TRANSIENT, $map, 12 * HOUR_IN_SECONDS);
    return $map;
}

// Memoized read of the cached metrics for the current request (no API call).
function ybme_gsc_metrics_map() {
    static $map = null;
    if ($map === null) {
        $cached = get_transient(YBME_GSC_DATA_TRANSIENT);
        $map    = is_array($cached) ? $cached : array();
    }
    return $map;
}

function ybme_gsc_metrics_for_url($url) {
    $map = ybme_gsc_metrics_map();
    $key = ybme_gsc_normalize_url($url);
    return isset($map[$key]) ? $map[$key] : null;
}

/* -------------------------------------------------------------------------
 * Editor columns
 * ---------------------------------------------------------------------- */

function ybme_gsc_columns() {
    return array(
        'gsc_clicks'      => __('Clicks', YBME_TEXT_DOMAIN),
        'gsc_impressions' => __('Impressions', YBME_TEXT_DOMAIN),
        'gsc_ctr'         => __('CTR', YBME_TEXT_DOMAIN),
        'gsc_position'    => __('Position', YBME_TEXT_DOMAIN),
    );
}

add_filter('ybme_available_columns', 'ybme_gsc_register_columns');
function ybme_gsc_register_columns($cols) {
    if (!ybme_gsc_connected()) {
        return $cols;
    }
    foreach (ybme_gsc_columns() as $key => $label) {
        $cols[$key] = array('label' => $label); // No meta_key: read-only.
    }
    return $cols;
}

add_filter('ybme_render_column_cell', 'ybme_gsc_render_cell', 10, 4);
function ybme_gsc_render_cell($cell, $col, $post_id, $permalink) {
    if (!isset(ybme_gsc_columns()[$col])) {
        return $cell;
    }
    $m = ybme_gsc_metrics_for_url($permalink);
    if (!$m) {
        return '<td class="ybme-gsc" data-value="-1">&mdash;</td>';
    }
    switch ($col) {
        case 'gsc_clicks':
            return '<td class="ybme-gsc" data-value="' . esc_attr($m['clicks']) . '">' . esc_html(number_format_i18n($m['clicks'])) . '</td>';
        case 'gsc_impressions':
            return '<td class="ybme-gsc" data-value="' . esc_attr($m['impressions']) . '">' . esc_html(number_format_i18n($m['impressions'])) . '</td>';
        case 'gsc_ctr':
            $pct = $m['ctr'] * 100;
            return '<td class="ybme-gsc" data-value="' . esc_attr($pct) . '">' . esc_html(number_format_i18n($pct, 1)) . '%</td>';
        case 'gsc_position':
            return '<td class="ybme-gsc" data-value="' . esc_attr($m['position']) . '">' . esc_html(number_format_i18n($m['position'], 1)) . '</td>';
    }
    return $cell;
}

/* -------------------------------------------------------------------------
 * Admin page, settings, actions
 * ---------------------------------------------------------------------- */

// Priority 20 so the parent menu (registered at the default priority) exists.
add_action('admin_menu', 'ybme_gsc_menu', 20);
function ybme_gsc_menu() {
    add_submenu_page(
        'yoast-bulk-meta-editor',
        __('Search Console', YBME_TEXT_DOMAIN),
        __('Search Console', YBME_TEXT_DOMAIN),
        'manage_options',
        'yoast-bulk-meta-editor-gsc',
        'ybme_gsc_page'
    );
}

add_action('admin_init', 'ybme_gsc_register_settings');
function ybme_gsc_register_settings() {
    register_setting('ybme-gsc-settings-group', 'ybme_gsc_client_id', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('ybme-gsc-settings-group', 'ybme_gsc_client_secret', array('sanitize_callback' => 'sanitize_text_field'));
}

// Handle OAuth callback and connect/disconnect/refresh/site actions.
add_action('admin_init', 'ybme_gsc_handle_actions');
function ybme_gsc_handle_actions() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'yoast-bulk-meta-editor-gsc') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    $base = ybme_gsc_redirect_uri();

    // OAuth callback: ?code=...&state=...
    if (isset($_GET['code'])) {
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!wp_verify_nonce($state, 'ybme_gsc_oauth')) {
            wp_safe_redirect($base . '&ybme_gsc_status=badstate');
            exit;
        }
        $ok = ybme_gsc_exchange_code(sanitize_text_field(wp_unslash($_GET['code'])));
        wp_safe_redirect($base . '&ybme_gsc_status=' . ($ok ? 'connected' : 'error'));
        exit;
    }

    $action = isset($_GET['ybme_gsc_action']) ? sanitize_key($_GET['ybme_gsc_action']) : '';
    if ($action === '') {
        return;
    }
    check_admin_referer('ybme_gsc_action');

    if ($action === 'disconnect') {
        ybme_gsc_delete_tokens();
        wp_safe_redirect($base . '&ybme_gsc_status=disconnected');
        exit;
    }
    if ($action === 'refresh') {
        ybme_gsc_fetch_metrics(true);
        wp_safe_redirect($base . '&ybme_gsc_status=refreshed');
        exit;
    }
    if ($action === 'select_site') {
        $site = isset($_GET['site']) ? esc_url_raw(wp_unslash($_GET['site'])) : '';
        // Domain properties look like "sc-domain:example.com" and aren't URLs.
        if ($site === '' && isset($_GET['site'])) {
            $site = sanitize_text_field(wp_unslash($_GET['site']));
        }
        update_option('ybme_gsc_site', $site);
        delete_transient(YBME_GSC_DATA_TRANSIENT);
        wp_safe_redirect($base . '&ybme_gsc_status=site_saved');
        exit;
    }
}

function ybme_gsc_action_url($action) {
    return wp_nonce_url(ybme_gsc_redirect_uri() . '&ybme_gsc_action=' . $action, 'ybme_gsc_action');
}

function ybme_gsc_page() {
    if (!current_user_can('manage_options')) {
        wp_die();
    }

    $status = isset($_GET['ybme_gsc_status']) ? sanitize_key($_GET['ybme_gsc_status']) : '';
    $notices = array(
        'connected'    => array('updated', __('Connected to Google Search Console.', YBME_TEXT_DOMAIN)),
        'disconnected' => array('updated', __('Disconnected from Google Search Console.', YBME_TEXT_DOMAIN)),
        'refreshed'    => array('updated', __('Search Console data refreshed.', YBME_TEXT_DOMAIN)),
        'site_saved'   => array('updated', __('Property saved.', YBME_TEXT_DOMAIN)),
        'error'        => array('error', __('Could not connect. Check your credentials and try again.', YBME_TEXT_DOMAIN)),
        'badstate'     => array('error', __('Security check failed. Please try connecting again.', YBME_TEXT_DOMAIN)),
    );

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Search Console', YBME_TEXT_DOMAIN) . '</h1>';

    if (isset($notices[$status])) {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$status][0]), esc_html($notices[$status][1]));
    }

    // Credentials.
    echo '<h2>' . esc_html__('Google API credentials', YBME_TEXT_DOMAIN) . '</h2>';
    echo '<p class="description">' . sprintf(
        /* translators: %s: the redirect URI to register in Google Cloud */
        esc_html__('Create an OAuth client (Web application) in Google Cloud with the Search Console API enabled, and add this redirect URI: %s', YBME_TEXT_DOMAIN),
        '<code>' . esc_html(ybme_gsc_redirect_uri()) . '</code>'
    ) . '</p>';
    echo '<form method="post" action="options.php">';
    settings_fields('ybme-gsc-settings-group');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="ybme_gsc_client_id">' . esc_html__('Client ID', YBME_TEXT_DOMAIN) . '</label></th>';
    echo '<td><input type="text" class="regular-text" id="ybme_gsc_client_id" name="ybme_gsc_client_id" value="' . esc_attr(ybme_gsc_client_id()) . '" autocomplete="off" /></td></tr>';
    echo '<tr><th scope="row"><label for="ybme_gsc_client_secret">' . esc_html__('Client Secret', YBME_TEXT_DOMAIN) . '</label></th>';
    echo '<td><input type="password" class="regular-text" id="ybme_gsc_client_secret" name="ybme_gsc_client_secret" value="' . esc_attr(ybme_gsc_client_secret()) . '" autocomplete="off" /></td></tr>';
    echo '</tbody></table>';
    submit_button(__('Save credentials', YBME_TEXT_DOMAIN));
    echo '</form>';

    if (!ybme_gsc_configured()) {
        echo '<p>' . esc_html__('Enter your credentials above to connect a property.', YBME_TEXT_DOMAIN) . '</p></div>';
        return;
    }

    // Connection.
    echo '<h2>' . esc_html__('Connection', YBME_TEXT_DOMAIN) . '</h2>';
    if (!ybme_gsc_connected()) {
        echo '<p><a class="button button-primary" href="' . esc_url(ybme_gsc_auth_url()) . '">' . esc_html__('Connect to Google Search Console', YBME_TEXT_DOMAIN) . '</a></p>';
        echo '</div>';
        return;
    }

    echo '<p>' . esc_html__('Status:', YBME_TEXT_DOMAIN) . ' <strong style="color:#46b450;">' . esc_html__('Connected', YBME_TEXT_DOMAIN) . '</strong> ';
    echo '<a class="button" href="' . esc_url(ybme_gsc_action_url('disconnect')) . '">' . esc_html__('Disconnect', YBME_TEXT_DOMAIN) . '</a></p>';

    // Property picker.
    $sites    = ybme_gsc_list_sites();
    $selected = ybme_gsc_selected_site();
    echo '<h2>' . esc_html__('Property', YBME_TEXT_DOMAIN) . '</h2>';
    if (empty($sites)) {
        echo '<p>' . esc_html__('No Search Console properties were found for this account.', YBME_TEXT_DOMAIN) . '</p>';
    } else {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="yoast-bulk-meta-editor-gsc" />';
        echo '<input type="hidden" name="ybme_gsc_action" value="select_site" />';
        wp_nonce_field('ybme_gsc_action');
        echo '<select name="site">';
        foreach ($sites as $site) {
            echo '<option value="' . esc_attr($site) . '" ' . selected($selected, $site, false) . '>' . esc_html($site) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Use this property', YBME_TEXT_DOMAIN), 'secondary', '', false);
        echo '</form>';
    }

    // Data status + manual refresh.
    if ($selected !== '') {
        $map = ybme_gsc_metrics_map();
        echo '<h2>' . esc_html__('Data', YBME_TEXT_DOMAIN) . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of URLs with cached metrics */
            __('Cached metrics for %d URLs (last ~28 days).', YBME_TEXT_DOMAIN),
            count($map)
        )) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(ybme_gsc_action_url('refresh')) . '">' . esc_html__('Refresh data now', YBME_TEXT_DOMAIN) . '</a></p>';
        echo '<p class="description">' . esc_html__('Enable the Clicks / Impressions / CTR / Position columns on the Settings page to see them in the editor.', YBME_TEXT_DOMAIN) . '</p>';
    }

    echo '</div>';
}

/* -------------------------------------------------------------------------
 * Daily refresh cron
 * ---------------------------------------------------------------------- */

add_action('init', 'ybme_gsc_schedule_cron');
function ybme_gsc_schedule_cron() {
    if (ybme_gsc_connected() && ybme_gsc_selected_site() !== '' && !wp_next_scheduled(YBME_GSC_CRON_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', YBME_GSC_CRON_HOOK);
    }
}

add_action(YBME_GSC_CRON_HOOK, 'ybme_gsc_cron_refresh');
function ybme_gsc_cron_refresh() {
    ybme_gsc_fetch_metrics(true);
}

// Clear the cron when the plugin is deactivated.
register_deactivation_hook(YBME_PATH . 'seo-bulk-meta-editor.php', 'ybme_gsc_clear_cron');
function ybme_gsc_clear_cron() {
    wp_clear_scheduled_hook(YBME_GSC_CRON_HOOK);
}
