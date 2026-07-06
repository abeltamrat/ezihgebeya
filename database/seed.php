<?php
/** CLI seeder: php database/seed.php
 *  Creates PWA icons, admin + demo users, businesses, listings (with generated placeholder images), videos, reviews.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/helpers.php';

echo "Seeding EzihGebeya...\n";

// re-apply emoji category icons via PDO/utf8mb4 (the mysql CLI mangles them when piping setup.sql)
require __DIR__ . '/fix_icons.php';

// ---------- PWA icons ----------
$iconDir = __DIR__ . '/../assets/icons';
if (!is_dir($iconDir)) mkdir($iconDir, 0775, true);
foreach ([192, 512] as $size) {
    $im = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($im, 124, 74, 33);      // brand brown
    $fg = imagecolorallocate($im, 250, 246, 241);    // cream
    $ac = imagecolorallocate($im, 232, 145, 58);     // accent
    imagefill($im, 0, 0, $bg);
    $u = $size / 16;
    // simple armchair glyph
    imagefilledrectangle($im, (int)(3*$u), (int)(6*$u), (int)(13*$u), (int)(10.5*$u), $fg);          // back+seat
    imagefilledrectangle($im, (int)(2*$u), (int)(8*$u), (int)(4*$u), (int)(12*$u), $ac);             // left arm
    imagefilledrectangle($im, (int)(12*$u), (int)(8*$u), (int)(14*$u), (int)(12*$u), $ac);           // right arm
    imagefilledrectangle($im, (int)(2*$u), (int)(11*$u), (int)(14*$u), (int)(12.5*$u), $fg);         // base
    imagefilledrectangle($im, (int)(3*$u), (int)(12.5*$u), (int)(4.2*$u), (int)(14*$u), $fg);        // legs
    imagefilledrectangle($im, (int)(11.8*$u), (int)(12.5*$u), (int)(13*$u), (int)(14*$u), $fg);
    imagepng($im, "$iconDir/icon-$size.png");
    imagedestroy($im);
}
echo "  ✓ PWA icons\n";

// ---------- placeholder image generator ----------
$prodDir = UPLOAD_DIR . '/products';
if (!is_dir($prodDir)) mkdir($prodDir, 0775, true);
function placeholder(string $file, string $label, array $c1, array $c2): string {
    $w = 640; $h = 480;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) { // vertical gradient
        $r = (int)($c1[0] + ($c2[0] - $c1[0]) * $y / $h);
        $g = (int)($c1[1] + ($c2[1] - $c1[1]) * $y / $h);
        $b = (int)($c1[2] + ($c2[2] - $c1[2]) * $y / $h);
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, $r, $g, $b));
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    $font = 5;
    $tw = imagefontwidth($font) * strlen($label);
    imagestring($im, $font, (int)(($w - $tw) / 2), (int)($h / 2) - 8, $label, $white);
    imagepng($im, UPLOAD_DIR . '/' . $file);
    return $file;
}

$pdo = db();
if (val("SELECT COUNT(*) FROM users") > 0) { echo "  ! users already exist — skipping data seed.\n"; exit; }

// ---------- users ----------
$mk = function ($name, $phone, $email, $pass, $type) use ($pdo) {
    q("INSERT INTO users (full_name, phone, email, password, account_type, status, phone_verified_at) VALUES (?,?,?,?,?,'active',NOW())",
      [$name, $phone, $email, password_hash($pass, PASSWORD_BCRYPT), $type]);
    return (int)$pdo->lastInsertId();
};

$adminId    = $mk('Abel Tamrat', '0911000000', 'abeltamrat@gmail.com', 'admin123', 'super_admin');
$sellerId   = $mk('Meskerem Alemu', '0911111111', 'seller@demo.et', 'demo123', 'seller');
$makerId    = $mk('Dawit Bekele', '0922222222', 'maker@demo.et', 'demo123', 'manufacturer');
$serviceId  = $mk('Hana Girma', '0933333333', 'service@demo.et', 'demo123', 'service_provider');
$supplierId = $mk('Yonas Tesfaye', '0944444444', 'supplier@demo.et', 'demo123', 'supplier');
$custId     = $mk('Sara Mekonnen', '0955555555', 'customer@demo.et', 'demo123', 'customer');
echo "  ✓ users (admin: 0911000000 / admin123)\n";

// ---------- businesses ----------
$mkBiz = function ($uid, $name, $slug, $type, $desc, $phone, $city, $sub, $verify) use ($pdo) {
    q("INSERT INTO businesses (user_id, business_name, slug, business_type, description, phone, city, subcity, verification_status, status, rating_average, rating_count)
       VALUES (?,?,?,?,?,?,?,?,?, 'active', 0, 0)",
      [$uid, $name, $slug, $type, $desc, $phone, $city, $sub, $verify]);
    return (int)$pdo->lastInsertId();
};

$b1 = $mkBiz($sellerId, 'Meskerem Furniture', 'meskerem-furniture', 'seller',
    "Quality ready-made and imported furniture in Addis Ababa. Sofas, beds, dining sets and office furniture with delivery across the city.",
    '0911111111', 'Addis Ababa', 'Bole', 'document_verified');
$b2 = $mkBiz($makerId, 'Dawit Custom Woodworks', 'dawit-custom-woodworks', 'manufacturer',
    "Workshop in Kolfe producing made-to-order kitchen cabinets, wardrobes and solid wood furniture. Bulk orders welcome.",
    '0922222222', 'Addis Ababa', 'Kolfe Keranio', 'premium_verified');
$b3 = $mkBiz($serviceId, 'Hana Interiors & Finishing', 'hana-interiors', 'service_provider',
    "Interior design, gypsum work and painting for homes, offices and cafés. 8+ years of experience, portfolio available on request.",
    '0933333333', 'Addis Ababa', 'Yeka', 'document_verified');
$b4 = $mkBiz($supplierId, 'Yonas Building Materials', 'yonas-building-materials', 'supplier',
    "Wholesale and retail MDF, plywood, hardware and finishing materials. Delivery available for bulk orders.",
    '0944444444', 'Addis Ababa', 'Addis Ketema', 'phone_verified');
echo "  ✓ businesses\n";

// ---------- products ----------
$catId = fn($slug) => val("SELECT id FROM categories WHERE slug = ?", [$slug]);
$brown = [124, 74, 33]; $tan = [176, 122, 74]; $sand = [214, 178, 138]; $dark = [76, 47, 20]; $orange = [232, 145, 58];

$products = [
    [$b1, 'sofa', 'Modern L-Shape Sofa', 48000, 42500, "Comfortable L-shape sofa with premium fabric, solid eucalyptus frame and high-density foam. Seats 5.", 'ready_made', 'Fabric & wood', '280×180×85 cm', 1, 1, $brown, $tan],
    [$b1, 'bed', 'King Size Bed with Headboard', 36000, null, "King size bed (180×200) with upholstered headboard. Mattress not included.", 'ready_made', 'MDF & fabric', '200×180×120 cm', 1, 0, $dark, $brown],
    [$b1, 'dining-table', '6-Seater Dining Set', 52000, null, "Solid wood dining table with 6 cushioned chairs. Walnut finish.", 'ready_made', 'Solid wood', '180×90×75 cm', 1, 1, $tan, $sand],
    [$b1, 'office-furniture', 'Executive Office Desk', 24500, 21900, "L-shaped executive desk with drawers and cable management.", 'imported', 'MDF veneer', '160×140×76 cm', 1, 0, $dark, $tan],
    [$b1, 'tv-stand', 'Floating TV Stand 180cm', 12500, null, "Wall-mounted TV stand with LED strip and soft-close drawers.", 'ready_made', 'MDF high gloss', '180×35×30 cm', 1, 1, $brown, $orange],
    [$b2, 'kitchen-cabinet', 'Custom Kitchen Cabinet (per meter)', 18000, null, "Made-to-order kitchen cabinets: HPL or membrane doors, soft-close hinges, granite-ready counters. Price per running meter.", 'made_to_order', 'MDF & HPL', null, 1, 1, $orange, $sand],
    [$b2, 'wardrobe', '3-Door Sliding Wardrobe', 45000, null, "Custom sliding wardrobe with mirror, drawers and LED lighting. Built to your room size.", 'made_to_order', 'MDF', '240×60×240 cm', 1, 1, $tan, $dark],
    [$b2, 'chair', 'Solid Wood Dining Chairs (set of 4)', 14000, 12500, "Handmade solid wood chairs, natural finish, very sturdy.", 'custom_made', 'Solid wood', null, 1, 0, $sand, $brown],
    [$b1, 'decor', 'Handwoven Wall Basket Set', 3500, null, "Set of 5 traditional handwoven baskets for wall decor.", 'ready_made', 'Natural fiber', null, 0, 0, $orange, $tan],
    [$b1, 'lighting', 'Modern Pendant Light', 4800, null, "3-head pendant light, matte black with brass details.", 'imported', 'Metal', null, 1, 0, $dark, $orange],
];
$featuredEvery = 3; $i = 0;
foreach ($products as [$biz, $cat, $title, $price, $disc, $desc, $ptype, $material, $dims, $delivery, $custom, $c1, $c2]) {
    $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)), '-') . '-addis-ababa';
    q("INSERT INTO products (business_id, category_id, title, slug, description, product_type, price, discount_price, material, dimensions, delivery_available, customization_available, city, subcity, status, is_featured, stock_quantity, views_count)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', ?, ?, ?)",
      [$biz, $catId($cat), $title, $slug, $desc, $ptype, $price, $disc, $material, $dims, $delivery, $custom,
       'Addis Ababa', $biz == $b2 ? 'Kolfe Keranio' : 'Bole', ($i % $featuredEvery === 0) ? 1 : 0, rand(1, 12), rand(40, 900)]);
    $pid = $pdo->lastInsertId();
    $file = placeholder("products/demo-$pid.png", $title, $c1, $c2);
    q("INSERT INTO product_media (product_id, file_url, is_primary) VALUES (?,?,1)", [$pid, $file]);
    $i++;
}
echo "  ✓ products\n";

// ---------- services ----------
$svcDir = UPLOAD_DIR . '/services';
if (!is_dir($svcDir)) mkdir($svcDir, 0775, true);
$services = [
    [$b3, 'interior-design', 'Full Interior Design Package', 'per_project', 35000, 8, "Complete interior design: concept, 3D visuals, material selection and site supervision for homes, offices and cafés."],
    [$b3, 'gypsum-work', 'Gypsum Ceiling & Wall Design', 'per_square_meter', 850, 8, "Modern gypsum ceilings with hidden LED lighting. Price per m² including material and labor."],
    [$b3, 'painting', 'Interior & Exterior Painting', 'per_square_meter', 260, 6, "Professional painting with premium paint. Smooth finish guarantee."],
    [$b2, 'furniture-installation', 'Furniture Assembly & Installation', 'per_day', 2500, 10, "Professional assembly of wardrobes, kitchen cabinets and office furniture."],
    [$b3, 'flooring', 'Laminate & SPC Flooring Installation', 'quote_required', null, 5, "Supply and installation of laminate and SPC flooring. Free site measurement in Addis Ababa."],
];
foreach ($services as $j => [$biz, $cat, $title, $ptype, $sprice, $years, $desc]) {
    $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)), '-') . '-addis-ababa';
    q("INSERT INTO services (business_id, category_id, title, slug, description, experience_years, price_type, starting_price, image, city, subcity, status, is_featured, views_count)
       VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active', ?, ?)",
      [$biz, $catId($cat), $title, $slug, $desc, $years, $ptype, $sprice,
       placeholder("services/demo-svc-$j.png", $title, [94, 55, 22], [176, 122, 74]),
       'Addis Ababa', 'Yeka', $j === 0 ? 1 : 0, rand(30, 400)]);
}
echo "  ✓ services\n";

// ---------- supplies ----------
$supDir = UPLOAD_DIR . '/supplies';
if (!is_dir($supDir)) mkdir($supDir, 0775, true);
$supplies = [
    [$b4, 'mdf-board', 'MDF Board 18mm (122×244cm)', 'sheet', 2350, 2200, 10, '18mm', '122×244 cm', "Plain MDF board, furniture grade. Bulk discount from 10 sheets."],
    [$b4, 'mdf-board', 'Melamine MDF 16mm — White', 'sheet', 2900, 2750, 10, '16mm', '122×244 cm', "Double-faced melamine MDF, white. Scratch resistant."],
    [$b4, 'plywood', 'Plywood 12mm Commercial', 'sheet', 1850, 1700, 20, '12mm', '122×244 cm', "Commercial plywood, good for backing and drawers."],
    [$b4, 'hardware-accessories', 'Soft-Close Hinges (box of 50)', 'box', 3200, 2900, 5, null, null, "Clip-on soft-close hinges, 35mm cup. Box of 50 pcs."],
    [$b4, 'paint-finishing', 'Wood Lacquer 4L — Clear', 'piece', 2400, null, 1, null, '4 liter', "High-quality NC lacquer for furniture finishing."],
    [$b4, 'solid-wood', 'Wanza Solid Wood Board', 'meter', 950, 880, 20, '5cm', null, "Seasoned wanza boards for table tops and stairs. Price per meter."],
];
foreach ($supplies as $j => [$biz, $cat, $name, $unit, $ppu, $bulk, $moq, $thick, $size, $desc]) {
    $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-') . '-addis-ababa';
    q("INSERT INTO supplies (business_id, category_id, name, slug, description, thickness, size, unit_of_measurement, price_per_unit, bulk_price, minimum_order_quantity, stock_quantity, delivery_available, image, city, subcity, status, is_featured, views_count)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 1, ?, ?, ?, 'active', ?, ?)",
      [$biz, $catId($cat), $name, $slug, $desc, $thick, $size, $unit, $ppu, $bulk, $moq, rand(20, 400),
       placeholder("supplies/demo-sup-$j.png", $name, [60, 42, 25], [140, 100, 60]),
       'Addis Ababa', 'Addis Ketema', $j === 0 ? 1 : 0, rand(20, 300)]);
}
echo "  ✓ supplies\n";

// ---------- videos ----------
$prod1 = val("SELECT id FROM products WHERE business_id = ? ORDER BY id LIMIT 1", [$b1]);
$svc1 = val("SELECT id FROM services WHERE business_id = ? ORDER BY id LIMIT 1", [$b3]);
$videos = [
    [$b1, $sellerId, 'youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ', 'product', $prod1, 'Check Product', 'Modern L-Shape Sofa — showroom tour'],
    [$b3, $serviceId, 'youtube', 'https://www.youtube.com/shorts/aqz-KE-bpKQ', 'aqz-KE-bpKQ', 'service', $svc1, 'Book Service', 'Gypsum ceiling transformation, before & after'],
    [$b4, $supplierId, 'youtube', 'https://www.youtube.com/watch?v=jNQXAC9IVRw', 'jNQXAC9IVRw', 'business', null, 'Visit Supplier', 'Warehouse walkthrough — MDF & plywood stock'],
];
foreach ($videos as [$biz, $uid, $platform, $orig, $vid, $ltype, $lid, $cta, $title]) {
    q("INSERT INTO video_posts (business_id, user_id, platform, original_url, video_id, embed_url, title, linked_type, linked_id, cta_label, city, subcity, status, views_count, cta_clicks_count)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'approved', ?, ?)",
      [$biz, $uid, $platform, $orig, $vid, 'https://www.youtube.com/embed/' . $vid . '?playsinline=1&rel=0',
       $title, $ltype, $lid, $cta, 'Addis Ababa', 'Bole', rand(100, 2000), rand(5, 120)]);
}
echo "  ✓ videos\n";

// ---------- reviews ----------
$revs = [
    [$custId, $b1, 'product', $prod1, 5, "Great quality sofa, delivered on time. Highly recommend!"],
    [$custId, $b3, 'business', null, 5, "Hana's team did our gypsum ceiling and painting — clean work and fair price."],
    [$custId, $b4, 'business', null, 4, "Good MDF prices. Delivery was a day late but material quality is solid."],
];
foreach ($revs as [$uid, $biz, $ltype, $lid, $rating, $comment]) {
    q("INSERT INTO reviews (reviewer_id, business_id, listing_type, listing_id, rating, comment, status) VALUES (?,?,?,?,?,?, 'approved')",
      [$uid, $biz, $ltype, $lid, $rating, $comment]);
}
foreach ([$b1, $b3, $b4] as $biz) {
    $agg = row("SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE business_id = ? AND status = 'approved'", [$biz]);
    q("UPDATE businesses SET rating_average = ?, rating_count = ? WHERE id = ?", [round($agg['a'], 2), $agg['c'], $biz]);
}
echo "  ✓ reviews\n";

// ---------- a sample inquiry ----------
q("INSERT INTO inquiries (customer_id, business_id, listing_type, listing_id, listing_title, inquiry_type, name, message, phone, source)
   VALUES (?,?,?,?,?,?,?,?,?,?)",
  [$custId, $b1, 'product', $prod1, 'Modern L-Shape Sofa', 'product_inquiry', 'Sara Mekonnen',
   'Hello, is the L-shape sofa available in grey? Can you deliver to CMC area this week?', '0955555555', 'product_detail']);
echo "  ✓ sample inquiry\n";

echo "\nDone! Log in as:\n";
echo "  Admin:    0911000000 / admin123\n";
echo "  Seller:   0911111111 / demo123\n";
echo "  Service:  0933333333 / demo123\n";
echo "  Supplier: 0944444444 / demo123\n";
echo "  Customer: 0955555555 / demo123\n";
