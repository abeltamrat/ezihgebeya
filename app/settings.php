<?php
/**
 * System Settings engine: one setting for every configurable system part, edited
 * from admin → Settings (super admin), stored as JSON in site_settings under
 * 'system_settings'. Constants in config.php remain the factory defaults.
 *
 * Read a value with sys('group.key'); plans()/promo_types()/payment_methods()
 * return the pricing tables with admin overrides applied.
 */

function system_settings_defaults(): array {
    return [
        'general' => [
            'site_name' => SITE_NAME,
            'tagline' => SITE_TAGLINE,
            'currency_label' => 'ETB',
            'contact_phone' => '0911000000',
            'contact_email' => 'hello@ezihgebeya.example',
            'default_city' => DEFAULT_CITY,
            'registration_open' => 1,
            'maintenance_mode' => 0,
            'maintenance_message' => 'We are doing scheduled maintenance — back within the hour.',
        ],
        'features' => [ // module on/off switches
            'videos' => 1,
            'cart' => 1,            // cart + checkout + orders
            'promotions' => 1,
            'subscriptions' => 1,
            'reviews' => 1,
            'inquiries' => 1,
            'ar' => 1,              // 3D/AR uploads + viewer
            'ads' => 1,             // ad engine slots
            'api' => 1,             // /api endpoints
            'location_detection' => 1, // GPS/IP auto-location
        ],
        'moderation' => [ // §16.3 — start strict, relax later (§30.5)
            'auto_approve_businesses' => 0,
            'auto_approve_listings' => 0,
            'auto_approve_videos' => 0,
            'auto_approve_reviews' => 0,
        ],
        'limits' => [
            'max_images_per_listing' => 6,
            'inquiry_rate_max' => 5,        // per window per session
            'inquiry_rate_window_min' => 10,
            'review_rate_max' => 5,         // per window per session (§22 cross-cutting rate limits)
            'review_rate_window_min' => 10,
            'listing_rate_max' => 10,       // listing create/edit submissions per window per session
            'listing_rate_window_min' => 60,
            'upload_rate_max' => 30,        // upload attempts per window per session
            'upload_rate_window_min' => 60,
            'video_feed_size' => 50,
            'ar_model_max_mb' => 10,
        ],
        'plans' => array_map(fn($p) => ['price' => $p['price'], 'listings' => $p['listings'], 'videos' => $p['videos']], PLANS),
        'promos' => array_map(fn($p) => ['price' => $p['price']], PROMO_TYPES),
        'payments' => [
            'cash_on_delivery' => 1,
            'bank_transfer' => 1,
            'telebirr' => 1,
            'cbe_birr' => 1,
            'instructions' => "Bank transfer: CBE 1000-XXXX-XXXX (EzihGebeya)\nTelebirr: 0911 000 000\nCBE Birr: 0911 000 000\nAlways keep the reference number and upload your proof screenshot.",
            'commission_percent' => 0, // §26.1 commission per order (0 = commission-free)
        ],
        'ranking' => [ // §8.4 listing score weights
            'city' => 20, 'subcity' => 10, 'keyword' => 4, 'verification' => 15,
            'rating' => 2, 'freshness' => 10, 'featured' => 8, 'promoted' => 6, 'report_penalty' => 10,
        ],
        'video_ranking' => [ // §6.5 video score weights
            'city' => 25, 'subcity' => 8, 'engagement' => 3, 'verification' => 15,
            'freshness' => 10, 'rating' => 2, 'promoted' => 5, 'featured' => 3, 'report_penalty' => 8,
        ],
        'auth' => [ // §22.1
            'otp_required' => 1,
            'login_max_attempts' => 8,
            'login_lockout_min' => 15,
            'session_timeout_min' => SESSION_TIMEOUT_MINUTES,
            'min_password_len' => 6,
        ],
        'notifications' => [
            'sms_mirror' => 1,        // mirror high-value notifications to SMS
            'sms_gateway_url' => '',  // e.g. https://sms.example/send?to={phone}&text={message}&token=SECRET
            'email_from' => 'no-reply@ezihgebeya.local',
        ],
        'seo' => [
            'meta_description' => SITE_TAGLINE,
            'head_snippet' => '',     // analytics / verification tags injected into <head>
        ],
    ];
}

/** Merged settings (defaults ⟵ saved overrides), cached per request. */
function system_settings(): array {
    static $merged = null;
    if ($merged === null) {
        $saved = site_setting_get('system_settings', []);
        $merged = array_replace_recursive(system_settings_defaults(), is_array($saved) ? $saved : []);
    }
    return $merged;
}

/** Dot-path accessor: sys('auth.otp_required'), sys('plans.pro.price'). */
function sys(string $path, $default = null) {
    $node = system_settings();
    foreach (explode('.', $path) as $k) {
        if (!is_array($node) || !array_key_exists($k, $node)) return $default;
        $node = $node[$k];
    }
    return $node;
}

function feature_enabled(string $key): bool { return (bool)sys("features.$key", 1); }

/** PLANS with admin price/limit overrides (labels stay from config). */
function plans(): array {
    $out = PLANS;
    foreach ((array)sys('plans', []) as $k => $p) {
        if (!isset($out[$k])) continue;
        $out[$k]['price'] = max(0, (float)($p['price'] ?? $out[$k]['price']));
        $out[$k]['listings'] = max(-1, (int)($p['listings'] ?? $out[$k]['listings']));
        $out[$k]['videos'] = max(-1, (int)($p['videos'] ?? $out[$k]['videos']));
    }
    $out['free']['price'] = 0;
    return $out;
}

/** PROMO_TYPES with admin price overrides. */
function promo_types(): array {
    $out = PROMO_TYPES;
    foreach ((array)sys('promos', []) as $k => $p) {
        if (isset($out[$k])) $out[$k]['price'] = max(0, (float)($p['price'] ?? $out[$k]['price']));
    }
    return $out;
}

/** Enabled payment methods for orders (label map). 'cash_on_delivery' + PAYMENT_METHODS. */
function payment_methods(bool $withCash = true): array {
    $all = ($withCash ? ['cash_on_delivery' => 'Cash on delivery / pickup'] : []) + PAYMENT_METHODS;
    return array_filter($all, fn($k) => (bool)sys("payments.$k", 1), ARRAY_FILTER_USE_KEY);
}

function payment_instructions(): string { return trim((string)sys('payments.instructions', '')); }

/** Sanitize the admin form input into the stored structure. */
function sanitize_system_settings(array $in): array {
    $d = system_settings_defaults();
    $out = [];

    $g = $in['general'] ?? [];
    $out['general'] = [
        'site_name' => mb_substr(trim($g['site_name'] ?? '') ?: $d['general']['site_name'], 0, 60),
        'tagline' => mb_substr(trim($g['tagline'] ?? '') ?: $d['general']['tagline'], 0, 160),
        'currency_label' => mb_substr(trim($g['currency_label'] ?? '') ?: 'ETB', 0, 10),
        'contact_phone' => mb_substr(trim($g['contact_phone'] ?? ''), 0, 30),
        'contact_email' => mb_substr(trim($g['contact_email'] ?? ''), 0, 150),
        'default_city' => array_key_exists($g['default_city'] ?? '', CITIES) ? $g['default_city'] : DEFAULT_CITY,
        'registration_open' => !empty($g['registration_open']) ? 1 : 0,
        'maintenance_mode' => !empty($g['maintenance_mode']) ? 1 : 0,
        'maintenance_message' => mb_substr(trim($g['maintenance_message'] ?? '') ?: $d['general']['maintenance_message'], 0, 300),
    ];

    foreach (['features', 'moderation'] as $grp) {
        $out[$grp] = [];
        foreach ($d[$grp] as $k => $_) $out[$grp][$k] = !empty($in[$grp][$k]) ? 1 : 0;
    }

    $out['limits'] = [];
    foreach (['max_images_per_listing' => [1, 20], 'inquiry_rate_max' => [1, 50], 'inquiry_rate_window_min' => [1, 120],
              'review_rate_max' => [1, 50], 'review_rate_window_min' => [1, 120],
              'listing_rate_max' => [1, 100], 'listing_rate_window_min' => [1, 240],
              'upload_rate_max' => [1, 200], 'upload_rate_window_min' => [1, 240],
              'video_feed_size' => [10, 200], 'ar_model_max_mb' => [1, 100]] as $k => [$min, $max]) {
        $out['limits'][$k] = max($min, min($max, (int)($in['limits'][$k] ?? $d['limits'][$k])));
    }

    $out['plans'] = [];
    foreach (PLANS as $k => $_) {
        $p = $in['plans'][$k] ?? [];
        $out['plans'][$k] = [
            'price' => max(0, (float)($p['price'] ?? $d['plans'][$k]['price'])),
            'listings' => max(-1, (int)($p['listings'] ?? $d['plans'][$k]['listings'])),
            'videos' => max(-1, (int)($p['videos'] ?? $d['plans'][$k]['videos'])),
        ];
    }
    $out['plans']['free']['price'] = 0;

    $out['promos'] = [];
    foreach (PROMO_TYPES as $k => $_) {
        $out['promos'][$k] = ['price' => max(0, (float)($in['promos'][$k]['price'] ?? $d['promos'][$k]['price']))];
    }

    $pm = $in['payments'] ?? [];
    $out['payments'] = [
        'cash_on_delivery' => !empty($pm['cash_on_delivery']) ? 1 : 0,
        'bank_transfer' => !empty($pm['bank_transfer']) ? 1 : 0,
        'telebirr' => !empty($pm['telebirr']) ? 1 : 0,
        'cbe_birr' => !empty($pm['cbe_birr']) ? 1 : 0,
        'instructions' => mb_substr(trim($pm['instructions'] ?? ''), 0, 2000),
        'commission_percent' => max(0, min(50, (float)($pm['commission_percent'] ?? 0))),
    ];
    if (!array_filter(array_intersect_key($out['payments'], array_flip(['cash_on_delivery', 'bank_transfer', 'telebirr', 'cbe_birr'])))) {
        $out['payments']['cash_on_delivery'] = 1; // never lock every payment method off
    }

    foreach (['ranking', 'video_ranking'] as $grp) {
        $out[$grp] = [];
        foreach ($d[$grp] as $k => $def) $out[$grp][$k] = max(0, min(100, (float)($in[$grp][$k] ?? $def)));
    }

    $a = $in['auth'] ?? [];
    $out['auth'] = [
        'otp_required' => !empty($a['otp_required']) ? 1 : 0,
        'login_max_attempts' => max(3, min(50, (int)($a['login_max_attempts'] ?? $d['auth']['login_max_attempts']))),
        'login_lockout_min' => max(1, min(1440, (int)($a['login_lockout_min'] ?? $d['auth']['login_lockout_min']))),
        'session_timeout_min' => max(10, min(10080, (int)($a['session_timeout_min'] ?? $d['auth']['session_timeout_min']))),
        'min_password_len' => max(4, min(64, (int)($a['min_password_len'] ?? $d['auth']['min_password_len']))),
    ];

    $n = $in['notifications'] ?? [];
    $out['notifications'] = [
        'sms_mirror' => !empty($n['sms_mirror']) ? 1 : 0,
        'sms_gateway_url' => mb_substr(trim($n['sms_gateway_url'] ?? ''), 0, 500),
        'email_from' => mb_substr(trim($n['email_from'] ?? '') ?: $d['notifications']['email_from'], 0, 150),
    ];

    $s = $in['seo'] ?? [];
    $out['seo'] = [
        'meta_description' => mb_substr(trim($s['meta_description'] ?? '') ?: $d['seo']['meta_description'], 0, 200),
        'head_snippet' => str_ireplace('</head', '', mb_substr((string)($s['head_snippet'] ?? ''), 0, 6000)),
    ];

    return $out;
}

// Convenience wrappers used across templates
function site_name(): string { return (string)sys('general.site_name', SITE_NAME); }
function site_tagline(): string { return (string)sys('general.tagline', SITE_TAGLINE); }
