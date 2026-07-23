<?php
/** Browse listings with search + filters. Expects $type: product|service|supply */
$table = LISTING_TABLES[$type];
$titleCol = listing_title_col($type);
$labels = ['product' => 'Furniture & Decor', 'service' => 'Services', 'supply' => 'Supplies & Materials'];
$pageTitle = $labels[$type];

$loc       = user_location();
$qStr      = trim($_GET['q'] ?? '');
$catSlug   = $_GET['category'] ?? '';
$city      = array_key_exists('city', $_GET) ? $_GET['city'] : '';
$subcity   = array_key_exists('subcity', $_GET) ? $_GET['subcity'] : '';
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
    // FULLTEXT relevance (§21.1) with a LIKE net for partial words the index misses.
    // Transliteration-aware: expand the query with any known Latin<->Amharic synonym
    // (app/search_synonyms.php) so "wenber" also finds listings written as ወንበር.
    $searchTerms = search_expand_terms($qStr);
    $matchString = implode(' ', $searchTerms); // natural-language MATCH ORs individual words
    [$likeTitle, $likeTitleParams] = search_like_clause("l.`$titleCol`", $searchTerms);
    [$likeDesc, $likeDescParams] = search_like_clause('l.description', $searchTerms);
    [$likeCategory, $likeCategoryParams] = search_like_clause('c.name', $searchTerms);
    $where[] = "(MATCH(l.`$titleCol`, l.description) AGAINST (?) OR $likeTitle OR $likeDesc OR $likeCategory)";
    array_push($params, $matchString, ...$likeTitleParams, ...$likeDescParams, ...$likeCategoryParams);
    $ftScore = "MATCH(l.`$titleCol`, l.description) AGAINST (" . db()->quote($matchString) . ") * 4";
}
// Dynamic per-category attribute filters (admin-managed under Admin → Categories → Attributes).
// Only meaningful once a single category is selected, since attribute keys differ per category.
$catId = $catSlug ? (int)val("SELECT id FROM categories WHERE slug = ?", [$catSlug]) : 0;
$catAttrDefs = $catId ? array_values(array_filter(category_attributes($catId), fn($a) => (bool)$a['is_filterable'])) : [];
$attrFilterIn = $_GET['attr'] ?? [];
foreach ($catAttrDefs as $a) {
    $key = $a['key_name'];
    $path = '$.' . $key;
    $extract = "JSON_UNQUOTE(JSON_EXTRACT(l.attributes, " . db()->quote($path) . "))"; // JSON_UNQUOTE(JSON_EXTRACT(...)) is portable across MySQL 5.7+ and MariaDB 10.2+
    if ($a['input_type'] === 'boolean') {
        if (!empty($attrFilterIn[$key])) { $where[] = "$extract = 'true'"; }
    } elseif ($a['input_type'] === 'number') {
        $minV = $attrFilterIn[$key]['min'] ?? '';
        $maxV = $attrFilterIn[$key]['max'] ?? '';
        if ($minV !== '' && is_numeric($minV)) { $where[] = "CAST($extract AS DECIMAL(14,2)) >= ?"; $params[] = (float)$minV; }
        if ($maxV !== '' && is_numeric($maxV)) { $where[] = "CAST($extract AS DECIMAL(14,2)) <= ?"; $params[] = (float)$maxV; }
    } elseif ($a['input_type'] === 'select') {
        $val = trim((string)($attrFilterIn[$key] ?? ''));
        if ($val !== '') { $where[] = "$extract = ?"; $params[] = $val; }
    } else { // text
        $val = trim((string)($attrFilterIn[$key] ?? ''));
        if ($val !== '') { $where[] = "$extract LIKE ?"; $params[] = "%$val%"; }
    }
}
if ($catSlug)      { $where[] = "c.slug = ?"; $params[] = $catSlug; }
if ($city)         { $where[] = "l.city = ?"; $params[] = $city; }
if ($subcity)      { $where[] = "l.subcity = ?"; $params[] = $subcity; }
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

$priceFacetWhere = $where;
$priceFacetParams = $params;
if ($minPrice > 0) { $where[] = "$priceCol >= ?"; $params[] = $minPrice; }
if ($maxPrice > 0) { $where[] = "$priceCol <= ?"; $params[] = $maxPrice; }

$orderParams = [];
$boostRank = boost_rank_sql('b.id');
if ($sort === 'nearest') {
    // No universal lat/lng on listings yet, so "nearest" ranks same-neighborhood, then same-city, then freshness.
    if ($loc['subcity']) {
        $order = "(l.subcity = ?) DESC, (l.city = ?) DESC, $boostRank DESC, l.is_featured DESC, l.created_at DESC";
        $orderParams[] = $loc['subcity'];
        $orderParams[] = $loc['city'];
    } else {
        $order = "(l.city = ?) DESC, $boostRank DESC, l.is_featured DESC, l.created_at DESC";
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
        . " + $boostRank * {$W('promoted')}"
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
        'discount_first' => ($type === 'product' ? "(l.discount_price > 0) DESC, " : '') . "$boostRank DESC, l.is_featured DESC, l.created_at DESC",
        default          => "$boostRank DESC, l.is_featured DESC, l.is_promoted DESC, l.created_at DESC",
    };
}

$whereSql = implode(' AND ', $where);
$base = "FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id WHERE $whereSql";
$total = (int)val("SELECT COUNT(*) $base", $params);
[$page, $pages, $offset] = paginate($total, 24, $page);
$items = rows("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name, c.icon c_icon $base ORDER BY $order LIMIT 24 OFFSET $offset", array_merge($params, $orderParams));
if ($qStr !== '') {
    event_record('search', [
        'source' => 'organic',
        'city' => $city ?: null,
        'subcity' => $subcity ?: null,
        'category_id' => $catId ?: null,
        'metadata' => [
            'scope' => $type,
            'query' => mb_substr($qStr, 0, 120),
            'category' => $catSlug ?: null,
            'result_count' => $total,
            'zero_results' => $total === 0,
        ],
    ]);
}

$cats = rows("SELECT * FROM categories WHERE type = ? AND status = 'active' ORDER BY sort_order", [$type]);
$adCtx = ['market_type' => $type, 'city' => $city ?: null, 'subcity' => $subcity ?: null,
    'category_id' => $catId ?: null];
$activeAttrFilters = 0;
foreach ($catAttrDefs as $a) {
    $key = $a['key_name'];
    if ($a['input_type'] === 'number') {
        if (($attrFilterIn[$key]['min'] ?? '') !== '' || ($attrFilterIn[$key]['max'] ?? '') !== '') $activeAttrFilters++;
    } elseif (!empty($attrFilterIn[$key])) {
        $activeAttrFilters++;
    }
}
$activeFilters = [
    $catSlug !== '',
    $city !== '',
    $subcity !== '',
    $minPrice > 0,
    $maxPrice > 0,
    $condition !== '',
    $verified,
    $delivery,
    $discount,
    $inStock,
    $material !== '',
    $brand !== '',
    $color !== '',
    $pType !== '',
    $priceType !== '',
    $minExp > 0,
    $thickness !== '',
    $unit !== '',
    $minRating > 0,
    !empty($_GET['installation']),
];
$activeCount = count(array_filter($activeFilters)) + $activeAttrFilters;
$browseBase = ['product' => 'products', 'service' => 'services', 'supply' => 'supplies'][$type];
$sortOptions = [
    'recommended' => 'Recommended',
    'nearest' => 'Nearest to me',
    'newest' => 'Newest',
    'lowest_price' => 'Lowest price',
    'highest_price' => 'Highest price',
    'most_viewed' => 'Most viewed',
    'top_rated' => 'Top rated',
    'most_inquired' => 'Most inquired',
    'discount_first' => 'Deals first',
];
$currentPlace = $subcity ? $subcity . ', ' . $city : ($city ?: 'All Ethiopia');
$priceFacetSql = implode(' AND ', $priceFacetWhere);
$priceFacetBase = "FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id WHERE $priceFacetSql AND $priceCol > 0";
$priceStats = row("SELECT MIN($priceCol) min_price, MAX($priceCol) max_price, COUNT(*) total $priceFacetBase", $priceFacetParams);
$priceBrackets = [];
$moneyShort = function (float $amount): string {
    if ($amount >= 1000000) return rtrim(rtrim(number_format($amount / 1000000, 1), '0'), '.') . 'M ETB';
    if ($amount >= 1000) return rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'K ETB';
    return number_format($amount) . ' ETB';
};
$niceCeil = function (float $value): float {
    if ($value <= 0) return 0;
    $pow = 10 ** floor(log10($value));
    $norm = $value / $pow;
    $nice = $norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10));
    return $nice * $pow;
};
if (($priceStats['total'] ?? 0) > 0 && (float)$priceStats['max_price'] > 0) {
    $step = max(1, $niceCeil((float)$priceStats['max_price'] / 5));
    $rawBrackets = [
        ['min' => 0, 'max' => $step, 'label' => 'Under ' . $moneyShort($step)],
        ['min' => $step, 'max' => $step * 2, 'label' => $moneyShort($step) . ' - ' . $moneyShort($step * 2)],
        ['min' => $step * 2, 'max' => $step * 3, 'label' => $moneyShort($step * 2) . ' - ' . $moneyShort($step * 3)],
        ['min' => $step * 3, 'max' => $step * 4, 'label' => $moneyShort($step * 3) . ' - ' . $moneyShort($step * 4)],
        ['min' => $step * 4, 'max' => 0, 'label' => 'Above ' . $moneyShort($step * 4)],
    ];
    foreach ($rawBrackets as $b) {
        $bw = $priceFacetWhere;
        $bp = $priceFacetParams;
        $bw[] = "$priceCol > 0";
        if ($b['min'] > 0) { $bw[] = "$priceCol > ?"; $bp[] = $b['min']; }
        if ($b['max'] > 0) { $bw[] = "$priceCol <= ?"; $bp[] = $b['max']; }
        $count = (int)val("SELECT COUNT(*) FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id WHERE " . implode(' AND ', $bw), $bp);
        if ($count > 0) $priceBrackets[] = $b + ['count' => $count];
    }
}
$priceBracketUrl = function (float $min, float $max) use ($browseBase): string {
    $qs = $_GET;
    unset($qs['page']);
    if ($min > 0) $qs['min_price'] = (string)(int)$min; else unset($qs['min_price']);
    if ($max > 0) $qs['max_price'] = (string)(int)$max; else unset($qs['max_price']);
    return url($browseBase . '?' . http_build_query($qs));
};
// Canonical strips all filter/sort facets so filtered variants aren't indexed as
// duplicate content against the clean category URL; pagination is preserved since
// each page has distinct listings.
$canonical = $browseBase . ($page > 1 ? '?page=' . $page : '');
$breadcrumbItems = [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => absolute_url(url(''))],
    ['@type' => 'ListItem', 'position' => 2, 'name' => $labels[$type], 'item' => absolute_url(url($browseBase))],
];
if ($catSlug) {
    $catName = val("SELECT name FROM categories WHERE slug = ?", [$catSlug]) ?: $catSlug;
    $breadcrumbItems[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $catName, 'item' => absolute_url(url($browseBase . '?category=' . urlencode($catSlug)))];
}
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $breadcrumbItems,
];
$viewer = auth();
$savedSearchAvailable = db_table_exists('saved_searches');
$savedSearchQuery = saved_search_normalize_query($_GET);
$savedSearchHash = saved_search_hash($type, $savedSearchQuery);
$savedSearchRow = ($viewer && $savedSearchAvailable)
    ? row("SELECT * FROM saved_searches WHERE user_id = ? AND query_hash = ?", [$viewer['id'], $savedSearchHash])
    : null;
$savedSearchLabel = saved_search_label($type, $_GET);
include __DIR__ . '/../views/layout_top.php';
?>
<?php
$browseHtmxAttrs = 'hx-target="#browse-page" hx-select="#browse-page" hx-swap="outerHTML" hx-push-url="true"';
?>
<div id="browse-page" class="browse-page" x-data="{ filtersOpen: false }">
<div class="filter-backdrop" @click="filtersOpen = false" :class="{ 'is-open': filtersOpen }"></div>

<section class="browse-hero">
  <div class="container browse-hero-inner">
    <div class="browse-hero-copy">
      <span class="section-label"><?= e($pageTitle) ?></span>
      <h1><?= e($qStr !== '' ? 'Results for "' . $qStr . '"' : ($type === 'product' ? 'Find furniture that fits your space' : 'Browse ' . strtolower($pageTitle))) ?></h1>
      <p class="muted"><?= number_format($total) ?> listing<?= $total === 1 ? '' : 's' ?> available around <?= e($currentPlace) ?>.</p>
    </div>
    <form class="browse-hero-search" method="get" action="<?= url($browseBase) ?>" <?= $browseHtmxAttrs ?>>
      <label class="sr-only" for="browse-search-q">Search <?= e(strtolower($pageTitle)) ?></label>
      <input type="search" name="q" id="browse-search-q" value="<?= e($qStr) ?>" placeholder="Search by item, brand, material...">
      <input type="hidden" name="subcity" value="">
      <label class="sr-only" for="browse-search-city">City</label>
      <select name="city" id="browse-search-city">
        <option value="">All Ethiopia</option>
        <?php foreach (array_keys(CITIES) as $c): ?><option <?= $city === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary" type="submit">Search</button>
    </form>
  </div>
</section>

<?php if ($cats): ?>
<div class="browse-cats-wrap" hx-boost="true" <?= $browseHtmxAttrs ?>>
  <div class="container browse-cats" aria-label="Categories">
    <a class="browse-cat-chip <?= $catSlug === '' ? 'is-active' : '' ?>" href="<?= url($browseBase . '?' . http_build_query(array_filter(['q' => $qStr, 'city' => $city, 'subcity' => $subcity], fn($v) => $v !== '' && $v !== null))) ?>">
      <span><?= system_ui_icon($type === 'product' ? 'furniture' : $type, '') ?></span> All
    </a>
    <?php foreach ($cats as $c):
      $qs = $_GET; unset($qs['page']); $qs['category'] = $c['slug'];
    ?>
      <a class="browse-cat-chip <?= $catSlug === $c['slug'] ? 'is-active' : '' ?>" href="<?= url($browseBase . '?' . http_build_query($qs)) ?>">
        <span><?= system_ui_category_icon($c['name'], $type) ?></span><?= e($c['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="container section browse-layout" :class="{ 'filters-open': filtersOpen }">
  <aside class="filters" id="listing-filters" aria-label="Listing filters" :aria-hidden="window.innerWidth < 900 && !filtersOpen">
    <form method="get" <?= $browseHtmxAttrs ?>>
      <?php if ($qStr !== ''): ?><input type="hidden" name="q" value="<?= e($qStr) ?>"><?php endif; ?>
      <div class="filters-header">
        <div>
          <h3>Refine results</h3>
          <?php if ($activeCount): ?><span aria-live="polite"><?= $activeCount ?> active</span><?php endif; ?>
        </div>
        <button type="button" class="filter-close-btn" @click="filtersOpen = false" aria-label="Close filters">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <label>Category
        <select name="category">
          <option value="">All categories</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= e($c['slug']) ?>" <?= $catSlug === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($catAttrDefs): ?>
      <div class="attr-filters" aria-label="<?= e($cats[array_search($catSlug, array_column($cats, 'slug'))]['name'] ?? '') ?> filters">
        <?php foreach ($catAttrDefs as $a): $key = $a['key_name']; ?>
          <?php if ($a['input_type'] === 'boolean'): ?>
            <label class="check"><input type="checkbox" name="attr[<?= e($key) ?>]" value="1" <?= !empty($attrFilterIn[$key]) ? 'checked' : '' ?>> <?= e($a['label']) ?></label>
          <?php elseif ($a['input_type'] === 'select'): ?>
            <label><?= e($a['label']) ?>
              <select name="attr[<?= e($key) ?>]">
                <option value="">Any</option>
                <?php foreach (json_decode($a['options'] ?? '[]', true) ?: [] as $opt): ?>
                  <option <?= ($attrFilterIn[$key] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php elseif ($a['input_type'] === 'number'): ?>
            <label><?= e($a['label']) ?><?= $a['unit'] ? ' (' . e($a['unit']) . ')' : '' ?>
              <div class="range-row">
                <input type="number" step="any" name="attr[<?= e($key) ?>][min]" placeholder="Min" value="<?= e((string)($attrFilterIn[$key]['min'] ?? '')) ?>">
                <input type="number" step="any" name="attr[<?= e($key) ?>][max]" placeholder="Max" value="<?= e((string)($attrFilterIn[$key]['max'] ?? '')) ?>">
              </div>
            </label>
          <?php else: ?>
            <label><?= e($a['label']) ?> <input name="attr[<?= e($key) ?>]" value="<?= e((string)($attrFilterIn[$key] ?? '')) ?>"></label>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <label>City
        <select name="city" id="city-select">
          <option value="">All Ethiopia</option>
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
      <?php if ($priceBrackets): ?>
      <div class="price-brackets" aria-label="Popular price ranges" hx-boost="true" <?= $browseHtmxAttrs ?>>
        <div class="filter-subhead">
          <span>Popular ranges</span>
          <?php if ($minPrice > 0 || $maxPrice > 0): ?>
            <?php $clearPriceQs = $_GET; unset($clearPriceQs['min_price'], $clearPriceQs['max_price'], $clearPriceQs['page']); ?>
            <a href="<?= url($browseBase . '?' . http_build_query($clearPriceQs)) ?>">Clear</a>
          <?php endif; ?>
        </div>
        <?php foreach ($priceBrackets as $b): ?>
          <?php $activeBracket = abs($minPrice - $b['min']) < 0.01 && abs($maxPrice - $b['max']) < 0.01; ?>
          <a class="price-bracket <?= $activeBracket ? 'is-active' : '' ?>" href="<?= $priceBracketUrl((float)$b['min'], (float)$b['max']) ?>">
            <span><?= e($b['label']) ?></span>
            <strong><?= number_format((int)$b['count']) ?></strong>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
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
      <a class="btn btn-ghost btn-block" href="<?= url($table) ?>">Clear</a>
    </form>
  </aside>

  <div class="browse-main">
    <div class="browse-head">
      <div class="browse-head-left">
        <button type="button" class="filter-toggle-btn" @click="filtersOpen = true" aria-label="Open filters" :aria-expanded="filtersOpen.toString()" aria-controls="listing-filters">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h6"/></svg>
          Filters
          <?php if ($activeCount): ?><span class="filter-active-badge"><?= $activeCount ?></span><?php endif; ?>
        </button>
        <div>
          <h2><?= e($labels[$type]) ?><?= $qStr !== '' ? ' matching "' . e($qStr) . '"' : '' ?></h2>
          <p class="muted browse-result-line" aria-live="polite">
            <?= number_format($total) ?> result<?= $total === 1 ? '' : 's' ?>
            <?php if ($city): ?>
              in <?= e($subcity ? $subcity . ', ' . $city : $city) ?> (<a href="<?= url($table) ?>">show all Ethiopia</a>)
            <?php endif; ?>
          </p>
        </div>
      </div>
      <form method="get" class="sort-form" id="browse-sort" <?= $browseHtmxAttrs ?>>
        <?php foreach ($_GET as $k => $v): if ($k !== 'sort' && $k !== 'page' && $v !== ''): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endif; endforeach; ?>
        <label>
          Sort
          <select name="sort" onchange="this.form.submit()">
          <?php foreach ($sortOptions as $k => $v): ?>
            <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div><!-- /browse-head -->

    <div class="panel saved-search-panel">
      <div class="section-head">
        <div>
          <h3>Get alerts for this search</h3>
          <p class="muted small">We’ll notify you when new <?= e(strtolower($labels[$type])) ?> match: <?= e($savedSearchLabel) ?>.</p>
        </div>
        <?php if (!$viewer): ?>
          <a class="btn btn-outline btn-sm" href="<?= url('login') ?>"><?= system_ui_icon('login', 'Log in') ?> Log in to save</a>
        <?php elseif (!$savedSearchAvailable): ?>
          <button class="btn btn-outline btn-sm" disabled>Run DB upgrade first</button>
        <?php elseif ($savedSearchRow): ?>
          <form method="post" action="<?= url('saved-search') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int)$savedSearchRow['id'] ?>">
            <input type="hidden" name="return_to" value="<?= e($browseBase . ($savedSearchQuery !== '' ? '?' . $savedSearchQuery : '')) ?>">
            <button class="btn btn-ghost btn-sm">Saved — remove</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= url('saved-search') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="listing_type" value="<?= e($type) ?>">
            <input type="hidden" name="query_string" value="<?= e($savedSearchQuery) ?>">
            <input type="hidden" name="return_to" value="<?= e($browseBase . ($savedSearchQuery !== '' ? '?' . $savedSearchQuery : '')) ?>">
            <button class="btn btn-primary btn-sm">Save this search</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?= ad_slot('browse_top', $adCtx) ?>

    <?php if (!$items): ?>
      <div class="empty-state">No listings match your filters. Try widening your search.</div>
    <?php else: ?>
      <div class="grid" aria-label="<?= number_format($total) ?> <?= $total === 1 ? 'listing result' : 'listing results' ?>">
        <?php $adCard = ad_slot('browse_inline', $adCtx); $adPos = min(2, count($items));
        foreach ($items as $idx => $item) {
            $cardType = $type; include __DIR__ . '/../views/partial_card.php';
            if ($idx === $adPos && $adCard) { echo $adCard; $adCard = ''; }
        }
        echo $adCard; ?>
      </div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
    <nav class="pagination" hx-boost="true" <?= $browseHtmxAttrs ?>>
      <?php for ($i = 1; $i <= $pages; $i++):
        $qs = $_GET; $qs['page'] = $i; ?>
        <a class="<?= $i === $page ? 'current' : '' ?>" href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </nav>
    <?php endif; ?>
  </div>
</div><!-- /browse-layout -->

<div class="browse-mobile-bar">
  <button type="button" @click="filtersOpen = true">
    <?= system_ui_icon('filter', 'Filters') ?> Filters<?php if ($activeCount): ?><span><?= $activeCount ?></span><?php endif; ?>
  </button>
  <a href="#browse-sort"><?= system_ui_icon('sort', 'Sort') ?> <?= e($sortOptions[$sort] ?? 'Sort') ?></a>
</div>
</div><!-- /x-data -->
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
