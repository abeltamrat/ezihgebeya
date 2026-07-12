<?php
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$manifest = [
    'id' => url(''),
    'name' => site_name() . ' - Furniture, Finishing & Supplies Marketplace',
    'short_name' => site_name(),
    'description' => site_tagline() . '. Find trusted furniture sellers, finishing professionals, and material suppliers near you.',
    'lang' => 'en-ET',
    'dir' => 'ltr',
    'start_url' => url(''),
    'scope' => url(''),
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui', 'browser'],
    'orientation' => 'portrait-primary',
    'background_color' => '#f5f7fb',
    'theme_color' => '#2454d6',
    'categories' => ['shopping', 'business', 'lifestyle'],
    'icons' => [
        ['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Browse furniture', 'short_name' => 'Furniture', 'url' => url('products'), 'icons' => [['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192']]],
        ['name' => 'Post a listing', 'short_name' => 'Post', 'url' => url('vendor/listings/product/new'), 'icons' => [['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192']]],
        ['name' => 'My account', 'short_name' => 'Account', 'url' => url('account'), 'icons' => [['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192']]],
    ],
];
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
