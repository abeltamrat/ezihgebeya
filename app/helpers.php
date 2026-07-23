<?php
// ---------- output ----------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }

/** Convert historical notification destinations to the current role-aware SPA routes. */
function notification_destination(?array $user, ?string $storedUrl): ?string {
    $path = trim((string)$storedUrl);
    if ($path === '') return null;
    if (preg_match('~^https?://~i', $path)) return $path;
    if (defined('BASE_URL') && BASE_URL !== '' && str_starts_with($path, BASE_URL . '/')) {
        $path = substr($path, strlen(BASE_URL) + 1);
    }
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'app/')) return url($path);
    if (preg_match('~^inquiries/(\d+)$~', $path, $match)) {
        return url(($user && is_vendor($user) ? 'app/vendor/inquiries/' : 'app/account/inquiries/') . $match[1]);
    }
    if (preg_match('~^vendor/listings/(product|service|supply)$~', $path, $match)) {
        return url('app/vendor/listings/' . $match[1]);
    }
    if ($path === 'vendor') return url('app/vendor');
    if (str_starts_with($path, 'vendor/')) return url('app/' . $path);
    if (str_starts_with($path, 'account/')) return url('app/' . $path);
    return url($path);
}

/** Versioned URL for a local static asset: appends ?v=<filemtime> so a rebuilt CSS/JS bundle
 * busts the browser HTTP cache and service-worker cache instead of stranding users on a stale
 * file. Falls back to the plain URL when the file can't be stat'd. */
function asset_url(string $path): string {
    $v = @filemtime(__DIR__ . '/../' . ltrim($path, '/'));
    return url($path) . ($v ? '?v=' . $v : '');
}
function redirect(string $path): never { header('Location: ' . url($path)); exit; }

function money($amount): string {
    if ($amount === null || $amount === '' || (float)$amount == 0.0) return '';
    $cur = function_exists('sys') ? (string)sys('general.currency_label', 'ETB') : 'ETB';
    return number_format((float)$amount) . ' ' . $cur;
}

// Session-scoped sliding-window rate limit (cross-cutting security checklist: "Rate limits
// for login, OTP, password reset, inquiries, reviews, and uploads"). Session-based rather
// than IP/account-based, matching the existing inquiry limiter's convention — sufficient to
// stop casual scripted abuse; login/OTP already have a separate, stronger IP+identity-based
// limiter (login_throttled() in app/notify.php) for the higher-stakes auth surface.
// Records this call as an attempt and returns true if the caller is currently rate-limited.
function rate_limited(string $key, int $max, int $windowSeconds): bool {
    $sessionKey = 'rate_' . $key;
    $_SESSION[$sessionKey] = array_filter($_SESSION[$sessionKey] ?? [], fn($t) => $t > time() - $windowSeconds);
    if (count($_SESSION[$sessionKey]) >= $max) return true;
    $_SESSION[$sessionKey][] = time();
    return false;
}

function db_column_exists(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $cache[$key] = (bool)val(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

function db_table_exists(string $table): bool {
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $cache[$table] = (bool)val(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

function traffic_source_for_listing(string $type, ?int $id, string $fallback = 'organic'): string {
    if ($type === 'video') return 'video_feed';
    if ($fallback === 'ad') return 'ad';
    if (!$id || !isset(LISTING_TABLES[$type])) return in_array($fallback, ['organic', 'promoted', 'video_feed', 'ad'], true) ? $fallback : 'organic';
    $table = LISTING_TABLES[$type];
    try {
        $row = row("SELECT is_promoted, is_featured FROM `$table` WHERE id = ?", [$id]);
        if ($row && (!empty($row['is_promoted']) || !empty($row['is_featured']))) return 'promoted';
    } catch (Throwable $e) {}
    return in_array($fallback, ['organic', 'promoted', 'video_feed', 'ad'], true) ? $fallback : 'organic';
}

function event_record(string $eventType, array $data = []): void {
    if (!db_table_exists('events')) return;
    try {
        $loc = user_location();
        $meta = $data['metadata'] ?? null;
        if (is_array($meta)) $meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        q("INSERT INTO events (user_id, session_id, event_type, listing_type, listing_id, business_id, category_id, source, city, subcity, referrer, metadata, ip, user_agent)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $data['user_id'] ?? (auth()['id'] ?? null),
            $data['session_id'] ?? session_id(),
            $eventType,
            $data['listing_type'] ?? null,
            $data['listing_id'] ?? null,
            $data['business_id'] ?? null,
            $data['category_id'] ?? null,
            in_array(($data['source'] ?? 'organic'), ['organic', 'promoted', 'video_feed', 'ad'], true) ? $data['source'] : 'organic',
            $data['city'] ?? $loc['city'],
            $data['subcity'] ?? $loc['subcity'],
            isset($_SERVER['HTTP_REFERER']) ? mb_substr($_SERVER['HTTP_REFERER'], 0, 255) : null,
            $meta,
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        // Analytics must never block marketplace actions.
    }
}

function active_account_sanction(int $userId): ?array {
    if (!db_table_exists('account_sanctions')) return null;
    return row("SELECT * FROM account_sanctions WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$userId]);
}

function create_account_sanction(int $userId, int $adminId, string $status, string $reason = 'policy_violation', string $note = ''): void {
    if (!db_table_exists('account_sanctions')) return;
    $level = $status === 'banned' ? 'ban' : ($status === 'suspended' ? 'suspension' : 'warning');
    q("UPDATE account_sanctions SET status = 'lifted', lifted_at = NOW() WHERE user_id = ? AND status = 'active'", [$userId]);
    q("INSERT INTO account_sanctions (user_id, admin_id, level, reason, admin_note) VALUES (?,?,?,?,?)",
      [$userId, $adminId, $level, $reason ?: 'policy_violation', $note ?: null]);
}

function lift_account_sanctions(int $userId, int $adminId, string $response = ''): void {
    if (!db_table_exists('account_sanctions')) return;
    q("UPDATE account_sanctions
       SET status = 'lifted', lifted_at = NOW(), appeal_status = IF(appeal_status = 'pending', 'approved', appeal_status),
           appeal_response = COALESCE(NULLIF(?, ''), appeal_response), reviewed_by = ?, reviewed_at = NOW()
       WHERE user_id = ? AND status = 'active'", [$response, $adminId, $userId]);
}

function listing_rejection_reasons(): array {
    return [
        'title' => 'Title problem — use a clear item/service name, no emojis, contact details, or spam words.',
        'category' => 'Wrong category — choose the most specific matching category so buyers can find it.',
        'price' => 'Price problem — enter a realistic ETB price or mark the listing negotiable where allowed.',
        'duplicate' => 'Duplicate listing — update your existing listing instead of reposting the same item.',
        'images' => 'Image problem — upload clear original photos; avoid watermarks, screenshots, collages, or phone numbers in images.',
        'description' => 'Description incomplete — add condition, dimensions, materials, delivery terms, and important details.',
        'prohibited' => 'Not allowed — this item/service violates marketplace rules or needs admin approval.',
        'contact_info' => 'Contact info in content — keep phone numbers and links in the seller profile/contact fields only.',
        'other' => 'Other issue — read the reviewer note and correct the listing before resubmitting.',
    ];
}

function listing_rejection_instruction(?string $reason): string {
    $reasons = listing_rejection_reasons();
    return $reasons[$reason ?: ''] ?? $reasons['other'];
}

function normalize_listing_title(string $title): string {
    $title = mb_strtolower(trim($title));
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
    return trim($title);
}

function contains_amharic(string $text): bool {
    return (bool)preg_match('/[\x{1200}-\x{137F}]/u', $text);
}

function content_lang_attr(string $text): string {
    return contains_amharic($text) ? ' lang="am"' : '';
}

function find_duplicate_listing(string $type, int $businessId, string $title, int $categoryId, string $city, ?int $excludeId = null): ?array {
    if (!isset(LISTING_TABLES[$type])) return null;
    $table = LISTING_TABLES[$type];
    $titleCol = listing_title_col($type);
    $norm = normalize_listing_title($title);
    if ($norm === '') return null;

    $params = [$businessId, $categoryId, $city];
    $excludeSql = '';
    if ($excludeId) {
        $excludeSql = ' AND id != ?';
        $params[] = $excludeId;
    }

    $rowsList = rows("SELECT id, `$titleCol` title, slug, status, city, category_id, created_at FROM `$table`
        WHERE business_id = ? AND category_id = ? AND city = ? AND status NOT IN ('deleted','rejected','sold_out') $excludeSql
        ORDER BY FIELD(status, 'active', 'pending_review', 'paused', 'expired'), updated_at DESC LIMIT 25", $params);

    foreach ($rowsList as $candidate) {
        if (normalize_listing_title($candidate['title']) === $norm) return $candidate;
    }
    return null;
}

function time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($dt));
}

// ---------- session / auth ----------
function auth(): ?array {
    static $user = false;
    if ($user === false) {
        $user = empty($_SESSION['user_id']) ? null
            : row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']]);
    }
    return $user;
}

function is_vendor(?array $u): bool { return $u && in_array($u['account_type'], VENDOR_TYPES, true); }
function is_admin(?array $u): bool { return $u && in_array($u['account_type'], ['admin', 'super_admin'], true); }

function current_internal_path(): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $query = parse_url($uri, PHP_URL_QUERY);
    $base = rtrim((string)BASE_URL, '/');
    if ($base !== '') {
        $basePath = parse_url($base, PHP_URL_PATH) ?: '';
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        } elseif ($basePath !== '' && $path === $basePath) {
            $path = '/';
        }
    }
    $internal = ltrim($path, '/');
    return $internal . ($query ? '?' . $query : '');
}

function safe_return_path(?string $path, string $fallback = ''): string {
    $path = trim((string)$path);
    if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) return $fallback;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_starts_with($path, '//')) return $fallback;
    $parts = parse_url($path);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) return $fallback;
    $cleanPath = ltrim((string)($parts['path'] ?? ''), '/');
    $first = explode('/', $cleanPath, 2)[0] ?? '';
    if (in_array($first, ['login', 'logout', 'register', 'forgot-password'], true)) return $fallback;
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $cleanPath . $query;
}

function default_post_login_path(array $u): string {
    return is_admin($u) ? 'admin' : (is_vendor($u) ? 'app/vendor' : 'app/account');
}

function require_login(): array {
    $u = auth();
    if (!$u) {
        $returnTo = safe_return_path(current_internal_path(), '');
        if ($returnTo !== '') $_SESSION['return_to'] = $returnTo;
        flash('Please log in first.', 'error');
        redirect('login' . ($returnTo !== '' ? '?return=' . rawurlencode($returnTo) : ''));
    }
    return $u;
}
function require_vendor(): array {
    $u = require_login();
    if (!is_vendor($u)) { flash('Vendor account required.', 'error'); redirect(''); }
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if (!is_admin($u)) { flash('Not authorized.', 'error'); redirect(''); }
    return $u;
}

/** The logged-in vendor's business row (or null). */
function my_business(?array $u = null): ?array {
    $u = $u ?? auth();
    if (!$u) return null;
    return row("SELECT * FROM businesses WHERE user_id = ? AND status != 'deleted'", [$u['id']]);
}

/** Whether the current viewer may see a business's raw phone number: PLAN.md "Keep first
 * contact inside platform chat before exposing phone numbers, so response time is
 * measurable and users are protected." True for the business's own owner, admins, and any
 * customer who has already sent at least one inquiry to this business — false (unlock via
 * chat first) for everyone else, including anonymous visitors. */
function business_phone_unlocked(?array $u, int $businessId): bool {
    return $u !== null;
}

// ---------- JSON API envelope (shared by the bearer-token /api and session-cookie /api/v1) ----------
function api_out($data, int $code = 200): never {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function api_error(string $msg, int $code = 400, array $extra = []): never {
    api_out(['ok' => false, 'error' => $msg] + $extra, $code);
}
function api_validation_error(array $fieldErrors, string $msg = 'Validation failed.'): never {
    api_error($msg, 422, ['fields' => $fieldErrors]);
}

// ---------- flash + csrf ----------
function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type]; }
function get_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (!hash_equals(csrf_token(), $_POST['_token'] ?? '')) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back and retry.');
    }
}

// ---------- misc ----------
function upload_limit_mb(): int {
    return (int)system_restrictions_config()['max_image_upload_mb'];
}

function upload_limit_bytes(): int {
    return upload_limit_mb() * 1024 * 1024;
}

function upload_rate_exceeded(string $kind = 'file'): bool {
    $max = (int)sys('limits.upload_rate_max', 30);
    $window = (int)sys('limits.upload_rate_window_min', 60) * 60;
    return rate_limited('upload_' . $kind, $max, $window);
}

function slugify(string $text, string $table, string $col = 'slug'): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text), '-'));
    if ($slug === '') $slug = 'item';
    $base = $slug; $i = 1;
    while (val("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?", [$slug])) $slug = $base . '-' . (++$i);
    return $slug;
}

/** $private routes storage to PROTECTED_UPLOAD_DIR (blocked from direct web access by
 * .htaccess) instead of the normal public UPLOAD_DIR — for verification documents and
 * payment proofs, which must only ever be reachable through the authorized download
 * endpoint in pages/download.php, never as a directly-guessable public URL. */
function upload_image(array $file, string $subdir, bool $private = false): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (upload_rate_exceeded('image')) {
        flash('Too many upload attempts. Please wait a while before uploading more files.', 'error');
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Image upload failed because it exceeds the server upload limit.'
            : 'Image upload failed.';
        flash($msg, 'error');
        return null;
    }
    $limitMb = upload_limit_mb();
    if ($file['size'] > upload_limit_bytes()) { flash('Image too large (max ' . $limitMb . ' MB).', 'error'); return null; }
    $info = @getimagesize($file['tmp_name']);
    $ext = match ($info['mime'] ?? '') {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => null,
    };
    if (!$ext) { flash('Only JPG, PNG, WEBP or GIF images allowed.', 'error'); return null; }
    $base = $private ? PROTECTED_UPLOAD_DIR : UPLOAD_DIR;
    $dir = $base . '/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = $subdir . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $base . '/' . $name)) { flash('Could not save image.', 'error'); return null; }
    image_optimize($base . '/' . $name, $ext); // §22.3: downscale huge photos + write a thumbnail
    return $name;
}

/** Downscale to IMAGE_MAX_DIMENSION, recompress, and write a .thumb.jpg next to the file. GIFs (animation) are left alone. */
function image_optimize(string $absPath, string $ext): void {
    if ($ext === 'gif' || !function_exists('imagecreatetruecolor')) return;
    try {
        $src = match ($ext) {
            'jpg' => @imagecreatefromjpeg($absPath),
            'png' => @imagecreatefrompng($absPath),
            'webp' => @imagecreatefromwebp($absPath),
            default => false,
        };
        if (!$src) return;
        $w = imagesx($src); $h = imagesy($src);

        // main image: cap the longest side + recompress
        $max = IMAGE_MAX_DIMENSION;
        if ($w > $max || $h > $max) {
            $scale = min($max / $w, $max / $h);
            $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst; $w = $nw; $h = $nh;
        }
        match ($ext) {
            'jpg' => imagejpeg($src, $absPath, 82),
            'png' => imagepng($src, $absPath, 6),
            'webp' => imagewebp($src, $absPath, 82),
        };

        // thumbnail (grid cards / previews)
        $scale = min(THUMB_DIMENSION / $w, THUMB_DIMENSION / $h, 1);
        $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($h * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagejpeg($thumb, $absPath . '.thumb.jpg', 78);
        imagedestroy($thumb);
        imagedestroy($src);
    } catch (Throwable $e) {
        // optimization is best-effort; the original upload already succeeded
    }
}

function img_url(?string $path): ?string { return $path ? UPLOAD_URL . '/' . $path : null; }

/** Delete an uploaded file and its .thumb.jpg companion from disk. Used by the retention cron
 * to purge verification documents and payment proofs past their retention window — the DB row
 * (decision/record) is kept, only the file content goes. Path-traversal guarded even though
 * callers only ever pass paths this app itself generated via upload_image(). */
function purge_upload_file(?string $relPath): void {
    if (!$relPath) return;
    // Verification documents/payment proofs now live under PROTECTED_UPLOAD_DIR, not the
    // public UPLOAD_DIR — check both so this retention purge still finds them either way.
    foreach ([UPLOAD_DIR, PROTECTED_UPLOAD_DIR] as $base) {
        $real = realpath($base . '/' . $relPath);
        if ($real !== false && str_starts_with($real, realpath($base))) {
            @unlink($real);
            @unlink($real . '.thumb.jpg');
            return;
        }
    }
}

/** Thumbnail URL when one exists, else the full image. */
function thumb_url(?string $path): ?string {
    if (!$path) return null;
    return file_exists(UPLOAD_DIR . '/' . $path . '.thumb.jpg') ? UPLOAD_URL . '/' . $path . '.thumb.jpg' : img_url($path);
}

function request_scheme(): string {
    $forwarded = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    if ($forwarded === 'https') return 'https';
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return 'https';
    return $forwarded === 'http' ? 'http' : 'https';
}

function absolute_url(?string $path): ?string {
    if (!$path) return null;
    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', (string)$host)[0]);
    return request_scheme() . '://' . $host . '/' . ltrim($path, '/');
}

// Default canonical: the current request path with BASE_URL and any query string
// stripped. Pages with faceted/filtered variants (browse.php) should override by
// setting $canonical before including layout_top.php, so filter combinations don't
// count as duplicate content against the clean category URL.
function default_canonical_path(): string {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $basePath = trim((string)parse_url(BASE_URL, PHP_URL_PATH), '/');
    $path = trim($path, '/');
    if ($basePath !== '' && str_starts_with($path, $basePath)) {
        $path = trim(substr($path, strlen($basePath)), '/');
    }
    return $path;
}

function remote_url_allowed(string $url, array $allowedHosts = []): bool {
    $parts = parse_url($url);
    if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) return false;
    $host = strtolower($parts['host']);
    if ($allowedHosts && !in_array($host, array_map('strtolower', $allowedHosts), true)) return false;

    $ips = [];
    foreach (@dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
        if (!empty($record['ip'])) $ips[] = $record['ip'];
        if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
    }
    if (!$ips) $ips = gethostbynamel($host) ?: [];
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (is_private_ip($ip)) return false;
    }
    return true;
}

function remote_text(string $url, array $allowedHosts = [], int $timeout = 10): ?string {
    if (!remote_url_allowed($url, $allowedHosts)) return null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 EzihGebeya',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300 && is_string($body) ? $body : null;
    }
    if (!ini_get('allow_url_fopen')) return null;
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));
    return is_string($body) ? $body : null;
}

// ---------- video embeds (official embeds only per spec) ----------
function parse_video_url(string $platform, string $urlIn): ?array {
    $urlIn = trim($urlIn);
    if ($platform === 'youtube') {
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $urlIn, $m)) {
            return ['video_id' => $m[1], 'embed_url' => 'https://www.youtube.com/embed/' . $m[1] . '?playsinline=1&rel=0'];
        }
    } elseif ($platform === 'tiktok') {
        $urlToParse = $urlIn;
        if (preg_match('~https?://(?:vt|vm)\.tiktok\.com/[^\\s]+~i', $urlIn)) {
            $urlToParse = resolve_redirect_url($urlIn) ?: $urlIn;
        }
        if (preg_match('~tiktok\.com/@[^/]+/video/(\d+)~', $urlToParse, $m)) {
            return ['video_id' => $m[1], 'embed_url' => 'https://www.tiktok.com/embed/v2/' . $m[1]];
        }
        $oembedId = tiktok_oembed_video_id($urlIn);
        if ($oembedId) {
            return ['video_id' => $oembedId, 'embed_url' => 'https://www.tiktok.com/embed/v2/' . $oembedId];
        }
        if (preg_match('~https?://(?:www\.|m\.|vt\.|vm\.)?tiktok\.com/[^\\s]+~i', $urlIn)) {
            return ['video_id' => 'link-' . substr(sha1($urlIn), 0, 24), 'embed_url' => $urlIn];
        }
    }
    return null;
}

function tiktok_oembed_video_id(string $url): ?string {
    $json = fetch_remote_text('https://www.tiktok.com/oembed?url=' . rawurlencode($url));
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!is_array($data)) return null;
    $haystack = implode(' ', array_map(fn($v) => is_scalar($v) ? (string)$v : '', $data));
    if (preg_match('~(?:/video/|embed/v2/|embed_product_id["\':\s]+)(\d{8,})~', $haystack, $m)) return $m[1];
    return null;
}

function fetch_remote_text(string $url): ?string {
    return remote_text($url, ['www.tiktok.com']);
}

function resolve_redirect_url(string $url): ?string {
    $allowed = ['vt.tiktok.com', 'vm.tiktok.com', 'www.tiktok.com', 'm.tiktok.com'];
    if (!remote_url_allowed($url, $allowed)) return null;

    for ($i = 0; $i < 8; $i++) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 EzihGebeya',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!is_string($response) || $status < 300 || $status >= 400) return $url;
            if (!preg_match('/^Location:\s*(.+)$/mi', $response, $m)) return $url;
            $next = trim($m[1]);
        } else {
            $context = stream_context_create(['http' => ['method' => 'HEAD', 'follow_location' => 0, 'timeout' => 10]]);
            $headers = @get_headers($url, true, $context);
            if (!is_array($headers) || empty($headers['Location'])) return $url;
            $next = is_array($headers['Location']) ? end($headers['Location']) : $headers['Location'];
        }
        if (!is_string($next) || $next === '') return $url;
        if (str_starts_with($next, '/')) {
            $parts = parse_url($url);
            $next = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $next;
        }
        if (!remote_url_allowed($next, $allowed)) return null;
        if ($next === $url) return $url;
        $url = $next;
    }
    return $url;
}

function video_embed_html(array $v): string {
    $embedUrl = (string)$v['embed_url'];
    if ($v['platform'] === 'tiktok') {
        if (!str_contains($embedUrl, '/embed/v2/')) {
            $src = e($embedUrl);
            return '<blockquote class="tiktok-embed video-frame tiktok-link-embed" cite="' . $src . '" data-src="' . $src . '"><a href="' . $src . '">Watch on TikTok</a></blockquote><script async src="https://www.tiktok.com/embed.js"></script>';
        }
        $src = e(video_embed_autoplay_url($embedUrl, $v['platform'], (string)($v['video_id'] ?? '')));
        return '<iframe class="video-frame tiktok" src="' . $src . '" loading="lazy" scrolling="no" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
    }
    $src = e(video_embed_autoplay_url($embedUrl, $v['platform'], (string)($v['video_id'] ?? '')));
    return '<iframe class="video-frame" src="' . $src . '" loading="lazy" scrolling="no" title="' . e($v['title'] ?? 'Video') . '" allow="autoplay; accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
}

function video_embed_autoplay_url(string $src, string $platform, string $videoId = ''): string {
    $parts = parse_url($src);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $src;
    parse_str($parts['query'] ?? '', $query);
    $query['autoplay'] = '1';
    if ($platform === 'youtube') {
        $query['mute'] = '1';
        $query['playsinline'] = '1';
        $query['loop'] = '1';
        $query['controls'] = '0';
        $query['modestbranding'] = '1';
        $query['rel'] = '0';
        if ($videoId !== '') $query['playlist'] = $videoId;
    } elseif ($platform === 'tiktok') {
        $query['muted'] = '1';
        $query['mute'] = '1';
        $query['playsinline'] = '1';
        $query['loop'] = '1';
        $query['controls'] = '0';
        $query['hide_controls'] = '1';
    }
    $path = $parts['path'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port . $path . '?' . http_build_query($query) . $fragment;
}

// ---------- listings ----------
const LISTING_TABLES = ['product' => 'products', 'service' => 'services', 'supply' => 'supplies'];

function listing_title_col(string $type): string { return $type === 'supply' ? 'name' : 'title'; }

function listing_url(string $type, array $item): string {
    return url(['product' => 'products', 'service' => 'services', 'supply' => 'supplies'][$type] . '/' . $item['slug']);
}

/** Primary image URL for a listing row, or null. */
function listing_image(string $type, array $item): ?string {
    if ($type === 'product') {
        // AR models share product_media with photos but cannot be rendered by <img>.
        // Always select an actual image for cards/search/cart thumbnails.
        $m = row("SELECT file_url FROM product_media WHERE product_id = ? AND media_type = 'image' ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1", [$item['id']]);
        return $m ? img_url($m['file_url']) : null;
    }
    return img_url($item['image'] ?? null);
}

function listing_badge(string $kind, string $label, ?string $icon = null): string {
    $allowedKinds = ['featured', 'promoted', 'discount', 'condition', 'delivery', 'negotiable', 'verified',
        'verified-phone_verified', 'verified-document_verified', 'verified-location_verified', 'verified-premium_verified'];
    if (!in_array($kind, $allowedKinds, true)) $kind = 'condition';
    $paths = [
        'star' => 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z',
        'trend' => 'M5 17 11 11l4 4 5-7 M15 8h5v5',
        'tag' => 'M3 5v6l10 10 8-8L11 3H5a2 2 0 0 0-2 2z M8 8h.01',
        'box' => 'm4 7 8-4 8 4-8 4z M4 7v10l8 4 8-4V7 M12 11v10',
        'truck' => 'M3 6h11v11H3z M14 10h4l3 3v4h-7z M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
        'offer' => 'M12 3v18 M16 7.5C16 5.6 14.2 5 12 5s-4 .8-4 3 4 3 4 3 4 .8 4 3-1.8 3-4 3-4-.6-4-2.5',
        'check' => 'M5 12.5 9.5 17 19 7',
    ];
    // Featured, verified, and discount badges use full-color glyphs (badge_rich_icon); everything
    // else keeps the monochrome line set that inherits the badge text color.
    $richMap = ['featured' => 'featured-star', 'discount' => 'sale-tag'];
    $richName = $richMap[$kind] ?? (str_starts_with($kind, 'verified-') ? 'verified-shield' : null);
    $iconHtml = $richName
        ? badge_rich_icon($richName)
        : (isset($paths[$icon ?? ''])
            ? '<svg class="badge-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="' . e($paths[$icon]) . '"></path></svg>'
            : '');
    $classes = 'badge badge-' . $kind . (str_starts_with($kind, 'verified-') ? ' badge-verified' : '');
    return '<span class="' . e($classes) . '">' . $iconHtml . '<span>' . e($label) . '</span></span>';
}

/** Full-color inline SVG badge glyphs, self-hosted (no external/flaticon requests, no attribution
 * obligation since these are original drawings). Sized by .badge-ricon; each SVG carries its own
 * colors. Matches the flaticon look the marketplace uses: gold sparkle star, green verified
 * shield, red sale tag. */
function badge_rich_icon(string $name): string {
    $svg = [
        'featured-star' =>
            '<path d="M12 2.5l2.64 6.03 6.56.55-4.98 4.3 1.5 6.42L12 16.8 6.28 20l1.5-6.42-4.98-4.3 6.56-.55z" fill="#FCC419"/>'
          . '<path d="M12 8.1l1.16 2.65 2.88.24-2.19 1.89.66 2.82L12 14.9l-2.51 1.36.66-2.82-2.19-1.89 2.88-.24z" fill="#FB8C00"/>'
          . '<circle cx="3" cy="6.4" r=".95" fill="#8C9EFF"/><circle cx="21" cy="6.4" r=".95" fill="#8C9EFF"/>'
          . '<circle cx="4.5" cy="18.4" r=".8" fill="#8C9EFF"/><circle cx="19.5" cy="18.4" r=".8" fill="#8C9EFF"/>'
          . '<circle cx="12" cy="22.4" r=".8" fill="#8C9EFF"/>',
        'verified-shield' =>
            '<path d="M12 2.2 4.6 5v6.1c0 4.55 3.16 8.8 7.4 10.5 4.24-1.7 7.4-5.95 7.4-10.5V5z" fill="#40C057"/>'
          . '<path d="M10.7 15.35 7.55 12.2l1.5-1.5 1.65 1.65 4.3-4.3 1.5 1.5z" fill="#F1F3F5"/>',
        'sale-tag' =>
            '<path d="M3 5v6l10 10 8-8L11 3H5a2 2 0 0 0-2 2z" fill="currentColor"/>'
          . '<circle cx="7.8" cy="7.8" r="1.7" fill="var(--red)"/>',
    ];
    if (!isset($svg[$name])) return '';
    return '<svg class="badge-ricon" viewBox="0 0 24 24" aria-hidden="true">' . $svg[$name] . '</svg>';
}

function verified_badge(?string $status): string {
    if (!$status || $status === 'unverified') return '';
    $label = ['phone_verified' => 'Phone Verified', 'document_verified' => 'Verified', 'location_verified' => 'Verified', 'premium_verified' => 'Premium Verified'][$status] ?? 'Verified';
    $level = in_array($status, ['phone_verified', 'document_verified', 'location_verified', 'premium_verified'], true) ? $status : 'document_verified';
    return '<span title="' . e($label) . '">' . listing_badge('verified-' . $level, $label, 'check') . '</span>';
}

/** Seller response rate (§23.1): share of inquiries the vendor acted on. Null until 3+ inquiries. */
function business_response_rate(int $businessId): ?int {
    $agg = row("SELECT COUNT(*) total, SUM(status NOT IN ('new','seen')) handled FROM inquiries WHERE business_id = ?", [$businessId]);
    if ((int)$agg['total'] < 3) return null;
    return (int)round((int)$agg['handled'] / (int)$agg['total'] * 100);
}

function business_response_median_minutes(int $businessId): ?int {
    static $cache = [];
    if (array_key_exists($businessId, $cache)) return $cache[$businessId];
    if (!db_table_exists('inquiry_messages')) return $cache[$businessId] = null;
    $biz = row("SELECT user_id FROM businesses WHERE id = ?", [$businessId]);
    if (!$biz) return $cache[$businessId] = null;
    $rows = rows(
        "SELECT TIMESTAMPDIFF(MINUTE, i.created_at, MIN(m.created_at)) mins
         FROM inquiries i
         JOIN inquiry_messages m ON m.inquiry_id = i.id AND m.sender_id = ?
         WHERE i.business_id = ? AND m.created_at >= i.created_at
         GROUP BY i.id
         HAVING mins IS NOT NULL
         ORDER BY mins",
        [$biz['user_id'], $businessId]
    );
    if (count($rows) < 3) return $cache[$businessId] = null;
    $mins = array_map(fn($r) => max(0, (int)$r['mins']), $rows);
    sort($mins);
    $mid = (int)floor((count($mins) - 1) / 2);
    return $cache[$businessId] = (count($mins) % 2 ? $mins[$mid] : (int)round(($mins[$mid] + $mins[$mid + 1]) / 2));
}

function response_time_label(?int $minutes): ?string {
    if ($minutes === null) return null;
    if ($minutes < 60) return $minutes <= 1 ? 'usually replies in 1 min' : 'usually replies in ' . $minutes . ' mins';
    if ($minutes < 1440) return 'usually replies in ' . max(1, (int)round($minutes / 60)) . ' hrs';
    return 'usually replies in ' . max(1, (int)round($minutes / 1440)) . ' days';
}

function business_recent_activity_label(int $businessId): ?string {
    static $cache = [];
    if (array_key_exists($businessId, $cache)) return $cache[$businessId];
    $biz = row("SELECT user_id FROM businesses WHERE id = ?", [$businessId]);
    if (!$biz) return $cache[$businessId] = null;
    $last = val("SELECT last_login_at FROM users WHERE id = ?", [$biz['user_id']]);
    foreach (LISTING_TABLES as $table) {
        $d = val("SELECT MAX(updated_at) FROM `$table` WHERE business_id = ?", [$businessId]);
        if ($d && (!$last || strtotime($d) > strtotime($last))) $last = $d;
    }
    if (db_table_exists('video_posts')) {
        $d = val("SELECT MAX(updated_at) FROM video_posts WHERE business_id = ?", [$businessId]);
        if ($d && (!$last || strtotime($d) > strtotime($last))) $last = $d;
    }
    if (!$last) return $cache[$businessId] = null;
    $age = time() - strtotime($last);
    if ($age <= 86400) return $cache[$businessId] = 'active today';
    if ($age <= 7 * 86400) return $cache[$businessId] = 'active this week';
    return $cache[$businessId] = null;
}

function business_trust_snapshot(int $businessId): array {
    static $cache = [];
    if (!$businessId) return [];
    if (!array_key_exists($businessId, $cache)) {
        $cache[$businessId] = row("SELECT rating_average, rating_count, created_at FROM businesses WHERE id = ?", [$businessId]) ?: [];
    }
    return $cache[$businessId];
}

function star_rating($avg, int $count = -1): string {
    $avg = (float)$avg;
    if ($avg <= 0) return '<span class="stars muted">No ratings yet</span>';
    $full = str_repeat('★', (int)round($avg));
    $html = '<span class="stars">' . $full . ' ' . number_format($avg, 1) . '</span>';
    if ($count >= 0) $html .= ' <span class="muted">(' . $count . ')</span>';
    return $html;
}

// ---------- system UI optimizer ----------
function ensure_site_settings_table(): bool {
    static $done = false;
    if ($done) return true;
    try {
        q("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Throwable $e) {
        return false;
    }
    return true;
}

function site_setting_get(string $key, $default = null) {
    if (!ensure_site_settings_table()) return $default;
    try {
        $raw = val("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
    } catch (Throwable $e) {
        return $default;
    }
    if ($raw === false || $raw === null) return $default;
    $decoded = json_decode((string)$raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
}

function site_setting_set(string $key, $value): void {
    if (!ensure_site_settings_table()) {
        flash('Could not create the settings table. Run database/upgrade4.sql and try again.', 'error');
        return;
    }
    $raw = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        q("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, $raw]);
    } catch (Throwable $e) {
        flash('Could not save system UI settings.', 'error');
    }
}

function system_restrictions_config(): array {
    $saved = site_setting_get('system_restrictions', []);
    $saved = is_array($saved) ? $saved : [];
    return [
        'max_image_upload_mb' => max(1, min(100, (int)($saved['max_image_upload_mb'] ?? 30))),
    ];
}

function sanitize_system_restrictions(array $in): array {
    return [
        'max_image_upload_mb' => max(1, min(100, (int)($in['max_image_upload_mb'] ?? 30))),
    ];
}

function system_ui_defaults(): array {
    return [
        'brand' => '#2563eb',
        'brand_dark' => '#1d4ed8',
        'brand_soft' => '#eff6ff',
        'accent' => '#d97706',
        'accent_soft' => '#fef3c7',
        'ink' => '#0f172a',
        'text' => '#334155',
        'bg' => '#f8fafc',
        'surface' => '#ffffff',
        'theme_mode' => 'light',
        'font_family' => 'inter',
        'font_scale' => 100,
        'container_width' => 1240,
        'section_spacing' => 34,
        'grid_min_width' => 235,
        'logo_mark' => 'EG',
        'logo_text' => 'EzihGebeya',
        'announcement_enabled' => 0,
        'announcement_text' => 'New sellers can open a shop for free.',
        'announcement_url' => '',
        'announcement_tone' => 'brand',
        'button_radius' => 10,
        'card_radius' => 16,
        'panel_radius' => 16,
        'input_radius' => 10,
        'card_image_ratio' => '4/3',
        'shadow_strength' => 45,
        'border_width' => 1,
        'focus_style' => 'ring',
        'component_density' => 'comfortable',
        'icon_pack' => 'line',
        'nav_style' => 'glass',
        'form_style' => 'soft',
        'ad_style' => 'clean',
        'table_style' => 'soft',
        'badge_style' => 'pill',
        'card_style' => 'standard',
        'header_behavior' => 'sticky',
        'footer_style' => 'dark',
        'mobile_nav_style' => 'pill',
        'search_style' => 'rounded',
        'hover_motion' => 'lift',
        'image_treatment' => 'natural',
        'image_hover' => 'zoom',
        'button_style' => 'solid',
        'section_head_style' => 'plain',
        'filters_behavior' => 'sticky',
        'card_text_align' => 'left',
        'hero_align' => 'left',
        'hero_height' => 'standard',
        'hero_search_enabled' => 1,
        'hero_links_enabled' => 1,
        'hero_stats_enabled' => 1,
        'category_style' => 'icon',
        'category_display_limit' => 8,
        'price_style' => 'standard',
        'empty_state_style' => 'dashed',
        'show_card_category' => 1,
        'show_card_price' => 1,
        'show_card_location' => 1,
        'show_card_vendor' => 1,
        'show_featured_badge' => 1,
        'button_badge_enabled' => 0,
        'button_badge_text' => 'New',
        'button_badge_target' => 'join',
        'button_badge_tone' => 'accent',
        'hero_overlay' => 72,
        'hero_background_mode' => 'overlay_image',
        'hero_gradient_from' => '#0f172a',
        'hero_gradient_to' => '#1d4ed8',
        'hero_image_position' => 'center',
        'hero_title' => 'Furniture, finishing and supplies in one trusted marketplace.',
        'hero_subtitle' => 'Discover verified furniture sellers, finishing professionals and material suppliers across Ethiopia by location, price and rating.',
        'hero_image' => '',
        'cta_title' => 'Are you a seller, workshop or service provider?',
        'cta_text' => 'List your products, get discovered by customers near you, and receive direct inquiries. Free to start.',
        'cta_button' => 'Open your shop',
        'home_sections' => ['categories', 'near', 'featured', 'services', 'supplies', 'cta'],
        'hidden_sections' => [],
        'custom_css' => '',
    ];
}

function system_ui_config(): array {
    $saved = site_setting_get('system_ui_optimizer', []);
    return array_replace(system_ui_defaults(), is_array($saved) ? $saved : []);
}

function sanitize_system_ui(array $in): array {
    $defaults = system_ui_defaults();
    $out = $defaults;
    foreach (['brand','brand_dark','brand_soft','accent','accent_soft','ink','text','bg','surface','hero_gradient_from','hero_gradient_to'] as $k) {
        $v = trim((string)($in[$k] ?? $defaults[$k]));
        $out[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $defaults[$k];
    }
    foreach (['button_radius' => [4, 999], 'card_radius' => [6, 28], 'panel_radius' => [6, 28], 'input_radius' => [4, 24], 'shadow_strength' => [0, 80], 'border_width' => [0, 3], 'hero_overlay' => [20, 92], 'font_scale' => [88, 116], 'container_width' => [960, 1480], 'section_spacing' => [18, 70], 'grid_min_width' => [160, 320], 'category_display_limit' => [4, 16]] as $k => [$min, $max]) {
        $out[$k] = max($min, min($max, (int)($in[$k] ?? $defaults[$k])));
    }
    $out['theme_mode'] = in_array($in['theme_mode'] ?? '', ['light', 'soft-dark', 'high-contrast'], true) ? $in['theme_mode'] : 'light';
    $out['font_family'] = in_array($in['font_family'] ?? '', ['inter', 'system', 'serif', 'rounded'], true) ? $in['font_family'] : 'inter';
    $out['logo_mark'] = strtoupper(substr(trim((string)($in['logo_mark'] ?? $defaults['logo_mark'])), 0, 4)) ?: $defaults['logo_mark'];
    $out['logo_text'] = substr(trim((string)($in['logo_text'] ?? $defaults['logo_text'])), 0, 32) ?: $defaults['logo_text'];
    $out['announcement_enabled'] = !empty($in['announcement_enabled']) ? 1 : 0;
    $out['announcement_text'] = substr(trim((string)($in['announcement_text'] ?? $defaults['announcement_text'])), 0, 160);
    $out['announcement_url'] = substr(trim((string)($in['announcement_url'] ?? '')), 0, 240);
    $out['announcement_tone'] = in_array($in['announcement_tone'] ?? '', ['brand', 'accent', 'dark', 'light'], true) ? $in['announcement_tone'] : 'brand';
    $out['component_density'] = in_array($in['component_density'] ?? '', ['compact', 'comfortable', 'spacious'], true) ? $in['component_density'] : 'comfortable';
    $out['icon_pack'] = in_array($in['icon_pack'] ?? '', ['line', 'solid', 'emoji', 'initials'], true) ? $in['icon_pack'] : 'line';
    $out['nav_style'] = in_array($in['nav_style'] ?? '', ['glass', 'solid', 'dark'], true) ? $in['nav_style'] : 'glass';
    $out['form_style'] = in_array($in['form_style'] ?? '', ['soft', 'outlined', 'filled'], true) ? $in['form_style'] : 'soft';
    $out['ad_style'] = in_array($in['ad_style'] ?? '', ['clean', 'boxed', 'premium'], true) ? $in['ad_style'] : 'clean';
    $out['table_style'] = in_array($in['table_style'] ?? '', ['soft', 'striped', 'compact'], true) ? $in['table_style'] : 'soft';
    $out['badge_style'] = in_array($in['badge_style'] ?? '', ['pill', 'square', 'soft'], true) ? $in['badge_style'] : 'pill';
    $out['card_style'] = in_array($in['card_style'] ?? '', ['standard', 'borderless', 'outlined', 'compact'], true) ? $in['card_style'] : 'standard';
    $out['header_behavior'] = in_array($in['header_behavior'] ?? '', ['sticky', 'static', 'floating'], true) ? $in['header_behavior'] : 'sticky';
    $out['footer_style'] = in_array($in['footer_style'] ?? '', ['dark', 'light', 'brand'], true) ? $in['footer_style'] : 'dark';
    $out['mobile_nav_style'] = in_array($in['mobile_nav_style'] ?? '', ['pill', 'minimal', 'boxed'], true) ? $in['mobile_nav_style'] : 'pill';
    $out['search_style'] = in_array($in['search_style'] ?? '', ['rounded', 'box', 'underline'], true) ? $in['search_style'] : 'rounded';
    $out['hover_motion'] = in_array($in['hover_motion'] ?? '', ['lift', 'soft', 'none'], true) ? $in['hover_motion'] : 'lift';
    $out['focus_style'] = in_array($in['focus_style'] ?? '', ['ring', 'underline', 'glow'], true) ? $in['focus_style'] : 'ring';
    $out['image_treatment'] = in_array($in['image_treatment'] ?? '', ['natural', 'warm', 'cool', 'mono'], true) ? $in['image_treatment'] : 'natural';
    $out['image_hover'] = in_array($in['image_hover'] ?? '', ['zoom', 'fade', 'none'], true) ? $in['image_hover'] : 'zoom';
    $out['button_style'] = in_array($in['button_style'] ?? '', ['solid', 'gradient', 'flat'], true) ? $in['button_style'] : 'solid';
    $out['section_head_style'] = in_array($in['section_head_style'] ?? '', ['plain', 'rule', 'boxed'], true) ? $in['section_head_style'] : 'plain';
    $out['filters_behavior'] = in_array($in['filters_behavior'] ?? '', ['sticky', 'static'], true) ? $in['filters_behavior'] : 'sticky';
    $out['card_text_align'] = in_array($in['card_text_align'] ?? '', ['left', 'center'], true) ? $in['card_text_align'] : 'left';
    $out['hero_align'] = in_array($in['hero_align'] ?? '', ['left', 'center', 'split'], true) ? $in['hero_align'] : 'left';
    $out['hero_height'] = in_array($in['hero_height'] ?? '', ['compact', 'standard', 'tall'], true) ? $in['hero_height'] : 'standard';
    $out['category_style'] = in_array($in['category_style'] ?? '', ['icon', 'minimal', 'banner', 'rail'], true) ? $in['category_style'] : 'icon';
    $out['price_style'] = in_array($in['price_style'] ?? '', ['standard', 'brand', 'accent', 'dark'], true) ? $in['price_style'] : 'standard';
    $out['empty_state_style'] = in_array($in['empty_state_style'] ?? '', ['dashed', 'soft', 'plain'], true) ? $in['empty_state_style'] : 'dashed';
    $out['card_image_ratio'] = in_array($in['card_image_ratio'] ?? '', ['1/1', '4/3', '3/2', '16/9'], true) ? $in['card_image_ratio'] : '4/3';
    foreach (['show_card_category','show_card_price','show_card_location','show_card_vendor','show_featured_badge','hero_search_enabled','hero_links_enabled','hero_stats_enabled'] as $k) {
        $out[$k] = !empty($in[$k]) ? 1 : 0;
    }
    $out['button_badge_enabled'] = !empty($in['button_badge_enabled']) ? 1 : 0;
    $out['button_badge_text'] = substr(trim((string)($in['button_badge_text'] ?? $defaults['button_badge_text'])), 0, 18) ?: $defaults['button_badge_text'];
    $out['button_badge_target'] = in_array($in['button_badge_target'] ?? '', ['join', 'account', 'primary', 'all'], true) ? $in['button_badge_target'] : 'join';
    $out['button_badge_tone'] = in_array($in['button_badge_tone'] ?? '', ['accent', 'brand', 'dark', 'danger'], true) ? $in['button_badge_tone'] : 'accent';
    $out['hero_background_mode'] = in_array($in['hero_background_mode'] ?? '', ['gradient', 'image', 'overlay_image'], true) ? $in['hero_background_mode'] : 'overlay_image';
    $out['hero_image_position'] = in_array($in['hero_image_position'] ?? '', ['center', 'top', 'bottom', 'left', 'right'], true) ? $in['hero_image_position'] : 'center';
    $out['hero_title'] = substr(trim((string)($in['hero_title'] ?? $defaults['hero_title'])), 0, 160);
    $out['hero_subtitle'] = substr(trim((string)($in['hero_subtitle'] ?? $defaults['hero_subtitle'])), 0, 260);
    $out['hero_image'] = substr(trim(str_replace(['"', "'", ')', "\n", "\r"], '', (string)($in['hero_image'] ?? ''))), 0, 500);
    $out['cta_title'] = substr(trim((string)($in['cta_title'] ?? $defaults['cta_title'])), 0, 120) ?: $defaults['cta_title'];
    $out['cta_text'] = substr(trim((string)($in['cta_text'] ?? $defaults['cta_text'])), 0, 240) ?: $defaults['cta_text'];
    $out['cta_button'] = substr(trim((string)($in['cta_button'] ?? $defaults['cta_button'])), 0, 40) ?: $defaults['cta_button'];
    $allowedSections = ['categories', 'near', 'featured', 'services', 'supplies', 'cta'];
    $ordered = array_values(array_unique(array_filter((array)($in['home_sections'] ?? $defaults['home_sections']), fn($s) => in_array($s, $allowedSections, true))));
    $out['home_sections'] = $ordered ?: $defaults['home_sections'];
    $out['hidden_sections'] = array_values(array_intersect((array)($in['hidden_sections'] ?? []), $allowedSections));
    $out['custom_css'] = str_ireplace('</style', '', substr((string)($in['custom_css'] ?? ''), 0, 12000));
    return $out;
}

function system_ui_templates(): array {
    $templates = site_setting_get('system_ui_templates', []);
    return is_array($templates) ? $templates : [];
}

function system_ui_save_template(string $name, array $config): void {
    $name = substr(trim($name), 0, 60);
    if ($name === '') {
        flash('Template name is required.', 'error');
        return;
    }
    $templates = system_ui_templates();
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'template';
    $base = $slug; $i = 1;
    while (isset($templates[$slug])) $slug = $base . '-' . (++$i);
    $templates[$slug] = ['name' => $name, 'config' => sanitize_system_ui($config), 'created_at' => date('c')];
    site_setting_set('system_ui_templates', $templates);
    flash('Template saved.');
}

function system_ui_button_badge(string $target): string {
    $ui = system_ui_config();
    if (empty($ui['button_badge_enabled'])) return '';
    if (!in_array($ui['button_badge_target'], ['all', $target], true)) return '';
    return '<span class="btn-badge btn-badge-' . e($ui['button_badge_tone']) . '">' . e($ui['button_badge_text']) . '</span>';
}

function system_ui_icon(string $name, string $label = ''): string {
    $ui = system_ui_config();
    $pack = $ui['icon_pack'] ?? 'line';
    $label = $label !== '' ? $label : $name;
    $emoji = [
        'home' => '&#8962;', 'shop' => '&#9638;', 'cart' => '&#128722;', 'play' => '&#9654;',
        'user' => '&#9679;', 'admin' => '&#9881;', 'furniture' => '&#9638;', 'services' => '&#9881;',
        'supplies' => '&#9635;', 'pin' => '&#9679;', 'search' => '&#9906;', 'video' => '&#9654;',
        'overview' => '&#9636;', 'business' => '&#9638;', 'orders' => '&#9776;', 'analytics' => '&#9585;',
        'messages' => '&#9993;', 'subscription' => '&#9733;', 'ads' => '&#9673;', 'category' => '&#9638;',
    ];
    if ($pack === 'emoji') {
        return '<span class="ui-icon ui-icon-emoji" aria-hidden="true">' . ($emoji[$name] ?? '&#9679;') . '</span>';
    }
    if ($pack === 'initials') {
        return '<span class="ui-icon ui-icon-initial" aria-hidden="true">' . e(strtoupper(substr($label, 0, 1))) . '</span>';
    }

    $paths = [
        'home' => '<path d="M3 10.8 12 3.5l9 7.3"/><path d="M5 9.8V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.8"/>',
        'shop' => '<path d="M3 9.5 5 4h14l2 5.5"/><path d="M3 9.5a2.6 2.6 0 0 0 5.2 0 2.6 2.6 0 0 0 5.2 0 2.6 2.6 0 0 0 5.2 0 2.6 2.6 0 0 0 2.4 0"/><path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8"/><path d="M9 21v-6h6v6"/>',
        'cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="17.5" cy="20" r="1.4"/><path d="M2.5 3.5h2l2.6 12.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20.5 8H6"/>',
        'play' => '<path d="M7 4.8a1 1 0 0 1 1.5-.86l11 6.2a1 1 0 0 1 0 1.73l-11 6.2A1 1 0 0 1 7 17.2V4.8Z"/>',
        'user' => '<circle cx="12" cy="7.5" r="3.8"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'admin' => '<path d="M12 2.8 4.5 5.6v5.6c0 4.7 3.2 7.8 7.5 9 4.3-1.2 7.5-4.3 7.5-9V5.6L12 2.8Z"/><path d="m8.8 11.8 2.2 2.2 4.2-4.6"/>',
        'furniture' => '<path d="M5.5 11V7.5A2.5 2.5 0 0 1 8 5h8a2.5 2.5 0 0 1 2.5 2.5V11"/><path d="M3.5 13.5a2 2 0 0 1 4 0V14h9v-.5a2 2 0 0 1 4 0V17a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 17v-3.5Z"/><path d="M6 18.5V21M18 18.5V21"/>',
        'sofa' => '<path d="M5.5 11V7.5A2.5 2.5 0 0 1 8 5h8a2.5 2.5 0 0 1 2.5 2.5V11"/><path d="M3.5 13.5a2 2 0 0 1 4 0V14h9v-.5a2 2 0 0 1 4 0V17a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 17v-3.5Z"/><path d="M6 18.5V21M18 18.5V21"/>',
        'bed' => '<path d="M3 19v-8.5A1.5 1.5 0 0 1 4.5 9H8a3 3 0 0 1 3 3h9.5"/><path d="M3 13h18v6"/><path d="M3 21v-2M21 21v-2"/><path d="M5.5 9V6.5A1.5 1.5 0 0 1 7 5h10a1.5 1.5 0 0 1 1.5 1.5V11"/>',
        'table' => '<path d="M3 8h18"/><path d="M5 8l-.8 12M19 8l.8 12"/><path d="M7.5 13h9"/>',
        'chair' => '<path d="M7 11V4.5A1.5 1.5 0 0 1 8.5 3h7A1.5 1.5 0 0 1 17 4.5V11"/><path d="M5.5 14a1.8 1.8 0 0 1 1.8-1.8h9.4a1.8 1.8 0 0 1 1.8 1.8V16H5.5v-2Z"/><path d="M6.5 16v5M17.5 16v5"/>',
        'cabinet' => '<rect x="4" y="3.5" width="16" height="17" rx="1.5"/><path d="M12 3.5v17"/><path d="M9.5 11v2.5M14.5 11v2.5"/>',
        'wardrobe' => '<rect x="5" y="3" width="14" height="16" rx="1.5"/><path d="M12 3v16"/><path d="M9.8 10v2M14.2 10v2"/><path d="M7 19v2M17 19v2"/>',
        'tv' => '<rect x="3" y="5" width="18" height="11" rx="1.5"/><path d="M8 19h8"/><path d="M12 16v3"/>',
        'lamp' => '<path d="M8 2.5h8l3 8H5l3-8Z"/><path d="M12 10.5V19"/><path d="M8.5 21.5h7"/>',
        'office' => '<path d="M7 4h10v7H7z"/><path d="M4 11h16v3H4z"/><path d="M6 14l-1 7M18 14l1 7"/><path d="M12 14v7"/>',
        'services' => '<path d="M14.7 6.3a4.5 4.5 0 0 0-6 5.6L3 17.6a2 2 0 1 0 2.8 2.8l5.7-5.7a4.5 4.5 0 0 0 5.6-6l-3 3-2.5-.7-.7-2.5 3-3-.2-.2Z"/>',
        'supplies' => '<path d="M12 2.8 3.5 7v10l8.5 4.2L20.5 17V7L12 2.8Z"/><path d="M3.5 7 12 11.2 20.5 7"/><path d="M12 11.2V21.2"/>',
        'pin' => '<path d="M12 21.5s7.5-5.6 7.5-11.5a7.5 7.5 0 0 0-15 0c0 5.9 7.5 11.5 7.5 11.5Z"/><circle cx="12" cy="10" r="2.8"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m15.8 15.8 4.7 4.7"/>',
        'login' => '<path d="M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10"/><path d="m13 8 4 4-4 4M8 12h9"/>',
        'logout' => '<path d="M14 4h4.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H14"/><path d="m11 8-4 4 4 4M16 12H7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'send' => '<path d="m3.5 4.5 17 7.5-17 7.5 2.2-6.1L14 12l-8.3-1.4-2.2-6.1Z"/>',
        'chat' => '<path d="M20.5 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-3.5-.7L3 20.5l1.3-5.2A8.4 8.4 0 1 1 20.5 11.5Z"/><path d="M8.5 10h7M8.5 13.2h4.5"/>',
        'lock' => '<rect x="4.5" y="10" width="15" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14.5v2.5"/>',
        'unlock' => '<rect x="4.5" y="10" width="15" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.2-2.4M12 14.5v2.5"/>',
        'share' => '<circle cx="18" cy="5" r="2.2"/><circle cx="6" cy="12" r="2.2"/><circle cx="18" cy="19" r="2.2"/><path d="m8 10.9 8-4.7M8 13.1l8 4.7"/>',
        'report' => '<path d="M5 21V4M5 5h11l-1.5 3L16 11H5"/>',
        'heart-outline' => '<path d="M12 20.5s-8.5-5-8.5-11A4.6 4.6 0 0 1 12 6.6a4.6 4.6 0 0 1 8.5 2.9c0 6-8.5 11-8.5 11Z"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/><circle cx="8" cy="6" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="12" cy="18" r="1.5"/>',
        'sort' => '<path d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3"/>',
        'video' => '<rect x="2.5" y="6" width="13" height="12" rx="2"/><path d="m15.5 10.5 6-3.5v10l-6-3.5"/>',
        'overview' => '<rect x="3.5" y="3.5" width="7" height="9.5" rx="1.2"/><rect x="13.5" y="3.5" width="7" height="5.5" rx="1.2"/><rect x="13.5" y="11.5" width="7" height="9" rx="1.2"/><rect x="3.5" y="15.5" width="7" height="5" rx="1.2"/>',
        'business' => '<path d="M4 21h16"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9.5 21v-5h5v5"/><path d="M9 10h.01M12 10h.01M15 10h.01M9 13h.01M12 13h.01M15 13h.01"/>',
        'orders' => '<path d="M15.5 2.5H8.5A1.5 1.5 0 0 0 7 4v16a1.5 1.5 0 0 0 1.5 1.5h9A1.5 1.5 0 0 0 19 20V6l-3.5-3.5Z"/><path d="M15 2.5V6h3.5"/><path d="M10 12h6M10 15.5h4"/>',
        'analytics' => '<path d="M3.5 3.5v15A1.5 1.5 0 0 0 5 20h15.5"/><path d="m7 14 3.5-4.5 3 2.5 4.5-6"/>',
        'messages' => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-3.5-.7L3 20.5l1.3-5.2A8.4 8.4 0 1 1 21 11.5Z"/><path d="M8.5 10h7M8.5 13.2h4.5"/>',
        'subscription' => '<path d="m12 2.8 2.9 5.8 6.4 1-4.6 4.5 1 6.4L12 17.5l-5.7 3-1-6.4L.7 9.6l6.4-1L12 2.8Z"/>',
        'ads' => '<path d="M11 6.5 4 9v5l7 2.5V6.5Z"/><path d="M11 6.5 19.5 3v17L11 16.5"/><path d="M5.5 14.5 6.5 20"/>',
        'category' => '<rect x="3.5" y="3.5" width="7.2" height="7.2" rx="1.6"/><rect x="13.3" y="3.5" width="7.2" height="7.2" rx="1.6"/><rect x="3.5" y="13.3" width="7.2" height="7.2" rx="1.6"/><rect x="13.3" y="13.3" width="7.2" height="7.2" rx="1.6"/>',
        'bell' => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6.3-2.5 8-2.5 8h17s-2.5-1.7-2.5-8Z"/><path d="M10.2 20.5a2 2 0 0 0 3.6 0"/>',
        'heart' => '<path d="M12 20.5s-8.5-5-8.5-11A4.6 4.6 0 0 1 12 6.6a4.6 4.6 0 0 1 8.5 2.9c0 6-8.5 11-8.5 11Z"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3Z"/>',
        'verified' => '<path d="M12 2.5 14.2 4.6l3-.4 1 2.9 2.8 1.2-.6 3 1.9 2.4-1.9 2.4.6 3-2.8 1.2-1 2.9-3-.4L12 21.5l-2.2-2.1-3 .4-1-2.9L3 15.7l.6-3L1.7 10.3l1.9-2.4-.6-3L5.8 3.7l1-2.9 3 .4L12 2.5Z"/><path d="m8.8 12 2.2 2.2 4.2-4.6"/>',
        'truck' => '<path d="M2.5 5.5h11.5V17H2.5z"/><path d="M14 9h4l3 3.5V17h-7"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/>',
        'phone' => '<path d="M20.5 16.9v2.6a1.7 1.7 0 0 1-1.9 1.7 17.3 17.3 0 0 1-7.5-2.7 17 17 0 0 1-5.2-5.2A17.3 17.3 0 0 1 3.2 5.7 1.7 1.7 0 0 1 4.9 3.8h2.6a1.7 1.7 0 0 1 1.7 1.5c.1.8.3 1.7.6 2.5a1.7 1.7 0 0 1-.4 1.8l-1.1 1.1a13.6 13.6 0 0 0 4.9 4.9l1.1-1.1a1.7 1.7 0 0 1 1.8-.4c.8.3 1.7.5 2.5.6a1.7 1.7 0 0 1 1.5 1.7Z"/>',
        'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.9 2.9l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.9-2.9l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.9-2.9l.1.1a1.7 1.7 0 0 0 1.8.3 1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.9 2.9l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1Z"/>',
    ];
    $body = $paths[$name] ?? $paths['category'];
    $class = $pack === 'solid' ? 'ui-icon ui-icon-solid' : 'ui-icon ui-icon-line';
    // data-ico drives the per-icon signature color + tinted chip background (assets/css/tailwind.css
    // [data-ico] rules) — an e-commerce-style colorful icon set built from the existing line art
    // rather than one-off multi-color illustrations, so every icon name gets consistent treatment
    // for free. Furniture-category glyphs (sofa/bed/table/...) are deliberately left uncolored here
    // since they already get a rotating rainbow chip from .cat-tile:nth-child in the category grid.
    return '<span class="' . $class . '" data-ico="' . e($name) . '" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false">' . $body . '</svg></span>';
}

function system_ui_category_icon(string $categoryName, string $type = 'product'): string {
    $name = strtolower($categoryName);
    $map = [
        'sofa' => 'sofa', 'couch' => 'sofa', 'seat' => 'sofa',
        'bed' => 'bed', 'mattress' => 'bed',
        'dining' => 'table', 'table' => 'table', 'desk' => 'table',
        'chair' => 'chair', 'stool' => 'chair',
        'office' => 'office',
        'kitchen' => 'cabinet', 'cabinet' => 'cabinet',
        'wardrobe' => 'wardrobe', 'closet' => 'wardrobe',
        'tv' => 'tv', 'stand' => 'tv',
        'lamp' => 'lamp', 'light' => 'lamp',
        'service' => 'services', 'design' => 'services', 'install' => 'services', 'finish' => 'services', 'paint' => 'services', 'repair' => 'services',
        'wood' => 'supplies', 'board' => 'supplies', 'tool' => 'supplies', 'material' => 'supplies', 'fabric' => 'supplies', 'foam' => 'supplies',
    ];
    foreach ($map as $needle => $icon) {
        if (str_contains($name, $needle)) return system_ui_icon($icon, $categoryName);
    }
    if ($type === 'supply') return system_ui_icon('supplies', $categoryName);
    if ($type === 'service') return system_ui_icon('services', $categoryName);
    if ($type === 'product') return system_ui_icon('furniture', $categoryName);
    return system_ui_icon('category', $categoryName);
}

function system_ui_section_enabled(string $section): bool {
    $ui = system_ui_config();
    return !in_array($section, $ui['hidden_sections'] ?? [], true);
}

// Single source of truth for the runtime design tokens the System UI Optimizer controls.
// Consumed by system_ui_style_tag() for PHP pages and exposed through /api/v1/me shell
// state so the React SPA re-themes from the same admin settings instead of a drifting
// hardcoded copy in frontend/src/index.css.
function system_ui_css_vars(): array {
    $ui = system_ui_config();
    $shadow = (int)$ui['shadow_strength'];
    $fontStack = [
        'inter' => "Inter,'Segoe UI',system-ui,-apple-system,sans-serif",
        'system' => "system-ui,-apple-system,'Segoe UI',sans-serif",
        'serif' => "Georgia,'Times New Roman',serif",
        'rounded' => "'Segoe UI Rounded','Arial Rounded MT Bold',Inter,system-ui,sans-serif",
    ][$ui['font_family']] ?? "Inter,'Segoe UI',system-ui,-apple-system,sans-serif";
    return [
        '--brand' => $ui['brand'],
        '--brand-dark' => $ui['brand_dark'],
        '--brand-soft' => $ui['brand_soft'],
        '--accent' => $ui['accent'],
        '--accent-soft' => $ui['accent_soft'],
        '--ink' => $ui['ink'],
        '--text' => $ui['text'],
        '--bg' => $ui['bg'],
        '--surface' => $ui['surface'],
        '--r' => (int)$ui['card_radius'] . 'px',
        '--r-sm' => max(6, (int)$ui['panel_radius'] - 4) . 'px',
        '--shadow-sm' => '0 8px 20px -16px rgba(16,24,40,.' . min(90, $shadow) . ')',
        '--shadow' => '0 18px 42px -28px rgba(16,24,40,.' . min(90, $shadow + 8) . ')',
        '--font-ui' => $fontStack,
    ];
}

function system_ui_style_tag(): string {
    $ui = system_ui_config();
    $density = ['compact' => 34, 'comfortable' => 40, 'spacious' => 46][$ui['component_density']] ?? 40;
    $heroImage = trim((string)$ui['hero_image']);
    $overlay = max(0.2, min(0.92, ((int)$ui['hero_overlay']) / 100));
    $heroMode = $ui['hero_background_mode'] ?? 'overlay_image';
    $heroFrom = $ui['hero_gradient_from'] ?? '#111827';
    $heroTo = $ui['hero_gradient_to'] ?? '#0f766e';
    $heroPosition = $ui['hero_image_position'] ?? 'center';
    $heroBackground = "linear-gradient(115deg,{$heroFrom},{$heroTo})";
    if ($heroImage !== '') {
        $safeHero = str_replace(['"', "'", ')'], '', $heroImage);
        if ($heroMode === 'image') {
            $heroBackground = "url(\"" . e($safeHero) . "\") {$heroPosition}/cover no-repeat";
        } elseif ($heroMode === 'overlay_image') {
            $heroBackground = "linear-gradient(115deg,rgba(2,6,23,{$overlay}),rgba(29,78,216," . max(0.2, $overlay - 0.16) . ")),url(\"" . e($safeHero) . "\") {$heroPosition}/cover no-repeat";
        }
    }
    $rootVars = '';
    foreach (system_ui_css_vars() as $var => $value) $rootVars .= "$var:$value;";
    $css = ":root{" . $rootVars . "}"
        . "body{font-family:var(--font-ui);font-size:" . ((int)$ui['font_scale'] / 100 * 15.5) . "px}.container{max-width:" . (int)$ui['container_width'] . "px}.section{padding-top:" . (int)$ui['section_spacing'] . "px;padding-bottom:" . (int)$ui['section_spacing'] . "px}.grid{grid-template-columns:repeat(auto-fill,minmax(" . (int)$ui['grid_min_width'] . "px,1fr))}"
        . ".card,.filters,.panel,.dash-nav,.table-wrap,.cat-tile,input,select,textarea{border-width:" . (int)$ui['border_width'] . "px}"
        . ".btn,.header-search input,.header-search button{min-height:{$density}px}.btn,.header-search button,.hero-search .btn{border-radius:" . (int)$ui['button_radius'] . "px}"
        . "input,select,textarea{border-radius:" . (int)$ui['input_radius'] . "px}"
        . ".card-img{aspect-ratio:{$ui['card_image_ratio']}}"
        . ".card,.filters,.panel,.dash-nav,.table-wrap{border-radius:" . (int)$ui['panel_radius'] . "px}"
        . ".hero{background:{$heroBackground}}";
    if ($ui['theme_mode'] === 'soft-dark') $css .= "body{background:#0f172a;color:#dbe4ef}.site-header,.card,.filters,.panel,.table-wrap,.dash-nav,.mobile-nav{background:#172033;color:#dbe4ef;border-color:#26344d}h1,h2,h3,h4,.card-title{color:#f8fafc}.muted,.card-meta,.card-vendor{color:#a9b7ca}input,select,textarea{background:#101827;color:#f8fafc;border-color:#2d3a52}";
    if ($ui['theme_mode'] === 'high-contrast') $css .= "body{background:#fff;color:#000}h1,h2,h3,h4,.card-title{color:#000}.card,.filters,.panel,.table-wrap,.dash-nav,input,select,textarea{border-color:#000!important}.btn-primary{background:#000!important;color:#fff!important}.muted{color:#333}";
    if ($ui['focus_style'] === 'underline') $css .= "input:focus,select:focus,textarea:focus{box-shadow:none;border-color:var(--brand);border-bottom-width:3px}";
    if ($ui['focus_style'] === 'glow') $css .= "input:focus,select:focus,textarea:focus,.btn:focus-visible,a:focus-visible{box-shadow:0 0 0 5px color-mix(in srgb,var(--brand) 22%,transparent),0 0 24px color-mix(in srgb,var(--brand) 30%,transparent);outline:none}";
    if ($ui['button_style'] === 'gradient') $css .= ".btn-primary{background:linear-gradient(135deg,var(--brand),var(--accent))!important}.btn-outline{border-color:var(--brand);background:linear-gradient(135deg,#fff,var(--brand-soft))}";
    if ($ui['button_style'] === 'flat') $css .= ".btn,.btn:hover{box-shadow:none!important;transform:none}.btn-primary{background:var(--brand)!important}";
    if ($ui['header_behavior'] === 'static') $css .= ".site-header{position:relative}";
    if ($ui['header_behavior'] === 'floating') $css .= ".site-header{top:10px;margin:10px auto 0;width:min(calc(100% - 24px)," . (int)$ui['container_width'] . "px);border-radius:18px;border:1px solid var(--line);box-shadow:var(--shadow-sm)}";
    if ($ui['nav_style'] === 'solid') $css .= ".site-header{background:#fff;backdrop-filter:none}.main-nav>a:hover{background:var(--brand-soft)}";
    if ($ui['nav_style'] === 'dark') $css .= ".site-header{background:#0f172a;border-bottom-color:#243148}.site-header .logo,.site-header .main-nav>a{color:#fff}.site-header .main-nav>a:hover{background:rgba(255,255,255,.12)}";
    if ($ui['form_style'] === 'outlined') $css .= "input,select,textarea{background:#fff;border-width:2px}.panel,.filters{background:#fff}";
    if ($ui['form_style'] === 'filled') $css .= "input,select,textarea{background:var(--surface-2);border-color:transparent}.filters label input,.filters label select{background:#fff}";
    if ($ui['ad_style'] === 'boxed') $css .= ".ad-banner,.ad-house{border:1px solid var(--line);box-shadow:var(--shadow);padding:8px}.ad-label{background:var(--brand);color:#fff}";
    if ($ui['ad_style'] === 'premium') $css .= ".ad-banner,.ad-house{border:1px solid rgba(249,115,22,.3);box-shadow:0 20px 48px -30px rgba(249,115,22,.8)}.ad-label{background:var(--accent);color:#fff}";
    if ($ui['table_style'] === 'striped') $css .= ".data-table tr:nth-child(even) td{background:#f8fafc}";
    if ($ui['table_style'] === 'compact') $css .= ".data-table th,.data-table td{padding:7px 10px}.data-table{font-size:.8rem}";
    if ($ui['badge_style'] === 'square') $css .= ".badge,.pill,.btn-badge{border-radius:7px}";
    if ($ui['badge_style'] === 'soft') $css .= ".badge{background:var(--brand-soft);color:var(--brand-dark)}";
    if ($ui['card_style'] === 'borderless') $css .= ".card{border:0}.card-body{padding:16px}";
    if ($ui['card_style'] === 'outlined') $css .= ".card{box-shadow:none;border-width:2px}.card:hover{box-shadow:var(--shadow-sm)}";
    if ($ui['card_style'] === 'compact') $css .= ".card-body{padding:11px 12px}.card-title{min-height:auto;font-size:.9rem}.card-price{font-size:.96rem}";
    if ($ui['hover_motion'] === 'soft') $css .= ".card:hover,.cat-tile:hover,.btn:hover{transform:none;box-shadow:var(--shadow-sm)}";
    if ($ui['hover_motion'] === 'none') $css .= "*,*::before,*::after{transition:none!important;animation:none!important}.card:hover,.cat-tile:hover,.btn:hover{transform:none!important}";
    if ($ui['image_treatment'] === 'warm') $css .= ".card-img img,.gallery img{filter:saturate(1.08) sepia(.08)}";
    if ($ui['image_treatment'] === 'cool') $css .= ".card-img img,.gallery img{filter:saturate(.95) hue-rotate(8deg)}";
    if ($ui['image_treatment'] === 'mono') $css .= ".card-img img,.gallery img{filter:grayscale(1)}";
    if ($ui['image_hover'] === 'fade') $css .= ".card:hover .card-img img{transform:none;opacity:.86}";
    if ($ui['image_hover'] === 'none') $css .= ".card:hover .card-img img{transform:none;opacity:1}";
    if ($ui['section_head_style'] === 'rule') $css .= ".section-head{border-bottom:1px solid var(--line);padding-bottom:10px}";
    if ($ui['section_head_style'] === 'boxed') $css .= ".section-head{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px;box-shadow:var(--shadow-xs)}";
    if ($ui['filters_behavior'] === 'static') $css .= ".filters{position:static}";
    if ($ui['card_text_align'] === 'center') $css .= ".card-body{text-align:center;align-items:center}.card-meta{justify-content:center}";
    if ($ui['footer_style'] === 'light') $css .= ".site-footer{background:#fff;color:var(--text-2);border-top:1px solid var(--line)}.site-footer h4,.site-footer .logo{color:var(--ink)}.site-footer a{color:var(--text-2)}.footer-bottom{border-top-color:var(--line);color:var(--muted)}";
    if ($ui['footer_style'] === 'brand') $css .= ".site-footer{background:linear-gradient(135deg,var(--brand-dark),var(--brand));color:#dff8f4}.site-footer a{color:#eafcf9}.footer-bottom{border-top-color:rgba(255,255,255,.18);color:#c7f4ee}";
    if ($ui['mobile_nav_style'] === 'minimal') $css .= ".mobile-nav{background:#fff}.mobile-nav .mn-icon{background:transparent!important;width:auto;height:auto}.mobile-nav a.current{color:var(--brand)}";
    if ($ui['mobile_nav_style'] === 'boxed') $css .= ".mobile-nav{left:10px;right:10px;bottom:10px;border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}";
    if ($ui['search_style'] === 'box') $css .= ".header-search input,.header-search button{border-radius:12px}.hero-search,.hero-search input,.hero-search select,.hero-search .btn{border-radius:12px}";
    if ($ui['search_style'] === 'underline') $css .= ".header-search input{border-width:0 0 2px 0;border-radius:0;background:transparent}.header-search button{border-radius:10px}.header-search::before,.header-search::after{display:none}";
    if ($ui['hero_align'] === 'center') $css .= ".hero{text-align:center}.hero p,.hero-search{margin-left:auto;margin-right:auto}.hero-links,.hero-stats{justify-content:center}";
    if ($ui['hero_align'] === 'split') $css .= ".hero .container{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.65fr);gap:28px;align-items:center}.hero .container::after{content:'';min-height:260px;border-radius:var(--r-lg);background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);box-shadow:var(--shadow)}@media(max-width:600px){.hero .container{display:block}.hero .container::after{display:none}}";
    if ($ui['hero_height'] === 'compact') $css .= ".hero{padding-top:42px;padding-bottom:34px}.hero h1{font-size:clamp(1.8rem,4vw,2.7rem)}";
    if ($ui['hero_height'] === 'tall') $css .= ".hero{padding-top:104px;padding-bottom:92px}";
    if ($ui['category_style'] === 'minimal') $css .= ".cat-tile{box-shadow:none;background:transparent}.cat-icon{display:none}";
    if ($ui['category_style'] === 'banner') $css .= ".cat-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}.cat-tile{min-height:96px;background:linear-gradient(135deg,var(--brand-soft),#fff)}.cat-icon{background:#fff}";
    if ($ui['category_style'] === 'rail') $css .= ".cat-grid{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;padding:2px 2px 10px}.cat-grid::-webkit-scrollbar{height:6px}.cat-grid::-webkit-scrollbar-thumb{background:var(--line);border-radius:999px}.cat-tile{min-width:max-content;min-height:48px;padding:8px 13px;border-radius:999px;scroll-snap-align:start}.cat-icon{width:30px;height:30px;border-radius:999px}.cat-icon .ui-icon{width:16px;height:16px}";
    if ($ui['price_style'] === 'brand') $css .= ".card-price,.price{color:var(--brand-dark)}";
    if ($ui['price_style'] === 'accent') $css .= ".card-price,.price{color:var(--accent)}";
    if ($ui['price_style'] === 'dark') $css .= ".card-price,.price{color:var(--ink)}";
    if ($ui['empty_state_style'] === 'soft') $css .= ".empty-state{border-style:solid;background:var(--brand-soft);color:var(--brand-dark)}";
    if ($ui['empty_state_style'] === 'plain') $css .= ".empty-state{border-color:transparent;background:transparent;box-shadow:none}";
    if (!empty($ui['announcement_enabled'])) {
        $barBg = $ui['announcement_tone'] === 'accent' ? 'var(--accent)' : ($ui['announcement_tone'] === 'dark' ? 'var(--ink)' : ($ui['announcement_tone'] === 'light' ? '#fff' : 'var(--brand)'));
        $barColor = $ui['announcement_tone'] === 'light' ? 'var(--ink)' : '#fff';
        $css .= ".announcement-bar{display:block;background:{$barBg};color:{$barColor}}";
    }
    if (trim((string)$ui['custom_css']) !== '') $css .= "\n" . $ui['custom_css'];
    return "<style id=\"system-ui-optimizer-css\">\n{$css}\n</style>";
}

// ---------- location detection (GPS permission → IP fallback → default) ----------
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function nearest_city(float $lat, float $lng): string {
    $best = DEFAULT_CITY; $bestDist = INF;
    foreach (CITY_COORDS as $city => [$clat, $clng]) {
        $d = haversine_km($lat, $lng, $clat, $clng);
        if ($d < $bestDist) { $bestDist = $d; $best = $city; }
    }
    return $best;
}

/** Nearest known neighborhood within a given city, or null if that city has no sub-city map yet. */
function nearest_subcity(string $city, float $lat, float $lng): ?string {
    $options = SUBCITY_COORDS[$city] ?? [];
    if (!$options) return null;
    $best = null; $bestDist = INF;
    foreach ($options as $subcity => [$slat, $slng]) {
        $d = haversine_km($lat, $lng, $slat, $slng);
        if ($d < $bestDist) { $bestDist = $d; $best = $subcity; }
    }
    return $best;
}

/** City + (when available) neighborhood nearest to a precise GPS fix. */
function nearest_location(float $lat, float $lng): array {
    $city = nearest_city($lat, $lng);
    return ['city' => $city, 'subcity' => nearest_subcity($city, $lat, $lng)];
}

function is_private_ip(string $ip): bool {
    return $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** Best-effort server-side IP geolocation. Returns null on any failure (private IP, no network, bad response). */
function ip_geolocate(): ?array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_private_ip($ip)) return null; // localhost/LAN — nothing a public IP API can resolve

    $url = IP_GEO_API . rawurlencode($ip) . '?fields=status,lat,lon,city';
    $host = parse_url(IP_GEO_API, PHP_URL_HOST);
    $json = $host ? remote_text($url, [$host], 3) : null;
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!$data) return null;
    $lat = $data['lat'] ?? $data['latitude'] ?? null;
    $lng = $data['lon'] ?? $data['lng'] ?? $data['longitude'] ?? null;
    if (($data['status'] ?? 'success') !== 'success' || $lat === null || $lng === null) return null;
    return ['lat' => (float)$lat, 'lng' => (float)$lng];
}

/** Current visitor location: session (fast) → cookie (returning visit) → IP lookup (once) → default city. */
function user_location(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    // admin can switch auto-detection off (Settings → Features): everyone gets the default city,
    // and the 'disabled' source stops app.js from requesting GPS permission
    if (function_exists('feature_enabled') && !feature_enabled('location_detection')) {
        $city = (string)sys('general.default_city', DEFAULT_CITY);
        [$lat, $lng] = CITY_COORDS[$city] ?? CITY_COORDS[DEFAULT_CITY];
        return $cached = ['city' => $city, 'subcity' => null, 'lat' => $lat, 'lng' => $lng, 'source' => 'disabled'];
    }

    if (!empty($_SESSION['loc'])) return $cached = $_SESSION['loc'];

    if (!empty($_COOKIE['ak_loc'])) {
        $decoded = json_decode($_COOKIE['ak_loc'], true);
        if (is_array($decoded) && isset($decoded['city'])) {
            $_SESSION['loc'] = $decoded;
            return $cached = $decoded;
        }
    }

    $geo = ip_geolocate();
    if ($geo) {
        $loc = ['city' => nearest_city($geo['lat'], $geo['lng']), 'subcity' => null, 'lat' => $geo['lat'], 'lng' => $geo['lng'], 'source' => 'ip'];
    } else {
        [$lat, $lng] = CITY_COORDS[DEFAULT_CITY];
        $loc = ['city' => DEFAULT_CITY, 'subcity' => null, 'lat' => $lat, 'lng' => $lng, 'source' => 'default'];
    }
    $_SESSION['loc'] = $loc;
    if (!headers_sent()) setcookie('ak_loc', json_encode($loc), time() + 30 * 86400, BASE_URL . '/');
    return $cached = $loc;
}

/** Explicitly set the visitor's location (GPS fix or manual city pick) and persist it. */
function set_user_location(string $city, ?string $subcity, ?float $lat, ?float $lng, string $source): array {
    if ($lat === null || $lng === null) [$lat, $lng] = CITY_COORDS[$city] ?? CITY_COORDS[DEFAULT_CITY];
    $loc = ['city' => $city, 'subcity' => $subcity, 'lat' => $lat, 'lng' => $lng, 'source' => $source];
    $_SESSION['loc'] = $loc;
    if (!headers_sent()) setcookie('ak_loc', json_encode($loc), time() + 30 * 86400, BASE_URL . '/');
    return $loc;
}

// ---------- cart (session) ----------
function cart(): array { return $_SESSION['cart'] ?? []; }

function cart_add(string $type, int $id, float $qty): void {
    $key = "$type:$id";
    $_SESSION['cart'][$key] = max(0.01, ($_SESSION['cart'][$key] ?? 0) + $qty);
}

function cart_set(string $type, int $id, float $qty): void {
    $key = "$type:$id";
    if ($qty <= 0) unset($_SESSION['cart'][$key]);
    else $_SESSION['cart'][$key] = $qty;
}

/** Resolve cart into rows grouped by business: [business_id => ['business'=>row,'items'=>[],'subtotal'=>n]] */
function cart_resolve(): array {
    $groups = [];
    foreach (cart() as $key => $qty) {
        [$type, $id] = explode(':', $key);
        if ($type === 'product') {
            $l = row("SELECT p.*, b.business_name FROM products p JOIN businesses b ON b.id = p.business_id
                      WHERE p.id = ? AND p.status = 'active'", [(int)$id]);
            if (!$l) { cart_set($type, (int)$id, 0); continue; }
            $price = $l['discount_price'] > 0 ? (float)$l['discount_price'] : (float)$l['price'];
            $title = $l['title']; $unit = $l['unit'] ?: 'piece';
        } elseif ($type === 'supply') {
            $l = row("SELECT s.*, b.business_name FROM supplies s JOIN businesses b ON b.id = s.business_id
                      WHERE s.id = ? AND s.status = 'active'", [(int)$id]);
            if (!$l) { cart_set($type, (int)$id, 0); continue; }
            $price = (float)$l['price_per_unit'];
            $qty = max($qty, (float)$l['minimum_order_quantity']);
            $title = $l['name']; $unit = $l['unit_of_measurement'] ?: 'piece';
        } else continue;
        $bid = (int)$l['business_id'];
        $groups[$bid]['business_id'] = $bid;
        $groups[$bid]['business_name'] = $l['business_name'];
        $groups[$bid]['items'][] = ['type' => $type, 'id' => (int)$id, 'title' => $title, 'slug' => $l['slug'],
            'price' => $price, 'qty' => $qty, 'unit' => $unit, 'line' => $price * $qty];
        $groups[$bid]['subtotal'] = ($groups[$bid]['subtotal'] ?? 0) + $price * $qty;
    }
    return $groups;
}

function cart_count(): int { return count(cart()); }

/** Allowed order lifecycle transitions. Terminal states cannot be reopened. */
function order_status_transitions(string $status): array {
    return match ($status) {
        'pending' => ['confirmed', 'deposit_paid', 'cancelled', 'disputed'],
        'confirmed' => ['deposit_paid', 'processing', 'cancelled', 'disputed'],
        'deposit_paid' => ['processing', 'refunded', 'disputed'],
        'processing' => ['ready_for_delivery', 'refunded', 'disputed'],
        'ready_for_delivery' => ['out_for_delivery', 'delivered', 'refunded', 'disputed'],
        'out_for_delivery' => ['delivered', 'disputed'],
        'delivered' => ['completed', 'refunded', 'disputed'],
        'disputed' => ['refunded', 'completed'],
        'completed' => ['refunded', 'disputed'],
        default => [],
    };
}

function order_can_transition(string $from, string $to): bool {
    return $from !== $to && in_array($to, order_status_transitions($from), true);
}

/** Lock listing rows, validate availability, decrement stock, and refresh checkout
 * snapshot prices. The caller must have an open database transaction. */
function reserve_order_inventory(array &$groups): void {
    foreach ($groups as &$group) {
        $subtotal = 0.0;
        foreach ($group['items'] as &$item) {
            $table = $item['type'] === 'product' ? 'products' : ($item['type'] === 'supply' ? 'supplies' : '');
            if ($table === '') throw new RuntimeException('Unsupported cart item.');
            $record = row("SELECT * FROM `$table` WHERE id = ? FOR UPDATE", [(int)$item['id']]);
            if (!$record || $record['status'] !== 'active') {
                throw new RuntimeException($item['title'] . ' is no longer available.');
            }
            $available = (float)$record['stock_quantity'];
            $quantity = (float)$item['qty'];
            if ($quantity <= 0 || $available < $quantity) {
                throw new RuntimeException($item['title'] . ' has only ' . max(0, $available) . ' available.');
            }
            if ($item['type'] === 'product') {
                $price = (float)$record['discount_price'] > 0 ? (float)$record['discount_price'] : (float)$record['price'];
                $title = $record['title'];
            } else {
                $minimum = max(0.01, (float)$record['minimum_order_quantity']);
                if ($quantity < $minimum) {
                    throw new RuntimeException($item['title'] . ' requires a minimum order of ' . $minimum . '.');
                }
                $price = (float)$record['price_per_unit'];
                $title = $record['name'];
            }
            $emptyStatus = $item['type'] === 'product' ? 'sold_out' : 'out_of_stock';
            $updated = q("UPDATE `$table` SET stock_quantity = stock_quantity - ?,
                status = CASE WHEN stock_quantity - ? <= 0 THEN ? ELSE status END
                WHERE id = ? AND status = 'active' AND stock_quantity >= ?",
                [$quantity, $quantity, $emptyStatus, (int)$item['id'], $quantity])->rowCount();
            if ($updated !== 1) throw new RuntimeException($item['title'] . ' is no longer available in that quantity.');
            $item['title'] = $title;
            $item['price'] = $price;
            $item['line'] = $price * $quantity;
            $subtotal += $item['line'];
        }
        unset($item);
        $group['subtotal'] = $subtotal;
    }
    unset($group);
}

function restore_order_inventory(int $orderId): void {
    $order = row("SELECT inventory_committed FROM orders WHERE id = ? FOR UPDATE", [$orderId]);
    if (!$order || !(int)$order['inventory_committed']) return;
    foreach (rows("SELECT listing_type, listing_id, quantity FROM order_items WHERE order_id = ?", [$orderId]) as $item) {
        $table = $item['listing_type'] === 'product' ? 'products' : ($item['listing_type'] === 'supply' ? 'supplies' : '');
        if ($table === '') continue;
        $emptyStatus = $item['listing_type'] === 'product' ? 'sold_out' : 'out_of_stock';
        q("UPDATE `$table` SET stock_quantity = stock_quantity + ?,
            status = CASE WHEN status = ? THEN 'active' ELSE status END WHERE id = ?",
            [(float)$item['quantity'], $emptyStatus, (int)$item['listing_id']]);
    }
    q("UPDATE orders SET inventory_committed = 0 WHERE id = ?", [$orderId]);
}

/** Validate, apply, and audit an order status change. */
function transition_order_status(array $order, string $to, ?int $changedBy = null, ?string $note = null): bool {
    $from = (string)$order['status'];
    if (!order_can_transition($from, $to)) return false;
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) db()->beginTransaction();
    try {
        $fresh = row("SELECT * FROM orders WHERE id = ? FOR UPDATE", [(int)$order['id']]);
        if (!$fresh || (string)$fresh['status'] !== $from || !order_can_transition($from, $to)) {
            if ($ownsTransaction) db()->rollBack();
            return false;
        }
        if (in_array($to, ['cancelled', 'refunded'], true)) restore_order_inventory((int)$fresh['id']);
        q("UPDATE orders SET status = ? WHERE id = ?", [$to, (int)$fresh['id']]);
        q("INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
            VALUES (?,?,?,?,?)", [(int)$fresh['id'], $from, $to, $changedBy, $note]);
        if ($ownsTransaction) db()->commit();
        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

// ---------- subscriptions & limits ----------
/** Drop the premium_verified badge back to the business's earned level when no active premium
 * listing plan (and no independently-approved premium verification request) still backs it.
 * Shared by the nightly expiry cron and activate_subscription() so a superseding downgrade can
 * never strand a premium badge the vendor no longer pays for. No-op unless a revert is due. */
function revert_premium_badge_if_unbacked(int $businessId): void {
    $biz = row("SELECT verification_status FROM businesses WHERE id = ?", [$businessId]);
    if (!$biz || $biz['verification_status'] !== 'premium_verified') return;
    // An active premium listing plan still backs the badge — leave it.
    if (current_plan($businessId) === 'premium') return;
    // Earned independently through an approved premium verification request — never revoke.
    if (val("SELECT COUNT(*) FROM verification_requests
             WHERE business_id = ? AND status = 'approved' AND requested_level = 'premium_verified'", [$businessId])) return;
    $fallback = val("SELECT requested_level FROM verification_requests
        WHERE business_id = ? AND status = 'approved'
        ORDER BY FIELD(requested_level, 'phone_verified','location_verified','document_verified') DESC, id DESC LIMIT 1",
        [$businessId]) ?: 'unverified';
    q("UPDATE businesses SET verification_status = ? WHERE id = ?", [$fallback, $businessId]);
}

/** Activate a paid subscription on admin payment-confirm. Guarantees at most one active
 * subscription per (business, type) and, on renewal, extends from the current still-future
 * expiry rather than NOW() so time the vendor already paid for is never thrown away. */
function activate_subscription(array $sub): void {
    $bizId  = (int)$sub['business_id'];
    $type   = in_array($sub['type'] ?? 'listing_plan', ['listing_plan', 'boost'], true) ? $sub['type'] : 'listing_plan';
    $months = max(1, min(12, (int)$sub['months']));
    // Renewal extension: start from the latest still-future expiry of an existing active
    // same-type subscription; otherwise from now.
    $existingEnd = val("SELECT MAX(ends_at) FROM subscriptions
        WHERE business_id = ? AND type = ? AND status = 'active' AND id <> ?
          AND ends_at IS NOT NULL AND ends_at > NOW()", [$bizId, $type, (int)$sub['id']]);
    $base = $existingEnd ?: date('Y-m-d H:i:s');
    // Only one active subscription of a given type should ever exist — retire the rest.
    q("UPDATE subscriptions SET status = 'superseded'
       WHERE business_id = ? AND type = ? AND status = 'active' AND id <> ?", [$bizId, $type, (int)$sub['id']]);
    // $months is an int (cast + clamped above), safe to inline — INTERVAL takes no placeholder.
    q("UPDATE subscriptions SET status = 'active', starts_at = NOW(), ends_at = DATE_ADD(?, INTERVAL " . $months . " MONTH)
       WHERE id = ?", [$base, (int)$sub['id']]);
    // Premium listing plan grants the premium_verified badge; a non-premium activation that
    // superseded a premium plan must not leave the badge stranded.
    if ($type === 'listing_plan') {
        if (($sub['plan'] ?? '') === 'premium') {
            q("UPDATE businesses SET verification_status = 'premium_verified' WHERE id = ?", [$bizId]);
        } else {
            revert_premium_badge_if_unbacked($bizId);
        }
    }
}

function current_plan(int $businessId): string {
    // Scoped to type='listing_plan' — subscriptions also carries Boost tier purchases in the
    // same table/plan column (type='boost'), which must never be read as a listing-quota plan.
    $sub = row("SELECT plan FROM subscriptions WHERE business_id = ? AND type = 'listing_plan' AND status = 'active'
                AND (ends_at IS NULL OR ends_at > NOW()) ORDER BY ends_at DESC LIMIT 1", [$businessId]);
    return $sub['plan'] ?? 'free';
}

/** Active Boost tier key (see BOOST_TIERS), or null if the business has none active. */
function current_boost(int $businessId): ?string {
    $sub = row("SELECT plan FROM subscriptions WHERE business_id = ? AND type = 'boost' AND status = 'active'
                AND (ends_at IS NULL OR ends_at > NOW()) ORDER BY ends_at DESC LIMIT 1", [$businessId]);
    return $sub['plan'] ?? null;
}

function boost_rank_weight(?string $tier): int {
    $tiers = boost_tiers();
    return isset($tier, $tiers[$tier]) ? (int)$tiers[$tier]['rank_weight'] : 0;
}

/** SQL expression returning the active Boost rank weight for a business id expression. */
function boost_rank_sql(string $businessIdExpr = 'b.id'): string {
    $cases = [];
    foreach (boost_tiers() as $key => $tier) {
        $cases[] = "WHEN " . db()->quote($key) . " THEN " . (int)$tier['rank_weight'];
    }
    return "(CASE (
        SELECT s.plan FROM subscriptions s
        WHERE s.business_id = {$businessIdExpr}
          AND s.type = 'boost'
          AND s.status = 'active'
          AND (s.ends_at IS NULL OR s.ends_at > NOW())
        ORDER BY s.ends_at DESC
        LIMIT 1
    ) " . implode(' ', $cases) . " ELSE 0 END)";
}

function listing_count(int $businessId): int {
    return (int)val("SELECT
        (SELECT COUNT(*) FROM products WHERE business_id = ? AND status NOT IN ('deleted','rejected'))
      + (SELECT COUNT(*) FROM services WHERE business_id = ? AND status NOT IN ('deleted','rejected'))
      + (SELECT COUNT(*) FROM supplies WHERE business_id = ? AND status NOT IN ('deleted','rejected'))",
      [$businessId, $businessId, $businessId]);
}

function can_add_listing(int $businessId): bool {
    $limit = plans()[current_plan($businessId)]['listings'];
    return $limit < 0 || listing_count($businessId) < $limit;
}

function can_add_video(int $businessId): bool {
    $limit = plans()[current_plan($businessId)]['videos'];
    if ($limit < 0) return true;
    return (int)val("SELECT COUNT(*) FROM video_posts WHERE business_id = ? AND status NOT IN ('deleted','rejected')", [$businessId]) < $limit;
}

// ---------- moderation reports ----------
function report_allowed_types(): array {
    return ['product', 'service', 'supply', 'business', 'video', 'review', 'user'];
}

/** Create a moderation report from either a public PHP form or the React session API. */
function create_report(?int $reporterId, string $type, int $reportedId, string $reason, string $description = ''): array {
    $type = trim($type);
    $reason = trim($reason);
    $description = trim($description);

    if (!in_array($type, report_allowed_types(), true) || $reportedId <= 0 || $reason === '') {
        return [null, ['Report could not be submitted.']];
    }

    q("INSERT INTO reports (reporter_id, reported_type, reported_id, reason, description) VALUES (?,?,?,?,?)",
      [$reporterId, $type, $reportedId, $reason, $description ?: null]);
    if ($type === 'video') q("UPDATE video_posts SET reports_count = reports_count + 1 WHERE id = ?", [$reportedId]);

    return [row("SELECT * FROM reports WHERE id = ?", [db()->lastInsertId()]), []];
}

// ---------- promotions ----------
/** Apply/remove visibility flags for a promotion row. */
function promotion_apply(array $p, bool $on): void {
    $flag = $on ? 1 : 0;
    if ($p['promotable_type'] === 'video') {
        q("UPDATE video_posts SET is_promoted = ? WHERE id = ?", [$flag, $p['promotable_id']]);
    } elseif (isset(LISTING_TABLES[$p['promotable_type']])) {
        $t = LISTING_TABLES[$p['promotable_type']];
        q("UPDATE `$t` SET is_featured = ?, is_promoted = ? WHERE id = ?", [$flag, $flag, $p['promotable_id']]);
    }
}

function promotion_target_ready(array $p): bool {
    if (($p['promotable_type'] ?? '') === 'business') {
        return (bool)val("SELECT COUNT(*) FROM businesses WHERE id = ? AND status = 'active'", [$p['promotable_id']]);
    }
    if (($p['promotable_type'] ?? '') === 'video') {
        return (bool)val("SELECT COUNT(*) FROM video_posts WHERE id = ? AND status = 'approved'", [$p['promotable_id']]);
    }
    if (isset(LISTING_TABLES[$p['promotable_type'] ?? ''])) {
        $t = LISTING_TABLES[$p['promotable_type']];
        return (bool)val("SELECT COUNT(*) FROM `$t` WHERE id = ? AND status = 'active'", [$p['promotable_id']]);
    }
    return false;
}

function promotion_activate(array $p, ?string $startsAt = null): bool {
    if (!promotion_target_ready($p)) return false;
    $startsAt = $startsAt ?: date('Y-m-d H:i:s');
    // duration_days (TOP Pin's 7/30-day flat packages) takes precedence over duration_weeks
    // when set — duration_weeks alone can't express a 30-day period without rounding.
    if (!empty($p['duration_days'])) {
        q("UPDATE promotions SET status = 'active', starts_at = ?, ends_at = DATE_ADD(?, INTERVAL duration_days DAY) WHERE id = ?",
          [$startsAt, $startsAt, $p['id']]);
    } else {
        q("UPDATE promotions SET status = 'active', starts_at = ?, ends_at = DATE_ADD(?, INTERVAL duration_weeks WEEK) WHERE id = ?",
          [$startsAt, $startsAt, $p['id']]);
    }
    $fresh = row("SELECT * FROM promotions WHERE id = ?", [$p['id']]);
    if ($fresh) promotion_apply($fresh, true);
    return true;
}

function order_number(): string {
    return 'EG' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

function upload_model(array $file, string $ext): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (upload_rate_exceeded('model')) {
        flash('Too many upload attempts. Please wait a while before uploading more files.', 'error');
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) { flash('3D model upload failed.', 'error'); return null; }
    $maxMb = (int)sys('limits.ar_model_max_mb', 10);
    if ($file['size'] > $maxMb * 1024 * 1024) { flash("3D model too large (max $maxMb MB).", 'error'); return null; }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== $ext) { flash("Model must be a .$ext file.", 'error'); return null; }
    // Content-sniff the actual bytes rather than trusting the client-supplied filename/extension alone.
    // .glb (glTF Binary) always starts with the 4-byte magic "glTF"; .usdz is a zip container and
    // always starts with a standard ZIP local-file-header signature. This rejects arbitrary content
    // (e.g. an HTML/script payload renamed to .glb) that would otherwise be stored and, since Apache
    // has no MIME mapping for these extensions and sends no Content-Type, could be sniffed and
    // rendered as HTML by a browser that opens the file directly — a stored-XSS vector.
    $head = @file_get_contents($file['tmp_name'], false, null, 0, 8);
    $validMagic = match ($ext) {
        'glb' => substr((string)$head, 0, 4) === 'glTF',
        'usdz' => in_array(substr((string)$head, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true),
        default => false,
    };
    if (!$validMagic) { flash("File does not look like a valid .$ext model.", 'error'); return null; }
    $dir = UPLOAD_DIR . '/ar-models';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'ar-models/' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) { flash('Could not save model.', 'error'); return null; }
    return $name;
}

function paginate(int $total, int $perPage, int $page): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return [$page, $pages, ($page - 1) * $perPage];
}
