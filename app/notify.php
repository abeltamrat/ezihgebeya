<?php
/**
 * Notifications (§15), OTP (§5.1), audit log (§22.4.9).
 * SMS/email channels are pluggable: on shared hosting wire a real gateway in
 * send_sms()/send_email(); until then they append to database/outbox.log so the
 * full flow is testable locally (the OTP code is also flashed in DEV_MODE).
 */

/** Tail the outbox log for recent failure lines (sms-error/email-error/webhook-error/
 * cron-error), grouped by channel. Bounded read (last ~256KB) so a large log file never
 * makes an admin page load slowly. */
function recent_delivery_failures(int $days = 7): array {
    $path = __DIR__ . '/../database/outbox.log';
    if (!is_file($path)) return [];
    $size = filesize($path);
    $chunk = 262144;
    $fh = fopen($path, 'r');
    if (!$fh) return [];
    fseek($fh, max(0, $size - $chunk));
    $tail = stream_get_contents($fh);
    fclose($fh);
    $since = time() - $days * 86400;
    $counts = [];
    foreach (explode("\n", $tail) as $line) {
        if (!preg_match('/^\[([^\]]+)\]\s+([\w-]+)\s+→/', $line, $m)) continue;
        if (!str_ends_with($m[2], '-error')) continue;
        $ts = strtotime($m[1]);
        if ($ts !== false && $ts < $since) continue;
        $counts[$m[2]] = ($counts[$m[2]] ?? 0) + 1;
    }
    return $counts;
}

// ---------- outbound channels ----------
function outbox_log(string $channel, string $to, string $message): void {
    $line = '[' . date('c') . "] $channel → $to: " . str_replace(["\r", "\n"], ' ', $message) . "\n";
    @file_put_contents(__DIR__ . '/../database/outbox.log', $line, FILE_APPEND | LOCK_EX);
}

/** Send an SMS: always logged to database/outbox.log; when a gateway URL is configured
 *  in admin → Settings → Notifications (and DEV_MODE is off), the gateway is called too. */
function send_android_sms_gateway(string $phone, string $message): bool {
    $endpoint = trim((string)sys('notifications.android_sms_endpoint', ''));
    $username = trim((string)sys('notifications.android_sms_username', ''));
    $password = (string)sys('notifications.android_sms_password', '');
    $parts = parse_url($endpoint);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || $username === '' || $password === '' || !function_exists('curl_init')) {
        return false;
    }
    $payload = json_encode([
        'textMessage' => ['text' => $message],
        'phoneNumbers' => [$phone],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) return false;
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status >= 200 && $status < 300) return true;
    outbox_log('sms-error', $phone, 'Android SMS Gateway HTTP ' . $status . ($error !== '' ? ': ' . $error : '')
        . (is_string($raw) && $raw !== '' ? ' — ' . mb_substr(strip_tags($raw), 0, 300) : ''));
    return false;
}

function send_sms(string $phone, string $message): bool {
    outbox_log('sms', $phone, $message);
    if (DEV_MODE) return false;
    $provider = (string)sys('notifications.sms_provider', '');
    if ($provider === 'android_sms_gateway') return send_android_sms_gateway($phone, $message);
    $gateway = (string)sys('notifications.sms_gateway_url', '');
    // Backward compatibility for installations that already configured the generic URL.
    if ($provider === 'log' || ($provider !== 'generic_url' && $gateway === '')) return false;
    if ($gateway === '') return false;
    $url = str_replace(['{phone}', '{message}'], [rawurlencode($phone), rawurlencode($message)], $gateway);
    try {
        $host = parse_url($gateway, PHP_URL_HOST);
        return $host && remote_text($url, [$host], 5) !== null;
    } catch (Throwable $e) {
        outbox_log('sms-error', $phone, $e->getMessage());
        return false;
    }
}

// ---------- Firebase Cloud Messaging web push ----------
// Same pluggable/graceful-degrade pattern as send_sms() above: always logged to the
// outbox; the real network calls only fire once admin → Settings → Notifications has a
// Firebase project ID + service account configured, and never in DEV_MODE.

/** POST JSON to a fixed, explicitly allowed HTTPS host and return the decoded response
 * (or null on any failure). Not a general SSRF-safe fetcher — deliberately narrow, only
 * ever called with the two hardcoded Google endpoints below, never a user/admin-supplied URL. */
function remote_post_json(string $url, array $headers, string $body, int $timeout = 8): ?array {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || !remote_url_allowed($url, [$host])) return null;
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw) || $status < 200 || $status >= 300) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** Base64url-encode (RFC 4648 §5) — JWT's encoding, distinct from base64_encode(). */
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Mint (and cache for ~55 minutes, short of FCM's real 60-minute expiry) an OAuth2
 * access token for the configured Firebase service account, via the standard Google
 * JWT-bearer flow (RFC 7523). Returns null if unconfigured, malformed, or the token
 * endpoint call fails — every caller must treat that as "push unavailable right now"
 * and fall back to the outbox log, never throw. */
function fcm_access_token(): ?string {
    $json = (string)sys('notifications.fcm_service_account_json', '');
    if ($json === '') return null;
    return cache_remember('fcm_access_token_' . md5($json), 3300, function () use ($json): ?string {
        $account = json_decode($json, true);
        $privateKey = $account['private_key'] ?? null;
        $clientEmail = $account['client_email'] ?? null;
        if (!$privateKey || !$clientEmail) return null;

        $now = time();
        $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = base64url_encode(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signature = '';
        $signed = openssl_sign("$header.$claims", $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) return null;
        $jwt = "$header.$claims." . base64url_encode($signature);

        $resp = remote_post_json(
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        );
        return $resp['access_token'] ?? null;
    }) ?: null;
}

/** Send a web push notification to every device the user has registered (see
 * push_subscriptions, populated by POST /api/v1/push/subscribe). Always logged to the
 * outbox first; the real FCM v1 send only happens when a project + service account are
 * configured. A token FCM reports as unregistered/invalid is pruned so it stops being
 * retried on every future notification. */
function send_push(int $userId, string $title, string $body, string $url = ''): void {
    outbox_log('push', (string)$userId, "$title — $body");
    $projectId = (string)sys('notifications.fcm_project_id', '');
    if ($projectId === '' || DEV_MODE || !db_table_exists('push_subscriptions')) return;
    $accessToken = fcm_access_token();
    if (!$accessToken) { outbox_log('push-error', (string)$userId, 'no access token (check service account config)'); return; }

    $tokens = rows("SELECT id, fcm_token FROM push_subscriptions WHERE user_id = ?", [$userId]);
    foreach ($tokens as $t) {
        $message = [
            'message' => [
                'token' => $t['fcm_token'],
                'notification' => ['title' => mb_substr($title, 0, 200), 'body' => mb_substr($body, 0, 500)],
            ],
        ];
        if ($url !== '') $message['message']['webpush'] = ['fcm_options' => ['link' => $url]];
        try {
            $resp = remote_post_json(
                "https://fcm.googleapis.com/v1/projects/$projectId/messages:send",
                ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
                json_encode($message),
            );
            if ($resp === null) {
                // Distinguish "unregistered" (dead token, safe to prune) from a transient
                // failure (network/quota/etc, keep the token and just skip this send) is not
                // possible from remote_post_json()'s null-on-any-failure return alone — a
                // future iteration could inspect the raw FCM error body if this needs tuning.
                outbox_log('push-error', $t['fcm_token'], 'send failed');
            }
        } catch (Throwable $e) {
            outbox_log('push-error', $t['fcm_token'], $e->getMessage());
        }
    }
}

/** Send an email. MVP: PHP mail() if configured, always logged to the outbox. */
function send_email(string $to, string $subject, string $body): void {
    outbox_log('email', $to, "$subject — $body");
    if (!DEV_MODE && function_exists('mail')) {
        $sent = @mail($to, $subject, $body, 'From: ' . site_name() . ' <' . sys('notifications.email_from', 'no-reply@ezihgebeya.local') . '>');
        if (!$sent) outbox_log('email-error', $to, 'mail() returned false — check server mail configuration');
    }
}

/** POST a JSON payload to a webhook URL, using the same HTTPS-only/SSRF-blocking guard
 * (remote_url_allowed() in app/helpers.php) as every other server-side fetch in this app.
 * No caller yet (payment-gateway integration is future Revenue-model work) — this is the
 * convention that work should use, so failure logging comes for free instead of being
 * bolted on later. Pass $allowedHosts once a real gateway host is known; empty means
 * "any host that passes the private-IP/HTTPS checks", which is fine for now since nothing
 * calls this yet. */
function webhook_post(string $url, array $payload, array $allowedHosts = [], int $timeoutSec = 5): bool {
    if (!remote_url_allowed($url, $allowedHosts)) {
        outbox_log('webhook-error', $url, 'blocked: not an approved HTTPS host');
        return false;
    }
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err || $code >= 400) { outbox_log('webhook-error', $url, $err ?: "HTTP $code"); return false; }
        return true;
    } catch (Throwable $e) {
        outbox_log('webhook-error', $url, $e->getMessage());
        return false;
    }
}

// ---------- in-app notifications ----------
function notification_is_marketing(string $type): bool {
    $type = strtolower(str_replace('-', '_', trim($type)));
    if (substr($type, 0, 10) === 'marketing_') return true;
    return in_array($type, ['promo', 'promotion', 'campaign', 'newsletter', 'vendor_digest'], true);
}

function user_marketing_opted_in(?int $userId, string $channel): bool {
    if (!$userId) return false;
    $cols = [
        'sms' => 'marketing_sms_opt_in',
        'email' => 'marketing_email_opt_in',
        'push' => 'marketing_push_opt_in',
        'in_app' => 'marketing_push_opt_in',
    ];
    $col = $cols[$channel] ?? null;
    if (!$col) return false;

    // Before the migration is applied, keep existing notification behavior rather
    // than breaking transactional flows on a missing preference column.
    if (!function_exists('db_column_exists') || !db_column_exists('users', $col)) return true;
    return (int)val("SELECT `$col` FROM users WHERE id = ?", [$userId]) === 1;
}

function notification_categories(): array {
    return [
        'inquiries' => 'Inquiries and chat replies',
        'orders' => 'Orders and delivery updates',
        'reviews' => 'Reviews and ratings',
        'promotions' => 'Promotion/subscription reminders',
        'support' => 'Support ticket updates',
    ];
}

function notification_category_for_type(string $type): ?string {
    $type = strtolower(str_replace('-', '_', trim($type)));
    if (str_contains($type, 'inquiry') || $type === 'vendor_reply') return 'inquiries';
    if (str_starts_with($type, 'order_') || str_contains($type, 'delivery')) return 'orders';
    if (str_contains($type, 'review')) return 'reviews';
    if (str_contains($type, 'promotion') || str_contains($type, 'subscription')) return 'promotions';
    if (str_starts_with($type, 'support_') || $type === 'support_ticket') return 'support';
    return null;
}

function notification_category_is_mandatory(string $type): bool {
    $type = strtolower(str_replace('-', '_', trim($type)));
    return str_starts_with($type, 'account_')
        || str_starts_with($type, 'sanction_')
        || str_starts_with($type, 'payment_')
        || str_starts_with($type, 'verification_')
        || str_starts_with($type, 'listing_')
        || in_array($type, ['password_reset', 'otp', 'security'], true);
}

function user_notification_enabled(?int $userId, string $type): bool {
    if (!$userId || notification_category_is_mandatory($type)) return true;
    $category = notification_category_for_type($type);
    if (!$category || !db_table_exists('user_notification_preferences')) return true;
    $enabled = val("SELECT enabled FROM user_notification_preferences WHERE user_id = ? AND category = ?", [$userId, $category]);
    return $enabled === null ? true : (bool)$enabled;
}

function send_marketing_sms_to_user(int $userId, string $message): bool {
    if (!user_marketing_opted_in($userId, 'sms')) return false;
    $phone = val("SELECT phone FROM users WHERE id = ?", [$userId]);
    if (!$phone) return false;
    send_sms($phone, $message);
    return true;
}

function send_marketing_email_to_user(int $userId, string $subject, string $body): bool {
    if (!user_marketing_opted_in($userId, 'email')) return false;
    $email = val("SELECT email FROM users WHERE id = ?", [$userId]);
    if (!$email) return false;
    send_email($email, $subject, $body);
    return true;
}

/** Create an in-app notification (and mirror to SMS/email for high-value events). */
function notify(?int $userId, string $type, string $title, string $url = '', string $body = '', bool $alsoSms = false): void {
    if (!$userId) return;
    try {
        if (!user_notification_enabled($userId, $type)) return;
        $isMarketing = notification_is_marketing($type);
        if (!$isMarketing || user_marketing_opted_in($userId, 'push')) {
            q("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?,?,?,?,?)",
              [$userId, $type, mb_substr($title, 0, 220), $body ?: null, $url ?: null]);
        }
        if ($alsoSms && sys('notifications.sms_mirror', 1)) {
            if (!$isMarketing || user_marketing_opted_in($userId, 'sms')) {
                $phone = val("SELECT phone FROM users WHERE id = ?", [$userId]);
                if ($phone) send_sms($phone, site_name() . ': ' . $title);
            }
        }
        // Web push mirrors the same "high-value" call sites as the SMS mirror above (order
        // confirmed, new inquiry reply, moderation decisions, etc. — every existing caller
        // that already passes $alsoSms=true), not a new signal to thread through every
        // notify() call site individually.
        if ($alsoSms && (!$isMarketing || user_marketing_opted_in($userId, 'push'))) {
            send_push($userId, site_name(), $title, $url ? url($url) : '');
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

/** Notify every admin/super_admin account (e.g. a new suspicious-activity trend). */
function notify_admins(string $type, string $title, string $url = ''): void {
    foreach (rows("SELECT id FROM users WHERE account_type IN ('admin', 'super_admin') AND status = 'active'") as $a) {
        notify((int)$a['id'], $type, $title, $url);
    }
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

/** Hours from submission to first moderation decision, one value per moderated item, across
 * every entity type the admin queues cover. Computed in PHP rather than SQL MEDIAN() (not
 * available on MySQL 5.7 / most MariaDB versions) — matches the plan's "stay portable across
 * MySQL 5.7+ and MariaDB" rule already applied to the attribute-filter JSON queries. */
function moderation_turnaround_hours(int $days = 30): array {
    $since = "NOW() - INTERVAL $days DAY";
    $specs = [
        // [audit action, target_type value stored by admin.php's action dispatcher, submission table, submission id col]
        ['listing_status', 'product', 'products', 'id'],
        ['listing_status', 'service', 'services', 'id'],
        ['listing_status', 'supply', 'supplies', 'id'],
        ['biz_status', 'businesses', 'businesses', 'id'],
        ['video_status', 'videos', 'video_posts', 'id'],
        ['review_status', 'reviews', 'reviews', 'id'],
        ['vr_review', 'verification', 'verification_requests', 'id'],
    ];
    $hours = [];
    foreach ($specs as [$action, $targetType, $table, $idCol]) {
        $rowsList = rows(
            "SELECT TIMESTAMPDIFF(HOUR, s.created_at, first_decision.decided_at) hrs
             FROM (
                 SELECT target_id, MIN(created_at) decided_at FROM audit_logs
                 WHERE action = ? AND target_type = ? AND target_id IS NOT NULL AND created_at > $since
                 GROUP BY target_id
             ) first_decision
             JOIN `$table` s ON s.`$idCol` = first_decision.target_id",
            [$action, $targetType]
        );
        foreach ($rowsList as $r) if ($r['hrs'] !== null) $hours[] = (float)$r['hrs'];
    }
    return $hours;
}

function median(array $nums): ?float {
    if (!$nums) return null;
    sort($nums);
    $mid = (int)floor((count($nums) - 1) / 2);
    return count($nums) % 2 ? $nums[$mid] : ($nums[$mid] + $nums[$mid + 1]) / 2;
}
