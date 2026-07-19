<?php

defined('ABSPATH') or die();

final class BroddaITCustomUserRoles
{
    private const string VERSION = '1';
    private const string VERSION_OPTION = 'broddait_custom_user_roles_version';

    public static function init(): void
    {
        self::register_capability_filters();
        add_action('init', [self::class, 'maybe_recreate_roles'], 999);
    }

    public static function maybe_recreate_roles(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }

        self::recreate_roles();

        $custom_role_names = self::role_definitions()
                |> array_keys(...);
        $missing_roles = array_filter(
                $custom_role_names,
                static fn(string $role): bool => get_role($role) === null
        );

        if (!$missing_roles) {
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }
    }

    private static function role_definitions(): array
    {
        return [
                'customer_admin' => [
                        'display_name' => 'Kunden-Administrator',
                        'capabilities' => [

                            // Login / dashboard
                                'read' => true,

                            // Posts
                                'edit_posts' => true,
                                'edit_others_posts' => true,
                                'edit_published_posts' => true,
                                'publish_posts' => true,
                                'delete_posts' => true,
                                'delete_others_posts' => true,
                                'delete_published_posts' => true,

                            // Pages
                                'edit_pages' => true,
                                'edit_others_pages' => true,
                                'edit_published_pages' => true,
                                'publish_pages' => true,
                                'delete_pages' => true,
                                'delete_others_pages' => true,
                                'delete_published_pages' => true,

                            // Media
                                'upload_files' => true,

                            // Categories / tags
                                'manage_categories' => true,

                            // Comments
                                'moderate_comments' => true,
                                'edit_comment' => true,

                            // Themes appearance basics
                                'edit_theme_options' => false,

                            // Plugins/themes/core
                                'activate_plugins' => false,
                                'install_plugins' => false,
                                'update_plugins' => false,
                                'delete_plugins' => false,

                                'install_themes' => false,
                                'switch_themes' => false,
                                'update_themes' => false,
                                'delete_themes' => false,

                                'update_core' => false,

                            // Users
                                'list_users' => true,
                                'create_users' => true,
                                'edit_users' => true,
                                'delete_users' => true,
                                'promote_users' => true,

                            // Settings/tools
                                'manage_options' => false,
                                'import' => false,
                                'export' => false,
                                'view_site_health_checks' => false,
                                'export_others_personal_data' => false,
                                'erase_others_personal_data' => false,

                            // Customizer
                                'customize' => false,
                        ],
                ],
        ];
    }

    private static function recreate_roles(): void
    {
        global $wp_roles;

        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        foreach ($wp_roles->roles as $role => $details) {
            if ($role !== 'administrator') {
                remove_role($role);
            }
        }

        foreach (self::role_definitions() as $role => $definition) {
            add_role($role, $definition['display_name'], $definition['capabilities']);
        }
    }

    private static function register_capability_filters(): void
    {
        $custom_role_names = self::role_definitions()
                |> array_keys(...);

        add_filter('map_meta_cap', static function ($caps, $cap, $user_id, $args) use ($custom_role_names) {
            $acting_user = get_userdata($user_id);

            if (!$acting_user || !array_intersect($custom_role_names, (array)$acting_user->roles)) {
                return $caps;
            }

            $target_user_id = $args[0] ?? 0;
            if (!$target_user_id) {
                return $caps;
            }

            $target_user = get_userdata($target_user_id);
            if (!$target_user || !in_array('administrator', (array)$target_user->roles, true)) {
                return $caps;
            }

            if (in_array($cap, [
                    'edit_user',
                    'remove_user',
                    'delete_user',
                    'promote_user',
            ], true)) {
                return ['do_not_allow'];
            }

            return $caps;
        }, 10, 4);
    }
}

BroddaITCustomUserRoles::init();
