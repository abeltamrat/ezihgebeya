<?php
/** Browse listings with search + filters. Expects $type: product|service|supply */
$table = LISTING_TABLES[$type];
$titleCol = listing_title_col($type);
$labels = ['product' => 'Furniture & Decor', 'service' => 'Services', 'supply' => 'Supplies & Materials'];
$pageTitle = $labels[$type];

$loc       = user_location();
$qStr      = trim($_GET['q'] ?? '');
$catSlug   = $_GET['category'] ?? '';
// no ?city/?subcity at all (fresh visit) means default to the visitor's detected location; an explicit empty value means "All"
$city      = array_key_exists('city', $_GET) ? $_GET['city'] : $loc['city'];
$subcity   = array_key_exists('subcity', $_GET) ? $_GET['subcity'] : ($city === $loc['city'] ? (string)$loc['subcity'] : '');
$minPrice  = (float)($_GET['min_price'] ?? 0);
$maxPrice  = (float)($_GET['max_price'] ?? 0);
$condition = $_GET['condition'] ?? '';
$verified  = !empty($_GET['verified']);
$delivery  = !empty($_GET['delivery']);
$discount  = !empty($_GET['discount']);
$inStock   = !empty($_GET['in_stock']);
$material  = trim($_GET['material'] ?? '');
$brand     = trim($_GET['brand'] ?? '');
$color     = trim($_GET['color'] ?? '');
$pType     = $_GET['product_type'] ?? '';
$priceType = $_GET['price_type'] ?? '';
$minExp    = (int)($_GET['min_experience'] ?? 0);
$thickness = trim($_GET['thickness'] ?? '');
$unit      = $_GET['unit'] ?? '';
$minRating = (float)($_GET['min_rating'] ?? 0);
$sort      = $_GET['sort'] ?? 'recommended';
$page      = (int)($_GET['page'] ?? 1);

$priceCol = ['product' => 'l.price', 'service' => 'l.starting_price', 'supply' => 'l.price_per_unit'][$type];
$where = ["l.status = 'active'", "b.status = 'active'"];
$params = [];

$ftScore = '0'; // keyword relevance term reused by the "recommended" ranking (§8.4)
if ($qStr !== '') {
    // FULLTEXT relevance (§21.1) with a LIKE net for partial words the index misses
    $where[] = "(MATCH(l.`$titleCol`, l.description) AGAINST (?) OR l.`$titleCol` LIKE ? OR l.description LIKE ?)";
    array_push($params, $qStr, "%$qStr%", "%$qStr%");
    $ftScore = "MATCH(l.`$titleCol`, l.description) AGAINST (" . db()->quote($qStr) . ") * 4";
}
if ($catSlug)      { $where[] = "c.slug = ?"; $params[] = $catSlug; }
if ($city)         { $where[] = "l.city = ?"; $params[] = $city; }
if ($subcity)      { $where[] = "l.subcity = ?"; $params[] = $subcity; }
if ($minPrice > 0) { $where[] = "$priceCol >= ?"; $params[] = $minPrice; }
if ($maxPrice > 0) { $where[] = "$priceCol <= ?"; $params[] = $maxPrice; }
if ($verified)     { $where[] = "b.verification_status != 'unverified'"; }
if ($minRating > 0){ $where[] = "b.rating_average >= ?"; $params[] = $minRating; }
if ($type === 'product') {
    if (in_array($condition, ['new', 'used', 'refurbished'], true)) { $where[] = "l.condition_type = ?"; $params[] = $condition; }
    if (isset(PRODUCT_TYPES[$pType])) { $where[] = "l.product_type = ?"; $params[] = $pType; }
    if ($material) { $where[] = "l.material LIKE ?"; $params[] = "%$material%"; }
    if ($brand)    { $where[] = "l.brand LIKE ?"; $params[] = "%$brand%"; }
    if ($color)    { $where[] = "l.color LIKE ?"; $params[] = "%$color%"; }
    if ($delivery) { $where[] = "l.delivery_available = 1"; }
    if ($discount) { $where[] = "l.discount_price > 0"; }
    if ($inStock)  { $where[] = "l.stock_quantity > 0"; }
    if (!empty($_GET['installation'])) { $where[] = "l.installation_available = 1"; }
} elseif ($type === 'service') {
    if (isset(PRICE_TYPES[$priceType])) { $where[] = "l.price_type = ?"; $params[] = $priceType; }
    if ($minExp > 0) { $where[] = "l.experience_years >= ?"; $params[] = $minExp; }
} else { // supply
    if ($thickness) { $where[] = "l.thickness LIKE ?"; $params[] = "%$thickness%"; }
    if ($brand)     { $where[] = "l.brand LIKE ?"; $params[] = "%$brand%"; }
    if (in_array($unit, SUPPLY_UNITS, true)) { $where[] = "l.unit_of_measurement = ?"; $params[] = $unit; }
    if ($delivery)  { $where[] = "l.delivery_available = 1"; }
    if ($inStock)   { $where[] = "l.stock_quantity > 0"; }
    if ($discount)  { $where[] = "l.bulk_price > 0"; }
}

$orderParams = [];
if ($sort === 'nearest') {
    // No universal lat/lng on listings yet, so "nearest" ranks same-neighborhood, then same-city, then freshness.
    if ($loc['subcity']) {
        $order = "(l.subcity = ?) DESC, (l.city = ?) DESC, l.is_featured DESC, l.created_at DESC";
        $orderParams[] = $loc['subcity'];
        $orderParams[] = $loc['city'];
    } else {
        $order = "(l.city = ?) DESC, l.is_featured DESC, l.created_at DESC";
        $orderParams[] = $loc['city'];
    }
} elseif ($sort === 'recommended') {
    // §8.4 Listing Score — weights tunable in admin → Settings → ranking
    $W = fn(string $k) => (float)sys("ranking.$k");
    $order = "((l.city = ?) * {$W('city')} + (l.subcity <=> ?) * {$W('subcity')}"
        . " + $ftScore"
        . " + (b.verification_status != 'unverified') * {$W('verification')}"
        . " + LEAST(10, b.rating_average * {$W('rating')})"
        . " + GREATEST(0, {$W('freshness')} - DATEDIFF(NOW(), l.created_at) / 5)"
        . " + l.is_featured * {$W('featured')} + l.is_promoted * {$W('promoted')}"
        . " - (SELECT COUNT(*) FROM reports r WHERE r.reported_type = '$type' AND r.reported_id = l.id AND r.status IN ('open','reviewing')) * {$W('report_penalty')}"
        . ") DESC, l.created_at DESC";
    $orderParams[] = $loc['city'];
    $orderParams[] = $loc['subcity'];
} else {
    $order = match ($sort) {
        'lowest_price'   => "$priceCol IS NULL, $priceCol ASC",
        'highest_price'  => "$priceCol DESC",
        'newest'         => "l.created_at DESC",
        'most_viewed'    => "l.views_count DESC",
        'top_rated'      => "b.rating_average DESC, b.rating_count DESC",
        'most_inquired'  => "l.inquiries_count DESC",
        'discount_first' => ($type === 'product' ? "(l.discount_price > 0) DESC, " : '') . "l.is_featured DESC, l.created_at DESC",
        default          => "l.is_featured DESC, l.is_promoted DESC, l.created_at DESC",
    };
}

$whereSql = implode(' AND ', $where);
$base = "FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id WHERE $whereSql";
$total = (int)val("SELECT COUNT(*) $base", $params);
[$page, $pages, $offset] = paginate($total, 24, $page);
$items = rows("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name, c.icon c_icon $base ORDER BY $order LIMIT 24 OFFSET $offset", array_merge($params, $orderParams));

$cats = rows("SELECT * FROM categories WHERE type = ? AND status = 'active' ORDER BY sort_order", [$type]);
$adCtx = ['market_type' => $type, 'city' => $city ?: null, 'subcity' => $subcity ?: null,
    'category_id' => $catSlug ? (int)val("SELECT id FROM categories WHERE slug = ?", [$catSlug]) : null];
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section browse-layout">
  <aside class="filters">
    <form method="get">
      <?php if ($qStr !== ''): ?><input type="hidden" name="q" value="<?= e($qStr) ?>"><?php endif; ?>
      <h3>Filters</h3>
      <label>Category
        <select name="category">
          <option value="">All categories</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= e($c['slug']) ?>" <?= $catSlug === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>City
        <select name="city" id="city-select">
          <option value="">All cities</option>
          <?php foreach (array_keys(CITIES) as $c): ?><option <?= $city === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Sub-city
        <select name="subcity" id="subcity-select" data-selected="<?= e($subcity) ?>">
          <option value="">All</option>
          <?php foreach (CITIES[$city] ?? [] as $s): ?><option <?= $subcity === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Price range (<?= e(sys('general.currency_label', 'ETB')) ?>)
        <div class="range-row">
          <input type="number" name="min_price" placeholder="Min" value="<?= $minPrice ?: '' ?>">
          <input type="number" name="max_price" placeholder="Max" value="<?= $maxPrice ?: '' ?>">
        </div>
      </label>
      <?php if ($type === 'product'): ?>
      <label>Condition
        <select name="condition">
          <option value="">Any</option>
          <?php foreach (['new', 'used', 'refurbished'] as $c): ?><option <?= $condition === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Type
        <select name="product_type">
          <option value="">Any</option>
          <?php foreach (PRODUCT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $pType === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Material <input name="material" value="<?= e($material) ?>" placeholder="e.g. oak, MDF"></label>
      <label>Brand <input name="brand" value="<?= e($brand) ?>"></label>
      <label>Color <input name="color" value="<?= e($color) ?>"></label>
      <label class="check"><input type="checkbox" name="installation" value="1" <?= !empty($_GET['installation']) ? 'checked' : '' ?>> Installation available</label>
      <label class="check"><input type="checkbox" name="discount" value="1" <?= $discount ? 'checked' : '' ?>> On discount</label>
      <?php elseif ($type === 'service'): ?>
      <label>Pricing
        <select name="price_type">
          <option value="">Any</option>
          <?php foreach (PRICE_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $priceType === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Min. experience (years) <input type="number" name="min_experience" min="0" value="<?= $minExp ?: '' ?>"></label>
      <?php else: ?>
      <label>Thickness <input name="thickness" value="<?= e($thickness) ?>" placeholder="e.g. 18mm"></label>
      <label>Brand <input name="brand" value="<?= e($brand) ?>"></label>
      <label>Unit
        <select name="unit">
          <option value="">Any</option>
          <?php foreach (SUPPLY_UNITS as $un): ?><option <?= $unit === $un ? 'selected' : '' ?>><?= $un ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="check"><input type="checkbox" name="discount" value="1" <?= $discount ? 'checked' : '' ?>> Bulk price available</label>
      <?php endif; ?>
      <?php if ($type !== 'service'): ?>
      <label class="check"><input type="checkbox" name="delivery" value="1" <?= $delivery ? 'checked' : '' ?>> Delivery available</label>
      <label class="check"><input type="checkbox" name="in_stock" value="1" <?= $inStock ? 'checked' : '' ?>> In stock</label>
      <?php endif; ?>
      <label>Min. rating
        <select name="min_rating">
          <option value="">Any</option>
          <?php foreach ([4, 3, 2] as $r): ?><option value="<?= $r ?>" <?= (int)$minRating === $r ? 'selected' : '' ?>>★ <?= $r ?>+</option><?php endforeach; ?>
        </select>
      </label>
      <label class="check"><input type="checkbox" name="verified" value="1" <?= $verified ? 'checked' : '' ?>> Verified sellers only</label>
      <button class="btn btn-primary btn-block" type="submit">Apply filters</button>
      <a class="btn btn-ghost btn-block" href="<?= url($table . '?city=&subcity=') ?>">Clear</a>
    </form>
  </aside>

  <div class="browse-main">
    <div class="browse-head">
      <h1><?= e($labels[$type]) ?><?= $qStr !== '' ? ' for "' . e($qStr) . '"' : '' ?></h1>
      <form method="get" class="sort-form">
        <?php foreach (['q' => $qStr, 'category' => $catSlug, 'city' => $city, 'subcity' => $subcity] as $k => $v): if ($v): ?>
          <input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
        <select name="sort" onchange="this.form.submit()">
          <?php foreach (['recommended' => 'Recommended', 'nearest' => 'Nearest to me', 'newest' => 'Newest', 'lowest_price' => 'Lowest price', 'highest_price' => 'Highest price', 'most_viewed' => 'Most viewed', 'top_rated' => 'Top rated', 'most_inquired' => 'Most inquired', 'discount_first' => 'Discounts first'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <p class="muted">
      <?= $total ?> listing<?= $total === 1 ? '' : 's' ?> found
      <?php if (!array_key_exists('city', $_GET) && $city): ?>
        - showing <?= e($subcity ? $subcity . ', ' . $city : $city) ?> first (<a href="<?= url($table . '?city=&subcity=') ?>">show all cities</a>)
      <?php endif; ?>
    </p>

    <?= ad_slot('browse_top', $adCtx) ?>

    <?php if (!$items): ?>
      <div class="empty-state">No listings match your filters. Try widening your search.</div>
    <?php else: ?>
      <div class="grid">
        <?php $adCard = ad_slot('browse_inline', $adCtx); $adPos = min(2, count($items));
        foreach ($items as $idx => $item) {
            $cardType = $type; include __DIR__ . '/../views/partial_card.php';
            if ($idx === $adPos && $adCard) { echo $adCard; $adCard = ''; }
        }
        echo $adCard; ?>
      </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
    <nav class="pagination">
      <?php for ($i = 1; $i <= $pages; $i++):
        $qs = $_GET; $qs['page'] = $i; ?>
        <a class="<?= $i === $page ? 'current' : '' ?>" href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </nav>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
