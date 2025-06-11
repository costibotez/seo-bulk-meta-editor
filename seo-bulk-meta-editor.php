<?php
/**
 * Plugin Name: Yoast SEO Bulk Meta Editor
 * Description: Display & edit all meta titles, descriptions, and keywords from all posts, pages, and custom post types into one dashboard.
 * Version: 1.1.0
 * Plugin URI: https://nomad-developer.co.uk
 * Author: Nomad Developer
 * Author URI:  https://nomad-developer.co.uk
*/

define('YBME_POSTS_PER_PAGE', 50);


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

    // Call register settings function
    add_action('admin_init', 'register_yoast_bulk_meta_editor_settings');
}

// Page content
function yoast_bulk_meta_editor_page()
{
    $posts_per_page = YBME_POSTS_PER_PAGE;
    // Get all public post types
    $post_types = get_post_types(array('public' => true), 'names');
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

    echo '<thead><tr><th>Title</th><th>Post Type</th><th>Meta Title</th><th>Meta Description</th><th>Keyword</th></tr></thead><tbody>';
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
        $cat_slugs = wp_get_post_terms($post->ID, 'category', array('fields' => 'slugs'));
        $row  = '<tr data-post-id="' . $post->ID . '"';
        $row .= ' data-title="' . esc_attr(strtolower($page_title)) . '"';
        $row .= ' data-categories="' . esc_attr(implode(',', $cat_slugs)) . '"';
        $row .= ' data-post-type="' . esc_attr($post_type) . '"';
        $row .= '>'; 
        $row .= '<td><a href="' . $page_title_link . '">' . $page_title . '</a></td>';
        $row .= '<td>' . ucfirst($post_type) . '</td>';
        $row .= '<td class="editable" data-meta-key="_yoast_wpseo_title">' . $post_meta_title . '</td>';
        $row .= '<td class="editable" data-meta-key="_yoast_wpseo_metadesc">' . $post_meta_description . '</td>';
        $row .= '<td class="editable" data-meta-key="_yoast_wpseo_focuskw">' . $post_meta_keywords . '</td>';
        $row .= '</tr>';
        echo $row;
    }
    echo '</tbody>';
    echo '</table>';
    echo '<button id="load-more-btn" style="margin-top:20px;">Load More</button>';
    wp_reset_postdata();

    echo '<div style="text-align: center; margin-top: 20px;">';
    echo '<button id="save-btn" style="background-color: #4CAF50; color: white; padding: 10px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer;">Save Changes</button>';
    echo '<a href="https://www.buymeacoffee.com/costinbotez" target="_blank" style="background-color: #FF813F; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Support the plugin 🙌</a>';
    echo '</div>';
}

// Register our settings
function register_yoast_bulk_meta_editor_settings() {
    register_setting('yoast-bulk-meta-editor-settings-group', 'post_types');
}

// Create settings page
function yoast_bulk_meta_editor_settings_page() {

    // Get all public post types
    $all_post_types = get_post_types(array('public' => true), 'names');
    // Get selected post types
    $selected_post_types = get_option('post_types', ['post', 'page']);
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
        $cat_slugs = wp_get_post_terms($post->ID, 'category', array('fields' => 'slugs'));

        $row  = '<tr data-post-id="' . $post->ID . '"';
        $row .= ' data-title="' . esc_attr(strtolower($page_title)) . '"';
        $row .= ' data-categories="' . esc_attr(implode(',', $cat_slugs)) . '"';
        $row .= ' data-post-type="' . esc_attr($post_type) . '"';
        $row .= '>';
        $row .= '<td><a href="' . $page_title_link . '">' . $page_title . '</a></td>';
        $row .= '<td>' . ucfirst($post_type) . '</td>';
        $row .= '<td class="editable" data-meta-key="_yoast_wpseo_title">' . $post_meta_title . '</td>';
        $row .= '<td class="editable" data-meta-key="_yoast_wpseo_metadesc">' . $post_meta_description . '</td>';
        $row .= '<td class="editable" data-meta-key="_yoast_wpseo_focuskw">' . $post_meta_keywords . '</td>';
        $row .= '</tr>';
        echo $row;
    }
    wp_reset_postdata();
    wp_die();
}
