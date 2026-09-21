<?php
/**
 * ShopInnKart - Lightweight file cache.
 *
 * Used for expensive read-only queries (menus, homepage widget data,
 * category trees). Writes are cheap and invalidation is explicit, so
 * anything the admin edits calls Cache::flush() or Cache::forget().
 */

declare(strict_types=1);

final class Cache
{
    private static ?string $dir = null;

    /** In-process memo so repeated reads in one request hit memory. */
    private static array $memo = [];

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = STORAGE_PATH . '/cache';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0775, true);
            }
        }
        return self::$dir;
    }

    private static function enabled(): bool
    {
        return setting_bool('cache_enabled', true);
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.cache';
    }

    /** Fetch a cached value, or null when missing/expired. */
    public static function get(string $key)
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        if (!self::enabled()) {
            return null;
        }

        $file = self::path($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || !isset($payload['expires'], $payload['value'])) {
            @unlink($file);
            return null;
        }
        if ($payload['expires'] > 0 && $payload['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return self::$memo[$key] = $payload['value'];
    }

    /** Store a value. $ttl of 0 means "until explicitly cleared". */
    public static function put(string $key, $value, ?int $ttl = null): void
    {
        self::$memo[$key] = $value;

        if (!self::enabled()) {
            return;
        }
        $ttl = $ttl ?? setting_int('cache_ttl', 600);
        $payload = [
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value'   => $value,
        ];
        @file_put_contents(self::path($key), serialize($payload), LOCK_EX);
    }

    /**
     * Fetch, or compute and store on a miss.
     *
     * $value = Cache::remember('menu.main', 600, fn() => build_menu('main'));
     */
    public static function remember(string $key, ?int $ttl, callable $callback)
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        self::put($key, $value, $ttl);
        return $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$memo[$key]);
        $file = self::path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Clear everything. Called after any admin content change. */
    public static function flush(): int
    {
        self::$memo = [];
        $count = 0;
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    /** Total bytes currently held in the cache directory. */
    public static function size(): int
    {
        $bytes = 0;
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            $bytes += (int) @filesize($file);
        }
        return $bytes;
    }
}

/** Shorthand used across the storefront. */
function cache_remember(string $key, ?int $ttl, callable $callback)
{
    return Cache::remember($key, $ttl, $callback);
}

/** Invalidate storefront caches after a content change. */
function cache_bust(): void
{
    Cache::flush();
}
