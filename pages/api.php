<?php
/**
 * Bearer-token JSON REST API (§18) for non-browser clients (mobile apps, third-party
 * integrations) so they can reuse the backend later (§30.15).
 * Auth: POST /api/login returns a bearer token; send it as "Authorization: Bearer <token>".
 * Bearer tokens are never sent by a browser automatically, so this API is inherently
 * CSRF-safe and needs no CSRF check.
 *
 * The React SPA uses a *different* prefix — /api/v1/* (pages/api_v1.php) — which reuses
 * the existing PHP session cookie instead of a bearer token. The two are intentionally
 * not merged into one auth model; see pages/api_v1.php for why. Both share the same JSON
 * envelope (api_out/api_error/api_validation_error, defined once in app/helpers.php) so
 * responses look identical regardless of which one a client talks to.
 * Expects $apiSeg (path segments after /api).
 */
header('Content-Type: application/json; charset=utf-8');

/** Resolve the bearer token to a user row, or null. */
function api_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $hdr, $m)) return $user = null;
    $t = row("SELECT t.*, u.* FROM api_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND u.status = 'active'", [hash('sha256', $m[1])]);
    if ($t) q("UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?", [hash('sha256', $m[1])]);
    return $user = $t ?: null;
}
function api_require_user(): array {
    return api_user() ?? api_error('Authentication required. Send Authorization: Bearer <token>.', 401);
}

function api_listing_row(string $type, array $l): array {
    return [
        'id' => (int)$l['id'],
        'type' => $type,
        'title' => $l[listing_title_col($type)],
        'slug' => $l['slug'],
        'url' => listing_url($type, $l),
        'description' => $l['description'],
        'price' => $type === 'product' ? ($l['discount_price'] > 0 ? (float)$l['discount_price'] : (float)$l['price'])
                 : ($type === 'service' ? (float)$l['starting_price'] : (float)$l['price_per_unit']),
        'currency' => 'ETB',
        'city' => $l['city'],
        'subcity' => $l['subcity'],
        'business' => ['id' => (int)$l['business_id'], 'name' => $l['b_name'] ?? null, 'verified' => ($l['b_verification'] ?? 'unverified') !== 'unverified'],
        'category' => $l['c_name'] ?? null,
        'image' => listing_image($type, $l),
        'is_featured' => (bool)$l['is_featured'],
        'views' => (int)$l['views_count'],
        'created_at' => $l['created_at'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$r0 = $apiSeg[0] ?? '';
$r1 = $apiSeg[1] ?? '';
$body = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_POST;

// ---------- auth ----------
if ($r0 === 'login' && $method === 'POST') {
    $identity = trim($body['identity'] ?? $body['phone'] ?? '');
    if (login_throttled($identity)) api_error('Too many failed attempts — try later.', 429);
    $user = row("SELECT * FROM users WHERE (phone = ? OR email = ?) AND status IN ('active','pending')", [$identity, $identity]);
    if (!$user || !password_verify($body['password'] ?? '', $user['password'])) {
        login_record($identity, false);
        api_error('Wrong phone/email or password.', 401);
    }
    login_record($identity, true);
    $token = bin2hex(random_bytes(32));
    q("INSERT INTO api_tokens (user_id, token_hash, label) VALUES (?,?,?)",
      [$user['id'], hash('sha256', $token), mb_substr($body['device'] ?? 'api', 0, 100)]);
    api_out(['ok' => true, 'token' => $token, 'user' => ['id' => (int)$user['id'], 'name' => $user['full_name'], 'account_type' => $user['account_type']]]);
}
if ($r0 === 'register' && $method === 'POST') {
    $name = trim($body['full_name'] ?? '');
    $phone = preg_replace('/[^\d+]/', '', $body['phone'] ?? '');
    $pass = $body['password'] ?? '';
    if (!sys('general.registration_open', 1)) api_error('Registrations are temporarily closed.', 403);
    $minPass = (int)sys('auth.min_password_len', 6);
    if (mb_strlen($name) < 2 || strlen($phone) < 9 || strlen($pass) < $minPass) api_error("full_name, phone and password ($minPass+) required.");
    if (val("SELECT COUNT(*) FROM users WHERE phone = ?", [$phone])) api_error('Phone already registered.', 409);
    q("INSERT INTO users (full_name, phone, password, account_type, status) VALUES (?,?,?, 'customer', 'active')",
      [$name, $phone, password_hash($pass, PASSWORD_BCRYPT)]);
    otp_send($phone, 'verify_phone');
    api_out(['ok' => true, 'message' => 'Registered. Verify your phone with the SMS code via POST /api/otp/verify.'], 201);
}
if ($r0 === 'otp' && $r1 === 'send' && $method === 'POST') {
    $phone = preg_replace('/[^\d+]/', '', $body['phone'] ?? '');
    if (strlen($phone) < 9) api_error('phone required.');
    api_out(['ok' => otp_send($phone, in_array($body['purpose'] ?? '', ['verify_phone', 'reset_password'], true) ? $body['purpose'] : 'verify_phone')]);
}
if ($r0 === 'otp' && $r1 === 'verify' && $method === 'POST') {
    $phone = preg_replace('/[^\d+]/', '', $body['phone'] ?? '');
    if (!otp_verify($phone, 'verify_phone', $body['code'] ?? '')) api_error('Wrong or expired code.', 422);
    q("UPDATE users SET phone_verified_at = NOW() WHERE phone = ?", [$phone]);
    api_out(['ok' => true]);
}
if ($r0 === 'me' && $method === 'GET') {
    $me = api_require_user();
    api_out(['ok' => true, 'user' => [
        'id' => (int)$me['user_id'], 'name' => $me['full_name'], 'phone' => $me['phone'],
        'email' => $me['email'], 'account_type' => $me['account_type'],
        'phone_verified' => (bool)$me['phone_verified_at'],
    ]]);
}
if ($r0 === 'logout' && $method === 'POST') {
    api_require_user();
    $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    preg_match('/^Bearer\s+(\S+)$/i', $hdr, $m);
    q("DELETE FROM api_tokens WHERE token_hash = ?", [hash('sha256', $m[1])]);
    api_out(['ok' => true]);
}

// ---------- listings: /api/products|services|supplies[/{id}] ----------
$typeMap = ['products' => 'product', 'services' => 'service', 'supplies' => 'supply'];
if (isset($typeMap[$r0]) && $method === 'GET') {
    $type = $typeMap[$r0];
    $table = LISTING_TABLES[$type];
    $col = listing_title_col($type);
    if ($r1 !== '') {
        $l = row("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name
                  FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
                  WHERE (l.id = ? OR l.slug = ?) AND l.status = 'active'", [(int)$r1, $r1]);
        if (!$l) api_error('Not found.', 404);
        api_out(['ok' => true, 'data' => api_listing_row($type, $l)]);
    }
    $where = ["l.status = 'active'", "b.status = 'active'"];
    $params = [];
    foreach (['city' => 'l.city', 'subcity' => 'l.subcity'] as $k => $colName) {
        if (!empty($_GET[$k])) { $where[] = "$colName = ?"; $params[] = $_GET[$k]; }
    }
    if (!empty($_GET['category'])) { $where[] = "c.slug = ?"; $params[] = $_GET['category']; }
    if (!empty($_GET['q'])) { $where[] = "(l.`$col` LIKE ? OR l.description LIKE ?)"; array_push($params, '%' . $_GET['q'] . '%', '%' . $_GET['q'] . '%'); }
    $priceCol = ['product' => 'l.price', 'service' => 'l.starting_price', 'supply' => 'l.price_per_unit'][$type];
    if (!empty($_GET['min_price'])) { $where[] = "$priceCol >= ?"; $params[] = (float)$_GET['min_price']; }
    if (!empty($_GET['max_price'])) { $where[] = "$priceCol <= ?"; $params[] = (float)$_GET['max_price']; }
    $sort = match ($_GET['sort'] ?? '') {
        'lowest_price' => "$priceCol ASC", 'highest_price' => "$priceCol DESC", 'most_viewed' => 'l.views_count DESC',
        default => 'l.is_featured DESC, l.created_at DESC',
    };
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20;
    $total = (int)val("SELECT COUNT(*) FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id WHERE " . implode(' AND ', $where), $params);
    $list = rows("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name
        FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
        WHERE " . implode(' AND ', $where) . " ORDER BY $sort LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    api_out(['ok' => true,
        'data' => array_map(fn($l) => api_listing_row($type, $l), $list),
        'pagination' => ['current_page' => $page, 'per_page' => $per, 'total' => $total,
                         'next_page' => $page * $per < $total ? $page + 1 : null]]);
}

// ---------- businesses ----------
if ($r0 === 'businesses' && $method === 'GET') {
    if ($r1 !== '') {
        $b = row("SELECT * FROM businesses WHERE (id = ? OR slug = ?) AND status = 'active'", [(int)$r1, $r1]);
        if (!$b) api_error('Not found.', 404);
        api_out(['ok' => true, 'data' => [
            'id' => (int)$b['id'], 'name' => $b['business_name'], 'slug' => $b['slug'],
            'type' => $b['business_type'], 'description' => $b['description'],
            'city' => $b['city'], 'subcity' => $b['subcity'],
            'verified' => $b['verification_status'] !== 'unverified',
            'verification_status' => $b['verification_status'],
            'rating' => (float)$b['rating_average'], 'rating_count' => (int)$b['rating_count'],
            'url' => url('businesses/' . $b['slug']),
        ]]);
    }
    $list = rows("SELECT * FROM businesses WHERE status = 'active' ORDER BY (verification_status != 'unverified') DESC, rating_average DESC LIMIT 50");
    api_out(['ok' => true, 'data' => array_map(fn($b) => [
        'id' => (int)$b['id'], 'name' => $b['business_name'], 'slug' => $b['slug'], 'type' => $b['business_type'],
        'city' => $b['city'], 'verified' => $b['verification_status'] !== 'unverified', 'rating' => (float)$b['rating_average'],
    ], $list)]);
}

// ---------- video feed: /api/videos/feed (§18.6) ----------
if ($r0 === 'videos' && $r1 === 'feed' && $method === 'GET') {
    $where = "v.status = 'approved' AND b.status = 'active'";
    $params = [];
    if (!empty($_GET['city'])) { $where .= " AND v.city = ?"; $params[] = $_GET['city']; }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 10;
    $list = rows("SELECT v.*, b.business_name, b.slug b_slug, b.verification_status
        FROM video_posts v JOIN businesses b ON b.id = v.business_id WHERE $where
        ORDER BY v.is_promoted DESC, v.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    $data = [];
    foreach ($list as $v) {
        $ctaUrl = url('videos/cta/' . $v['id']);
        $data[] = [
            'id' => (int)$v['id'], 'platform' => $v['platform'], 'embed_url' => $v['embed_url'],
            'business_name' => $v['business_name'], 'verified' => $v['verification_status'] !== 'unverified',
            'linked_type' => $v['linked_type'], 'linked_id' => $v['linked_id'] ? (int)$v['linked_id'] : null,
            'title' => $v['title'], 'location' => trim(($v['subcity'] ? $v['subcity'] . ', ' : '') . ($v['city'] ?? ''), ', '),
            'cta_label' => $v['cta_label'], 'cta_url' => $ctaUrl, 'views' => (int)$v['views_count'],
        ];
    }
    api_out(['ok' => true, 'data' => $data, 'pagination' => ['current_page' => $page, 'next_page' => count($list) === $per ? $page + 1 : null]]);
}

// ---------- inquiries ----------
if ($r0 === 'inquiries' && $method === 'POST') {
    $me = api_require_user();
    $type = in_array($body['listing_type'] ?? '', ['product', 'service', 'supply', 'business'], true) ? $body['listing_type'] : 'business';
    $bid = (int)($body['business_id'] ?? 0);
    $msg = trim($body['message'] ?? '');
    $phone = trim($body['phone'] ?? $me['phone'] ?? '');
    if (!$bid || $msg === '' || strlen($phone) < 9) api_error('business_id, message and phone are required.');
    if (!val("SELECT COUNT(*) FROM businesses WHERE id = ? AND status = 'active'", [$bid])) api_error('Business not found.', 404);
    // Rate limit by authenticated user, not session — session_start() gives bearer-token
    // clients a fresh, empty session on every request (no cookie jar to persist it), so the
    // session-based rate_limited() helper used by the web UI's action_inquiry.php would be a
    // no-op here. Count against the same thresholds via a DB lookup keyed on customer_id instead.
    $rateMax = (int)sys('limits.inquiry_rate_max', 5);
    $rateWindowMin = (int)sys('limits.inquiry_rate_window_min', 10);
    $recentCount = (int)val("SELECT COUNT(*) FROM inquiries WHERE customer_id = ? AND created_at > NOW() - INTERVAL ? MINUTE", [$me['user_id'], $rateWindowMin]);
    if ($recentCount >= $rateMax) api_error('Too many inquiries — please wait a few minutes.', 429);
    $lid = (int)($body['listing_id'] ?? 0);
    $trafficSource = traffic_source_for_listing($type, $lid, 'organic');
    $listingTitle = null;
    if ($lid && isset(LISTING_TABLES[$type])) {
        $t = LISTING_TABLES[$type];
        $listingTitle = val("SELECT " . listing_title_col($type) . " FROM `$t` WHERE id = ?", [$lid]);
        q("UPDATE `$t` SET inquiries_count = inquiries_count + 1 WHERE id = ?", [$lid]);
    }
    if (db_column_exists('inquiries', 'traffic_source')) {
        q("INSERT INTO inquiries (customer_id, business_id, listing_type, listing_id, listing_title, inquiry_type, name, message, phone, source, traffic_source)
           VALUES (?,?,?,?,?, 'product_inquiry', ?,?,?, 'pwa', ?)",
          [$me['user_id'], $bid, $type, $lid ?: null, $listingTitle, $me['full_name'], $msg, $phone, $trafficSource]);
    } else {
        q("INSERT INTO inquiries (customer_id, business_id, listing_type, listing_id, listing_title, inquiry_type, name, message, phone, source)
           VALUES (?,?,?,?,?, 'product_inquiry', ?,?,?, 'pwa')",
          [$me['user_id'], $bid, $type, $lid ?: null, $listingTitle, $me['full_name'], $msg, $phone]);
    }
    $inqId = (int)db()->lastInsertId();
    event_record('inquiry', [
        'user_id' => $me['user_id'],
        'listing_type' => $type,
        'listing_id' => $lid ?: null,
        'business_id' => $bid,
        'source' => $trafficSource,
        'metadata' => ['inquiry_id' => $inqId, 'legacy_source' => 'pwa'],
    ]);
    notify_business($bid, 'new_inquiry', 'New inquiry from ' . $me['full_name'], 'app/vendor/inquiries/' . $inqId, mb_substr($msg, 0, 200), true);
    api_out(['ok' => true, 'inquiry_id' => $inqId], 201);
}
if ($r0 === 'inquiries' && $method === 'GET') {
    $me = api_require_user();
    $list = rows("SELECT i.*, b.business_name FROM inquiries i JOIN businesses b ON b.id = i.business_id
                  WHERE i.customer_id = ? ORDER BY i.created_at DESC LIMIT 50", [$me['user_id']]);
    api_out(['ok' => true, 'data' => array_map(fn($i) => [
        'id' => (int)$i['id'], 'business' => $i['business_name'], 'listing_title' => $i['listing_title'],
        'message' => $i['message'], 'status' => $i['status'], 'created_at' => $i['created_at'],
    ], $list)]);
}

// ---------- categories ----------
if ($r0 === 'categories' && $method === 'GET') {
    api_out(['ok' => true, 'data' => rows("SELECT id, name, slug, type, icon FROM categories WHERE status = 'active' ORDER BY type, sort_order")]);
}

api_error('Unknown endpoint. Available: login, register, otp/send, otp/verify, me, logout, products, services, supplies, businesses, videos/feed, inquiries, categories.', 404);
