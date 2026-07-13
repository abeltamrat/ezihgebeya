<?php
// EzihGebeya configuration
require_once __DIR__ . '/app/env.php';
load_env(__DIR__ . '/.env'); // gitignored — see .env.example for the variables this reads

define('SITE_NAME', 'EzihGebeya');
define('SITE_TAGLINE', 'Ethiopia\'s Furniture, Finishing & Supplies Marketplace');
$host = $_SERVER['HTTP_HOST'] ?? '';
define('BASE_URL', getenv('APP_BASE_URL') ?: (($host === 'ezihgebeya.nonstopplc.com' || str_contains($host, 'localhost')) ? '' : '/ezihgebeya'));

// Local defaults match the existing XAMPP dev setup; production sets these via
// the hosting panel's environment variables or a private .env file (never committed).
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'ezihgebeya');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');
define('MAX_UPLOAD_BYTES', 30 * 1024 * 1024);
define('CACHE_DIR', __DIR__ . '/storage/cache'); // not web-addressable; see app/cache.php
// Verification documents and payment proofs: not public media like listing photos, so they
// live outside uploads/ entirely, blocked from direct web access by .htaccess, and are only
// ever reachable through the authorized download endpoint in pages/download.php.
define('PROTECTED_UPLOAD_DIR', __DIR__ . '/protected_uploads');

// Location data (MVP: config-driven; move to DB when scaling)
const CITIES = [
    'Addis Ababa' => ['Bole','Yeka','Kirkos','Arada','Lideta','Gullele','Nifas Silk-Lafto','Kolfe Keranio','Akaky Kaliti','Addis Ketema','Lemi Kura'],
    'Adama'       => [],
    'Hawassa'     => [],
    'Bahir Dar'   => [],
    'Mekelle'     => [],
    'Dire Dawa'   => [],
    'Jimma'       => [],
];

// Approximate city-center coordinates, used to (a) find the nearest known city to a
// GPS/IP lat-lng and (b) let a manual city pick behave the same as a GPS fix.
const CITY_COORDS = [
    'Addis Ababa' => [9.0192, 38.7525],
    'Adama'       => [8.5400, 39.2700],
    'Hawassa'     => [7.0500, 38.4700],
    'Bahir Dar'   => [11.5936, 37.3908],
    'Mekelle'     => [13.4967, 39.4753],
    'Dire Dawa'   => [9.5931, 41.8661],
    'Jimma'       => [7.6730, 36.8344],
];
const DEFAULT_CITY = 'Addis Ababa';

// Approximate neighborhood (sub-city) center coordinates, used to narrow a GPS fix
// beyond city level. Only Addis Ababa is mapped for now — per the spec's own MVP
// guidance to focus there first (§30.12); other cities stay at city-level precision
// until their sub-city geography is added.
const SUBCITY_COORDS = [
    'Addis Ababa' => [
        'Bole'              => [8.9945, 38.7898],
        'Yeka'              => [9.0257, 38.8108],
        'Kirkos'            => [9.0107, 38.7613],
        'Arada'             => [9.0350, 38.7469],
        'Lideta'            => [9.0139, 38.7361],
        'Gullele'           => [9.0505, 38.7333],
        'Nifas Silk-Lafto'  => [8.9564, 38.7333],
        'Kolfe Keranio'     => [9.0192, 38.6864],
        'Akaky Kaliti'      => [8.8833, 38.7833],
        'Addis Ketema'      => [9.0350, 38.7275],
        'Lemi Kura'         => [9.0450, 38.8300],
    ],
];

// Free-tier IP geolocation fallback (no key required). Server-to-server call only —
// see app/helpers.php ip_geolocate(). Swap for a paid provider before high-traffic launch.
define('IP_GEO_API', getenv('IP_GEO_API') ?: 'https://freeipapi.com/api/json/');

const VENDOR_TYPES = ['seller','manufacturer','importer','service_provider','supplier'];

const PRODUCT_TYPES = ['ready_made'=>'Ready Made','custom_made'=>'Custom Made','imported'=>'Imported','used'=>'Used','made_to_order'=>'Made to Order','decor'=>'Decor','tool'=>'Tool','machine'=>'Machine'];
const PRICE_TYPES   = ['fixed'=>'Fixed','starting_from'=>'Starting From','per_square_meter'=>'Per m²','per_day'=>'Per Day','per_project'=>'Per Project','quote_required'=>'Quote Required'];
const SUPPLY_UNITS  = ['piece','sheet','meter','kg','liter','roll','set','box','bundle'];
const CTA_LABELS    = ['Check Product','Request Quote','View Material','Visit Supplier','Book Service','Chat Seller','Call Now'];

// Subscription plans (§26.2): price ETB/month, listing limit, video limit (-1 = unlimited)
const PLANS = [
    'free'    => ['label' => 'Free',    'price' => 0,    'listings' => 5,   'videos' => 1],
    'basic'   => ['label' => 'Basic',   'price' => 500,  'listings' => 25,  'videos' => 5],
    'pro'     => ['label' => 'Pro',     'price' => 1500, 'listings' => 100, 'videos' => -1],
    'premium' => ['label' => 'Premium', 'price' => 4000, 'listings' => -1,  'videos' => -1],
];

// Promotion types (§9.1): label, ETB/week, allowed target
const PROMO_TYPES = [
    'category_featured'      => ['label' => 'Featured in category',   'price' => 300,  'target' => 'listing'],
    'city_featured'          => ['label' => 'Featured in city',       'price' => 400,  'target' => 'listing'],
    'search_top_result'      => ['label' => 'Top of search results',  'price' => 500,  'target' => 'listing'],
    'homepage_banner'        => ['label' => 'Homepage banner',        'price' => 1000, 'target' => 'listing'],
    'video_feed_boost'       => ['label' => 'Video feed boost',       'price' => 350,  'target' => 'video'],
    'business_profile_boost' => ['label' => 'Business profile boost', 'price' => 600,  'target' => 'business'],
];

// Tenant monetization ladder (PLAN.md "Tenant monetization ladder"). Launch prices, confirmed
// by [You] — intentionally low to test willingness to pay before any gateway integration.
// TOP Pin: single-listing pin, flat price for a fixed duration (not the per-week PROMO_TYPES
// model) — reuses the `promotions` table with promotion_type='top_pin' and duration_days.
const TOP_PIN_PACKAGES = [
    'top_pin_7'  => ['label' => 'TOP Pin — 7 days',  'price' => 150, 'duration_days' => 7],
    'top_pin_30' => ['label' => 'TOP Pin — 30 days', 'price' => 450, 'duration_days' => 30],
];

// Boost subscription: vendor-wide ranking lift + analytics — reuses the `subscriptions` table
// with type='boost' (never conflated with the listing-quota `type='listing_plan'` PLANS above).
const BOOST_TIERS = [
    'boost_basic' => ['label' => 'Boost Basic', 'price' => 500,  'rank_weight' => 1,
        'benefits' => ['Light ranking lift for all your listings', 'More "similar listings" exposure', 'Basic analytics']],
    'boost_pro'   => ['label' => 'Boost Pro',   'price' => 1000, 'rank_weight' => 2,
        'benefits' => ['Stronger ranking lift for all your listings', 'Auto-refreshed listing freshness', 'Full analytics']],
    'boost_max'   => ['label' => 'Boost Max',   'price' => 2000, 'rank_weight' => 3,
        'benefits' => ['Highest ranking lift for all your listings', 'Priority "similar listings" placement', 'Full analytics', 'Sales callback support']],
];

const PAYMENT_METHODS = ['bank_transfer' => 'Bank Transfer', 'telebirr' => 'Telebirr', 'cbe_birr' => 'CBE Birr'];

// Ad placements (§9): label + suggested rate card shown to the super admin
const AD_PLACEMENTS = [
    'home_hero'      => ['label' => 'Homepage hero banner',        'hint' => 'Widest reach — suggest 1,500 ETB/wk flat or 60 ETB CPM'],
    'home_inline'    => ['label' => 'Homepage inline banner',      'hint' => 'Suggest 900 ETB/wk flat or 40 ETB CPM'],
    'browse_top'     => ['label' => 'Search results top banner',   'hint' => 'Category-targeted — suggest 15 ETB CPC'],
    'browse_inline'  => ['label' => 'Native card in listing grid', 'hint' => 'Highest CTR — suggest 20 ETB CPC'],
    'detail_sidebar' => ['label' => 'Listing detail sidebar',      'hint' => 'High intent — suggest 12 ETB CPC'],
    'video_slide'    => ['label' => 'Video feed sponsored slide',  'hint' => 'Suggest 80 ETB CPM'],
];
const AD_PRICING = ['cpm' => 'CPM (per 1,000 views)', 'cpc' => 'CPC (per click)', 'flat_weekly' => 'Flat per week'];
// Contact shown on "Advertise here" house ads when a slot has no paid campaign
define('AD_SALES_CONTACT', 'tel:0911000000');

define('CRON_SECRET', getenv('CRON_SECRET') ?: '');

// DEV mode: OTP codes are flashed on screen and SMS/email go to database/outbox.log
// instead of a real gateway. It is opt-in and can never be enabled in production.
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'production'));
$appDevMode = strtolower((string)(getenv('APP_DEV_MODE') ?: ''));
define('DEV_MODE', $appEnv !== 'production' && in_array($appDevMode, ['1', 'true', 'yes', 'on'], true));

// Never leak file paths/stack traces to visitors (cross-cutting security checklist: "No
// production stack traces, SQL errors, OTPs, or secrets in responses"). display_errors
// defaults to whatever php.ini says, which is commonly ON on dev/shared-hosting installs —
// an uncaught exception would otherwise render the full server path, exact file/line, and
// stack trace with a 200 status. Real errors still go to the PHP error log either way.
ini_set('display_errors', DEV_MODE ? '1' : '0');
ini_set('log_errors', '1');
function render_fatal_error_page(string $devDetail): void {
    if (!headers_sent()) http_response_code(500);
    if (DEV_MODE) {
        echo '<pre>' . htmlspecialchars($devDetail, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<!DOCTYPE html><html><head><title>Something went wrong</title></head>'
           . '<body style="font-family:sans-serif;text-align:center;padding:60px 20px">'
           . '<h1>Something went wrong</h1><p>Please try again in a moment.</p></body></html>';
    }
}
set_exception_handler(function (Throwable $e): void {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    render_fatal_error_page((string)$e);
});
// Catches fatal errors that never reach the exception handler (e.g. memory exhaustion,
// timeout) — the only path left that could still leak a raw PHP error page.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        // display_errors is off in production (set above), so PHP hasn't already printed its
        // own raw error text by the time this runs — safe to render our fallback page instead.
        render_fatal_error_page($err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
});

// Idle session timeout (§22.1.5)
define('SESSION_TIMEOUT_MINUTES', 120);

// Max side length for uploaded images; larger photos are downscaled + recompressed (§22.3)
define('IMAGE_MAX_DIMENSION', 1600);
define('THUMB_DIMENSION', 400);
