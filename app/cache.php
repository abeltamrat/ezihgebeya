<?php
// Lightweight file-based cache for hot public queries/fragments (categories, home
// sections, listing counts). Deliberately file-based, not APCu/Redis: this project
// targets shared hosting where those extensions aren't guaranteed available, matching
// the same portability rule already applied to backups (pure-PHP dump, no mysqldump
// shell-out). Invalidation is time-based (a short TTL per call site) rather than
// write-hook invalidation, since hooking every place a listing/category can change
// (moderation, vendor edit, delete, cron expiry) is large surface area to keep correct;
// a short TTL bounds staleness to something acceptable for a count/fragment display.

function cache_path(string $key): string {
    return CACHE_DIR . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
}

// Returns the cached value for $key if it's younger than $ttlSeconds; otherwise calls
// $compute(), caches the result, and returns it. $compute()'s return value must be
// JSON-serializable.
function cache_remember(string $key, int $ttlSeconds, callable $compute) {
    $file = cache_path($key);
    if (is_file($file) && (filemtime($file) > time() - $ttlSeconds)) {
        $raw = file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
    }
    $value = $compute();
    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0775, true);
    file_put_contents($file, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $value;
}

function cache_forget(string $key): void {
    $file = cache_path($key);
    if (is_file($file)) unlink($file);
}
