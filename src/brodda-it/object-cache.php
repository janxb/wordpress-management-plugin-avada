<?php
/**
 * brodda.IT SQLite object-cache drop-in.
 *
 * WordPress loads this file from wp-content/object-cache.php before plugins.
 */

defined('ABSPATH') || exit;

const BRODDA_IT_SQLITE_CACHE_DROPIN = '1';

function wp_cache_init(): void
{
    $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

function wp_cache_add($key, $data, $group = '', $expire = 0): bool
{
    return $GLOBALS['wp_object_cache']->add($key, $data, $group, (int)$expire);
}

function wp_cache_add_multiple(array $data, $group = '', $expire = 0): array
{
    return $GLOBALS['wp_object_cache']->add_multiple($data, $group, (int)$expire);
}

function wp_cache_replace($key, $data, $group = '', $expire = 0): bool
{
    return $GLOBALS['wp_object_cache']->replace($key, $data, $group, (int)$expire);
}

function wp_cache_set($key, $data, $group = '', $expire = 0): bool
{
    return $GLOBALS['wp_object_cache']->set($key, $data, $group, (int)$expire);
}

function wp_cache_set_multiple(array $data, $group = '', $expire = 0): array
{
    return $GLOBALS['wp_object_cache']->set_multiple($data, $group, (int)$expire);
}

function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
    return $GLOBALS['wp_object_cache']->get($key, $group, (bool)$force, $found);
}

function wp_cache_get_multiple($keys, $group = '', $force = false): array
{
    return $GLOBALS['wp_object_cache']->get_multiple($keys, $group, (bool)$force);
}

function wp_cache_delete($key, $group = '', $deprecated = false): bool
{
    return $GLOBALS['wp_object_cache']->delete($key, $group, (bool)$deprecated);
}

function wp_cache_delete_multiple(array $keys, $group = ''): array
{
    return $GLOBALS['wp_object_cache']->delete_multiple($keys, $group);
}

function wp_cache_incr($key, $offset = 1, $group = '')
{
    return $GLOBALS['wp_object_cache']->incr($key, (int)$offset, $group);
}

function wp_cache_decr($key, $offset = 1, $group = '')
{
    return $GLOBALS['wp_object_cache']->decr($key, (int)$offset, $group);
}

function wp_cache_flush(): bool
{
    return $GLOBALS['wp_object_cache']->flush();
}

function wp_cache_flush_runtime(): bool
{
    return $GLOBALS['wp_object_cache']->flush_runtime();
}

function wp_cache_flush_group($group): bool
{
    return $GLOBALS['wp_object_cache']->flush_group($group);
}

function wp_cache_close(): bool
{
    return $GLOBALS['wp_object_cache']->close();
}

function wp_cache_add_global_groups($groups): void
{
    $GLOBALS['wp_object_cache']->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups($groups): void
{
    $GLOBALS['wp_object_cache']->add_non_persistent_groups($groups);
}

function wp_cache_switch_to_blog($blog_id): void
{
    $GLOBALS['wp_object_cache']->switch_to_blog((int)$blog_id);
}

function wp_cache_reset(): bool
{
    $GLOBALS['wp_object_cache']->reset();
    return true;
}

function wp_cache_supports($feature): bool
{
    return in_array($feature, [
            'add_multiple',
            'set_multiple',
            'get_multiple',
            'delete_multiple',
            'flush_runtime',
            'flush_group',
    ], true);
}

class WP_Object_Cache
{
    public int $cache_hits = 0;
    public int $cache_misses = 0;

    /** @var array<string, array<string, array{0: int, 1: mixed}>> */
    private array $cache = [];
    private array $global_groups = [];
    private array $non_persistent_groups = [];
    private string $blog_prefix = '';
    private string $database_path;
    private $database = null;

    public function __construct()
    {
        $this->database_path = WP_CONTENT_DIR . '/.ht.broddait-cache.sqlite';
        $this->blog_prefix = is_multisite() ? get_current_blog_id() . ':' : '';
        $this->open_database();
    }

    public function __destruct()
    {
        if ($this->database instanceof SQLite3) {
            @$this->database->close();
        }
    }

    public function add($key, $data, $group = 'default', $expire = 0): bool
    {
        if ((function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition())
                || !$this->valid_key($key)) {
            return false;
        }

        [$key, $group] = $this->normalize($key, $group);
        $expires_at = $this->expiration($expire);

        if (isset($this->non_persistent_groups[$group]) || !$this->database instanceof SQLite3) {
            if ($this->memory_record($key, $group) !== null) {
                return false;
            }
            $this->cache[$group][$key] = [$expires_at, $this->copy_value($data)];
            return true;
        }

        $this->delete_expired_key($key, $group);
        $statement = @$this->database->prepare(
                'INSERT OR IGNORE INTO cache_entries (cache_key, cache_group, expires_at, created_at, cache_value) '
                . 'VALUES (:key, :group, :expires, :created, :value)'
        );
        if (!$statement) {
            return false;
        }
        $this->bind_record($statement, $key, $group, $expires_at, $data);
        $result = @$statement->execute();
        $added = $result !== false && $this->database->changes() === 1;
        if ($result instanceof SQLite3Result) {
            $result->finalize();
        }
        if ($added) {
            $this->cache[$group][$key] = [$expires_at, $this->copy_value($data)];
        }
        return $added;
    }

    public function add_multiple(array $data, $group = 'default', $expire = 0): array
    {
        return $this->apply_multiple($data, fn($key, $value) => $this->add($key, $value, $group, $expire));
    }

    public function replace($key, $data, $group = 'default', $expire = 0): bool
    {
        $found = false;
        $this->get($key, $group, false, $found);
        return $found && $this->set($key, $data, $group, $expire);
    }

    public function set($key, $data, $group = 'default', $expire = 0): bool
    {
        if (!$this->valid_key($key)) {
            return false;
        }
        [$key, $group] = $this->normalize($key, $group);
        $expires_at = $this->expiration($expire);

        if (isset($this->non_persistent_groups[$group]) || !$this->database instanceof SQLite3) {
            $this->cache[$group][$key] = [$expires_at, $this->copy_value($data)];
            return true;
        }

        $statement = @$this->database->prepare(
                'INSERT OR REPLACE INTO cache_entries (cache_key, cache_group, expires_at, created_at, cache_value) '
                . 'VALUES (:key, :group, :expires, :created, :value)'
        );
        if (!$statement) {
            return false;
        }
        $this->bind_record($statement, $key, $group, $expires_at, $data);
        $result = @$statement->execute();
        $success = $result !== false;
        if ($result instanceof SQLite3Result) {
            $result->finalize();
        }
        if ($success) {
            $this->cache[$group][$key] = [$expires_at, $this->copy_value($data)];
        }
        return $success;
    }

    public function set_multiple(array $data, $group = 'default', $expire = 0): array
    {
        return $this->apply_multiple($data, fn($key, $value) => $this->set($key, $value, $group, $expire));
    }

    public function get($key, $group = 'default', $force = false, &$found = null)
    {
        $found = false;
        if (!$this->valid_key($key)) {
            return $this->miss();
        }
        [$key, $group] = $this->normalize($key, $group);

        if (!$force) {
            $record = $this->memory_record($key, $group);
            if ($record !== null) {
                $found = true;
                $this->cache_hits++;
                return $this->copy_value($record[1]);
            }
        }

        if (isset($this->non_persistent_groups[$group]) || !$this->database instanceof SQLite3) {
            return $this->miss();
        }

        $statement = @$this->database->prepare(
                'SELECT expires_at, cache_value FROM cache_entries WHERE cache_key = :key AND cache_group = :group LIMIT 1'
        );
        if (!$statement) {
            return $this->miss();
        }
        $statement->bindValue(':key', $key, SQLITE3_TEXT);
        $statement->bindValue(':group', $group, SQLITE3_TEXT);
        $result = @$statement->execute();
        $row = $result instanceof SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        if ($result instanceof SQLite3Result) {
            $result->finalize();
        }
        if (!is_array($row)) {
            return $this->miss();
        }

        $expires_at = (int)$row['expires_at'];
        if ($this->expired($expires_at)) {
            $this->delete_normalized($key, $group);
            return $this->miss();
        }
        $serialized = (string)$row['cache_value'];
        $value = @unserialize($serialized, ['allowed_classes' => true]);
        if ($value === false && $serialized !== serialize(false)) {
            $this->delete_normalized($key, $group);
            return $this->miss();
        }

        $this->cache[$group][$key] = [$expires_at, $value];
        $found = true;
        $this->cache_hits++;
        return $this->copy_value($value);
    }

    public function get_multiple($keys, $group = 'default', $force = false): array
    {
        $result = [];
        foreach ((array)$keys as $key) {
            $result[$key] = $this->get($key, $group, $force);
        }
        return $result;
    }

    public function delete($key, $group = 'default', $deprecated = false): bool
    {
        if (!$this->valid_key($key)) {
            return false;
        }
        [$key, $group] = $this->normalize($key, $group);
        $existed = $this->memory_record($key, $group) !== null;
        unset($this->cache[$group][$key]);

        if (isset($this->non_persistent_groups[$group]) || !$this->database instanceof SQLite3) {
            return $existed;
        }
        return $this->delete_normalized($key, $group) || $existed;
    }

    public function delete_multiple(array $keys, $group = 'default'): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->delete($key, $group);
        }
        return $result;
    }

    public function incr($key, $offset = 1, $group = 'default')
    {
        $found = false;
        $value = $this->get($key, $group, true, $found);
        if (!$found || !is_numeric($value)) {
            return false;
        }
        $value = max(0, (int)$value + (int)$offset);
        return $this->set($key, $value, $group) ? $value : false;
    }

    public function decr($key, $offset = 1, $group = 'default')
    {
        return $this->incr($key, -(int)$offset, $group);
    }

    public function flush(): bool
    {
        $this->cache = [];
        return !$this->database instanceof SQLite3 || @$this->database->exec('DELETE FROM cache_entries');
    }

    public function flush_runtime(): bool
    {
        $this->cache = [];
        return true;
    }

    public function flush_group($group): bool
    {
        $group = $this->normalize_group($group);
        unset($this->cache[$group]);
        if (!$this->database instanceof SQLite3) {
            return true;
        }
        $statement = @$this->database->prepare('DELETE FROM cache_entries WHERE cache_group = :group');
        if (!$statement) {
            return false;
        }
        $statement->bindValue(':group', $group, SQLITE3_TEXT);
        return $statement->execute() !== false;
    }

    public function add_global_groups($groups): void
    {
        foreach ((array)$groups as $group) {
            $this->global_groups[$this->normalize_group($group)] = true;
        }
    }

    public function add_non_persistent_groups($groups): void
    {
        foreach ((array)$groups as $group) {
            $this->non_persistent_groups[$this->normalize_group($group)] = true;
        }
    }

    public function switch_to_blog($blog_id): void
    {
        $this->blog_prefix = is_multisite() ? (int)$blog_id . ':' : '';
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    public function close(): bool
    {
        $this->cache = [];
        if (!$this->database instanceof SQLite3) {
            return true;
        }

        $closed = @$this->database->close();
        $this->database = null;
        return $closed;
    }

    private function open_database(): void
    {
        if (!class_exists('SQLite3')) {
            return;
        }
        try {
            $this->database = new SQLite3($this->database_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $this->database->busyTimeout(1000);
            @$this->database->exec('PRAGMA journal_mode = WAL');
            @$this->database->exec('PRAGMA synchronous = NORMAL');
            @$this->database->exec(
                    'CREATE TABLE IF NOT EXISTS cache_entries ('
                    . 'cache_key TEXT NOT NULL, cache_group TEXT NOT NULL, expires_at INTEGER NOT NULL DEFAULT 0, '
                    . 'created_at INTEGER NOT NULL DEFAULT 0, '
                    . 'cache_value BLOB NOT NULL, PRIMARY KEY (cache_key, cache_group)) WITHOUT ROWID'
            );
            @$this->database->exec(
                    'CREATE INDEX IF NOT EXISTS cache_expiration ON cache_entries (expires_at) WHERE expires_at > 0'
            );
            @$this->database->exec('CREATE INDEX IF NOT EXISTS cache_created ON cache_entries (created_at)');
            @chmod($this->database_path, 0600);
        } catch (Throwable $exception) {
            $this->database = null;
        }
    }

    private function bind_record(SQLite3Stmt $statement, string $key, string $group, int $expires_at, $data): void
    {
        $statement->bindValue(':key', $key, SQLITE3_TEXT);
        $statement->bindValue(':group', $group, SQLITE3_TEXT);
        $statement->bindValue(':expires', $expires_at, SQLITE3_INTEGER);
        $statement->bindValue(':created', time(), SQLITE3_INTEGER);
        $statement->bindValue(':value', serialize($data), SQLITE3_BLOB);
    }

    private function delete_expired_key(string $key, string $group): void
    {
        $statement = @$this->database->prepare(
                'DELETE FROM cache_entries WHERE cache_key = :key AND cache_group = :group AND expires_at > 0 AND expires_at <= :now'
        );
        if ($statement) {
            $statement->bindValue(':key', $key, SQLITE3_TEXT);
            $statement->bindValue(':group', $group, SQLITE3_TEXT);
            $statement->bindValue(':now', time(), SQLITE3_INTEGER);
            @$statement->execute();
        }
    }

    private function delete_normalized(string $key, string $group): bool
    {
        $statement = @$this->database->prepare(
                'DELETE FROM cache_entries WHERE cache_key = :key AND cache_group = :group'
        );
        if (!$statement) {
            return false;
        }
        $statement->bindValue(':key', $key, SQLITE3_TEXT);
        $statement->bindValue(':group', $group, SQLITE3_TEXT);
        $result = @$statement->execute();
        return $result !== false && $this->database->changes() > 0;
    }

    private function memory_record(string $key, string $group): ?array
    {
        if (!array_key_exists($key, $this->cache[$group] ?? [])) {
            return null;
        }
        $record = $this->cache[$group][$key];
        if ($this->expired($record[0])) {
            unset($this->cache[$group][$key]);
            return null;
        }
        return $record;
    }

    private function normalize($key, $group): array
    {
        $group = $this->normalize_group($group);
        if ($this->blog_prefix !== '' && !isset($this->global_groups[$group])) {
            $key = $this->blog_prefix . $key;
        }
        return [(string)$key, $group];
    }

    private function normalize_group($group): string
    {
        return (string)$group !== '' ? (string)$group : 'default';
    }

    private function expiration($expire): int
    {
        return (int)$expire > 0 ? time() + (int)$expire : 0;
    }

    private function expired(int $expires_at): bool
    {
        return $expires_at > 0 && $expires_at <= time();
    }

    private function valid_key($key): bool
    {
        return is_int($key) || (is_string($key) && $key !== '');
    }

    private function miss()
    {
        $this->cache_misses++;
        return false;
    }

    private function copy_value($value)
    {
        return is_object($value) ? clone $value : $value;
    }

    private function apply_multiple(array $data, callable $callback): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $callback($key, $value);
        }
        return $result;
    }
}
