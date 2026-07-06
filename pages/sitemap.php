<?php
/** XML sitemap (§25) */
header('Content-Type: application/xml; charset=utf-8');
$base = 'http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . BASE_URL;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$emit = function (string $path, string $mod = null, string $freq = 'weekly') use ($base) {
    echo "<url><loc>" . htmlspecialchars($base . '/' . ltrim($path, '/')) . "</loc>"
        . ($mod ? "<lastmod>" . date('Y-m-d', strtotime($mod)) . "</lastmod>" : '')
        . "<changefreq>$freq</changefreq></url>\n";
};

$emit('', null, 'daily');
foreach (['products', 'services', 'supplies', 'videos'] as $p) $emit($p, null, 'daily');
foreach (rows("SELECT slug, updated_at FROM products WHERE status='active'") as $r) $emit('products/' . $r['slug'], $r['updated_at']);
foreach (rows("SELECT slug, updated_at FROM services WHERE status='active'") as $r) $emit('services/' . $r['slug'], $r['updated_at']);
foreach (rows("SELECT slug, updated_at FROM supplies WHERE status='active'") as $r) $emit('supplies/' . $r['slug'], $r['updated_at']);
foreach (rows("SELECT slug, updated_at FROM businesses WHERE status='active'") as $r) $emit('businesses/' . $r['slug'], $r['updated_at']);

echo '</urlset>';
