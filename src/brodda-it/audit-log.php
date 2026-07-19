<?php

defined('ABSPATH') or die();

final class BroddaITAuditLog
{
    private const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'broddait_audit_log_db_version';
    private const PER_PAGE = 50;
    private const RETENTION_DAYS = 365;
    private const CLEANUP_HOOK = 'broddait_audit_log_cleanup';

    public static function init(): void
    {
        add_action('init', [self::class, 'maybe_create_table']);
        add_action('init', [self::class, 'schedule_cleanup']);
        add_action(self::CLEANUP_HOOK, [self::class, 'delete_expired_events']);

        add_action('wp_login', [self::class, 'log_login'], 10, 2);
        add_action('wp_logout', [self::class, 'log_logout'], 10, 1);
        add_action('user_register', [self::class, 'log_user_creation'], 10, 2);
        add_action('deleted_user', [self::class, 'log_user_deletion'], 10, 3);
        add_action('profile_update', [self::class, 'log_profile_password_change'], 10, 3);
        add_action('retrieve_password', [self::class, 'log_password_reset_request']);
        add_action('after_password_reset', [self::class, 'log_password_reset'], 10, 2);
        add_action('wp_mail_succeeded', [self::class, 'log_sent_email']);
        add_action('post_updated', [self::class, 'log_post_update'], 10, 3);
        add_action('before_delete_post', [self::class, 'log_content_deletion'], 10, 2);
        add_action('add_attachment', [self::class, 'log_media_upload']);
        add_action('delete_attachment', [self::class, 'log_media_deletion'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'log_upgrader_change'], 10, 2);
        add_action('activated_plugin', [self::class, 'log_plugin_activation'], 10, 2);
        add_action('deactivated_plugin', [self::class, 'log_plugin_deactivation'], 10, 2);
        add_action('deleted_plugin', [self::class, 'log_plugin_uninstall'], 10, 2);

        add_action('admin_menu', [self::class, 'register_admin_page']);

        register_deactivation_hook(__DIR__ . '/brodda-it.php', [self::class, 'unschedule_cleanup']);
    }

    public static function maybe_create_table(): void
    {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_time datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            username varchar(60) NOT NULL DEFAULT '',
            event_type varchar(50) NOT NULL,
            object_type varchar(30) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            description text NOT NULL,
            PRIMARY KEY  (id),
            KEY event_time (event_time),
            KEY event_type (event_type),
            KEY user_id (user_id)
        ) {$charset_collate};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function schedule_cleanup(): void
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
    }

    public static function unschedule_cleanup(): void
    {
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }

    public static function delete_expired_events(): void
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));
        $wpdb->query(
                $wpdb->prepare(
                        'DELETE FROM ' . self::table_name() . ' WHERE event_time < %s',
                        $cutoff
                )
        );
    }

    public static function log_login(string $user_login, WP_User $user): void
    {
        self::write('user_login', 'user', $user->ID, sprintf('User “%s” logged in.', $user_login), $user);
    }

    public static function log_logout(int $user_id = 0): void
    {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        $username = $user instanceof WP_User ? $user->user_login : '';

        self::write('user_logout', 'user', $user_id, sprintf('User “%s” logged out.', $username), $user);
    }

    public static function log_user_creation(int $user_id, array $userdata = []): void
    {
        $user = get_userdata($user_id);
        $username = $user instanceof WP_User ? $user->user_login : ($userdata['user_login'] ?? '');

        self::write(
                'user_created',
                'user',
                $user_id,
                sprintf('User account “%s” was created.', $username)
        );
    }

    public static function log_user_deletion(int $user_id, ?int $reassign, WP_User $deleted_user): void
    {
        self::write(
                'user_deleted',
                'user',
                $user_id,
                sprintf('User account “%s” was deleted.', $deleted_user->user_login)
        );
    }

    public static function log_profile_password_change(
            int $user_id,
            WP_User $old_user_data,
            array $userdata = []
    ): void {
        $updated_user = get_userdata($user_id);
        if (!$updated_user instanceof WP_User || $updated_user->user_pass === $old_user_data->user_pass) {
            return;
        }

        self::write(
                'password_changed',
                'user',
                $user_id,
                sprintf('Password changed for user “%s”.', $updated_user->user_login)
        );
    }

    public static function log_password_reset(WP_User $user, string $new_password): void
    {
        self::write(
                'password_changed',
                'user',
                $user->ID,
                sprintf('Password reset for user “%s”.', $user->user_login)
        );
    }

    public static function log_password_reset_request(string $user_login): void
    {
        $user = get_user_by('login', $user_login);
        if (!$user instanceof WP_User) {
            return;
        }

        self::write(
                'password_reset_requested',
                'user',
                $user->ID,
                sprintf('Password reset requested for user “%s”.', $user->user_login)
        );
    }

    public static function log_sent_email(array $mail_data): void
    {
        $recipients = $mail_data['to'] ?? [];
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $recipients = array_values(array_filter(array_map(
                static fn($recipient): string => sanitize_text_field((string)$recipient),
                $recipients
        )));
        $recipient_list = $recipients ? implode(', ', $recipients) : 'unknown recipient';
        $subject = sanitize_text_field((string)($mail_data['subject'] ?? ''));

        self::write(
                'email_sent',
                'email',
                0,
                sprintf('Email sent to “%s” with subject “%s”.', $recipient_list, $subject)
        );
    }

    public static function log_post_update(int $post_id, WP_Post $post_after, WP_Post $post_before): void
    {
        if (!self::should_log_post_type($post_after->post_type)
                || wp_is_post_revision($post_id)
                || wp_is_post_autosave($post_id)) {
            return;
        }

        $changes = [];
        if ($post_before->post_title !== $post_after->post_title) {
            $changes[] = 'title';
        }
        if ($post_before->post_status !== $post_after->post_status) {
            $changes[] = sprintf('status (%s → %s)', $post_before->post_status, $post_after->post_status);
        }
        if ($post_before->post_content !== $post_after->post_content) {
            $changes[] = 'content';
        }

        $detail = $changes ? ' Changed: ' . implode(', ', $changes) . '.' : '';
        self::write(
                'content_updated',
                $post_after->post_type,
                $post_id,
                sprintf('%s “%s” was updated.%s', self::post_type_label($post_after->post_type), $post_after->post_title, $detail)
        );
    }

    public static function log_content_deletion(int $post_id, WP_Post $post): void
    {
        if ($post->post_status !== 'trash' || !self::should_log_post_type($post->post_type)) {
            return;
        }

        self::write(
                'content_deleted',
                $post->post_type,
                $post_id,
                sprintf(
                        '%s “%s” was permanently deleted from Trash.',
                        self::post_type_label($post->post_type),
                        $post->post_title
                )
        );
    }

    public static function log_media_upload(int $attachment_id): void
    {
        $attachment = get_post($attachment_id);
        if (!$attachment instanceof WP_Post) {
            return;
        }

        $filename = basename((string)get_attached_file($attachment_id));
        self::write(
                'media_updated',
                'attachment',
                $attachment_id,
                sprintf('Media file “%s” was uploaded.', $filename ?: $attachment->post_title)
        );
    }

    public static function log_media_deletion(int $attachment_id, WP_Post $attachment): void
    {
        $filename = basename((string)get_attached_file($attachment_id));
        self::write(
                'media_updated',
                'attachment',
                $attachment_id,
                sprintf('Media file “%s” was deleted.', $filename ?: $attachment->post_title)
        );
    }

    public static function log_upgrader_change(WP_Upgrader $upgrader, array $options): void
    {
        $type = $options['type'] ?? '';
        $action = $options['action'] ?? '';

        if ($type === 'core' && $action === 'update') {
            $version = self::installed_wordpress_version();
            $description = $version !== ''
                    ? sprintf('WordPress core updated to version %s.', $version)
                    : 'WordPress core was updated.';

            self::write('core_updated', 'core', 0, $description);
            return;
        }

        if ($type === 'theme' && $action === 'update') {
            $themes = $options['themes'] ?? [];
            if (!$themes && !empty($options['theme'])) {
                $themes = [$options['theme']];
            }

            $description = $themes
                    ? sprintf('Theme(s) updated: %s.', implode(', ', array_map('sanitize_key', (array)$themes)))
                    : 'One or more themes were updated.';

            self::write('theme_updated', 'theme', 0, $description);
            return;
        }

        if ($type !== 'plugin' || !in_array($action, ['install', 'update'], true)) {
            return;
        }

        $plugins = $options['plugins'] ?? [];
        if (!$plugins && !empty($options['plugin'])) {
            $plugins = [$options['plugin']];
        }
        if (!$plugins && $action === 'install' && method_exists($upgrader, 'plugin_info')) {
            $installed_plugin = $upgrader->plugin_info();
            if (is_string($installed_plugin) && $installed_plugin !== '') {
                $plugins = [$installed_plugin];
            }
        }
        if (!$plugins && $action === 'install' && !empty($upgrader->result['destination_name'])) {
            $plugins = [(string)$upgrader->result['destination_name']];
        }

        $plugin_names = array_map(static function ($plugin): string {
            return dirname((string)$plugin) === '.' ? basename((string)$plugin, '.php') : dirname((string)$plugin);
        }, (array)$plugins);

        $description = $plugin_names
                ? sprintf('Plugin %s: %s.', $action === 'install' ? 'installed' : 'updated', implode(', ', $plugin_names))
                : sprintf('One or more plugins were %s.', $action === 'install' ? 'installed' : 'updated');

        $event_type = $action === 'install' ? 'plugin_installed' : 'plugin_updated';
        self::write($event_type, 'plugin', 0, $description);
    }

    public static function log_plugin_activation(string $plugin_file, bool $network_wide): void
    {
        $scope = $network_wide ? ' network-wide' : '';
        self::write(
                'plugin_activated',
                'plugin',
                0,
                sprintf('Plugin activated%s: %s.', $scope, self::plugin_name_from_file($plugin_file))
        );
    }

    public static function log_plugin_deactivation(string $plugin_file, bool $network_wide): void
    {
        $scope = $network_wide ? ' network-wide' : '';
        self::write(
                'plugin_deactivated',
                'plugin',
                0,
                sprintf('Plugin deactivated%s: %s.', $scope, self::plugin_name_from_file($plugin_file))
        );
    }

    public static function log_plugin_uninstall(string $plugin_file, bool $deleted): void
    {
        if (!$deleted) {
            return;
        }

        self::write(
                'plugin_uninstalled',
                'plugin',
                0,
                sprintf('Plugin uninstalled: %s.', self::plugin_name_from_file($plugin_file))
        );
    }

    public static function register_admin_page(): void
    {
        if (!self::can_view_audit_log()) {
            return;
        }

        add_dashboard_page(
                'brodda.IT Activity Log',
                'Activity Log',
                'read',
                'broddait-audit-log',
                [self::class, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void
    {
        if (!self::can_view_audit_log()) {
            wp_die(esc_html__('You are not allowed to access this page.'));
        }

        global $wpdb;

        $page = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $table_name = self::table_name();

        $requested_user = isset($_GET['audit_user']) ? (string)wp_unslash($_GET['audit_user']) : '';
        $selected_user = preg_match('/^\d+$/', $requested_user)
                ? (string)absint($requested_user)
                : '';
        $selected_event = isset($_GET['audit_event'])
                ? sanitize_key((string)wp_unslash($_GET['audit_event']))
                : '';

        $where = [];
        $query_parameters = [];
        if ($selected_user !== '') {
            $where[] = 'user_id = %d';
            $query_parameters[] = (int)$selected_user;
        }
        if ($selected_event !== '') {
            $where[] = 'event_type = %s';
            $query_parameters[] = $selected_event;
        }

        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count_sql = "SELECT COUNT(*) FROM {$table_name}{$where_sql}";
        $total = (int)$wpdb->get_var(
                $query_parameters ? $wpdb->prepare($count_sql, ...$query_parameters) : $count_sql
        );

        $event_sql = "SELECT * FROM {$table_name}{$where_sql} ORDER BY event_time DESC, id DESC LIMIT %d OFFSET %d";
        $event_parameters = array_merge($query_parameters, [self::PER_PAGE, $offset]);
        $events = $wpdb->get_results(
                $wpdb->prepare($event_sql, ...$event_parameters)
        );

        $users = $wpdb->get_results(
                "SELECT user_id, MAX(username) AS username FROM {$table_name} GROUP BY user_id ORDER BY username ASC"
        );
        $event_types = $wpdb->get_col(
                "SELECT DISTINCT event_type FROM {$table_name} ORDER BY event_type ASC"
        );

        $event_groups = self::group_consecutive_events($events);
        $total_pages = max(1, (int)ceil($total / self::PER_PAGE));
        ?>
        <div class="wrap">
            <style>
                .broddait-audit-pagination {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 6px;
                    margin: 20px 0;
                }

                .broddait-audit-pagination .page-numbers {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 34px;
                    min-height: 32px;
                    box-sizing: border-box;
                    padding: 4px 10px;
                    border: 1px solid #8c8f94;
                    border-radius: 4px;
                    background: #f6f7f7;
                    color: #2271b1;
                    text-decoration: none;
                }

                .broddait-audit-pagination a.page-numbers:hover,
                .broddait-audit-pagination a.page-numbers:focus {
                    border-color: #2271b1;
                    background: #fff;
                    color: #135e96;
                }

                .broddait-audit-pagination .page-numbers.current {
                    border-color: #2271b1;
                    background: #2271b1;
                    color: #fff;
                    font-weight: 600;
                }

                .broddait-audit-pagination .page-numbers.dots {
                    min-width: auto;
                    border-color: transparent;
                    background: transparent;
                    color: #50575e;
                }

                .broddait-audit-loading-overlay {
                    position: fixed;
                    z-index: 100000;
                    inset: 0;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    flex-direction: column;
                    gap: 12px;
                    background: rgba(240, 240, 241, 0.82);
                    color: #1d2327;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: wait;
                }

                .broddait-audit-filter.is-loading .broddait-audit-loading-overlay {
                    display: flex;
                }

                .broddait-audit-loading-spinner {
                    width: 36px;
                    height: 36px;
                    box-sizing: border-box;
                    border: 4px solid #c3c4c7;
                    border-top-color: #2271b1;
                    border-radius: 50%;
                    animation: broddait-audit-spin 0.75s linear infinite;
                }

                @keyframes broddait-audit-spin {
                    to {
                        transform: rotate(360deg);
                    }
                }
            </style>
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p><?php echo esc_html(sprintf('%d recorded events', $total)); ?></p>
            <form method="get" class="broddait-audit-filter" style="margin: 12px 0;">
                <input type="hidden" name="page" value="broddait-audit-log">
                <label for="broddait-audit-user" class="screen-reader-text">Filter by user</label>
                <select id="broddait-audit-user" name="audit_user"
                        onchange="this.form.classList.add('is-loading'); this.form.submit()">
                    <option value="">All users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo esc_attr((string)$user->user_id); ?>"
                                <?php selected($selected_user, (string)$user->user_id); ?>>
                            <?php echo esc_html($user->username ?: 'System'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="broddait-audit-event" class="screen-reader-text">Filter by event</label>
                <select id="broddait-audit-event" name="audit_event"
                        onchange="this.form.classList.add('is-loading'); this.form.submit()">
                    <option value="">All events</option>
                    <?php foreach ($event_types as $event_type): ?>
                        <option value="<?php echo esc_attr($event_type); ?>"
                                <?php selected($selected_event, $event_type); ?>>
                            <?php echo esc_html(str_replace('_', ' ', $event_type)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selected_user !== '' || $selected_event !== ''): ?>
                    <a class="button"
                       href="<?php echo esc_url(admin_url('index.php?page=broddait-audit-log')); ?>"
                       onclick="this.closest('form').classList.add('is-loading')">Reset</a>
                <?php endif; ?>
                <div class="broddait-audit-loading-overlay" role="status" aria-live="polite">
                    <span class="broddait-audit-loading-spinner" aria-hidden="true"></span>
                    <span>Loading activity log…</span>
                </div>
            </form>
            <script>
                window.addEventListener('pageshow', function () {
                    const filterForm = document.querySelector('.broddait-audit-filter');
                    if (filterForm) {
                        filterForm.classList.remove('is-loading');
                    }
                });
            </script>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Event</th>
                    <th>Details</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$event_groups): ?>
                    <tr><td colspan="4">No activity has been recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($event_groups as $group): ?>
                        <?php $event = $group['event']; ?>
                        <tr>
                            <td><?php echo esc_html(get_date_from_gmt($event->event_time, 'Y-m-d H:i:s')); ?></td>
                            <td><?php echo esc_html($event->username ?: 'System'); ?></td>
                            <td><?php echo esc_html(str_replace('_', ' ', $event->event_type)); ?></td>
                            <td>
                                <?php echo esc_html($event->description); ?>
                                <?php if ($group['count'] > 1): ?>
                                    <?php echo esc_html(sprintf(' (%d times)', $group['count'])); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
                <div class="broddait-audit-pagination">
                    <?php echo wp_kses_post(paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $page,
                            'total' => $total_pages,
                    ])); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function group_consecutive_events(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $last_index = count($groups) - 1;

            if ($last_index >= 0 && self::events_are_similar($groups[$last_index]['event'], $event)) {
                $groups[$last_index]['count']++;
                continue;
            }

            $groups[] = [
                    'event' => $event,
                    'count' => 1,
            ];
        }

        return $groups;
    }

    private static function events_are_similar(object $first, object $second): bool
    {
        if ($first->event_type !== 'content_updated' || $second->event_type !== 'content_updated') {
            return false;
        }

        return (int)$first->user_id === (int)$second->user_id
                && $first->object_type === $second->object_type
                && (int)$first->object_id === (int)$second->object_id;
    }

    private static function write(
            string $event_type,
            string $object_type,
            int $object_id,
            string $description,
            $user = null
    ): void {
        global $wpdb;

        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::maybe_create_table();
        }

        if (!$user instanceof WP_User) {
            $user = wp_get_current_user();
        }

        $wpdb->insert(
                self::table_name(),
                [
                        'event_time' => current_time('mysql', true),
                        'user_id' => $user->exists() ? $user->ID : 0,
                        'username' => $user->exists() ? $user->user_login : '',
                        'event_type' => sanitize_key($event_type),
                        'object_type' => sanitize_key($object_type),
                        'object_id' => $object_id,
                        'description' => sanitize_text_field($description),
                ],
                ['%s', '%d', '%s', '%s', '%s', '%d', '%s']
        );
    }

    private static function can_view_audit_log(): bool
    {
        $user = wp_get_current_user();
        $allowed_roles = ['administrator', 'customer_admin'];

        return $user->exists()
                && current_user_can('read')
                && (bool)array_intersect($allowed_roles, (array)$user->roles);
    }

    private static function should_log_post_type(string $post_type): bool
    {
        $excluded_types = [
                'attachment',
                'revision',
                'nav_menu_item',
                'custom_css',
                'customize_changeset',
                'oembed_cache',
                'user_request',
                'wp_block',
                'wp_template',
                'wp_template_part',
                'wp_global_styles',
                'wp_navigation',
                'wp_font_family',
                'wp_font_face',
        ];

        if (in_array($post_type, $excluded_types, true)) {
            return false;
        }

        if (in_array($post_type, ['post', 'page'], true)) {
            return true;
        }

        $post_type_object = get_post_type_object($post_type);
        return $post_type_object !== null && (bool)$post_type_object->show_ui;
    }

    private static function post_type_label(string $post_type): string
    {
        $post_type_object = get_post_type_object($post_type);
        if ($post_type_object !== null && !empty($post_type_object->labels->singular_name)) {
            return $post_type_object->labels->singular_name;
        }

        return ucfirst($post_type);
    }

    private static function plugin_name_from_file(string $plugin_file): string
    {
        return dirname($plugin_file) === '.'
                ? basename($plugin_file, '.php')
                : dirname($plugin_file);
    }

    private static function installed_wordpress_version(): string
    {
        $wp_version = '';
        require ABSPATH . WPINC . '/version.php';

        return is_string($wp_version) ? $wp_version : '';
    }

    private static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'broddait_audit_log';
    }
}

BroddaITAuditLog::init();
