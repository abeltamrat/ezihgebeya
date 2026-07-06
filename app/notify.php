<?php
/**
 * Notifications (§15), OTP (§5.1), audit log (§22.4.9).
 * SMS/email channels are pluggable: on shared hosting wire a real gateway in
 * send_sms()/send_email(); until then they append to database/outbox.log so the
 * full flow is testable locally (the OTP code is also flashed in DEV_MODE).
 */

// ---------- outbound channels ----------
function outbox_log(string $channel, string $to, string $message): void {
    $line = '[' . date('c') . "] $channel → $to: " . str_replace(["\r", "\n"], ' ', $message) . "\n";
    @file_put_contents(__DIR__ . '/../database/outbox.log', $line, FILE_APPEND | LOCK_EX);
}

/** Send an SMS: always logged to database/outbox.log; when a gateway URL is configured
 *  in admin → Settings → Notifications (and DEV_MODE is off), the gateway is called too. */
function send_sms(string $phone, string $message): void {
    outbox_log('sms', $phone, $message);
    $gateway = (string)sys('notifications.sms_gateway_url', '');
    if ($gateway === '' || DEV_MODE) return;
    $url = str_replace(['{phone}', '{message}'], [rawurlencode($phone), rawurlencode($message)], $gateway);
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5]);
            curl_exec($ch);
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
        }
    } catch (Throwable $e) {
        outbox_log('sms-error', $phone, $e->getMessage());
    }
}

/** Send an email. MVP: PHP mail() if configured, always logged to the outbox. */
function send_email(string $to, string $subject, string $body): void {
    outbox_log('email', $to, "$subject — $body");
    if (!DEV_MODE && function_exists('mail')) {
        @mail($to, $subject, $body, 'From: ' . site_name() . ' <' . sys('notifications.email_from', 'no-reply@ezihgebeya.local') . '>');
    }
}

// ---------- in-app notifications ----------
/** Create an in-app notification (and mirror to SMS/email for high-value events). */
function notify(?int $userId, string $type, string $title, string $url = '', string $body = '', bool $alsoSms = false): void {
    if (!$userId) return;
    try {
        q("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?,?,?,?,?)",
          [$userId, $type, mb_substr($title, 0, 220), $body ?: null, $url ?: null]);
        if ($alsoSms && sys('notifications.sms_mirror', 1)) {
            $phone = val("SELECT phone FROM users WHERE id = ?", [$userId]);
            if ($phone) send_sms($phone, site_name() . ': ' . $title);
        }
    } catch (Throwable $e) {
        // notifications must never break the main action
    }
}

/** Notify the owner of a business. */
function notify_business(int $businessId, string $type, string $title, string $url = '', string $body = '', bool $alsoSms = false): void {
    $ownerId = (int)val("SELECT user_id FROM businesses WHERE id = ?", [$businessId]);
    notify($ownerId ?: null, $type, $title, $url, $body, $alsoSms);
}

function unread_notifications(int $userId): int {
    try {
        return (int)val("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL", [$userId]);
    } catch (Throwable $e) {
        return 0;
    }
}

// ---------- OTP (§5.1) ----------
/** Create + send a 6-digit OTP. Returns false when rate-limited. */
function otp_send(string $phone, string $purpose): bool {
    $recent = (int)val("SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND purpose = ? AND created_at > NOW() - INTERVAL 10 MINUTE", [$phone, $purpose]);
    if ($recent >= 3) return false;
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q("UPDATE otp_codes SET used_at = NOW() WHERE phone = ? AND purpose = ? AND used_at IS NULL", [$phone, $purpose]);
    q("INSERT INTO otp_codes (phone, purpose, code, expires_at) VALUES (?,?,?, NOW() + INTERVAL 10 MINUTE)", [$phone, $purpose, $code]);
    $label = $purpose === 'reset_password' ? 'password reset' : 'verification';
    send_sms($phone, SITE_NAME . " $label code: $code (valid 10 minutes)");
    if (DEV_MODE) flash("DEV mode — SMS gateway not configured, your $label code is: $code", 'success');
    return true;
}

/** Verify an OTP; marks it used on success. Max 5 attempts per code (§29.2.8). */
function otp_verify(string $phone, string $purpose, string $code): bool {
    $row = row("SELECT * FROM otp_codes WHERE phone = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
                ORDER BY id DESC LIMIT 1", [$phone, $purpose]);
    if (!$row || $row['attempts'] >= 5) return false;
    if (!hash_equals($row['code'], trim($code))) {
        q("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?", [$row['id']]);
        return false;
    }
    q("UPDATE otp_codes SET used_at = NOW() WHERE id = ?", [$row['id']]);
    return true;
}

// ---------- login throttling (§22.1.4) ----------
function login_throttled(string $identity): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $window = (int)sys('auth.login_lockout_min', 15);
    $fails = (int)val("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL $window MINUTE
                       AND (identity = ? OR ip = ?)", [$identity, $ip]);
    return $fails >= (int)sys('auth.login_max_attempts', 8);
}

function login_record(string $identity, bool $success): void {
    q("INSERT INTO login_attempts (identity, ip, success) VALUES (?,?,?)",
      [mb_substr($identity, 0, 150), $_SERVER['REMOTE_ADDR'] ?? null, $success ? 1 : 0]);
}

// ---------- audit log (§22.4.9) ----------
function audit(string $action, string $targetType = '', $targetId = null, string $details = ''): void {
    $u = auth();
    if (!$u || !is_admin($u)) return;
    try {
        q("INSERT INTO audit_logs (admin_id, action, target_type, target_id, details, ip) VALUES (?,?,?,?,?,?)",
          [$u['id'], mb_substr($action, 0, 100), $targetType ?: null, $targetId ?: null, $details ?: null, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        // never block the admin action
    }
}
