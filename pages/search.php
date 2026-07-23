<?php
/** Global search (§8.1): products, services, supplies, businesses, videos, categories, locations. */
$qStr = trim($_GET['q'] ?? '');
$pageTitle = $qStr !== '' ? 'Search: ' . $qStr : 'Search';
$canonical = 'search';
$robots = 'noindex,follow';
$like = "%$qStr%";
// Transliteration-aware: expand the query with any known Latin<->Amharic synonym
// (app/search_synonyms.php) so "wenber" also finds listings written as ወንበር.
$searchTerms = search_expand_terms($qStr);
$matchString = implode(' ', $searchTerms); // natural-language MATCH ORs individual words
$results = ['product' => [], 'service' => [], 'supply' => []];
$businesses = $videos = $cats = [];

if ($qStr !== '') {
    $boostRank = boost_rank_sql('b.id');
    foreach (LISTING_TABLES as $t => $table) {
        $col = listing_title_col($t);
        [$likeCol, $likeColParams] = search_like_clause("l.`$col`", $searchTerms);
        [$likeDesc, $likeDescParams] = search_like_clause('l.description', $searchTerms);
        [$likeCategory, $likeCategoryParams] = search_like_clause('c.name', $searchTerms);
        $results[$t] = rows("SELECT l.*, b.business_name b_name, b.verification_status b_verification, c.name c_name, c.icon c_icon
            FROM `$table` l JOIN businesses b ON b.id = l.business_id JOIN categories c ON c.id = l.category_id
            WHERE l.status = 'active' AND b.status = 'active'
              AND (MATCH(l.`$col`, l.description) AGAINST (?) OR $likeCol OR $likeDesc OR $likeCategory)
            ORDER BY MATCH(l.`$col`, l.description) AGAINST (?) DESC, $boostRank DESC, l.is_featured DESC LIMIT 8",
            [$matchString, ...$likeColParams, ...$likeDescParams, ...$likeCategoryParams, $matchString]);
    }
    [$likeName, $likeNameParams] = search_like_clause('business_name', $searchTerms);
    [$likeDesc, $likeDescParams] = search_like_clause('description', $searchTerms);
    $businesses = rows("SELECT * FROM businesses WHERE status = 'active'
        AND ($likeName OR $likeDesc OR city LIKE ? OR subcity LIKE ? OR area_name LIKE ?)
        ORDER BY (verification_status != 'unverified') DESC, rating_average DESC LIMIT 8",
        [...$likeNameParams, ...$likeDescParams, $like, $like, $like]);
    [$likeVTitle, $likeVTitleParams] = search_like_clause('v.title', $searchTerms);
    [$likeVDesc, $likeVDescParams] = search_like_clause('v.description', $searchTerms);
    $videos = rows("SELECT v.*, b.business_name b_name, b.slug b_slug FROM video_posts v
        JOIN businesses b ON b.id = v.business_id
        WHERE v.status = 'approved' AND b.status = 'active' AND ($likeVTitle OR $likeVDesc OR b.business_name LIKE ?)
        ORDER BY v.views_count DESC LIMIT 6", [...$likeVTitleParams, ...$likeVDescParams, $like]);
    [$likeCatName, $likeCatNameParams] = search_like_clause('name', $searchTerms);
    $cats = rows("SELECT * FROM categories WHERE status = 'active' AND $likeCatName ORDER BY sort_order LIMIT 10", $likeCatNameParams);
}
$totalFound = array_sum(array_map('count', $results)) + count($businesses) + count($videos) + count($cats);
if ($qStr !== '') {
    event_record('search', [
        'source' => 'organic',
        'metadata' => [
            'scope' => 'global',
            'query' => mb_substr($qStr, 0, 120),
            'result_count' => $totalFound,
            'zero_results' => $totalFound === 0,
        ],
    ]);
}
include __DIR__ . '/../views/layout_top.php';
?>
<div class="container section">
  <h1>🔎 Search everything</h1>
  <form method="get" class="panel form-inline">
    <input type="search" name="q" value="<?= e($qStr) ?>" placeholder="Furniture, services, supplies, shops, videos…" style="flex:1;min-width:240px" autofocus>
    <button class="btn btn-primary">Search</button>
  </form>

  <?php if ($qStr === ''): ?>
    <p class="muted">Search across products, services, supplies, businesses, videos and categories.</p>
  <?php elseif (!$totalFound): ?>
    <div class="empty-state">Nothing found for “<?= e($qStr) ?>”. Try a shorter keyword.</div>
  <?php else: ?>

    <?php if ($cats): ?>
      <h2 class="section-gap">Categories</h2>
      <div class="btn-row">
        <?php foreach ($cats as $c): $catBase = ['product' => 'products', 'service' => 'services', 'supply' => 'supplies'][$c['type']] ?? 'products'; ?>
          <a class="btn btn-outline btn-sm" href="<?= url($catBase . '?category=' . e($c['slug']) . '&city=&subcity=') ?>"><?= $c['icon'] ?> <?= e($c['name']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php foreach (['product' => 'Furniture & Decor', 'service' => 'Services', 'supply' => 'Supplies'] as $t => $label): if (!$results[$t]) continue; ?>
      <div class="section-head section-gap">
        <h2><?= $label ?> (<?= count($results[$t]) ?>)</h2>
        <a class="btn btn-ghost btn-sm" href="<?= url(LISTING_TABLES[$t] . '?q=' . urlencode($qStr) . '&city=&subcity=') ?>">See all →</a>
      </div>
      <div class="grid">
        <?php foreach ($results[$t] as $item) { $cardType = $t; include __DIR__ . '/../views/partial_card.php'; } ?>
      </div>
    <?php endforeach; ?>

    <?php if ($businesses): ?>
      <h2 class="section-gap">Businesses (<?= count($businesses) ?>)</h2>
      <div class="grid">
        <?php foreach ($businesses as $b): ?>
          <a class="card" href="<?= url('businesses/' . e($b['slug'])) ?>">
            <div class="card-body">
              <h3 class="card-title">🏪 <?= e($b['business_name']) ?> <?= verified_badge($b['verification_status']) ?></h3>
              <div class="card-meta"><?= e(ucfirst(str_replace('_', ' ', $b['business_type']))) ?><?= $b['city'] ? ' · 📍 ' . e($b['city']) : '' ?></div>
              <div class="card-vendor"><?= star_rating($b['rating_average'], (int)$b['rating_count']) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($videos): ?>
      <h2 class="section-gap">Videos (<?= count($videos) ?>)</h2>
      <div class="grid">
        <?php foreach ($videos as $v): ?>
          <a class="card" href="<?= url('videos') ?>">
            <div class="card-body">
              <h3 class="card-title">▶ <?= e($v['title'] ?: $v['b_name']) ?></h3>
              <div class="card-meta"><?= e($v['b_name']) ?> · 👁 <?= (int)$v['views_count'] ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>
<?php include __DIR__ . '/../views/layout_bottom.php'; ?>
