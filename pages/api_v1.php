<?php
/**
 * Session-cookie JSON API for the React frontend (/api/v1/*).
 *
 * Auth reuses the *same* PHP $_SESSION the rest of the site already uses (auth() in
 * app/helpers.php) — there is deliberately no second browser authentication system.
 * A logged-in PHP page and the React app share one login. For non-browser clients
 * (mobile apps, third-party integrations) use the bearer-token API at /api/* instead
 * (pages/api.php); the two prefixes intentionally use different auth models and are
 * not merged into one endpoint surface — bearer tokens for API clients that can't hold
 * a browser cookie, session cookies for the same-origin SPA.
 *
 * JSON envelope (api_out/api_error/api_validation_error) is shared with pages/api.php,
 * defined once in app/helpers.php, so both APIs return an identical response shape.
 *
 * CSRF: every state-changing request (POST/PUT/PATCH/DELETE) is checked here, once,
 * before any route dispatches — a future endpoint cannot forget to protect itself.
 * The check reuses the same session-scoped token as the PHP page forms
 * (csrf_token()/csrf_check() in app/helpers.php). The SPA reads the token from
 * GET /api/v1/me and sends it back as the X-CSRF-Token header (or a _token field
 * on non-JSON form-encoded requests).
 *
 * Expects $apiSeg (path segments after /api/v1).
 */

/** Current session user, or null. Thin alias over the shared auth() so v1 endpoints
 * read consistently as "v1_*" without inventing a parallel identity concept. */
function v1_user(): ?array { return auth(); }

function v1_require_user(): array {
    return v1_user() ?? api_error('Authentication required. Log in first.', 401);
}

/** Require the session user to have one of the given account_type values. */
function v1_require_role(array $accountTypes): array {
    $u = v1_require_user();
    if (!in_array($u['account_type'], $accountTypes, true)) api_error('Not authorized for this account type.', 403);
    return $u;
}

/** Verify a row belongs to the current actor before returning it. 404s rather than
 * 403s on a mismatch so a non-owner can't probe whether a given id exists. */
function v1_require_owner(?array $row, string $ownerCol, $expected): array {
    if (!$row || (string)$row[$ownerCol] !== (string)$expected) api_error('Not found.', 404);
    return $row;
}

function v1_csrf_check(array $body): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_token'] ?? '');
    if (!hash_equals(csrf_token(), (string)$token)) {
        api_error('Invalid or expired CSRF token. Refresh and retry.', 419);
    }
}

/** Shared shell state for the React app.
 * This mirrors the PHP header's session-derived state so /app/* does not need a
 * second auth, notification, or cart source of truth.
 */
function v1_shell_state(?array $u): array {
    $cartEnabled = feature_enabled('cart');
    // Public client-side Firebase config only (never the server-side service account) — null
    // until admin → Settings → Notifications has a Firebase project configured, so the SPA
    // knows whether to attempt push registration at all.
    $fcmWebConfigRaw = (string)sys('notifications.fcm_web_config', '');
    $fcmWebConfig = $fcmWebConfigRaw !== '' ? (json_decode($fcmWebConfigRaw, true) ?: null) : null;
    $shell = [
        'home_url' => url(''),
        'browse_url' => url('products'),
        'cart_url' => url('cart'),
        'cart_count' => $cartEnabled ? cart_count() : 0,
        'cart_enabled' => $cartEnabled,
        'sell_url' => url($u && is_vendor($u) ? 'app/vendor/listings/product/new' : 'register'),
        'sell_label' => $u && is_vendor($u) ? 'Post listing' : 'Sell / Join',
        'fcm_web_config' => $fcmWebConfig,
        // Runtime design tokens from the System UI Optimizer — the SPA applies these to
        // :root on load so admin re-theming reaches React screens, not just PHP pages.
        'theme' => system_ui_css_vars(),
    ];

    if (!$u) {
        return $shell + [
            'login_url' => url('login'),
            'register_url' => url('register'),
            'authenticated' => false,
        ];
    }

    $accountPath = is_admin($u) ? 'app/admin/health' : (is_vendor($u) ? 'app/vendor' : 'account');
    $biz = is_vendor($u) ? my_business($u) : null;

    return $shell + [
        'authenticated' => true,
        'account_url' => url($accountPath),
        'account_label' => is_admin($u) ? 'Admin panel' : (is_vendor($u) ? 'Dashboard' : 'My account'),
        'notifications_url' => url('app/account/notifications'),
        'notification_count' => unread_notifications((int)$u['id']),
        'logout_url' => url('logout'),
        'business_profile_url' => is_vendor($u) ? url('app/vendor/business') : null,
        'public_business_url' => $biz && !empty($biz['slug']) ? url('businesses/' . $biz['slug']) : null,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$r0 = $apiSeg[0] ?? '';
$body = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_POST;

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    v1_csrf_check($body);
}

// ---------- session bootstrap: GET /api/v1/me ----------
// The SPA calls this once on load to learn whether a session exists and to fetch the
// CSRF token it must echo back on every mutation for the rest of the page lifetime.
if ($r0 === 'me' && $method === 'GET') {
    $u = v1_user();
    if (!$u) api_out(['ok' => true, 'authenticated' => false, 'csrf_token' => csrf_token(), 'shell' => v1_shell_state(null)]);
    api_out(['ok' => true, 'authenticated' => true, 'csrf_token' => csrf_token(), 'user' => [
        'id' => (int)$u['id'], 'name' => $u['full_name'], 'phone' => $u['phone'],
        'email' => $u['email'], 'account_type' => $u['account_type'],
        'phone_verified' => (bool)$u['phone_verified_at'],
    ], 'shell' => v1_shell_state($u)]);
}

// ---------- account profile/settings ----------
if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'settings') {
    $u = v1_require_user();
    $notificationCategories = function_exists('notification_categories') ? notification_categories() : [
        'inquiries' => 'Inquiries and chat replies',
        'orders' => 'Orders and delivery updates',
        'reviews' => 'Reviews and ratings',
        'promotions' => 'Promotion/subscription reminders',
        'support' => 'Support ticket updates',
    ];
    $hasMarketingPrefs = db_column_exists('users', 'marketing_sms_opt_in')
        && db_column_exists('users', 'marketing_email_opt_in')
        && db_column_exists('users', 'marketing_push_opt_in');
    $hasNotificationPrefs = db_table_exists('user_notification_preferences');

    $settingsState = function () use ($u, $notificationCategories, $hasMarketingPrefs, $hasNotificationPrefs): array {
        $freshUser = row("SELECT * FROM users WHERE id = ?", [$u['id']]) ?: $u;
        $marketing = $hasMarketingPrefs
            ? row("SELECT marketing_sms_opt_in, marketing_email_opt_in, marketing_push_opt_in, marketing_updated_at FROM users WHERE id = ?", [$u['id']])
            : ['marketing_sms_opt_in' => 1, 'marketing_email_opt_in' => 1, 'marketing_push_opt_in' => 1, 'marketing_updated_at' => null];
        $categoryPrefs = array_fill_keys(array_keys($notificationCategories), true);
        if ($hasNotificationPrefs) {
            foreach (rows("SELECT category, enabled FROM user_notification_preferences WHERE user_id = ?", [$u['id']]) as $pref) {
                if (array_key_exists($pref['category'], $categoryPrefs)) $categoryPrefs[$pref['category']] = (bool)$pref['enabled'];
            }
        }
        return [
            'profile' => [
                'id' => (int)$freshUser['id'],
                'name' => $freshUser['full_name'],
                'phone' => $freshUser['phone'],
                'email' => $freshUser['email'],
                'account_type' => $freshUser['account_type'],
                'phone_verified' => (bool)$freshUser['phone_verified_at'],
                'created_at' => $freshUser['created_at'],
            ],
            'capabilities' => [
                'marketing_preferences' => $hasMarketingPrefs,
                'notification_preferences' => $hasNotificationPrefs,
            ],
            'marketing' => [
                'sms' => (bool)$marketing['marketing_sms_opt_in'],
                'email' => (bool)$marketing['marketing_email_opt_in'],
                'push' => (bool)$marketing['marketing_push_opt_in'],
                'updated_at' => $marketing['marketing_updated_at'],
            ],
            'categories' => array_map(fn($key, $label) => [
                'key' => $key,
                'label' => $label,
                'enabled' => (bool)$categoryPrefs[$key],
            ], array_keys($notificationCategories), $notificationCategories),
            'php_settings_url' => url('account/settings'),
            'verify_phone_url' => url('verify'),
        ];
    };

    if ($method === 'GET') api_out(['ok' => true] + $settingsState());

    if ($method === 'POST') {
        if (!$hasMarketingPrefs && !$hasNotificationPrefs) api_error('Notification preference tables are not installed yet. Run the latest database upgrade first.', 409);
        $marketing = (array)($body['marketing'] ?? []);
        if ($hasMarketingPrefs) {
            q("UPDATE users SET marketing_sms_opt_in = ?, marketing_email_opt_in = ?, marketing_push_opt_in = ?, marketing_updated_at = NOW() WHERE id = ?", [
                !empty($marketing['sms']) ? 1 : 0,
                !empty($marketing['email']) ? 1 : 0,
                !empty($marketing['push']) ? 1 : 0,
                $u['id'],
            ]);
        }
        if ($hasNotificationPrefs) {
            $postedCategories = (array)($body['categories'] ?? []);
            foreach ($notificationCategories as $category => $label) {
                q("INSERT INTO user_notification_preferences (user_id, category, enabled) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()", [
                    $u['id'],
                    $category,
                    !empty($postedCategories[$category]) ? 1 : 0,
                ]);
            }
        }
        api_out(['ok' => true] + $settingsState());
    }

    api_error('Unknown endpoint.', 404);
}

// Data export and account deletion — ported from pages/account_settings.php's PHP form,
// which stays live as-is; this must produce byte-for-byte the same export shape and the
// same anonymization behavior, not a reimplementation, since both surfaces write to the
// same account. High-risk/destructive, so the same discipline applies here as there:
// password re-entry + typed confirmation for deletion, no shortcuts for a JSON client.
if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'export' && $method === 'GET') {
    $u = v1_require_user();
    $hasMarketingPrefs = db_column_exists('users', 'marketing_sms_opt_in')
        && db_column_exists('users', 'marketing_email_opt_in')
        && db_column_exists('users', 'marketing_push_opt_in');
    $hasNotificationPrefs = db_table_exists('user_notification_preferences');
    $notificationCategories = function_exists('notification_categories') ? notification_categories() : [
        'inquiries' => 'Inquiries and chat replies', 'orders' => 'Orders and delivery updates',
        'reviews' => 'Reviews and ratings', 'promotions' => 'Promotion/subscription reminders',
        'support' => 'Support ticket updates',
    ];
    $profile = [
        'id' => (int)$u['id'], 'full_name' => $u['full_name'], 'phone' => $u['phone'],
        'email' => $u['email'], 'account_type' => $u['account_type'], 'created_at' => $u['created_at'],
    ];
    if ($hasMarketingPrefs) {
        $marketing = row("SELECT marketing_sms_opt_in, marketing_email_opt_in, marketing_push_opt_in, marketing_updated_at FROM users WHERE id = ?", [$u['id']]);
        $profile['marketing_preferences'] = [
            'sms_opt_in' => (bool)$marketing['marketing_sms_opt_in'], 'email_opt_in' => (bool)$marketing['marketing_email_opt_in'],
            'push_opt_in' => (bool)$marketing['marketing_push_opt_in'], 'updated_at' => $marketing['marketing_updated_at'],
        ];
    }
    if ($hasNotificationPrefs) {
        $categoryPrefs = array_fill_keys(array_keys($notificationCategories), true);
        foreach (rows("SELECT category, enabled FROM user_notification_preferences WHERE user_id = ?", [$u['id']]) as $pref) {
            if (array_key_exists($pref['category'], $categoryPrefs)) $categoryPrefs[$pref['category']] = (bool)$pref['enabled'];
        }
        $profile['notification_preferences'] = $categoryPrefs;
    }
    $export = [
        'exported_at' => date('c'),
        'profile' => $profile,
        'orders' => rows("SELECT order_number, business_id, status, delivery_option, delivery_address, city, subcity,
            phone, total, payment_method, created_at FROM orders WHERE customer_id = ? ORDER BY created_at", [$u['id']]),
        'inquiries' => rows("SELECT business_id, listing_type, listing_title, message, phone, status, created_at
            FROM inquiries WHERE customer_id = ? ORDER BY created_at", [$u['id']]),
        'reviews' => rows("SELECT business_id, listing_type, rating, title, comment, status, created_at
            FROM reviews WHERE reviewer_id = ? ORDER BY created_at", [$u['id']]),
        'favorites' => rows("SELECT product_id, created_at FROM favorites WHERE user_id = ? ORDER BY created_at", [$u['id']]),
        'notifications' => rows("SELECT type, title, body, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at", [$u['id']]),
    ];
    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="ezihgebeya-my-data-' . date('Ymd') . '.json"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'delete' && $method === 'POST') {
    $u = v1_require_user();
    if (!password_verify((string)($body['password'] ?? ''), $u['password'])) {
        api_error('Incorrect password. Enter your current password to confirm deletion.', 422, ['fields' => ['password' => ['Incorrect password.']]]);
    }
    if (($body['confirm'] ?? '') !== 'DELETE') {
        api_error('Type DELETE to confirm.', 422, ['fields' => ['confirm' => ['Type DELETE to confirm.']]]);
    }
    if (is_admin($u)) {
        api_error('Admin accounts cannot self-delete — ask another super admin to revoke your access first.', 403);
    }
    // Same anonymization as the PHP form: orders keep their stored phone/address/name as-is
    // (financial/delivery records with a legitimate retention need), everything else that
    // captured identity gets scrubbed.
    q("UPDATE users SET full_name = 'Deleted user', phone = NULL, email = NULL,
       password = ?, status = 'deleted' WHERE id = ?", [password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $u['id']]);
    q("UPDATE inquiries SET name = 'Deleted user', phone = NULL WHERE customer_id = ?", [$u['id']]);
    q("DELETE FROM api_tokens WHERE user_id = ?", [$u['id']]);
    remembered_login_revoke_user((int)$u['id']);
    remembered_login_clear_cookie();
    session_unset();
    session_regenerate_id(true);
    api_out(['ok' => true]);
}

if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'favorites') {
    $u = v1_require_user();
    $pid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $favoriteOut = function (array $item): array {
        $img = listing_image('product', $item);
        $price = $item['discount_price'] > 0 ? money($item['discount_price']) : money($item['price']);
        $oldPrice = $item['discount_price'] > 0 && $item['price'] > 0 ? money($item['price']) : '';
        return [
            'id' => (int)$item['id'],
            'title' => $item['title'],
            'slug' => $item['slug'],
            'url' => listing_url('product', $item),
            'image_url' => $img,
            'price' => $price,
            'old_price' => $oldPrice,
            'city' => $item['city'],
            'subcity' => $item['subcity'],
            'category_name' => $item['c_name'],
            'business_name' => $item['b_name'],
            'business_verification' => $item['b_verification'],
            'saved_at' => $item['saved_at'],
        ];
    };

    if ($method === 'GET' && $pid === 0) {
        $list = rows("SELECT l.*, f.created_at saved_at, b.business_name b_name, b.verification_status b_verification, c.name c_name
            FROM favorites f
            JOIN products l ON l.id = f.product_id AND l.status = 'active'
            JOIN businesses b ON b.id = l.business_id
            JOIN categories c ON c.id = l.category_id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC", [$u['id']]);
        api_out(['ok' => true, 'data' => array_map($favoriteOut, $list)]);
    }

    if ($method === 'POST' && $pid !== 0) {
        $p = row("SELECT id, business_id, category_id FROM products WHERE id = ? AND status = 'active'", [$pid]);
        if (!$p) api_error('Product not found.', 404);
        if (!val("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND product_id = ?", [$u['id'], $pid])) {
            q("INSERT INTO favorites (user_id, product_id) VALUES (?,?)", [$u['id'], $pid]);
            q("UPDATE products SET favorites_count = favorites_count + 1 WHERE id = ?", [$pid]);
            event_record('favorite', [
                'user_id' => $u['id'],
                'listing_type' => 'product',
                'listing_id' => $pid,
                'business_id' => $p['business_id'] ?? null,
                'category_id' => $p['category_id'] ?? null,
                'source' => traffic_source_for_listing('product', $pid),
            ]);
        }
        api_out(['ok' => true, 'saved' => true]);
    }

    if ($method === 'DELETE' && $pid !== 0) {
        if (val("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND product_id = ?", [$u['id'], $pid])) {
            q("DELETE FROM favorites WHERE user_id = ? AND product_id = ?", [$u['id'], $pid]);
            q("UPDATE products SET favorites_count = GREATEST(favorites_count - 1, 0) WHERE id = ?", [$pid]);
        }
        api_out(['ok' => true, 'saved' => false]);
    }

    api_error('Unknown endpoint.', 404);
}

if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'inquiries') {
    $u = v1_require_user();
    $iid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $sub = $iid !== 0 ? ($apiSeg[3] ?? '') : '';
    $inquiryOut = fn(array $i): array => [
        'id' => (int)$i['id'],
        'business_name' => $i['business_name'],
        'business_slug' => $i['b_slug'],
        'listing_type' => $i['listing_type'],
        'listing_id' => $i['listing_id'] !== null ? (int)$i['listing_id'] : null,
        'listing_title' => $i['listing_title'],
        'inquiry_type' => $i['inquiry_type'],
        'message' => $i['message'],
        'phone' => $i['phone'],
        'preferred_contact_method' => $i['preferred_contact_method'],
        'status' => $i['status'],
        'created_at' => $i['created_at'],
        'message_count' => (int)($i['message_count'] ?? 0),
    ];

    if ($method === 'GET' && $iid === 0) {
        $list = rows("SELECT i.*, b.business_name, b.slug b_slug, COALESCE(mc.message_count, 0) message_count
            FROM inquiries i
            JOIN businesses b ON b.id = i.business_id
            LEFT JOIN (SELECT inquiry_id, COUNT(*) message_count FROM inquiry_messages GROUP BY inquiry_id) mc ON mc.inquiry_id = i.id
            WHERE i.customer_id = ?
            ORDER BY i.created_at DESC
            LIMIT 100", [$u['id']]);
        api_out(['ok' => true, 'data' => array_map($inquiryOut, $list)]);
    }

    if ($iid !== 0) {
        $inq = row("SELECT i.*, b.business_name, b.slug b_slug, b.user_id owner_id, b.phone b_phone
            FROM inquiries i JOIN businesses b ON b.id = i.business_id
            WHERE i.id = ? AND i.customer_id = ?", [$iid, $u['id']]);
        if (!$inq) api_error('Inquiry not found.', 404);

        if ($method === 'GET' && $sub === '') {
            q("UPDATE inquiry_messages SET read_at = NOW() WHERE inquiry_id = ? AND sender_id != ? AND read_at IS NULL", [$iid, $u['id']]);
            $messages = rows("SELECT m.*, users.full_name FROM inquiry_messages m JOIN users ON users.id = m.sender_id WHERE m.inquiry_id = ? ORDER BY m.created_at", [$iid]);
            api_out(['ok' => true, 'data' => $inquiryOut($inq), 'messages' => array_map(fn($m) => [
                'id' => (int)$m['id'],
                'sender_id' => (int)$m['sender_id'],
                'sender_name' => $m['full_name'],
                'body' => $m['body'],
                'read_at' => $m['read_at'],
                'created_at' => $m['created_at'],
                'mine' => (int)$m['sender_id'] === (int)$u['id'],
            ], $messages)]);
        }

        if ($method === 'POST' && $sub === 'messages') {
            $text = trim((string)($body['body'] ?? ''));
            if ($text === '') api_validation_error(['body' => 'Write a message first.']);
            q("INSERT INTO inquiry_messages (inquiry_id, sender_id, body) VALUES (?,?,?)", [$iid, $u['id'], mb_substr($text, 0, 3000)]);
            notify((int)$inq['owner_id'], 'new_inquiry',
                ($u['full_name'] ?: 'Customer') . ' sent a message on inquiry #' . $iid, 'inquiries/' . $iid, mb_substr($text, 0, 200));
            api_out(['ok' => true], 201);
        }
    }

    api_error('Unknown endpoint.', 404);
}

if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'notifications') {
    $u = v1_require_user();
    $nid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $sub = $nid !== 0 ? ($apiSeg[3] ?? '') : ($apiSeg[2] ?? '');
    $notificationOut = fn(array $n): array => [
        'id' => (int)$n['id'],
        'type' => $n['type'],
        'title' => $n['title'],
        'body' => $n['body'],
        'url' => $n['url'] ? url($n['url']) : null,
        'read_at' => $n['read_at'],
        'created_at' => $n['created_at'],
        'unread' => !$n['read_at'],
    ];

    if ($method === 'GET') {
        $list = rows("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100", [$u['id']]);
        api_out(['ok' => true, 'unread_count' => unread_notifications((int)$u['id']), 'data' => array_map($notificationOut, $list)]);
    }

    if ($method === 'POST' && $sub === 'read-all') {
        q("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL", [$u['id']]);
        api_out(['ok' => true, 'unread_count' => 0]);
    }

    if ($method === 'POST' && $nid !== 0 && $sub === 'read') {
        q("UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?", [$nid, $u['id']]);
        api_out(['ok' => true, 'unread_count' => unread_notifications((int)$u['id'])]);
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- push (Firebase Cloud Messaging device-token registration) ----------
if ($r0 === 'push' && ($apiSeg[1] ?? '') === 'subscribe' && $method === 'POST') {
    $u = v1_require_user();
    $token = trim((string)($body['fcm_token'] ?? ''));
    if ($token === '' || mb_strlen($token) > 255) api_validation_error(['fcm_token' => ['Invalid device token.']]);
    // One row per token globally (a token belongs to one browser/device at a time); re-registering
    // under a different user (e.g. shared device, different login) reassigns rather than duplicates.
    q("INSERT INTO push_subscriptions (user_id, fcm_token) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_used_at = NOW()", [$u['id'], $token]);
    api_out(['ok' => true]);
}

if ($r0 === 'push' && ($apiSeg[1] ?? '') === 'unsubscribe' && $method === 'POST') {
    $u = v1_require_user();
    $token = trim((string)($body['fcm_token'] ?? ''));
    if ($token !== '') q("DELETE FROM push_subscriptions WHERE fcm_token = ? AND user_id = ?", [$token, $u['id']]);
    api_out(['ok' => true]);
}

// ================== Cart, checkout, and customer orders (React pilot) ==================
// Mirrors pages/cart.php / pages/checkout.php / pages/account_orders.php exactly — same
// session cart ($_SESSION['cart']), same cart_resolve()/cart_add()/cart_set() helpers, same
// order-placement transaction and payment-proof upload path — so the PHP pages and this API
// stay two views over one source of truth rather than a second reimplementation that can drift.

/** Serialize cart_resolve()'s grouped-by-business shape for JSON. */
function v1_cart_out(): array {
    $groups = array_values(cart_resolve());
    return [
        'ok' => true,
        'enabled' => feature_enabled('cart'),
        'groups' => $groups,
        'grand_total' => array_sum(array_column($groups, 'subtotal')),
        'cart_count' => cart_count(),
    ];
}

if ($r0 === 'cart') {
    if (!feature_enabled('cart')) api_error('Cart is not available.', 404);

    if ($method === 'GET' && count($apiSeg) === 1) {
        api_out(v1_cart_out());
    }

    if ($method === 'POST' && count($apiSeg) === 1) {
        $do = $body['do'] ?? 'add';
        $type = (string)($body['listing_type'] ?? '');
        $id = (int)($body['listing_id'] ?? 0);
        $qty = (float)($body['qty'] ?? 1);

        if (!in_array($type, ['product', 'supply'], true) || !$id) {
            api_error('Invalid listing.', 422);
        }

        if ($do === 'add') {
            $t = LISTING_TABLES[$type];
            if (!val("SELECT COUNT(*) FROM `$t` WHERE id = ? AND status = 'active'", [$id])) {
                api_error('This listing is no longer available.', 404);
            }
            cart_add($type, $id, max(1, $qty));
            $postedSource = (string)($body['traffic_source'] ?? '');
            $src = in_array($postedSource, ['organic', 'promoted', 'video_feed', 'ad'], true) ? $postedSource : traffic_source_for_listing($type, $id);
            $_SESSION['cart_source'][$type . ':' . $id] = $src;
            event_record('cart_add', [
                'listing_type' => $type, 'listing_id' => $id, 'source' => $src,
                'metadata' => ['qty' => max(1, $qty)],
            ]);
        } elseif ($do === 'update') {
            cart_set($type, $id, $qty);
        } elseif ($do === 'remove') {
            cart_set($type, $id, 0);
            unset($_SESSION['cart_source'][$type . ':' . $id]);
        } else {
            api_error('Unknown cart action.', 422);
        }

        api_out(v1_cart_out());
    }

    api_error('Unknown endpoint.', 404);
}

if ($r0 === 'checkout') {
    if (!feature_enabled('cart')) api_error('Cart is not available.', 404);
    $u = v1_require_user();

    if ($method === 'GET' && count($apiSeg) === 1) {
        $groups = array_values(cart_resolve());
        if (!$groups) api_error('Your cart is empty.', 409);
        api_out(['ok' => true,
            'groups' => $groups,
            'grand_total' => array_sum(array_column($groups, 'subtotal')),
            'phone' => $u['phone'],
            'cities' => array_keys(CITIES), 'subcities' => CITIES,
            'payment_methods' => payment_methods(),
            'payment_instructions' => payment_instructions(),
        ]);
    }

    if ($method === 'POST' && count($apiSeg) === 1) {
        $groups = cart_resolve();
        if (!$groups) api_error('Your cart is empty.', 409);

        $delivery = ($body['delivery_option'] ?? '') === 'delivery' ? 'delivery' : 'pickup';
        $address = trim((string)($body['delivery_address'] ?? ''));
        $city = (string)($body['city'] ?? '');
        $subcity = trim((string)($body['subcity'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));
        $note = trim((string)($body['note'] ?? ''));
        $enabledMethods = payment_methods();
        $method_ = array_key_exists($body['payment_method'] ?? '', $enabledMethods) ? $body['payment_method'] : array_key_first($enabledMethods);

        $fields = [];
        if (strlen($phone) < 9) $fields['phone'] = ['Phone number required.'];
        if ($delivery === 'delivery' && ($address === '' || !isset(CITIES[$city]))) $fields['delivery_address'] = ['Delivery address and city required.'];
        if ($fields) api_validation_error($fields);

        $orderNums = [];
        try {
            db()->beginTransaction();
            foreach ($groups as $g) {
                $num = order_number();
                $trafficSource = 'organic';
                foreach ($g['items'] as $it) {
                    $itemSource = $_SESSION['cart_source'][$it['type'] . ':' . $it['id']] ?? traffic_source_for_listing($it['type'], (int)$it['id']);
                    if ($itemSource === 'ad') { $trafficSource = 'ad'; break; }
                    if ($itemSource === 'promoted') $trafficSource = 'promoted';
                }
                if (db_column_exists('orders', 'traffic_source')) {
                    q("INSERT INTO orders (order_number, customer_id, business_id, status, delivery_option, delivery_address, city, subcity, phone, note, subtotal, total, payment_method, traffic_source)
                       VALUES (?,?,?, 'pending', ?,?,?,?,?,?,?,?,?,?)",
                      [$num, $u['id'], $g['business_id'], $delivery, $address ?: null, $city ?: null, $subcity ?: null,
                       $phone, $note ?: null, $g['subtotal'], $g['subtotal'], $method_, $trafficSource]);
                } else {
                    q("INSERT INTO orders (order_number, customer_id, business_id, status, delivery_option, delivery_address, city, subcity, phone, note, subtotal, total, payment_method)
                       VALUES (?,?,?, 'pending', ?,?,?,?,?,?,?,?,?)",
                      [$num, $u['id'], $g['business_id'], $delivery, $address ?: null, $city ?: null, $subcity ?: null,
                       $phone, $note ?: null, $g['subtotal'], $g['subtotal'], $method_]);
                }
                $oid = (int)db()->lastInsertId();
                foreach ($g['items'] as $it) {
                    q("INSERT INTO order_items (order_id, listing_type, listing_id, title, unit_price, quantity, line_total)
                       VALUES (?,?,?,?,?,?,?)", [$oid, $it['type'], $it['id'], $it['title'], $it['price'], $it['qty'], $it['line']]);
                }
                event_record('order', [
                    'user_id' => $u['id'], 'listing_type' => 'order', 'listing_id' => $oid,
                    'business_id' => (int)$g['business_id'], 'source' => $trafficSource,
                    'city' => $city ?: null, 'subcity' => $subcity ?: null,
                    'metadata' => ['order_number' => $num, 'subtotal' => (float)$g['subtotal'], 'items' => array_map(fn($it) => ['type' => $it['type'], 'id' => (int)$it['id'], 'qty' => (float)$it['qty']], $g['items'])],
                ]);
                $orderNums[] = $num;
                notify_business((int)$g['business_id'], 'order_created',
                    'New order ' . $num . ' — ' . money($g['subtotal']), 'vendor/orders', '', true);
            }
            db()->commit();
            unset($_SESSION['cart']);
            unset($_SESSION['cart_source']);
            api_out(['ok' => true, 'order_numbers' => $orderNums, 'requires_proof' => $method_ !== 'cash_on_delivery']);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            api_error('We could not place your order. Please try again.', 500);
        }
    }

    api_error('Unknown endpoint.', 404);
}

if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'orders') {
    $u = v1_require_user();
    $oid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $sub = $apiSeg[3] ?? '';

    $orderOut = function (array $o) use ($u): array {
        $items = rows("SELECT * FROM order_items WHERE order_id = ?", [$o['id']]);
        $pays = rows("SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC", [$o['id']]);
        $hasLivePayment = (bool)array_filter($pays, fn($p) => $p['status'] !== 'rejected');
        return [
            'id' => (int)$o['id'],
            'order_number' => $o['order_number'],
            'business_name' => $o['business_name'],
            'business_phone' => $o['b_phone'],
            'status' => $o['status'],
            'delivery_option' => $o['delivery_option'],
            'payment_method' => $o['payment_method'],
            'created_at' => $o['created_at'],
            'subtotal' => (float)$o['subtotal'],
            'total' => (float)$o['total'],
            'items' => array_map(fn($it) => [
                'title' => $it['title'], 'unit_price' => (float)$it['unit_price'],
                'quantity' => (float)$it['quantity'], 'line_total' => (float)$it['line_total'],
            ], $items),
            'payments' => array_map(fn($p) => [
                'id' => (int)$p['id'], 'amount' => (float)$p['amount'], 'payment_method' => $p['payment_method'],
                'reference_number' => $p['reference_number'], 'status' => $p['status'], 'created_at' => $p['created_at'],
            ], $pays),
            'can_submit_payment' => $o['payment_method'] !== 'cash_on_delivery' && !$hasLivePayment && !in_array($o['status'], ['cancelled', 'completed'], true),
            'can_cancel' => in_array($o['status'], ['pending', 'confirmed'], true),
        ];
    };

    if ($method === 'GET' && $oid === 0) {
        $orders = rows("SELECT o.*, b.business_name, b.phone b_phone FROM orders o JOIN businesses b ON b.id = o.business_id
            WHERE o.customer_id = ? ORDER BY o.created_at DESC", [$u['id']]);
        api_out(['ok' => true, 'data' => array_map($orderOut, $orders)]);
    }

    if ($oid !== 0) {
        $order = row("SELECT o.*, b.business_name, b.phone b_phone FROM orders o JOIN businesses b ON b.id = o.business_id
            WHERE o.id = ?", [$oid]);
        v1_require_owner($order, 'customer_id', $u['id']);

        if ($method === 'POST' && $sub === 'cancel') {
            if (!in_array($order['status'], ['pending', 'confirmed'], true)) api_error('This order can no longer be cancelled.', 409);
            q("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$oid]);
            $order['status'] = 'cancelled';
            api_out(['ok' => true, 'order' => $orderOut($order)]);
        }

        if ($method === 'POST' && $sub === 'pay') {
            $ref = trim((string)($body['reference_number'] ?? ''));
            $methods = payment_methods(false);
            $method_ = array_key_exists($body['payment_method'] ?? '', $methods) ? $body['payment_method'] : array_key_first($methods);
            $proof = upload_image($_FILES['proof_image'] ?? [], 'payments', true);
            // Same server-side duplicate guard as account_orders.php: the client only hides the
            // form once a pending/confirmed payment exists, which a second direct POST bypasses.
            $existing = val("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status IN ('pending','confirmed')", [$oid]);
            if ($existing) api_error('A payment for this order is already submitted or confirmed.', 409);
            if ($ref === '' && !$proof) api_validation_error(['reference_number' => ['Add a transaction reference or a proof screenshot.']]);
            q("INSERT INTO payments (payer_id, business_id, order_id, payment_type, amount, payment_method, reference_number, proof_image)
               VALUES (?,?,?, 'order_payment', ?,?,?,?)",
              [$u['id'], $order['business_id'], $oid, $order['total'], $method_, $ref ?: null, $proof]);
            $fresh = row("SELECT o.*, b.business_name, b.phone b_phone FROM orders o JOIN businesses b ON b.id = o.business_id WHERE o.id = ?", [$oid]);
            api_out(['ok' => true, 'order' => $orderOut($fresh)]);
        }
    }

    api_error('Unknown endpoint.', 404);
}

if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'reviews') {
    $u = v1_require_user();
    $reviewOut = fn(array $r): array => [
        'id' => (int)$r['id'],
        'business_id' => (int)$r['business_id'],
        'business_name' => $r['business_name'],
        'listing_type' => $r['listing_type'],
        'listing_id' => $r['listing_id'] !== null ? (int)$r['listing_id'] : null,
        'rating' => (int)$r['rating'],
        'title' => $r['title'],
        'comment' => $r['comment'],
        'is_verified_purchase' => (bool)$r['is_verified_purchase'],
        'status' => $r['status'],
        'vendor_reply' => $r['vendor_reply'] ?? null,
        'vendor_replied_at' => $r['vendor_replied_at'] ?? null,
        'created_at' => $r['created_at'],
    ];

    if ($method === 'GET') {
        $list = rows("SELECT r.*, b.business_name FROM reviews r JOIN businesses b ON b.id = r.business_id
            WHERE r.reviewer_id = ? ORDER BY r.created_at DESC LIMIT 100", [$u['id']]);
        api_out(['ok' => true, 'data' => array_map($reviewOut, $list)]);
    }

    if ($method === 'POST') {
        $type = (string)($body['listing_type'] ?? '');
        $lid = (int)($body['listing_id'] ?? 0) ?: null;
        $bid = (int)($body['business_id'] ?? 0);
        $rating = max(1, min(5, (int)($body['rating'] ?? 0)));
        $comment = trim((string)($body['comment'] ?? ''));
        $title = trim((string)($body['title'] ?? ''));

        $errors = [];
        if (!in_array($type, ['product', 'service', 'supply', 'business'], true) || !$bid || $comment === '') {
            $errors[] = 'Review could not be submitted.';
        }
        if ($bid && val("SELECT COUNT(*) FROM businesses WHERE id = ? AND user_id = ?", [$bid, $u['id']])) {
            $errors[] = "You can't review your own business.";
        }
        $reviewRateMax = (int)sys('limits.review_rate_max', 5);
        $reviewRateWindow = (int)sys('limits.review_rate_window_min', 10) * 60;
        if (rate_limited('review', $reviewRateMax, $reviewRateWindow)) $errors[] = 'Too many reviews — please wait a few minutes.';
        if (!$errors && val("SELECT COUNT(*) FROM reviews WHERE reviewer_id = ? AND business_id = ? AND listing_type = ? AND (listing_id <=> ?)", [$u['id'], $bid, $type, $lid])) {
            $errors[] = 'You already reviewed this.';
        }
        if ($errors) api_validation_error(['_' => $errors]);

        $orderId = val("SELECT id FROM orders WHERE customer_id = ? AND business_id = ? AND status IN ('delivered','completed')
            ORDER BY created_at DESC LIMIT 1", [$u['id'], $bid]);
        $rStatus = sys('moderation.auto_approve_reviews') ? 'approved' : 'pending';
        try {
            q("INSERT INTO reviews (reviewer_id, business_id, listing_type, listing_id, order_id, rating, title, comment, images, is_verified_purchase, status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)",
              [$u['id'], $bid, $type, $lid, $orderId ?: null, $rating, $title ?: null, $comment, null, $orderId ? 1 : 0, $rStatus]);
            $rid = (int)db()->lastInsertId();
            if ($rStatus === 'approved') {
                $agg = row("SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE business_id = ? AND status = 'approved'", [$bid]);
                q("UPDATE businesses SET rating_average = ?, rating_count = ? WHERE id = ?", [round($agg['a'], 2), $agg['c'], $bid]);
                notify_business($bid, 'review_received', 'You received a new ' . $rating . '★ review', 'vendor/reviews');
            }
            $row = row("SELECT r.*, b.business_name FROM reviews r JOIN businesses b ON b.id = r.business_id WHERE r.id = ?", [$rid]);
            api_out(['ok' => true, 'data' => $reviewOut($row)], 201);
        } catch (Throwable $e) {
            api_validation_error(['_' => ['You already reviewed this.']]);
        }
    }

    api_error('Unknown endpoint.', 404);
}

if ($r0 === 'account' && ($apiSeg[1] ?? '') === 'reports') {
    $u = v1_require_user();
    $reportOut = fn(array $r): array => [
        'id' => (int)$r['id'],
        'reported_type' => $r['reported_type'],
        'reported_id' => (int)$r['reported_id'],
        'reason' => $r['reason'],
        'description' => $r['description'],
        'status' => $r['status'],
        'admin_note' => $r['admin_note'],
        'created_at' => $r['created_at'],
    ];

    if ($method === 'GET') {
        $list = rows("SELECT * FROM reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 100", [$u['id']]);
        api_out(['ok' => true, 'data' => array_map($reportOut, $list)]);
    }

    if ($method === 'POST') {
        $type = (string)($body['reported_type'] ?? '');
        $rid = (int)($body['reported_id'] ?? 0);
        $reason = trim((string)($body['reason'] ?? ''));
        $desc = trim((string)($body['description'] ?? ''));
        [$row, $errors] = create_report((int)$u['id'], $type, $rid, $reason, $desc);
        if ($errors) api_validation_error(['_' => $errors]);
        api_out(['ok' => true, 'data' => $reportOut($row)], 201);
    }

    api_error('Unknown endpoint.', 404);
}

// ================== React admin capability layer ==================
// Adds new admin views without replacing the existing PHP admin surface.
if ($r0 === 'admin') {
    v1_require_role(['admin', 'super_admin']);

    if (($apiSeg[1] ?? '') === 'health' && $method === 'GET') {
        $commerce30 = row("SELECT
                COUNT(*) orders_count,
                COALESCE(SUM(total),0) gmv,
                COALESCE(AVG(total),0) aov,
                SUM(status IN ('delivered','completed')) completed_orders,
                SUM(status IN ('pending','confirmed','deposit_paid','processing','ready_for_delivery','out_for_delivery')) active_orders
            FROM orders
            WHERE created_at > NOW() - INTERVAL 30 DAY
              AND status NOT IN ('cancelled','refunded','disputed')") ?: [];
        $platformRevenue = row("SELECT
                COALESCE(SUM(CASE WHEN payment_type != 'order_payment' THEN amount ELSE 0 END),0) total,
                COALESCE(SUM(CASE WHEN promotion_id IS NOT NULL OR payment_type = 'featured_listing_payment' THEN amount ELSE 0 END),0) promotions,
                COALESCE(SUM(CASE WHEN subscription_id IS NOT NULL OR payment_type = 'subscription_payment' THEN amount ELSE 0 END),0) subscriptions
            FROM payments
            WHERE status = 'confirmed' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')") ?: [];
        $paymentBacklog = row("SELECT COUNT(*) pending_count, COALESCE(SUM(amount),0) pending_value FROM payments WHERE status = 'pending'") ?: [];

        $supplyTotals = row("SELECT
                COUNT(*) active_vendors,
                COALESCE(SUM(active_listings),0) active_listings,
                COALESCE(AVG(active_listings),0) avg_listings
            FROM (
                SELECT b.id,
                       (SELECT COUNT(*) FROM products p WHERE p.business_id = b.id AND p.status = 'active')
                     + (SELECT COUNT(*) FROM services s WHERE s.business_id = b.id AND s.status = 'active')
                     + (SELECT COUNT(*) FROM supplies sp WHERE sp.business_id = b.id AND sp.status = 'active') active_listings
                FROM businesses b
                WHERE b.status = 'active'
            ) x") ?: [];
        $newVendors = (int)val("SELECT COUNT(*) FROM businesses WHERE created_at > NOW() - INTERVAL 30 DAY");
        $activatedNew = (int)val("SELECT COUNT(*) FROM businesses b
            WHERE b.created_at > NOW() - INTERVAL 30 DAY
              AND EXISTS (
                  SELECT 1 FROM (
                      SELECT business_id, created_at FROM products
                      UNION ALL SELECT business_id, created_at FROM services
                      UNION ALL SELECT business_id, created_at FROM supplies
                  ) l
                  WHERE l.business_id = b.id AND l.created_at <= b.created_at + INTERVAL 7 DAY
              )");

        $firstInquiryRows = rows("SELECT TIMESTAMPDIFF(HOUR, listings.created_at, first_inquiry.first_at) hrs
            FROM (
                SELECT 'product' listing_type, id, created_at FROM products WHERE status IN ('active','sold_out','expired')
                UNION ALL SELECT 'service' listing_type, id, created_at FROM services WHERE status IN ('active','sold_out','expired')
                UNION ALL SELECT 'supply' listing_type, id, created_at FROM supplies WHERE status IN ('active','sold_out','expired','out_of_stock')
            ) listings
            JOIN (
                SELECT listing_type, listing_id, MIN(created_at) first_at
                FROM inquiries
                WHERE listing_id IS NOT NULL AND listing_type IN ('product','service','supply')
                GROUP BY listing_type, listing_id
            ) first_inquiry ON first_inquiry.listing_type = listings.listing_type AND first_inquiry.listing_id = listings.id
            WHERE first_inquiry.first_at >= listings.created_at");
        $liquidityHours = array_values(array_filter(array_map(fn($r) => $r['hrs'] === null ? null : (float)$r['hrs'], $firstInquiryRows), fn($v) => $v !== null));
        $stale = row("SELECT COUNT(*) total, SUM(CASE WHEN views_count = 0 AND inquiries_count = 0 THEN 1 ELSE 0 END) zero_traction
            FROM (
                SELECT views_count, inquiries_count FROM products WHERE status = 'active' AND created_at <= NOW() - INTERVAL 14 DAY
                UNION ALL SELECT views_count, inquiries_count FROM services WHERE status = 'active' AND created_at <= NOW() - INTERVAL 14 DAY
                UNION ALL SELECT views_count, inquiries_count FROM supplies WHERE status = 'active' AND created_at <= NOW() - INTERVAL 14 DAY
            ) old_listings") ?: ['total' => 0, 'zero_traction' => 0];

        $topSearches = db_table_exists('events') ? rows(
            "SELECT LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')))) q,
                    COUNT(*) searches,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.zero_results')) = 'true' THEN 1 ELSE 0 END) zeroes
             FROM events
             WHERE event_type = 'search' AND created_at > NOW() - INTERVAL 30 DAY
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')) IS NOT NULL
             GROUP BY LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query'))))
             ORDER BY searches DESC LIMIT 8"
        ) : [];
        $zeroSearches = db_table_exists('events') ? rows(
            "SELECT LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query')))) q,
                    COUNT(*) zeroes,
                    MAX(created_at) last_seen
             FROM events
             WHERE event_type = 'search' AND created_at > NOW() - INTERVAL 30 DAY
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.zero_results')) = 'true'
             GROUP BY LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query'))))
             ORDER BY zeroes DESC, last_seen DESC LIMIT 8"
        ) : [];

        $reportStats = row("SELECT
                COUNT(*) total_30d,
                SUM(created_at > NOW() - INTERVAL 7 DAY) total_7d,
                SUM(status = 'open') open_now,
                SUM(status = 'reviewing') reviewing_now,
                SUM(status IN ('resolved','dismissed')) closed_30d
            FROM reports
            WHERE created_at > NOW() - INTERVAL 30 DAY OR status IN ('open','reviewing')") ?: [];
        $suspicious = [
            'underpriced' => (int)val("SELECT COUNT(*) FROM products p
                JOIN (SELECT category_id, AVG(price) avg_price FROM products WHERE status='active' AND price > 0 GROUP BY category_id HAVING COUNT(*) >= 3) avg_t
                  ON avg_t.category_id = p.category_id
                WHERE p.status = 'active' AND p.price > 0 AND p.price < avg_t.avg_price * 0.1"),
            'listing_floods' => (int)val("SELECT COUNT(*) FROM (
                SELECT b.id FROM businesses b JOIN products p ON p.business_id = b.id
                WHERE b.created_at > NOW() - INTERVAL 7 DAY GROUP BY b.id HAVING COUNT(p.id) > 10
            ) t"),
            'report_clusters' => (int)val("SELECT COUNT(*) FROM (
                SELECT reported_type, reported_id FROM reports WHERE status IN ('open','reviewing')
                GROUP BY reported_type, reported_id HAVING COUNT(*) >= 3
            ) t"),
            'duplicate_titles' => (int)val("SELECT COUNT(*) FROM (
                SELECT title FROM products WHERE status='active' GROUP BY title HAVING COUNT(*) >= 4
            ) t"),
        ];

        api_out(['ok' => true,
            'commerce' => [
                'gmv_30d_formatted' => money($commerce30['gmv'] ?? 0) ?: '0 ETB',
                'orders_30d' => (int)($commerce30['orders_count'] ?? 0),
                'aov_30d_formatted' => money($commerce30['aov'] ?? 0) ?: '0 ETB',
                'active_orders_30d' => (int)($commerce30['active_orders'] ?? 0),
                'completed_orders_30d' => (int)($commerce30['completed_orders'] ?? 0),
                'platform_revenue_mtd_formatted' => money($platformRevenue['total'] ?? 0) ?: '0 ETB',
                'promotion_revenue_mtd_formatted' => money($platformRevenue['promotions'] ?? 0) ?: '0 ETB',
                'subscription_revenue_mtd_formatted' => money($platformRevenue['subscriptions'] ?? 0) ?: '0 ETB',
                'payment_backlog_formatted' => money($paymentBacklog['pending_value'] ?? 0) ?: '0 ETB',
                'payment_backlog_count' => (int)($paymentBacklog['pending_count'] ?? 0),
            ],
            'supply' => [
                'active_vendors' => (int)($supplyTotals['active_vendors'] ?? 0),
                'active_listings' => (int)($supplyTotals['active_listings'] ?? 0),
                'avg_listings_per_vendor' => round((float)($supplyTotals['avg_listings'] ?? 0), 1),
                'new_vendors_30d' => $newVendors,
                'activated_new_vendors_30d' => $activatedNew,
                'activation_rate_30d' => $newVendors ? round($activatedNew / $newVendors * 100, 1) : null,
            ],
            'liquidity' => [
                'median_first_inquiry_hours' => median($liquidityHours),
                'zero_traction_older_listings' => (int)($stale['zero_traction'] ?? 0),
                'older_active_listings' => (int)($stale['total'] ?? 0),
                'zero_traction_share' => (int)($stale['total'] ?? 0) ? round((int)$stale['zero_traction'] / (int)$stale['total'] * 100, 1) : null,
            ],
            'demand' => [
                'top_searches' => array_map(fn($r) => ['query' => $r['q'], 'searches' => (int)$r['searches'], 'zeroes' => (int)$r['zeroes']], $topSearches),
                'zero_searches' => array_map(fn($r) => ['query' => $r['q'], 'zeroes' => (int)$r['zeroes'], 'last_seen' => $r['last_seen']], $zeroSearches),
            ],
            'trust' => [
                'reports_30d' => (int)($reportStats['total_30d'] ?? 0),
                'reports_7d' => (int)($reportStats['total_7d'] ?? 0),
                'open_reports' => (int)($reportStats['open_now'] ?? 0) + (int)($reportStats['reviewing_now'] ?? 0),
                'closed_reports_30d' => (int)($reportStats['closed_30d'] ?? 0),
                'suspicious_flags' => array_sum($suspicious),
                'suspicious_breakdown' => $suspicious,
            ],
        ]);
    }

    if (($apiSeg[1] ?? '') === 'monetization' && $method === 'GET') {
        $pendingPayments = rows("SELECT p.*, b.business_name, s.plan subscription_plan, pr.promotion_type
            FROM payments p
            LEFT JOIN businesses b ON b.id = p.business_id
            LEFT JOIN subscriptions s ON s.id = p.subscription_id
            LEFT JOIN promotions pr ON pr.id = p.promotion_id
            WHERE p.status = 'pending' AND p.payment_type != 'order_payment'
            ORDER BY p.created_at DESC LIMIT 25");
        $topPinStats = row("SELECT
                COUNT(*) total,
                SUM(status = 'pending') pending,
                SUM(status = 'active') active,
                SUM(status = 'scheduled') scheduled,
                COALESCE(SUM(CASE WHEN status IN ('active','scheduled','completed') THEN budget ELSE 0 END),0) value
            FROM promotions WHERE promotion_type = 'top_pin'") ?: [];
        $boostStats = row("SELECT
                COUNT(*) total,
                SUM(status = 'pending') pending,
                SUM(status = 'active') active,
                SUM(status = 'expired') expired
            FROM subscriptions WHERE type = 'boost'") ?: [];
        $revenue30 = row("SELECT
                COALESCE(SUM(CASE WHEN promotion_id IS NOT NULL OR payment_type = 'featured_listing_payment' THEN amount ELSE 0 END),0) promotions,
                COALESCE(SUM(CASE WHEN subscription_id IS NOT NULL OR payment_type = 'subscription_payment' THEN amount ELSE 0 END),0) subscriptions
            FROM payments
            WHERE status = 'confirmed' AND created_at > NOW() - INTERVAL 30 DAY") ?: [];

        api_out(['ok' => true,
            'top_pin_packages' => TOP_PIN_PACKAGES,
            'boost_tiers' => BOOST_TIERS,
            'top_pin_stats' => [
                'total' => (int)($topPinStats['total'] ?? 0),
                'pending' => (int)($topPinStats['pending'] ?? 0),
                'active' => (int)($topPinStats['active'] ?? 0),
                'scheduled' => (int)($topPinStats['scheduled'] ?? 0),
                'value_formatted' => money($topPinStats['value'] ?? 0) ?: '0 ETB',
            ],
            'boost_stats' => [
                'total' => (int)($boostStats['total'] ?? 0),
                'pending' => (int)($boostStats['pending'] ?? 0),
                'active' => (int)($boostStats['active'] ?? 0),
                'expired' => (int)($boostStats['expired'] ?? 0),
            ],
            'revenue_30d' => [
                'top_pin_formatted' => money($revenue30['promotions'] ?? 0) ?: '0 ETB',
                'boost_formatted' => money($revenue30['subscriptions'] ?? 0) ?: '0 ETB',
            ],
            'pending_payments' => array_map(fn($p) => [
                'id' => (int)$p['id'],
                'business_name' => $p['business_name'],
                'payment_type' => $p['payment_type'],
                'promotion_type' => $p['promotion_type'],
                'subscription_plan' => $p['subscription_plan'],
                'amount' => (float)$p['amount'],
                'amount_formatted' => money($p['amount']) ?: '0 ETB',
                'payment_method' => $p['payment_method'],
                'reference_number' => $p['reference_number'],
                'created_at' => $p['created_at'],
            ], $pendingPayments),
            'php_admin_url' => url('admin/payments'),
        ]);
    }

    api_error('Unknown endpoint.', 404);
}

// ================== Phase 2 React pilot: vendor dashboard + listing management ==================
// Everything below is the stateful pilot vertical (PLAN.md Phase 2). Ownership is enforced by
// scoping every query to the session vendor's own business_id — never trusting a client-supplied
// business id — matching "never trust ownership values supplied by React" from the Decision.

if (str_starts_with($r0, 'vendor')) {
    $u = v1_require_role(VENDOR_TYPES);
    $biz = my_business($u);
}

/** Serialize a listing row for the API, decoding its attributes JSON and normalizing the
 * title/price fields that differ by listing type into one predictable shape. */
function v1_listing_out(string $type, array $l): array {
    $publicBase = ['product' => 'products', 'service' => 'services', 'supply' => 'supplies'][$type];
    return [
        'id' => (int)$l['id'],
        'type' => $type,
        'title' => $l[listing_title_col($type)],
        'slug' => $l['slug'],
        'public_url' => url($publicBase . '/' . $l['slug']),
        'category_id' => (int)$l['category_id'],
        'category_name' => $l['c_name'] ?? null,
        'description' => $l['description'],
        'city' => $l['city'],
        'subcity' => $l['subcity'],
        'status' => $l['status'],
        'is_featured' => (bool)$l['is_featured'],
        'views' => (int)$l['views_count'],
        'inquiries' => (int)$l['inquiries_count'],
        'attributes' => decode_attributes($l['attributes'] ?? null),
        'created_at' => $l['created_at'],
        'updated_at' => $l['updated_at'],
        'price' => $type === 'product' ? $l['price'] : ($type === 'service' ? $l['starting_price'] : $l['price_per_unit']),
        'discount_price' => $type === 'product' ? $l['discount_price'] : null,
        // type-specific fields the edit form needs, harmless to omit for the list view
        'product_type' => $l['product_type'] ?? null, 'condition_type' => $l['condition_type'] ?? null,
        'is_negotiable' => isset($l['is_negotiable']) ? (bool)$l['is_negotiable'] : null,
        'stock_quantity' => $l['stock_quantity'] ?? null, 'material' => $l['material'] ?? null,
        'brand' => $l['brand'] ?? null, 'color' => $l['color'] ?? null, 'dimensions' => $l['dimensions'] ?? null,
        'warranty' => $l['warranty'] ?? null, 'delivery_available' => isset($l['delivery_available']) ? (bool)$l['delivery_available'] : null,
        'installation_available' => isset($l['installation_available']) ? (bool)$l['installation_available'] : null,
        'customization_available' => isset($l['customization_available']) ? (bool)$l['customization_available'] : null,
        'experience_years' => $l['experience_years'] ?? null, 'price_type' => $l['price_type'] ?? null,
        'grade' => $l['grade'] ?? null, 'size' => $l['size'] ?? null, 'thickness' => $l['thickness'] ?? null,
        'unit_of_measurement' => $l['unit_of_measurement'] ?? null, 'bulk_price' => $l['bulk_price'] ?? null,
        'minimum_order_quantity' => $l['minimum_order_quantity'] ?? null,
        'images' => $type === 'product'
            ? array_map(fn($m) => ['id' => (int)$m['id'], 'url' => img_url($m['file_url']), 'is_primary' => (bool)$m['is_primary']],
                rows("SELECT id, file_url, is_primary FROM product_media WHERE product_id = ? AND media_type = 'image' ORDER BY is_primary DESC, id", [$l['id']]))
            : ($l['image'] ? [['id' => 0, 'url' => img_url($l['image']), 'is_primary' => true]] : []),
    ];
}

// ---------- GET /vendor/dashboard ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'dashboard' && $method === 'GET') {
    if (!$biz) api_out(['ok' => true, 'business' => null]);
    $stats = [
        'products' => (int)val("SELECT COUNT(*) FROM products WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
        'services' => (int)val("SELECT COUNT(*) FROM services WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
        'supplies' => (int)val("SELECT COUNT(*) FROM supplies WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
        'videos' => (int)val("SELECT COUNT(*) FROM video_posts WHERE business_id = ? AND status != 'deleted'", [$biz['id']]),
        'new_orders' => (int)val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND status = 'pending'", [$biz['id']]),
        'new_inquiries' => (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND status = 'new'", [$biz['id']]),
        'total_inquiries' => (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ?", [$biz['id']]),
        'product_views' => (int)val("SELECT COALESCE(SUM(views_count),0) FROM products WHERE business_id = ?", [$biz['id']]),
    ];
    $recent = rows("SELECT id, name, phone, status, listing_title, listing_type, message, created_at
        FROM inquiries WHERE business_id = ? ORDER BY created_at DESC LIMIT 5", [$biz['id']]);
    api_out(['ok' => true, 'business' => [
        'id' => (int)$biz['id'], 'name' => $biz['business_name'], 'status' => $biz['status'],
        'city' => $biz['city'], 'plan' => current_plan((int)$biz['id']),
    ], 'stats' => $stats, 'recent_inquiries' => $recent]);
}

// ---------- GET|POST /vendor/business ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'business') {
    $businessOut = function (?array $b): ?array {
        if (!$b) return null;
        return [
            'id' => (int)$b['id'],
            'business_name' => $b['business_name'],
            'slug' => $b['slug'],
            'business_type' => $b['business_type'],
            'description' => $b['description'],
            'phone' => $b['phone'],
            'city' => $b['city'],
            'subcity' => $b['subcity'],
            'area_name' => $b['area_name'],
            'address' => $b['address'],
            'tin_number' => $b['tin_number'],
            'license_number' => $b['license_number'],
            'logo_url' => $b['logo'] ? img_url($b['logo']) : null,
            'cover_image_url' => $b['cover_image'] ? img_url($b['cover_image']) : null,
            'verification_status' => $b['verification_status'],
            'status' => $b['status'],
            'public_url' => $b['slug'] ? url('businesses/' . $b['slug']) : null,
        ];
    };

    if ($method === 'GET') {
        api_out([
            'ok' => true,
            'business' => $businessOut($biz),
            'cities' => array_keys(CITIES),
            'subcities' => CITIES,
            'default_phone' => $u['phone'],
        ]);
    }

    if ($method === 'POST') {
        $src = $_POST ?: $body;
        $name = trim((string)($src['business_name'] ?? ''));
        $desc = trim((string)($src['description'] ?? ''));
        $phone = trim((string)($src['phone'] ?? ''));
        $city = (string)($src['city'] ?? '');
        $subcity = trim((string)($src['subcity'] ?? ''));
        $area = trim((string)($src['area_name'] ?? ''));
        $address = trim((string)($src['address'] ?? ''));
        $tin = trim((string)($src['tin_number'] ?? ''));
        $license = trim((string)($src['license_number'] ?? ''));

        $fields = [];
        if (mb_strlen($name) < 2) $fields['business_name'] = ['Business name required.'];
        if (!isset(CITIES[$city])) $fields['city'] = ['Select a city.'];
        if ($subcity !== '' && !in_array($subcity, CITIES[$city] ?? [], true)) $fields['subcity'] = ['Select a valid sub-city.'];
        if (strlen($phone) < 9) $fields['phone'] = ['Business phone required.'];
        if ($fields) api_validation_error($fields);

        $logo = upload_image($_FILES['logo'] ?? [], 'businesses') ?? ($biz['logo'] ?? null);
        $cover = upload_image($_FILES['cover_image'] ?? [], 'businesses') ?? ($biz['cover_image'] ?? null);

        if ($biz) {
            q("UPDATE businesses SET business_name=?, description=?, phone=?, city=?, subcity=?, area_name=?, address=?, tin_number=?, license_number=?, logo=?, cover_image=?,
               status = IF(status = 'rejected', 'pending', status) WHERE id=?",
              [$name, $desc, $phone, $city, $subcity, $area, $address, $tin, $license, $logo, $cover, $biz['id']]);
            $fresh = row("SELECT * FROM businesses WHERE id = ?", [$biz['id']]);
            api_out(['ok' => true, 'business' => $businessOut($fresh)]);
        }

        $bizStatus = sys('moderation.auto_approve_businesses') ? 'active' : 'pending';
        q("INSERT INTO businesses (user_id, business_name, slug, business_type, description, phone, city, subcity, area_name, address, tin_number, license_number, logo, cover_image, verification_status, status)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'phone_verified', ?)",
          [$u['id'], $name, slugify($name, 'businesses'), $u['account_type'] === 'customer' ? 'mixed' : $u['account_type'],
           $desc, $phone, $city, $subcity, $area, $address, $tin, $license, $logo, $cover, $bizStatus]);
        $fresh = row("SELECT * FROM businesses WHERE id = ?", [db()->lastInsertId()]);
        api_out(['ok' => true, 'business' => $businessOut($fresh)], 201);
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- vendor inquiries + message threads ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'inquiries') {
    if (!$biz) api_error('Create your business profile first.', 409);
    $iid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $sub = $iid !== 0 ? ($apiSeg[3] ?? '') : '';
    $statuses = ['new', 'seen', 'responded', 'negotiating', 'converted', 'closed', 'spam'];

    $inquiryOut = function (array $i): array {
        return [
            'id' => (int)$i['id'],
            'customer_id' => $i['customer_id'] !== null ? (int)$i['customer_id'] : null,
            'listing_type' => $i['listing_type'],
            'listing_id' => $i['listing_id'] !== null ? (int)$i['listing_id'] : null,
            'listing_title' => $i['listing_title'],
            'inquiry_type' => $i['inquiry_type'],
            'name' => $i['name'],
            'message' => $i['message'],
            'phone' => $i['phone'],
            'preferred_contact_method' => $i['preferred_contact_method'],
            'source' => $i['source'],
            'status' => $i['status'],
            'created_at' => $i['created_at'],
            'updated_at' => $i['updated_at'],
            'message_count' => (int)($i['message_count'] ?? 0),
        ];
    };

    if ($method === 'GET' && $iid === 0) {
        $filter = $_GET['status'] ?? '';
        $where = 'i.business_id = ?';
        $params = [$biz['id']];
        if ($filter !== '' && in_array($filter, $statuses, true)) {
            $where .= ' AND i.status = ?';
            $params[] = $filter;
        }
        $list = rows("SELECT i.*, COALESCE(mc.message_count, 0) message_count
            FROM inquiries i
            LEFT JOIN (
                SELECT inquiry_id, COUNT(*) message_count
                FROM inquiry_messages
                GROUP BY inquiry_id
            ) mc ON mc.inquiry_id = i.id
            WHERE $where
            ORDER BY i.created_at DESC
            LIMIT 200", $params);
        api_out(['ok' => true, 'data' => array_map($inquiryOut, $list), 'statuses' => $statuses]);
    }

    if ($iid !== 0) {
        $inq = v1_require_owner(row("SELECT * FROM inquiries WHERE id = ?", [$iid]), 'business_id', $biz['id']);

        if ($method === 'GET' && $sub === '') {
            if ($inq['status'] === 'new') q("UPDATE inquiries SET status = 'seen' WHERE id = ?", [$iid]);
            q("UPDATE inquiry_messages SET read_at = NOW() WHERE inquiry_id = ? AND sender_id != ? AND read_at IS NULL", [$iid, $u['id']]);
            $messages = rows("SELECT m.*, u.full_name
                FROM inquiry_messages m
                JOIN users u ON u.id = m.sender_id
                WHERE m.inquiry_id = ?
                ORDER BY m.created_at", [$iid]);
            api_out(['ok' => true, 'data' => $inquiryOut($inq), 'messages' => array_map(fn($m) => [
                'id' => (int)$m['id'],
                'sender_id' => (int)$m['sender_id'],
                'sender_name' => $m['full_name'],
                'body' => $m['body'],
                'read_at' => $m['read_at'],
                'created_at' => $m['created_at'],
                'mine' => (int)$m['sender_id'] === (int)$u['id'],
            ], $messages)]);
        }

        if ($method === 'POST' && $sub === 'status') {
            $status = (string)($body['status'] ?? '');
            if (!in_array($status, $statuses, true)) api_validation_error(['status' => 'Invalid status.']);
            q("UPDATE inquiries SET status = ? WHERE id = ? AND business_id = ?", [$status, $iid, $biz['id']]);
            api_out(['ok' => true]);
        }

        if ($method === 'POST' && $sub === 'messages') {
            $text = trim((string)($body['body'] ?? ''));
            if ($text === '') api_validation_error(['body' => 'Write a message first.']);
            q("INSERT INTO inquiry_messages (inquiry_id, sender_id, body) VALUES (?,?,?)", [$iid, $u['id'], mb_substr($text, 0, 3000)]);
            if (in_array($inq['status'], ['new', 'seen'], true)) q("UPDATE inquiries SET status = 'responded' WHERE id = ?", [$iid]);
            notify($inq['customer_id'] ? (int)$inq['customer_id'] : null, 'vendor_reply',
                $biz['business_name'] . ' replied to your inquiry', 'inquiries/' . $iid, mb_substr($text, 0, 200), true);
            api_out(['ok' => true], 201);
        }
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- vendor orders + manual payment confirmation ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'orders') {
    if (!$biz) api_error('Create your business profile first.', 409);
    $oid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $sub = $oid !== 0 ? ($apiSeg[3] ?? '') : '';
    $flow = ['pending', 'confirmed', 'deposit_paid', 'processing', 'ready_for_delivery', 'out_for_delivery', 'delivered', 'completed', 'cancelled', 'refunded', 'disputed'];

    $orderOut = function (array $o): array {
        $items = rows("SELECT id, listing_type, listing_id, title, unit_price, quantity, line_total FROM order_items WHERE order_id = ?", [$o['id']]);
        $payments = rows("SELECT id, payer_id, amount, currency, payment_method, reference_number, proof_image, status, created_at
            FROM payments WHERE order_id = ? ORDER BY created_at DESC", [$o['id']]);
        return [
            'id' => (int)$o['id'],
            'order_number' => $o['order_number'],
            'customer' => $o['customer'],
            'customer_id' => (int)$o['customer_id'],
            'status' => $o['status'],
            'delivery_option' => $o['delivery_option'],
            'delivery_address' => $o['delivery_address'],
            'city' => $o['city'],
            'subcity' => $o['subcity'],
            'phone' => $o['phone'],
            'note' => $o['note'],
            'subtotal' => $o['subtotal'],
            'delivery_fee' => $o['delivery_fee'],
            'total' => $o['total'],
            'total_formatted' => money($o['total']),
            'payment_method' => $o['payment_method'],
            'created_at' => $o['created_at'],
            'items' => array_map(fn($it) => [
                'id' => (int)$it['id'],
                'listing_type' => $it['listing_type'],
                'listing_id' => (int)$it['listing_id'],
                'title' => $it['title'],
                'unit_price' => $it['unit_price'],
                'quantity' => $it['quantity'],
                'line_total' => $it['line_total'],
                'line_total_formatted' => money($it['line_total']),
            ], $items),
            'payments' => array_map(fn($p) => [
                'id' => (int)$p['id'],
                'payer_id' => (int)$p['payer_id'],
                'amount' => $p['amount'],
                'amount_formatted' => money($p['amount']),
                'currency' => $p['currency'],
                'payment_method' => $p['payment_method'],
                'reference_number' => $p['reference_number'],
                'proof_url' => $p['proof_image'] ? url('download/payment/' . $p['id']) : null,
                'status' => $p['status'],
                'created_at' => $p['created_at'],
            ], $payments),
        ];
    };

    if ($method === 'GET' && $oid === 0) {
        $orders = rows("SELECT o.*, u.full_name customer
            FROM orders o
            JOIN users u ON u.id = o.customer_id
            WHERE o.business_id = ?
            ORDER BY (o.status='pending') DESC, o.created_at DESC
            LIMIT 200", [$biz['id']]);
        api_out(['ok' => true, 'data' => array_map($orderOut, $orders), 'statuses' => $flow]);
    }

    if ($method === 'POST' && $oid !== 0 && $sub === 'status') {
        $status = (string)($body['status'] ?? '');
        if (!in_array($status, $flow, true)) api_validation_error(['status' => 'Invalid status.']);
        $order = v1_require_owner(row("SELECT * FROM orders WHERE id = ?", [$oid]), 'business_id', $biz['id']);
        if ($order['status'] !== $status) {
            q("UPDATE orders SET status = ? WHERE id = ?", [$status, $oid]);
            notify((int)$order['customer_id'], 'order_status_changed',
                'Order ' . $order['order_number'] . ' is now ' . str_replace('_', ' ', $status), 'account/orders', '', true);
        }
        api_out(['ok' => true]);
    }

    if ($method === 'POST' && $sub === 'payments') {
        $paymentId = ctype_digit($apiSeg[4] ?? '') ? (int)$apiSeg[4] : 0;
        $paymentAction = $apiSeg[5] ?? '';
        if ($paymentId !== 0 && $paymentAction === 'confirm') {
            $payment = row("SELECT p.* FROM payments p JOIN orders o ON o.id = p.order_id
                WHERE p.id = ? AND p.order_id = ? AND o.business_id = ?", [$paymentId, $oid, $biz['id']]);
            if (!$payment) api_error('Payment not found.', 404);
            if ($payment['status'] !== 'pending') api_error('Only pending payments can be confirmed.', 409);
            q("UPDATE payments SET status = 'confirmed', confirmed_by = ? WHERE id = ?", [$u['id'], $paymentId]);
            q("UPDATE orders SET status = 'deposit_paid' WHERE id = ? AND status IN ('pending','confirmed')", [$payment['order_id']]);
            notify((int)$payment['payer_id'], 'payment_received', 'Your payment of ' . money($payment['amount']) . ' was confirmed', 'account/orders');
            api_out(['ok' => true]);
        }
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- vendor video link management ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'videos') {
    if (!$biz) api_error('Create your business profile first.', 409);
    $vid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $sub = $vid !== 0 ? ($apiSeg[3] ?? '') : ($apiSeg[2] ?? '');

    $videoOut = fn(array $v): array => [
        'id' => (int)$v['id'],
        'platform' => $v['platform'],
        'original_url' => $v['original_url'],
        'video_id' => $v['video_id'],
        'embed_url' => $v['embed_url'],
        'title' => $v['title'],
        'linked_type' => $v['linked_type'],
        'linked_id' => $v['linked_id'] !== null ? (int)$v['linked_id'] : null,
        'cta_label' => $v['cta_label'],
        'status' => $v['status'],
        'views_count' => (int)$v['views_count'],
        'cta_clicks_count' => (int)$v['cta_clicks_count'],
        'created_at' => $v['created_at'],
    ];

    if ($method === 'GET' && $sub === 'meta' && $vid === 0) {
        $owned = [
            'product' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title']], rows("SELECT id, title FROM products WHERE business_id = ? AND status != 'deleted' ORDER BY title", [$biz['id']])),
            'service' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title']], rows("SELECT id, title FROM services WHERE business_id = ? AND status != 'deleted' ORDER BY title", [$biz['id']])),
            'supply' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title']], rows("SELECT id, name AS title FROM supplies WHERE business_id = ? AND status != 'deleted' ORDER BY name", [$biz['id']])),
        ];
        api_out(['ok' => true, 'cta_labels' => CTA_LABELS, 'owned_listings' => $owned, 'can_add_video' => can_add_video((int)$biz['id']), 'plan' => current_plan((int)$biz['id'])]);
    }

    if ($method === 'GET' && $vid === 0 && $sub === '') {
        $videos = rows("SELECT * FROM video_posts WHERE business_id = ? AND status != 'deleted' ORDER BY created_at DESC", [$biz['id']]);
        api_out(['ok' => true, 'data' => array_map($videoOut, $videos)]);
    }

    if ($method === 'POST' && $vid === 0 && $sub === '') {
        if (!can_add_video((int)$biz['id'])) {
            api_error('Video limit reached for your ' . plans()[current_plan((int)$biz['id'])]['label'] . ' plan. Upgrade to add more.', 403);
        }
        $platform = (string)($body['platform'] ?? '');
        $urlIn = trim((string)($body['original_url'] ?? ''));
        $linkedType = (string)($body['linked_type'] ?? 'business');
        $linkedId = (int)($body['linked_id'] ?? 0) ?: null;
        $cta = in_array($body['cta_label'] ?? '', CTA_LABELS, true) ? (string)$body['cta_label'] : 'Check Now';
        $title = trim((string)($body['title'] ?? ''));

        $errors = [];
        $parsed = in_array($platform, ['tiktok', 'youtube'], true) ? parse_video_url($platform, $urlIn) : null;
        if (!$parsed) $errors[] = 'Could not recognize that video link. Paste a TikTok or YouTube/Shorts URL.';
        if (!in_array($linkedType, ['product', 'service', 'supply', 'business'], true)) $errors[] = 'Invalid link target.';
        if ($linkedType !== 'business') {
            $t = LISTING_TABLES[$linkedType];
            if (!$linkedId || !val("SELECT COUNT(*) FROM `$t` WHERE id = ? AND business_id = ?", [$linkedId, $biz['id']])) {
                $errors[] = 'Select one of your own listings to link the video to.';
            }
        } else {
            $linkedId = null;
        }
        if ($errors) api_validation_error(['_' => $errors]);

        $vStatus = sys('moderation.auto_approve_videos') ? 'approved' : 'pending';
        q("INSERT INTO video_posts (business_id, user_id, platform, original_url, video_id, embed_url, title, linked_type, linked_id, cta_label, city, subcity, status)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$biz['id'], $u['id'], $platform, $urlIn, $parsed['video_id'], $parsed['embed_url'], $title ?: null,
           $linkedType, $linkedId, $cta, $biz['city'], $biz['subcity'], $vStatus]);
        $v = row("SELECT * FROM video_posts WHERE id = ?", [db()->lastInsertId()]);
        api_out(['ok' => true, 'data' => $videoOut($v)], 201);
    }

    if ($method === 'DELETE' && $vid !== 0) {
        v1_require_owner(row("SELECT * FROM video_posts WHERE id = ?", [$vid]), 'business_id', $biz['id']);
        q("UPDATE video_posts SET status = 'deleted' WHERE id = ?", [$vid]);
        api_out(['ok' => true]);
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- vendor verification requests + documents ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'verification') {
    if (!$biz) api_error('Create your business profile first.', 409);
    $docTypes = [
        'business_license' => 'Business license',
        'tin_certificate' => 'TIN certificate',
        'national_id' => 'Fayda / National ID',
        'shop_photo' => 'Shop or workshop photo',
        'portfolio' => 'Previous work / portfolio',
    ];
    $levels = [
        'document_verified' => 'Document verified — license/TIN checked',
        'location_verified' => 'Location verified — documents + physical address',
    ];

    $requestOut = function (array $r) use ($docTypes): array {
        $docs = rows("SELECT id, doc_type, file_url, created_at FROM verification_documents WHERE request_id = ? ORDER BY id", [$r['id']]);
        return [
            'id' => (int)$r['id'],
            'requested_level' => $r['requested_level'],
            'status' => $r['status'],
            'message' => $r['message'],
            'admin_note' => $r['admin_note'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'documents' => array_map(fn($d) => [
                'id' => (int)$d['id'],
                'doc_type' => $d['doc_type'],
                'label' => $docTypes[$d['doc_type']] ?? str_replace('_', ' ', $d['doc_type']),
                'url' => url('download/verification/' . $d['id']),
                'created_at' => $d['created_at'],
            ], $docs),
        ];
    };

    if ($method === 'GET') {
        $history = rows("SELECT * FROM verification_requests WHERE business_id = ? ORDER BY id DESC", [$biz['id']]);
        $open = null;
        foreach ($history as $r) {
            if (in_array($r['status'], ['pending', 'changes_requested'], true)) { $open = $requestOut($r); break; }
        }
        api_out(['ok' => true,
            'current_level' => $biz['verification_status'],
            'doc_types' => array_map(fn($key, $label) => ['key' => $key, 'label' => $label], array_keys($docTypes), $docTypes),
            'levels' => array_map(fn($key, $label) => ['key' => $key, 'label' => $label], array_keys($levels), $levels),
            'open' => $open,
            'history' => array_map($requestOut, $history),
        ]);
    }

    if ($method === 'POST') {
        $open = row("SELECT * FROM verification_requests WHERE business_id = ? AND status IN ('pending','changes_requested') ORDER BY id DESC LIMIT 1", [$biz['id']]);
        $level = array_key_exists($body['requested_level'] ?? '', $levels) ? (string)$body['requested_level'] : 'document_verified';
        $msg = trim((string)($body['message'] ?? ''));
        $do = (string)($body['do'] ?? '');

        $files = [];
        foreach ($docTypes as $key => $label) {
            $f = $_FILES['doc_' . $key] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $path = upload_image($f, 'verification', true);
                if ($path) $files[] = [$key, $path];
            }
        }

        if ($open && $do === 'update') {
            foreach ($files as [$type, $path]) {
                q("INSERT INTO verification_documents (request_id, doc_type, file_url) VALUES (?,?,?)", [$open['id'], $type, $path]);
            }
            q("UPDATE verification_requests SET status = 'pending', message = COALESCE(NULLIF(?,''), message) WHERE id = ?", [$msg, $open['id']]);
            api_out(['ok' => true, 'data' => $requestOut(row("SELECT * FROM verification_requests WHERE id = ?", [$open['id']]))]);
        }

        if ($open) api_error('A verification request is already in review.', 409);
        if (!$files) api_validation_error(['_' => ['Attach at least one document.']]);
        q("INSERT INTO verification_requests (business_id, requested_level, message) VALUES (?,?,?)", [$biz['id'], $level, $msg ?: null]);
        $reqId = (int)db()->lastInsertId();
        foreach ($files as [$type, $path]) {
            q("INSERT INTO verification_documents (request_id, doc_type, file_url) VALUES (?,?,?)", [$reqId, $type, $path]);
        }
        foreach (rows("SELECT id FROM users WHERE account_type IN ('admin','super_admin') AND status = 'active'") as $adm) {
            notify((int)$adm['id'], 'verification_request', $biz['business_name'] . ' submitted a verification request', 'admin/verification');
        }
        api_out(['ok' => true, 'data' => $requestOut(row("SELECT * FROM verification_requests WHERE id = ?", [$reqId]))], 201);
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- vendor reviews + one-time public replies ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'reviews') {
    if (!$biz) api_error('Create your business profile first.', 409);
    $rid = ctype_digit($apiSeg[2] ?? '') ? (int)$apiSeg[2] : 0;
    $sub = $rid !== 0 ? ($apiSeg[3] ?? '') : '';

    $reviewOut = fn(array $r): array => [
        'id' => (int)$r['id'],
        'reviewer_id' => (int)$r['reviewer_id'],
        'reviewer_name' => $r['full_name'],
        'listing_type' => $r['listing_type'],
        'listing_id' => $r['listing_id'] !== null ? (int)$r['listing_id'] : null,
        'rating' => (int)$r['rating'],
        'title' => $r['title'],
        'comment' => $r['comment'],
        'is_verified_purchase' => (bool)$r['is_verified_purchase'],
        'status' => $r['status'],
        'vendor_reply' => $r['vendor_reply'] ?? null,
        'vendor_replied_at' => $r['vendor_replied_at'] ?? null,
        'created_at' => $r['created_at'],
    ];

    if ($method === 'GET' && $rid === 0) {
        $list = rows("SELECT r.*, u2.full_name FROM reviews r JOIN users u2 ON u2.id = r.reviewer_id
            WHERE r.business_id = ? AND r.status IN ('approved','pending') ORDER BY r.created_at DESC LIMIT 100", [$biz['id']]);
        api_out(['ok' => true,
            'rating_average' => (float)$biz['rating_average'],
            'rating_count' => (int)$biz['rating_count'],
            'data' => array_map($reviewOut, $list),
        ]);
    }

    if ($method === 'POST' && $rid !== 0 && $sub === 'reply') {
        $reply = trim((string)($body['reply'] ?? ''));
        if ($reply === '') api_validation_error(['reply' => 'Write a reply first.']);
        $r = row("SELECT * FROM reviews WHERE id = ? AND business_id = ?", [$rid, $biz['id']]);
        if (!$r) api_error('Review not found.', 404);
        if ($r['status'] !== 'approved') api_error('Only approved reviews can receive a public vendor reply.', 422);
        if (!empty($r['vendor_reply'])) api_error('You already replied to this review.', 409);
        q("UPDATE reviews SET vendor_reply = ?, vendor_replied_at = NOW() WHERE id = ?", [mb_substr($reply, 0, 1000), $rid]);
        notify((int)$r['reviewer_id'], 'vendor_reply', $biz['business_name'] . ' replied to your review', 'businesses/' . $biz['slug']);
        api_out(['ok' => true]);
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- vendor boost & promotions (tenant monetization ladder) ----------
// TOP Pin (single-listing pin) and Boost (vendor-wide ranking subscription) purchases.
// Same request→pending-row→admin-confirms→ledger-activates flow as the legacy
// vendor_promotions.php / vendor_subscription.php pages — one source of truth
// (promotion_activate()/the generic payment_confirm activation switch in admin.php),
// this is just a second, React-native front door onto it for the new packages.
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'boost') {
    if (!$biz) api_error('Create your business profile first.', 409);
    $sub = $apiSeg[2] ?? '';

    if ($method === 'GET' && $sub === '') {
        $promos = rows("SELECT * FROM promotions WHERE business_id = ? AND promotion_type = 'top_pin' ORDER BY created_at DESC", [$biz['id']]);
        $subs = rows("SELECT * FROM subscriptions WHERE business_id = ? AND type = 'boost' ORDER BY created_at DESC", [$biz['id']]);
        $listings = [];
        foreach (['product' => ['products', 'title'], 'service' => ['services', 'title'], 'supply' => ['supplies', 'name']] as $t => [$tb, $tc]) {
            foreach (rows("SELECT id, `$tc` t, is_featured FROM `$tb` WHERE business_id = ? AND status = 'active'", [$biz['id']]) as $r) {
                $listings[] = ['type' => $t, 'id' => (int)$r['id'], 'title' => $r['t'], 'is_featured' => (bool)$r['is_featured']];
            }
        }
        api_out(['ok' => true,
            'verified' => $biz['verification_status'] !== 'unverified',
            'top_pin_packages' => top_pin_packages(),
            'boost_tiers' => boost_tiers(),
            'current_boost' => current_boost((int)$biz['id']),
            'payment_methods' => payment_methods(false),
            'payment_instructions' => payment_instructions(),
            'listings' => $listings,
            'top_pins' => array_map(fn($p) => [
                'id' => (int)$p['id'], 'listing_type' => $p['promotable_type'], 'listing_id' => (int)$p['promotable_id'],
                'budget' => (float)$p['budget'], 'status' => $p['status'], 'starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'],
            ], $promos),
            'boost_subscriptions' => array_map(fn($s) => [
                'id' => (int)$s['id'], 'plan' => $s['plan'], 'months' => (int)$s['months'], 'status' => $s['status'],
                'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'],
            ], $subs),
        ]);
    }

    if ($method === 'POST' && $sub === 'top-pin') {
        if ($biz['verification_status'] === 'unverified') api_error('Only verified businesses can buy promotions. Submit your TIN/license first.', 403);
        $pkg = (string)($body['package'] ?? '');
        $packages = top_pin_packages();
        if (!isset($packages[$pkg])) api_validation_error(['package' => ['Select a valid TOP Pin package.']]);
        $ltype = (string)($body['listing_type'] ?? '');
        $lid = (int)($body['listing_id'] ?? 0);
        if (!isset(LISTING_TABLES[$ltype]) || !val("SELECT COUNT(*) FROM `" . LISTING_TABLES[$ltype] . "` WHERE id = ? AND business_id = ? AND status = 'active'", [$lid, $biz['id']])) {
            api_validation_error(['listing_id' => ['Select one of your own active listings.']]);
        }
        $ref = trim((string)($body['reference_number'] ?? ''));
        $methods = payment_methods(false);
        $method_ = array_key_exists($body['payment_method'] ?? '', $methods) ? $body['payment_method'] : array_key_first($methods);
        $cost = $packages[$pkg]['price'];
        q("INSERT INTO promotions (business_id, promotable_type, promotable_id, promotion_type, duration_days, city, subcity, budget, status)
           VALUES (?,?,?, 'top_pin', ?,?,?,?, 'pending')",
          [$biz['id'], $ltype, $lid, $packages[$pkg]['duration_days'], $biz['city'], $biz['subcity'], $cost]);
        $pid = (int)db()->lastInsertId();
        $proof = upload_image($_FILES['proof_image'] ?? [], 'payments', true);
        if ($ref === '' && !$proof) api_validation_error(['reference_number' => ['Add a transaction reference or a proof screenshot.']]);
        q("INSERT INTO payments (payer_id, business_id, promotion_id, payment_type, amount, payment_method, reference_number, proof_image)
           VALUES (?,?,?, 'ad_payment', ?,?,?,?)", [$u['id'], $biz['id'], $pid, $cost, $method_, $ref ?: null, $proof]);
        api_out(['ok' => true, 'promotion_id' => $pid, 'amount' => $cost]);
    }

    if ($method === 'POST' && $sub === 'subscribe') {
        $tier = (string)($body['tier'] ?? '');
        $tiers = boost_tiers();
        if (!isset($tiers[$tier])) api_validation_error(['tier' => ['Select a valid Boost tier.']]);
        if (val("SELECT COUNT(*) FROM subscriptions WHERE business_id = ? AND type = 'boost' AND status = 'pending'", [$biz['id']])) {
            api_error('You already have a Boost request awaiting confirmation.', 409);
        }
        $months = max(1, min(12, (int)($body['months'] ?? 1)));
        $ref = trim((string)($body['reference_number'] ?? ''));
        $methods = payment_methods(false);
        $method_ = array_key_exists($body['payment_method'] ?? '', $methods) ? $body['payment_method'] : array_key_first($methods);
        $cost = $tiers[$tier]['price'] * $months;
        q("INSERT INTO subscriptions (business_id, type, plan, months, status) VALUES (?, 'boost', ?, ?, 'pending')", [$biz['id'], $tier, $months]);
        $sid = (int)db()->lastInsertId();
        $proof = upload_image($_FILES['proof_image'] ?? [], 'payments', true);
        if ($ref === '' && !$proof) api_validation_error(['reference_number' => ['Add a transaction reference or a proof screenshot.']]);
        q("INSERT INTO payments (payer_id, business_id, subscription_id, payment_type, amount, payment_method, reference_number, proof_image)
           VALUES (?,?,?, 'subscription_payment', ?,?,?,?)", [$u['id'], $biz['id'], $sid, $cost, $method_, $ref ?: null, $proof]);
        api_out(['ok' => true, 'subscription_id' => $sid, 'amount' => $cost]);
    }

    if ($method === 'POST' && $sub === 'cancel') {
        $kind = (string)($body['kind'] ?? '');
        $id = (int)($body['id'] ?? 0);
        if ($kind === 'top_pin') {
            $p = row("SELECT * FROM promotions WHERE id = ? AND business_id = ? AND promotion_type = 'top_pin'", [$id, $biz['id']]);
            if (!$p || !in_array($p['status'], ['pending', 'scheduled', 'active', 'paused'], true)) api_error('Not found.', 404);
            q("UPDATE promotions SET status = 'cancelled' WHERE id = ?", [$id]);
            if ($p['status'] === 'active') promotion_apply($p, false);
        } elseif ($kind === 'boost') {
            $s = row("SELECT * FROM subscriptions WHERE id = ? AND business_id = ? AND type = 'boost'", [$id, $biz['id']]);
            if (!$s || !in_array($s['status'], ['pending', 'active'], true)) api_error('Not found.', 404);
            q("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?", [$id]);
        } else {
            api_validation_error(['kind' => ['Unknown cancellation target.']]);
        }
        api_out(['ok' => true]);
    }

    api_error('Unknown endpoint.', 404);
}

// ---------- vendor analytics ----------
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'analytics' && $method === 'GET') {
    if (!$biz) api_error('Create your business profile first.', 409);
    $businessId = (int)$biz['id'];
    // Tier-gating (PLAN.md "Tenant analytics & reporting"): free vendors see the basic
    // counters only; Boost Basic unlocks money/top-lists ("Basic analytics" in BOOST_TIERS);
    // Boost Pro/Max unlock the full drill-down/lead/reviews detail ("Full analytics").
    $boostTier = current_boost($businessId);
    $analyticsLevel = $boostTier === null ? 'basic' : ($boostTier === 'boost_basic' ? 'standard' : 'full');
    $showMoney = $analyticsLevel !== 'basic';
    $showFull = $analyticsLevel === 'full';
    $eventCount = function (string $eventType, int $days = 30, ?string $listingType = null, ?int $listingId = null, ?string $source = null) use ($businessId): int {
        if (!db_table_exists('events')) return 0;
        $where = ["business_id = ?", "event_type = ?", "created_at > NOW() - INTERVAL {$days} DAY"];
        $params = [$businessId, $eventType];
        if ($listingType !== null) { $where[] = "listing_type = ?"; $params[] = $listingType; }
        if ($listingId !== null) { $where[] = "listing_id = ?"; $params[] = $listingId; }
        if ($source !== null) { $where[] = "source = ?"; $params[] = $source; }
        return (int)val("SELECT COUNT(*) FROM events WHERE " . implode(' AND ', $where), $params);
    };
    $dropoff = fn(int $from, int $to): ?int => $from > 0 ? (int)round(max(0, ($from - $to) / $from) * 100) : null;

    $orderRevenue30 = (float)val("SELECT COALESCE(SUM(total),0) FROM orders WHERE business_id = ? AND status NOT IN ('cancelled','refunded') AND created_at > NOW() - INTERVAL 30 DAY", [$businessId]);
    $orders30 = (int)val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND created_at > NOW() - INTERVAL 30 DAY", [$businessId]);
    $completedOrders30 = (int)val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND status = 'completed' AND created_at > NOW() - INTERVAL 30 DAY", [$businessId]);
    $totalInquiries = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ?", [$businessId]);
    $convertedInquiries = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND status = 'converted'", [$businessId]);
    $promoSpend30 = $promotedInquiries30 = $promotedOrders30 = 0;
    if ($showMoney) {
        $promoSpend30 = (float)val("SELECT COALESCE(SUM(COALESCE(spent, budget, 0)),0) FROM promotions WHERE business_id = ? AND created_at > NOW() - INTERVAL 30 DAY AND status IN ('active','completed','scheduled','paused')", [$businessId]);
        $promotedInquiries30 = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND traffic_source IN ('promoted','ad') AND created_at > NOW() - INTERVAL 30 DAY", [$businessId]);
        $promotedOrders30 = db_column_exists('orders', 'traffic_source')
            ? (int)val("SELECT COUNT(*) FROM orders WHERE business_id = ? AND traffic_source IN ('promoted','ad') AND created_at > NOW() - INTERVAL 30 DAY", [$businessId])
            : 0;
    }

    $totals = [
        ['label' => 'Product views', 'value' => (float)val("SELECT COALESCE(SUM(views_count),0) FROM products WHERE business_id = ?", [$businessId])],
        ['label' => 'Service views', 'value' => (float)val("SELECT COALESCE(SUM(views_count),0) FROM services WHERE business_id = ?", [$businessId])],
        ['label' => 'Supply views', 'value' => (float)val("SELECT COALESCE(SUM(views_count),0) FROM supplies WHERE business_id = ?", [$businessId])],
        ['label' => 'Video views', 'value' => (float)val("SELECT COALESCE(SUM(views_count),0) FROM video_posts WHERE business_id = ?", [$businessId])],
        ['label' => 'Video CTA clicks', 'value' => (float)val("SELECT COALESCE(SUM(cta_clicks_count),0) FROM video_posts WHERE business_id = ?", [$businessId])],
        ['label' => 'Inquiries (30d)', 'value' => (float)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND created_at > NOW() - INTERVAL 30 DAY", [$businessId])],
        ['label' => 'Orders (30d)', 'value' => (float)$orders30],
        ['label' => 'Order revenue (30d)', 'value' => $orderRevenue30, 'formatted' => money($orderRevenue30)],
        ['label' => 'Lead conversion', 'value' => $totalInquiries ? round($convertedInquiries / $totalInquiries * 100, 1) : null, 'suffix' => '%'],
    ];

    $funnelRaw = [
        'Views' => $eventCount('view', 30),
        'Favorites' => $eventCount('favorite', 30),
        'Inquiries' => $eventCount('inquiry', 30) ?: (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND created_at > NOW() - INTERVAL 30 DAY", [$businessId]),
        'Orders' => $eventCount('order', 30) ?: $orders30,
        'Completed orders' => $completedOrders30,
    ];
    $funnel = [];
    $prev = null;
    foreach ($funnelRaw as $label => $count) {
        $funnel[] = ['label' => $label, 'count' => (int)$count, 'dropoff_percent' => $prev === null ? null : $dropoff((int)$prev, (int)$count)];
        $prev = (int)$count;
    }

    // Full-tier only ("Full analytics" — Boost Pro/Max): per-listing drill-down, reviews/response
    // metrics, lead source/status breakdown, revenue-by-listing. Skipped entirely for basic/standard
    // tiers rather than computed-then-hidden, so free/Basic vendors don't pay the query cost either.
    $listings = [];
    $reviewSummary = [];
    $medianResponseMins = null;
    $revenueByListing = [];
    if ($showFull) {
    $listingRows = rows("
        SELECT * FROM (
          SELECT 'product' listing_type, id, title, status, views_count, favorites_count, inquiries_count, created_at FROM products WHERE business_id = ? AND status != 'deleted'
          UNION ALL
          SELECT 'service' listing_type, id, title, status, views_count, 0 favorites_count, inquiries_count, created_at FROM services WHERE business_id = ? AND status != 'deleted'
          UNION ALL
          SELECT 'supply' listing_type, id, name title, status, views_count, 0 favorites_count, inquiries_count, created_at FROM supplies WHERE business_id = ? AND status != 'deleted'
        ) listings
        ORDER BY views_count DESC, inquiries_count DESC
        LIMIT 25", [$businessId, $businessId, $businessId]);
    foreach ($listingRows as $l) {
        $type = $l['listing_type'];
        $id = (int)$l['id'];
        $inquiries30 = (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND listing_type = ? AND listing_id = ? AND created_at > NOW() - INTERVAL 30 DAY", [$businessId, $type, $id]);
        $ordersForListing30 = in_array($type, ['product', 'supply'], true)
            ? (int)val("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.business_id = ? AND oi.listing_type = ? AND oi.listing_id = ? AND o.created_at > NOW() - INTERVAL 30 DAY", [$businessId, $type, $id])
            : 0;
        $revenue30 = in_array($type, ['product', 'supply'], true)
            ? (float)val("SELECT COALESCE(SUM(oi.line_total),0) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.business_id = ? AND oi.listing_type = ? AND oi.listing_id = ? AND o.status NOT IN ('cancelled','refunded') AND o.created_at > NOW() - INTERVAL 30 DAY", [$businessId, $type, $id])
            : 0.0;
        $listings[] = [
            'listing_type' => $type,
            'id' => $id,
            'title' => $l['title'],
            'status' => $l['status'],
            'views30' => $eventCount('view', 30, $type, $id) ?: (int)$l['views_count'],
            'views7' => $eventCount('view', 7, $type, $id),
            'favorites30' => $type === 'product' ? ($eventCount('favorite', 30, $type, $id) ?: (int)$l['favorites_count']) : 0,
            'inquiries30' => $inquiries30,
            'inquiries7' => (int)val("SELECT COUNT(*) FROM inquiries WHERE business_id = ? AND listing_type = ? AND listing_id = ? AND created_at > NOW() - INTERVAL 7 DAY", [$businessId, $type, $id]),
            'orders30' => $ordersForListing30,
            'revenue30' => $revenue30,
            'revenue30_formatted' => money($revenue30),
        ];
    }

    $reviewSummary = row("SELECT COUNT(*) total_reviews, AVG(rating) avg_rating,
            AVG(CASE WHEN created_at > NOW() - INTERVAL 30 DAY THEN rating END) avg_rating_30d,
            SUM(created_at > NOW() - INTERVAL 30 DAY) reviews_30d
        FROM reviews WHERE business_id = ? AND status = 'approved'", [$businessId]) ?: [];
    $responseRows = db_table_exists('inquiry_messages') ? rows(
        "SELECT TIMESTAMPDIFF(MINUTE, i.created_at, MIN(m.created_at)) mins
         FROM inquiries i
         JOIN inquiry_messages m ON m.inquiry_id = i.id AND m.sender_id = ? AND m.created_at >= i.created_at
         WHERE i.business_id = ? AND i.created_at > NOW() - INTERVAL 90 DAY
         GROUP BY i.id
         HAVING mins IS NOT NULL",
        [$biz['user_id'], $businessId]
    ) : [];
    $medianResponseMins = median(array_map(fn($r) => max(0, (int)$r['mins']), $responseRows));
    }

    // Standard-tier+ ("Basic analytics" — Boost Basic and up): top lists.
    $topVideos = $topProducts = [];
    if ($showMoney) {
    $topVideos = rows("SELECT id, COALESCE(title, original_url) title, views_count, cta_clicks_count
        FROM video_posts WHERE business_id = ? AND status = 'approved' ORDER BY views_count DESC LIMIT 10", [$businessId]);
    $topProducts = rows("SELECT title, views_count, inquiries_count, favorites_count FROM products
        WHERE business_id = ? AND status = 'active' ORDER BY views_count DESC LIMIT 10", [$businessId]);
    }
    if ($showFull) {
    $revenueByListing = rows("SELECT oi.listing_type, oi.listing_id, MAX(oi.title) title, COUNT(DISTINCT o.id) orders_count, SUM(oi.line_total) revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.business_id = ? AND o.status NOT IN ('cancelled','refunded') AND o.created_at > NOW() - INTERVAL 30 DAY
        GROUP BY oi.listing_type, oi.listing_id
        ORDER BY revenue DESC
        LIMIT 10", [$businessId]);
    }

    $leadSources = $showFull ? rows("SELECT source, COUNT(*) n FROM inquiries WHERE business_id = ? GROUP BY source ORDER BY n DESC", [$businessId]) : [];
    $leadStatuses = $showFull ? rows("SELECT status, COUNT(*) n FROM inquiries WHERE business_id = ? GROUP BY status ORDER BY n DESC", [$businessId]) : [];

    api_out(['ok' => true,
        'analytics_level' => $analyticsLevel,
        'upgrade_url' => url('app/vendor/boost'),
        'totals' => $totals,
        'funnel' => $funnel,
        'money' => $showMoney ? [
            'order_revenue_30d' => $orderRevenue30,
            'order_revenue_30d_formatted' => money($orderRevenue30),
            'average_order_value_30d' => $orders30 ? $orderRevenue30 / $orders30 : 0,
            'average_order_value_30d_formatted' => money($orders30 ? $orderRevenue30 / $orders30 : 0),
            'promotion_spend_30d' => $promoSpend30,
            'promotion_spend_30d_formatted' => money($promoSpend30),
            'promoted_inquiries_30d' => $promotedInquiries30,
            'promoted_orders_30d' => $promotedOrders30,
        ] : null,
        'listings' => $listings,
        'top_products' => array_map(fn($p) => ['title' => $p['title'], 'views' => (int)$p['views_count'], 'inquiries' => (int)$p['inquiries_count'], 'favorites' => (int)$p['favorites_count']], $topProducts),
        'lead_sources' => array_map(fn($r) => ['source' => $r['source'], 'count' => (int)$r['n']], $leadSources),
        'lead_statuses' => array_map(fn($r) => ['status' => $r['status'], 'count' => (int)$r['n']], $leadStatuses),
        'revenue_by_listing' => array_map(fn($r) => ['listing_type' => $r['listing_type'], 'listing_id' => (int)$r['listing_id'], 'title' => $r['title'], 'orders_count' => (int)$r['orders_count'], 'revenue' => (float)$r['revenue'], 'revenue_formatted' => money($r['revenue'])], $revenueByListing),
        'reviews' => $showFull ? [
            'average_rating' => isset($reviewSummary['avg_rating']) ? (float)$reviewSummary['avg_rating'] : null,
            'average_rating_30d' => isset($reviewSummary['avg_rating_30d']) ? (float)$reviewSummary['avg_rating_30d'] : null,
            'reviews_30d' => (int)($reviewSummary['reviews_30d'] ?? 0),
            'median_response_minutes' => $medianResponseMins,
            'median_response_label' => response_time_label($medianResponseMins),
        ] : null,
        'top_videos' => array_map(fn($v) => ['id' => (int)$v['id'], 'title' => $v['title'], 'views' => (int)$v['views_count'], 'cta_clicks' => (int)$v['cta_clicks_count'], 'ctr_percent' => (int)$v['views_count'] > 0 ? round((int)$v['cta_clicks_count'] / (int)$v['views_count'] * 100, 1) : null], $topVideos),
    ]);
}

// All remaining vendor routes operate on a listing type; validate it once up front.
if ($r0 === 'vendor' && ($apiSeg[1] ?? '') === 'listings') {
    $ltype = $apiSeg[2] ?? '';
    if (!isset(LISTING_TABLES[$ltype])) api_error('Unknown listing type.', 404);
    if (!$biz) api_error('Create your business profile first.', 409);
    $table = LISTING_TABLES[$ltype];
    $titleCol = listing_title_col($ltype);
    // 4th segment is either a numeric listing id or the literal action "meta"; 5th segment
    // (only present alongside a numeric id) is a per-listing sub-action like "images".
    $idOrAction = $apiSeg[3] ?? '';
    $lid = ctype_digit($idOrAction) ? (int)$idOrAction : 0;
    $sub = $lid !== 0 ? ($apiSeg[4] ?? '') : $idOrAction;

    // ---------- GET /vendor/listings/{type}/meta ----------
    if ($sub === 'meta' && $lid === 0 && $method === 'GET') {
        $cats = rows("SELECT * FROM categories WHERE type = ? AND status = 'active' ORDER BY sort_order", [$ltype]);
        $attrsByCategory = [];
        foreach ($cats as $c) $attrsByCategory[$c['id']] = category_attributes((int)$c['id']);
        api_out(['ok' => true,
            'categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $cats),
            'attributes_by_category' => $attrsByCategory,
            'cities' => array_keys(CITIES), 'subcities' => CITIES,
            'can_add_listing' => can_add_listing((int)$biz['id']),
            'plan' => current_plan((int)$biz['id']),
        ]);
    }

    // ---------- GET /vendor/listings/{type} (list) | GET .../{id} (single) ----------
    if ($method === 'GET' && $lid === 0 && $sub === '') {
        $list = rows("SELECT l.*, c.name c_name FROM `$table` l JOIN categories c ON c.id = l.category_id
            WHERE l.business_id = ? AND l.status != 'deleted' ORDER BY l.created_at DESC", [$biz['id']]);
        api_out(['ok' => true, 'data' => array_map(fn($l) => v1_listing_out($ltype, $l), $list)]);
    }
    if ($method === 'GET' && $lid !== 0) {
        $l = v1_require_owner(row("SELECT l.*, c.name c_name FROM `$table` l JOIN categories c ON c.id = l.category_id WHERE l.id = ?", [$lid]), 'business_id', $biz['id']);
        api_out(['ok' => true, 'data' => v1_listing_out($ltype, $l)]);
    }

    // ---------- POST /vendor/listings/{type} (create) ----------
    if ($method === 'POST' && $sub === '') {
        if (!can_add_listing((int)$biz['id'])) {
            api_error('Listing limit reached for your ' . plans()[current_plan((int)$biz['id'])]['label'] . ' plan. Upgrade to add more.', 403);
        }
        [$newId, $errors] = v1_save_listing($ltype, $table, $titleCol, (int)$biz['id'], null, $body);
        if ($errors) api_validation_error(['_' => $errors]);
        $l = row("SELECT l.*, c.name c_name FROM `$table` l JOIN categories c ON c.id = l.category_id WHERE l.id = ?", [$newId]);
        api_out(['ok' => true, 'data' => v1_listing_out($ltype, $l)], 201);
    }

    // ---------- PUT /vendor/listings/{type}/{id} (update) ----------
    if ($method === 'PUT' && $lid !== 0) {
        $item = v1_require_owner(row("SELECT * FROM `$table` WHERE id = ?", [$lid]), 'business_id', $biz['id']);
        [, $errors] = v1_save_listing($ltype, $table, $titleCol, (int)$biz['id'], $item, $body);
        if ($errors) api_validation_error(['_' => $errors]);
        $l = row("SELECT l.*, c.name c_name FROM `$table` l JOIN categories c ON c.id = l.category_id WHERE l.id = ?", [$lid]);
        api_out(['ok' => true, 'data' => v1_listing_out($ltype, $l)]);
    }

    // ---------- DELETE /vendor/listings/{type}/{id} ----------
    if ($method === 'DELETE' && $lid !== 0) {
        v1_require_owner(row("SELECT * FROM `$table` WHERE id = ?", [$lid]), 'business_id', $biz['id']);
        q("UPDATE `$table` SET status = 'deleted' WHERE id = ?", [$lid]);
        api_out(['ok' => true]);
    }

    // ---------- product media management ----------
    if ($sub === 'images' && $lid !== 0) {
        v1_require_owner(row("SELECT * FROM `$table` WHERE id = ?", [$lid]), 'business_id', $biz['id']);

        $mediaId = ctype_digit($apiSeg[5] ?? '') ? (int)$apiSeg[5] : 0;
        $mediaAction = $apiSeg[6] ?? '';

        // Services and supplies have one legacy image column rather than product_media rows.
        if ($ltype !== 'product') {
            if ($method === 'POST' && $mediaId === 0) {
                $names = $_FILES['images']['name'] ?? [];
                $firstKey = is_array($names) ? array_key_first(array_filter($names)) : null;
                $file = null;
                if ($firstKey !== null) {
                    $file = [
                        'name' => $_FILES['images']['name'][$firstKey],
                        'type' => $_FILES['images']['type'][$firstKey],
                        'tmp_name' => $_FILES['images']['tmp_name'][$firstKey],
                        'error' => $_FILES['images']['error'][$firstKey],
                        'size' => $_FILES['images']['size'][$firstKey],
                    ];
                }
                $path = $file ? upload_image($file, $table) : null;
                if (!$path) api_error('No valid image uploaded (JPG/PNG/WEBP/GIF only).', 422);
                $old = val("SELECT image FROM `$table` WHERE id = ?", [$lid]);
                q("UPDATE `$table` SET image = ? WHERE id = ?", [$path, $lid]);
                purge_upload_file($old);
                api_out(['ok' => true, 'uploaded' => 1]);
            }

            if ($method === 'DELETE' && $mediaId === 0) {
                $old = val("SELECT image FROM `$table` WHERE id = ?", [$lid]);
                q("UPDATE `$table` SET image = NULL WHERE id = ?", [$lid]);
                purge_upload_file($old);
                api_out(['ok' => true]);
            }

            api_error('Unknown endpoint.', 404);
        }

        // DELETE /vendor/listings/product/{id}/images/{mediaId}
        if ($method === 'DELETE' && $mediaId !== 0) {
            $media = row("SELECT * FROM product_media WHERE id = ? AND product_id = ? AND media_type = 'image'", [$mediaId, $lid]);
            if (!$media) api_error('Image not found.', 404);
            q("DELETE FROM product_media WHERE id = ?", [$mediaId]);
            purge_upload_file($media['file_url'] ?? null);
            if (!val("SELECT COUNT(*) FROM product_media WHERE product_id = ? AND media_type = 'image' AND is_primary = 1", [$lid])) {
                $next = row("SELECT id FROM product_media WHERE product_id = ? AND media_type = 'image' ORDER BY sort_order, id LIMIT 1", [$lid]);
                if ($next) q("UPDATE product_media SET is_primary = 1 WHERE id = ?", [$next['id']]);
            }
            api_out(['ok' => true]);
        }

        // POST /vendor/listings/product/{id}/images/{mediaId}/primary
        if ($method === 'POST' && $mediaId !== 0 && $mediaAction === 'primary') {
            $media = row("SELECT id FROM product_media WHERE id = ? AND product_id = ? AND media_type = 'image'", [$mediaId, $lid]);
            if (!$media) api_error('Image not found.', 404);
            q("UPDATE product_media SET is_primary = 0 WHERE product_id = ? AND media_type = 'image'", [$lid]);
            q("UPDATE product_media SET is_primary = 1 WHERE id = ?", [$mediaId]);
            api_out(['ok' => true]);
        }

        // POST /vendor/listings/product/{id}/images (multipart upload)
        if ($method !== 'POST' || $mediaId !== 0) api_error('Unknown endpoint.', 404);
        $uploaded = [];
        foreach (array_slice($_FILES['images']['name'] ?? [], 0, (int)sys('limits.max_images_per_listing', 6), true) as $k => $n) {
            if (!$n) continue;
            $f = ['name' => $n, 'type' => $_FILES['images']['type'][$k], 'tmp_name' => $_FILES['images']['tmp_name'][$k],
                  'error' => $_FILES['images']['error'][$k], 'size' => $_FILES['images']['size'][$k]];
            $path = upload_image($f, 'products');
            if ($path) {
                $isFirst = !val("SELECT COUNT(*) FROM product_media WHERE product_id = ? AND media_type = 'image'", [$lid]);
                q("INSERT INTO product_media (product_id, file_url, is_primary) VALUES (?,?,?)", [$lid, $path, $isFirst ? 1 : 0]);
                $uploaded[] = $path;
            }
        }
        if (!$uploaded) api_error('No valid image uploaded (JPG/PNG/WEBP/GIF only).', 422);
        api_out(['ok' => true, 'uploaded' => count($uploaded)]);
    }

    api_error('Unknown endpoint.', 404);
}

/** Shared create/update field mapping — mirrors pages/vendor_listings.php's logic exactly so
 * the PHP page and the API stay behaviorally identical while both exist during the pilot.
 * Returns [newOrExistingId, errors]. */
function v1_save_listing(string $ltype, string $table, string $titleCol, int $bizId, ?array $item, array $body): array {
    $cats = rows("SELECT id FROM categories WHERE type = ? AND status = 'active'", [$ltype]);
    $title = trim((string)($body['title'] ?? ''));
    $catId = (int)($body['category_id'] ?? 0);
    $desc = trim((string)($body['description'] ?? ''));
    $city = (string)($body['city'] ?? '');
    $subcity = trim((string)($body['subcity'] ?? ''));

    $errors = [];
    if (mb_strlen($title) < 3) $errors[] = 'Title is required (min 3 characters).';
    if (!in_array($catId, array_column($cats, 'id'))) $errors[] = 'Select a valid category.';
    if (!isset(CITIES[$city])) $errors[] = 'Select a valid city.';

    $attrDefs = $catId ? category_attributes($catId) : [];
    // The API receives attributes as a plain {key: value} object, not the PHP form's attr[key] shape.
    [$attrValues, $attrErrors] = collect_attribute_input($attrDefs, ['attr' => array_map(
        fn($v) => is_bool($v) ? ($v ? '1' : null) : $v, (array)($body['attributes'] ?? [])
    )]);
    $errors = array_merge($errors, $attrErrors);
    if ($errors) return [null, $errors];

    $attrJson = encode_attributes($attrValues);
    $slug = $item ? $item['slug'] : slugify($title . '-' . $city, $table);
    if ($ltype === 'product') {
        $data = [$catId, $title, $desc, (string)($body['product_type'] ?? 'ready_made'), (string)($body['condition_type'] ?? 'new'),
            (float)($body['price'] ?? 0) ?: null, (float)($body['discount_price'] ?? 0) ?: null,
            !empty($body['is_negotiable']) ? 1 : 0, (int)($body['stock_quantity'] ?? 0),
            trim((string)($body['material'] ?? '')), trim((string)($body['brand'] ?? '')), trim((string)($body['color'] ?? '')),
            trim((string)($body['dimensions'] ?? '')), trim((string)($body['warranty'] ?? '')), $attrJson,
            !empty($body['delivery_available']) ? 1 : 0, !empty($body['installation_available']) ? 1 : 0,
            !empty($body['customization_available']) ? 1 : 0, $city, $subcity];
        $cols = "category_id=?, title=?, description=?, product_type=?, condition_type=?, price=?, discount_price=?, is_negotiable=?, stock_quantity=?, material=?, brand=?, color=?, dimensions=?, warranty=?, attributes=?, delivery_available=?, installation_available=?, customization_available=?, city=?, subcity=?";
    } elseif ($ltype === 'service') {
        $data = [$catId, $title, $desc, (int)($body['experience_years'] ?? 0),
            (string)($body['price_type'] ?? 'quote_required'), (float)($body['starting_price'] ?? 0) ?: null, $attrJson, $city, $subcity];
        $cols = "category_id=?, title=?, description=?, experience_years=?, price_type=?, starting_price=?, attributes=?, city=?, subcity=?";
    } else {
        $data = [$catId, $title, $desc, trim((string)($body['brand'] ?? '')), trim((string)($body['grade'] ?? '')),
            trim((string)($body['size'] ?? '')), trim((string)($body['thickness'] ?? '')), (string)($body['unit_of_measurement'] ?? 'piece'),
            (float)($body['price_per_unit'] ?? 0) ?: null, (float)($body['bulk_price'] ?? 0) ?: null,
            (float)($body['minimum_order_quantity'] ?? 1) ?: 1, (float)($body['stock_quantity'] ?? 0), $attrJson,
            !empty($body['delivery_available']) ? 1 : 0, $city, $subcity];
        $cols = "category_id=?, name=?, description=?, brand=?, grade=?, size=?, thickness=?, unit_of_measurement=?, price_per_unit=?, bulk_price=?, minimum_order_quantity=?, stock_quantity=?, attributes=?, delivery_available=?, city=?, subcity=?";
    }

    $newStatus = sys('moderation.auto_approve_listings') ? 'active' : 'pending_review';
    if ($item) {
        q("UPDATE `$table` SET $cols, status = ? WHERE id = ?", [...$data, $newStatus, $item['id']]);
        return [(int)$item['id'], []];
    }
    q("INSERT INTO `$table` SET business_id = " . (int)$bizId . ", slug = " . db()->quote($slug) . ", $cols, status = " . db()->quote($newStatus), $data);
    return [(int)db()->lastInsertId(), []];
}

api_error('Unknown endpoint.', 404);
