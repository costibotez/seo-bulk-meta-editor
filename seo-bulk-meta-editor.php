<?php
/**
 * Plugin Name: Yoast SEO Bulk Meta Editor
 * Description: Display & edit all meta titles, descriptions, and keywords from all posts, pages, and custom post types into one dashboard.
 * Version: 1.5.0
 * Plugin URI: https://nomad-developer.co.uk
 * Author: Nomad Developer
 * Author URI:  https://nomad-developer.co.uk
 * Text Domain: seo-bulk-meta-editor
 * Domain Path: /languages
*/

// Default number of rows shown in the table when no setting is saved.
define('YBME_POSTS_PER_PAGE', 20);

define('YBME_TEXT_DOMAIN', 'seo-bulk-meta-editor');

function ybme_load_textdomain() {
    load_plugin_textdomain(YBME_TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'ybme_load_textdomain');

define('YBME_CAPABILITY', 'manage_ybme_meta');

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

function ybme_get_available_columns() {
    $cols = array(
        'title'            => array('label' => __('Title', YBME_TEXT_DOMAIN)),
        'post_type'        => array('label' => __('Post Type', YBME_TEXT_DOMAIN)),
        'meta_title'       => array('label' => __('Meta Title', YBME_TEXT_DOMAIN), 'meta_key' => '_yoast_wpseo_title'),
        'meta_description' => array('label' => __('Meta Description', YBME_TEXT_DOMAIN), 'meta_key' => '_yoast_wpseo_metadesc'),
        'keyword'          => array('label' => __('Keyword', YBME_TEXT_DOMAIN), 'meta_key' => '_yoast_wpseo_focuskw'),
        'canonical_url'    => array('label' => __('Canonical URL', YBME_TEXT_DOMAIN), 'meta_key' => '_yoast_wpseo_canonical'),
        'social_title'     => array('label' => __('Social Title', YBME_TEXT_DOMAIN), 'meta_key' => '_yoast_wpseo_opengraph-title'),
    );
    if (ybme_wpml_active()) {
        $cols = array_merge(array('language_flag' => array('label' => __('Language', YBME_TEXT_DOMAIN))), $cols);
    }
    return $cols;
}

function ybme_get_enabled_columns() {
    $defaults = array('title','post_type','meta_title','meta_description','keyword');
    if (ybme_wpml_active()) {
        array_unshift($defaults, 'language_flag');
    }
    $saved = get_option('ybme_enabled_columns');
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_unique(array_merge($defaults, $saved));
}

function ybme_is_pro() {
    $key = trim(get_option('ybme_license_key'));
    return !empty($key);
}


// Check for Yoast SEO plugin on activation
register_activation_hook(__FILE__, 'ybme_activate');
register_deactivation_hook(__FILE__, 'ybme_deactivate');

function check_for_yoast_seo()
{
    if (!is_plugin_active('wordpress-seo/wp-seo.php') && current_user_can('activate_plugins')) {
        // Stop activation redirect and show error
        wp_die(sprintf(
            /* translators: %s: plugins admin url */
            __('Sorry, but this plugin requires Yoast SEO to be installed and active. <br><a href="%s">&laquo; Return to Plugins</a>', YBME_TEXT_DOMAIN),
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
}

function ybme_deactivate() {
    ybme_apply_role_capabilities(array());
}

add_action("update_option_ybme_roles", function($old, $new) { ybme_apply_role_capabilities($new); }, 10, 2);

// Hook into the admin menu
add_action('admin_menu', 'yoast_bulk_meta_editor_create_menu');

// Create new top-level menu
function yoast_bulk_meta_editor_create_menu() {
    // Create new top-level menu
    add_menu_page(__('Yoast Bulk Meta Editor', YBME_TEXT_DOMAIN), __('Yoast Bulk Meta Editor', YBME_TEXT_DOMAIN), YBME_CAPABILITY, 'yoast-bulk-meta-editor', 'yoast_bulk_meta_editor_page' );

    // Create submenu for settings
    add_submenu_page('yoast-bulk-meta-editor', __('Yoast Bulk Meta Editor Settings', YBME_TEXT_DOMAIN), __('Settings', YBME_TEXT_DOMAIN), 'manage_options', 'yoast-bulk-meta-editor-settings', 'yoast_bulk_meta_editor_settings_page');


    // Call register settings function
    add_action('admin_init', 'register_yoast_bulk_meta_editor_settings');
}

// Page content
function yoast_bulk_meta_editor_page()
{
    if (!current_user_can(YBME_CAPABILITY)) { wp_die(); }
    $posts_per_page = intval(get_option('ybme_rows_per_page', YBME_POSTS_PER_PAGE));
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
        $page_title = get_the_title($post->ID);
        $post_lang = '';
        if (ybme_wpml_active()) {
            $info = apply_filters('wpml_post_language_details', null, $post->ID);
            if (isset($info['language_code'])) {
                $post_lang = $info['language_code'];
            }
            if (!empty($selected_langs) && $post_lang !== '' && !in_array($post_lang, $selected_langs)) {
                continue;
            }
        }
        $page_title_link = get_edit_post_link($post->ID);
        $post_type = get_post_type($post->ID);
        $post_meta_description = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
        // Fetch the Yoast focus keyphrase for the post.
        // Yoast stores the focus keyphrase in the _yoast_wpseo_focuskw meta key.
        // Previous versions of this plugin attempted to read the value from
        // `_yoast_wpseo_focuskw_text_input`, which no longer exists in recent
        // versions of Yoast SEO and resulted in keywords not appearing in the
        // table. Using the correct meta key ensures that the current value is
        // displayed and can be updated properly.
        $post_meta_keywords = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true);
        $post_meta_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        $canonical = get_post_meta($post->ID, '_yoast_wpseo_canonical', true);
        $social_title = get_post_meta($post->ID, '_yoast_wpseo_opengraph-title', true);
        $cat_slugs = wp_get_post_terms($post->ID, 'category', array('fields' => 'slugs'));
        $row  = '<tr data-post-id="' . $post->ID . '"';
        $row .= ' data-title="' . esc_attr(strtolower($page_title)) . '"';
        $row .= ' data-categories="' . esc_attr(implode(',', $cat_slugs)) . '"';
        $row .= ' data-post-type="' . esc_attr($post_type) . '"';
        if ($post_lang) { $row .= ' data-language="' . esc_attr($post_lang) . '"'; }
        $row .= '>';
        foreach ($enabled_columns as $col) {
            switch ($col) {
                case 'language_flag':
                    $flag = isset($lang_flags[$post_lang]) ? $lang_flags[$post_lang] : '';
                    $row .= '<td>' . ($flag ? '<img src="' . esc_url($flag) . '" alt="" style="width:18px;" />' : esc_html($post_lang)) . '</td>';
                    break;
                case 'title':
                    $row .= '<td><a href="' . $page_title_link . '">' . $page_title . '</a></td>';
                    break;
                case 'post_type':
                    $row .= '<td>' . ucfirst($post_type) . '</td>';
                    break;
                case 'meta_title':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_title">' . $post_meta_title . '</td>';
                    break;
                case 'meta_description':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_metadesc">' . $post_meta_description . '</td>';
                    break;
                case 'keyword':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_focuskw">' . $post_meta_keywords . '</td>';
                    break;
                case 'canonical_url':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_canonical">' . $canonical . '</td>';
                    break;
                case 'social_title':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_opengraph-title">' . $social_title . '</td>';
                    break;
            }
        }
        $row .= '</tr>';
        echo $row;
    }
    echo '</tbody>';
    echo '</table>';
    echo '<button id="load-more-btn" style="margin-top:20px;">' . esc_html__('Load More', YBME_TEXT_DOMAIN) . '</button>';
    wp_reset_postdata();

    echo '<div style="text-align: center; margin-top: 20px;">';
    echo '<button id="save-btn" style="background-color: #4CAF50; color: white; padding: 10px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer;">' . esc_html__('Save Changes', YBME_TEXT_DOMAIN) . '</button>';
    echo '<button id="undo-btn" style="background-color: #777; color: white; padding: 10px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer;">' . esc_html__('Undo Last Change', YBME_TEXT_DOMAIN) . '</button>';
    echo '<a href="https://www.buymeacoffee.com/costinbotez" target="_blank" style="background-color: #FF813F; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">' . esc_html__('Support the plugin 🙌', YBME_TEXT_DOMAIN) . '</a>';
    echo '</div>';
    echo '<ul id="history-log" style="margin-top: 20px;"></ul>';
}

// Register our settings
function register_yoast_bulk_meta_editor_settings() {
    register_setting('yoast-bulk-meta-editor-settings-group', 'post_types');
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_enabled_columns');
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_license_key');
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_roles');
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_rows_per_page');
    if (ybme_wpml_active()) {
        register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_languages');
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

            <h2><?php echo esc_html__('Select post types:', YBME_TEXT_DOMAIN); ?></h2>

            <?php
                foreach ($all_post_types as $post_type) {
                    echo '<input type="checkbox" id="' . $post_type . '" name="post_types[]" value="' . $post_type . '"' . (in_array($post_type, $selected_post_types) ? ' checked' : '') . '>';
                    echo '<label for="' . $post_type . '">' . ucfirst($post_type) . '</label><br>';
                }
            ?>

            <h2 style="margin-top:20px;"><?php echo esc_html__('Select columns:', YBME_TEXT_DOMAIN); ?></h2>
            <?php
                foreach ($all_columns as $key => $info) {
                    echo '<input type="checkbox" id="col_' . $key . '" name="ybme_enabled_columns[]" value="' . $key . '"' . (in_array($key, $selected_columns) ? ' checked' : '') . '>';
                    echo '<label for="col_' . $key . '">' . esc_html($info['label']) . '</label><br>';
                }
            ?>

            <?php if (ybme_wpml_active()) : ?>
                <?php $langs = ybme_get_wpml_languages(); $sel_langs = get_option('ybme_languages', array()); ?>
                <h2 style="margin-top:20px;">
                    <?php echo esc_html__('Select languages:', YBME_TEXT_DOMAIN); ?>
                </h2>
                <p><?php echo sprintf(esc_html__('%d languages detected', YBME_TEXT_DOMAIN), count($langs)); ?></p>
                <?php foreach ($langs as $code => $lang) {
                    echo '<input type="checkbox" name="ybme_languages[]" value="' . esc_attr($code) . '"' . (in_array($code, $sel_langs) ? ' checked' : '') . '>'; 
                    echo '<label>' . esc_html($lang['native_name']) . '</label><br>'; 
                } ?>
            <?php endif; ?>

            <h2 style="margin-top:20px;"><?php echo esc_html__('Rows per page:', YBME_TEXT_DOMAIN); ?></h2>
            <?php
                $rows = intval(get_option('ybme_rows_per_page', YBME_POSTS_PER_PAGE));
                echo '<input type="number" min="1" style="width:60px;" name="ybme_rows_per_page" value="' . $rows . '" />';
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
function enqueue_admin_scripts()
{
    // Only add on the Yoast Bulk Meta Editor page
    if (isset($_GET['page']) && $_GET['page'] == 'yoast-bulk-meta-editor') {
        // Enqueue jQuery UI sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue tablesorter
        wp_enqueue_script('jquery-tablesorter', plugins_url('/js/jquery.tablesorter.min.js', __FILE__), array('jquery'), '1.0', true);

        // Enqueue our custom script
        wp_enqueue_script('bulk-meta-editor', plugins_url('/js/bulk-meta-editor.js', __FILE__), array('jquery', 'jquery-tablesorter', 'jquery-ui-sortable'), '1.0', true);
        $rows = intval(get_option('ybme_rows_per_page', YBME_POSTS_PER_PAGE));
        wp_localize_script('bulk-meta-editor', 'bulk_editor_vars', array(
            'posts_per_page' => $rows,
            'i18n' => array(
                'meta_updated'    => __('Meta info updated successfully', YBME_TEXT_DOMAIN),
                'update_failed'   => __('Failed to update meta info', YBME_TEXT_DOMAIN),
                'nothing_to_undo' => __('Nothing to undo', YBME_TEXT_DOMAIN),
                'change_reverted' => __('Change reverted', YBME_TEXT_DOMAIN),
                'revert_failed'   => __('Failed to revert change', YBME_TEXT_DOMAIN),
                'load_failed'     => __('Failed to load more posts', YBME_TEXT_DOMAIN),
                'label_title'     => __('Title', YBME_TEXT_DOMAIN),
                'label_meta_description' => __('Meta Description', YBME_TEXT_DOMAIN),
                'label_keyword'   => __('Keyword', YBME_TEXT_DOMAIN),
                'label_canonical_url' => __('Canonical URL', YBME_TEXT_DOMAIN),
                'label_social_title' => __('Social Title', YBME_TEXT_DOMAIN),
            ),
        ));

        // Enqueue our custom styles
        wp_enqueue_style('bulk-meta-editor', plugins_url('/css/style.css', __FILE__), array(), '1.0', 'all');
    }
}

add_action('wp_ajax_save_meta_info', 'save_meta_info');
function save_meta_info()
{
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!current_user_can(YBME_CAPABILITY)) { wp_send_json_error(); wp_die(); }
    $meta_key = isset($_POST['meta_key']) ? sanitize_text_field($_POST['meta_key']) : '';
    $meta_value = isset($_POST['meta_value']) ? sanitize_text_field($_POST['meta_value']) : '';


    if ($post_id > 0 && !empty($meta_key)) {
        update_post_meta($post_id, $meta_key, $meta_value);
        wp_send_json_success(); // Send a JSON response indicating success
    } else {
        wp_send_json_error(); // Send a JSON response indicating error
    }

    wp_die(); // All ajax handlers die when finished
}

add_action('wp_ajax_load_more_posts', 'yoast_bulk_meta_editor_load_more_posts');
function yoast_bulk_meta_editor_load_more_posts()
{
    if (!current_user_can(YBME_CAPABILITY)) { wp_die(); }
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $enabled_columns = ybme_get_enabled_columns();
    $rows = intval(get_option('ybme_rows_per_page', YBME_POSTS_PER_PAGE));
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
        $page_title = get_the_title($post->ID);
        $post_lang = '';
        if (ybme_wpml_active()) {
            $info = apply_filters('wpml_post_language_details', null, $post->ID);
            if (isset($info['language_code'])) {
                $post_lang = $info['language_code'];
            }
            if (!empty($selected_langs) && $post_lang !== '' && !in_array($post_lang, $selected_langs)) {
                continue;
            }
        }
        $page_title_link = get_edit_post_link($post->ID);
        $post_type = get_post_type($post->ID);
        $post_meta_description = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
        $post_meta_keywords = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true);
        $post_meta_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        $canonical = get_post_meta($post->ID, '_yoast_wpseo_canonical', true);
        $social_title = get_post_meta($post->ID, '_yoast_wpseo_opengraph-title', true);
        $cat_slugs = wp_get_post_terms($post->ID, 'category', array('fields' => 'slugs'));

        $row  = '<tr data-post-id="' . $post->ID . '"';
        $row .= ' data-title="' . esc_attr(strtolower($page_title)) . '"';
        $row .= ' data-categories="' . esc_attr(implode(',', $cat_slugs)) . '"';
        $row .= ' data-post-type="' . esc_attr($post_type) . '"';
        if ($post_lang) { $row .= ' data-language="' . esc_attr($post_lang) . '"'; }
        $row .= '>';
        foreach ($enabled_columns as $col) {
            switch ($col) {
                case 'language_flag':
                    $flag = isset($lang_flags[$post_lang]) ? $lang_flags[$post_lang] : '';
                    $row .= '<td>' . ($flag ? '<img src="' . esc_url($flag) . '" alt="" style="width:18px;" />' : esc_html($post_lang)) . '</td>';
                    break;
                case 'title':
                    $row .= '<td><a href="' . $page_title_link . '">' . $page_title . '</a></td>';
                    break;
                case 'post_type':
                    $row .= '<td>' . ucfirst($post_type) . '</td>';
                    break;
                case 'meta_title':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_title">' . $post_meta_title . '</td>';
                    break;
                case 'meta_description':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_metadesc">' . $post_meta_description . '</td>';
                    break;
                case 'keyword':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_focuskw">' . $post_meta_keywords . '</td>';
                    break;
                case 'canonical_url':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_canonical">' . $canonical . '</td>';
                    break;
                case 'social_title':
                    $row .= '<td class="editable" data-meta-key="_yoast_wpseo_opengraph-title">' . $social_title . '</td>';
                    break;
            }
        }
        $row .= '</tr>';
        echo $row;
    }
    wp_reset_postdata();
    wp_die();
}

