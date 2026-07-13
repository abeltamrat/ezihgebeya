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

/**
 * Return the validated token plus the small user shape required by /login.
 * Invalid, expired, or disabled-account credentials are revoked immediately.
 */
function remembered_login_find(): ?array {
    $rawCookie = (string)($_COOKIE[REMEMBERED_LOGIN_COOKIE] ?? '');
    if ($rawCookie === '' || !remembered_login_available()) return null;

    $parts = remembered_login_parse_cookie($rawCookie);
    if (!$parts) {
        remembered_login_clear_cookie();
        return null;
    }

    $record = row("SELECT rt.id AS remembered_token_id, rt.user_id, rt.selector, rt.token_hash,
            rt.expires_at, rt.last_used_at, rt.created_at,
            u.full_name, u.phone, u.email, u.account_type, u.status, u.phone_verified_at
        FROM remembered_login_tokens rt
        JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = ? LIMIT 1", [$parts['selector']]);

    $otpSatisfied = $record && (!(bool)sys('auth.otp_required', 1) || !empty($record['phone_verified_at']));
    $valid = $record
        && $record['status'] === 'active'
        && !in_array($record['account_type'], ['admin', 'super_admin'], true)
        && $otpSatisfied
        && strtotime((string)$record['expires_at']) > time()
        && hash_equals((string)$record['token_hash'], hash('sha256', $parts['validator']));

    if (!$valid) {
        if ($record) q("DELETE FROM remembered_login_tokens WHERE id = ?", [$record['remembered_token_id']]);
        remembered_login_clear_cookie();
        return null;
    }

    return $record;
}

/** Create or replace the quick-login credential for this browser. */
function remembered_login_create(int $userId): bool {
    if (!remembered_login_available()) return false;

    try {
        $user = row("SELECT status, account_type, phone_verified_at FROM users WHERE id = ?", [$userId]);
        if (!$user || $user['status'] !== 'active' || in_array($user['account_type'], ['admin', 'super_admin'], true)) return false;
        if ((bool)sys('auth.otp_required', 1) && empty($user['phone_verified_at'])) return false;

        $old = remembered_login_parse_cookie();
        if ($old) q("DELETE FROM remembered_login_tokens WHERE selector = ?", [$old['selector']]);
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

        remembered_login_write_cookie($selector . '.' . $validator, time() + REMEMBERED_LOGIN_TTL_DAYS * 86400);
        return true;
    } catch (Throwable $e) {
        error_log('Remembered login creation failed: ' . $e->getMessage());
        remembered_login_clear_cookie();
        return false;
    }
}

/** Validate, rotate, and return the remembered account for one-tap login. */
function remembered_login_authenticate(): ?array {
    $record = remembered_login_find();
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
            remembered_login_clear_cookie();
            return null;
        }
        remembered_login_write_cookie($selector . '.' . $validator, strtotime((string)$record['expires_at']));
        return $record;
    } catch (Throwable $e) {
        error_log('Remembered login rotation failed: ' . $e->getMessage());
        remembered_login_forget();
        return null;
    }
}

/** Remove only the credential associated with the current browser. */
function remembered_login_forget(): void {
    $parts = remembered_login_parse_cookie();
    if ($parts && remembered_login_available()) {
        q("DELETE FROM remembered_login_tokens WHERE selector = ?", [$parts['selector']]);
    }
    remembered_login_clear_cookie();
}

/** Revoke every remembered browser after a password reset or account deletion. */
function remembered_login_revoke_user(int $userId): void {
    if (remembered_login_available()) q("DELETE FROM remembered_login_tokens WHERE user_id = ?", [$userId]);
}
