<?php
/** Seeds demo ad campaigns with generated banner creatives. Safe to re-run (skips if ads exist). */
require __DIR__ . '/../config.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/helpers.php';

if (val("SELECT COUNT(*) FROM ads") > 0) { echo "ads already seeded — skipping\n"; exit; }

$dir = UPLOAD_DIR . '/ads';
if (!is_dir($dir)) mkdir($dir, 0775, true);

function ad_banner(string $file, string $line1, string $line2, array $c1, array $c2, int $w = 1100, int $h = 220): string {
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x++) {
        $r = (int)($c1[0] + ($c2[0] - $c1[0]) * $x / $w);
        $g = (int)($c1[1] + ($c2[1] - $c1[1]) * $x / $w);
        $b = (int)($c1[2] + ($c2[2] - $c1[2]) * $x / $w);
        imageline($im, $x, 0, $x, $h, imagecolorallocate($im, $r, $g, $b));
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, 40, (int)($h / 2) - 24, $line1, $white);
    imagestring($im, 3, 40, (int)($h / 2) + 4, $line2, $white);
    imagepng($im, UPLOAD_DIR . '/' . $file);
    return $file;
}

$catSofa = (int)val("SELECT id FROM categories WHERE slug = 'sofa'");
$catMdf  = (int)val("SELECT id FROM categories WHERE slug = 'mdf-board'");

$demo = [
    // advertiser, phone, title, body, dest, placement, market, cat, city, subcity, pricing, unit, budget, priority, img
    ['Habesha Paints', '0912000001', 'Premium interior paints — 20% off this month', 'Free color consultation for EzihGebeya customers.',
     'https://example.com/habesha-paints', 'home_hero', 'any', null, 'Addis Ababa', null, 'cpm', 60, 500, 3,
     ad_banner('ads/demo-paints.png', 'HABESHA PAINTS - 20% OFF', 'Premium interior & exterior paints - free color consultation', [90, 45, 130], [190, 60, 90])],
    ['Bole Woodworks Supply', '0912000002', '18mm MDF wholesale — best price in Addis', 'Delivery within 24h for orders above 20 sheets.',
     '/ezihgebeya/supplies/mdf-board-18mm-122-244cm-addis-ababa', 'browse_inline', 'supply', $catMdf, 'Addis Ababa', null, 'cpc', 20, 300, 4,
     ad_banner('ads/demo-mdf.png', 'MDF WHOLESALE', 'Best 18mm price in Addis - 24h delivery', [40, 70, 40], [110, 140, 60], 640, 480)],
    ['Selam Home Loans', '0912000003', 'Furnish now, pay monthly', 'Financing for home furniture from 5,000 ETB/month.',
     'https://example.com/selam-loans', 'browse_top', 'product', null, null, null, 'cpc', 15, 0, 2,
     ad_banner('ads/demo-loans.png', 'FURNISH NOW - PAY MONTHLY', 'Home furniture financing from 5,000 ETB/month', [20, 60, 110], [30, 130, 160])],
];

foreach ($demo as [$name, $phone, $title, $body, $dest, $placement, $market, $cat, $city, $subcity, $pricing, $unit, $budget, $prio, $img]) {
    q("INSERT INTO ads (advertiser_name, advertiser_phone, title, body, destination_url, placement, market_type, category_id, city, subcity, pricing_type, unit_price, budget, priority, status, image, starts_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', ?, NOW())",
      [$name, $phone, $title, $body, $dest, $placement, $market, $cat, $city, $subcity, $pricing, $unit, $budget, $prio, $img]);
}
echo "seeded " . count($demo) . " demo ad campaigns\n";
