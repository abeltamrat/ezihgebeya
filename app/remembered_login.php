<?php
/**
 * Secure remembered-device login.
 *
 * The HttpOnly cookie contains a random selector and validator. Only the
 * validator's SHA-256 hash is stored in MySQL, so a database read alone cannot
 * be turned into a browser credential. The credential is rotated after every
 * successful quick login and expires after 30 days.
 */

const REMEMBERED_LOGIN_COOKIE = 'ezg_quick_login';
const REMEMBERED_LOGIN_TTL_DAYS = 30;
// A v2 cookie with 20 selector/validator pairs remains comfortably below the
// common 4 KB per-cookie browser limit while supporting shared work devices.
const REMEMBERED_LOGIN_MAX_PROFILES = 20;

function remembered_login_available(): bool {
    return db_table_exists('remembered_login_tokens');
}

function remembered_login_cookie_path(): string {
    $basePath = trim((string)(parse_url((string)BASE_URL, PHP_URL_PATH) ?? ''), '/');
    return $basePath === '' ? '/' : '/' . $basePath . '/';
}

function remembered_login_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function remembered_login_write_cookie(string $value, int $expires): void {
    if (headers_sent()) return;
    setcookie(REMEMBERED_LOGIN_COOKIE, $value, [
        'expires' => $expires,
        'path' => remembered_login_cookie_path(),
        'secure' => remembered_login_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($expires > time()) $_COOKIE[REMEMBERED_LOGIN_COOKIE] = $value;
    else unset($_COOKIE[REMEMBERED_LOGIN_COOKIE]);
}

function remembered_login_clear_cookie(): void {
    remembered_login_write_cookie('', time() - 3600);
}

/** @return array{selector:string,validator:string}|null */
function remembered_login_parse_cookie(?string $cookie = null): ?array {
    $cookie ??= (string)($_COOKIE[REMEMBERED_LOGIN_COOKIE] ?? '');
    if (!preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D', $cookie, $matches)) return null;
    return ['selector' => $matches[1], 'validator' => $matches[2]];
}

/** @return list<array{selector:string,validator:string}> */
function remembered_login_parse_profiles(?string $cookie = null): array {
    $cookie ??= (string)($_COOKIE[REMEMBERED_LOGIN_COOKIE] ?? '');
    if ($legacy = remembered_login_parse_cookie($cookie)) return [$legacy];
    if (!str_starts_with($cookie, 'v2.')) return [];
    $encoded = substr($cookie, 3);
    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
    $items = $decoded !== false ? json_decode($decoded, true) : null;
    if (!is_array($items)) return [];

    $profiles = [];
    foreach ($items as $item) {
        $selector = strtolower((string)($item[0] ?? ''));
        $validator = strtolower((string)($item[1] ?? ''));
        if (preg_match('/\A[a-f0-9]{32}\z/D', $selector) && preg_match('/\A[a-f0-9]{64}\z/D', $validator)) {
            $profiles[$selector] = compact('selector', 'validator');
        }
    }
    return array_slice(array_values($profiles), 0, REMEMBERED_LOGIN_MAX_PROFILES);
}

/** @param list<array{selector:string,validator:string}> $profiles */
function remembered_login_write_profiles(array $profiles, ?int $expires = null): void {
    $profiles = array_slice(array_values($profiles), 0, REMEMBERED_LOGIN_MAX_PROFILES);
    if (!$profiles) {
        remembered_login_clear_cookie();
        return;
    }
    $payload = array_map(static fn(array $item): array => [$item['selector'], $item['validator']], $profiles);
    $encoded = rtrim(strtr(base64_encode((string)json_encode($payload)), '+/', '-_'), '=');
    remembered_login_write_cookie('v2.' . $encoded, $expires ?? (time() + REMEMBERED_LOGIN_TTL_DAYS * 86400));
}

/**
 * Return every validated profile stored on this browser.
 * Invalid, expired, or disabled-account credentials are revoked immediately.
 */
function remembered_login_find_all(): array {
    if (!remembered_login_available()) return [];
    $profiles = remembered_login_parse_profiles();
    if (!$profiles) {
        if ((string)($_COOKIE[REMEMBERED_LOGIN_COOKIE] ?? '') !== '') remembered_login_clear_cookie();
        return [];
    }

    $validRecords = [];
    $validProfiles = [];
    foreach ($profiles as $parts) {
        $record = row("SELECT rt.id AS remembered_token_id, rt.user_id, rt.selector, rt.token_hash,
                rt.expires_at, UNIX_TIMESTAMP(rt.expires_at) AS expires_at_unix, rt.last_used_at, rt.created_at,
                u.full_name, u.phone, u.email, u.account_type, u.status, u.phone_verified_at
            FROM remembered_login_tokens rt
            JOIN users u ON u.id = rt.user_id
            WHERE rt.selector = ? LIMIT 1", [$parts['selector']]);

        $otpSatisfied = $record && (!(bool)sys('auth.otp_required', 1) || !empty($record['phone_verified_at']));
        $valid = $record
            && $record['status'] === 'active'
            && $otpSatisfied
            && (int)$record['expires_at_unix'] > time()
            && hash_equals((string)$record['token_hash'], hash('sha256', $parts['validator']));
        if ($valid) {
            $record['cookie_validator'] = $parts['validator'];
            $validRecords[] = $record;
            $validProfiles[] = $parts;
        } elseif ($record) {
            q("DELETE FROM remembered_login_tokens WHERE id = ?", [$record['remembered_token_id']]);
        }
    }
    if (count($validProfiles) !== count($profiles)) remembered_login_write_profiles($validProfiles);
    return $validRecords;
}

/** Return one selected profile, defaulting to the first remembered account. */
function remembered_login_find(?string $selector = null): ?array {
    $records = remembered_login_find_all();
    if ($selector === null || $selector === '') return $records[0] ?? null;
    foreach ($records as $record) {
        if (hash_equals((string)$record['selector'], $selector)) return $record;
    }
    return null;
}

/** Add or replace one quick-login profile on this browser. */
function remembered_login_create(int $userId): bool {
    if (!remembered_login_available()) return false;

    try {
        $user = row("SELECT status, account_type, phone_verified_at FROM users WHERE id = ?", [$userId]);
        if (!$user || $user['status'] !== 'active') return false;
        if ((bool)sys('auth.otp_required', 1) && empty($user['phone_verified_at'])) return false;

        $existingRecords = remembered_login_find_all();
        $existingProfiles = [];
        foreach ($existingRecords as $record) {
            if ((int)$record['user_id'] === $userId) {
                q("DELETE FROM remembered_login_tokens WHERE id = ?", [$record['remembered_token_id']]);
                continue;
            }
            $existingProfiles[] = [
                'selector' => (string)$record['selector'],
                'validator' => (string)$record['cookie_validator'],
            ];
        }
        q("DELETE FROM remembered_login_tokens WHERE expires_at <= NOW()");

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        q("INSERT INTO remembered_login_tokens (user_id, selector, token_hash, user_agent, expires_at)
           VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL " . REMEMBERED_LOGIN_TTL_DAYS . " DAY))", [
            $userId,
            $selector,
            hash('sha256', $validator),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        // Bound abandoned trusted-browser rows without preventing normal multi-device use.
        $devices = rows("SELECT id FROM remembered_login_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC", [$userId]);
        foreach (array_slice($devices, 5) as $device) {
            q("DELETE FROM remembered_login_tokens WHERE id = ?", [$device['id']]);
        }

        $existingProfiles[] = compact('selector', 'validator');
        remembered_login_write_profiles(array_slice($existingProfiles, -REMEMBERED_LOGIN_MAX_PROFILES));
        return true;
    } catch (Throwable $e) {
        error_log('Remembered login creation failed: ' . $e->getMessage());
        remembered_login_clear_cookie();
        return false;
    }
}

/** Validate and rotate the remembered account after password verification. */
function remembered_login_authenticate(?string $selectedSelector = null): ?array {
    $record = remembered_login_find($selectedSelector);
    if (!$record) return null;

    try {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $updated = q("UPDATE remembered_login_tokens
           SET selector = ?, token_hash = ?, user_agent = ?, last_used_at = NOW()
           WHERE id = ? AND selector = ? AND token_hash = ? AND expires_at > NOW()", [
            $selector,
            hash('sha256', $validator),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $record['remembered_token_id'],
            $record['selector'],
            $record['token_hash'],
        ]);
        if ($updated->rowCount() !== 1) {
            remembered_login_forget((string)$record['selector']);
            return null;
        }
        $profiles = [];
        foreach (remembered_login_parse_profiles() as $profile) {
            $profiles[] = $profile['selector'] === $record['selector']
                ? compact('selector', 'validator')
                : $profile;
        }
        remembered_login_write_profiles($profiles);
        return $record;
    } catch (Throwable $e) {
        error_log('Remembered login rotation failed: ' . $e->getMessage());
        remembered_login_forget();
        return null;
    }
}

/** Remove one selected profile, or every remembered profile when none is selected. */
function remembered_login_forget(?string $selectedSelector = null): void {
    try {
        $remaining = [];
        foreach (remembered_login_parse_profiles() as $parts) {
            if ($selectedSelector === null || hash_equals($parts['selector'], $selectedSelector)) {
                if (remembered_login_available()) q("DELETE FROM remembered_login_tokens WHERE selector = ?", [$parts['selector']]);
            } else {
                $remaining[] = $parts;
            }
        }
    } catch (Throwable $e) {
        // A database problem must never trap someone in a browser credential.
        error_log('Remembered login removal failed: ' . $e->getMessage());
    } finally {
        if ($selectedSelector === null) remembered_login_clear_cookie();
        else remembered_login_write_profiles($remaining);
    }
}

/** Revoke every remembered browser after a password reset or account deletion. */
function remembered_login_revoke_user(int $userId): void {
    if (remembered_login_available()) q("DELETE FROM remembered_login_tokens WHERE user_id = ?", [$userId]);
}
