<?php

defined('ABSPATH') or die();

final class BroddaITUserSwitching
{
    private const string SESSION_USER_ID = 'broddait_switched_from_id';
    private const string SESSION_TOKEN = 'broddait_switched_from_session';
    private const string SESSION_REMEMBER = 'broddait_switched_from_remember';

    public static function init(): void
    {
        add_filter('user_row_actions', [self::class, 'add_switch_action'], 10, 2);
        add_filter('ms_user_row_actions', [self::class, 'add_switch_action'], 10, 2);
        add_action('admin_post_broddait_switch_user', [self::class, 'switch_user']);
        add_action('admin_post_broddait_switch_back', [self::class, 'switch_back']);
        add_action('admin_bar_menu', [self::class, 'show_switched_identity'], 999);
        add_action('admin_head', [self::class, 'highlight_switched_identity']);
        add_action('wp_head', [self::class, 'highlight_switched_identity']);
    }

    public static function add_switch_action(array $actions, WP_User $user): array
    {
        if (
                self::get_switch_state() !== null
                || !current_user_can('manage_options')
                || !current_user_can('edit_user', $user->ID)
                || get_current_user_id() === $user->ID
        ) {
            return $actions;
        }

        $url = wp_nonce_url(
                add_query_arg(
                        [
                                'action' => 'broddait_switch_user',
                                'user_id' => $user->ID,
                        ],
                        admin_url('admin-post.php')
                ),
                'broddait_switch_user_' . $user->ID
        );
        $actions['broddait_switch_user'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                esc_html__('Switch to', 'brodda-it')
        );

        return $actions;
    }

    public static function switch_user(): void
    {
        $target_user_id = isset($_GET['user_id'])
                ? absint(wp_unslash($_GET['user_id']))
                : 0;

        if (
                !$target_user_id
                || self::get_switch_state() !== null
                || !current_user_can('manage_options')
                || !current_user_can('edit_user', $target_user_id)
        ) {
            wp_die(
                    esc_html__('You are not allowed to switch to this user.', 'brodda-it'),
                    '',
                    ['response' => 403]
            );
        }

        check_admin_referer('broddait_switch_user_' . $target_user_id);

        $target_user = get_userdata($target_user_id);
        if (!$target_user instanceof WP_User || $target_user_id === get_current_user_id()) {
            wp_die(esc_html__('The selected user does not exist.', 'brodda-it'), '', ['response' => 404]);
        }

        $original_user_id = get_current_user_id();
        $original_session_token = wp_get_session_token();
        $remember = self::is_current_user_remembered();
        $session_filter = static function (array $session) use (
                $original_user_id,
                $original_session_token,
                $remember
        ): array {
            $session[self::SESSION_USER_ID] = $original_user_id;
            $session[self::SESSION_TOKEN] = $original_session_token;
            $session[self::SESSION_REMEMBER] = $remember;
            return $session;
        };

        add_filter('attach_session_information', $session_filter, 99);
        wp_clear_auth_cookie();
        wp_set_current_user($target_user_id);
        wp_set_auth_cookie($target_user_id, $remember, is_ssl());
        remove_filter('attach_session_information', $session_filter, 99);

        wp_safe_redirect(admin_url());
        exit;
    }

    public static function switch_back(): void
    {
        $switch_state = self::get_switch_state();
        if ($switch_state === null) {
            wp_die(esc_html__('The original user session is no longer available.', 'brodda-it'), '', ['response' => 403]);
        }

        check_admin_referer('broddait_switch_back');

        $original_user_id = $switch_state['user_id'];
        $original_user = get_userdata($original_user_id);
        if (!$original_user instanceof WP_User || !user_can($original_user, 'manage_options')) {
            wp_die(esc_html__('The original user can no longer be restored.', 'brodda-it'), '', ['response' => 403]);
        }

        $original_sessions = WP_Session_Tokens::get_instance($original_user_id);
        if (!$original_sessions->verify($switch_state['token'])) {
            wp_die(esc_html__('The original user session has expired.', 'brodda-it'), '', ['response' => 403]);
        }

        $switched_user_id = get_current_user_id();
        $switched_session_token = wp_get_session_token();
        wp_clear_auth_cookie();
        wp_set_current_user($original_user_id);
        wp_set_auth_cookie(
                $original_user_id,
                $switch_state['remember'],
                is_ssl(),
                $switch_state['token']
        );
        WP_Session_Tokens::get_instance($switched_user_id)->destroy($switched_session_token);

        wp_safe_redirect(admin_url('users.php'));
        exit;
    }

    public static function show_switched_identity(WP_Admin_Bar $admin_bar): void
    {
        $switch_state = self::get_switch_state();
        if ($switch_state === null) {
            return;
        }

        $original_user = get_userdata($switch_state['user_id']);
        if (!$original_user instanceof WP_User) {
            return;
        }

        $admin_bar->add_node([
                'parent' => 'user-actions',
                'id' => 'broddait-switch-back',
                'title' => sprintf(
                        __('Switch back to %s', 'brodda-it'),
                        esc_html($original_user->display_name)
                ),
                'href' => wp_nonce_url(
                        admin_url('admin-post.php?action=broddait_switch_back'),
                        'broddait_switch_back'
                ),
        ]);
    }

    public static function highlight_switched_identity(): void
    {
        if (!is_admin_bar_showing() || self::get_switch_state() === null) {
            return;
        }
        ?>
        <style>
            #wpadminbar #wp-admin-bar-my-account > .ab-item {
                background-color: #b26200;
                color: #fff;
            }

            #wpadminbar #wp-admin-bar-my-account:hover > .ab-item,
            #wpadminbar #wp-admin-bar-my-account.hover > .ab-item {
                background-color: #8a4c00;
                color: #fff;
            }
        </style>
        <?php
    }

    /**
     * @return array{user_id: int, token: string, remember: bool}|null
     */
    private static function get_switch_state(): ?array
    {
        $user_id = get_current_user_id();
        $session_token = wp_get_session_token();
        if (!$user_id || $session_token === '') {
            return null;
        }

        $session = WP_Session_Tokens::get_instance($user_id)->get($session_token);
        if (
                !is_array($session)
                || empty($session[self::SESSION_USER_ID])
                || empty($session[self::SESSION_TOKEN])
        ) {
            return null;
        }

        return [
                'user_id' => (int)$session[self::SESSION_USER_ID],
                'token' => (string)$session[self::SESSION_TOKEN],
                'remember' => !empty($session[self::SESSION_REMEMBER]),
        ];
    }

    private static function is_current_user_remembered(): bool
    {
        $cookie = wp_parse_auth_cookie('', 'logged_in');
        if (!$cookie) {
            return false;
        }

        $default_lifetime = apply_filters(
                'auth_cookie_expiration',
                2 * DAY_IN_SECONDS,
                get_current_user_id(),
                false
        );
        return ((int)$cookie['expiration'] - time()) > $default_lifetime;
    }
}

BroddaITUserSwitching::init();
