<?php
/** Seed upgrade-5 data: default content pages (§19.1) + location hierarchy (§14.1). Idempotent. */
require __DIR__ . '/../config.php';
require __DIR__ . '/../app/db.php';

// ---------- content pages ----------
$pages = [
    'about' => ['About ' . SITE_NAME, SITE_NAME . " is Ethiopia's specialized marketplace for furniture, home decor, interior design, finishing works, construction finishing services and furniture manufacturing supplies.

We connect customers with trusted furniture sellers, manufacturers, importers, verified service providers (carpenters, painters, electricians, gypsum workers, installers) and supply vendors — searchable by location, price, quality, rating and video content.

Our long-term vision is to become the trusted digital marketplace for the home, furniture, finishing and furnishing industries in Ethiopia, and later East Africa."],
    'contact' => ['Contact us', "We'd love to hear from you.

Phone: 0911 000 000
Email: hello@ezihgebeya.example
Address: Addis Ababa, Ethiopia

For advertising inquiries, call the number above or use the \"Advertise here\" banners across the site.

Vendors: to get verified, open your dashboard and submit your documents under Verification."],
    'terms' => ['Terms of Service', "By using " . SITE_NAME . " you agree to these terms.

1. The platform is a marketplace that connects buyers with independent sellers, service providers and suppliers. Transactions are between you and the vendor; the platform records orders and payment proofs but is not a party to the sale.

2. Vendors must provide accurate listings. Fake products, misleading prices and stolen content are removed and repeat offenders are banned.

3. Reviews must reflect genuine experience. Abusive or fake reviews are removed.

4. Payment proofs submitted for orders, promotions and subscriptions are verified manually. Fraudulent proofs lead to account suspension.

5. The platform may moderate, edit or remove any listing, video or review that violates these rules.

6. These terms may change; continued use means acceptance of the current version."],
    'privacy' => ['Privacy Policy', "We respect your privacy.

What we collect: your name, phone number, optional email, listings and messages you post, and approximate location (used to show nearby listings first).

What we use it for: operating the marketplace — showing your listings to buyers, delivering inquiries to vendors, ranking by location, and sending you notifications about your account activity.

What we don't do: we do not sell your personal data to third parties.

Phone numbers are shown on listings only in the way the vendor chooses. Location detection can be ignored — searching all cities is always available.

To delete your account and data, contact us via the details on the Contact page."],
];
foreach ($pages as $slug => [$title, $body]) {
    if (!val("SELECT COUNT(*) FROM content_pages WHERE slug = ?", [$slug])) {
        q("INSERT INTO content_pages (slug, title, body, status) VALUES (?,?,?, 'published')", [$slug, $title, $body]);
        echo "page seeded: $slug\n";
    }
}

// ---------- locations ----------
$locId = function (string $name, string $level, ?int $parent, ?float $lat = null, ?float $lng = null): int {
    $found = val("SELECT id FROM locations WHERE name = ? AND level = ? AND parent_id <=> ?", [$name, $level, $parent]);
    if ($found) return (int)$found;
    q("INSERT INTO locations (parent_id, name, level, latitude, longitude) VALUES (?,?,?,?,?)", [$parent, $name, $level, $lat, $lng]);
    echo "location seeded: $level $name\n";
    return (int)db()->lastInsertId();
};
$ethiopia = $locId('Ethiopia', 'country', null, 9.145, 40.4897);
foreach (CITY_COORDS as $city => [$lat, $lng]) {
    $cid = $locId($city, 'city', $ethiopia, $lat, $lng);
    foreach (SUBCITY_COORDS[$city] ?? [] as $sub => [$slat, $slng]) {
        $locId($sub, 'subcity', $cid, $slat, $slng);
    }
}
echo "seed5 done\n";
