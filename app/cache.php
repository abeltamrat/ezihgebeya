<?php
// Lightweight file-based cache for hot public queries/fragments (categories, home
// sections, listing counts). Deliberately file-based, not APCu/Redis: this project
// targets shared hosting where those extensions aren't guaranteed available, matching
// the same portability rule already applied to backups (pure-PHP dump, no mysqldump
// shell-out). Invalidation is time-based (a short TTL per call site) rather than
// write-hook invalidation, since hooking every place a listing/category can change
// (moderation, vendor edit, delete, cron expiry) is large surface area to keep correct;
// a short TTL bounds staleness to something acceptable for a count/fragment display.

/**
 * Resolve a writable cache directory without assuming every deployment already has
 * the newest config.php. File caching is an optimization and must never take the
 * storefront down when a shared host has an old config or restrictive permissions.
 */
function cache_directory(): ?string {
    static $resolved = false;
    static $directory = null;
    if ($resolved) return $directory;
    $resolved = true;

    $configured = defined('CACHE_DIR') ? (string)constant('CACHE_DIR') : trim((string)getenv('CACHE_DIR'));
    $preferred = $configured !== '' ? $configured : dirname(__DIR__) . '/storage/cache';
    if ((is_dir($preferred) || @mkdir($preferred, 0775, true)) && is_writable($preferred)) {
        return $directory = rtrim($preferred, '/\\');
    }

    // Keep different installations isolated when several sites share the same /tmp.
    $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/ezihgebeya-cache-' . substr(hash('sha256', dirname(__DIR__)), 0, 12);
    if ((is_dir($fallback) || @mkdir($fallback, 0700, true)) && is_writable($fallback)) {
        return $directory = $fallback;
    }
    return null;
}

function cache_path(string $key): ?string {
    $directory = cache_directory();
    if ($directory === null) return null;
    return $directory . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
}

// Returns the cached value for $key if it's younger than $ttlSeconds; otherwise calls
// $compute(), caches the result, and returns it. $compute()'s return value must be
// JSON-serializable.
function cache_remember(string $key, int $ttlSeconds, callable $compute) {
    $file = cache_path($key);
    if ($file !== null && is_file($file) && ((int)@filemtime($file) > time() - $ttlSeconds)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
    }
    $value = $compute();
    if ($file !== null) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) @file_put_contents($file, $encoded, LOCK_EX);
    }
    return $value;
}

function cache_forget(string $key): void {
    $file = cache_path($key);
    if ($file !== null && is_file($file)) @unlink($file);
}
