<?php
declare(strict_types=1);

// Dependency-free security regression checks: php tests/backend_regression.php
$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/config.php';
require_once $root . '/app/db.php';
require_once $root . '/app/settings.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/remembered_login.php';
require_once $root . '/app/ads.php';

$tests = 0; $failures = [];
$assertSame = static function ($expected, $actual, string $label) use (&$tests, &$failures): void {
    $tests++;
    if ($expected !== $actual) $failures[] = $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
};
$assertSame('app/vendor', safe_return_path('/app/vendor'), 'accepts an internal React route');
$assertSame('products/example', safe_return_path('/products/example'), 'accepts an internal public route');
$assertSame('', safe_return_path('https://evil.example/steal'), 'rejects an absolute external URL');
$assertSame('', safe_return_path('//evil.example/steal'), 'rejects a protocol-relative URL');
$assertSame('', safe_return_path('/login?return=/login'), 'rejects an authentication loop');
$assertSame('', safe_return_path("/app/vendor\r\nX-Test: injected"), 'rejects header injection');
$assertSame('products', LISTING_TABLES['product'] ?? null, 'maps product through the listing allowlist');
$assertSame(false, isset(LISTING_TABLES['products; DROP TABLE users']), 'rejects an invalid listing type');
$assertSame(null, remembered_login_parse_cookie('not-a-token'), 'rejects a malformed quick-login cookie');
$quickParts = remembered_login_parse_cookie(str_repeat('a', 32) . '.' . str_repeat('b', 64));
$assertSame(str_repeat('a', 32), $quickParts['selector'] ?? null, 'accepts a strict quick-login selector');
$assertSame(str_repeat('b', 64), $quickParts['validator'] ?? null, 'accepts a strict quick-login validator');
$expectedCookiePath = BASE_URL === '' ? '/' : rtrim(BASE_URL, '/') . '/';
$assertSame($expectedCookiePath, remembered_login_cookie_path(), 'scopes the quick-login cookie to the configured site path');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$assertSame(true, remembered_login_is_https(), 'marks proxy-terminated HTTPS quick-login cookies secure');
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$assertSame(true, count(offensive_content_matches('This is a shit product.')) > 0, 'detects a configured offensive review term');
$assertSame([], offensive_content_matches('The craftsmanship and service were excellent.'), 'allows normal review language');
$assertSame([], offensive_content_matches('The shiitake-colored finish looks good.'), 'does not block harmless partial-word matches');
$adA = ['placement' => 'browse_top', 'market_type' => 'product', 'category_id' => null, 'city' => 'Addis Ababa', 'subcity' => null, 'starts_at' => '2026-07-01 00:00:00', 'ends_at' => '2026-07-31 23:59:59'];
$adB = ['placement' => 'any', 'market_type' => 'any', 'category_id' => 4, 'city' => null, 'subcity' => null, 'starts_at' => '2026-07-15 00:00:00', 'ends_at' => '2026-08-15 23:59:59'];
$assertSame(true, ad_campaigns_overlap($adA, $adB), 'detects overlapping ad slot, audience, location, and schedule');
$adB['starts_at'] = '2026-08-01 00:00:00';
$assertSame(false, ad_campaigns_overlap($adA, $adB), 'allows the same ad inventory in non-overlapping schedules');
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    fwrite(STDERR, 'Backend regression: ' . count($failures) . " of {$tests} failed.\n");
    exit(1);
}
echo "Backend regression: {$tests} passed.\n";
