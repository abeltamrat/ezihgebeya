<?php
/** XML sitemap index + split sitemaps (§25). Expects optional $sitemapKind. */
header('Content-Type: application/xml; charset=utf-8');
$kind = $sitemapKind ?? 'index';
$base = rtrim(absolute_url(url('')) ?: '', '/');

$xml = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$lastmod = function (?string $date): string {
    $ts = $date ? strtotime($date) : time();
    return date('Y-m-d', $ts ?: time());
};
$emitUrl = function (string $path, ?string $mod = null, string $freq = 'weekly') use ($base, $xml, $lastmod) {
    echo "  <url><loc>" . $xml($base . '/' . ltrim($path, '/')) . "</loc>"
        . "<lastmod>" . $lastmod($mod) . "</lastmod>"
        . "<changefreq>" . $xml($freq) . "</changefreq></url>\n";
};

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if ($kind === 'index') {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (['static', 'products', 'services', 'supplies', 'businesses'] as $part) {
        $mod = match ($part) {
            'products' => val("SELECT MAX(updated_at) FROM products WHERE status='active'"),
            'services' => val("SELECT MAX(updated_at) FROM services WHERE status='active'"),
            'supplies' => val("SELECT MAX(updated_at) FROM supplies WHERE status='active'"),
            'businesses' => val("SELECT MAX(updated_at) FROM businesses WHERE status='active'"),
            default => date('Y-m-d'),
        };
        echo "  <sitemap><loc>" . $xml($base . "/sitemap-$part.xml") . "</loc><lastmod>" . $lastmod($mod) . "</lastmod></sitemap>\n";
    }
    echo '</sitemapindex>';
    return;
}

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

if ($kind === 'static') {
    $emitUrl('', date('Y-m-d'), 'daily');
    foreach (['products', 'services', 'supplies', 'videos', 'about', 'contact', 'terms', 'privacy'] as $p) $emitUrl($p, date('Y-m-d'), 'daily');
} elseif ($kind === 'products') {
    foreach (rows("SELECT slug, updated_at FROM products WHERE status='active' ORDER BY updated_at DESC, id DESC") as $r) $emitUrl('products/' . $r['slug'], $r['updated_at']);
} elseif ($kind === 'services') {
    foreach (rows("SELECT slug, updated_at FROM services WHERE status='active' ORDER BY updated_at DESC, id DESC") as $r) $emitUrl('services/' . $r['slug'], $r['updated_at']);
} elseif ($kind === 'supplies') {
    foreach (rows("SELECT slug, updated_at FROM supplies WHERE status='active' ORDER BY updated_at DESC, id DESC") as $r) $emitUrl('supplies/' . $r['slug'], $r['updated_at']);
} elseif ($kind === 'businesses') {
    foreach (rows("SELECT slug, updated_at FROM businesses WHERE status='active' ORDER BY updated_at DESC, id DESC") as $r) $emitUrl('businesses/' . $r['slug'], $r['updated_at']);
}

echo '</urlset>';
