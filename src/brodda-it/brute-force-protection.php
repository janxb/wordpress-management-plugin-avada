<?php

defined('ABSPATH') or die();

final class BroddaITBruteForceProtection
{
    private const int MAX_ATTEMPTS = 5;
    private const int ATTEMPT_WINDOW = 30 * MINUTE_IN_SECONDS;
    private const int LOCKOUT_DURATION = 30 * MINUTE_IN_SECONDS;
    private const string TRANSIENT_PREFIX = 'broddait_login_attempts_';

    public static function init(): void
    {
        add_filter('authenticate', [self::class, 'block_locked_login'], 100, 3);
        add_action('wp_login_failed', [self::class, 'record_failed_login'], 10, 2);
        add_action('wp_login', [self::class, 'clear_attempts_after_login'], 20, 2);
    }

    public static function block_locked_login($user, string $username, string $password)
    {
        $key = self::transient_key();
        if ($key === null) {
            return $user;
        }

        $state = get_transient($key);
        if (!is_array($state) || empty($state['locked_until'])) {
            return $user;
        }

        $remaining = (int)$state['locked_until'] - time();
        if ($remaining <= 0) {
            delete_transient($key);
            return $user;
        }

        $minutes = max(1, (int)ceil($remaining / MINUTE_IN_SECONDS));

        return new WP_Error(
                'broddait_login_rate_limited',
                sprintf(
                        'Too many failed login attempts. Please try again in %d minute%s.',
                        $minutes,
                        $minutes === 1 ? '' : 's'
                )
        );
    }

    public static function record_failed_login(string $username, WP_Error $error): void
    {
        $key = self::transient_key();
        if ($key === null) {
            return;
        }

        $now = time();
        $state = get_transient($key);

        if (is_array($state) && !empty($state['locked_until']) && (int)$state['locked_until'] > $now) {
            return;
        }

        if (!is_array($state)
                || empty($state['window_started'])
                || ((int)$state['window_started'] + self::ATTEMPT_WINDOW) <= $now) {
            $state = [
                    'attempts' => 0,
                    'window_started' => $now,
                    'locked_until' => 0,
            ];
        }

        $state['attempts'] = (int)$state['attempts'] + 1;

        if ($state['attempts'] >= self::MAX_ATTEMPTS) {
            $state['locked_until'] = $now + self::LOCKOUT_DURATION;
            set_transient($key, $state, self::LOCKOUT_DURATION);
            return;
        }

        $window_expires_in = max(
                1,
                ((int)$state['window_started'] + self::ATTEMPT_WINDOW) - $now
        );
        set_transient($key, $state, $window_expires_in);
    }

    public static function clear_attempts_after_login(string $user_login, WP_User $user): void
    {
        $key = self::transient_key();
        if ($key !== null) {
            delete_transient($key);
        }
    }

    private static function transient_key(): ?string
    {
        $ip_address = self::remote_address();
        if ($ip_address === null) {
            return null;
        }

        return self::TRANSIENT_PREFIX . hash('sha256', $ip_address);
    }

    private static function remote_address(): ?string
    {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return null;
        }

        $ip_address = (string)wp_unslash($_SERVER['REMOTE_ADDR']);
        return filter_var($ip_address, FILTER_VALIDATE_IP) !== false ? $ip_address : null;
    }
}

BroddaITBruteForceProtection::init();
