<?php
/**
 * HTMX autocomplete endpoint — returns an HTML fragment (no layout).
 * Called by the header search input via hx-get="/search/suggest?q=..."
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo ''; exit; }

$limit = 4;
// Transliteration-aware: expand the query with any known Latin<->Amharic synonym
// (app/search_synonyms.php) so "wenber" also suggests listings written as ወንበር.
$searchTerms = search_expand_terms($q);

[$likePTitle, $likePTitleParams] = search_like_clause('p.title', $searchTerms);
[$likePDesc, $likePDescParams] = search_like_clause('p.description', $searchTerms);
$products = rows(
    "SELECT 'product' AS type, p.title AS title, p.slug AS slug,
            b.business_name AS biz,
            (SELECT file_url FROM product_media
             WHERE product_id = p.id AND media_type = 'image'
             ORDER BY is_primary DESC, sort_order, id LIMIT 1) AS img
     FROM products p
     JOIN businesses b ON b.id = p.business_id
     WHERE p.status = 'active' AND b.status = 'active'
       AND ($likePTitle OR $likePDesc)
     LIMIT " . (int)$limit,
    [...$likePTitleParams, ...$likePDescParams]
);

[$likeSTitle, $likeSTitleParams] = search_like_clause('s.title', $searchTerms);
[$likeSDesc, $likeSDescParams] = search_like_clause('s.description', $searchTerms);
$services = rows(
    "SELECT 'service' AS type, s.title AS title, s.slug AS slug,
            b.business_name AS biz, s.image AS img
     FROM services s
     JOIN businesses b ON b.id = s.business_id
     WHERE s.status = 'active' AND b.status = 'active'
       AND ($likeSTitle OR $likeSDesc)
     LIMIT " . (int)$limit,
    [...$likeSTitleParams, ...$likeSDescParams]
);

[$likeSpName, $likeSpNameParams] = search_like_clause('s.name', $searchTerms);
[$likeSpDesc, $likeSpDescParams] = search_like_clause('s.description', $searchTerms);
$supplies = rows(
    "SELECT 'supply' AS type, s.name AS title, s.slug AS slug,
            b.business_name AS biz, s.image AS img
     FROM supplies s
     JOIN businesses b ON b.id = s.business_id
     WHERE s.status = 'active' AND b.status = 'active'
       AND ($likeSpName OR $likeSpDesc)
     LIMIT " . (int)$limit,
    [...$likeSpNameParams, ...$likeSpDescParams]
);

$all = array_slice(array_merge($products, $services, $supplies), 0, 7);
if (!$all) { echo ''; exit; }

$typeLabel = ['product' => 'Furniture', 'service' => 'Service', 'supply' => 'Supply'];
?>
<?php foreach ($all as $item): ?>
<?php $href = listing_url($item['type'], $item); ?>
<a href="<?= e($href) ?>" role="option" aria-label="<?= e($item['title'] . ' from ' . $item['biz']) ?>">
  <?php if ($item['img']): ?>
    <img class="ac-img" src="<?= e(thumb_url($item['img'])) ?>" alt="" loading="lazy">
  <?php else: ?>
    <span class="ac-img ac-placeholder"></span>
  <?php endif; ?>
  <span class="ac-info">
    <span class="ac-title"><?= e($item['title']) ?></span>
    <span class="ac-meta"><?= e($typeLabel[$item['type']] ?? '') ?> · <?= e($item['biz']) ?></span>
  </span>
</a>
<?php endforeach; ?>
<a href="<?= url('search?q=' . urlencode($q)) ?>" class="ac-all" role="option">
  See all results for "<?= e($q) ?>" →
</a>
