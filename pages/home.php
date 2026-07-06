<?php
$pageTitle = 'Home';
$pageDesc = SITE_TAGLINE;

$sel = fn(string $t, string $titleCol) => "SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name, c.icon c_icon
    FROM $t l JOIN businesses b ON b.id = l.business_id AND b.status = 'active'
    JOIN categories c ON c.id = l.category_id
    WHERE l.status = 'active'";

$loc = user_location();
$nearItems = [];
$nearLabel = $loc['city'];
foreach (['product' => 'products', 'service' => 'services', 'supply' => 'supplies'] as $t => $tbl) {
    // neighborhood-first: try the exact sub-city, then widen to the whole city if that is too thin
    $found = $loc['subcity']
        ? rows($sel($tbl, listing_title_col($t)) . " AND l.city = ? AND l.subcity = ? ORDER BY l.created_at DESC LIMIT 4", [$loc['city'], $loc['subcity']])
        : [];
    if ($found) $nearLabel = $loc['subcity'] . ', ' . $loc['city'];
    else $found = rows($sel($tbl, listing_title_col($t)) . " AND l.city = ? ORDER BY l.created_at DESC LIMIT 4", [$loc['city']]);
    foreach ($found as $r) { $r['_type'] = $t; $nearItems[] = $r; }
}
usort($nearItems, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
$nearItems = array_slice($nearItems, 0, 8);

$videoCount = (int)val("SELECT COUNT(*) FROM video_posts WHERE status = 'approved'");
$ui = system_ui_config();
$sectionOrder = $ui['home_sections'];
$categoryLimit = max(4, min(16, (int)($ui['category_display_limit'] ?? 8)));
$featured = rows($sel('products', 'title') . " ORDER BY l.is_featured DESC, l.created_at DESC LIMIT 8");
$services = rows($sel('services', 'title') . " ORDER BY l.is_featured DESC, l.created_at DESC LIMIT 4");
$supplies = rows($sel('supplies', 'name') . " ORDER BY l.is_featured DESC, l.created_at DESC LIMIT 4");
$prodCats = rows("SELECT * FROM categories WHERE type = 'product' AND status = 'active' ORDER BY sort_order LIMIT {$categoryLimit}");

include __DIR__ . '/../views/layout_top.php';
?>
<section class="hero">
  <div class="container">
    <h1><?= e($ui['hero_title']) ?></h1>
    <p><?= e($ui['hero_subtitle']) ?></p>
    <?php if (!empty($ui['hero_search_enabled'])): ?>
    <form class="hero-search" action="<?= url('products') ?>" method="get">
      <input type="search" name="q" placeholder="What are you looking for? e.g. L-shape sofa, gypsum work, 18mm MDF...">
      <select name="city"><option value="">All cities</option>
        <?php foreach (array_keys(CITIES) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Search<?= system_ui_button_badge('primary') ?></button>
    </form>
    <?php endif; ?>
    <?php if (!empty($ui['hero_links_enabled'])): ?>
    <div class="hero-links">
      <a href="<?= url('products') ?>"><span class="icon-chip"><?= system_ui_icon('furniture', 'Furniture') ?></span> Furniture</a>
      <a href="<?= url('services') ?>"><span class="icon-chip"><?= system_ui_icon('services', 'Services') ?></span> Services</a>
      <a href="<?= url('supplies') ?>"><span class="icon-chip"><?= system_ui_icon('supplies', 'Supplies') ?></span> Supplies</a>
      <a href="<?= url('videos') ?>"><span class="icon-chip"><?= system_ui_icon('play', 'Watch') ?></span> Watch & Buy<?= $videoCount ? " ($videoCount)" : '' ?></a>
    </div>
    <?php endif; ?>
    <?php
    $heroListings = (int)val("SELECT (SELECT COUNT(*) FROM products WHERE status='active')+(SELECT COUNT(*) FROM services WHERE status='active')+(SELECT COUNT(*) FROM supplies WHERE status='active')");
    $heroVendors = (int)val("SELECT COUNT(*) FROM businesses WHERE status='active'");
    $heroVerified = (int)val("SELECT COUNT(*) FROM businesses WHERE status='active' AND verification_status != 'unverified'");
    ?>
    <?php if (!empty($ui['hero_stats_enabled'])): ?>
    <div class="hero-stats">
      <div class="hero-stat"><b><?= number_format($heroListings) ?>+</b><span>active listings</span></div>
      <div class="hero-stat"><b><?= number_format($heroVendors) ?></b><span>shops & providers</span></div>
      <div class="hero-stat"><b><?= number_format($heroVerified) ?></b><span>verified businesses</span></div>
      <div class="hero-stat"><b><?= count(CITIES) ?></b><span>cities covered</span></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php foreach ($sectionOrder as $homeSection): ?>
<?php if ($homeSection === 'categories' && system_ui_section_enabled('categories')): ?>
<section class="container section">
  <div class="section-head"><h2>Browse by category</h2><a href="<?= url('products') ?>">View all &rarr;</a></div>
  <div class="cat-grid">
    <?php foreach ($prodCats as $c): ?>
      <a class="cat-tile" href="<?= url('products?category=' . e($c['slug'])) ?>">
        <span class="cat-icon"><?= system_ui_category_icon((string)$c['name'], 'product') ?></span><span><?= e($c['name']) ?></span>
      </a>
    <?php endforeach; ?>
    <a class="cat-tile cat-more" href="<?= url('products') ?>">
      <span class="cat-icon"><?= system_ui_icon('search', 'All categories') ?></span><span>All categories</span>
    </a>
  </div>
</section>
<?php endif; ?>

<?php if ($homeSection === 'categories'): ?>
<div class="container"><?= ad_slot('home_hero') ?></div>
<?php endif; ?>

<?php if ($homeSection === 'near' && system_ui_section_enabled('near') && $nearItems): ?>
<section class="container section">
  <div class="section-head"><h2>Near you in <?= e($nearLabel) ?></h2><a href="<?= url('products?city=' . urlencode($loc['city'])) ?>">View all &rarr;</a></div>
  <div class="grid">
    <?php foreach ($nearItems as $item) { $cardType = $item['_type']; include __DIR__ . '/../views/partial_card.php'; } ?>
  </div>
</section>
<?php endif; ?>

<?php if ($homeSection === 'featured' && system_ui_section_enabled('featured') && $featured): ?>
<section class="container section">
  <div class="section-head"><h2>Featured & latest furniture</h2><a href="<?= url('products') ?>">View all &rarr;</a></div>
  <div class="grid">
    <?php foreach ($featured as $item) { $cardType = 'product'; include __DIR__ . '/../views/partial_card.php'; } ?>
  </div>
</section>
<?php endif; ?>

<?php if ($homeSection === 'featured'): ?>
<div class="container"><?= ad_slot('home_inline') ?></div>
<?php endif; ?>

<?php if ($homeSection === 'services' && system_ui_section_enabled('services') && $services): ?>
<section class="container section">
  <div class="section-head"><h2>Finishing & interior services</h2><a href="<?= url('services') ?>">View all &rarr;</a></div>
  <div class="grid">
    <?php foreach ($services as $item) { $cardType = 'service'; include __DIR__ . '/../views/partial_card.php'; } ?>
  </div>
</section>
<?php endif; ?>

<?php if ($homeSection === 'supplies' && system_ui_section_enabled('supplies') && $supplies): ?>
<section class="container section">
  <div class="section-head"><h2>Materials & supplies</h2><a href="<?= url('supplies') ?>">View all &rarr;</a></div>
  <div class="grid">
    <?php foreach ($supplies as $item) { $cardType = 'supply'; include __DIR__ . '/../views/partial_card.php'; } ?>
  </div>
</section>
<?php endif; ?>

<?php if ($homeSection === 'cta' && system_ui_section_enabled('cta')): ?>
<section class="container section">
  <div class="cta-banner">
    <div>
      <h2><?= e($ui['cta_title']) ?></h2>
      <p><?= e($ui['cta_text']) ?></p>
    </div>
    <a class="btn btn-primary btn-lg" href="<?= url('register') ?>"><?= e($ui['cta_button']) ?><?= system_ui_button_badge('join') ?></a>
  </div>
</section>
<?php endif; ?>
<?php endforeach; ?>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
