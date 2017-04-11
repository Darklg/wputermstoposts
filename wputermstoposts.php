<?php

/*
Plugin Name: WPU Terms to Posts
Description: Link terms to posts from the term edit page.
Version: 0.4.0
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUTermsToPosts {
    private $query;
    private $taxonomies;
    private $version = '0.4.0';
    private $order_list = array('DESC', 'ASC');
    private $orderby_list = array('post_date', 'post_title', 'post_status', 'ID');

    public function __construct() {
        add_action('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        add_action('init', array(&$this,
            'init'
        ));
        add_action('admin_post_change_taxonomy', array(&$this,
            'change_taxonomy'
        ));
    }

    public function plugins_loaded() {
        load_plugin_textdomain('wputermstoposts', false, dirname(plugin_basename(__FILE__)) . '/lang/');
    }

    public function init() {
        $this->taxonomies = apply_filters('wputtp_taxonomies', array(
            'category' => array(
                'post_types' => array('post')
            )
        ));

        add_action('admin_enqueue_scripts', array(&$this, 'load_assets'));

        foreach ($this->taxonomies as $id => $taxonomy) {

            /* Default post types */
            if (!isset($taxonomy['post_types'])) {
                $this->taxonomies[$id]['post_types'] = array('post');
            }
            /* Default post types */
            if (!isset($taxonomy['fields'])) {
                $this->taxonomies[$id]['fields'] = array(
                    'post_status' => array(
                        'name' => __('Status', 'wputermstoposts')
                    ),
                    'post_type' => array(
                        'name' => __('Post type', 'wputermstoposts')
                    ),
                    'post_date' => array(
                        'name' => __('Date', 'wputermstoposts')
                    )
                );
            }

            /* Insert before form */
            add_action($id . '_pre_edit_form', array(&$this,
                'linked_posts_before_form'
            ));
        }
    }

    /* ----------------------------------------------------------
      Load assets
    ---------------------------------------------------------- */

    public function load_assets($hook) {
        if ('term.php' != $hook) {
            return;
        }

        wp_enqueue_script('wputermstoposts_scripts', plugins_url('assets/script.js', __FILE__), array('jquery'), $this->version);
        wp_enqueue_style('wputermstoposts_style', plugins_url('assets/style.css', __FILE__), false, $this->version);
    }

    /* ----------------------------------------------------------
      Save changes
    ---------------------------------------------------------- */

    /**
     * For each post, add or remove the given term
     */
    public function change_taxonomy() {

        /* Check nonce */
        if (!isset($_POST['wputtp_noncename']) || !wp_verify_nonce($_POST['wputtp_noncename'], plugin_basename(__FILE__))) {
            wp_safe_redirect(wp_get_referer());
            die;
        }

        /* Check term & taxonomy */
        if (!isset($_REQUEST['term'], $_REQUEST['taxonomy']) || !is_numeric($_REQUEST['term'])) {
            wp_safe_redirect(wp_get_referer());
            die;
        }

        $term = get_term_by('id', $_REQUEST['term'], $_REQUEST['taxonomy']);
        $taxonomy = get_taxonomy($term->taxonomy);

        if (!is_object($term) || !is_object($taxonomy) || !current_user_can($taxonomy->cap->manage_terms)) {
            wp_safe_redirect(wp_get_referer());
            die;
        }

        /* Get results */

        $ids_ok = $_REQUEST['wputtp_results'];
        $initial_ids = $_REQUEST['wputtp_values'];

        foreach ($initial_ids as $post_id => $item_in_category) {

            /* The post was checked but didn't have this term */
            if (array_key_exists($post_id, $ids_ok) && !$item_in_category) {
                /* Append term */
                wp_set_object_terms($post_id, $term->term_id, $term->taxonomy, true);
            }

            /* The post was not checked but had this term */
            if (!array_key_exists($post_id, $ids_ok) && $item_in_category) {
                $terms = wp_get_post_terms($post_id, $term->taxonomy);
                $terms_ids = array();
                /* Keep all terms but the current one */
                foreach ($terms as $tmp_term) {
                    if ($term->term_id != $tmp_term->term_id) {
                        $terms_ids[] = $tmp_term->term_id;
                    }
                }
                wp_set_object_terms($post_id, $terms_ids, $term->taxonomy, false);
            }
        }

        wp_safe_redirect(wp_get_referer() . '&wputermstoposts_success=1');
        die;
    }

    /* ----------------------------------------------------------
      Display form
    ---------------------------------------------------------- */

    /**
     * Display a list of posts before the edit term form
     * @param  object $term  WordPress Term Object
     */
    public function linked_posts_before_form($term) {

        if (!array_key_exists($term->taxonomy, $this->taxonomies)) {
            return;
        }
        $tax_details = $this->taxonomies[$term->taxonomy];

        $opts = array(
            'orderby' => isset($_GET['orderby']) && in_array($_GET['orderby'], $this->orderby_list) ? $_GET['orderby'] : $this->orderby_list[0],
            'order' => isset($_GET['order']) && in_array($_GET['order'], $this->order_list) ? $_GET['order'] : $this->order_list[0]
        );

        $field_title = array(
            'post_title' => array('name' => __('Post title', 'wputermstoposts'))
        );

        $current_page_url = 'term.php?tag_ID=' . esc_html($_GET['tag_ID']);
        $current_page_url .= '&taxonomy=' . esc_html($_GET['taxonomy']);

        $filtered_results = $this->get_filtered_results($term, $opts);

        /* Open wrap */
        echo '<div class="wrap">';
        echo '<h1>' . __('Linked posts', 'wputermstoposts') . '</h1>';

        if (isset($_GET['wputermstoposts_success']) && $_GET['wputermstoposts_success'] == '1') {
            echo '<div id="message" class="updated"><p>' . __('Linked posts were successfully updated', 'wputermstoposts') . '</p></div>';
        }

        echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
        echo '<div class="wputermstoposts_table_wrap">';
        echo '<table id="wputermstoposts_table" class="wp-list-table widefat striped">';

        /* Heading */
        echo '<thead>';
        echo '<tr><th></th>';

        $tax_details['fields'] = array_merge($field_title, $tax_details['fields']);
        foreach ($tax_details['fields'] as $id => $field) {
            if (!isset($field['name'])) {
                $field['name'] = $id;
            }
            $_field_name = $field['name'];
            if (in_array($id, $this->orderby_list)) {
                $_field_content = $_field_name;
                if ($opts['orderby'] == $id) {
                    $_field_content .= ' ' . ($opts['order'] == 'ASC' ? '▴' : '▾');
                }
                $_field_order = admin_url($current_page_url . '&orderby=' . $id . '&order=' . ($opts['order'] == $this->order_list[0] ? $this->order_list[1] : $this->order_list[0]));
                $_field_name = '<a href="' . $_field_order . '">' . $_field_content . '</a>';
            }
            echo '<th>' . $_field_name . '</th>';
        }
        echo '</tr>';
        echo '</thead>';
        unset($tax_details['fields']['post_title']);

        /* Results */
        echo '<tbody>';
        foreach ($filtered_results as $result) {
            echo '<tr data-line="' . esc_attr(strtolower($result['post_title'])) . '">';
            echo '<td>';
            echo '<input type="hidden" name="wputtp_values[' . $result['ID'] . ']"  value="' . ($result['term_taxonomy_id'] == $term->term_id ? '1' : '0') . '" />';
            echo '<input type="checkbox" name="wputtp_results[' . $result['ID'] . ']" id="wputtp_result_' . $result['ID'] . '" ' . checked($result['term_taxonomy_id'], $term->term_id, false) . ' value="" />';
            echo '</td>';
            echo '<td><label for="wputtp_result_' . $result['ID'] . '">' . $result['post_title'] . '</label></td>';
            foreach ($tax_details['fields'] as $id => $field) {
                echo '<td>' . (isset($result[$id]) ? $result[$id] : '-') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody>';

        /* Close wrap */
        echo '</table>';
        echo '</div>';
        echo '<div class="wputermstoposts_filter"><label for="wputermstoposts_s">' . __('Filter:', 'wputermstoposts') . '</label> <input id="wputermstoposts_s" type="text" value="" /></div>';
        wp_nonce_field(plugin_basename(__FILE__), 'wputtp_noncename');
        echo '<input type="hidden" name="action" value="change_taxonomy" />';
        echo '<input type="hidden" name="term" value="' . $term->term_id . '">';
        echo '<input type="hidden" name="taxonomy" value="' . $term->taxonomy . '">';
        submit_button(__('Save', 'wputermstoposts'));
        echo '</form>';
        echo '</div>';

        /* Garbage collect */
        unset($filtered_results);
    }

    /* ----------------------------------------------------------
      Internal API
    ---------------------------------------------------------- */

    /**
     * Get a list of posts
     * @param  object  $term WordPress term object
     * @param  array   $opts  array of options
     * @return array          list of posts
     */
    private function get_filtered_results($term = false, $opts = array()) {
        global $wpdb;

        if (!is_array($opts)) {
            $opts = array();
        }

        /* Get details for this taxonomy */
        if (!$term || !array_key_exists($term->taxonomy, $this->taxonomies)) {
            return array();
        }
        $tax_details = $this->taxonomies[$term->taxonomy];
        $custom_fields = '';
        if (isset($tax_details['fields'])) {
            foreach ($tax_details['fields'] as $field_id => $field) {
                $custom_fields .= 'p.' . $field_id . ',';
            }
        }

        /* Chosen fields */
        $fields = 'p.ID, p.post_title, ' . $custom_fields . ' t.term_taxonomy_id';
        if (isset($opts['only_ids'])) {
            $fields = 'p.ID, t.term_taxonomy_id';
        }

        /* Order */
        $orderby = isset($opts['orderby']) && in_array($opts['orderby'], $this->orderby_list) ? $opts['orderby'] : $this->orderby_list[0];
        $order = isset($opts['order']) && in_array($opts['order'], $this->order_list) ? $opts['order'] : $this->order_list[0];

        /* Get results */
        $query = "SELECT {$fields}
        FROM wp_posts p
        LEFT JOIN wp_term_relationships t
        ON p.ID = t.object_id
        WHERE 1=1
        AND p.post_status NOT in('inherit','auto-draft')";
        $query .= " AND post_type IN('" . implode("','", $tax_details['post_types']) . "')";
        $query .= " ORDER BY p." . $orderby . " " . $order;

        $results = $wpdb->get_results($query, ARRAY_A);

        /* De-duplicate listing and keep only infos about current categories if duplicate */
        $filtered_results = array();
        foreach ($results as $id => $result) {
            if (isset($filtered_results[$result['ID']]) && $filtered_results[$result['ID']]['term_taxonomy_id'] == $term->term_id) {
                $result['term_taxonomy_id'] = $term->term_id;
            }
            $filtered_results[$result['ID']] = $result;
        }

        /* Garbage collect */
        unset($results);

        return $filtered_results;
    }
}

$WPUTermsToPosts = new WPUTermsToPosts();
