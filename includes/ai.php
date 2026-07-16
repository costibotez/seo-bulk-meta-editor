<?php
/**
 * AI-assisted meta generation.
 *
 * Drafts a meta title and description for a post from its content using the
 * user's own Claude (Anthropic) or OpenAI API key. Results are never written
 * directly: a per-row "Generate" button (and an optional bulk action for
 * visible problem rows) fills the editor cells as unsaved edits that the user
 * reviews and then saves or submits for review.
 *
 * The API key is sensitive and is stored in an option; the settings page is
 * gated on `manage_options`. Post content is sent to the configured provider,
 * which is the point of the feature — configuring a key is opt-in.
 */

if (!defined('ABSPATH')) {
    exit;
}

function ybme_ai_provider() {
    $p = get_option('ybme_ai_provider', 'claude');
    return in_array($p, array('claude', 'openai'), true) ? $p : 'claude';
}

function ybme_ai_key() {
    return trim((string) get_option('ybme_ai_key', ''));
}

function ybme_ai_default_model($provider) {
    return $provider === 'openai' ? 'gpt-4o-mini' : 'claude-sonnet-5';
}

function ybme_ai_model() {
    $m = trim((string) get_option('ybme_ai_model', ''));
    return $m !== '' ? $m : ybme_ai_default_model(ybme_ai_provider());
}

function ybme_ai_configured() {
    return ybme_ai_key() !== '';
}

/* -------------------------------------------------------------------------
 * Generation
 * ---------------------------------------------------------------------- */

// Tidy a single line of model output into a storable meta value.
function ybme_ai_clean($value) {
    $value = wp_strip_all_tags((string) $value);
    $value = trim(preg_replace('/\s+/', ' ', $value));
    return sanitize_text_field($value);
}

// Pull a JSON object out of a model response that may include stray prose.
function ybme_ai_parse_json($text) {
    $data = json_decode($text, true);
    if (is_array($data)) {
        return $data;
    }
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

/**
 * Generate a meta title + description for a post. Returns
 * array('title' => ..., 'description' => ...) or a WP_Error.
 */
function ybme_ai_generate_meta($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('no_post', __('Post not found.', YBME_TEXT_DOMAIN));
    }

    $content = wp_strip_all_tags($post->post_content);
    $content = trim(preg_replace('/\s+/', ' ', $content));
    $content = function_exists('mb_substr') ? mb_substr($content, 0, 1500) : substr($content, 0, 1500);

    $prompt  = "You are an SEO copywriter. Write an SEO meta title and meta description for the web page below.\n";
    $prompt .= "Rules:\n";
    $prompt .= "- Meta title: at most 60 characters, compelling, include the main topic.\n";
    $prompt .= "- Meta description: 140-155 characters, active voice, encourages the click, no clickbait.\n";
    $prompt .= "- Write in the same language as the content.\n";
    $prompt .= "- Return ONLY a JSON object of the form {\"title\": \"...\", \"description\": \"...\"} with no extra text.\n\n";
    $prompt .= 'Page title: ' . $post->post_title . "\n";
    $prompt .= 'URL: ' . get_permalink($post_id) . "\n";
    $prompt .= "Content:\n" . $content . "\n";

    $text = ybme_ai_call($prompt);
    if (is_wp_error($text)) {
        return $text;
    }

    $data = ybme_ai_parse_json($text);
    if (!$data || (empty($data['title']) && empty($data['description']))) {
        return new WP_Error('parse', __('Could not read the AI response. Try again.', YBME_TEXT_DOMAIN));
    }

    return array(
        'title'       => isset($data['title']) ? ybme_ai_clean($data['title']) : '',
        'description' => isset($data['description']) ? ybme_ai_clean($data['description']) : '',
    );
}

// Dispatch a prompt to the configured provider. Returns text or WP_Error.
function ybme_ai_call($prompt) {
    if (!ybme_ai_configured()) {
        return new WP_Error('not_configured', __('AI is not configured.', YBME_TEXT_DOMAIN));
    }
    return ybme_ai_provider() === 'openai'
        ? ybme_ai_call_openai($prompt)
        : ybme_ai_call_claude($prompt);
}

function ybme_ai_call_claude($prompt) {
    $resp = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'timeout' => 45,
        'headers' => array(
            'x-api-key'         => ybme_ai_key(),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ),
        'body'    => wp_json_encode(array(
            'model'      => ybme_ai_model(),
            'max_tokens' => 400,
            'messages'   => array(array('role' => 'user', 'content' => $prompt)),
        )),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200) {
        $msg = isset($body['error']['message']) ? $body['error']['message'] : sprintf(__('API error (%d).', YBME_TEXT_DOMAIN), $code);
        return new WP_Error('api', $msg);
    }
    if (empty($body['content'][0]['text'])) {
        return new WP_Error('empty', __('Empty AI response.', YBME_TEXT_DOMAIN));
    }
    return $body['content'][0]['text'];
}

function ybme_ai_call_openai($prompt) {
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'timeout' => 45,
        'headers' => array(
            'Authorization' => 'Bearer ' . ybme_ai_key(),
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode(array(
            'model'       => ybme_ai_model(),
            'max_tokens'  => 400,
            'temperature' => 0.7,
            'messages'    => array(array('role' => 'user', 'content' => $prompt)),
        )),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200) {
        $msg = isset($body['error']['message']) ? $body['error']['message'] : sprintf(__('API error (%d).', YBME_TEXT_DOMAIN), $code);
        return new WP_Error('api', $msg);
    }
    if (empty($body['choices'][0]['message']['content'])) {
        return new WP_Error('empty', __('Empty AI response.', YBME_TEXT_DOMAIN));
    }
    return $body['choices'][0]['message']['content'];
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_ybme_ai_generate', 'ybme_ai_generate');
function ybme_ai_generate() {
    check_ajax_referer(YBME_NONCE_ACTION, 'nonce');
    if (!current_user_can(YBME_CAPABILITY)) {
        wp_send_json_error();
    }
    if (!ybme_ai_configured()) {
        wp_send_json_error(array('message' => __('AI is not configured.', YBME_TEXT_DOMAIN)));
    }
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error();
    }
    $res = ybme_ai_generate_meta($post_id);
    if (is_wp_error($res)) {
        wp_send_json_error(array('message' => $res->get_error_message()));
    }
    wp_send_json_success($res);
}

/* -------------------------------------------------------------------------
 * Editor integration
 * ---------------------------------------------------------------------- */

add_filter('ybme_available_columns', 'ybme_ai_register_column');
function ybme_ai_register_column($cols) {
    if (ybme_ai_configured()) {
        $cols['ai_tools'] = array('label' => __('AI', YBME_TEXT_DOMAIN));
    }
    return $cols;
}

add_filter('ybme_render_column_cell', 'ybme_ai_render_cell', 10, 4);
function ybme_ai_render_cell($cell, $col, $post_id, $permalink) {
    if ($col !== 'ai_tools') {
        return $cell;
    }
    return '<td><button type="button" class="button ybme-ai-generate">' . esc_html__('Generate', YBME_TEXT_DOMAIN) . '</button></td>';
}

add_action('ybme_editor_actions', 'ybme_ai_bulk_button');
function ybme_ai_bulk_button() {
    if (!ybme_ai_configured()) {
        return;
    }
    echo '<button type="button" id="ybme-ai-bulk" class="button" style="padding:10px 20px;margin-right:10px;">' . esc_html__('AI: fill problem rows', YBME_TEXT_DOMAIN) . '</button>';
}

add_filter('ybme_editor_js_vars', 'ybme_ai_js_vars');
function ybme_ai_js_vars($vars) {
    if (!ybme_ai_configured()) {
        return $vars;
    }
    $vars['ai_enabled'] = true;
    $vars['i18n']['ai_generate']    = __('Generate', YBME_TEXT_DOMAIN);
    $vars['i18n']['ai_generating']  = __('Generating…', YBME_TEXT_DOMAIN);
    $vars['i18n']['ai_done']        = __('Done', YBME_TEXT_DOMAIN);
    $vars['i18n']['ai_failed']      = __('AI generation failed', YBME_TEXT_DOMAIN);
    $vars['i18n']['ai_no_rows']     = __('No problem rows are visible.', YBME_TEXT_DOMAIN);
    $vars['i18n']['ai_bulk_confirm'] = __('Generate meta for up to %d visible problem rows? This calls the AI once per row.', YBME_TEXT_DOMAIN);
    $vars['i18n']['ai_bulk_done']   = __('Generated %d row(s). Review, then Save.', YBME_TEXT_DOMAIN);
    return $vars;
}

/* -------------------------------------------------------------------------
 * Settings page
 * ---------------------------------------------------------------------- */

// Priority 20 so the parent menu (registered at the default priority) exists.
add_action('admin_menu', 'ybme_ai_menu', 20);
function ybme_ai_menu() {
    add_submenu_page(
        'yoast-bulk-meta-editor',
        __('AI Assistant', YBME_TEXT_DOMAIN),
        __('AI Assistant', YBME_TEXT_DOMAIN),
        'manage_options',
        'yoast-bulk-meta-editor-ai',
        'ybme_ai_page'
    );
}

add_action('admin_init', 'ybme_ai_register_settings');
function ybme_ai_register_settings() {
    register_setting('ybme-ai-settings-group', 'ybme_ai_provider', array('sanitize_callback' => 'ybme_ai_sanitize_provider'));
    register_setting('ybme-ai-settings-group', 'ybme_ai_key', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('ybme-ai-settings-group', 'ybme_ai_model', array('sanitize_callback' => 'sanitize_text_field'));
}

function ybme_ai_sanitize_provider($value) {
    return in_array($value, array('claude', 'openai'), true) ? $value : 'claude';
}

function ybme_ai_page() {
    if (!current_user_can('manage_options')) {
        wp_die();
    }
    $provider = ybme_ai_provider();
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('AI Assistant', YBME_TEXT_DOMAIN) . '</h1>';
    echo '<p class="description">' . esc_html__('Generate meta titles and descriptions from page content. Your API key is stored on this site and page content is sent to the selected provider when you click Generate.', YBME_TEXT_DOMAIN) . '</p>';

    echo '<form method="post" action="options.php">';
    settings_fields('ybme-ai-settings-group');
    echo '<table class="form-table"><tbody>';

    echo '<tr><th scope="row"><label for="ybme_ai_provider">' . esc_html__('Provider', YBME_TEXT_DOMAIN) . '</label></th><td>';
    echo '<select id="ybme_ai_provider" name="ybme_ai_provider">';
    echo '<option value="claude" ' . selected($provider, 'claude', false) . '>' . esc_html__('Claude (Anthropic)', YBME_TEXT_DOMAIN) . '</option>';
    echo '<option value="openai" ' . selected($provider, 'openai', false) . '>' . esc_html__('OpenAI', YBME_TEXT_DOMAIN) . '</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="ybme_ai_key">' . esc_html__('API key', YBME_TEXT_DOMAIN) . '</label></th>';
    echo '<td><input type="password" class="regular-text" id="ybme_ai_key" name="ybme_ai_key" value="' . esc_attr(ybme_ai_key()) . '" autocomplete="off" /></td></tr>';

    echo '<tr><th scope="row"><label for="ybme_ai_model">' . esc_html__('Model', YBME_TEXT_DOMAIN) . '</label></th>';
    echo '<td><input type="text" class="regular-text" id="ybme_ai_model" name="ybme_ai_model" value="' . esc_attr((string) get_option('ybme_ai_model', '')) . '" placeholder="' . esc_attr(ybme_ai_default_model($provider)) . '" />';
    echo '<p class="description">' . sprintf(
        /* translators: 1: Claude default model, 2: OpenAI default model */
        esc_html__('Leave blank for the default (%1$s for Claude, %2$s for OpenAI).', YBME_TEXT_DOMAIN),
        esc_html(ybme_ai_default_model('claude')),
        esc_html(ybme_ai_default_model('openai'))
    ) . '</p></td></tr>';

    echo '</tbody></table>';
    submit_button();
    echo '</form>';

    if (ybme_ai_configured()) {
        echo '<p>' . esc_html__('Enable the "AI" column on the Settings page to show a Generate button per row.', YBME_TEXT_DOMAIN) . '</p>';
    }
    echo '</div>';
}
