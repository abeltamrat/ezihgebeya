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
    $shell = [
        'home_url' => url(''),
        'browse_url' => url('products'),
        'cart_url' => url('cart'),
        'cart_count' => $cartEnabled ? cart_count() : 0,
        'cart_enabled' => $cartEnabled,
        'sell_url' => url($u && is_vendor($u) ? 'app/vendor/listings/product/new' : 'register'),
        'sell_label' => $u && is_vendor($u) ? 'Post listing' : 'Sell / Join',
    ];

    if (!$u) {
        return $shell + [
            'login_url' => url('login'),
            'register_url' => url('register'),
            'authenticated' => false,
        ];
    }

    $accountPath = is_admin($u) ? 'admin' : (is_vendor($u) ? 'app/vendor' : 'account');
    $biz = is_vendor($u) ? my_business($u) : null;

    return $shell + [
        'authenticated' => true,
        'account_url' => url($accountPath),
        'account_label' => is_admin($u) ? 'Admin panel' : (is_vendor($u) ? 'Dashboard' : 'My account'),
        'notifications_url' => url('notifications'),
        'notification_count' => unread_notifications((int)$u['id']),
        'logout_url' => url('logout'),
        'business_profile_url' => is_vendor($u) ? url('vendor/business') : null,
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

    // ---------- POST /vendor/listings/{type}/{id}/images (product only, multipart) ----------
    if ($sub === 'images' && $method === 'POST') {
        if ($ltype !== 'product') api_error('Only products accept multiple images.', 422);
        v1_require_owner(row("SELECT * FROM `$table` WHERE id = ?", [$lid]), 'business_id', $biz['id']);
        $uploaded = [];
        foreach (array_slice($_FILES['images']['name'] ?? [], 0, (int)sys('limits.max_images_per_listing', 6), true) as $k => $n) {
            if (!$n) continue;
            $f = ['name' => $n, 'type' => $_FILES['images']['type'][$k], 'tmp_name' => $_FILES['images']['tmp_name'][$k],
                  'error' => $_FILES['images']['error'][$k], 'size' => $_FILES['images']['size'][$k]];
            $path = upload_image($f, 'products');
            if ($path) {
                $isFirst = !val("SELECT COUNT(*) FROM product_media WHERE product_id = ?", [$lid]);
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
