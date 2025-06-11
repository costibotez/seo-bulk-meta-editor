<?php
/**
 * Plugin Name: Yoast SEO Bulk Meta Editor
 * Description: Display & edit all meta titles, descriptions, and keywords from all posts, pages, and custom post types into one dashboard.
 * Version: 1.4.0
 * Plugin URI: https://nomad-developer.co.uk
 * Author: Nomad Developer
 * Author URI:  https://nomad-developer.co.uk
*/

define('YBME_POSTS_PER_PAGE', 50);

function ybme_get_available_columns() {
    return array(
        'title'            => array('label' => 'Title'),
        'post_type'        => array('label' => 'Post Type'),
        'meta_title'       => array('label' => 'Meta Title', 'meta_key' => '_yoast_wpseo_title'),
        'meta_description' => array('label' => 'Meta Description', 'meta_key' => '_yoast_wpseo_metadesc'),
        'keyword'          => array('label' => 'Keyword', 'meta_key' => '_yoast_wpseo_focuskw'),
        'canonical_url'    => array('label' => 'Canonical URL', 'meta_key' => '_yoast_wpseo_canonical'),
        'social_title'     => array('label' => 'Social Title', 'meta_key' => '_yoast_wpseo_opengraph-title'),
    );
}

function ybme_get_enabled_columns() {
    $defaults = array('title','post_type','meta_title','meta_description','keyword');
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
register_activation_hook(__FILE__, 'check_for_yoast_seo');

function check_for_yoast_seo()
{
    if (!is_plugin_active('wordpress-seo/wp-seo.php') && current_user_can('activate_plugins')) {
        // Stop activation redirect and show error
        wp_die('Sorry, but this plugin requires Yoast SEO to be installed and active. <br><a href="' . admin_url('plugins.php') . '">&laquo; Return to Plugins</a>');
    }
}
// Hook into the admin menu
add_action('admin_menu', 'yoast_bulk_meta_editor_create_menu');

// Create new top-level menu
function yoast_bulk_meta_editor_create_menu() {
    // Create new top-level menu
    add_menu_page('Yoast Bulk Meta Editor', 'Yoast Bulk Meta Editor', 'administrator', 'yoast-bulk-meta-editor', 'yoast_bulk_meta_editor_page' );

    // Create submenu for settings
    add_submenu_page('yoast-bulk-meta-editor', 'Yoast Bulk Meta Editor Settings', 'Settings', 'manage_options', 'yoast-bulk-meta-editor-settings', 'yoast_bulk_meta_editor_settings_page');

    // CSV import/export page (PRO)
    add_submenu_page('yoast-bulk-meta-editor', 'CSV Import/Export', 'CSV Import/Export (PRO)', 'manage_options', 'yoast-bulk-meta-editor-csv', 'ybme_csv_tools_page');

    // Call register settings function
    add_action('admin_init', 'register_yoast_bulk_meta_editor_settings');
}

// Page content
function yoast_bulk_meta_editor_page()
{
    $posts_per_page = YBME_POSTS_PER_PAGE;
    $enabled_columns = ybme_get_enabled_columns();
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

    echo '<h1 style="text-align: center;padding: 30px 0">Yoast Bulk Meta Editor</h1>';

    echo '<div class="filter-controls">';
    echo '<input type="text" id="search-box" placeholder="Search title..." />';
    echo '<select id="post-type-filter"><option value="">All Post Types</option>';
    foreach ($post_types as $type) {
        echo '<option value="' . esc_attr($type) . '">' . esc_html(ucfirst($type)) . '</option>';
    }
    echo '</select>';
    echo '<select id="category-filter" disabled="disabled"><option value="">All Categories</option>';
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
        'order'       => 'DESC',
    );
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
        $row .= '>'; 
        foreach ($enabled_columns as $col) {
            switch ($col) {
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
    echo '<button id="load-more-btn" style="margin-top:20px;">Load More</button>';
    wp_reset_postdata();

    echo '<div style="text-align: center; margin-top: 20px;">';
    echo '<button id="save-btn" style="background-color: #4CAF50; color: white; padding: 10px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer;">Save Changes</button>';
    echo '<button id="undo-btn" style="background-color: #777; color: white; padding: 10px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer;">Undo Last Change</button>';
    echo '<a href="https://www.buymeacoffee.com/costinbotez" target="_blank" style="background-color: #FF813F; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Support the plugin 🙌</a>';
    echo '</div>';
    echo '<ul id="history-log" style="margin-top: 20px;"></ul>';
}

// Register our settings
function register_yoast_bulk_meta_editor_settings() {
    register_setting('yoast-bulk-meta-editor-settings-group', 'post_types');
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_enabled_columns');
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_license_key');
    register_setting('yoast-bulk-meta-editor-settings-group', 'ybme_delete_on_blank');
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
        <h1>Yoast Bulk Meta Editor Settings</h1>

        <form method="post" action="options.php">
            <?php settings_fields('yoast-bulk-meta-editor-settings-group'); ?>
            <?php do_settings_sections('yoast-bulk-meta-editor-settings-group'); ?>

            <h2>Select post types:</h2>

            <?php
                foreach ($all_post_types as $post_type) {
                    echo '<input type="checkbox" id="' . $post_type . '" name="post_types[]" value="' . $post_type . '"' . (in_array($post_type, $selected_post_types) ? ' checked' : '') . '>';
                    echo '<label for="' . $post_type . '">' . ucfirst($post_type) . '</label><br>';
                }
            ?>

            <h2 style="margin-top:20px;">Select columns:</h2>
            <?php
                foreach ($all_columns as $key => $info) {
                    echo '<input type="checkbox" id="col_' . $key . '" name="ybme_enabled_columns[]" value="' . $key . '"' . (in_array($key, $selected_columns) ? ' checked' : '') . '>';
                    echo '<label for="col_' . $key . '">' . esc_html($info['label']) . '</label><br>';
                }
            ?>

            <h2 style="margin-top:20px;">License Key (PRO):</h2>
            <?php
                $license = esc_attr(get_option('ybme_license_key', ''));
                echo '<input type="text" style="width:300px;" name="ybme_license_key" value="' . $license . '" placeholder="Enter license key" />';
            ?>
            <h2 style="margin-top:20px;">Import Options:</h2>
            <?php
                $del = get_option('ybme_delete_on_blank', 0);
                echo '<label><input type="checkbox" name="ybme_delete_on_blank" value="1"' . checked(1, $del, false) . '> Delete meta values when CSV cells are blank</label>';
            ?>
            <div class="ybme-upsell">
                <p><strong>Bulk-edit metadata in seconds</strong></p>
                <p><strong>Offline backups you can trust</strong></p>
                <p><strong>Boost your WP workflow</strong></p>
                <p class="ybme-upsell-subtext"><strong>Why go PRO?</strong><br/>Unlock CSV import/export and secure backups for rapid SEO edits.</p>
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
        wp_localize_script('bulk-meta-editor', 'bulk_editor_vars', array(
            'posts_per_page' => YBME_POSTS_PER_PAGE,
        ));

        // Enqueue our custom styles
        wp_enqueue_style('bulk-meta-editor', plugins_url('/css/style.css', __FILE__), array(), '1.0', 'all');
    }
}

add_action('wp_ajax_save_meta_info', 'save_meta_info');
function save_meta_info()
{
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
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
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $enabled_columns = ybme_get_enabled_columns();
    $args = array(
        'numberposts' => YBME_POSTS_PER_PAGE,
        'offset'      => $offset,
        'post_type'   => get_option('post_types', ['post', 'page']),
        'post_status' => 'publish',
        'orderby'     => 'post_type',
        'order'       => 'DESC',
    );
    $posts = get_posts($args);
    foreach ($posts as $post) {
        $page_title = get_the_title($post->ID);
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
        $row .= '>';
        foreach ($enabled_columns as $col) {
            switch ($col) {
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

function ybme_csv_tools_page() {
    echo '<div class="wrap"><h1>CSV Import/Export</h1>';

    if (!ybme_is_pro()) {
        echo '<p>This feature is available in the PRO version. Enter your license key in Settings to enable it.</p></div>';
        return;
    }

    if (isset($_POST['ybme_export_csv'])) {
        ybme_export_csv();
        exit;
    }

    if (isset($_POST['ybme_import_csv']) && !empty($_FILES['ybme_csv_file']['tmp_name'])) {
        ybme_import_csv($_FILES['ybme_csv_file']);
        echo '<div class="updated notice"><p>Import completed.</p></div>';
    }

    ?>
    <form method="post">
        <input type="hidden" name="ybme_export_csv" value="1" />
        <?php submit_button('Export CSV'); ?>
    </form>
    <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
        <input type="file" name="ybme_csv_file" accept=".csv" required />
        <?php submit_button('Import CSV'); ?>
    </form>
    </div>
    <?php
}

function ybme_export_csv() {
    global $wpdb;

    $posts = get_posts([
        'numberposts' => -1,
        'post_type'   => get_option('post_types', ['post','page']),
        'post_status' => 'any',
        'orderby'     => 'ID',
        'order'       => 'ASC'
    ]);

    if (empty($posts)) {
        return;
    }

    $post_ids = wp_list_pluck($posts, 'ID');
    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $meta_keys = [];
    if ($placeholders) {
        $query = $wpdb->prepare("SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)", $post_ids);
        $meta_keys = $wpdb->get_col($query);
    }
    sort($meta_keys);

    $headers = [
        'post_id',
        'post_type',
        'post_title',
        'post_slug',
        'post_status',
        'post_date',
        'post_modified'
    ];
    foreach ($meta_keys as $k) {
        $headers[] = 'meta_' . $k;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ybme-export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);

    foreach ($posts as $p) {
        $row = [
            $p->ID,
            $p->post_type,
            $p->post_title,
            $p->post_name,
            $p->post_status,
            mysql2date('Y-m-d H:i:s', $p->post_date, false),
            mysql2date('Y-m-d H:i:s', $p->post_modified, false)
        ];

        foreach ($meta_keys as $k) {
            $val = get_post_meta($p->ID, $k, true);
            if (is_array($val)) {
                $val = implode('|', $val);
            }
            $row[] = $val;
        }
        fputcsv($out, $row);
    }
    fclose($out);
}

function ybme_import_csv($file) {
    $delete = get_option('ybme_delete_on_blank', 0);

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        return;
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return;
    }

    $meta_cols = [];
    foreach ($header as $index => $name) {
        if (strpos($name, 'meta_') === 0) {
            $meta_cols[$index] = substr($name, 5);
        }
    }
    $post_index = array_search('post_id', $header);

    while (($row = fgetcsv($handle)) !== false) {
        $post_id = ($post_index !== false && isset($row[$post_index])) ? intval($row[$post_index]) : 0;
        if (!$post_id) {
            continue;
        }

        foreach ($meta_cols as $index => $meta_key) {
            if (!isset($row[$index])) {
                continue;
            }
            $val = $row[$index];
            if ($val === '') {
                if ($delete) {
                    delete_post_meta($post_id, $meta_key);
                }
                continue;
            }
            if (strpos($val, '|') !== false) {
                $val = array_map('sanitize_text_field', explode('|', $val));
            } else {
                $val = sanitize_text_field($val);
            }
            update_post_meta($post_id, $meta_key, $val);
        }
    }
    fclose($handle);
}
