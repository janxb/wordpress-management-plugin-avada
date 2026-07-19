<?php
/**
 * Plugin Name: brodda.IT
 * Author: Jan Brodda / brodda.IT
 * Version: 16
 */

defined('ABSPATH') or die();

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/brute-force-protection.php';
require_once __DIR__ . '/custom-user-roles.php';
require_once __DIR__ . '/ics-shortcode.php';

class BroddaITPlugin
{
    public function __construct()
    {
        $this->update_check();
        $this->keep_plugin_active();
        $this->disable_comments();
        $this->disable_blog();
        $this->force_auto_updates();
        $this->remove_all_dashboard_widgets();
        $this->disable_gutenberg_editor();
        $this->disable_user_avatars();
        $this->random_upload_filenames();
        $this->create_settings_page();
        $this->allow_svg_uploads();
        $this->custom_backend_scripts();
        $this->init_old_posts_cleanup();
        $this->remove_ai_connectors();
        $this->disable_rest_api();
        $this->disable_xmlrpc_api();
        $this->disable_user_enumeration();
        $this->hide_login_errors();
    }

    public function hide_login_errors(): void
    {
        add_filter('wp_login_errors', function (WP_Error $errors): WP_Error {
            $login_action = isset($_REQUEST['action'])
                    ? sanitize_key((string)wp_unslash($_REQUEST['action']))
                    : 'login';

            if ($login_action !== 'login') {
                return $errors;
            }

            $credential_error_codes = [
                    'invalid_username',
                    'invalid_email',
                    'incorrect_password',
                    'authentication_failed',
            ];

            if (!array_intersect($credential_error_codes, $errors->get_error_codes())) {
                return $errors;
            }

            foreach ($credential_error_codes as $error_code) {
                $errors->remove($error_code);
            }

            $errors->add(
                    'incorrect_password',
                    '<strong>Error:</strong> The password you entered is incorrect.'
            );

            return $errors;
        });
    }

    public function disable_user_enumeration(): void
    {
        add_action('init', function () {
            if (is_admin()) {
                return;
            }

            if (isset($_REQUEST['author'])) {
                wp_safe_redirect(get_home_url(), 301);
                exit;
            }
        });
        add_action('template_redirect', function () {
            if (is_author()) {
                wp_safe_redirect(get_home_url(), 301);
                exit;
            }
        });
    }

    public function disable_xmlrpc_api(): void
    {
        add_filter('xmlrpc_methods', function () {
            return [];
        }, PHP_INT_MAX);
        add_filter('wp_headers', function ($headers) {
            unset($headers['X-Pingback']);

            return $headers;
        });
    }

    public function disable_rest_api(): void
    {
        add_filter('rest_authentication_errors', function ($result) {
            if (true === $result || is_wp_error($result)) {
                return $result;
            }
            if (!is_user_logged_in()) {
                return new WP_Error(
                        'rest_not_logged_in',
                        __('You are not currently logged in.'),
                        array('status' => 401)
                );
            }

            return $result;
        });
    }

    public function remove_ai_connectors(): void
    {
        add_filter('wp_supports_ai', '__return_false');
        add_action('admin_init', function () {
            remove_submenu_page('options-general.php', 'options-connectors.php');
        });
    }

    public function init_old_posts_cleanup(): void
    {
        add_action('brodda_it_delete_old_posts', [$this, 'delete_old_posts']);

        if (!wp_next_scheduled('brodda_it_delete_old_posts')) {
            wp_schedule_event(time(), 'hourly', 'brodda_it_delete_old_posts');
        }
    }

    public function delete_old_posts(): void
    {
        $max_age_days = absint(get_option('broddait_post_max_age_days'));

        if ($max_age_days < 1) {
            $max_age_days = 36500;
        }

        $old_posts = get_posts([
                'post_type' => 'post',
                'post_status' => 'publish',
                'date_query' => [
                        [
                                'before' => $max_age_days . ' days ago',
                                'inclusive' => false,
                        ],
                ],
                'fields' => 'ids',
                'posts_per_page' => 100,
                'no_found_rows' => true,
        ]);

        foreach ($old_posts as $post_id) {
            wp_trash_post($post_id);
        }
    }

    private function custom_backend_scripts(): void
    {
        add_action('admin_footer', function () {
            echo <<<EOL
	<script>
		jQuery( document ).ready(function() {
			const currentPage = new URLSearchParams(window.location.search).get('page');
			const currentUrl = window.location.pathname.split('/').pop();
	
			if (currentUrl === 'options-media.php'){
				jQuery('form').remove();
				jQuery('#wpbody .wrap').append(jQuery("<p></p>").text("Die Medien-Einstellungen dieser Seite werden durch brodda.IT verwaltet."));
			}
	
			// disable umami analytics tracking for logged in users
			localStorage.setItem('umami.disabled', "1");
		});
	</script>
EOL;
        });
    }

    private function allow_svg_uploads(): void
    {
        add_filter('upload_mimes', function ($mimes) {
            $mimes['svg'] = 'image/svg+xml';

            return $mimes;
        });

        add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
            $filetype = wp_check_filetype($filename, $mimes);

            return [
                    'ext' => $filetype['ext'],
                    'type' => $filetype['type'],
                    'proper_filename' => $data['proper_filename']
            ];
        }, 10, 4);
    }

    private function is_avada_theme_installed(): bool
    {
        return $this->is_theme_installed('Avada');
    }

    private function is_enfold_theme_installed(): bool
    {
        return $this->is_theme_installed('Enfold');
    }

    private function is_theme_installed($name): bool
    {
        $theme = wp_get_theme();
        $parent_theme = $theme->parent();

        return $theme->__get('name') === $name || $parent_theme && $parent_theme->__get('name') === $name;
    }

    private function disable_user_avatars(): void
    {
        // Replace Gravatar with a local default avatar
        add_filter('get_avatar_url', function ($url, $id_or_email, $args) {
            // Return a local default avatar image
            return plugin_dir_url(__FILE__) . 'default-avatar.png';
        }, 10, 3);

// Prevent DNS prefetch to gravatar.com
        remove_action('wp_head', 'wp_resource_hints', 2);
    }

    private function random_upload_filenames(): void
    {
        add_filter('wp_insert_attachment_data', function ($data, $postarr) {
            if ($_POST['action'] == 'upload-attachment' && !empty($data['post_title']) && isset($postarr['post_type']) && $postarr['post_type'] === 'attachment') {
                $data['post_title'] = '';
            }

            return $data;
        }, 10, 2);

        add_filter('sanitize_file_name', function ($filename) {
            $name = sha1(random_bytes(32));
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext === 'zip') {
                return $filename;
            }

            return "$name.$ext";
        });

        add_action('admin_init', function () {
            if (get_option('uploads_use_yearmonth_folders') != 0) {
                update_option('uploads_use_yearmonth_folders', 0);
            }
        });
    }

    private function disable_gutenberg_editor(): void
    {
        add_filter('use_block_editor_for_post', '__return_false', 10);
        add_filter('use_widgets_block_editor', '__return_false');
    }

    private function remove_all_dashboard_widgets(): void
    {
        add_action('wp_dashboard_setup', function () {
            global $wp_meta_boxes;
            $wp_meta_boxes['dashboard'] = [];
        }, 999);
    }

    private function force_auto_updates(): void
    {
        add_filter('auto_update_plugin', '__return_true');
        add_filter('auto_update_theme', '__return_true');
        add_filter('auto_update_core', '__return_true');
        add_filter('allow_major_auto_core_updates', '__return_true');
    }

    private function should_disable_comments(): bool
    {
        if ($this->is_enfold_theme_installed()) {
            return avia_get_option('disable_blog') === 'disable_blog';
        } else {
            return get_option('broddait_comments_enabled') == 'false';
        }
    }

    private function should_disable_blog(): bool
    {
        if ($this->is_enfold_theme_installed()) {
            return false;
        } else {
            return get_option('broddait_blog_enabled') == 'false';
        }
    }

    private function disable_blog(): void
    {
        if (!$this->should_disable_blog()) {
            return;
        }

        // Deaktiviere den Post Type "post" (Blog-Beiträge) und den Menüeintrag
        add_action('admin_menu', function () {
            remove_menu_page('edit.php');
        }, 999);

        // Deaktiviere den Post Type "post" nach dem init-Hook
        add_action('init', function () {
            if (post_type_exists('post')) {
                unregister_post_type('post');
            }
        }, 100);

        // Deaktiviere Kategorien- und Tag-Taxonomien
        add_action('init', function () {
            if (taxonomy_exists('category')) {
                unregister_taxonomy('category');
            }
            if (taxonomy_exists('post_tag')) {
                unregister_taxonomy('post_tag');
            }
        }, 100);

        // Deaktiviere Autorenarchive
        add_action('template_redirect', function () {
            if (is_author()) {
                global $wp_query;
                $wp_query->set_404();
            }
        });

        // Deaktiviere Kommentare für den Post Type "post"
        add_filter('comments_open', function ($open, $post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_type == 'post') {
                return false;
            }

            return $open;
        }, 10, 2);

        // Deaktiviere RSS-Feeds für Beiträge
        add_action('do_feed', function ($feed, $feed_name) {
            if (get_post_type() === 'post' || in_array($feed_name, ['feed', 'rdf', 'rss', 'rss2', 'atom'])) {
                wp_die(__('Feed deaktiviert.'));
            }
        }, 1, 2);

        // Optional: Deaktiviere REST API-Endpoints für Beiträge, Kategorien und Tags
        add_filter('rest_endpoints', function ($endpoints) {
            $to_remove = [
                    '/wp/v2/posts',
                    '/wp/v2/posts/(?P<id>[\d]+)',
                    '/wp/v2/categories',
                    '/wp/v2/categories/(?P<id>[\d]+)',
                    '/wp/v2/tags',
                    '/wp/v2/tags/(?P<id>[\d]+)'
            ];
            foreach ($to_remove as $endpoint) {
                if (isset($endpoints[$endpoint])) {
                    unset($endpoints[$endpoint]);
                }
            }

            return $endpoints;
        });
    }

    private function disable_comments(): void
    {
        if (!$this->should_disable_comments()) {
            return;
        }
        add_action('admin_init', function () {
            // Redirect any user trying to access comments page
            global $pagenow;

            if ($pagenow === 'edit-comments.php' || $pagenow === 'options-discussion.php') {
                wp_redirect(admin_url());
                exit;
            }

            // Remove comments metabox from dashboard
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

            // Disable support for comments and trackbacks in post types
            foreach (get_post_types() as $post_type) {
                if (post_type_supports($post_type, 'comments')) {
                    remove_post_type_support($post_type, 'comments');
                    remove_post_type_support($post_type, 'trackbacks');
                }
            }

            add_action('admin_bar_menu', function ($wp_admin_bar) {
                $wp_admin_bar->remove_node('comments');
            }, 999);
        });

// Close comments on the front-end
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);

// Hide existing comments
        add_filter('comments_array', '__return_empty_array', 10, 2);

// Remove comments page and option page in menu
        add_action('admin_menu', function () {
            remove_menu_page('edit-comments.php');
            remove_submenu_page('options-general.php', 'options-discussion.php');
        });

// Remove comments links from admin bar
        add_action('init', function () {
            if (is_admin_bar_showing()) {
                remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
            }
        });
    }

    private function create_settings_page(): void
    {
        add_action('admin_init', function () {
            $page_slug = 'broddait_settings';
            $option_group = 'broddait_settings';

            add_settings_section(
                    'broddait_settings',
                    '',
                    '',
                    $page_slug
            );

            register_setting($option_group, 'broddait_comments_enabled',
                    function ($value) {
                        return 'on' == $value ? 'true' : 'false';
                    }
            );
            if (!get_option('broddait_comments_enabled')) {
                update_option('broddait_comments_enabled', 'false');
            }

            register_setting($option_group, 'broddait_post_max_age_days', ['type' => 'integer']);
            if (!get_option('broddait_post_max_age_days') || get_option('broddait_post_max_age_days') < 1) {
                update_option('broddait_post_max_age_days', 36500);
            }

            register_setting($option_group, 'broddait_blog_enabled',
                    function ($value) {
                        return 'on' == $value ? 'true' : 'false';
                    }
            );
            if (!get_option('broddait_blog_enabled')) {
                update_option('broddait_blog_enabled', 'false');
            }

            if (!$this->is_enfold_theme_installed()) {
                add_settings_field(
                        'broddait_comments_enabled',
                        'Kommentare aktiviert',
                        function ($args) { ?>
                            <label>
                                <input type="checkbox"
                                       name="broddait_comments_enabled" <?php checked(get_option('broddait_comments_enabled'), 'true') ?> />
                            </label>
                        <?php },
                        $page_slug,
                        'broddait_settings' // section ID
                );
                add_settings_field(
                        'broddait_blog_enabled',
                        'Blog aktiviert',
                        function ($args) { ?>
                            <label>
                                <input type="checkbox"
                                       name="broddait_blog_enabled" <?php checked(get_option('broddait_blog_enabled'), 'true') ?> />
                            </label>
                        <?php },
                        $page_slug,
                        'broddait_settings' // section ID
                );
            }
            add_settings_field(
                    'broddait_post_max_age_days',
                    'Maximales Beitragsalter in Tagen',
                    function ($args) { ?>
                        <label>
                            <input type="number" name="broddait_post_max_age_days"
                                   value="<?php echo absint(get_option('broddait_post_max_age_days')) ?>"/>
                        </label>
                    <?php },
                    $page_slug,
                    'broddait_settings' // section ID
            );
        });

        add_action('admin_menu', function () {
            add_options_page(
                    'brodda.IT Einstellungen',
                    'brodda.IT',
                    'manage_options',
                    'broddait_settings',
                    function () {
                        ?>
                        <div class="wrap">
                            <h1><?php echo get_admin_page_title() ?></h1>
                            <form method="post" action="options.php">
                                <?php
                                settings_fields('broddait_settings');
                                do_settings_sections('broddait_settings');
                                submit_button();
                                ?>
                            </form>
                        </div>
                        <?php
                    },
            );
        });
    }

    private function keep_plugin_active(): void
    {
        add_filter('plugin_action_links', function ($actions, $plugin_file) {
            if ($plugin_file === plugin_basename(__FILE__)) {
                unset($actions['deactivate']);
            }
            add_filter('plugin_row_meta', function ($links, $file) {
                if ($file === plugin_basename(__FILE__)) {
                    foreach ($links as $key => $link) {
                        if (stripos($link, 'plugin-install.php?tab=plugin-information') !== false) {
                            unset($links[$key]);
                        }
                    }
                }

                return $links;
            }, 10, 2);

            return $actions;
        }, 10, 2);
        add_action('admin_head', function () {
            $plugin = plugin_basename(__FILE__);
            echo '<style>
        tr[data-plugin="' . esc_attr($plugin) . '"] th.check-column input[type="checkbox"] {
            display:none !important;
        }
    </style>';
        });
    }

    private function update_check(): void
    {
        add_action('upgrader_process_complete', function ($upgrader, $options) {
            if ('update' === $options['action'] && 'plugin' === $options['type']) {
                delete_transient('broddait_plugin_update_cache');
            }
        }, 10, 2);


        add_filter('pre_set_site_transient_update_plugins',
                function ($transient) {
                    $plugin = (json_decode(file_get_contents(dirname(__FILE__) . "/info.json")));

                    $remote = get_transient('broddait_plugin_update_cache');

                    if ($remote === false) {
                        $remote = wp_remote_get(
                                $plugin->info_url,
                                array(
                                        'timeout' => 10,
                                        'headers' => array(
                                                'Accept' => 'application/json'
                                        )
                                )
                        );
                        set_transient('broddait_plugin_update_cache', $remote, 10);
                    }

                    if (
                            is_wp_error($remote)
                            || 200 !== wp_remote_retrieve_response_code($remote)
                            || empty(wp_remote_retrieve_body($remote))
                    ) {
                        return $transient;
                    }

                    $remote = json_decode(wp_remote_retrieve_body($remote));

                    if ($remote) {
                        $res = new stdClass();
                        $res->slug = 'brodda-it';
                        $res->id = plugin_basename(__FILE__);
                        $res->plugin = plugin_basename(__FILE__);
                        $res->new_version = $remote->version;
                        $res->package = $remote->download_url;

                        if (version_compare($plugin->version, $remote->version, '<')) {
                            $transient->response[$res->plugin] = $res;
                        } else {
                            $transient->no_update[$res->plugin] = $res;
                        }
                    }

                    return $transient;
                });
    }
}

new BroddaITPlugin();
