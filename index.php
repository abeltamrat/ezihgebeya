<?php
require __DIR__ . '/config.php';

// Secure session cookie (§ cross-cutting security checklist): HttpOnly always, Secure
// whenever the request actually arrived over HTTPS — including behind a reverse proxy
// that terminates TLS and forwards plain HTTP internally, which is common on shared
// hosting. Must run before session_start(), and needs BASE_URL from config.php first.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => (BASE_URL !== '' ? BASE_URL : '') . '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/cache.php';
require __DIR__ . '/app/helpers.php';
require __DIR__ . '/app/settings.php';
require __DIR__ . '/app/remembered_login.php';
require __DIR__ . '/app/notify.php';
require __DIR__ . '/app/ads.php';
require __DIR__ . '/app/attributes.php';
require __DIR__ . '/app/search_synonyms.php';
require __DIR__ . '/app/saved_searches.php';

// idle session timeout (§22.1.5, minutes configurable in admin → Settings)
if (!empty($_SESSION['user_id'])) {
    if (($_SESSION['last_seen'] ?? time()) < time() - (int)sys('auth.session_timeout_min', SESSION_TIMEOUT_MINUTES) * 60) {
        session_unset();
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen'] = time();
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, BASE_URL)) $path = substr($path, strlen(BASE_URL));
$path = trim($path, '/');
$seg = $path === '' ? [] : explode('/', $path);
$P = __DIR__ . '/pages/';

// React authenticated app fallback. Apache/Yegara normally handles this through
// .htaccess, but the PHP built-in server and some local proxy setups route
// /app/* into index.php instead. Serve the built Vite shell here too so direct
// refreshes like /app/vendor do not fall through to the public PHP 404 page.
if (($seg[0] ?? '') === 'app') {
    if (($seg[1] ?? '') === 'assets') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Asset not found.';
        exit;
    }
    $spa = __DIR__ . '/app/index.html';
    if (is_file($spa)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($spa);
    } else {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "React app build is missing. Run `npm run build` in frontend/ and copy frontend/dist/* into app/.";
    }
    exit;
}

user_location(); // warm session/cookie for this request so every page can read it cheaply

// maintenance mode (admin → Settings → General): admins may still log in and work
if (sys('general.maintenance_mode') && !is_admin(auth())
    && !in_array($path, ['login', 'logout'], true) && ($seg[0] ?? '') !== 'cron') {
    http_response_code(503);
    header('Retry-After: 3600');
    $pageTitle = 'Maintenance';
    include __DIR__ . '/views/layout_top.php';
    echo '<div class="container section"><div class="empty-state"><h1>🔧 ' . e(site_name()) . '</h1><p>'
        . e(sys('general.maintenance_message')) . '</p></div></div>';
    include __DIR__ . '/views/layout_bottom.php';
    exit;
}

// feature switches (admin → Settings → Features): unknown-off routes fall through to 404
$featureRoutes = [
    'videos' => ($seg[0] ?? '') === 'videos',
    'cart' => in_array($path, ['cart', 'checkout', 'account/orders'], true),
    'promotions' => $path === 'vendor/promotions',
    'subscriptions' => $path === 'vendor/subscription',
    'reviews' => $path === 'review',
    'inquiries' => $path === 'inquiry',
    'api' => ($seg[0] ?? '') === 'api',
];
foreach ($featureRoutes as $feature => $matches) {
    if ($matches && !feature_enabled($feature)) {
        if ($feature === 'api') { header('Content-Type: application/json'); http_response_code(503); exit('{"ok":false,"error":"API disabled by administrator."}'); }
        flash('This feature is currently disabled.', 'error');
        redirect('');
    }
}

// Compatibility redirects for authenticated workflows that have accepted React
// replacements. Keep these GET-only so stale POST submissions from old tabs do
// not silently turn into navigation and lose intent.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reactRedirects = [
        'cart' => 'app/cart',
        'checkout' => 'app/checkout',
        'account' => 'app/account',
        'account/orders' => 'app/account/orders',
        'account/settings' => 'app/account/settings',
        'notifications' => 'app/account/notifications',
        'vendor' => 'app/vendor',
        'vendor/business' => 'app/vendor/business',
        'vendor/videos' => 'app/vendor/videos',
        'vendor/verification' => 'app/vendor/verification',
        'vendor/reviews' => 'app/vendor/reviews',
        'vendor/inquiries' => 'app/vendor/inquiries',
        'vendor/orders' => 'app/vendor/orders',
        'vendor/promotions' => 'app/vendor/boost',
        'vendor/subscription' => 'app/vendor/boost',
        'vendor/analytics' => 'app/vendor/analytics',
    ];
    if (isset($reactRedirects[$path])) redirect($reactRedirects[$path]);
    if (($seg[0] ?? '') === 'vendor' && ($seg[1] ?? '') === 'listings') {
        $ltype = isset(LISTING_TABLES[$seg[2] ?? '']) ? $seg[2] : 'product';
        $action = $seg[3] ?? '';
        $lid = ctype_digit($seg[4] ?? '') ? (int)$seg[4] : 0;
        if ($action === 'new') redirect("app/vendor/listings/$ltype/new");
        if ($action === 'edit' && $lid !== 0) redirect("app/vendor/listings/$ltype/$lid/edit");
        redirect("app/vendor/listings/$ltype");
    }
}

// route => page file; dynamic params set as globals for the page
match (true) {
    // public
    $path === ''                                  => require $P . 'home.php',
    $seg[0] === 'products' && count($seg) === 1   => call($P, 'browse.php', ['type' => 'product']),
    $seg[0] === 'services' && count($seg) === 1   => call($P, 'browse.php', ['type' => 'service']),
    $seg[0] === 'supplies' && count($seg) === 1   => call($P, 'browse.php', ['type' => 'supply']),
    $seg[0] === 'products' && count($seg) === 2   => call($P, 'detail.php', ['type' => 'product', 'slug' => $seg[1]]),
    $seg[0] === 'services' && count($seg) === 2   => call($P, 'detail.php', ['type' => 'service', 'slug' => $seg[1]]),
    $seg[0] === 'supplies' && count($seg) === 2   => call($P, 'detail.php', ['type' => 'supply', 'slug' => $seg[1]]),
    $seg[0] === 'businesses' && count($seg) === 2 => call($P, 'business.php', ['slug' => $seg[1]]),
    $seg[0] === 'videos' && count($seg) === 1     => require $P . 'videos.php',
    $seg[0] === 'videos' && ($seg[1] ?? '') === 'cta' => call($P, 'video_cta.php', ['id' => (int)($seg[2] ?? 0)]),

    // HTMX partials (no layout — return HTML fragments only)
    $path === 'search/suggest' => require $P . 'search_suggest.php',
    $path === 'cart/drawer'    => require $P . 'cart_drawer.php',

    // location + ads
    $path === 'location' => require $P . 'location.php',
    $seg[0] === 'ads' && ($seg[1] ?? '') === 'go' => call($P, 'ad_click.php', ['id' => (int)($seg[2] ?? 0)]),

    // cart + checkout + orders
    $path === 'cart'     => require $P . 'cart.php',
    $path === 'checkout' => require $P . 'checkout.php',
    $path === 'account/orders' => require $P . 'account_orders.php',
    $path === 'account/settings' => require $P . 'account_settings.php',
    $path === 'account/saved-searches' => require $P . 'account_saved_searches.php',
    $path === 'support' => require $P . 'support.php',

    // auth
    $path === 'login'    => require $P . 'auth_login.php',
    $path === 'register' => require $P . 'auth_register.php',
    $path === 'logout'   => require $P . 'auth_logout.php',
    $path === 'verify'   => require $P . 'auth_verify.php',
    $path === 'forgot-password' => require $P . 'auth_forgot.php',
    $path === 'appeal'   => require $P . 'sanction_appeal.php',
    $path === 'account'  => require $P . 'account.php',

    // inquiry threads + notifications + search + content pages
    $seg[0] === 'inquiries' && count($seg) === 2 => call($P, 'inquiry_view.php', ['id' => (int)$seg[1]]),
    $seg[0] === 'download' && count($seg) === 3 && in_array($seg[1], ['verification', 'payment'], true)
        => call($P, 'download.php', ['kind' => $seg[1], 'id' => (int)$seg[2]]),
    $path === 'notifications' => require $P . 'notifications.php',
    $path === 'search'        => require $P . 'search.php',
    $seg[0] === 'page' && count($seg) === 2 => call($P, 'page.php', ['slug' => $seg[1]]),
    in_array($path, ['about', 'contact', 'terms', 'privacy', 'prohibited-items'], true) => call($P, 'page.php', ['slug' => $path]),

    // video engagement events (POST from feed JS)
    $path === 'videos/event' => require $P . 'video_event.php',

    // session-cookie API for the React SPA — must be matched before the generic
    // /api arm below, since match(true) stops at the first true condition
    $seg[0] === 'api' && ($seg[1] ?? '') === 'v1' => call($P, 'api_v1.php', ['apiSeg' => array_slice($seg, 2)]),

    // bearer-token REST API (§18) for non-browser clients
    $seg[0] === 'api' => call($P, 'api.php', ['apiSeg' => array_slice($seg, 1)]),

    // form actions (POST)
    $path === 'inquiry'  => require $P . 'action_inquiry.php',
    $path === 'review'   => require $P . 'action_review.php',
    $path === 'report'   => require $P . 'action_report.php',
    $path === 'favorite' => require $P . 'action_favorite.php',
    $path === 'saved-search' => require $P . 'action_saved_search.php',

    // vendor dashboard
    $path === 'vendor'                => require $P . 'vendor_dashboard.php',
    $path === 'vendor/business'       => require $P . 'vendor_business.php',
    $seg[0] === 'vendor' && ($seg[1] ?? '') === 'listings' => call($P, 'vendor_listings.php', ['ltype' => $seg[2] ?? 'product', 'action' => $seg[3] ?? '', 'lid' => (int)($seg[4] ?? 0)]),
    $path === 'vendor/videos'         => require $P . 'vendor_videos.php',
    $path === 'vendor/verification'   => require $P . 'vendor_verification.php',
    $path === 'vendor/reviews'        => require $P . 'vendor_reviews.php',
    $path === 'vendor/inquiries'      => require $P . 'vendor_inquiries.php',
    $path === 'vendor/orders'         => require $P . 'vendor_orders.php',
    $path === 'vendor/promotions'     => require $P . 'vendor_promotions.php',
    $path === 'vendor/subscription'   => require $P . 'vendor_subscription.php',
    $path === 'vendor/analytics'      => require $P . 'vendor_analytics.php',

    // utilities
    $path === 'manifest.webmanifest' => require $P . 'manifest.php',
    $path === 'offline' => require $P . 'offline.php',
    $path === 'web-vitals' => require $P . 'web_vitals.php',
    $path === 'sitemap.xml' => call($P, 'sitemap.php', ['sitemapKind' => 'index']),
    preg_match('~^sitemap-(static|products|services|supplies|businesses)\.xml$~', $path, $m) === 1 => call($P, 'sitemap.php', ['sitemapKind' => $m[1]]),
    $seg[0] === 'cron'      => call($P, 'cron.php', ['job' => $seg[1] ?? 'daily']),

    // admin
    $seg[0] === 'admin' => call($P, 'admin.php', ['section' => $seg[1] ?? 'dashboard']),

    default => call($P, '404.php', []),
};

function call(string $dir, string $file, array $params): void {
    extract($params);
    require $dir . $file;
}
