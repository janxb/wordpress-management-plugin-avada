<?php

defined('ABSPATH') or die();

final class BroddaITObjectCacheManager
{
    private const string CLEANUP_HOOK = 'broddait_object_cache_cleanup';
    private const string VACUUM_HOOK = 'broddait_object_cache_vacuum';
    private const string VERIFY_HOOK = 'broddait_object_cache_verify';
    private const int RETENTION_DAYS = 30;

    public static function init(): void
    {
        register_uninstall_hook(
                __DIR__ . '/brodda-it.php',
                [self::class, 'uninstall']
        );
        register_activation_hook(
                __DIR__ . '/brodda-it.php',
                [self::class, 'install_drop_in']
        );

        add_action('upgrader_process_complete', [self::class, 'refresh_after_plugin_update'], 10, 2);
        add_action(self::VERIFY_HOOK, [self::class, 'install_drop_in']);
        add_action(self::CLEANUP_HOOK, [self::class, 'cleanup']);
        add_action(self::VACUUM_HOOK, [self::class, 'vacuum']);
        add_action('clean_post_cache', [self::class, 'flush_avada_query_cache'], 10, 0);
        add_action('clean_term_cache', [self::class, 'flush_avada_query_cache'], 10, 0);

        if (!wp_next_scheduled(self::VERIFY_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::VERIFY_HOOK);
        }
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
        if (!wp_next_scheduled(self::VACUUM_HOOK)) {
            wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', self::VACUUM_HOOK);
        }
    }

    public static function refresh_after_plugin_update(WP_Upgrader $upgrader, array $options): void
    {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = (array)($options['plugins'] ?? []);
        if (!$plugins && !empty($options['plugin'])) {
            $plugins = [(string)$options['plugin']];
        }

        $plugin_file = plugin_basename(__DIR__ . '/brodda-it.php');
        if (in_array($plugin_file, $plugins, true)) {
            self::install_drop_in();
        }
    }

    public static function install_drop_in(): void
    {
        $source = __DIR__ . '/object-cache.php';
        $target = WP_CONTENT_DIR . '/object-cache.php';

        if (!is_file($source)) {
            return;
        }

        if (is_file($target)) {
            $header = file_get_contents($target, false, null, 0, 2048);
            $is_ours = is_string($header) && str_contains($header, 'BRODDA_IT_SQLITE_CACHE_DROPIN');

            if (!$is_ours || hash_file('sha256', $source) === hash_file('sha256', $target)) {
                return;
            }
        }

        $temporary = $target . '.broddait-' . wp_generate_password(12, false, false) . '.tmp';
        if (!copy($source, $temporary)) {
            return;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
        }
    }

    public static function uninstall(): void
    {
        wp_clear_scheduled_hook(self::VERIFY_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        wp_clear_scheduled_hook(self::VACUUM_HOOK);

        $target = WP_CONTENT_DIR . '/object-cache.php';
        if (!is_file($target)) {
            return;
        }

        $header = file_get_contents($target, false, null, 0, 2048);
        if (is_string($header) && str_contains($header, 'BRODDA_IT_SQLITE_CACHE_DROPIN')) {
            wp_delete_file($target);
        }
    }

    public static function cleanup(): void
    {
        $database = self::open_database();
        if (!$database instanceof SQLite3) {
            return;
        }

        $now = time();
        $cutoff = $now - (self::RETENTION_DAYS * DAY_IN_SECONDS);
        @$database->exec(
                "DELETE FROM cache_entries WHERE (expires_at > 0 AND expires_at <= {$now}) OR created_at <= {$cutoff}"
        );
        @$database->exec('PRAGMA optimize');
        @$database->close();
    }

    public static function flush_avada_query_cache(): void
    {
        wp_cache_flush_group('fusion_library');
    }

    public static function vacuum(): void
    {
        $database = self::open_database();
        if (!$database instanceof SQLite3) {
            return;
        }

        @$database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        @$database->exec('VACUUM');
        @$database->exec('PRAGMA optimize');
        @$database->close();
    }

    private static function open_database()
    {
        $database_path = WP_CONTENT_DIR . '/.ht.broddait-cache.sqlite';
        if (!class_exists('SQLite3') || !is_file($database_path)) {
            return null;
        }

        try {
            $database = new SQLite3($database_path, SQLITE3_OPEN_READWRITE);
            $database->busyTimeout(5000);
            return $database;
        } catch (Throwable $exception) {
            return null;
        }
    }
}

BroddaITObjectCacheManager::init();
